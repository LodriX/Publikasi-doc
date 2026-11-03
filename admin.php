<?php

declare(strict_types=1);

require __DIR__ . '/lib.php';

session_name('mcsp_admin');
session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
]);
session_start();

$config = app_config();
$credentials = admin_credentials();
$error = '';
$uploadFolder = (string) ($_POST['folder'] ?? '');
$uploadNewFolder = (string) ($_POST['new_folder'] ?? '');

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

function render_admin_document_table(array $documents): void
{
    if (!$documents) {
        return;
    }
    ?>
    <table class="admin-table">
      <thead><tr><th>Nama File</th><th>Ukuran</th><th>Tanggal</th><th>Dilihat</th><th>Diunduh</th><th>Aksi</th></tr></thead>
      <tbody>
        <?php foreach ($documents as $document): ?>
          <?php $statistics = document_stat($document['path']); ?>
          <tr>
            <td><strong><?= e($document['filename']) ?></strong></td>
            <td><?= e(format_file_size($document['size'])) ?></td>
            <td><?= e(format_publication_date($document['published_at'])) ?></td>
            <td><?= $statistics['views'] ?> kali</td>
            <td><?= $statistics['downloads'] ?> kali</td>
            <td>
              <a class="button" href="admin.php?page=unggah&amp;edit=<?= rawurlencode($document['path']) ?>">Ubah</a>
              <form method="post" style="display:inline" onsubmit="return confirm('Hapus dokumen ini?')">
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

function render_admin_document_folders(array $children): void
{
    foreach ($children as $name => $node):
        ?>
        <details class="folder-node" open>
          <summary><?= e((string) $name) ?><span class="folder-count"><?= document_tree_count($node) ?> dokumen</span></summary>
          <div class="folder-body">
            <?php render_admin_document_table($node['documents']); ?>
            <?php render_admin_document_folders($node['children']); ?>
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
          <tr><td><?= e(basename($path)) ?></td><td><?= e(format_file_size($filePath ? (filesize($filePath) ?: 0) : 0)) ?></td><td><a class="button button-primary" href="admin.php?page=unggah&amp;edit=<?= rawurlencode($path) ?>">Isi data publikasi</a></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php
}

function render_admin_unpublished_folders(array $children): void
{
    foreach ($children as $name => $node):
        ?>
        <details class="folder-node" open>
          <summary><?= e((string) $name) ?><span class="folder-count"><?= unpublished_file_tree_count($node) ?> file</span></summary>
          <div class="folder-body">
            <?php render_admin_unpublished_table($node['files']); ?>
            <?php render_admin_unpublished_folders($node['children']); ?>
          </div>
        </details>
        <?php
    endforeach;
}

if (!is_admin() && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = (string) ($_POST['username'] ?? '');
    $password = (string) ($_POST['password'] ?? '');
    if (login_is_throttled()) {
        $error = 'Terlalu banyak percobaan login. Silakan coba lagi dalam 15 menit.';
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
        $error = 'Username atau password tidak tepat.';
    }
}

if (is_admin() && $_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'logout') {
    verify_csrf();
    admin_audit('Logout', 'Keluar dari dashboard.');
    $_SESSION = [];
    session_destroy();
    redirect('admin.php');
}

if (is_admin() && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');
    $documents = load_documents();

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

    if ($action === 'publish' || $action === 'update') {
        $path = safe_relative_path((string) ($_POST['path'] ?? ''));
        $publishedAt = trim((string) ($_POST['published_at'] ?? ''));
        if ($path === null || absolute_file_path($path) === null || ($publishedAt !== '' && !valid_publication_date($publishedAt))) {
            $error = 'Tanggal publikasi harus menggunakan format tanggal yang valid.';
        } else {
            $existingDocument = is_array($documents[$path] ?? null) ? $documents[$path] : [];
            $documents[$path] = ['filename' => document_display_name($existingDocument, $path), 'published_at' => $publishedAt];
            if (!save_documents($documents)) {
                $error = 'Data dokumen tidak dapat disimpan.';
            } else {
                admin_audit($action === 'publish' ? 'Publikasi dokumen' : 'Pembaruan dokumen', document_display_name($documents[$path], $path));
                redirect('admin.php?page=terbit&notice=' . rawurlencode($action === 'publish' ? 'Dokumen dipublikasikan.' : 'Data dokumen diperbarui.'));
            }
        }
    }

    if ($action === 'upload') {
        $publishedAt = trim((string) ($_POST['published_at'] ?? ''));
        $upload = $_FILES['document'] ?? null;
        $folder = safe_relative_directory($uploadFolder);
        $newFolder = safe_relative_directory($uploadNewFolder);
        $destination = $folder === null || $newFolder === null
            ? null
            : implode('/', array_filter([$folder, $newFolder], static fn (string $part): bool => $part !== ''));
        if (($publishedAt !== '' && !valid_publication_date($publishedAt)) || !is_array($upload) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $error = 'Pilih file dan gunakan tanggal publikasi yang valid bila diisi.';
        } elseif ($destination === null || ($targetDirectory = absolute_directory_path($destination, true)) === null) {
            $error = 'Folder tujuan tidak valid atau tidak dapat dibuat.';
        } elseif ((int) $upload['size'] > $config['max_upload_bytes']) {
            $error = 'Ukuran file melebihi batas 30 MB.';
        } else {
            $originalName = basename(str_replace('\\', '/', (string) $upload['name']));
            $extension = supported_extension($originalName);
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file((string) $upload['tmp_name']) ?: '';
            if ($extension === null || !upload_mime_is_allowed($extension, $mime)) {
                $error = 'Format file tidak diizinkan atau isi file tidak sesuai formatnya.';
            } else {
                $storedName = bin2hex(random_bytes(16)) . '.' . $extension;
                $storedPath = $destination === '' ? $storedName : $destination . '/' . $storedName;
                $target = $targetDirectory . '/' . $storedName;
                if (!move_uploaded_file((string) $upload['tmp_name'], $target)) {
                    $error = 'File tidak dapat disimpan.';
                } else {
                    $documents[$storedPath] = ['filename' => $originalName, 'published_at' => $publishedAt];
                    if (!save_documents($documents)) {
                        unlink($target);
                        $error = 'Data dokumen tidak dapat disimpan.';
                    } else {
                        admin_audit('Unggah dokumen', $originalName);
                        redirect('admin.php?page=terbit&notice=' . rawurlencode('Dokumen berhasil diunggah dan dipublikasikan.'));
                    }
                }
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
    page_header('Login Admin', 'admin-page');
    ?>
<main class="shell">
  <section class="card form-card">
    <h1>Login Admin</h1>
    <p class="subtitle">Kelola dokumen yang dipublikasikan.</p>
    <?php if ($error): ?><p class="error"><?= e($error) ?></p><?php endif; ?>
    <form method="post" autocomplete="on">
      <label>Username<input name="username" required autofocus></label>
      <label>Password<input name="password" type="password" required></label>
      <div class="form-actions"><button class="button button-primary" type="submit">Masuk</button></div>
    </form>
  </section>
</main>
<?php page_footer(); exit; endif;

$documents = load_documents();
$files = discover_files();
$directories = discover_directories();
$publishedTree = public_document_tree();
$publishedDocuments = public_documents();
$unpublished = array_diff_key($files, $documents);
$publishedSize = array_sum(array_column($publishedDocuments, 'size'));
$notice = trim((string) ($_GET['notice'] ?? ''));
$pages = [
    'dashboard' => ['title' => 'Dashboard Statistik', 'description' => 'Pantau ringkasan publikasi, view, dan download dokumen.'],
    'unggah' => ['title' => 'Unggah Dokumen', 'description' => 'Unggah file baru atau lengkapi data publikasi file yang tersedia.'],
    'terbit' => ['title' => 'Dokumen Terbit', 'description' => 'Kelola dokumen yang sudah tersedia untuk publik.'],
    'belum-terbit' => ['title' => 'File Belum Terbit', 'description' => 'Tinjau file yang belum dipublikasikan.'],
    'aktivitas' => ['title' => 'Riwayat Aktivitas', 'description' => 'Tinjau aktivitas pengelolaan dashboard terbaru.'],
    'akun' => ['title' => 'Pengaturan Akun', 'description' => 'Perbarui username atau password administrator.'],
];
$page = (string) ($_GET['page'] ?? 'dashboard');
if (!isset($pages[$page])) {
    $page = 'dashboard';
}
$statistics = document_statistics();
$totalViews = array_sum(array_column($statistics, 'views'));
$totalDownloads = array_sum(array_column($statistics, 'downloads'));
$mostViewedDocuments = $publishedDocuments;
usort($mostViewedDocuments, static function (array $left, array $right): int {
    $leftStatistics = document_stat($left['path']);
    $rightStatistics = document_stat($right['path']);
    return ($rightStatistics['views'] <=> $leftStatistics['views']) ?: ($rightStatistics['downloads'] <=> $leftStatistics['downloads']) ?: strnatcasecmp($left['filename'], $right['filename']);
});
$mostDownloadedDocuments = $publishedDocuments;
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
page_header('Admin Dokumen', 'admin-page');
?>
<main class="shell">
  <div class="admin-layout">
    <aside class="admin-sidebar">
      <div class="admin-logo"><img src="<?= e(app_url('logo-murung-raya.png')) ?>" alt="Lambang Kabupaten Murung Raya"><span>MCSP RBS KPK<small>Administrator</small></span></div>
      <nav class="admin-nav" aria-label="Navigasi admin">
        <a class="<?= $page === 'dashboard' ? 'is-active' : '' ?>" href="admin.php?page=dashboard">Dashboard</a>
        <a class="<?= $page === 'unggah' ? 'is-active' : '' ?>" href="admin.php?page=unggah">Unggah Dokumen</a>
        <a class="<?= $page === 'terbit' ? 'is-active' : '' ?>" href="admin.php?page=terbit">Dokumen Terbit</a>
        <a class="<?= $page === 'belum-terbit' ? 'is-active' : '' ?>" href="admin.php?page=belum-terbit">Belum Terbit</a>
        <a class="<?= $page === 'aktivitas' ? 'is-active' : '' ?>" href="admin.php?page=aktivitas">Riwayat Aktivitas</a>
        <a class="<?= $page === 'akun' ? 'is-active' : '' ?>" href="admin.php?page=akun">Pengaturan Akun</a>
        <a href="<?= e(app_url()) ?>">Lihat Publik</a>
        <form method="post" class="admin-logout-form"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="logout"><button type="submit">Keluar</button></form>
      </nav>
    </aside>
    <div class="admin-main">
      <header class="admin-hero">
        <div><div class="brand-kicker">Pusat Pengelolaan Dokumen</div><h1><?= e($pages[$page]['title']) ?></h1><p class="subtitle"><?= e($pages[$page]['description']) ?></p></div>
        <?php if ($page === 'dashboard'): ?><a class="button" href="admin.php?page=unggah">Unggah Dokumen</a><?php endif; ?>
      </header>

      <?php if ($page === 'dashboard'): ?>
      <section class="dashboard-grid" aria-label="Ringkasan dokumen">
        <article class="metric"><span>Dokumen terbit</span><strong><?= count($publishedDocuments) ?></strong></article>
        <article class="metric"><span>Belum terbit</span><strong><?= count($unpublished) ?></strong></article>
        <article class="metric"><span>Folder aktif</span><strong><?= max(0, count($directories) - 1) ?></strong></article>
        <article class="metric"><span>Total ukuran publik</span><strong><?= e(format_file_size($publishedSize)) ?></strong></article>
        <article class="metric"><span>Total dilihat</span><strong><?= $totalViews ?></strong></article>
        <article class="metric"><span>Total diunduh</span><strong><?= $totalDownloads ?></strong></article>
      </section>

      <div class="dashboard-leaderboards">
        <section class="card admin-section">
          <div class="section-header"><strong>Top 5 Paling Dilihat</strong><span class="muted">Berdasarkan view</span></div>
          <?php if (!$mostViewedDocuments): ?>
            <p class="empty">Belum ada dokumen yang dipublikasikan.</p>
          <?php else: ?>
            <table class="admin-table"><thead><tr><th>Nama File</th><th>Dilihat</th><th>Diunduh</th></tr></thead><tbody><?php foreach (array_slice($mostViewedDocuments, 0, 5) as $document): ?><?php $documentStatistics = document_stat($document['path']); ?><tr><td><strong><?= e($document['filename']) ?></strong></td><td><?= $documentStatistics['views'] ?> kali</td><td><?= $documentStatistics['downloads'] ?> kali</td></tr><?php endforeach; ?></tbody></table>
          <?php endif; ?>
        </section>
        <section class="card admin-section">
          <div class="section-header"><strong>Top 5 Paling Diunduh</strong><span class="muted">Berdasarkan download</span></div>
          <?php if (!$mostDownloadedDocuments): ?>
            <p class="empty">Belum ada dokumen yang dipublikasikan.</p>
          <?php else: ?>
            <table class="admin-table"><thead><tr><th>Nama File</th><th>Diunduh</th><th>Dilihat</th></tr></thead><tbody><?php foreach (array_slice($mostDownloadedDocuments, 0, 5) as $document): ?><?php $documentStatistics = document_stat($document['path']); ?><tr><td><strong><?= e($document['filename']) ?></strong></td><td><?= $documentStatistics['downloads'] ?> kali</td><td><?= $documentStatistics['views'] ?> kali</td></tr><?php endforeach; ?></tbody></table>
          <?php endif; ?>
        </section>
      </div>
      <?php endif; ?>

      <?php if ($notice): ?><p class="notice"><?= e($notice) ?></p><?php endif; ?>
      <?php if ($error): ?><p class="error"><?= e($error) ?></p><?php endif; ?>

  <?php if ($page === 'akun'): ?><section class="card admin-section admin-form">
    <div class="content-intro"><div><h2>Pengaturan Akun</h2><p>Perbarui username atau password administrator.</p></div></div>
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
    <div class="section-header"><strong>Riwayat Aktivitas</strong><span class="muted">30 aktivitas terakhir</span></div>
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
    <div class="content-intro"><div><h2><?= $editingExistingFile ? ($editDocument ? 'Ubah Dokumen' : 'Publikasikan File') : 'Unggah Dokumen' ?></h2><p>Atur file, folder, dan tanggal publikasi dalam satu langkah.</p></div></div>
    <?php if ($editingExistingFile): ?>
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="<?= $editDocument ? 'update' : 'publish' ?>">
        <input type="hidden" name="path" value="<?= e($editPath) ?>">
        <p class="muted">Nama file: <?= e(document_display_name(is_array($editDocument) ? $editDocument : [], $editPath)) ?></p>
        <label>Tanggal publikasi (opsional)<input name="published_at" type="date" value="<?= e((string) ($editDocument['published_at'] ?? '')) ?>"></label>
        <p class="muted">Kosongkan untuk memakai tanggal Last Modified file.</p>
        <div class="form-actions"><button class="button button-primary" type="submit"><?= $editDocument ? 'Simpan perubahan' : 'Publikasikan' ?></button><a class="button" href="admin.php?page=terbit">Batal</a></div>
      </form>
    <?php else: ?>
      <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="upload">
        <input type="hidden" name="MAX_FILE_SIZE" value="<?= $config['max_upload_bytes'] ?>">
        <label>File dokumen<input name="document" type="file" accept=".pdf,.jpg,.jpeg,.png,.docx,.xls,.xlsx,.csv" required></label>
        <p class="muted">Format: PDF, JPG, JPEG, PNG, DOCX, XLS, XLSX, CSV. Maksimum 30 MB.</p>
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
        <div class="form-actions"><button class="button button-primary" type="submit">Unggah dan publikasikan</button></div>
      </form>
    <?php endif; ?>
  </section><?php endif; ?>

  <?php if ($page === 'terbit'): ?><section class="card admin-section">
    <div class="section-header"><strong>Dokumen yang dipublikasikan</strong><span class="muted"><?= count($publishedDocuments) ?> dokumen</span></div>
    <?php if (!$publishedTree['documents'] && !$publishedTree['children']): ?>
      <p class="empty">Belum ada dokumen yang dipublikasikan.</p>
    <?php else: ?>
      <?php if ($publishedTree['documents']): ?>
        <div class="admin-root-docs"><strong>Dokumen Utama</strong></div>
        <?php render_admin_document_table($publishedTree['documents']); ?>
      <?php endif; ?>
      <?php render_admin_document_folders($publishedTree['children']); ?>
    <?php endif; ?>
  </section><?php endif; ?>

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
<?php page_footer();
