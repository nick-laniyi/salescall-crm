<?php
require_once 'includes/auth.php';
require_once 'includes/config.php';

$project_id = isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0;

if (!$project_id) {
    echo json_encode([]);
    exit;
}

// Check if user has access to this project (owner or shared? For now, only owner)
$stmt = $pdo->prepare("SELECT id FROM projects WHERE id = ? AND user_id = ?");
$stmt->execute([$project_id, $_SESSION['user_id']]);
if (!$stmt->fetch()) {
    // Not owner, but maybe shared? For simplicity, deny
    echo json_encode([]);
    exit;
}

$stmt = $pdo->prepare("SELECT id, name, column_type, options FROM project_columns WHERE project_id = ? ORDER BY sort_order, id");
$stmt->execute([$project_id]);
$columns = $stmt->fetchAll();

// For select options, decode JSON
foreach ($columns as &$col) {
    if ($col['column_type'] === 'select' && $col['options']) {
        $col['options'] = json_decode($col['options'], true);
    }
}

echo json_encode($columns);