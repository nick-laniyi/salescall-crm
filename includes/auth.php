<?php
session_start();

// Redirect to login if not authenticated
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

// Get current user data
$stmt = $pdo->prepare("SELECT id, name, email, role FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$currentUser = $stmt->fetch();

if (!$currentUser) {
    // Invalid session
    session_destroy();
    header('Location: login.php');
    exit;
}

// Store role in session for easy access
$_SESSION['user_role'] = $currentUser['role'];

// Check if user is admin
function isAdmin() {
    return isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin';
}

// Redirect if not admin
function requireAdmin() {
    if (!isAdmin()) {
        header('HTTP/1.0 403 Forbidden');
        die('Access denied. Admin privileges required.');
    }
}

// For lead permissions: admin can do anything, users limited
function canEditLead($pdo, $leadId, $userId) {
    if (isAdmin()) return true;
    // User can edit if owner or has edit share
    return userCanEditLead($pdo, $leadId, $userId); // from functions.php
}

function canDeleteLead($pdo, $leadId, $userId) {
    if (isAdmin()) return true;
    // User can delete only if owner
    $stmt = $pdo->prepare("SELECT id FROM leads WHERE id = ? AND user_id = ?");
    $stmt->execute([$leadId, $userId]);
    return $stmt->fetch() !== false;
}

function canViewLead($pdo, $leadId, $userId) {
    if (isAdmin()) return true;
    // User can view if owner or shared
    return userCanViewLead($pdo, $leadId, $userId); // from functions.php
}