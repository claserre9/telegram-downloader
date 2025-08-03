<?php
require __DIR__ . '/bootstrap.php';

if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    header('Location: login.php');
    exit;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Telegram Downloader</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<h1>Telegram Media Downloader</h1>
<form method="get" action="media.php">
    <label>Chat, channel or group: <input type="text" name="chat" required></label>
    <label>Type:
        <select name="type">
            <option value="">All</option>
            <option value="photo">Photos</option>
            <option value="video">Videos</option>
            <option value="document">Documents</option>
            <option value="audio">Audio</option>
        </select>
    </label>
    <button type="submit">Browse</button>
</form>
<p><a href="logout.php">Logout</a></p>
</body>
</html>
