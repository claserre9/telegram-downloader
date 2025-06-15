<?php
require __DIR__ . '/bootstrap.php';

if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    header('Location: login.php');
    exit;
}

$chat = $_GET['chat'] ?? null;
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$chat || !$id) {
    http_response_code(400);
    echo 'Invalid request';
    exit;
}

try {
    $file = $client->downloadMedia($chat, $id);
    if (!is_file($file)) {
        throw new RuntimeException('Download failed');
    }
    $name = basename($file);
    header('Content-Type: application/octet-stream');
    header("Content-Disposition: attachment; filename=\"$name\"");
    readfile($file);
    unlink($file);
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Download error: ' . $e->getMessage();
}
