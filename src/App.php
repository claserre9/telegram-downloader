<?php
require __DIR__ . '/../vendor/autoload.php';

use claserre9\commands\DownloadMediaCommand;
use Symfony\Component\Console\Application;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->safeLoad();

$application = new Application('Telegram Downloader', '1.0.0');

$application->add(new DownloadMediaCommand());
try {
    $application->run();
} catch (Exception $e) {
    exit($e->getMessage().PHP_EOL);
}