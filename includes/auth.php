<?php
session_start();

// Redirect to login if not authenticated
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// Optional: get current user data for later use
require_once __DIR__ . '/config.php';
$stmt = $pdo->prepare("SELECT id, name, email FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$currentUser = $stmt->fetch();

if (!$currentUser) {
    // Invalid session
    session_destroy();
    header('Location: login.php');
    exit;
}