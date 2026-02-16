<?php
require_once 'includes/auth.php';
require_once 'includes/config.php';
require_once 'includes/functions.php';

// Get accessible leads
list($sql, $params) = getAccessibleLeadsQuery($pdo, $_SESSION['user_id']);
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$leads = $stmt->fetchAll();

// Set headers for CSV download
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="leads_export_' . date('Y-m-d') . '.csv"');

$output = fopen('php://output', 'w');

// Add UTF-8 BOM for Excel compatibility
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// Headers
fputcsv($output, [
    'ID',
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
]);

// Get additional data for each lead
foreach ($leads as $lead) {
    // Get last call info
    $stmt2 = $pdo->prepare("SELECT outcome, created_at FROM calls WHERE lead_id = ? ORDER BY created_at DESC LIMIT 1");
    $stmt2->execute([$lead['id']]);
    $lastCall = $stmt2->fetch();
    
    // Get total calls count
    $stmt3 = $pdo->prepare("SELECT COUNT(*) FROM calls WHERE lead_id = ?");
    $stmt3->execute([$lead['id']]);
    $totalCalls = $stmt3->fetchColumn();
    
    fputcsv($output, [
        $lead['id'],
        $lead['name'],
        $lead['company'],
        $lead['phone'],
        $lead['email'],
        $lead['status'],
        $lead['notes'],
        $lead['created_at'],
        $lastCall ? $lastCall['created_at'] : '',
        $lastCall ? $lastCall['outcome'] : '',
        $totalCalls
    ]);
}

fclose($output);