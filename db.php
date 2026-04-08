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
    $goalsSql = <<<SQL
CREATE TABLE IF NOT EXISTS goals (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    goal VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;

    $linksSql = <<<SQL
CREATE TABLE IF NOT EXISTS goal_links (
    parent_id INT UNSIGNED NOT NULL,
    child_id INT UNSIGNED NOT NULL,
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (parent_id, child_id),
    INDEX idx_goal_links_child (child_id),
    CONSTRAINT fk_goal_links_parent FOREIGN KEY (parent_id)
        REFERENCES goals(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    CONSTRAINT fk_goal_links_child FOREIGN KEY (child_id)
        REFERENCES goals(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;

    $rootOrderSql = <<<SQL
CREATE TABLE IF NOT EXISTS goal_root_order (
    goal_id INT UNSIGNED NOT NULL PRIMARY KEY,
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_goal_root_order_goal FOREIGN KEY (goal_id)
        REFERENCES goals(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;

    $homePinsSql = <<<SQL
CREATE TABLE IF NOT EXISTS goal_home_pins (
    goal_id INT UNSIGNED NOT NULL PRIMARY KEY,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_goal_home_pins_goal FOREIGN KEY (goal_id)
        REFERENCES goals(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;

    $pdo->exec($goalsSql);
    $pdo->exec($linksSql);
    $pdo->exec($rootOrderSql);
    $pdo->exec($homePinsSql);

    $hasSortOrderOnLinksStmt = $pdo->query("SHOW COLUMNS FROM goal_links LIKE 'sort_order'");
    $hasSortOrderOnLinks = (bool) $hasSortOrderOnLinksStmt->fetch();
    if (!$hasSortOrderOnLinks) {
        $pdo->exec('ALTER TABLE goal_links ADD COLUMN sort_order INT UNSIGNED NOT NULL DEFAULT 0 AFTER child_id');
    }

    $hasRootOrderTableStmt = $pdo->query("SHOW TABLES LIKE 'goal_root_order'");
    $hasRootOrderTable = (bool) $hasRootOrderTableStmt->fetch();
    if (!$hasRootOrderTable) {
        $pdo->exec($rootOrderSql);
    }

    $pdo->exec(<<<SQL
UPDATE goal_links gl
INNER JOIN (
    SELECT parent_id, child_id, ROW_NUMBER() OVER (PARTITION BY parent_id ORDER BY child_id DESC) AS new_sort_order
    FROM goal_links
) ranked ON ranked.parent_id = gl.parent_id AND ranked.child_id = gl.child_id
SET gl.sort_order = ranked.new_sort_order
WHERE gl.sort_order = 0;
SQL);

    $pdo->exec(<<<SQL
INSERT INTO goal_root_order (goal_id, sort_order)
SELECT ranked.id, ranked.new_sort_order
FROM (
    SELECT
        g.id,
        ROW_NUMBER() OVER (ORDER BY g.id DESC) AS new_sort_order
    FROM goals g
    LEFT JOIN goal_links gl ON gl.child_id = g.id
    WHERE gl.child_id IS NULL
) ranked
ON DUPLICATE KEY UPDATE sort_order = goal_root_order.sort_order;
SQL);

    // Migração automática de versões antigas (goals.parent_id -> goal_links).
    $legacyColumnStmt = $pdo->query("SHOW COLUMNS FROM goals LIKE 'parent_id'");
    $hasLegacyParent = (bool) $legacyColumnStmt->fetch();

    if ($hasLegacyParent) {
        $pdo->exec(<<<SQL
INSERT IGNORE INTO goal_links (parent_id, child_id)
SELECT parent_id, id
FROM goals
WHERE parent_id IS NOT NULL;
SQL);
        $pdo->exec('ALTER TABLE goals DROP FOREIGN KEY fk_goals_parent');
        $pdo->exec('ALTER TABLE goals DROP INDEX idx_parent_id');
        $pdo->exec('ALTER TABLE goals DROP COLUMN parent_id');
    }
}
