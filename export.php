<?php
require_once 'includes/auth.php';
require_once 'includes/config.php';
require_once 'includes/functions.php';

// Determine which leads to export
if (isAdmin() && isset($_GET['user_id'])) {
    // Admin exporting leads for a specific user
    $userId = (int)$_GET['user_id'];
    $accessibleIds = getAccessibleLeadIds($pdo, $userId, false); // that user's accessible leads
} elseif (isAdmin()) {
    // Admin exporting all leads
    $accessibleIds = getAccessibleLeadIds($pdo, $_SESSION['user_id'], true); // all leads
} else {
    // Regular user exports own/shared
    $accessibleIds = getAccessibleLeadIds($pdo, $_SESSION['user_id']);
}

if (empty($accessibleIds)) {
    header('Content-Type: text/plain');
    echo "No leads to export.";
    exit;
}

$placeholders = implode(',', array_fill(0, count($accessibleIds), '?'));

// Fetch leads with additional info
$sql = "SELECT l.*, u.name as owner_name,
               (SELECT outcome FROM calls WHERE lead_id = l.id ORDER BY created_at DESC LIMIT 1) as last_call_outcome,
               (SELECT created_at FROM calls WHERE lead_id = l.id ORDER BY created_at DESC LIMIT 1) as last_call_date,
               (SELECT COUNT(*) FROM calls WHERE lead_id = l.id) as total_calls
        FROM leads l
        LEFT JOIN users u ON l.user_id = u.id
        WHERE l.id IN ($placeholders)
        ORDER BY l.created_at DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($accessibleIds);
$leads = $stmt->fetchAll();

// Set headers for CSV download
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="leads_export_' . date('Y-m-d') . '.csv"');

$output = fopen('php://output', 'w');

// Add UTF-8 BOM for Excel compatibility
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// Headers
$headers = [
    'ID',
    'Owner',
    'Name',
    'Company',
    'Phone',
    'Email',
    'Status',
    'Notes',
    'Created At',
    'Last Call Date',
    'Last Call Outcome',
    'Total Calls'
];
fputcsv($output, $headers);

foreach ($leads as $lead) {
    fputcsv($output, [
        $lead['id'],
        $lead['owner_name'],
        $lead['name'],
        $lead['company'],
        $lead['phone'],
        $lead['email'],
        $lead['status'],
        $lead['notes'],
        $lead['created_at'],
        $lead['last_call_date'],
        $lead['last_call_outcome'],
        $lead['total_calls']
    ]);
}

fclose($output);