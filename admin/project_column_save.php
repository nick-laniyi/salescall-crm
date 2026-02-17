<?php
require_once '../includes/auth.php';
require_once '../includes/config.php';
requireAdmin();

$project_id = (int)($_POST['project_id'] ?? 0);
$id = (int)($_POST['id'] ?? 0);
$name = trim($_POST['name'] ?? '');
$type = $_POST['type'] ?? 'text';
$sort_order = (int)($_POST['sort_order'] ?? 0);
$options_raw = $_POST['options'] ?? '';

// Verify project ownership
$stmt = $pdo->prepare("SELECT id FROM projects WHERE id = ? AND user_id = ?");
$stmt->execute([$project_id, $_SESSION['user_id']]);
if (!$stmt->fetch()) {
    die('Project not found.');
}

$error = '';

if (empty($name)) {
    $error = "Column name is required.";
} elseif ($type === 'select' && empty($options_raw)) {
    $error = "Options are required for dropdown fields.";
} else {
    $options_json = $type === 'select' ? json_encode(array_map('trim', explode("\n", trim($options_raw)))) : null;
    
    if ($id > 0) {
        $stmt = $pdo->prepare("UPDATE project_columns SET name = ?, column_type = ?, options = ?, sort_order = ? WHERE id = ? AND project_id = ?");
        $stmt->execute([$name, $type, $options_json, $sort_order, $id, $project_id]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO project_columns (project_id, name, column_type, options, sort_order) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$project_id, $name, $type, $options_json, $sort_order]);
    }
    header("Location: project_columns.php?project_id=$project_id");
    exit;
}

// If error, go back to form with error message (simplified: redirect with message in session, but we'll just die for now)
if ($error) {
    die($error);
}