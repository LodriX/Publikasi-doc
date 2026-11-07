<?php

declare(strict_types=1);

require __DIR__ . '/lib.php';

start_portal_session();

$config = app_config();
$credentials = admin_credentials();
$error = '';
$uploadFolder = (string) ($_POST['folder'] ?? '');
$uploadNewFolder = (string) ($_POST['new_folder'] ?? '');

$isSecretLogin = defined('SECRET_LOGIN_ACCESS')
    || isset($_GET['secret_login'])
    || isset($_POST['secret_login'])
    || (isset($_SERVER['REQUEST_URI']) && preg_match('#/login(/|\?|$)#', parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? ''));

// Stealth Admin: return 404 if accessed directly without active session
if (!is_admin() && !$isSecretLogin) {
    http_response_code(404);
    send_security_headers();
    page_header('Halaman Tidak Ditemukan', 'fm-body', 'assets/public.css');
    ?>
    <header class="fm-masthead">
      <div class="fm-masthead-inner">
        <div class="fm-brand-zone">
          <a href="<?= e(app_url()) ?>" class="brand-link">
            <?php if (is_file(APP_ROOT . '/' . app_logo())): ?><img class="brand-mark" src="<?= e(app_url(app_logo())) ?>" alt=""><?php endif; ?>
            <div class="brand-texts"><span class="brand-name"><?= e(app_brand()) ?></span></div>
          </a>
        </div>
        <a class="btn-cyber-outline" href="<?= e(app_url()) ?>">&larr; Kembali ke Beranda</a>
      </div>
    </header>
    <main class="fm-workspace"><div class="fm-deck"><div class="fm-empty-state"><h3>404 - Halaman Tidak Ditemukan</h3><p>Halaman yang Anda minta tidak tersedia atau telah dipindahkan.</p><a class="btn-cyber" href="<?= e(app_url()) ?>">Kembali ke Beranda</a></div></div></main>
    <?php
    page_footer();
    exit;
}

if (is_admin() && $isSecretLogin) {
    redirect('admin.php');
}

if (is_admin() && (string) ($_GET['action'] ?? '') === 'export_audit') {
    $token = (string) ($_GET['csrf_token'] ?? '');
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        http_response_code(403);
        exit('Token tidak valid.');
    }

    $lines = is_file(ADMIN_AUDIT_FILE) ? (file(ADMIN_AUDIT_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) : [];

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="audit-log-' . date('Y-m-d') . '.csv"');
    header('Cache-Control: no-cache, no-store, must-revalidate');

    echo "\xEF\xBB\xBF";

    $output = fopen('php://output', 'w');
    if ($output !== false) {
        fputcsv($output, ['Waktu', 'Administrator', 'Tindakan', 'Detail']);
        foreach ($lines as $line) {
            $entry = json_decode($line, true);
            if (is_array($entry)) {
                $timeFormatted = isset($entry['at']) ? date('Y-m-d H:i:s', strtotime((string) $entry['at'])) : '';
                fputcsv($output, [
                    $timeFormatted,
                    (string) ($entry['admin'] ?? ''),
                    (string) ($entry['action'] ?? ''),
                    (string) ($entry['details'] ?? ''),
                ]);
            }
        }
        fclose($output);
    }
    exit;
}

function normalize_uploaded_files(array|null $upload): array
{
    if (!$upload) {
        return [];
    }
    $files = [];
    if (is_array($upload['name'] ?? null)) {
        $count = count($upload['name']);
        for ($i = 0; $i < $count; $i++) {
            if (($upload['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $files[] = [
                'name' => (string) ($upload['name'][$i] ?? ''),
                'tmp_name' => (string) ($upload['tmp_name'][$i] ?? ''),
                'size' => (int) ($upload['size'][$i] ?? 0),
                'error' => (int) ($upload['error'][$i] ?? UPLOAD_ERR_OK),
            ];
        }
    } elseif (is_string($upload['name'] ?? null) && ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $files[] = [
            'name' => (string) $upload['name'],
            'tmp_name' => (string) $upload['tmp_name'],
            'size' => (int) ($upload['size'] ?? 0),
            'error' => (int) ($upload['error'] ?? UPLOAD_ERR_OK),
        ];
    }
    return $files;
}

function valid_publication_date(string $date): bool
{
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    return $parsed !== false && $parsed->format('Y-m-d') === $date;
}

function upload_mime_is_allowed(string $extension, string $mime): bool
{
    $allowed = [
        'pdf' => ['application/pdf'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
        'xls' => ['application/vnd.ms-excel', 'application/octet-stream'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
        'csv' => ['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'],
    ];
    return in_array($mime, $allowed[$extension] ?? [], true);
}

function valid_document_pin(string $pin): bool
{
    return preg_match('/\A[0-9]{6}\z/', $pin) === 1;
}

function remap_folder_entries(array $entries, string $oldFolder, string $newFolder): array
{
    $updated = [];
    foreach ($entries as $folderPath => $value) {
        $folderPath = (string) $folderPath; // PHP casts numeric folder keys to integers.
        $path = $folderPath === $oldFolder
            ? $newFolder
            : (str_starts_with($folderPath, $oldFolder . '/')
                ? $newFolder . substr($folderPath, strlen($oldFolder))
                : $folderPath);
        $updated[$path] = $value;
    }
    return $updated;
}

function rollback_document_moves(array $moves): bool
{
    $restored = true;
    foreach (array_reverse($moves) as $move) {
        if (!rename($move['to'], $move['from'])) {
            $restored = false;
        }
    }
    return $restored;
}

/** Return an error message, or null after every selected document has moved. */
function bulk_move_documents(array $documents, array $paths, string $targetFolder): ?string
{
    $targetDir = absolute_directory_path($targetFolder);
    if ($targetDir === null) {
        return 'Folder tujuan tidak ditemukan.';
    }
    $moves = [];
    $plannedDestinations = [];
    $updated = $documents;
    $statistics = document_statistics();
    $updatedStatistics = $statistics;
    foreach ($paths as $path) {
        $destination = ($targetFolder === '' ? '' : $targetFolder . '/') . basename($path);
        if ($path === $destination) {
            continue;
        }
        $source = absolute_file_path($path);
        $target = $targetDir . '/' . basename($path);
        $destinationKey = strtolower($destination); // Conservative on case-insensitive hosting/filesystems.
        if ($source === null || file_exists($target) || isset($documents[$destination])
            || isset($plannedDestinations[$destinationKey])) {
            return 'Pemindahan dibatalkan: file tujuan sudah ada atau berkas sumber tidak tersedia.';
        }
        $plannedDestinations[$destinationKey] = true;
        $moves[] = ['from' => $source, 'to' => $target];
        $updated[$destination] = $documents[$path];
        unset($updated[$path]);
        if (isset($updatedStatistics[$path])) {
            $updatedStatistics[$destination] = $updatedStatistics[$path];
            unset($updatedStatistics[$path]);
        }
    }
    if (!$moves) {
        return 'Semua dokumen yang dipilih sudah berada di folder tujuan.';
    }
    $completed = [];
    foreach ($moves as $move) {
        if (!rename($move['from'], $move['to'])) {
            return rollback_document_moves($completed)
                ? 'Pemindahan gagal; seluruh berkas dikembalikan.'
                : 'Pemindahan gagal dan sebagian berkas tidak dapat dikembalikan; periksa penyimpanan secara manual.';
        }
        $completed[] = $move;
    }
    if (!save_document_statistics($updatedStatistics)) {
        return rollback_document_moves($completed)
            ? 'Statistik tidak dapat disimpan; pemindahan dibatalkan.'
            : 'Statistik tidak dapat disimpan dan sebagian berkas tidak dapat dikembalikan; periksa penyimpanan.';
    }
    if (!save_documents($updated)) {
        $statsRestored = save_document_statistics($statistics);
        $filesRestored = rollback_document_moves($completed);
        return $statsRestored && $filesRestored
            ? 'Metadata tidak dapat disimpan; pemindahan dibatalkan.'
            : 'Metadata tidak dapat disimpan dan pemulihan tidak lengkap; periksa penyimpanan.';
    }
    return null;
}

/** Stage physical files in the private data directory until metadata and stats are saved. */
function bulk_delete_documents(array $documents, array $paths): ?string
{
    $staged = [];
    foreach ($paths as $path) {
        $source = absolute_file_path($path);
        $temporary = tempnam(dirname(DOCUMENTS_FILE), 'bulk_delete_');
        if ($source === null || $temporary === false || !unlink($temporary) || !rename($source, $temporary)) {
            if ($temporary !== false && is_file($temporary)) {
                unlink($temporary);
            }
            return rollback_document_moves($staged)
                ? 'Penghapusan gagal; seluruh berkas dikembalikan.'
                : 'Penghapusan gagal dan sebagian berkas tidak dapat dikembalikan; periksa penyimpanan.';
        }
        $staged[] = ['from' => $source, 'to' => $temporary];
    }

    $statistics = document_statistics();
    $updatedStatistics = $statistics;
    foreach ($paths as $path) {
        unset($documents[$path], $updatedStatistics[$path]);
    }
    if (!save_document_statistics($updatedStatistics)) {
        return rollback_document_moves($staged)
            ? 'Statistik tidak dapat disimpan; penghapusan dibatalkan.'
            : 'Statistik tidak dapat disimpan dan sebagian berkas tidak dapat dikembalikan; periksa penyimpanan.';
    }
    if (!save_documents($documents)) {
        $statsRestored = save_document_statistics($statistics);
        $filesRestored = rollback_document_moves($staged);
        return $statsRestored && $filesRestored
            ? 'Metadata tidak dapat disimpan; penghapusan dibatalkan.'
            : 'Metadata tidak dapat disimpan dan pemulihan tidak lengkap; periksa penyimpanan.';
    }
    $leftover = 0;
    foreach ($staged as $file) {
        if (!unlink($file['to'])) {
            $leftover++;
        }
    }
    if ($leftover > 0) {
        admin_audit('Sisa karantina penghapusan', "$leftover file sementara di data/ perlu dibersihkan manual.");
        return "Dokumen tidak lagi terdaftar, tetapi $leftover berkas sementara di data/ gagal dihapus; hubungi administrator server.";
    }
    return null;
}

function render_admin_document_table(array $documents): void
{
    if (!$documents) {
        return;
    }
    $folderRules = load_folder_visibility();
    ?>
    <table class="admin-table admin-table-documents">
      <thead><tr><th><span class="visually-hidden">Pilih dokumen</span></th><th>Nama File</th><th>Visibilitas</th><th>Ukuran</th><th>Tanggal</th><th>Dilihat</th><th>Diunduh</th><th>Aksi</th></tr></thead>
      <tbody>
        <?php foreach ($documents as $document): ?>
          <?php $statistics = document_stat($document['path']); $visibility = document_access_level($document, $document['path'], $folderRules); ?>
          <tr>
            <td class="admin-select-cell"><input class="bulk-document-check" type="checkbox" name="paths[]" value="<?= e($document['path']) ?>" form="bulk-form" aria-label="Pilih <?= e($document['filename']) ?>"></td>
            <td data-label="Nama File"><strong><?= e($document['filename']) ?></strong></td>
            <td data-label="Visibilitas"><span class="visibility-badge visibility-badge--<?= e($visibility) ?>"><?= e(visibility_label($visibility)) ?></span><?php if (document_pin_hash($document) !== null): ?> <span class="pin-indicator" title="PIN enam digit aktif">PIN aktif</span><?php endif; ?></td>
            <td data-label="Ukuran"><span><?= e(format_file_size($document['size'])) ?></span></td>
            <td data-label="Tanggal"><span><?= e(format_publication_date($document['published_at'])) ?></span></td>
            <td data-label="Dilihat"><span><?= $statistics['views'] ?> kali</span></td>
            <td data-label="Diunduh"><span><?= $statistics['downloads'] ?> kali</span></td>
            <td data-label="Aksi">
              <a class="button" href="<?= e(document_public_url($document)) ?>" target="_blank" rel="noopener">Lihat</a>
              <button class="button" type="button" data-copy-link="<?= e(document_public_url($document)) ?>" aria-label="Salin tautan <?= e($document['filename']) ?>"<?= $visibility === 'hidden' ? ' title="Dokumen Privat hanya bisa dibuka admin"' : '' ?>><span class="copy-label" aria-live="polite">Salin Link</span></button>
              <a class="button" href="admin.php?page=unggah&amp;edit=<?= rawurlencode($document['path']) ?>">Ubah</a>
              <form method="post" onsubmit="return confirm('Hapus dokumen ini?')">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="path" value="<?= e($document['path']) ?>">
                <button class="button button-danger" type="submit">Hapus</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php
}

function render_admin_document_folders(array $children, string $parentPath = '', ?array $folderRules = null): void
{
    $folderRules ??= load_folder_visibility();
    foreach ($children as $name => $node):
        $currentDir = $parentPath === '' ? (string) $name : $parentPath . '/' . $name;
        ?>
        <details class="folder-node" open>
          <summary>
            <span class="folder-title"><?= e((string) $name) ?></span>
            <span class="folder-count"><?= document_tree_count($node) ?> dokumen</span>
            <?php $folderVisibility = folder_is_unlisted($currentDir, $folderRules) ? 'unlisted' : 'public'; ?>
            <span class="visibility-badge visibility-badge--<?= $folderVisibility ?>"><?= e(visibility_label($folderVisibility)) ?></span>
            <button type="button" class="button btn-folder-action" data-folder-path="<?= e($currentDir) ?>" data-folder-name="<?= e((string) $name) ?>">Ganti Nama</button>
          </summary>
          <div class="folder-body">
            <?php render_admin_document_table($node['documents']); ?>
            <?php render_admin_document_folders($node['children'], $currentDir, $folderRules); ?>
          </div>
        </details>
        <?php
    endforeach;
}

function unpublished_file_tree(array $files): array
{
    $tree = ['files' => [], 'children' => []];
    foreach ($files as $path => $_file) {
        $directory = str_replace('\\', '/', dirname($path));
        $segments = $directory === '.' ? [] : explode('/', $directory);
        $node =& $tree;
        foreach ($segments as $segment) {
            if (!isset($node['children'][$segment])) {
                $node['children'][$segment] = ['files' => [], 'children' => []];
            }
            $node =& $node['children'][$segment];
        }
        $node['files'][] = $path;
        unset($node);
    }
    sort_unpublished_file_tree($tree);
    return $tree;
}

function sort_unpublished_file_tree(array &$node): void
{
    usort($node['files'], static fn (string $a, string $b): int => strnatcasecmp(basename($a), basename($b)));
    uksort($node['children'], static fn (string $a, string $b): int => strnatcasecmp($a, $b));
    foreach ($node['children'] as &$child) {
        sort_unpublished_file_tree($child);
    }
    unset($child);
}

function unpublished_file_tree_count(array $node): int
{
    $count = count($node['files']);
    foreach ($node['children'] as $child) {
        $count += unpublished_file_tree_count($child);
    }
    return $count;
}

function render_admin_unpublished_table(array $files): void
{
    if (!$files) {
        return;
    }
    ?>
    <table class="admin-table">
      <thead><tr><th>Nama File</th><th>Ukuran</th><th>Aksi</th></tr></thead>
      <tbody>
        <?php foreach ($files as $path): ?>
          <?php $filePath = absolute_file_path($path); ?>
          <tr><td data-label="Nama File"><span><?= e(basename($path)) ?></span> <span class="visibility-badge visibility-badge--draft">Belum Terbit</span></td><td data-label="Ukuran"><span><?= e(format_file_size($filePath ? (filesize($filePath) ?: 0) : 0)) ?></span></td><td data-label="Aksi"><a class="button button-primary" href="admin.php?page=unggah&amp;edit=<?= rawurlencode($path) ?>">Isi data publikasi</a></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php
}

function render_admin_unpublished_folders(array $children, string $parentPath = ''): void
{
    foreach ($children as $name => $node):
        $currentDir = $parentPath === '' ? (string) $name : $parentPath . '/' . $name;
        ?>
        <details class="folder-node" open>
          <summary>
            <span class="folder-title"><?= e((string) $name) ?></span>
            <span class="folder-count"><?= unpublished_file_tree_count($node) ?> file</span>
            <?php $folderVisibility = folder_is_unlisted($currentDir) ? 'unlisted' : 'public'; ?>
            <span class="visibility-badge visibility-badge--<?= $folderVisibility ?>"><?= e(visibility_label($folderVisibility)) ?></span>
            <button type="button" class="button btn-folder-action" data-folder-path="<?= e($currentDir) ?>" data-folder-name="<?= e((string) $name) ?>">Ganti Nama</button>
          </summary>
          <div class="folder-body">
            <?php render_admin_unpublished_table($node['files']); ?>
            <?php render_admin_unpublished_folders($node['children'], $currentDir); ?>
          </div>
        </details>
        <?php
    endforeach;
}

$remaining = login_throttle_remaining();

if (!is_admin() && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = (string) ($_POST['username'] ?? '');
    $password = (string) ($_POST['password'] ?? '');
    if ($remaining > 0) {
        $error = "Terlalu banyak percobaan login (3 kali gagal). Akses diblokir sementara selama 5 menit. Silakan coba lagi dalam {$remaining} detik.";
    } elseif (hash_equals($credentials['username'], $username) && password_verify($password, $credentials['password_hash'])) {
        clear_failed_logins();
        session_regenerate_id(true);
        $_SESSION['is_admin'] = true;
        $_SESSION['admin_username'] = $credentials['username'];
        csrf_token();
        admin_audit('Login', 'Berhasil masuk ke dashboard.');
        redirect('admin.php');
    } else {
        record_failed_login();
        $remaining = login_throttle_remaining();
        if ($remaining > 0) {
            $error = "Terlalu banyak percobaan login (3 kali gagal). Akses diblokir sementara selama 5 menit. Silakan coba lagi dalam {$remaining} detik.";
        } else {
            $attempts = recent_login_attempts()[login_attempt_key()] ?? [];
            $attemptsLeft = max(0, LOGIN_MAX_ATTEMPTS - count($attempts));
            $error = "Username atau password tidak tepat. Sisa percobaan: {$attemptsLeft}.";
        }
    }
} elseif (!is_admin() && $remaining > 0) {
    $error = "Terlalu banyak percobaan login (3 kali gagal). Akses diblokir sementara selama 5 menit. Silakan coba lagi dalam {$remaining} detik.";
}

if (is_admin() && $_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'logout') {
    verify_csrf();
    admin_audit('Logout', 'Keluar dari dashboard.');
    $_SESSION = [];
    session_destroy();
    redirect(app_url());
}

if (is_admin() && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');
    $documents = load_documents();

    if ($action === 'identity') {
        $appName = trim((string) ($_POST['app_name'] ?? ''));
        $appHeadline = trim((string) ($_POST['app_headline'] ?? ''));
        $appSubtitle = trim((string) ($_POST['app_subtitle'] ?? ''));
        $themeStyle = (string) ($_POST['theme_style'] ?? '');
        $themeMode = (string) ($_POST['theme_mode'] ?? '');
        $logoUpload = $_FILES['app_logo'] ?? null;

        if ($appName === '') {
            $error = 'Nama instansi / aplikasi tidak boleh kosong.';
        } elseif (!in_array($themeStyle, ['hud', 'classic', 'standart'], true)) {
            $error = 'Pilihan tema publik tidak valid.';
        } elseif (!in_array($themeMode, ['dark', 'light'], true)) {
            $error = 'Pilihan mode tema tidak valid.';
        } else {
            $settings = dynamic_app_settings();
            $settings['app_name'] = $appName;
            $settings['app_headline'] = $appHeadline;
            $settings['app_subtitle'] = $appSubtitle;
            $settings['theme_style'] = $themeStyle;
            $settings['theme_mode'] = $themeMode;

            if (is_array($logoUpload) && ($logoUpload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                $logoExt = strtolower(pathinfo((string) $logoUpload['name'], PATHINFO_EXTENSION));
                $allowedLogoExts = ['png', 'jpg', 'jpeg', 'svg', 'webp'];
                $mime = (new finfo(FILEINFO_MIME_TYPE))->file((string) $logoUpload['tmp_name']) ?: '';
                $allowedMimes = ['image/png', 'image/jpeg', 'image/svg+xml', 'image/webp'];

                if (!in_array($logoExt, $allowedLogoExts, true) || !in_array($mime, $allowedMimes, true)) {
                    $error = 'Format berkas logo harus berupa PNG, JPG, WebP, atau SVG.';
                } elseif ((int) $logoUpload['size'] > 2 * 1024 * 1024) {
                    $error = 'Ukuran berkas logo maksimal 2 MB.';
                } else {
                    $newLogoFile = 'logo.' . ($logoExt === 'jpeg' ? 'jpg' : $logoExt);
                    $logoTarget = APP_ROOT . '/' . $newLogoFile;
                    if (!move_uploaded_file((string) $logoUpload['tmp_name'], $logoTarget)) {
                        $error = 'Gagal menyimpan berkas logo.';
                    } else {
                        $settings['app_logo'] = $newLogoFile;
                    }
                }
            }

            if (!$error) {
                if (!save_dynamic_app_settings($settings)) {
                    $error = 'Pengaturan identitas tidak dapat disimpan. Pastikan folder data dapat ditulis.';
                } else {
                    admin_audit('Pengaturan Identitas', "Nama: $appName; tema: $themeStyle / $themeMode");
                    redirect('admin.php?page=identitas&notice=' . rawurlencode('Identitas dan tema publik berhasil diperbarui.'));
                }
            }
        }
    }

    if ($action === 'account') {
        $currentPassword = (string) ($_POST['current_password'] ?? '');
        $requestedUsername = trim((string) ($_POST['new_username'] ?? ''));
        $newPassword = (string) ($_POST['new_password'] ?? '');
        $passwordConfirmation = (string) ($_POST['confirm_password'] ?? '');
        $currentCredentials = admin_credentials();
        $username = $requestedUsername === '' ? $currentCredentials['username'] : $requestedUsername;

        if (!password_verify($currentPassword, $currentCredentials['password_hash'])) {
            $error = 'Password saat ini tidak tepat.';
        } elseif (!preg_match('/\A[A-Za-z0-9._-]{3,64}\z/', $username)) {
            $error = 'Username harus terdiri dari 3-64 huruf, angka, titik, strip, atau underscore.';
        } elseif (($newPassword === '') !== ($passwordConfirmation === '')) {
            $error = 'Isi password baru dan konfirmasi password secara bersamaan.';
        } elseif ($newPassword !== '' && $newPassword !== $passwordConfirmation) {
            $error = 'Konfirmasi password baru tidak sama.';
        } elseif ($newPassword !== '' && strlen($newPassword) < 12) {
            $error = 'Password baru minimal terdiri dari 12 karakter.';
        } elseif ($username === $currentCredentials['username'] && $newPassword === '') {
            $error = 'Masukkan username baru atau password baru untuk disimpan.';
        } else {
            $passwordHash = $newPassword === '' ? $currentCredentials['password_hash'] : password_hash($newPassword, PASSWORD_DEFAULT);
            if (!save_admin_credentials($username, $passwordHash)) {
                $error = 'Pengaturan akun tidak dapat disimpan. Pastikan folder data dapat ditulis.';
            } else {
                session_regenerate_id(true);
                $_SESSION['admin_username'] = $username;
                unset($_SESSION['csrf_token']);
                csrf_token();
                admin_audit('Pengaturan akun', $newPassword === '' ? 'Username diperbarui.' : 'Kredensial akun diperbarui.');
                redirect('admin.php?page=akun&notice=' . rawurlencode('Pengaturan akun berhasil diperbarui.'));
            }
        }
    }

    if ($action === 'create_folder') {
        $parentFolder = safe_relative_directory((string) ($_POST['parent_folder'] ?? ''));
        $folderName = trim((string) ($_POST['folder_name'] ?? ''));
        $folderVisibility = (string) ($_POST['visibility'] ?? 'public');
        $folderPin = (string) ($_POST['pin'] ?? '');

        if ($parentFolder === null) {
            $error = 'Folder induk tidak valid.';
        } elseif (!in_array($folderVisibility, ['public', 'unlisted'], true)) {
            $error = 'Visibilitas folder tidak valid.';
        } elseif ($folderPin !== '' && ($folderVisibility !== 'unlisted' || !valid_document_pin($folderPin))) {
            $error = 'PIN folder hanya untuk Akses via Tautan dan harus terdiri dari enam angka.';
        } elseif (!preg_match('/\A[a-zA-Z0-9_\-\.\s]{1,100}\z/', $folderName) || $folderName === '.' || $folderName === '..') {
            $error = 'Nama folder baru hanya boleh berupa huruf, angka, spasi, titik, strip, atau underscore (1-100 karakter).';
        } else {
            $targetRel = $parentFolder === '' ? $folderName : $parentFolder . '/' . $folderName;
            if (absolute_directory_path($targetRel) !== null) {
                $error = 'Folder dengan nama tersebut sudah ada.';
            } else {
                $created = absolute_directory_path($targetRel, true);
                if ($created === null) {
                    $error = 'Folder gagal dibuat.';
                } else {
                    $rules = load_folder_visibility();
                    if ($folderVisibility === 'unlisted') {
                        $rules[$targetRel] = 'unlisted';
                    }
                    $pins = load_folder_pins();
                    if ($folderPin !== '') {
                        $pins[$targetRel] = password_hash($folderPin, PASSWORD_DEFAULT);
                    }
                    if ($folderPin !== '' && !save_folder_pins($pins)) {
                        rmdir($created);
                        $error = 'PIN folder tidak dapat disimpan.';
                    } elseif ($folderVisibility === 'unlisted' && !save_folder_visibility($rules)) {
                        if ($folderPin !== '') {
                            unset($pins[$targetRel]);
                            save_folder_pins($pins);
                        }
                        rmdir($created);
                        $error = 'Visibilitas folder tidak dapat disimpan.';
                    } else {
                        admin_audit('Buat folder', "$targetRel ($folderVisibility; PIN: " . ($folderPin !== '' ? 'aktif' : 'tidak aktif') . ')');
                        redirect('admin.php?page=folder&notice=' . rawurlencode("Folder $targetRel berhasil dibuat."));
                    }
                }
            }
        }
    }

    if ($action === 'folder_visibility') {
        $folder = safe_relative_directory((string) ($_POST['folder'] ?? ''));
        $visibility = (string) ($_POST['visibility'] ?? '');
        $pinAction = (string) ($_POST['pin_action'] ?? 'keep');
        $pin = (string) ($_POST['pin'] ?? '');
        if ($folder === null || $folder === '' || absolute_directory_path($folder) === null
            || !in_array($visibility, ['public', 'unlisted'], true)) {
            $error = 'Folder atau visibilitas tidak valid.';
        } elseif (!in_array($pinAction, ['keep', 'set', 'remove'], true)) {
            $error = 'Pengaturan PIN folder tidak valid.';
        } elseif ($pinAction === 'set' && ($visibility !== 'unlisted' || !valid_document_pin($pin))) {
            $error = 'PIN folder hanya untuk Akses via Tautan dan harus terdiri dari enam angka.';
        } elseif ($pinAction !== 'set' && $pin !== '') {
            $error = 'Pilih Tetapkan / ganti PIN sebelum mengisi PIN folder.';
        } else {
            $rules = load_folder_visibility();
            $originalPins = load_folder_pins();
            $pins = $originalPins;
            $previousVisibility = $rules[$folder] ?? 'public';
            if ($visibility === 'public') {
                unset($rules[$folder]);
            } else {
                $rules[$folder] = 'unlisted';
            }
            if ($visibility !== 'unlisted' || $previousVisibility !== 'unlisted' || $pinAction === 'remove') {
                unset($pins[$folder]);
            }
            if ($pinAction === 'set') {
                $pins[$folder] = password_hash($pin, PASSWORD_DEFAULT);
            }
            if (!save_folder_pins($pins)) {
                $error = 'PIN folder tidak dapat disimpan.';
            } elseif (!save_folder_visibility($rules)) {
                save_folder_pins($originalPins);
                $error = 'Visibilitas folder tidak dapat disimpan.';
            } else {
                admin_audit('Visibilitas folder', "$folder: $visibility (PIN: " . (isset($pins[$folder]) ? 'aktif' : 'tidak aktif') . ')');
                redirect('admin.php?page=folder&notice=' . rawurlencode('Visibilitas folder berhasil disimpan.'));
            }
        }
    }

    if ($action === 'rename_folder') {
        $oldFolder = safe_relative_directory((string) ($_POST['old_folder'] ?? ''));
        $newName = trim((string) ($_POST['new_name'] ?? ''));

        if ($oldFolder === null || $oldFolder === '') {
            $error = 'Folder yang akan diganti namanya tidak valid.';
        } elseif (!preg_match('/\A[a-zA-Z0-9_\-\.\s]{1,100}\z/', $newName) || $newName === '.' || $newName === '..') {
            $error = 'Nama folder baru hanya boleh berupa huruf, angka, spasi, titik, strip, atau underscore (1-100 karakter).';
        } else {
            $parentDir = str_replace('\\', '/', dirname($oldFolder));
            $parentDir = $parentDir === '.' ? '' : $parentDir;
            $newFolder = $parentDir === '' ? $newName : $parentDir . '/' . $newName;

            $oldAbs = absolute_directory_path($oldFolder);
            if ($oldAbs === null || !is_dir($oldAbs)) {
                $error = 'Folder lama tidak ditemukan.';
            } elseif ($oldFolder === $newFolder) {
                redirect('admin.php?page=folder');
            } elseif (absolute_directory_path($newFolder) !== null) {
                $error = 'Folder dengan nama tersebut sudah ada.';
            } else {
                $parentAbs = absolute_directory_path($parentDir);
                $newAbs = $parentAbs . '/' . $newName;
                if (!rename($oldAbs, $newAbs)) {
                    $error = 'Gagal mengganti nama folder pada sistem berkas.';
                } else {
                    $rules = load_folder_visibility();
                    $updatedRules = remap_folder_entries($rules, $oldFolder, $newFolder);
                    $pins = load_folder_pins();
                    $updatedPins = remap_folder_entries($pins, $oldFolder, $newFolder);
                    $updatedDocuments = [];
                    $oldPrefix = $oldFolder . '/';
                    $newPrefix = $newFolder . '/';
                    foreach ($documents as $docPath => $docData) {
                        if (str_starts_with($docPath, $oldPrefix)) {
                            $newDocPath = $newPrefix . substr($docPath, strlen($oldPrefix));
                            $updatedDocuments[$newDocPath] = $docData;
                        } else {
                            $updatedDocuments[$docPath] = $docData;
                        }
                    }
                    if (!save_folder_pins($updatedPins)) {
                        rename($newAbs, $oldAbs);
                        $error = 'PIN folder tidak dapat dipindahkan; penggantian nama dibatalkan.';
                    } elseif (!save_folder_visibility($updatedRules)) {
                        save_folder_pins($pins);
                        rename($newAbs, $oldAbs);
                        $error = 'Visibilitas folder tidak dapat dipindahkan; penggantian nama dibatalkan.';
                    } elseif (!save_documents($updatedDocuments)) {
                        save_folder_visibility($rules);
                        save_folder_pins($pins);
                        rename($newAbs, $oldAbs);
                        $error = 'Data dokumen tidak dapat disimpan; penggantian nama dibatalkan.';
                    } else {
                        $statistics = document_statistics();
                        $updatedStats = [];
                        foreach ($statistics as $statPath => $statData) {
                            if (str_starts_with($statPath, $oldPrefix)) {
                                $newStatPath = $newPrefix . substr($statPath, strlen($oldPrefix));
                                $updatedStats[$newStatPath] = $statData;
                            } else {
                                $updatedStats[$statPath] = $statData;
                            }
                        }
                        save_document_statistics($updatedStats);

                        admin_audit('Ganti nama folder', "$oldFolder -> $newFolder");
                        $redirectPage = in_array($page ?? '', ['terbit', 'folder', 'belum-terbit'], true) ? $page : 'folder';
                        redirect("admin.php?page=$redirectPage&notice=" . rawurlencode("Folder berhasil diubah namanya menjadi $newName."));
                    }
                }
            }
        }
    }

    if ($action === 'delete_folder') {
        $targetRel = safe_relative_directory((string) ($_POST['folder'] ?? ''));
        if ($targetRel === null || $targetRel === '') {
            $error = 'Folder tidak valid atau tidak boleh menghapus folder utama.';
        } else {
            $targetAbs = absolute_directory_path($targetRel);
            if ($targetAbs === null || !is_dir($targetAbs)) {
                $error = 'Folder tidak ditemukan.';
            } else {
                $items = array_diff(scandir($targetAbs) ?: [], ['.', '..']);
                if (count($items) > 0) {
                    $error = 'Folder tidak dapat dihapus karena masih berisi berkas atau subfolder.';
                } else {
                    if (!@rmdir($targetAbs)) {
                        $error = 'Gagal menghapus folder pada sistem berkas.';
                    } else {
                        $rules = load_folder_visibility();
                        $originalPins = load_folder_pins();
                        $pins = $originalPins;
                        unset($rules[$targetRel]);
                        unset($pins[$targetRel]);
                        if (!save_folder_pins($pins)) {
                            mkdir($targetAbs, 0755);
                            $error = 'PIN folder tidak dapat diperbarui; penghapusan dibatalkan.';
                        } elseif (!save_folder_visibility($rules)) {
                            save_folder_pins($originalPins);
                            mkdir($targetAbs, 0755);
                            $error = 'Visibilitas folder tidak dapat diperbarui; penghapusan dibatalkan.';
                        } else {
                            admin_audit('Hapus folder', $targetRel);
                            redirect('admin.php?page=folder&notice=' . rawurlencode("Folder $targetRel berhasil dihapus."));
                        }
                    }
                }
            }
        }
    }

    if ($action === 'publish' || $action === 'update') {
        $path = safe_relative_path((string) ($_POST['path'] ?? ''));
        $publishedAt = trim((string) ($_POST['published_at'] ?? ''));
        $existingDocument = is_array($documents[$path ?? ''] ?? null) ? $documents[$path] : [];
        $visibility = (string) ($_POST['visibility'] ?? document_visibility($existingDocument));
        $pinAction = (string) ($_POST['pin_action'] ?? 'keep');
        $pin = (string) ($_POST['pin'] ?? '');
        $targetFolder = safe_relative_directory((string) ($_POST['target_folder'] ?? ''));
        $targetNewFolder = safe_relative_directory((string) ($_POST['new_target_folder'] ?? ''));

        $destination = $targetFolder === null || $targetNewFolder === null
            ? null
            : implode('/', array_filter([$targetFolder, $targetNewFolder], static fn (string $p): bool => $p !== ''));

        if ($path === null || absolute_file_path($path) === null || ($publishedAt !== '' && !valid_publication_date($publishedAt))) {
            $error = 'Tanggal publikasi harus menggunakan format tanggal yang valid.';
        } elseif (!in_array($visibility, ['public', 'unlisted', 'hidden'], true)) {
            $error = 'Visibilitas dokumen tidak valid.';
        } elseif (!in_array($pinAction, ['keep', 'set', 'remove'], true)) {
            $error = 'Pilihan pengaturan PIN tidak valid.';
        } elseif ($pinAction === 'set' && ($visibility !== 'unlisted' || !valid_document_pin($pin))) {
            $error = 'PIN hanya dapat dipasang pada dokumen Akses via Tautan dan harus terdiri dari enam angka.';
        } elseif ($pinAction !== 'set' && $pin !== '') {
            $error = 'Pilih Tetapkan / ganti PIN sebelum mengisi angka PIN.';
        } elseif ($action === 'update' && $destination === null) {
            $error = 'Folder tujuan tidak valid.';
        } else {
            $displayName = document_display_name($existingDocument, $path);

            $currentDir = str_replace('\\', '/', dirname($path));
            $currentDir = $currentDir === '.' ? '' : $currentDir;
            $finalPath = $path;

            if ($action === 'update' && $destination !== null && $destination !== $currentDir) {
                $targetDirAbs = absolute_directory_path($destination, true);
                if ($targetDirAbs === null) {
                    $error = 'Folder tujuan tidak dapat dibuat atau tidak valid.';
                } else {
                    $baseFileName = basename($path);
                    $newRelPath = $destination === '' ? $baseFileName : $destination . '/' . $baseFileName;
                    $oldAbs = absolute_file_path($path);
                    $newAbs = $targetDirAbs . '/' . $baseFileName;

                    if (is_file($newAbs) && $oldAbs !== $newAbs) {
                        $error = 'File dengan nama yang sama sudah ada di folder tujuan.';
                    } elseif (!rename($oldAbs, $newAbs)) {
                        $error = 'Gagal memindahkan file fisik ke folder baru.';
                    } else {
                        $finalPath = $newRelPath;
                        unset($documents[$path]);

                        $statistics = document_statistics();
                        if (isset($statistics[$path])) {
                            $statistics[$finalPath] = $statistics[$path];
                            unset($statistics[$path]);
                            save_document_statistics($statistics);
                        }
                    }
                }
            }

            if (!$error) {
                $updatedDocument = array_replace($existingDocument, [
                    'filename' => $displayName,
                    'published_at' => $publishedAt,
                    'visibility' => $visibility,
                ]);
                if ($visibility !== 'unlisted' || $pinAction === 'remove' || document_visibility($existingDocument) !== 'unlisted') {
                    unset($updatedDocument['pin_hash']);
                }
                if ($pinAction === 'set') {
                    $updatedDocument['pin_hash'] = password_hash($pin, PASSWORD_DEFAULT);
                }
                $documents[$finalPath] = $updatedDocument;
                if (!save_documents($documents)) {
                    $error = 'Data dokumen tidak dapat disimpan.';
                } else {
                    admin_audit($action === 'publish' ? 'Publikasi dokumen' : 'Pembaruan dokumen', $displayName . ($finalPath !== $path ? " (pindah ke $destination)" : '') . " (visibilitas: $visibility, PIN: " . (document_pin_hash($updatedDocument) !== null ? 'aktif' : 'tidak aktif') . ')');
                    redirect('admin.php?page=terbit&notice=' . rawurlencode($action === 'publish' ? 'Dokumen dipublikasikan.' : 'Data dokumen diperbarui.'));
                }
            }
        }
    }

    if ($action === 'upload') {
        $publishedAt = trim((string) ($_POST['published_at'] ?? ''));
        $visibility = (string) ($_POST['visibility'] ?? 'public');
        $pin = (string) ($_POST['pin'] ?? '');
        $folder = safe_relative_directory($uploadFolder);
        $newFolder = safe_relative_directory($uploadNewFolder);
        $destination = $folder === null || $newFolder === null
            ? null
            : implode('/', array_filter([$folder, $newFolder], static fn (string $part): bool => $part !== ''));

        $rawUpload = $_FILES['documents'] ?? ($_FILES['document'] ?? null);
        $uploads = normalize_uploaded_files($rawUpload);

        if (!in_array($visibility, ['public', 'unlisted', 'hidden'], true)) {
            $error = 'Visibilitas dokumen tidak valid.';
        } elseif ($pin !== '' && ($visibility !== 'unlisted' || !valid_document_pin($pin))) {
            $error = 'PIN hanya dapat dipasang pada dokumen Akses via Tautan dan harus terdiri dari enam angka.';
        } elseif (($publishedAt !== '' && !valid_publication_date($publishedAt)) || !$uploads) {
            $error = 'Pilih setidaknya satu file dokumen dan gunakan tanggal publikasi yang valid bila diisi.';
        } elseif ($destination === null || ($targetDirectory = absolute_directory_path($destination, true)) === null) {
            $error = 'Folder tujuan tidak valid atau tidak dapat dibuat.';
        } else {
            $successCount = 0;
            $failedNames = [];
            $storedFiles = [];
            $pinHash = $pin !== '' ? password_hash($pin, PASSWORD_DEFAULT) : null;
            $finfo = new finfo(FILEINFO_MIME_TYPE);

            foreach ($uploads as $item) {
                if ($item['error'] !== UPLOAD_ERR_OK) {
                    $failedNames[] = $item['name'] . ' (gagal kirim)';
                    continue;
                }
                if ($item['size'] > $config['max_upload_bytes']) {
                    $failedNames[] = $item['name'] . ' (melebihi batas ukuran)';
                    continue;
                }
                $originalName = basename(str_replace('\\', '/', $item['name']));
                $extension = supported_extension($originalName);
                $mime = $finfo->file($item['tmp_name']) ?: '';
                $firstBytes = file_get_contents($item['tmp_name'], false, null, 0, 4096) ?: '';

                if (preg_match('/\.(php[0-9]?|phtml|phar|phps|pht|exe|bat|cmd|sh|pl|py|cgi|asp|aspx)\b/i', $originalName)) {
                    $failedNames[] = $originalName . ' (format dilarang)';
                    continue;
                }
                if (preg_match('/<\?(php|=)|<script\s+language\s*=\s*["\']?php/i', $firstBytes)) {
                    $failedNames[] = $originalName . ' (isi mengandung skrip)';
                    continue;
                }
                if ($extension === null || !upload_mime_is_allowed($extension, $mime)) {
                    $failedNames[] = $originalName . ' (ekstensi/mime tidak sesuai)';
                    continue;
                }

                $storedName = bin2hex(random_bytes(16)) . '.' . $extension;
                $storedPath = $destination === '' ? $storedName : $destination . '/' . $storedName;
                $target = $targetDirectory . '/' . $storedName;

                if (!move_uploaded_file($item['tmp_name'], $target)) {
                    $failedNames[] = $originalName . ' (gagal simpan)';
                    continue;
                }

                $documents[$storedPath] = ['filename' => $originalName, 'published_at' => $publishedAt, 'visibility' => $visibility];
                if ($pinHash !== null) {
                    $documents[$storedPath]['pin_hash'] = $pinHash;
                }
                $storedFiles[] = $target;
                $successCount++;
            }

            if ($successCount > 0) {
                if (!save_documents($documents)) {
                    foreach ($storedFiles as $storedFile) {
                        unlink($storedFile);
                    }
                    $error = 'Data dokumen tidak dapat disimpan; unggahan dibatalkan.';
                } else {
                    $msg = $successCount === 1
                    ? "1 dokumen berhasil diunggah dan dipublikasikan."
                    : "$successCount dokumen berhasil diunggah dan dipublikasikan.";
                    if ($failedNames) {
                        $msg .= ' Beberapa berkas gagal: ' . implode(', ', $failedNames);
                    }
                    admin_audit('Unggah dokumen (batch)', "$successCount berkas ke " . ($destination ?: 'Dokumen Utama') . " (visibilitas: $visibility, PIN: " . ($pinHash !== null ? 'aktif' : 'tidak aktif') . ')');
                    redirect('admin.php?page=terbit&notice=' . rawurlencode($msg));
                }
            } else {
                $error = 'Tidak ada berkas yang berhasil diunggah: ' . implode(', ', $failedNames);
            }
        }
    }

    if ($action === 'bulk_documents') {
        $selected = $_POST['paths'] ?? null;
        $bulkAction = (string) ($_POST['bulk_action'] ?? '');
        if (!is_array($selected) || !$selected || count($selected) > 500) {
            $error = 'Pilih antara 1 dan 500 dokumen untuk aksi massal.';
        } else {
            $paths = [];
            foreach ($selected as $path) {
                if (!is_string($path) || safe_relative_path($path) !== $path
                    || !is_array($documents[$path] ?? null) || absolute_file_path($path) === null) {
                    $error = 'Pilihan dokumen tidak valid atau berkas sudah tidak tersedia.';
                    break;
                }
                $paths[$path] = $path;
            }
            $paths = array_values($paths);
        }

        if ($error === '') {
            $count = count($paths);
            if ($bulkAction === 'visibility') {
                $visibility = (string) ($_POST['bulk_visibility'] ?? '');
                if (!in_array($visibility, ['public', 'unlisted', 'hidden'], true)) {
                    $error = 'Visibilitas massal tidak valid.';
                } else {
                    foreach ($paths as $path) {
                        $previousVisibility = document_visibility($documents[$path]);
                        $documents[$path]['visibility'] = $visibility;
                        if ($visibility !== 'unlisted' || $previousVisibility !== 'unlisted') {
                            unset($documents[$path]['pin_hash']);
                        }
                    }
                    if (!save_documents($documents)) {
                        $error = 'Visibilitas dokumen tidak dapat disimpan.';
                    } else {
                        admin_audit('Visibilitas massal', "$count dokumen -> $visibility: " . implode(', ', $paths));
                        redirect('admin.php?page=terbit&notice=' . rawurlencode("Visibilitas $count dokumen berhasil diubah."));
                    }
                }
            } elseif ($bulkAction === 'move') {
                $target = $_POST['bulk_target_folder'] ?? null;
                if (!is_string($target) || safe_relative_directory($target) !== $target) {
                    $error = 'Folder tujuan tidak valid.';
                } else {
                    $error = bulk_move_documents($documents, $paths, $target) ?? '';
                    if ($error === '') {
                        admin_audit('Pindah massal', "$count dokumen -> " . ($target ?: 'Dokumen Utama') . ': ' . implode(', ', $paths));
                        redirect('admin.php?page=terbit&notice=' . rawurlencode("$count dokumen berhasil dipindahkan."));
                    }
                }
            } elseif ($bulkAction === 'delete') {
                if (($_POST['confirm_delete'] ?? null) !== '1') {
                    $error = 'Centang konfirmasi hapus permanen sebelum menghapus dokumen.';
                } else {
                    $error = bulk_delete_documents($documents, $paths) ?? '';
                    if ($error === '') {
                        admin_audit('Hapus massal', "$count dokumen: " . implode(', ', $paths));
                        redirect('admin.php?page=terbit&notice=' . rawurlencode("$count dokumen berhasil dihapus."));
                    }
                }
            } else {
                $error = 'Aksi massal tidak valid.';
            }
        }
    }

    if ($action === 'delete') {
        $path = safe_relative_path((string) ($_POST['path'] ?? ''));
        $absolutePath = $path === null ? null : absolute_file_path($path);
        if ($path === null || $absolutePath === null || !isset($documents[$path])) {
            $error = 'Dokumen tidak ditemukan.';
        } elseif (!unlink($absolutePath)) {
            $error = 'File tidak dapat dihapus.';
        } else {
            $documentName = document_display_name($documents[$path], $path);
            unset($documents[$path]);
            if (!save_documents($documents)) {
                $error = 'File sudah dihapus, tetapi data publikasi tidak dapat diperbarui.';
            } else {
                admin_audit('Hapus dokumen', $documentName);
                redirect('admin.php?page=terbit&notice=' . rawurlencode('Dokumen dihapus.'));
            }
        }
    }
}

if (!is_admin()):
    page_header('Login Admin', 'admin-page', 'assets/admin.css');
    ?>
<main class="shell">
  <section class="card form-card">
    <div class="login-intro">
      <div class="login-brand"><?php if (is_file(APP_ROOT . '/' . app_logo())): ?><img src="<?= e(app_url(app_logo())) ?>" alt=""><?php endif; ?><span><?= e(app_brand()) ?></span></div>
      <h1>Masuk ke SIMPUN</h1>
      <p class="subtitle">Sistem Informasi Manajemen Publikasi Untuk Naskah</p>
      <?php render_public_mode_switcher(); ?>
    </div>
    <div class="login-fields">
    <?php if ($error): ?><p class="error" id="lockout-timer" data-seconds="<?= $remaining ?>"><?= e($error) ?></p><?php endif; ?>
    <form method="post" action="<?= e(app_url('login')) ?>" autocomplete="on">
      <input type="hidden" name="secret_login" value="1">
      <label>Username<input name="username" required autofocus <?= $remaining > 0 ? 'disabled' : '' ?>></label>
      <label>Password<input name="password" type="password" required <?= $remaining > 0 ? 'disabled' : '' ?>></label>
      <div class="form-actions"><button class="button button-primary" type="submit" <?= $remaining > 0 ? 'disabled' : '' ?>>Masuk</button></div>
    </form>
    <?php if ($remaining > 0): ?>
    <script>
      (function() {
        var timerEl = document.getElementById('lockout-timer');
        if (!timerEl) return;
        var s = parseInt(timerEl.getAttribute('data-seconds'), 10);
        if (!s || s <= 0) return;
        var inputs = document.querySelectorAll('.login-fields input, .login-fields button');
        var tick = setInterval(function() {
          s--;
          if (s <= 0) {
            clearInterval(tick);
            timerEl.textContent = 'Masa blokir berakhir. Silakan muat ulang halaman untuk mencoba login kembali.';
            inputs.forEach(function(el) { el.disabled = false; });
          } else {
            timerEl.textContent = 'Terlalu banyak percobaan login (3 kali gagal). Akses diblokir sementara selama 5 menit. Silakan coba lagi dalam ' + s + ' detik.';
          }
        }, 1000);
      })();
    </script>
    <?php endif; ?>
    </div>
  </section>
</main>
<?php page_footer(); exit; endif;

$documents = load_documents();
$files = discover_files();
$directories = discover_directories();
$publishedTree = published_document_tree();
$publishedDocuments = published_documents();
$unpublished = array_diff_key($files, $documents);
$publishedSize = array_sum(array_column($publishedDocuments, 'size'));
$notice = trim((string) ($_GET['notice'] ?? ''));
$pages = [
    'dashboard' => ['title' => 'Dashboard Statistik', 'description' => 'Pantau ringkasan publikasi, view, dan download dokumen.'],
    'unggah' => ['title' => 'Unggah Dokumen', 'description' => 'Unggah file baru atau lengkapi data publikasi file yang tersedia.'],
    'terbit' => ['title' => 'Dokumen Terbit', 'description' => 'Kelola dokumen Publik, Akses via Tautan, dan Privat.'],
    'folder' => ['title' => 'Kelola Folder', 'description' => 'Kelola dan ganti nama folder atau subfolder dokumen.'],
    'belum-terbit' => ['title' => 'File Belum Terbit', 'description' => 'Tinjau file yang belum dipublikasikan.'],
    'aktivitas' => ['title' => 'Riwayat Aktivitas', 'description' => 'Tinjau aktivitas pengelolaan dashboard terbaru.'],
    'identitas' => ['title' => 'Identitas Aplikasi', 'description' => 'Kustomisasi nama instansi, judul portal, tagline, dan logo resmi.'],
    'akun' => ['title' => 'Pengaturan Akun', 'description' => 'Perbarui username atau password administrator.'],
];
$page = (string) ($_GET['page'] ?? 'dashboard');
if (!isset($pages[$page])) {
    $page = 'dashboard';
}
$statistics = document_statistics();
$totalViews = array_sum(array_column($statistics, 'views'));
$totalDownloads = array_sum(array_column($statistics, 'downloads'));
$mostViewedDocuments = array_values(array_filter($publishedDocuments, static fn (array $document): bool => document_stat($document['path'])['views'] > 0));
usort($mostViewedDocuments, static function (array $left, array $right): int {
    $leftStatistics = document_stat($left['path']);
    $rightStatistics = document_stat($right['path']);
    return ($rightStatistics['views'] <=> $leftStatistics['views']) ?: ($rightStatistics['downloads'] <=> $leftStatistics['downloads']) ?: strnatcasecmp($left['filename'], $right['filename']);
});
$mostDownloadedDocuments = array_values(array_filter($publishedDocuments, static fn (array $document): bool => document_stat($document['path'])['downloads'] > 0));
usort($mostDownloadedDocuments, static function (array $left, array $right): int {
    $leftStatistics = document_stat($left['path']);
    $rightStatistics = document_stat($right['path']);
    return ($rightStatistics['downloads'] <=> $leftStatistics['downloads']) ?: ($rightStatistics['views'] <=> $leftStatistics['views']) ?: strnatcasecmp($left['filename'], $right['filename']);
});
$editPath = safe_relative_path((string) ($_GET['edit'] ?? ''));
if ($editPath !== null && !isset($_GET['page'])) {
    $page = 'unggah';
}
$editDocument = $editPath !== null ? ($documents[$editPath] ?? null) : null;
$editingExistingFile = $editPath !== null && absolute_file_path($editPath) !== null;
$auditEntries = recent_admin_audit();
page_header('Admin Dokumen', 'admin-page', 'assets/admin.css');
?>
<main class="shell">
  <div class="admin-layout">
    <aside class="admin-sidebar">
      <div class="admin-logo"><?php if (is_file(APP_ROOT . '/' . app_logo())): ?><img src="<?= e(app_url(app_logo())) ?>" alt=""><?php endif; ?><span><?= e(app_brand()) ?><small>Administrator</small></span></div>
      <button class="admin-nav-toggle" type="button" aria-controls="admin-navigation" aria-expanded="true">Menu administrasi</button>
      <nav class="admin-nav" id="admin-navigation" aria-label="Navigasi admin">
        <a class="<?= $page === 'dashboard' ? 'is-active' : '' ?>" href="admin.php?page=dashboard"<?= $page === 'dashboard' ? ' aria-current="page"' : '' ?>>Dashboard</a>
        <a class="<?= $page === 'unggah' ? 'is-active' : '' ?>" href="admin.php?page=unggah"<?= $page === 'unggah' ? ' aria-current="page"' : '' ?>>Unggah Dokumen</a>
        <a class="<?= $page === 'terbit' ? 'is-active' : '' ?>" href="admin.php?page=terbit"<?= $page === 'terbit' ? ' aria-current="page"' : '' ?>>Dokumen Terbit</a>
        <a class="<?= $page === 'folder' ? 'is-active' : '' ?>" href="admin.php?page=folder"<?= $page === 'folder' ? ' aria-current="page"' : '' ?>>Kelola Folder</a>
        <a class="<?= $page === 'belum-terbit' ? 'is-active' : '' ?>" href="admin.php?page=belum-terbit"<?= $page === 'belum-terbit' ? ' aria-current="page"' : '' ?>>Belum Terbit</a>
        <a class="<?= $page === 'aktivitas' ? 'is-active' : '' ?>" href="admin.php?page=aktivitas"<?= $page === 'aktivitas' ? ' aria-current="page"' : '' ?>>Riwayat Aktivitas</a>
        <a class="<?= $page === 'identitas' ? 'is-active' : '' ?>" href="admin.php?page=identitas"<?= $page === 'identitas' ? ' aria-current="page"' : '' ?>>Identitas Aplikasi</a>
        <a class="<?= $page === 'akun' ? 'is-active' : '' ?>" href="admin.php?page=akun"<?= $page === 'akun' ? ' aria-current="page"' : '' ?>>Pengaturan Akun</a>
        <a href="<?= e(app_url()) ?>">Lihat Publik</a>
        <form method="post" class="admin-logout-form"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="logout"><button type="submit">Keluar</button></form>
      </nav>
    </aside>
    <div class="admin-main">
      <header class="admin-hero">
        <div><h1><?= e($pages[$page]['title']) ?></h1><p class="subtitle"><?= e($pages[$page]['description']) ?></p></div>
        <?php if ($page === 'dashboard'): ?><a class="button" href="admin.php?page=unggah">Unggah Dokumen</a><?php endif; ?>
      </header>

      <?php if ($page === 'dashboard'): ?>
      <section class="dashboard-grid" aria-label="Ringkasan dokumen">
        <article class="metric"><span>Dokumen terbit</span><strong><?= count($publishedDocuments) ?></strong></article>
        <article class="metric"><span>Belum terbit</span><strong><?= count($unpublished) ?></strong></article>
        <article class="metric"><span>Folder aktif</span><strong><?= max(0, count($directories) - 1) ?></strong></article>
        <article class="metric"><span>Total ukuran terdaftar</span><strong><?= e(format_file_size($publishedSize)) ?></strong></article>
        <article class="metric"><span>Total dilihat</span><strong><?= $totalViews ?></strong></article>
        <article class="metric"><span>Total diunduh</span><strong><?= $totalDownloads ?></strong></article>
      </section>

      <div class="dashboard-leaderboards">
        <section class="card admin-section">
          <div class="section-header"><strong>Top 5 Paling Dilihat</strong><span class="muted">Berdasarkan view</span></div>
          <?php if (!$mostViewedDocuments || $totalViews === 0): ?>
            <p class="empty"><?= !$mostViewedDocuments ? 'Belum ada dokumen yang dipublikasikan.' : 'Belum ada dokumen yang dilihat.' ?></p>
          <?php else: ?>
            <table class="admin-table"><thead><tr><th>Nama File</th><th>Dilihat</th><th>Diunduh</th></tr></thead><tbody><?php foreach (array_slice($mostViewedDocuments, 0, 5) as $document): ?><?php $documentStatistics = document_stat($document['path']); ?><tr><td data-label="Nama File"><strong><?= e($document['filename']) ?></strong></td><td data-label="Dilihat"><span><?= $documentStatistics['views'] ?> kali</span></td><td data-label="Diunduh"><span><?= $documentStatistics['downloads'] ?> kali</span></td></tr><?php endforeach; ?></tbody></table>
          <?php endif; ?>
        </section>
        <section class="card admin-section">
          <div class="section-header"><strong>Top 5 Paling Diunduh</strong><span class="muted">Berdasarkan download</span></div>
          <?php if (!$mostDownloadedDocuments || $totalDownloads === 0): ?>
            <p class="empty"><?= !$mostDownloadedDocuments ? 'Belum ada dokumen yang dipublikasikan.' : 'Belum ada dokumen yang diunduh.' ?></p>
          <?php else: ?>
            <table class="admin-table"><thead><tr><th>Nama File</th><th>Diunduh</th><th>Dilihat</th></tr></thead><tbody><?php foreach (array_slice($mostDownloadedDocuments, 0, 5) as $document): ?><?php $documentStatistics = document_stat($document['path']); ?><tr><td data-label="Nama File"><strong><?= e($document['filename']) ?></strong></td><td data-label="Diunduh"><span><?= $documentStatistics['downloads'] ?> kali</span></td><td data-label="Dilihat"><span><?= $documentStatistics['views'] ?> kali</span></td></tr><?php endforeach; ?></tbody></table>
          <?php endif; ?>
        </section>
      </div>
      <?php endif; ?>

      <?php if ($notice): ?><p class="notice"><?= e($notice) ?></p><?php endif; ?>
      <?php if ($error): ?><p class="error"><?= e($error) ?></p><?php endif; ?>

  <?php if ($page === 'identitas'): ?><section class="card admin-section admin-form">
    <div class="content-intro">
      <div>
        <h2>Identitas &amp; Tampilan Aplikasi</h2>
        <p>Atur identitas aplikasi dan tampilan global untuk pengunjung serta halaman admin.</p>
      </div>
    </div>
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="identity">

      <label>Nama Instansi / Aplikasi
        <input name="app_name" value="<?= e(app_brand()) ?>" required maxlength="120" placeholder="Contoh: SIMPUN">
      </label>
      <p class="muted">Muncul di judul tab peramban, masthead navigasi, dan footer.</p>

      <label>Judul Headline Portal
        <input name="app_headline" value="<?= e(app_headline()) ?>" maxlength="160" placeholder="Contoh: Publikasi Dokumen Resmi">
      </label>
      <p class="muted">Judul utama yang tampil pada bilah beranda atau hero.</p>

      <label>Subtitle / Tagline Instansi
        <input name="app_subtitle" value="<?= e(app_subtitle()) ?>" maxlength="255" placeholder="Contoh: Telusuri dan unduh dokumen yang tersedia untuk publik.">
      </label>
      <p class="muted">Deskripsi singkat portal publikasi dokumen.</p>

      <fieldset class="theme-settings">
        <legend>Tema publik</legend>
        <p class="muted">Gaya tema berlaku global. Mode pilihan ini menjadi default; pengunjung dapat memilih terang atau gelap hanya untuk browser mereka.</p>
        <div class="theme-choice-grid" role="radiogroup" aria-label="Pilih tema global">
          <?php foreach ([
              'hud' => ['Futuristic HUD', 'Obsidian dan cyan elektrik'],
              'classic' => ['Classic Drive', 'Arsip bersih dengan aksen biru'],
              'standart' => ['Standart', 'Ruang kerja bergaya macOS dengan sidebar dan panel pratinjau'],
          ] as $themeValue => [$themeTitle, $themeDescription]): ?>
          <label class="theme-choice theme-choice--<?= e($themeValue) ?>">
            <input type="radio" name="theme_style" value="<?= e($themeValue) ?>"<?= app_theme_style() === $themeValue ? ' checked' : '' ?> required>
            <span class="theme-mini-preview" aria-hidden="true"><i></i><i></i><i></i></span>
            <span class="theme-choice-copy"><strong><?= e($themeTitle) ?></strong><small><?= e($themeDescription) ?></small></span>
          </label>
          <?php endforeach; ?>
        </div>
        <fieldset class="theme-mode-choice">
          <legend>Mode warna</legend>
          <label><input type="radio" name="theme_mode" value="dark"<?= app_theme_mode() === 'dark' ? ' checked' : '' ?> required> Default: Gelap</label>
          <label><input type="radio" name="theme_mode" value="light"<?= app_theme_mode() === 'light' ? ' checked' : '' ?> required> Default: Terang</label>
        </fieldset>
      </fieldset>

      <label>Logo Instansi Saat Ini</label>
      <div style="display: flex; align-items: center; gap: 1rem; margin-bottom: 1rem; padding: 0.75rem; background: var(--color-surface-subtle); border-radius: var(--radius-sm); border: 1px solid var(--color-rule);">
        <?php if (is_file(APP_ROOT . '/' . app_logo())): ?>
          <img src="<?= e(app_url(app_logo())) ?>?v=<?= filemtime(APP_ROOT . '/' . app_logo()) ?>" alt="Logo Instansi" style="max-height: 48px; max-width: 120px; object-fit: contain;">
          <span class="muted mono" style="font-size: var(--text-xs);"><?= e(app_logo()) ?></span>
        <?php else: ?>
          <span class="muted" style="font-size: var(--text-xs);">Belum ada logo terpasang.</span>
        <?php endif; ?>
      </div>

      <label>Unggah Logo Baru (Opsional)
        <input name="app_logo" type="file" accept=".png,.jpg,.jpeg,.svg,.webp">
      </label>
      <p class="muted">Format yang didukung: PNG, JPG, SVG, WebP. Maksimal 2 MB. Disarankan rasio proporsional transparan.</p>

      <div class="form-actions">
        <button class="button button-primary" type="submit">Simpan Identitas &amp; Tema</button>
      </div>
    </form>
    <script>
      document.querySelectorAll('input[name="theme_style"]').forEach(input => input.addEventListener('change', () => document.documentElement.dataset.themeStyle = input.value));
      document.querySelectorAll('input[name="theme_mode"]').forEach(input => input.addEventListener('change', () => document.documentElement.dataset.theme = input.value));
    </script>
  </section><?php endif; ?>

  <?php if ($page === 'akun'): ?><section class="card admin-section admin-form">
    <div class="content-intro"><div><h2>Akses administrator</h2><p>Ubah informasi masuk Anda di sini.</p></div></div>
    <form method="post" autocomplete="on">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="account">
      <label>Username<input name="new_username" value="<?= e((string) ($_POST['new_username'] ?? $credentials['username'])) ?>" autocomplete="username" maxlength="64"></label>
      <p class="muted">Gunakan 3-64 karakter: huruf, angka, titik, strip, atau underscore. Kosongkan untuk mempertahankan username saat ini.</p>
      <label>Password saat ini<input name="current_password" type="password" autocomplete="current-password" required></label>
      <label>Password baru (opsional)<input name="new_password" type="password" autocomplete="new-password" minlength="12"></label>
      <label>Konfirmasi password baru<input name="confirm_password" type="password" autocomplete="new-password" minlength="12"></label>
      <p class="muted">Isi kedua kolom password baru untuk mengubah password. Password baru minimal 12 karakter.</p>
      <div class="form-actions"><button class="button button-primary" type="submit">Simpan Pengaturan Akun</button></div>
    </form>
  </section><?php endif; ?>

  <?php if ($page === 'aktivitas'): ?><section class="card admin-section">
    <div class="section-header">
      <div>
        <strong>Riwayat Aktivitas</strong>
        <span class="muted">30 aktivitas terakhir</span>
      </div>
      <a class="button" href="admin.php?action=export_audit&amp;csrf_token=<?= e(csrf_token()) ?>" title="Unduh seluruh riwayat aktivitas sebagai file CSV">
        <svg viewBox="0 0 16 16" fill="currentColor" style="width:14px;height:14px;vertical-align:-2px;margin-right:4px;"><path d="M.5 9.9a.5.5 0 0 1 .5.5v2.5a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-2.5a.5.5 0 0 1 1 0v2.5a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2v-2.5a.5.5 0 0 1 .5-.5z"/><path d="M7.646 11.854a.5.5 0 0 0 .708 0l3-3a.5.5 0 0 0-.708-.708L8.5 10.293V1.5a.5.5 0 0 0-1 0v8.793L5.354 8.146a.5.5 0 1 0-.708.708l3 3z"/></svg>
        Ekspor CSV
      </a>
    </div>
    <?php if (!$auditEntries): ?>
      <p class="empty">Belum ada aktivitas yang tercatat.</p>
    <?php else: ?>
      <div class="audit-list">
        <?php foreach ($auditEntries as $entry): ?>
          <article class="audit-entry"><div><strong><?= e($entry['action']) ?></strong><p><?= e($entry['details']) ?></p></div><span><?= e(date('d/m/Y H:i', strtotime($entry['at']))) ?> · <?= e($entry['admin']) ?></span></article>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section><?php endif; ?>

  <?php if ($page === 'unggah'): ?><section class="card admin-section admin-form">
    <div class="content-intro"><div><h2><?= $editingExistingFile ? ($editDocument ? 'Ubah Dokumen' : 'Publikasikan File') : 'Data dokumen' ?></h2><p>Atur file, folder, dan tanggal publikasi dalam satu langkah.</p></div></div>
    <?php if ($editingExistingFile): ?>
      <?php
        $currentFileDir = str_replace('\\', '/', dirname($editPath));
        $currentFileDir = $currentFileDir === '.' ? '' : $currentFileDir;
      ?>
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="<?= $editDocument ? 'update' : 'publish' ?>">
        <input type="hidden" name="path" value="<?= e($editPath) ?>">
        <p class="muted">Nama file: <strong><?= e(document_display_name(is_array($editDocument) ? $editDocument : [], $editPath)) ?></strong></p>
        <p class="muted">Folder saat ini: <strong><?= e($currentFileDir === '' ? 'Dokumen Utama' : $currentFileDir) ?></strong></p>

        <label>Pindah ke Folder
          <select name="target_folder">
            <?php foreach ($directories as $directory): ?>
              <option value="<?= e($directory) ?>"<?= $currentFileDir === $directory ? ' selected' : '' ?>><?= e(directory_option_label($directory)) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>Subfolder baru (opsional)
          <input name="new_target_folder" placeholder="Contoh: 2026 atau Arsip/2026">
        </label>
        <p class="muted">Pilih folder tujuan di atas atau isi subfolder baru bila ingin memindahkan dokumen ini ke folder lain.</p>

        <label>Tanggal publikasi (opsional)<input name="published_at" type="date" value="<?= e((string) ($editDocument['published_at'] ?? '')) ?>"></label>
        <p class="muted">Kosongkan untuk memakai tanggal Last Modified file.</p>
        <?php $selectedVisibility = (string) ($_POST['visibility'] ?? document_visibility(is_array($editDocument) ? $editDocument : [])); ?>
        <label>Visibilitas Dokumen
          <select name="visibility">
            <option value="public"<?= $selectedVisibility === 'public' ? ' selected' : '' ?>>Publik — tampil di explorer</option>
            <option value="unlisted"<?= $selectedVisibility === 'unlisted' ? ' selected' : '' ?>>Akses via Tautan — tidak tampil di explorer</option>
            <option value="hidden"<?= $selectedVisibility === 'hidden' ? ' selected' : '' ?>>Privat — hanya admin</option>
          </select>
        </label>
        <p class="muted">Folder Akses via Tautan tetap menyembunyikan berkas dari explorer meskipun dokumen disetel Publik.</p>
        <div class="pin-settings">
          <strong>Proteksi PIN tautan</strong>
          <p class="muted">PIN dokumen hanya berlaku untuk Akses via Tautan. Folder dapat memakai PIN tersendiri.</p>
          <label>Pengaturan PIN
            <select name="pin_action">
              <option value="keep">Biarkan seperti sekarang<?= is_array($editDocument) && document_pin_hash($editDocument) !== null ? ' (PIN aktif)' : ' (tanpa PIN)' ?></option>
              <option value="set">Tetapkan / ganti PIN</option>
              <option value="remove">Hapus PIN</option>
            </select>
          </label>
          <label>PIN baru (jika tetapkan / ganti)
            <input name="pin" type="password" inputmode="numeric" pattern="[0-9]{6}" minlength="6" maxlength="6" autocomplete="new-password" placeholder="Enam angka">
          </label>
          <p class="muted">PIN tidak dapat dibaca kembali. Mengganti atau menghapusnya mencabut akses sesi sebelumnya.</p>
        </div>
        <div class="form-actions"><button class="button button-primary" type="submit"><?= $editDocument ? 'Simpan perubahan' : 'Publikasikan' ?></button><a class="button" href="admin.php?page=terbit">Batal</a></div>
      </form>
    <?php else: ?>
      <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="upload">
        <input type="hidden" name="MAX_FILE_SIZE" value="<?= $config['max_upload_bytes'] ?>">

        <div class="dropzone-deck">
          <label class="dropzone" id="upload-dropzone">
            <input type="file" name="documents[]" id="file-input" accept=".pdf,.jpg,.jpeg,.png,.docx,.xls,.xlsx,.csv" multiple style="display:none;" required>
            <div class="dropzone-content">
              <div class="dropzone-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
                  <path d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/>
                </svg>
              </div>
              <div class="dropzone-text">
                <strong>Klik untuk memilih berkas</strong> atau seret &amp; letakkan ke sini (Drag &amp; Drop)
              </div>
              <span class="dropzone-hint">Mendukung multi-file sekaligus (PDF, Word DOCX, Excel XLSX, CSV, Gambar). Maksimum 30 MB per berkas.</span>
            </div>
          </label>
          <div id="file-queue-deck" class="file-queue-deck" hidden>
            <div class="queue-header">
              <strong>Antrean Berkas (<span id="queue-count">0</span>)</strong>
              <button type="button" class="button" id="btn-clear-queue">Hapus Semua</button>
            </div>
            <ul class="queue-list" id="queue-list"></ul>
          </div>
        </div>

        <label>Folder tujuan
          <select name="folder">
            <?php foreach ($directories as $directory): ?>
              <option value="<?= e($directory) ?>"<?= $uploadFolder === $directory ? ' selected' : '' ?>><?= e(directory_option_label($directory)) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>Subfolder baru (opsional)<input name="new_folder" value="<?= e($uploadNewFolder) ?>" placeholder="Contoh: 2026 atau Arsip/2026"></label>
        <p class="muted">Pilih folder yang sudah ada di atas. Isi subfolder baru hanya jika folder tujuan belum tersedia.</p>
        <label>Tanggal publikasi (opsional)<input name="published_at" type="date"></label>
        <p class="muted">Kosongkan untuk memakai tanggal Last Modified file.</p>
        <label>Visibilitas Berkas yang Diunggah
          <select name="visibility">
            <option value="public">Publik — tampil di explorer</option>
            <option value="unlisted">Akses via Tautan — tidak tampil di explorer</option>
            <option value="hidden">Privat — hanya admin</option>
          </select>
        </label>
        <p class="muted">Pilihan berlaku untuk seluruh berkas dalam antrean unggah.</p>
        <label>PIN enam digit (opsional, hanya Akses via Tautan)
          <input name="pin" type="password" inputmode="numeric" pattern="[0-9]{6}" minlength="6" maxlength="6" autocomplete="new-password" placeholder="Kosongkan bila tidak memakai PIN">
        </label>
        <p class="muted">Jika diisi, PIN yang sama berlaku untuk seluruh berkas dalam antrean ini.</p>
        <div class="form-actions"><button class="button button-primary" type="submit">Unggah dan publikasikan</button></div>
      </form>
    <?php endif; ?>
  </section><?php endif; ?>

  <?php if ($page === 'terbit'): ?><section class="card admin-section">
    <div class="section-header"><strong>Dokumen terdaftar</strong><span class="muted"><?= count($publishedDocuments) ?> dokumen</span></div>
    <?php if (!$publishedTree['documents'] && !$publishedTree['children']): ?>
      <p class="empty">Belum ada dokumen yang dipublikasikan.</p>
    <?php else: ?>
      <form id="bulk-form" class="bulk-toolbar" method="post" action="admin.php?page=terbit">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="bulk_documents">
        <label class="bulk-select-all"><input type="checkbox" id="bulk-select-all"> Pilih semua <span id="bulk-selected-count" aria-live="polite">(0 dipilih)</span></label>
        <label>Aksi massal
          <select name="bulk_action" id="bulk-action" required>
            <option value="">Pilih aksi…</option>
            <option value="visibility">Ubah visibilitas</option>
            <option value="move">Pindahkan ke folder</option>
            <option value="delete">Hapus permanen</option>
          </select>
        </label>
        <label class="bulk-option" data-bulk-for="visibility">Visibilitas baru
          <select name="bulk_visibility">
            <option value="public">Publik</option>
            <option value="unlisted">Akses via Tautan</option>
            <option value="hidden">Privat</option>
          </select>
        </label>
        <label class="bulk-option" data-bulk-for="move">Folder tujuan
          <select name="bulk_target_folder">
            <?php foreach ($directories as $directory): ?>
              <option value="<?= e($directory) ?>"><?= e(directory_option_label($directory)) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="bulk-delete-confirm bulk-option" data-bulk-for="delete"><input name="confirm_delete" type="checkbox" value="1"> Saya mengonfirmasi penghapusan permanen berkas yang dipilih</label>
        <button class="button button-primary" type="submit" id="bulk-submit">Terapkan ke dokumen terpilih</button>
      </form>
      <?php if ($publishedTree['documents']): ?>
        <div class="admin-root-docs"><strong>Dokumen Utama</strong></div>
        <?php render_admin_document_table($publishedTree['documents']); ?>
      <?php endif; ?>
      <?php render_admin_document_folders($publishedTree['children']); ?>
    <?php endif; ?>
  </section><?php endif; ?>

  <?php if ($page === 'folder'): ?>
    <?php
      $folderList = array_values(array_filter($directories, static fn (string $d): bool => $d !== ''));
      $folderRules = load_folder_visibility();
      $folderPins = load_folder_pins();
      $editFolder = safe_relative_directory((string) ($_GET['rename'] ?? ''));
      $accessFolder = safe_relative_directory((string) ($_GET['access'] ?? ''));
      $accessFolder = in_array($accessFolder, $folderList, true) ? $accessFolder : null;
    ?>
    <section class="card admin-section admin-form">
      <div class="content-intro">
        <div>
          <h2>Buat Folder / Subfolder Baru</h2>
          <p>Tambahkan direktori baru untuk mengelompokkan dokumen instansi.</p>
        </div>
      </div>
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="create_folder">
        <label>Folder Induk
          <select name="parent_folder">
            <option value="">Dokumen Utama</option>
            <?php foreach ($folderList as $d): ?>
              <option value="<?= e($d) ?>"><?= e(directory_option_label($d)) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>Nama Folder / Subfolder Baru
          <input name="folder_name" placeholder="Contoh: 2026 atau Laporan-Tahunan" required>
        </label>
        <p class="muted">Gunakan huruf, angka, spasi, titik, strip, atau underscore.</p>
        <label>Visibilitas Folder
          <select name="visibility">
            <option value="public">Publik</option>
            <option value="unlisted">Akses via Tautan — sembunyikan dari explorer</option>
          </select>
        </label>
        <label>PIN folder enam digit (opsional, hanya Akses via Tautan)
          <input name="pin" type="password" inputmode="numeric" pattern="[0-9]{6}" minlength="6" maxlength="6" autocomplete="new-password" placeholder="Kosongkan bila tidak memakai PIN">
        </label>
        <p class="muted">Subfolder mewarisi PIN folder induk. Link dokumen langsung juga membutuhkan PIN folder; dokumen Privat tetap hanya dapat dibuka admin.</p>
        <div class="form-actions">
          <button class="button button-primary" type="submit">Buat Folder</button>
        </div>
      </form>
    </section>

    <?php if ($accessFolder !== null): ?>
    <section class="card admin-section admin-form" id="folder-access-form">
      <div class="content-intro"><div><h2>Atur Akses Folder</h2><p><?= e($accessFolder) ?></p></div></div>
      <form method="post" action="admin.php?page=folder&amp;access=<?= rawurlencode($accessFolder) ?>#folder-access-form">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="folder_visibility">
        <input type="hidden" name="folder" value="<?= e($accessFolder) ?>">
        <label>Visibilitas folder
          <select name="visibility">
            <option value="public"<?= ($folderRules[$accessFolder] ?? 'public') === 'public' ? ' selected' : '' ?>>Publik — tampil di explorer</option>
            <option value="unlisted"<?= ($folderRules[$accessFolder] ?? 'public') === 'unlisted' ? ' selected' : '' ?>>Akses via Tautan — tidak tampil di explorer</option>
          </select>
        </label>
        <p class="muted">Jika folder induk Akses via Tautan, subfolder tetap tidak tampil di explorer walaupun pengaturan sendiri Publik.</p>
        <label>Pengaturan PIN folder
          <select name="pin_action">
            <option value="keep">Biarkan seperti sekarang<?= isset($folderPins[$accessFolder]) ? ' (PIN aktif)' : ' (tanpa PIN)' ?></option>
            <option value="set">Tetapkan / ganti PIN</option>
            <option value="remove">Hapus PIN</option>
          </select>
        </label>
        <label>PIN baru (jika tetapkan / ganti)
          <input name="pin" type="password" inputmode="numeric" pattern="[0-9]{6}" minlength="6" maxlength="6" autocomplete="new-password" placeholder="Enam angka">
        </label>
        <p class="muted">PIN hanya untuk Akses via Tautan. Mengganti atau menghapusnya mencabut sesi yang pernah membuka folder ini.</p>
        <div class="form-actions"><button class="button button-primary" type="submit">Simpan Akses Folder</button><a class="button" href="admin.php?page=folder">Batal</a></div>
      </form>
    </section>
    <?php endif; ?>

    <section class="card admin-section">
      <div class="section-header">
        <strong>Daftar Folder &amp; Subfolder Tersedia</strong>
        <span class="muted"><?= count($folderList) ?> folder aktif</span>
      </div>

      <?php if (!$folderList): ?>
        <p class="empty">Belum ada folder yang dibuat. Silakan buat folder pertama Anda di atas.</p>
      <?php else: ?>
        <table class="admin-table">
          <thead>
            <tr>
              <th>Jalur Direktori</th>
              <th>Nama Folder</th>
              <th>Dokumen Terbit</th>
              <th>Visibilitas</th>
              <th>Aksi</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($folderList as $dirPath): ?>
              <?php
                $dirDocsCount = count(array_filter($publishedDocuments, static fn (array $d): bool => str_starts_with($d['path'], $dirPath . '/')));
                $baseName = basename($dirPath);
                $ownVisibility = $folderRules[$dirPath] ?? 'public';
                $effectiveVisibility = folder_is_unlisted($dirPath, $folderRules) ? 'unlisted' : 'public';
                $isEditingThis = ($editFolder === $dirPath);
                $absDir = absolute_directory_path($dirPath);
                $hasPhysicalItems = false;
                if ($absDir !== null && is_dir($absDir)) {
                    $items = array_diff(scandir($absDir) ?: [], ['.', '..']);
                    $hasPhysicalItems = count($items) > 0;
                }
              ?>
              <tr>
                <td data-label="Jalur Direktori">
                  <span class="mono" style="color:var(--color-accent)"><?= e($dirPath) ?></span>
                </td>
                <td data-label="Nama Folder">
                  <?php if ($isEditingThis): ?>
                    <form method="post" style="display:flex; gap:var(--space-xs); align-items:center;">
                      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                      <input type="hidden" name="action" value="rename_folder">
                      <input type="hidden" name="old_folder" value="<?= e($dirPath) ?>">
                      <input name="new_name" value="<?= e($baseName) ?>" style="min-height:36px; max-width:200px" required autofocus>
                      <button class="button button-primary" type="submit">Simpan</button>
                      <a class="button" href="admin.php?page=folder">Batal</a>
                    </form>
                  <?php else: ?>
                    <strong><?= e($baseName) ?></strong>
                  <?php endif; ?>
                </td>
                <td data-label="Dokumen Terbit">
                  <span><?= $dirDocsCount ?> dokumen</span>
                  <?php if (!$hasPhysicalItems): ?>
                    <span class="muted" style="font-size:var(--text-xs); margin-left:var(--space-2xs);">(Kosong)</span>
                  <?php endif; ?>
                </td>
                <td data-label="Visibilitas"><span class="visibility-badge visibility-badge--<?= $effectiveVisibility ?>"><?= e(visibility_label($effectiveVisibility)) ?></span><?= $ownVisibility !== $effectiveVisibility ? ' <small class="muted">(dari induk)</small>' : '' ?><?= isset($folderPins[$dirPath]) && $ownVisibility === 'unlisted' ? ' <span class="pin-indicator">PIN aktif</span>' : '' ?></td>
                <td data-label="Aksi">
                  <?php if (!$isEditingThis): ?>
                    <a class="button" href="admin.php?page=folder&amp;access=<?= rawurlencode($dirPath) ?>#folder-access-form">Atur Akses</a>
                    <button class="button" type="button" data-copy-link="<?= e(folder_public_url($dirPath)) ?>" aria-label="Salin tautan folder <?= e($dirPath) ?>"><span class="copy-label" aria-live="polite">Salin Link</span></button>
                    <a class="button" href="admin.php?page=folder&amp;rename=<?= rawurlencode($dirPath) ?>">Ganti Nama</a>
                    <?php if (!$hasPhysicalItems): ?>
                      <form method="post" style="display:inline; margin-left:var(--space-2xs);" onsubmit="return confirm('Hapus folder kosong ini dari sistem penyimpanan?')">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="delete_folder">
                        <input type="hidden" name="folder" value="<?= e($dirPath) ?>">
                        <button type="submit" class="button button-danger">Hapus</button>
                      </form>
                    <?php endif; ?>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </section>
  <?php endif; ?>

  <?php if ($page === 'belum-terbit' && $unpublished): ?>
    <?php $unpublishedTree = unpublished_file_tree($unpublished); ?>
    <section class="card admin-section" id="belum-terbit">
      <div class="section-header"><strong>File yang belum dipublikasikan</strong><span class="muted"><?= count($unpublished) ?> file</span></div>
      <?php if ($unpublishedTree['files']): ?>
        <div class="admin-root-docs"><strong>Dokumen Utama</strong></div>
        <?php render_admin_unpublished_table($unpublishedTree['files']); ?>
      <?php endif; ?>
      <?php render_admin_unpublished_folders($unpublishedTree['children']); ?>
    </section>
  <?php elseif ($page === 'belum-terbit'): ?>
    <section class="card admin-section"><p class="empty">Semua file sudah dipublikasikan.</p></section>
  <?php endif; ?>
    </div>
  </div>
</main>
<script src="<?= e(app_url('assets/js/share-link.js')) ?>"></script>
<script>
  const adminMenuButton = document.querySelector('.admin-nav-toggle');
  const adminNavigation = document.getElementById('admin-navigation');
  const compactAdminMenu = window.matchMedia('(max-width: 800px)');
  const syncAdminMenu = () => {
    adminNavigation.hidden = compactAdminMenu.matches;
    adminMenuButton.setAttribute('aria-expanded', String(!adminNavigation.hidden));
  };
  adminMenuButton.addEventListener('click', () => {
    adminNavigation.hidden = !adminNavigation.hidden;
    adminMenuButton.setAttribute('aria-expanded', String(!adminNavigation.hidden));
  });
  compactAdminMenu.addEventListener('change', syncAdminMenu);
  syncAdminMenu();

  document.querySelectorAll('.btn-folder-action').forEach(btn => {
    btn.addEventListener('click', (e) => {
      e.preventDefault();
      e.stopPropagation();
      const oldFolder = btn.dataset.folderPath;
      const currentName = btn.dataset.folderName;
      const newName = prompt('Ganti nama folder "' + currentName + '" menjadi:', currentName);
      if (!newName || newName.trim() === '' || newName.trim() === currentName) return;
      document.getElementById('rename-old-folder').value = oldFolder;
      document.getElementById('rename-new-name').value = newName.trim();
      document.getElementById('rename-folder-form').submit();
    });
  });

  (function() {
    const form = document.getElementById('bulk-form');
    if (!form) return;
    const boxes = Array.from(document.querySelectorAll('.bulk-document-check'));
    const selectAll = document.getElementById('bulk-select-all');
    const action = document.getElementById('bulk-action');
    const count = document.getElementById('bulk-selected-count');
    const submit = document.getElementById('bulk-submit');
    const sync = () => {
      const selected = boxes.filter(box => box.checked).length;
      count.textContent = `(${selected} dipilih)`;
      selectAll.checked = selected > 0 && selected === boxes.length;
      selectAll.indeterminate = selected > 0 && selected < boxes.length;
      submit.disabled = selected === 0 || !action.value;
      form.querySelectorAll('[data-bulk-for]').forEach(option => {
        option.hidden = option.dataset.bulkFor !== action.value;
      });
    };
    selectAll.addEventListener('change', () => {
      boxes.forEach(box => { box.checked = selectAll.checked; });
      sync();
    });
    boxes.forEach(box => box.addEventListener('change', sync));
    action.addEventListener('change', sync);
    form.addEventListener('submit', event => {
      const selected = boxes.filter(box => box.checked).length;
      if (!selected || (action.value === 'delete' && !confirm(`Hapus permanen ${selected} dokumen terpilih? Berkas tidak dapat dipulihkan.`))) {
        event.preventDefault();
      }
    });
    sync();
  })();

  (function() {
    const dropzone = document.getElementById('upload-dropzone');
    const fileInput = document.getElementById('file-input');
    const queueDeck = document.getElementById('file-queue-deck');
    const queueList = document.getElementById('queue-list');
    const queueCount = document.getElementById('queue-count');
    const btnClear = document.getElementById('btn-clear-queue');

    if (!dropzone || !fileInput) return;

    ['dragenter', 'dragover'].forEach(name => {
      dropzone.addEventListener(name, (e) => {
        e.preventDefault();
        dropzone.classList.add('is-dragover');
      });
    });

    ['dragleave', 'drop'].forEach(name => {
      dropzone.addEventListener(name, (e) => {
        e.preventDefault();
        dropzone.classList.remove('is-dragover');
      });
    });

    dropzone.addEventListener('drop', (e) => {
      if (e.dataTransfer && e.dataTransfer.files.length) {
        fileInput.files = e.dataTransfer.files;
        renderQueue();
      }
    });

    fileInput.addEventListener('change', renderQueue);

    function formatBytes(bytes) {
      if (bytes < 1024) return bytes + ' B';
      const kb = bytes / 1024;
      if (kb < 1024) return kb.toFixed(1) + ' KB';
      return (kb / 1024).toFixed(1) + ' MB';
    }

    function renderQueue() {
      const files = Array.from(fileInput.files || []);
      if (!files.length) {
        if (queueDeck) queueDeck.hidden = true;
        return;
      }
      if (queueDeck) queueDeck.hidden = false;
      if (queueCount) queueCount.textContent = files.length;
      if (queueList) {
        queueList.innerHTML = files.map((f) => `
          <li class="queue-item">
            <span class="queue-item-name">${f.name}</span>
            <span class="queue-item-size">${formatBytes(f.size)}</span>
          </li>
        `).join('');
      }
    }

    if (btnClear) {
      btnClear.onclick = (e) => {
        e.preventDefault();
        fileInput.value = '';
        renderQueue();
      };
    }
  })();
</script>
<form id="rename-folder-form" method="post" style="display:none">
  <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
  <input type="hidden" name="action" value="rename_folder">
  <input type="hidden" name="old_folder" id="rename-old-folder">
  <input type="hidden" name="new_name" id="rename-new-name">
</form>
<?php page_footer();
