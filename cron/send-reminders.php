#!/usr/bin/php
<?php
require_once dirname(__DIR__) . '/includes/config.php';

// Get all follow-ups due today
$stmt = $pdo->query("
    SELECT c.*, l.name as lead_name, l.user_id, u.name as user_name, u.email 
    FROM calls c
    JOIN leads l ON c.lead_id = l.id
    JOIN users u ON l.user_id = u.id
    WHERE c.follow_up_date = CURDATE()
");

$rows = $stmt->fetchAll();

foreach ($rows as $row) {
    // Send email
    $to = $row['email'];
    $subject = "Follow-up Reminder: " . $row['lead_name'];
    $message = "Hello " . $row['user_name'] . ",\n\n";
    $message .= "You have a follow-up scheduled for today with lead: " . $row['lead_name'] . ".\n";
    $message .= "Outcome of last call: " . $row['outcome'] . "\n";
    $message .= "Notes: " . $row['notes'] . "\n\n";
    $message .= "View lead: https://salescalls.naijabased.fun/lead.php?id=" . $row['lead_id'] . "\n";
    $headers = "From: reminders@naijabased.fun\r\n";
    mail($to, $subject, $message, $headers);

    // Create in-app notification
    $stmt2 = $pdo->prepare("INSERT INTO notifications (user_id, type, message, link) VALUES (?, 'reminder', ?, ?)");
    $stmt2->execute([
        $row['user_id'],
        "Follow-up reminder for lead: " . $row['lead_name'],
        "/lead.php?id=" . $row['lead_id']
    ]);
}