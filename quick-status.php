<?php
require_once 'includes/auth.php';
require_once 'includes/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    $lead_id = $_POST['lead_id'] ?? null;
    $field = $_POST['field'] ?? null;
    $value = $_POST['value'] ?? null;
} else {
    $lead_id = $input['lead_id'] ?? null;
    $field = $input['field'] ?? null;
    $value = $input['value'] ?? null;
}

if (!$lead_id || !$field || !isset($value)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing parameters']);
    exit;
}

// Special handling for delete
if ($field === 'delete') {
    $stmt = $pdo->prepare("DELETE FROM leads WHERE id = ? AND user_id = ?");
    if ($stmt->execute([$lead_id, $_SESSION['user_id']])) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Delete failed']);
    }
    exit;
}

$allowedFields = ['status', 'notes', 'company', 'phone', 'email'];
if (!in_array($field, $allowedFields)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid field']);
    exit;
}

if ($field === 'status') {
    $validStatuses = ['new', 'contacted', 'interested', 'not_interested', 'converted'];
    if (!in_array($value, $validStatuses)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid status value']);
        exit;
    }
}

try {
    $sql = "UPDATE leads SET $field = :value WHERE id = :id AND user_id = :user_id";
    $stmt = $pdo->prepare($sql);
    $success = $stmt->execute([
        ':value' => $value,
        ':id' => $lead_id,
        ':user_id' => $_SESSION['user_id']
    ]);

    if ($success && $stmt->rowCount() > 0) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'No changes made or lead not found']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}