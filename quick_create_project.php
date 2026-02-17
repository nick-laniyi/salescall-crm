<?php
require_once 'includes/auth.php';
require_once 'includes/config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$name = trim($_POST['name'] ?? '');
$description = trim($_POST['description'] ?? '');
$col_names = $_POST['col_name'] ?? [];
$col_types = $_POST['col_type'] ?? [];
$col_options = $_POST['col_options'] ?? [];

if (empty($name)) {
    echo json_encode(['success' => false, 'error' => 'Project name required']);
    exit;
}

if (empty($col_names)) {
    echo json_encode(['success' => false, 'error' => 'At least one column required']);
    exit;
}

// Start transaction
$pdo->beginTransaction();

try {
    // Insert project
    $stmt = $pdo->prepare("INSERT INTO projects (user_id, name, description) VALUES (?, ?, ?)");
    $stmt->execute([$_SESSION['user_id'], $name, $description]);
    $project_id = $pdo->lastInsertId();
    
    // Insert columns
    $colStmt = $pdo->prepare("INSERT INTO project_columns (project_id, name, column_type, options, sort_order) VALUES (?, ?, ?, ?, ?)");
    $order = 0;
    foreach ($col_names as $i => $col_name) {
        if (empty(trim($col_name))) continue;
        $type = $col_types[$i] ?? 'text';
        $options = null;
        if ($type === 'select' && !empty($col_options[$i])) {
            $options = json_encode(array_map('trim', explode("\n", trim($col_options[$i]))));
        }
        $colStmt->execute([$project_id, trim($col_name), $type, $options, $order++]);
    }
    
    $pdo->commit();
    
    echo json_encode([
        'success' => true,
        'project_id' => $project_id,
        'project_name' => $name
    ]);
} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}