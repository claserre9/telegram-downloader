<?php
require __DIR__ . '/bootstrap.php';

$step = $_SESSION['step'] ?? 'phone';
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($step === 'phone' && isset($_POST['phone'])) {
        try {
            $client->requestCode($_POST['phone']);
            $_SESSION['phone'] = $_POST['phone'];
            $_SESSION['step'] = 'code';
            header('Location: login.php');
            exit;
        } catch (Throwable $e) {
            $message = 'Error requesting code: ' . $e->getMessage();
        }
    } elseif ($step === 'code' && isset($_POST['code'])) {
        try {
            $client->completeCode($_POST['code'], $_POST['password'] ?? null);
            $_SESSION['logged_in'] = true;
            $_SESSION['step'] = 'phone';
            header('Location: index.php');
            exit;
        } catch (Throwable $e) {
            $message = 'Login error: ' . $e->getMessage();
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Login</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<h1>Login to Telegram</h1>
<p style="color:red;"><?= htmlspecialchars($message) ?></p>
<?php if ($step === 'phone'): ?>
<form method="post">
    <label>Phone number: <input type="text" name="phone" required></label>
    <button type="submit">Send code</button>
</form>
<?php else: ?>
<form method="post">
    <label>Code: <input type="text" name="code" required></label>
    <label>Password (if 2FA enabled): <input type="password" name="password"></label>
    <button type="submit">Login</button>
</form>
<?php endif; ?>
</body>
</html>
