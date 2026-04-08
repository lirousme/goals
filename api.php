<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/db.php';

try {
    $pdo = getDbConnection();
    ensureGoalsTable($pdo);

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'POST') {
        $body = json_decode(file_get_contents('php://input') ?: '{}', true);
        $goal = isset($body['goal']) ? trim((string) $body['goal']) : '';
        $parentId = $body['parent_id'] ?? null;

        if ($goal === '') {
            http_response_code(422);
            echo json_encode(['error' => 'O objetivo é obrigatório.']);
            exit;
        }

        if ($parentId === '' || $parentId === 0 || $parentId === '0') {
            $parentId = null;
        }

        if ($parentId !== null) {
            $parentId = (int) $parentId;
            $checkStmt = $pdo->prepare('SELECT id FROM goals WHERE id = :id');
            $checkStmt->execute(['id' => $parentId]);
            if (!$checkStmt->fetch()) {
                http_response_code(422);
                echo json_encode(['error' => 'Objetivo pai não encontrado.']);
                exit;
            }
        }

        $insertStmt = $pdo->prepare('INSERT INTO goals (goal, parent_id) VALUES (:goal, :parent_id)');
        $insertStmt->execute([
            'goal' => $goal,
            'parent_id' => $parentId,
        ]);

        echo json_encode(['ok' => true, 'id' => (int) $pdo->lastInsertId()]);
        exit;
    }

    $parentId = isset($_GET['parent_id']) && $_GET['parent_id'] !== '' ? (int) $_GET['parent_id'] : null;

    $listSql = 'SELECT id, goal, parent_id FROM goals WHERE parent_id <=> :parent_id ORDER BY id DESC';
    $listStmt = $pdo->prepare($listSql);
    $listStmt->bindValue(':parent_id', $parentId, $parentId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $listStmt->execute();
    $goals = $listStmt->fetchAll();

    $deepestStmt = $pdo->prepare(<<<SQL
WITH RECURSIVE descendants AS (
    SELECT id, goal, parent_id, id AS root_id, 0 AS depth
    FROM goals
    UNION ALL
    SELECT g.id, g.goal, g.parent_id, d.root_id, d.depth + 1
    FROM goals g
    INNER JOIN descendants d ON g.parent_id = d.id
),
ranked AS (
    SELECT
        root_id,
        id,
        goal,
        depth,
        ROW_NUMBER() OVER (PARTITION BY root_id ORDER BY depth DESC, id DESC) AS rn
    FROM descendants
)
SELECT root_id, id AS deepest_id, goal AS deepest_goal, depth
FROM ranked
WHERE rn = 1 AND root_id = :root_id
LIMIT 1;
SQL);

    foreach ($goals as &$goalRow) {
        $deepestStmt->execute(['root_id' => (int) $goalRow['id']]);
        $deepest = $deepestStmt->fetch();
        if ($deepest) {
            $goalRow['last_goal'] = $deepest['deepest_goal'];
            $goalRow['last_goal_id'] = (int) $deepest['deepest_id'];
            $goalRow['last_goal_depth'] = (int) $deepest['depth'];
        } else {
            $goalRow['last_goal'] = $goalRow['goal'];
            $goalRow['last_goal_id'] = (int) $goalRow['id'];
            $goalRow['last_goal_depth'] = 0;
        }
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
