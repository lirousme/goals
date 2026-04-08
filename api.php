<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/db.php';

function jsonError(string $message, int $status = 422): void
{
    http_response_code($status);
    echo json_encode(['error' => $message]);
    exit;
}

function goalExists(PDO $pdo, int $goalId): bool
{
    $stmt = $pdo->prepare('SELECT id FROM goals WHERE id = :id');
    $stmt->execute(['id' => $goalId]);

    return (bool) $stmt->fetch();
}

function wouldCreateCycle(PDO $pdo, int $parentId, int $childId): bool
{
    if ($parentId === $childId) {
        return true;
    }

    $stmt = $pdo->prepare(<<<SQL
WITH RECURSIVE descendants AS (
    SELECT child_id
    FROM goal_links
    WHERE parent_id = :child_id
    UNION ALL
    SELECT gl.child_id
    FROM goal_links gl
    INNER JOIN descendants d ON d.child_id = gl.parent_id
)
SELECT 1
FROM descendants
WHERE child_id = :parent_id
LIMIT 1;
SQL);
    $stmt->execute([
        'child_id' => $childId,
        'parent_id' => $parentId,
    ]);

    return (bool) $stmt->fetchColumn();
}

try {
    $pdo = getDbConnection();
    ensureGoalsTable($pdo);

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'POST') {
        $body = json_decode(file_get_contents('php://input') ?: '{}', true);
        $action = isset($body['action']) ? (string) $body['action'] : 'create';

        if ($action === 'adopt') {
            $parentId = isset($body['parent_id']) ? (int) $body['parent_id'] : 0;
            $childId = isset($body['child_id']) ? (int) $body['child_id'] : 0;

            if ($parentId <= 0 || $childId <= 0) {
                jsonError('IDs inválidos para adoção compartilhada.');
            }

            if (!goalExists($pdo, $parentId) || !goalExists($pdo, $childId)) {
                jsonError('Objetivo pai ou filho não encontrado.', 404);
            }

            if (wouldCreateCycle($pdo, $parentId, $childId)) {
                jsonError('Esta adoção criaria um ciclo na árvore de objetivos.');
            }

            $linkStmt = $pdo->prepare('INSERT IGNORE INTO goal_links (parent_id, child_id) VALUES (:parent_id, :child_id)');
            $linkStmt->execute([
                'parent_id' => $parentId,
                'child_id' => $childId,
            ]);

            echo json_encode(['ok' => true, 'parent_id' => $parentId, 'child_id' => $childId]);
            exit;
        }

        $goal = isset($body['goal']) ? trim((string) $body['goal']) : '';
        $parentId = $body['parent_id'] ?? null;

        if ($goal === '') {
            jsonError('O objetivo é obrigatório.');
        }

        if ($parentId === '' || $parentId === 0 || $parentId === '0') {
            $parentId = null;
        }

        if ($parentId !== null) {
            $parentId = (int) $parentId;
            if (!goalExists($pdo, $parentId)) {
                jsonError('Objetivo pai não encontrado.', 404);
            }
        }

        $insertStmt = $pdo->prepare('INSERT INTO goals (goal) VALUES (:goal)');
        $insertStmt->execute(['goal' => $goal]);
        $newId = (int) $pdo->lastInsertId();

        if ($parentId !== null) {
            $linkStmt = $pdo->prepare('INSERT INTO goal_links (parent_id, child_id) VALUES (:parent_id, :child_id)');
            $linkStmt->execute([
                'parent_id' => $parentId,
                'child_id' => $newId,
            ]);
        }

        echo json_encode(['ok' => true, 'id' => $newId]);
        exit;
    }

    if ($method === 'PUT') {
        $goalId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        $body = json_decode(file_get_contents('php://input') ?: '{}', true);
        $goal = isset($body['goal']) ? trim((string) $body['goal']) : '';

        if ($goalId <= 0) {
            jsonError('ID do objetivo inválido.');
        }

        if ($goal === '') {
            jsonError('O objetivo é obrigatório.');
        }

        $updateStmt = $pdo->prepare('UPDATE goals SET goal = :goal WHERE id = :id');
        $updateStmt->execute([
            'goal' => $goal,
            'id' => $goalId,
        ]);

        if ($updateStmt->rowCount() === 0 && !goalExists($pdo, $goalId)) {
            jsonError('Objetivo não encontrado.', 404);
        }

        echo json_encode(['ok' => true, 'id' => $goalId]);
        exit;
    }

    if ($method === 'DELETE') {
        $goalId = isset($_GET['id']) ? (int) $_GET['id'] : 0;

        if ($goalId <= 0) {
            jsonError('ID do objetivo inválido.');
        }

        $deleteStmt = $pdo->prepare('DELETE FROM goals WHERE id = :id');
        $deleteStmt->execute(['id' => $goalId]);

        if ($deleteStmt->rowCount() === 0) {
            jsonError('Objetivo não encontrado.', 404);
        }

        echo json_encode(['ok' => true]);
        exit;
    }

    $parentId = isset($_GET['parent_id']) && $_GET['parent_id'] !== '' ? (int) $_GET['parent_id'] : null;

    if ($parentId !== null && !goalExists($pdo, $parentId)) {
        jsonError('Objetivo pai não encontrado.', 404);
    }

    if ($parentId === null) {
        $listSql = <<<SQL
SELECT g.id, g.goal
FROM goals g
LEFT JOIN goal_links gl ON gl.child_id = g.id
WHERE gl.child_id IS NULL
ORDER BY g.id DESC;
SQL;
        $listStmt = $pdo->query($listSql);
    } else {
        $listSql = <<<SQL
SELECT g.id, g.goal
FROM goal_links gl
INNER JOIN goals g ON g.id = gl.child_id
WHERE gl.parent_id = :parent_id
ORDER BY g.id DESC;
SQL;
        $listStmt = $pdo->prepare($listSql);
        $listStmt->execute(['parent_id' => $parentId]);
    }

    $goals = $listStmt->fetchAll();

    $deepestStmt = $pdo->prepare(<<<SQL
WITH RECURSIVE walk AS (
    SELECT
        g.id,
        g.goal,
        g.id AS root_id,
        0 AS depth,
        CAST(g.id AS CHAR(2000)) AS path
    FROM goals g
    WHERE g.id = :root_id
    UNION ALL
    SELECT
        c.id,
        c.goal,
        w.root_id,
        w.depth + 1,
        CONCAT(w.path, ',', c.id) AS path
    FROM walk w
    INNER JOIN goal_links gl ON gl.parent_id = w.id
    INNER JOIN goals c ON c.id = gl.child_id
    WHERE FIND_IN_SET(c.id, w.path) = 0
),
ranked AS (
    SELECT
        root_id,
        id,
        goal,
        depth,
        ROW_NUMBER() OVER (PARTITION BY root_id ORDER BY depth DESC, id DESC) AS rn
    FROM walk
)
SELECT root_id, id AS deepest_id, goal AS deepest_goal, depth
FROM ranked
WHERE rn = 1
LIMIT 1;
SQL);

    $directChildrenStmt = $pdo->prepare('SELECT child_id FROM goal_links WHERE parent_id = :parent_id');

    foreach ($goals as &$goalRow) {
        $goalId = (int) $goalRow['id'];
        $deepestStmt->execute(['root_id' => $goalId]);
        $deepest = $deepestStmt->fetch();

        if ($deepest) {
            $goalRow['last_goal'] = $deepest['deepest_goal'];
            $goalRow['last_goal_id'] = (int) $deepest['deepest_id'];
            $goalRow['last_goal_depth'] = (int) $deepest['depth'];
        } else {
            $goalRow['last_goal'] = $goalRow['goal'];
            $goalRow['last_goal_id'] = $goalId;
            $goalRow['last_goal_depth'] = 0;
        }

        $directChildrenStmt->execute(['parent_id' => $goalId]);
        $goalRow['children_ids'] = array_map(
            static fn ($row): int => (int) $row['child_id'],
            $directChildrenStmt->fetchAll()
        );
    }

    echo json_encode([
        'parent_id' => $parentId,
        'items' => $goals,
    ]);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Erro ao processar requisição.',
        'details' => $exception->getMessage(),
    ]);
}
