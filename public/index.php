<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\App;
use App\Store;

// Adaptateur HTTP : superglobales → App::handle() → réponse JSON.

$dataDir = dirname(__DIR__) . '/data';
if (!is_dir($dataDir) && !mkdir($dataDir, 0750, true) && !is_dir($dataDir)) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'impossible de créer le dossier data/']), "\n";
    exit;
}

$app = new App(new Store('sqlite:' . $dataDir . '/otp.sqlite'));

$rawPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$path = rawurldecode(is_string($rawPath) && $rawPath !== '' ? $rawPath : '/');
$body = file_get_contents('php://input');

[$status, $payload] = $app->handle(
    $_SERVER['REQUEST_METHOD'] ?? 'GET',
    $path,
    is_string($body) ? $body : '',
    time(),
);

http_response_code($status);
header('Content-Type: application/json; charset=utf-8');
if ($status !== 204) {
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
}
