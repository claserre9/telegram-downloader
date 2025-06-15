<?php
require __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use claserre9\WebTelegramClient;

session_start();

$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

$sessionPath = __DIR__ . '/../sessions/web.session';

$client = new WebTelegramClient(
    (int)$_ENV['TELEGRAM_API_ID'],
    $_ENV['TELEGRAM_API_HASH'],
    $sessionPath
);

$madeline = $client->getMadeline();

?>
