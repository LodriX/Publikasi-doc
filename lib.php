<?php

declare(strict_types=1);

const APP_ROOT = __DIR__;
const FILES_ROOT = APP_ROOT . '/Files';
const DOCUMENTS_FILE = APP_ROOT . '/data/documents.php';
const ADMIN_CREDENTIALS_FILE = APP_ROOT . '/data/admin_credentials.php';
const LOGIN_ATTEMPTS_FILE = APP_ROOT . '/data/login_attempts.php';
const ADMIN_AUDIT_FILE = APP_ROOT . '/data/admin_audit.log';
const DOCUMENT_STATS_FILE = APP_ROOT . '/data/document_stats.php';

function app_config(): array
{
    return require APP_ROOT . '/config.php';
}

function app_setting(string $key, string $fallback): string
{
    $value = app_config()[$key] ?? null;
    return is_string($value) && $value !== '' ? $value : $fallback;
}

function app_brand(): string
{
    return app_setting('app_name', 'MCSP RBS KPK Kabupaten Murung Raya');
}

function app_headline(): string
{
    return app_setting('app_headline', 'Transparansi dan Publikasi');
}

function app_subtitle(): string
{
    return app_setting('app_subtitle', 'Dokumen Publikasi ' . app_brand());
}

function app_logo(): string
{
    return app_setting('app_logo', 'logo-murung-raya.png');
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
    $cutoff = time() - 900;
    $attempts = load_login_attempts();
    foreach ($attempts as $key => $timestamps) {
        if (!is_array($timestamps)) {
            unset($attempts[$key]);
            continue;
        }
        $attempts[$key] = array_values(array_filter($timestamps, static fn (mixed $timestamp): bool => is_int($timestamp) && $timestamp >= $cutoff));
        if (!$attempts[$key]) {
            unset($attempts[$key]);
        }
    }

    return $attempts;
}

function login_is_throttled(): bool
{
    $attempts = recent_login_attempts();
    return count($attempts[login_attempt_key()] ?? []) >= 5;
}

function record_failed_login(): void
{
    $attempts = recent_login_attempts();
    $key = login_attempt_key();
    $attempts[$key][] = time();
    save_login_attempts($attempts);
}

function clear_failed_logins(): void
{
    $attempts = recent_login_attempts();
    unset($attempts[login_attempt_key()]);
    save_login_attempts($attempts);
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
    return file_put_contents(DOCUMENTS_FILE, $contents, LOCK_EX) !== false;
}

function supported_extension(string $path): ?string
{
    $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    return in_array($extension, app_config()['allowed_extensions'], true) ? $extension : null;
}

function safe_relative_path(string $path): ?string
{
    $path = str_replace('\\', '/', rawurldecode($path));
    $path = ltrim($path, '/');
    if ($path === '' || str_contains($path, "\0") || preg_match('#(^|/)\.\.?(/|$)#', $path)) {
        return null;
    }

    return $path;
}

function safe_relative_directory(string $path): ?string
{
    $path = trim(str_replace('\\', '/', rawurldecode($path)), '/');
    if ($path === '') {
        return '';
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

function public_documents(): array
{
    $documents = load_documents();
    $public = [];
    foreach ($documents as $path => $document) {
        $absolutePath = absolute_file_path($path);
        if (!is_array($document) || $absolutePath === null) {
            continue;
        }
        $document['path'] = $path;
        $document['filename'] = document_display_name($document, $path);
        $document['published_at'] = effective_publication_date($document, $absolutePath);
        $document['size'] = filesize($absolutePath) ?: 0;
        $public[] = $document;
    }

    usort($public, 'compare_public_documents');
    return $public;
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

function find_public_document_by_slug(string $slug): ?array
{
    $slug = trim(rawurldecode($slug), '/');
    if ($slug === '' || !preg_match('#^[a-z0-9/-]+$#', $slug)) {
        return null;
    }
    foreach (public_documents() as $document) {
        if (hash_equals(document_slug($document), $slug)) {
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
    $tree = ['documents' => [], 'children' => []];
    foreach (public_documents() as $document) {
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

function public_file_url(string $script, string $relativePath): string
{
    $url = rtrim((string) app_config()['public_base_url'], '/') . '/' . $script . '?file=' . rawurlencode($relativePath);
    return $script === 'view.php' ? $url . '&v=' . preview_file_version($relativePath) : $url;
}

function app_url(string $path = ''): string
{
    $path = ltrim($path, '/');
    if (is_local_request()) {
        return '/' . $path;
    }
    $basePath = (string) (parse_url((string) app_config()['public_base_url'], PHP_URL_PATH) ?? '');
    return rtrim($basePath, '/') . '/' . $path;
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
    return $host === '' || str_starts_with($host, 'localhost') || str_starts_with($host, '127.0.0.1') || str_starts_with($host, '[::1]');
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

function page_header(string $title, string $bodyClass = ''): void
{
    ?><!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($title) ?> - <?= e(app_brand()) ?></title>
  <link rel="icon" type="image/png" href="<?= e(app_url(app_logo())) ?>">
  <link rel="apple-touch-icon" href="<?= e(app_url(app_logo())) ?>">
  <link rel="stylesheet" href="<?= e(app_url('assets/app.css')) ?>">
</head>
<body<?= $bodyClass ? ' class="' . e($bodyClass) . '"' : '' ?>>
<?php
}

function page_footer(): void
{
    ?></body>
</html><?php
}
