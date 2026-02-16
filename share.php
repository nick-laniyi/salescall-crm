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

$action = $_POST['action'] ?? '';
$leadId = (int)($_POST['lead_id'] ?? 0);
$targetUserId = (int)($_POST['user_id'] ?? 0);
$permission = $_POST['permission'] ?? 'view';

// Validate permission
if (!in_array($permission, ['view', 'edit'])) {
    $permission = 'view';
}

// Check if current user owns the lead
if (!canDeleteLead($pdo, $leadId, $_SESSION['user_id'])) { // reuse canDelete as only owner can share
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Only the owner can share this lead']);
    exit;
}

// Get list of all users except current (for dropdown)
if ($action === 'get_users') {
    $stmt = $pdo->prepare("SELECT id, name, email FROM users WHERE id != ? ORDER BY name");
    $stmt->execute([$_SESSION['user_id']]);
    $users = $stmt->fetchAll();
    echo json_encode(['success' => true, 'users' => $users]);
    exit;
}

// Add share
if ($action === 'add') {
    if (!$leadId || !$targetUserId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Missing parameters']);
        exit;
    }

    // Check if share already exists
    $stmt = $pdo->prepare("SELECT id FROM lead_shares WHERE lead_id = ? AND user_id = ?");
    $stmt->execute([$leadId, $targetUserId]);
    if ($stmt->fetch()) {
        // Update permission instead
        $stmt = $pdo->prepare("UPDATE lead_shares SET permission = ? WHERE lead_id = ? AND user_id = ?");
        $stmt->execute([$permission, $leadId, $targetUserId]);
    } else {
        // Insert new share
        $stmt = $pdo->prepare("INSERT INTO lead_shares (lead_id, user_id, permission, shared_by) VALUES (?, ?, ?, ?)");
        $stmt->execute([$leadId, $targetUserId, $permission, $_SESSION['user_id']]);
    }
    echo json_encode(['success' => true]);
    exit;
}

// Remove share
if ($action === 'remove') {
    if (!$leadId || !$targetUserId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Missing parameters']);
        exit;
    }
    $stmt = $pdo->prepare("DELETE FROM lead_shares WHERE lead_id = ? AND user_id = ?");
    $stmt->execute([$leadId, $targetUserId]);
    echo json_encode(['success' => true]);
    exit;
}

// List shares for a lead
if ($action === 'list') {
    if (!$leadId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Missing lead ID']);
        exit;
    }
    $stmt = $pdo->prepare("SELECT ls.*, u.name, u.email 
                           FROM lead_shares ls 
                           JOIN users u ON ls.user_id = u.id 
                           WHERE ls.lead_id = ?");
    $stmt->execute([$leadId]);
    $shares = $stmt->fetchAll();
    echo json_encode(['success' => true, 'shares' => $shares]);
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'error' => 'Invalid action']);