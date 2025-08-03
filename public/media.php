<?php
require __DIR__ . '/bootstrap.php';

if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    header('Location: login.php');
    exit;
}

$chat = $_GET['chat'] ?? null;
$type = $_GET['type'] ?? '';
$offsetId = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
$limit = 20;
$messages = [];
$nextOffset = 0;

if ($chat) {
    try {
        $messages = $client->getMedia($chat, $offsetId, $limit);
        if (!empty($messages)) {
            $last = end($messages);
            $nextOffset = $last['id'];
        }
    } catch (Throwable $e) {
        $error = 'Error fetching media: ' . $e->getMessage();
    }
}

function filter_media(array $messages, string $type): array {
    if ($type === '') {
        return $messages;
    }
    $filtered = [];
    foreach ($messages as $message) {
        if (!isset($message['media'])) continue;
        $mediaType = $message['media']['_'] ?? '';
        if ($type === 'photo' && $mediaType === 'messageMediaPhoto') {
            $filtered[] = $message;
        } elseif ($type === 'document' && $mediaType === 'messageMediaDocument') {
            $filtered[] = $message;
        } elseif ($type === 'video' && $mediaType === 'messageMediaDocument' && isset($message['media']['document']['mime_type']) && str_starts_with($message['media']['document']['mime_type'], 'video')) {
            $filtered[] = $message;
        } elseif ($type === 'audio' && $mediaType === 'messageMediaDocument' && isset($message['media']['document']['mime_type']) && str_starts_with($message['media']['document']['mime_type'], 'audio')) {
            $filtered[] = $message;
        }
    }
    return $filtered;
}

$messages = filter_media($messages, $type);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Media List</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<h1>Media in <?= htmlspecialchars($chat) ?></h1>
<?php if (!empty($error)): ?>
<p style="color:red;"><?= htmlspecialchars($error) ?></p>
<?php endif; ?>
<?php if ($chat): ?>
<table border="1" cellpadding="5" cellspacing="0">
    <tr><th>ID</th><th>Type</th><th>Action</th></tr>
<?php foreach ($messages as $msg): ?>
    <tr>
        <td><?= $msg['id'] ?></td>
        <td><?= htmlspecialchars($msg['media']['_'] ?? '') ?></td>
        <td><a href="download.php?chat=<?= urlencode($chat) ?>&id=<?= $msg['id'] ?>">Download</a></td>
    </tr>
<?php endforeach; ?>
</table>
<?php if ($nextOffset): ?>
    <a href="media.php?chat=<?= urlencode($chat) ?>&type=<?= urlencode($type) ?>&offset=<?= $nextOffset ?>">Next</a>
<?php endif; ?>
<?php endif; ?>
<p><a href="index.php">Back</a></p>
</body>
</html>
