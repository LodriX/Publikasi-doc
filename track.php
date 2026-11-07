<?php

declare(strict_types=1);

require __DIR__ . '/lib.php';
load_admin_session_if_present();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$relativePath = (string) ($_POST['file'] ?? '');
if (accessible_document_file($relativePath) === null) {
    http_response_code(404);
    header('Cache-Control: private, no-store');
    exit;
}

record_document_stat($relativePath, 'views');
http_response_code(204);
