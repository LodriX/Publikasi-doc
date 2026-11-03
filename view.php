<?php

declare(strict_types=1);

require __DIR__ . '/lib.php';

$relativePath = (string) ($_GET['file'] ?? '');
$documents = load_documents();
$document = $documents[$relativePath] ?? null;
$path = is_array($document) ? absolute_file_path($relativePath) : null;

if ($path === null) {
    http_response_code(404);
    exit('Dokumen tidak ditemukan.');
}

$mime = mime_content_type($path) ?: 'application/octet-stream';
$filename = document_display_name($document, $relativePath);
$size = filesize($path);
$start = 0;
$end = $size - 1;

if (isset($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $range)) {
    if ($range[1] === '' && $range[2] === '') {
        http_response_code(416);
        header('Content-Range: bytes */' . $size);
        exit;
    }
    if ($range[1] === '') {
        $start = max(0, $size - (int) $range[2]);
    } else {
        $start = (int) $range[1];
    }
    if ($range[2] !== '') {
        $end = min((int) $range[2], $size - 1);
    }
    if ($start > $end || $start >= $size) {
        http_response_code(416);
        header('Content-Range: bytes */' . $size);
        exit;
    }
    http_response_code(206);
    header("Content-Range: bytes $start-$end/$size");
}

$length = $end - $start + 1;
$etagBody = md5($size . ':' . (filemtime($path) ?: 0));
$etag = '"' . $etagBody . '"';
$ifNoneMatch = str_replace('W/', '', trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')));
$ifNoneMatch = trim($ifNoneMatch, '"');
if ($ifNoneMatch !== '' && $ifNoneMatch === $etagBody) {
    http_response_code(304);
    header('Cache-Control: private, max-age=86400, must-revalidate');
    header('X-Content-Type-Options: nosniff');
    header('ETag: ' . $etag);
    exit;
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) $length);
header("Content-Disposition: inline; filename*=UTF-8''" . rawurlencode($filename));
header('Cache-Control: private, max-age=86400, must-revalidate');
header('X-Content-Type-Options: nosniff');
header('Accept-Ranges: bytes');
header('ETag: ' . $etag);

$handle = fopen($path, 'rb');
fseek($handle, $start);
$remaining = $length;
$chunkSize = 1024 * 1024;
while (!feof($handle) && $remaining > 0) {
    $chunk = fread($handle, min($chunkSize, $remaining));
    if ($chunk === false) {
        break;
    }
    echo $chunk;
    $remaining -= strlen($chunk);
}
fclose($handle);
flush();
