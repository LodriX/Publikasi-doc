<?php

declare(strict_types=1);

require __DIR__ . '/lib.php';
load_admin_session_if_present();
header('Cache-Control: private, no-store');

$relativePath = (string) ($_GET['file'] ?? '');
$accessible = accessible_document_file($relativePath);

if ($accessible === null) {
    http_response_code(404);
    exit('Dokumen tidak ditemukan.');
}
$document = $accessible['document'];
$path = $accessible['absolute_path'];

record_document_stat($relativePath, 'downloads');
$mime = mime_content_type($path) ?: 'application/octet-stream';
$filename = document_display_name($document, $relativePath);
header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($path));
header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode($filename));
header('X-Content-Type-Options: nosniff');
if (document_access_level($document, $relativePath) !== 'public') {
    header('X-Robots-Tag: noindex, nofollow');
}
readfile($path);
