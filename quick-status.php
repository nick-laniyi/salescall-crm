<?php
require_once 'includes/auth.php';
require_once 'includes/config.php';
require_once 'includes/functions.php';

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// Get input
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

// Validate
if (!$lead_id || !$field || !isset($value)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing parameters']);
    exit;
}

// Special handling for delete
if ($field === 'delete') {
    if (!canDeleteLead($pdo, $lead_id, $_SESSION['user_id'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Permission denied']);
        exit;
    }
    $stmt = $pdo->prepare("DELETE FROM leads WHERE id = ?");
    if ($stmt->execute([$lead_id])) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Delete failed']);
    }
    exit;
}

// Handle owner change (admin only)
if ($field === 'owner') {
    if (!isAdmin()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Only admin can change owner']);
        exit;
    }
    // Validate new owner exists
    $stmt = $pdo->prepare("SELECT id FROM users WHERE id = ?");
    $stmt->execute([$value]);
    if (!$stmt->fetch()) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid user']);
        exit;
    }
    $stmt = $pdo->prepare("UPDATE leads SET user_id = ? WHERE id = ?");
    if ($stmt->execute([$value, $lead_id])) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Update failed']);
    }
    exit;
}

// For regular updates, check edit permission
if (!canEditLead($pdo, $lead_id, $_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'You do not have edit permission']);
    exit;
}

$allowedFields = ['status', 'notes', 'company', 'phone', 'email'];
if (!in_array($field, $allowedFields)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid field']);
    exit;
}

// Validate status
if ($field === 'status') {
    $validStatuses = ['new', 'contacted', 'interested', 'not_interested', 'converted'];
    if (!in_array($value, $validStatuses)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid status value']);
        exit;
    }
}

// Update database
try {
    $sql = "UPDATE leads SET $field = :value WHERE id = :id";
    $stmt = $pdo->prepare($sql);
    $success = $stmt->execute([
        ':value' => $value,
        ':id' => $lead_id
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