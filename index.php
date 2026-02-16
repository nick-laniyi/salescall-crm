<?php
// index.php - Landing page / entry point
session_start();

// If user is already logged in, send them to dashboard
if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

// Otherwise, redirect to login page
header('Location: login.php');
exit;