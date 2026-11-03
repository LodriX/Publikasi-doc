<?php

declare(strict_types=1);

require __DIR__ . '/lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$relativePath = (string) ($_POST['file'] ?? '');
$documents = load_documents();
$document = $documents[$relativePath] ?? null;
if (!is_array($document) || absolute_file_path($relativePath) === null) {
    http_response_code(404);
    exit;
}

record_document_stat($relativePath, 'views');
http_response_code(204);
