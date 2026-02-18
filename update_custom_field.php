<?php
require_once 'includes/auth.php';
require_once 'includes/config.php';
require_once 'includes/functions.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$lead_id = $input['lead_id'] ?? null;
$column_id = $input['column_id'] ?? null;
$value = $input['value'] ?? null;

if (!$lead_id || !$column_id || !isset($value)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing parameters']);
    exit;
}

// Check if user can edit this lead
if (!canEditLead($pdo, $lead_id, $_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Permission denied']);
    exit;
}

// Verify column belongs to the lead's project
$stmt = $pdo->prepare("SELECT l.project_id, pc.id FROM leads l 
                       JOIN project_columns pc ON pc.project_id = l.project_id 
                       WHERE l.id = ? AND pc.id = ?");
$stmt->execute([$lead_id, $column_id]);
if (!$stmt->fetch()) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Column does not belong to this lead\'s project']);
    exit;
}

// Save or delete
if (trim($value) === '') {
    $stmt = $pdo->prepare("DELETE FROM lead_column_values WHERE lead_id = ? AND column_id = ?");
    $stmt->execute([$lead_id, $column_id]);
} else {
    $stmt = $pdo->prepare("INSERT INTO lead_column_values (lead_id, column_id, value) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)");
    $stmt->execute([$lead_id, $column_id, $value]);
}

echo json_encode(['success' => true]);