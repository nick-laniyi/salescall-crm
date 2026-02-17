<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
// Check if user is admin
$isAdmin = isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Sales Calls CRM</title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
    <nav class="navbar">
        <div class="container">
            <a href="dashboard.php" class="navbar-brand">Sales Calls CRM</a>
            <div class="navbar-menu">
                <a href="dashboard.php">Dashboard</a>
                <a href="leads.php">Leads</a>
                <a href="analytics.php">Analytics</a>
                <?php if ($isAdmin): ?>
                    <a href="admin/users.php">User Management</a>
                    <a href="admin/team.php">Team Dashboard</a>
                <?php endif; ?>
                <a href="profile.php">Profile</a>
                <a href="logout.php">Logout</a>
                <button id="darkModeToggle" class="dark-mode-toggle" title="Toggle dark mode">🌓</button>
            </div>
        </div>
    </nav>
    <main class="container">

<script>
// Dark mode initialization
(function() {
    try {
        const storedTheme = localStorage.getItem('theme');
        const systemPrefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
        
        if (storedTheme === 'dark' || (!storedTheme && systemPrefersDark)) {
            document.body.classList.add('dark-mode');
        }
    } catch (e) {
        console.error('Dark mode init error:', e);
    }
})();

document.addEventListener('DOMContentLoaded', function() {
    const toggle = document.getElementById('darkModeToggle');
    if (toggle) {
        toggle.addEventListener('click', function() {
            document.body.classList.toggle('dark-mode');
            const isDark = document.body.classList.contains('dark-mode');
            try {
                localStorage.setItem('theme', isDark ? 'dark' : 'light');
            } catch (e) {}
        });
    }
});
</script>