<?php
declare(strict_types=1);

function app_config(string $rootDir): array
{
    $configFile = $rootDir . DIRECTORY_SEPARATOR . 'config.php';
    if (!is_file($configFile)) {
        throw new RuntimeException('Missing config.php');
    }
    $config = require $configFile;
    if (!is_array($config)) {
        throw new RuntimeException('Invalid config.php');
    }
    return $config;
}

function json_response(int $status, array $payload): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function parse_json_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}
