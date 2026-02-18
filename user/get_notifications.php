<?php
require_once '../includes/auth.php';
require_once '../includes/config.php';

header('Content-Type: application/json');

// Return empty notifications for now
echo json_encode([
    'notifications' => [],
    'unread_count' => 0
]);