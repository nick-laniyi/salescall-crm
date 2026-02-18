<?php
require_once 'includes/auth.php';
require_once 'includes/config.php';
require_once 'includes/functions.php';

header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true);
$lead_id = (int)($data['lead_id'] ?? 0);
$outcome = $data['outcome'] ?? '';
$duration = (int)($data['duration'] ?? 0);
$follow_up = $data['follow_up_date'] ?? null;
$notes = trim($data['notes'] ?? '');

if (!$lead_id || !$outcome) {
    echo json_encode(['success' => false, 'error' => 'Missing lead_id or outcome']);
    exit;
}

// Verify access
if (!canViewLead($pdo, $lead_id, $_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Access denied']);
    exit;
}

// Insert call record
$stmt = $pdo->prepare("INSERT INTO calls (lead_id, user_id, outcome, duration, follow_up_date, notes) VALUES (?, ?, ?, ?, ?, ?)");
if (!$stmt->execute([$lead_id, $_SESSION['user_id'], $outcome, $duration, $follow_up ?: null, $notes])) {
    echo json_encode(['success' => false, 'error' => 'Failed to log call']);
    exit;
}

// Update last_contacted to today
$update = $pdo->prepare("UPDATE leads SET last_contacted = CURDATE() WHERE id = ?");
$update->execute([$lead_id]);

// Map call outcome to lead status
$statusMap = [
    'interested' => 'interested',
    'not_interested' => 'not_interested',
    'converted' => 'converted',
    'no_answer' => 'contacted',
    'left_message' => 'contacted',
    'callback' => 'contacted'
];
$newStatus = null;
if (isset($statusMap[$outcome])) {
    $newStatus = $statusMap[$outcome];
    $updateStatus = $pdo->prepare("UPDATE leads SET status = ? WHERE id = ?");
    $updateStatus->execute([$newStatus, $lead_id]);
}

echo json_encode([
    'success' => true,
    'last_contacted' => date('Y-m-d'),
    'new_status' => $newStatus
]);