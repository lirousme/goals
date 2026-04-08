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

function getNextChildSortOrder(PDO $pdo, int $parentId): int
{
    $stmt = $pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM goal_links WHERE parent_id = :parent_id');
    $stmt->execute(['parent_id' => $parentId]);

    return (int) $stmt->fetchColumn();
}

function getNextRootSortOrder(PDO $pdo): int
{
    $stmt = $pdo->query('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM goal_root_order');

    return (int) $stmt->fetchColumn();
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

            $nextSortOrder = getNextChildSortOrder($pdo, $parentId);
            $linkStmt = $pdo->prepare('INSERT IGNORE INTO goal_links (parent_id, child_id, sort_order) VALUES (:parent_id, :child_id, :sort_order)');
            $linkStmt->execute([
                'parent_id' => $parentId,
                'child_id' => $childId,
                'sort_order' => $nextSortOrder,
            ]);

            echo json_encode(['ok' => true, 'parent_id' => $parentId, 'child_id' => $childId]);
            exit;
        }

        if ($action === 'pin_home' || $action === 'unpin_home') {
            $goalId = isset($body['goal_id']) ? (int) $body['goal_id'] : 0;

            if ($goalId <= 0) {
                jsonError('ID inválido para destacar na página inicial.');
            }

            if (!goalExists($pdo, $goalId)) {
                jsonError('Objetivo não encontrado.', 404);
            }

            if ($action === 'pin_home') {
                $pinStmt = $pdo->prepare('INSERT IGNORE INTO goal_home_pins (goal_id) VALUES (:goal_id)');
                $pinStmt->execute(['goal_id' => $goalId]);
            } else {
                $unpinStmt = $pdo->prepare('DELETE FROM goal_home_pins WHERE goal_id = :goal_id');
                $unpinStmt->execute(['goal_id' => $goalId]);
            }

            echo json_encode(['ok' => true, 'goal_id' => $goalId, 'action' => $action]);
            exit;
        }

        if ($action === 'reorder') {
            $parentIdRaw = $body['parent_id'] ?? null;
            $orderedIdsRaw = $body['ordered_ids'] ?? null;
            $parentId = ($parentIdRaw === null || $parentIdRaw === '' || $parentIdRaw === 0 || $parentIdRaw === '0')
                ? null
                : (int) $parentIdRaw;

            if (!is_array($orderedIdsRaw) || count($orderedIdsRaw) === 0) {
                jsonError('A nova ordem de objetivos é obrigatória.');
            }

            $orderedIds = array_values(array_unique(array_map('intval', $orderedIdsRaw)));
            if ($parentId !== null && !goalExists($pdo, $parentId)) {
                jsonError('Objetivo pai não encontrado.', 404);
            }

            $pdo->beginTransaction();
            try {
                if ($parentId === null) {
                    $existingStmt = $pdo->query(<<<SQL
SELECT DISTINCT g.id
FROM goals g
LEFT JOIN goal_links gl ON gl.child_id = g.id
LEFT JOIN goal_home_pins ghp ON ghp.goal_id = g.id
WHERE gl.child_id IS NULL OR ghp.goal_id IS NOT NULL
SQL);
                    $existingIds = array_map('intval', array_column($existingStmt->fetchAll(), 'id'));
                    sort($existingIds);
                    $incomingIds = $orderedIds;
                    sort($incomingIds);
                    if ($existingIds !== $incomingIds) {
                        throw new RuntimeException('A lista enviada para reordenação da raiz está inconsistente.');
                    }

                    $upsertStmt = $pdo->prepare(<<<SQL
INSERT INTO goal_root_order (goal_id, sort_order)
VALUES (:goal_id, :sort_order)
ON DUPLICATE KEY UPDATE sort_order = VALUES(sort_order)
SQL);
                    foreach ($orderedIds as $index => $goalId) {
                        $upsertStmt->execute([
                            'goal_id' => $goalId,
                            'sort_order' => $index + 1,
                        ]);
                    }
                } else {
                    $existingStmt = $pdo->prepare('SELECT child_id FROM goal_links WHERE parent_id = :parent_id');
                    $existingStmt->execute(['parent_id' => $parentId]);
                    $existingIds = array_map('intval', array_column($existingStmt->fetchAll(), 'child_id'));
                    sort($existingIds);
                    $incomingIds = $orderedIds;
                    sort($incomingIds);
                    if ($existingIds !== $incomingIds) {
                        throw new RuntimeException('A lista enviada para reordenação de filhos está inconsistente.');
                    }

                    $updateStmt = $pdo->prepare(<<<SQL
UPDATE goal_links
SET sort_order = :sort_order
WHERE parent_id = :parent_id AND child_id = :child_id
SQL);
                    foreach ($orderedIds as $index => $childId) {
                        $updateStmt->execute([
                            'sort_order' => $index + 1,
                            'parent_id' => $parentId,
                            'child_id' => $childId,
                        ]);
                    }
                }

                $pdo->commit();
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $exception;
            }

            echo json_encode(['ok' => true, 'parent_id' => $parentId, 'ordered_ids' => $orderedIds]);
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
            $linkStmt = $pdo->prepare('INSERT INTO goal_links (parent_id, child_id, sort_order) VALUES (:parent_id, :child_id, :sort_order)');
            $linkStmt->execute([
                'parent_id' => $parentId,
                'child_id' => $newId,
                'sort_order' => getNextChildSortOrder($pdo, $parentId),
            ]);
        } else {
            $rootOrderStmt = $pdo->prepare('INSERT INTO goal_root_order (goal_id, sort_order) VALUES (:goal_id, :sort_order)');
            $rootOrderStmt->execute([
                'goal_id' => $newId,
                'sort_order' => getNextRootSortOrder($pdo),
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
SELECT DISTINCT g.id, g.goal, CASE WHEN ghp.goal_id IS NULL THEN 0 ELSE 1 END AS is_pinned_home
FROM goals g
LEFT JOIN goal_links gl ON gl.child_id = g.id
LEFT JOIN goal_home_pins ghp ON ghp.goal_id = g.id
LEFT JOIN goal_root_order gro ON gro.goal_id = g.id
WHERE gl.child_id IS NULL OR ghp.goal_id IS NOT NULL
ORDER BY COALESCE(gro.sort_order, 999999), g.id DESC;
SQL;
        $listStmt = $pdo->query($listSql);
    } else {
        $listSql = <<<SQL
SELECT g.id, g.goal, CASE WHEN ghp.goal_id IS NULL THEN 0 ELSE 1 END AS is_pinned_home
FROM goal_links gl
INNER JOIN goals g ON g.id = gl.child_id
LEFT JOIN goal_home_pins ghp ON ghp.goal_id = g.id
WHERE gl.parent_id = :parent_id
ORDER BY gl.sort_order ASC, g.id DESC;
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
        CONCAT(w.path, ',', LPAD(gl.sort_order, 10, '0'), ':', c.id) AS path
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
        ROW_NUMBER() OVER (
            PARTITION BY root_id
            ORDER BY path DESC, depth DESC, id DESC
        ) AS rn
    FROM walk
)
SELECT root_id, id AS deepest_id, goal AS deepest_goal, depth
FROM ranked
WHERE rn = 1
LIMIT 1;
SQL);

    $directChildrenStmt = $pdo->prepare('SELECT child_id FROM goal_links WHERE parent_id = :parent_id ORDER BY sort_order ASC, child_id DESC');

    foreach ($goals as &$goalRow) {
        $goalId = (int) $goalRow['id'];
        $goalRow['is_pinned_home'] = (bool) ((int) ($goalRow['is_pinned_home'] ?? 0));
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
