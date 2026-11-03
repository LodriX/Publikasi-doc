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

record_document_stat($relativePath, 'downloads');
$mime = mime_content_type($path) ?: 'application/octet-stream';
$filename = document_display_name($document, $relativePath);
header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($path));
header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode($filename));
header('X-Content-Type-Options: nosniff');
readfile($path);
