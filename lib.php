<?php

declare(strict_types=1);

const APP_ROOT = __DIR__;
const FILES_ROOT = APP_ROOT . '/Files';
const DOCUMENTS_FILE = APP_ROOT . '/data/documents.php';
const FOLDER_VISIBILITY_FILE = APP_ROOT . '/data/folder_visibility.php';
const FOLDER_PINS_FILE = APP_ROOT . '/data/folder_pins.php';
const ADMIN_CREDENTIALS_FILE = APP_ROOT . '/data/admin_credentials.php';
const LOGIN_ATTEMPTS_FILE = APP_ROOT . '/data/login_attempts.php';
const ADMIN_AUDIT_FILE = APP_ROOT . '/data/admin_audit.log';
const DOCUMENT_STATS_FILE = APP_ROOT . '/data/document_stats.php';
const PIN_ATTEMPTS_FILE = APP_ROOT . '/data/pin_attempts.php';
const PIN_MAX_ATTEMPTS = 5;
const PIN_LOCKOUT_SECONDS = 300;

const LOGIN_MAX_ATTEMPTS = 3;
const LOGIN_LOCKOUT_SECONDS = 300; // 5 minutes lockout

const APP_SETTINGS_FILE = APP_ROOT . '/data/app_settings.php';

function app_config(): array
{
    return require APP_ROOT . '/config.php';
}

function dynamic_app_settings(): array
{
    if (!is_file(APP_SETTINGS_FILE)) {
        return [];
    }
    $settings = require APP_SETTINGS_FILE;
    return is_array($settings) ? $settings : [];
}

function save_dynamic_app_settings(array $settings): bool
{
    $contents = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($settings, true) . ";\n";
    $temporaryFile = tempnam(dirname(APP_SETTINGS_FILE), 'app_settings_');
    if ($temporaryFile === false) {
        return false;
    }

    $saved = file_put_contents($temporaryFile, $contents, LOCK_EX) !== false && chmod($temporaryFile, 0640) && rename($temporaryFile, APP_SETTINGS_FILE);
    if (!$saved && is_file($temporaryFile)) {
        unlink($temporaryFile);
    }

    return $saved;
}

function app_setting(string $key, string $fallback): string
{
    $dynamic = dynamic_app_settings();
    if (isset($dynamic[$key]) && is_string($dynamic[$key]) && $dynamic[$key] !== '') {
        return $dynamic[$key];
    }
    $value = app_config()[$key] ?? null;
    return is_string($value) && $value !== '' ? $value : $fallback;
}

function app_brand(): string
{
    return app_setting('app_name', 'SIMPUN');
}

function app_headline(): string
{
    return app_setting('app_headline', 'Sistem Informasi Manajemen Publikasi Untuk Naskah');
}

function app_subtitle(): string
{
    return app_setting('app_subtitle', 'Sistem Informasi Manajemen Publikasi Untuk Naskah');
}

function app_logo(): string
{
    return app_setting('app_logo', 'logo.png');
}

function app_theme_style(): string
{
    $style = app_setting('theme_style', 'hud');
    if ($style === 'macos') {
        return 'standart';
    }
    if ($style === 'macos-style') {
        return 'standart';
    }
    return in_array($style, ['hud', 'classic', 'standart'], true) ? $style : 'hud';
}

function app_theme_mode(): string
{
    $mode = app_setting('theme_mode', 'dark');
    return in_array($mode, ['dark', 'light'], true) ? $mode : 'dark';
}

function public_theme_mode(): string
{
    if (is_admin()) {
        return app_theme_mode();
    }
    $mode = $_COOKIE['simpun_theme_mode'] ?? '';
    return in_array($mode, ['dark', 'light'], true) ? $mode : app_theme_mode();
}

function render_public_mode_switcher(): void
{
    if (is_admin()) {
        return;
    }
    ?>
    <div class="public-mode-switch" role="group" aria-label="Mode warna">
      <button type="button" data-theme-mode="light" aria-label="Gunakan mode terang" title="Mode terang"><span aria-hidden="true">☀</span><span class="mode-label">Terang</span></button>
      <button type="button" data-theme-mode="dark" aria-label="Gunakan mode gelap" title="Mode gelap"><span aria-hidden="true">◐</span><span class="mode-label">Gelap</span></button>
    </div>
    <?php
}

function admin_credentials(): array
{
    $config = app_config();
    $fallback = [
        'username' => (string) $config['admin_username'],
        'password_hash' => (string) $config['admin_password_hash'],
    ];

    if (!is_file(ADMIN_CREDENTIALS_FILE)) {
        return $fallback;
    }

    $credentials = require ADMIN_CREDENTIALS_FILE;
    if (!is_array($credentials) || !is_string($credentials['username'] ?? null) || !is_string($credentials['password_hash'] ?? null) || $credentials['username'] === '' || $credentials['password_hash'] === '') {
        return $fallback;
    }

    return ['username' => $credentials['username'], 'password_hash' => $credentials['password_hash']];
}

function save_admin_credentials(string $username, string $passwordHash): bool
{
    $contents = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export([
        'username' => $username,
        'password_hash' => $passwordHash,
    ], true) . ";\n";
    $temporaryFile = tempnam(dirname(ADMIN_CREDENTIALS_FILE), 'admin_credentials_');
    if ($temporaryFile === false) {
        return false;
    }

    $saved = file_put_contents($temporaryFile, $contents, LOCK_EX) !== false && chmod($temporaryFile, 0640) && rename($temporaryFile, ADMIN_CREDENTIALS_FILE);
    if (!$saved && is_file($temporaryFile)) {
        unlink($temporaryFile);
    }

    return $saved;
}

function login_attempt_key(): string
{
    return hash('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
}

function load_login_attempts(): array
{
    if (!is_file(LOGIN_ATTEMPTS_FILE)) {
        return [];
    }

    $attempts = require LOGIN_ATTEMPTS_FILE;
    return is_array($attempts) ? $attempts : [];
}

function save_login_attempts(array $attempts): bool
{
    $contents = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($attempts, true) . ";\n";
    $temporaryFile = tempnam(dirname(LOGIN_ATTEMPTS_FILE), 'login_attempts_');
    if ($temporaryFile === false) {
        return false;
    }

    $saved = file_put_contents($temporaryFile, $contents, LOCK_EX) !== false && chmod($temporaryFile, 0640) && rename($temporaryFile, LOGIN_ATTEMPTS_FILE);
    if (!$saved && is_file($temporaryFile)) {
        unlink($temporaryFile);
    }

    return $saved;
}

function recent_login_attempts(): array
{
    $cutoff = time() - LOGIN_LOCKOUT_SECONDS;
    $attempts = load_login_attempts();
    $modified = false;
    foreach ($attempts as $key => $timestamps) {
        if (!is_array($timestamps)) {
            unset($attempts[$key]);
            $modified = true;
            continue;
        }
        $filtered = array_values(array_filter($timestamps, static fn (mixed $timestamp): bool => is_int($timestamp) && $timestamp >= $cutoff));
        if ($filtered !== $timestamps) {
            $modified = true;
        }
        if (!$filtered) {
            unset($attempts[$key]);
            $modified = true;
        } else {
            $attempts[$key] = $filtered;
        }
    }

    if ($modified) {
        save_login_attempts($attempts);
    }

    return $attempts;
}

function login_throttle_remaining(): int
{
    $attempts = recent_login_attempts();
    $timestamps = $attempts[login_attempt_key()] ?? [];
    if (count($timestamps) < LOGIN_MAX_ATTEMPTS) {
        return 0;
    }
    $lastAttempt = max($timestamps);
    $remaining = ($lastAttempt + LOGIN_LOCKOUT_SECONDS) - time();
    return max(0, $remaining);
}

function login_is_throttled(): bool
{
    return login_throttle_remaining() > 0;
}

function record_failed_login(): void
{
    $attempts = recent_login_attempts();
    $key = login_attempt_key();
    $attempts[$key] = $attempts[$key] ?? [];
    $attempts[$key][] = time();
    save_login_attempts($attempts);
}

function clear_failed_logins(): void
{
    $attempts = load_login_attempts();
    $key = login_attempt_key();
    if (isset($attempts[$key])) {
        unset($attempts[$key]);
        save_login_attempts($attempts);
    }
}

function admin_audit(string $action, string $details = ''): void
{
    $entry = json_encode([
        'at' => date('c'),
        'admin' => (string) ($_SESSION['admin_username'] ?? admin_credentials()['username']),
        'action' => $action,
        'details' => $details,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($entry !== false) {
        file_put_contents(ADMIN_AUDIT_FILE, $entry . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}

function recent_admin_audit(int $limit = 30): array
{
    if (!is_file(ADMIN_AUDIT_FILE)) {
        return [];
    }

    $lines = file(ADMIN_AUDIT_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return [];
    }

    $entries = [];
    foreach (array_slice($lines, -$limit) as $line) {
        $entry = json_decode($line, true);
        if (is_array($entry) && is_string($entry['at'] ?? null) && is_string($entry['admin'] ?? null) && is_string($entry['action'] ?? null) && is_string($entry['details'] ?? null)) {
            $entries[] = $entry;
        }
    }

    return array_reverse($entries);
}

function document_statistics(): array
{
    if (!is_file(DOCUMENT_STATS_FILE)) {
        return [];
    }

    $statistics = require DOCUMENT_STATS_FILE;
    if (!is_array($statistics)) {
        return [];
    }

    $valid = [];
    foreach ($statistics as $path => $counts) {
        if (!is_string($path) || !is_array($counts)) {
            continue;
        }
        $valid[$path] = [
            'views' => max(0, (int) ($counts['views'] ?? 0)),
            'downloads' => max(0, (int) ($counts['downloads'] ?? 0)),
        ];
    }

    return $valid;
}

function document_stat(string $path): array
{
    $statistics = document_statistics();
    return $statistics[$path] ?? ['views' => 0, 'downloads' => 0];
}

function save_document_statistics(array $statistics): bool
{
    $contents = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($statistics, true) . ";\n";
    $temporaryFile = tempnam(dirname(DOCUMENT_STATS_FILE), 'document_stats_');
    if ($temporaryFile === false) {
        return false;
    }

    $saved = file_put_contents($temporaryFile, $contents, LOCK_EX) !== false && chmod($temporaryFile, 0640) && rename($temporaryFile, DOCUMENT_STATS_FILE);
    if (!$saved && is_file($temporaryFile)) {
        unlink($temporaryFile);
    }

    return $saved;
}

function record_document_stat(string $path, string $type): void
{
    if (!in_array($type, ['views', 'downloads'], true)) {
        return;
    }

    $lock = fopen(DOCUMENT_STATS_FILE . '.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX)) {
        return;
    }

    $statistics = document_statistics();
    $statistics[$path] = $statistics[$path] ?? ['views' => 0, 'downloads' => 0];
    $statistics[$path][$type] = (int) $statistics[$path][$type] + 1;
    save_document_statistics($statistics);
    flock($lock, LOCK_UN);
    fclose($lock);
}

function load_documents(): array
{
    $documents = require DOCUMENTS_FILE;
    return is_array($documents) ? $documents : [];
}

function save_documents(array $documents): bool
{
    ksort($documents, SORT_NATURAL | SORT_FLAG_CASE);
    $contents = "<?php\n\nreturn " . var_export($documents, true) . ";\n";
    $temporaryFile = tempnam(dirname(DOCUMENTS_FILE), 'documents_');
    if ($temporaryFile === false) {
        return false;
    }
    $saved = file_put_contents($temporaryFile, $contents, LOCK_EX) !== false
        && chmod($temporaryFile, 0640)
        && rename($temporaryFile, DOCUMENTS_FILE);
    if (!$saved && is_file($temporaryFile)) {
        unlink($temporaryFile);
    }
    if ($saved && function_exists('opcache_invalidate')) {
        opcache_invalidate(DOCUMENTS_FILE, true);
    }
    return $saved;
}

function load_folder_visibility(): array
{
    if (!is_file(FOLDER_VISIBILITY_FILE)) {
        return [];
    }
    $stored = require FOLDER_VISIBILITY_FILE;
    if (!is_array($stored)) {
        return [];
    }

    $folders = [];
    foreach ($stored as $folder => $visibility) {
        // PHP casts numeric directory keys (e.g. "2026") to integers in array files.
        if ((is_string($folder) || is_int($folder)) && (string) $folder !== ''
            && safe_relative_directory((string) $folder) === (string) $folder && $visibility === 'unlisted') {
            $folder = (string) $folder;
            $folders[$folder] = 'unlisted';
        }
    }
    return $folders;
}

function save_folder_visibility(array $folders): bool
{
    ksort($folders, SORT_NATURAL | SORT_FLAG_CASE);
    $contents = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($folders, true) . ";\n";
    $temporaryFile = tempnam(dirname(FOLDER_VISIBILITY_FILE), 'folder_visibility_');
    if ($temporaryFile === false) {
        return false;
    }
    $saved = file_put_contents($temporaryFile, $contents, LOCK_EX) !== false
        && chmod($temporaryFile, 0640)
        && rename($temporaryFile, FOLDER_VISIBILITY_FILE);
    if (!$saved && is_file($temporaryFile)) {
        unlink($temporaryFile);
    }
    if ($saved && function_exists('opcache_invalidate')) {
        opcache_invalidate(FOLDER_VISIBILITY_FILE, true);
    }
    return $saved;
}

function load_folder_pins(): array
{
    if (!is_file(FOLDER_PINS_FILE)) {
        return [];
    }
    $stored = require FOLDER_PINS_FILE;
    if (!is_array($stored)) {
        return [];
    }
    $pins = [];
    foreach ($stored as $folder => $hash) {
        if ((is_string($folder) || is_int($folder)) && (string) $folder !== ''
            && safe_relative_directory((string) $folder) === (string) $folder
            && is_string($hash) && $hash !== '') {
            $pins[(string) $folder] = $hash;
        }
    }
    return $pins;
}

function save_folder_pins(array $pins): bool
{
    ksort($pins, SORT_NATURAL | SORT_FLAG_CASE);
    $contents = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($pins, true) . ";\n";
    $temporaryFile = tempnam(dirname(FOLDER_PINS_FILE), 'folder_pins_');
    if ($temporaryFile === false) {
        return false;
    }
    $saved = file_put_contents($temporaryFile, $contents, LOCK_EX) !== false
        && chmod($temporaryFile, 0640)
        && rename($temporaryFile, FOLDER_PINS_FILE);
    if (!$saved && is_file($temporaryFile)) {
        unlink($temporaryFile);
    }
    if ($saved && function_exists('opcache_invalidate')) {
        opcache_invalidate(FOLDER_PINS_FILE, true);
    }
    return $saved;
}

function folder_pin_grant_key(string $folder, string $pinHash): string
{
    return hash('sha256', "folder\0" . $folder . "\0" . $pinHash);
}

/** The first locked PIN on the path, including ancestors; descendants cannot bypass it. */
function locked_folder_pin(string $folder, ?array $rules = null, ?array $pins = null): ?array
{
    if (is_admin() || $folder === '') {
        return null;
    }
    $rules ??= load_folder_visibility();
    $pins ??= load_folder_pins();
    $current = '';
    foreach (explode('/', $folder) as $segment) {
        $current = $current === '' ? $segment : $current . '/' . $segment;
        $hash = $pins[$current] ?? null;
        if (($rules[$current] ?? null) === 'unlisted' && is_string($hash) && $hash !== ''
            && empty($_SESSION['unlocked_folders'][folder_pin_grant_key($current, $hash)])) {
            return ['folder' => $current, 'hash' => $hash];
        }
    }
    return null;
}

function folder_public_url(string $folder): string
{
    return app_url('folder/' . implode('/', array_map('rawurlencode', explode('/', $folder))));
}

function folder_is_unlisted(string $folder, ?array $rules = null): bool
{
    $rules ??= load_folder_visibility();
    while ($folder !== '') {
        if (($rules[$folder] ?? null) === 'unlisted') {
            return true;
        }
        $parent = dirname($folder);
        $folder = $parent === '.' ? '' : str_replace('\\', '/', $parent);
    }
    return false;
}

function document_visibility(array $document): string
{
    if (!array_key_exists('visibility', $document)) {
        return 'public'; // Metadata lama tetap dapat dilihat publik.
    }
    $visibility = $document['visibility'];
    return in_array($visibility, ['public', 'unlisted', 'hidden'], true) ? $visibility : 'hidden';
}

function document_pin_hash(array $document): ?string
{
    $hash = $document['pin_hash'] ?? null;
    return document_visibility($document) === 'unlisted' && is_string($hash) && $hash !== '' ? $hash : null;
}

function document_pin_grant_key(string $path, string $pinHash): string
{
    return hash('sha256', $path . "\0" . $pinHash);
}

function document_pin_is_unlocked(array $document, string $path): bool
{
    if (is_admin() || ($hash = document_pin_hash($document)) === null) {
        return true;
    }
    return !empty($_SESSION['unlocked_docs'][document_pin_grant_key($path, $hash)]);
}

function load_pin_attempts(): array
{
    if (!is_file(PIN_ATTEMPTS_FILE)) {
        return [];
    }
    $stored = require PIN_ATTEMPTS_FILE;
    return is_array($stored) ? $stored : [];
}

function save_pin_attempts(array $attempts): bool
{
    $contents = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($attempts, true) . ";\n";
    $temporaryFile = tempnam(dirname(PIN_ATTEMPTS_FILE), 'pin_attempts_');
    if ($temporaryFile === false) {
        return false;
    }
    $saved = file_put_contents($temporaryFile, $contents, LOCK_EX) !== false
        && chmod($temporaryFile, 0640)
        && rename($temporaryFile, PIN_ATTEMPTS_FILE);
    if (!$saved && is_file($temporaryFile)) {
        unlink($temporaryFile);
    }
    if ($saved && function_exists('opcache_invalidate')) {
        opcache_invalidate(PIN_ATTEMPTS_FILE, true);
    }
    return $saved;
}

function pin_attempt_key(): string
{
    return hash('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
}

function pin_throttle_remaining(): int
{
    $entry = load_pin_attempts()[pin_attempt_key()] ?? [];
    return is_array($entry) ? max(0, (int) ($entry['locked_until'] ?? 0) - time()) : 0;
}

function verify_access_pin(string $pin, string $pinHash): array
{
    $denied = ['valid' => false, 'remaining' => PIN_LOCKOUT_SECONDS, 'left' => 0];
    $lock = fopen(PIN_ATTEMPTS_FILE . '.lock', 'c');
    if ($lock === false) {
        return $denied;
    }
    if (!flock($lock, LOCK_EX)) {
        fclose($lock);
        return $denied;
    }

    try {
        $now = time();
        $key = pin_attempt_key();
        $attempts = load_pin_attempts();
        foreach ($attempts as $attemptKey => $history) {
            if (!is_array($history)) {
                unset($attempts[$attemptKey]);
                continue;
            }
            $active = array_values(array_filter(
                is_array($history['attempts'] ?? null) ? $history['attempts'] : [],
                static fn (mixed $at): bool => is_int($at) && $at > $now - PIN_LOCKOUT_SECONDS
            ));
            if (!$active && (int) ($history['locked_until'] ?? 0) <= $now) {
                unset($attempts[$attemptKey]);
            }
        }
        $entry = is_array($attempts[$key] ?? null) ? $attempts[$key] : [];
        $lockedUntil = (int) ($entry['locked_until'] ?? 0);
        if ($lockedUntil > $now) {
            return ['valid' => false, 'remaining' => $lockedUntil - $now, 'left' => 0];
        }
        $recent = $lockedUntil > 0 ? [] : array_values(array_filter(
            is_array($entry['attempts'] ?? null) ? $entry['attempts'] : [],
            static fn (mixed $at): bool => is_int($at) && $at > $now - PIN_LOCKOUT_SECONDS
        ));

        if (preg_match('/\A[0-9]{6}\z/', $pin) && password_verify($pin, $pinHash)) {
            // A correct PIN for another document must not clear recent failures.
            return save_pin_attempts($attempts)
                ? ['valid' => true, 'remaining' => 0, 'left' => max(0, PIN_MAX_ATTEMPTS - count($recent))]
                : $denied;
        }

        $recent[] = $now;
        $left = max(0, PIN_MAX_ATTEMPTS - count($recent));
        $remaining = $left === 0 ? PIN_LOCKOUT_SECONDS : 0;
        $attempts[$key] = ['attempts' => $recent, 'locked_until' => $remaining ? $now + $remaining : 0];
        if (!save_pin_attempts($attempts)) {
            return $denied;
        }
        return ['valid' => false, 'remaining' => $remaining, 'left' => $left];
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/** Require a session grant for either a folder or document PIN. */
function require_pin_grant(string $pinHash, string $sessionBucket, string $grantKey, string $url, string $subject): void
{
    if (is_admin()) {
        return;
    }
    start_portal_session();
    if (!empty($_SESSION[$sessionBucket][$grantKey])) {
        session_write_close();
        return;
    }

    header('Cache-Control: private, no-store');
    header('X-Robots-Tag: noindex, nofollow');
    $error = '';
    $remaining = pin_throttle_remaining();
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        verify_csrf();
        $submittedPin = $_POST['pin'] ?? '';
        $result = verify_access_pin(is_string($submittedPin) ? $submittedPin : '', $pinHash);
        if ($result['valid']) {
            session_regenerate_id(true);
            $_SESSION[$sessionBucket][$grantKey] = true;
            redirect($url);
        }
        $remaining = $result['remaining'];
        if ($remaining > 0) {
            http_response_code(429);
            header('Retry-After: ' . $remaining);
            $error = "Terlalu banyak percobaan. Coba lagi dalam {$remaining} detik.";
        } else {
            $error = "PIN tidak tepat. Sisa percobaan: {$result['left']}.";
        }
    } elseif ($remaining > 0) {
        $error = "Terlalu banyak percobaan. Coba lagi dalam {$remaining} detik.";
    }

    page_header('Verifikasi PIN ' . strtolower($subject), 'fm-body', 'assets/public.css', ['robots' => 'noindex, nofollow']);
    ?>
<header class="fm-masthead">
  <div class="fm-masthead-inner">
    <div class="fm-brand-zone"><a class="brand-link" href="<?= e(app_url()) ?>"><?php if (is_file(APP_ROOT . '/' . app_logo())): ?><img class="brand-mark" src="<?= e(app_url(app_logo())) ?>" alt=""><?php endif; ?><span class="brand-name"><?= e(app_brand()) ?></span></a></div>
    <a class="btn-cyber-outline" href="<?= e(app_url()) ?>">&larr; Kembali ke File Explorer</a>
  </div>
</header>
<main class="fm-workspace pin-workspace">
  <section class="pin-card" aria-labelledby="pin-heading">
    <span class="visibility-badge visibility-badge--unlisted">Tautan terlindungi</span>
    <h1 id="pin-heading">Masukkan PIN untuk membuka <?= e(strtolower($subject)) ?></h1>
    <p><?= e($subject) ?> ini hanya dapat dibuka setelah verifikasi PIN enam digit.</p>
    <?php if ($error !== ''): ?><p class="pin-error" role="alert"><?= e($error) ?></p><?php endif; ?>
    <form method="post" action="<?= e($url) ?>" autocomplete="off">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
      <label for="access-pin">PIN enam digit</label>
      <input id="access-pin" name="pin" type="password" inputmode="numeric" pattern="[0-9]{6}" minlength="6" maxlength="6" autocomplete="off" required autofocus<?= $remaining > 0 ? ' disabled' : '' ?>>
      <button class="btn-cyber" type="submit"<?= $remaining > 0 ? ' disabled' : '' ?>>Buka <?= e(strtolower($subject)) ?></button>
    </form>
  </section>
</main>
<?php page_footer(); exit;
}

function document_access_level(array $document, string $path, ?array $folderRules = null): string
{
    $visibility = document_visibility($document);
    if ($visibility === 'public') {
        $directory = str_replace('\\', '/', dirname($path));
        if (folder_is_unlisted($directory === '.' ? '' : $directory, $folderRules)) {
            return 'unlisted';
        }
    }
    return $visibility;
}

function visibility_label(string $visibility): string
{
    return match ($visibility) {
        'unlisted' => 'Akses via Tautan',
        'hidden' => 'Privat',
        default => 'Publik',
    };
}

function start_portal_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name('document_portal_admin');
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
    session_start();
}

function load_admin_session_if_present(): void
{
    // Tamu tanpa cookie tidak perlu dibuatkan sesi untuk membaca berkas publik.
    if (is_string($_COOKIE['document_portal_admin'] ?? null) && session_status() === PHP_SESSION_NONE) {
        start_portal_session();
        session_write_close();
    }
}

function accessible_document_file(string $relativePath): ?array
{
    if (safe_relative_path($relativePath) !== $relativePath) {
        return null;
    }
    $documents = load_documents();
    $document = $documents[$relativePath] ?? null;
    if (!is_array($document) || (document_visibility($document) === 'hidden' && !is_admin())
        || !document_pin_is_unlocked($document, $relativePath)
        || locked_folder_pin(($directory = str_replace('\\', '/', dirname($relativePath))) === '.' ? '' : $directory) !== null) {
        return null;
    }
    $absolutePath = absolute_file_path($relativePath);
    return $absolutePath === null ? null : ['document' => $document, 'absolute_path' => $absolutePath];
}

function supported_extension(string $path): ?string
{
    $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    return in_array($extension, app_config()['allowed_extensions'], true) ? $extension : null;
}

function file_category(string $ext): string
{
    return match (strtolower($ext)) {
        'pdf' => 'pdf',
        'docx' => 'docx',
        'xls', 'xlsx' => 'xlsx',
        'png', 'jpg', 'jpeg', 'webp', 'svg' => 'image',
        'csv' => 'csv',
        default => 'file',
    };
}

function render_format_icon(string $category, string $extension): void
{
    $cat = strtolower($category);
    ?>
    <div class="file-icon-box type-<?= e($cat) ?>" aria-hidden="true">
      <?php if ($cat === 'pdf'): ?>
        <svg class="file-type-glyph" viewBox="0 0 24 24" fill="currentColor">
          <path d="M6 2a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8.5L13.5 2H6zm7 1.5L18.5 8.5H13V3.5zM8 12.5h1.5a1.5 1.5 0 0 1 0 3H8v2H7v-5h1zm0 2h1.5a.5.5 0 0 0 0-1H8v1zm4-2h1.5a2 2 0 0 1 0 4H12v-4zm1 3a1 1 0 0 0 0-2h-.5v2h.5zm3-3h2.5v1H17v1h1.5v1H17v2h-1v-5z"/>
        </svg>
      <?php elseif ($cat === 'docx'): ?>
        <svg class="file-type-glyph" viewBox="0 0 24 24" fill="currentColor">
          <path d="M6 2a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8.5L13.5 2H6zm7 1.5L18.5 8.5H13V3.5zM7.2 12.5h1.3l.9 3.2.9-3.2h1.3l-1.5 5h-1.3l-.8-2.8-.8 2.8H7l-1.5-5h1.3l.9 3.2.9-3.2zm6.3 0h2a1.5 1.5 0 0 1 0 3h-2v2H12v-5h1.5zm0 1.8h1.2a.5.5 0 0 0 0-1H13.5v1z"/>
        </svg>
      <?php elseif ($cat === 'xlsx'): ?>
        <svg class="file-type-glyph" viewBox="0 0 24 24" fill="currentColor">
          <path d="M6 2a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8.5L13.5 2H6zm7 1.5L18.5 8.5H13V3.5zM7 11h10v8H7v-8zm1 1v6h3v-6H8zm4 0v6h4v-6h-4z"/>
        </svg>
      <?php elseif ($cat === 'csv'): ?>
        <svg class="file-type-glyph" viewBox="0 0 24 24" fill="currentColor">
          <path d="M6 2a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8.5L13.5 2H6zm7 1.5L18.5 8.5H13V3.5zM7 11h10v1.5H7V11zm0 3h10v1.5H7V14zm0 3h7v1.5H7V17z"/>
        </svg>
      <?php elseif ($cat === 'image'): ?>
        <svg class="file-type-glyph" viewBox="0 0 24 24" fill="currentColor">
          <path d="M6 2a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8.5L13.5 2H6zm7 1.5L18.5 8.5H13V3.5zM8.5 11a1.5 1.5 0 1 1 0 3 1.5 1.5 0 0 1 0-3zm-1.5 7 2.5-3.5 1.8 2.4 2.7-3.6 3 4.7H7z"/>
        </svg>
      <?php else: ?>
        <svg class="file-type-glyph" viewBox="0 0 24 24" fill="currentColor">
          <path d="M6 2a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8.5L13.5 2H6zm7 1.5L18.5 8.5H13V3.5zM8 12h8v1H8v-1zm0 2h8v1H8v-1zm0 2h5v1H8v-1z"/>
        </svg>
      <?php endif; ?>
    </div>
    <?php
}

function safe_relative_path(string $path): ?string
{
    // Defense against double URL encoding & null-bytes
    for ($i = 0; $i < 3; $i++) {
        $decoded = rawurldecode($path);
        if ($decoded === $path) {
            break;
        }
        $path = $decoded;
    }

    if (str_contains($path, "\0") || str_contains($path, "\x00")) {
        return null;
    }

    $path = str_replace('\\', '/', $path);
    $path = ltrim($path, '/');
    if ($path === '' || preg_match('#(^|/)\.\.?(/|$)#', $path)) {
        return null;
    }

    return $path;
}

function safe_relative_directory(string $path): ?string
{
    for ($i = 0; $i < 3; $i++) {
        $decoded = rawurldecode($path);
        if ($decoded === $path) {
            break;
        }
        $path = $decoded;
    }

    if (str_contains($path, "\0") || str_contains($path, "\x00")) {
        return null;
    }

    $path = trim(str_replace('\\', '/', $path), '/');
    if ($path === '') {
        return '';
    }
    if (preg_match('#(^|/)\.\.?(/|$)#', $path)) {
        return null;
    }
    return safe_relative_path($path);
}

function absolute_directory_path(string $relativePath, bool $create = false): ?string
{
    $relativePath = safe_relative_directory($relativePath);
    if ($relativePath === null) {
        return null;
    }

    $root = realpath(FILES_ROOT);
    if ($root === false) {
        return null;
    }
    $candidate = FILES_ROOT . ($relativePath === '' ? '' : '/' . $relativePath);
    if ($create && !is_dir($candidate) && !mkdir($candidate, 0755, true) && !is_dir($candidate)) {
        return null;
    }
    $path = realpath($candidate);
    if ($path === false || !is_dir($path) || ($path !== $root && !str_starts_with($path, $root . DIRECTORY_SEPARATOR))) {
        return null;
    }

    return $path;
}

function absolute_file_path(string $relativePath): ?string
{
    $relativePath = safe_relative_path($relativePath);
    if ($relativePath === null || supported_extension($relativePath) === null) {
        return null;
    }

    $root = realpath(FILES_ROOT);
    $path = realpath(FILES_ROOT . '/' . $relativePath);
    if ($root === false || $path === false || !is_file($path) || !str_starts_with($path, $root . DIRECTORY_SEPARATOR)) {
        return null;
    }

    return $path;
}

function discover_files(): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(FILES_ROOT, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );

    foreach ($iterator as $file) {
        if (!$file->isFile() || $file->isLink()) {
            continue;
        }
        $relativePath = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen(FILES_ROOT) + 1));
        if (supported_extension($relativePath) !== null) {
            $files[$relativePath] = $file;
        }
    }

    ksort($files, SORT_NATURAL | SORT_FLAG_CASE);
    return $files;
}

function discover_directories(): array
{
    $directories = [''];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(FILES_ROOT, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $directory) {
        if (!$directory->isDir() || $directory->isLink()) {
            continue;
        }
        $relativePath = str_replace(DIRECTORY_SEPARATOR, '/', substr($directory->getPathname(), strlen(FILES_ROOT) + 1));
        if (safe_relative_directory($relativePath) !== null) {
            $directories[] = $relativePath;
        }
    }

    $directories = array_values(array_unique($directories));
    usort($directories, static fn (string $a, string $b): int => strnatcasecmp($a, $b));
    return $directories;
}

function published_documents(): array
{
    $documents = load_documents();
    $published = [];
    foreach ($documents as $path => $document) {
        if (!is_string($path) || !is_array($document) || ($absolutePath = absolute_file_path($path)) === null) {
            continue;
        }
        $document['path'] = $path;
        $document['filename'] = document_display_name($document, $path);
        $document['published_at'] = effective_publication_date($document, $absolutePath);
        $document['size'] = filesize($absolutePath) ?: 0;
        $document['visibility'] = document_visibility($document);
        $published[] = $document;
    }

    usort($published, 'compare_public_documents');
    return $published;
}

function public_documents(): array
{
    $folderRules = load_folder_visibility();
    return array_values(array_filter(
        published_documents(),
        static fn (array $document): bool => document_access_level($document, $document['path'], $folderRules) === 'public'
    ));
}

function document_display_name(array $document, string $path): string
{
    $filename = trim((string) ($document['filename'] ?? ''));
    if ($filename !== '') {
        return basename(str_replace('\\', '/', $filename));
    }
    return basename($path);
}

function effective_publication_date(array $document, string $absolutePath): string
{
    $manualDate = trim((string) ($document['published_at'] ?? ''));
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $manualDate);
    if ($parsed !== false && $parsed->format('Y-m-d') === $manualDate) {
        return $manualDate;
    }
    $modifiedAt = filemtime($absolutePath) ?: time();
    return date('Y-m-d', $modifiedAt);
}

function slugify(string $value): string
{
    $value = trim($value);
    $transliterated = function_exists('iconv') ? iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) : $value;
    $value = $transliterated === false ? $value : $transliterated;
    $value = function_exists('mb_strtolower') ? mb_strtolower($value) : strtolower($value);
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
    return trim($value, '-') ?: 'dokumen';
}

function document_slug(array $document): string
{
    $directory = str_replace('\\', '/', dirname($document['path']));
    $segments = $directory === '.' ? [] : array_map('slugify', explode('/', $directory));
    $segments[] = slugify($document['filename']);
    return implode('/', $segments);
}

function document_public_url(array $document): string
{
    return app_url('dokumen/' . document_slug($document));
}

function find_accessible_document_by_slug(string $slug): ?array
{
    $slug = trim(rawurldecode($slug), '/');
    if ($slug === '' || !preg_match('#^[a-z0-9/-]+$#', $slug)) {
        return null;
    }
    foreach (published_documents() as $document) {
        if (hash_equals(document_slug($document), $slug)
            && ($document['visibility'] !== 'hidden' || is_admin())) {
            return $document;
        }
    }
    return null;
}

function document_sort_number(array $document): ?int
{
    return preg_match('/^\s*(\d+)\./', $document['filename'], $matches) ? (int) $matches[1] : null;
}

function compare_public_documents(array $a, array $b): int
{
    $aNumber = document_sort_number($a);
    $bNumber = document_sort_number($b);
    if ($aNumber !== null && $bNumber !== null) {
        return $aNumber <=> $bNumber ?: strnatcasecmp($a['filename'], $b['filename']);
    }
    if ($aNumber !== null) {
        return -1;
    }
    if ($bNumber !== null) {
        return 1;
    }
    return strnatcasecmp($a['filename'], $b['filename']);
}

function public_documents_by_directory(): array
{
    $groups = [];
    foreach (public_documents() as $document) {
        $directory = str_replace('\\', '/', dirname($document['path']));
        $directory = $directory === '.' ? '' : $directory;
        $groups[$directory][] = $document;
    }
    uksort($groups, static fn (string $a, string $b): int => strnatcasecmp($a, $b));
    return $groups;
}

function public_document_tree(): array
{
    return build_document_tree(public_documents());
}

function published_document_tree(): array
{
    return build_document_tree(published_documents());
}

function build_document_tree(array $documents): array
{
    $tree = ['documents' => [], 'children' => []];
    foreach ($documents as $document) {
        $directory = str_replace('\\', '/', dirname($document['path']));
        $segments = $directory === '.' ? [] : explode('/', $directory);
        $node =& $tree;
        foreach ($segments as $segment) {
            if (!isset($node['children'][$segment])) {
                $node['children'][$segment] = ['documents' => [], 'children' => []];
            }
            $node =& $node['children'][$segment];
        }
        $node['documents'][] = $document;
        unset($node);
    }

    sort_public_document_tree($tree);
    return $tree;
}

function document_tree_folder_count(array $node): int
{
    $count = count($node['children']);
    foreach ($node['children'] as $child) {
        $count += document_tree_folder_count($child);
    }
    return $count;
}

function sort_public_document_tree(array &$node): void
{
    usort($node['documents'], 'compare_public_documents');
    uksort($node['children'], static fn (string $a, string $b): int => strnatcasecmp($a, $b));
    foreach ($node['children'] as &$child) {
        sort_public_document_tree($child);
    }
    unset($child);
}

function document_tree_count(array $node): int
{
    $count = count($node['documents']);
    foreach ($node['children'] as $child) {
        $count += document_tree_count($child);
    }
    return $count;
}

function directory_option_label(string $directory): string
{
    if ($directory === '') {
        return 'Dokumen Utama';
    }
    $depth = substr_count($directory, '/') + 1;
    return str_repeat('-- ', $depth - 1) . $directory;
}

function directory_label(string $directory): string
{
    return $directory === '' ? 'Dokumen Utama' : str_replace('/', ' / ', $directory);
}

function app_base_path(): string
{
    // Auto-detect directory from SCRIPT_NAME (supports subfolder deployment zero-config)
    if (isset($_SERVER['SCRIPT_NAME']) && is_string($_SERVER['SCRIPT_NAME']) && $_SERVER['SCRIPT_NAME'] !== '') {
        $scriptDir = trim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/.');
        return $scriptDir === '' ? '' : '/' . $scriptDir;
    }

    // Fallback: inspect configured public_base_url if set and not placeholder
    $configured = app_config()['public_base_url'] ?? '';
    if (is_string($configured) && $configured !== '' && !str_contains($configured, 'domain-anda.tld')) {
        $parsed = trim((string) parse_url($configured, PHP_URL_PATH), '/.');
        return $parsed === '' ? '' : '/' . $parsed;
    }

    return '';
}

function app_url(string $path = ''): string
{
    $path = ltrim($path, '/');
    $basePath = app_base_path();
    if ($path === '') {
        return $basePath === '' ? '/' : $basePath;
    }
    return ($basePath === '' ? '' : $basePath) . '/' . $path;
}

function app_full_url(string $path = ''): string
{
    $configured = (string) (app_config()['public_base_url'] ?? '');
    if ($configured !== '' && !str_contains($configured, 'domain-anda.tld')) {
        $base = rtrim($configured, '/');
    } else {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $base = $scheme . '://' . $host . app_base_path();
    }
    $path = ltrim($path, '/');
    return $path === '' ? $base : $base . '/' . $path;
}

function public_file_url(string $script, string $relativePath): string
{
    $url = app_full_url($script) . '?file=' . rawurlencode($relativePath);
    return $script === 'view.php' ? $url . '&v=' . preview_file_version($relativePath) : $url;
}

function preview_file_version(string $relativePath): int
{
    $path = absolute_file_path($relativePath);
    return $path ? (filemtime($path) ?: 0) : 0;
}

function document_view_url(string $relativePath): string
{
    return app_url('view.php?file=' . rawurlencode($relativePath) . '&v=' . preview_file_version($relativePath));
}

function document_download_url(string $relativePath): string
{
    return app_url('download.php?file=' . rawurlencode($relativePath));
}

function office_viewer_url(string $relativePath): string
{
    return 'https://view.officeapps.live.com/op/embed.aspx?src=' . rawurlencode(public_file_url('view.php', $relativePath));
}

function is_local_request(): bool
{
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    $host = preg_replace('/:\d+$/', '', $host) ?? $host;

    return $host === ''
        || str_starts_with($host, 'localhost')
        || str_starts_with($host, '127.0.0.1')
        || str_starts_with($host, '[::1]')
        || str_ends_with($host, '.test')
        || str_ends_with($host, '.local');
}

function format_publication_date(string $date): string
{
    $dateTime = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!$dateTime) {
        return $date;
    }

    $months = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
        5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
        9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
    ];
    return $dateTime->format('d') . '/' . $months[(int) $dateTime->format('n')] . '/' . $dateTime->format('Y');
}

function format_file_size(int $bytes): string
{
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    $units = ['KB', 'MB', 'GB', 'TB'];
    $index = 0;
    $size = $bytes / 1024;
    while ($size >= 1024 && $index < count($units) - 1) {
        $size /= 1024;
        $index++;
    }
    return number_format($size, $size >= 10 ? 0 : 1, ',', '.') . ' ' . $units[$index];
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf(): void
{
    $token = (string) ($_POST['csrf_token'] ?? '');
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        http_response_code(403);
        exit('Permintaan tidak valid. Silakan ulangi.');
    }
}

function is_admin(): bool
{
    return !empty($_SESSION['is_admin']);
}

function require_admin(): void
{
    if (!is_admin()) {
        header('Location: admin.php');
        exit;
    }
}

function redirect(string $location): never
{
    header('Location: ' . $location);
    exit;
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function send_security_headers(bool $allowFrame = false): void
{
    if (PHP_SAPI === 'cli' || headers_sent()) {
        return;
    }
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    if (!$allowFrame) {
        header('X-Frame-Options: SAMEORIGIN');
    }
}

function page_header(string $title, string $bodyClass = '', string $stylesheet = 'assets/app.css', array $metaTags = []): void
{
    send_security_headers();
    $pageTitle = $title === app_headline()
        ? app_brand() . ' - ' . app_headline()
        : ($title === app_brand() ? $title : $title . ' - ' . app_brand());
    ?><!doctype html>
<html lang="id" data-base-path="<?= e(app_base_path() ?: '/') ?>" data-theme-style="<?= e(app_theme_style()) ?>" data-theme="<?= e(public_theme_mode()) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($pageTitle) ?></title>
  <?php foreach ($metaTags as $name => $content): ?>
    <?php if (str_starts_with($name, 'og:') || str_starts_with($name, 'twitter:')): ?>
      <meta property="<?= e($name) ?>" content="<?= e($content) ?>">
    <?php else: ?>
      <meta name="<?= e($name) ?>" content="<?= e($content) ?>">
    <?php endif; ?>
  <?php endforeach; ?>
  <?php if (is_file(APP_ROOT . '/apple-touch-icon.png')): ?>
  <link rel="apple-touch-icon" href="<?= e(app_url('apple-touch-icon.png')) ?>">
  <?php elseif (is_file(APP_ROOT . '/' . app_logo())): ?>
  <link rel="apple-touch-icon" href="<?= e(app_url(app_logo())) ?>">
  <?php endif; ?>
  <?php if (is_file(APP_ROOT . '/' . app_logo())): ?>
  <link rel="icon" type="image/png" href="<?= e(app_url(app_logo())) ?>">
  <?php endif; ?>
  <link rel="stylesheet" href="<?= e(app_url($stylesheet)) ?>">
  <script src="<?= e(app_url('assets/js/theme-mode.js')) ?>" defer></script>
</head>
<body<?= $bodyClass ? ' class="' . e($bodyClass) . '"' : '' ?>>
<?php
}

function page_footer(): void
{
    render_app_footer();
    ?></body>
</html><?php
}

function render_app_footer(): void
{
    ?>
<footer class="app-footer" aria-label="Informasi aplikasi">
  <div class="app-footer-inner">
    <div class="app-footer-identity">
      <a class="app-footer-brand" href="<?= e(app_url()) ?>"><?= e(app_brand()) ?></a>
      <span class="app-footer-tagline"><?= e(app_subtitle()) ?></span>
      <span class="app-footer-credit">by <a href="https://lodrix.id" target="_blank" rel="noopener noreferrer">lodrix.id</a></span>
    </div>
    <a class="app-footer-repository" href="https://github.com/LodriX/Publikasi-doc" target="_blank" rel="noopener noreferrer" aria-label="Lihat source code SIMPUN di GitHub">
      <svg aria-hidden="true" viewBox="0 0 16 16" width="18" height="18" fill="currentColor"><path d="M8 0C3.58 0 0 3.67 0 8.2c0 3.62 2.29 6.69 5.47 7.77.4.08.55-.18.55-.4v-1.41c-2.23.5-2.7-.98-2.7-.98-.36-.95-.9-1.2-.9-1.2-.73-.52.06-.51.06-.51.8.06 1.22.85 1.22.85.71 1.25 1.87.89 2.33.68.07-.53.28-.89.5-1.1-1.78-.21-3.65-.92-3.65-4.07 0-.9.31-1.64.82-2.22-.08-.21-.36-1.05.08-2.19 0 0 .67-.22 2.2.85A7.4 7.4 0 0 1 8 3.9c.68 0 1.36.1 2 .28 1.53-1.07 2.2-.85 2.2-.85.44 1.14.16 1.98.08 2.19.51.58.82 1.32.82 2.22 0 3.16-1.87 3.86-3.66 4.07.29.26.54.76.54 1.53v2.23c0 .22.15.49.55.4A8.22 8.22 0 0 0 16 8.2C16 3.67 12.42 0 8 0Z"/></svg>
      <span>Lihat di GitHub</span>
    </a>
  </div>
</footer>
    <?php
}
