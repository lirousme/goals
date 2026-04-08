<?php

declare(strict_types=1);

function loadEnvFile(string $path): array
{
    $vars = [];

    if (!file_exists($path)) {
        return $vars;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return $vars;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        $parts = explode('=', $line, 2);
        if (count($parts) !== 2) {
            continue;
        }

        $key = trim($parts[0]);
        $value = trim($parts[1]);
        $vars[$key] = $value;
    }

    return $vars;
}

function getDbConnection(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $rootEnv = loadEnvFile('.env');

    $host = $rootEnv['DB_HOST'] ?? getenv('DB_HOST') ?: '127.0.0.1';
    $name = $rootEnv['DB_NAME'] ?? getenv('DB_NAME') ?: '';
    $user = $rootEnv['DB_USER'] ?? getenv('DB_USER') ?: '';
    $pass = $rootEnv['DB_PASS'] ?? getenv('DB_PASS') ?: '';

    if ($name === '' || $user === '') {
        throw new RuntimeException('Credenciais MySQL ausentes. Defina DB_HOST, DB_NAME, DB_USER e DB_PASS em /.env.');
    }

    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $host, $name);

    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    return $pdo;
}

function ensureGoalsTable(PDO $pdo): void
{
    $sql = <<<SQL
CREATE TABLE IF NOT EXISTS goals (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    goal VARCHAR(255) NOT NULL,
    parent_id INT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_parent_id (parent_id),
    CONSTRAINT fk_goals_parent FOREIGN KEY (parent_id)
        REFERENCES goals(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;

    $pdo->exec($sql);
}
