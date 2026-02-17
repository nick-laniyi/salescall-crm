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
            <a href="/dashboard.php" class="navbar-brand">Sales Calls CRM</a>
            <div class="navbar-menu">
                <a href="/dashboard.php">Dashboard</a>
                <a href="/leads.php">Leads</a>
                <a href="analytics.php">Analytics</a>
                <?php if ($isAdmin): ?>
                    <a href="/admin/users.php">User Management</a>
                    <a href="admin/custom_fields.php">Custom Fields</a>
                    <a href="/admin/team.php">Team Dashboard</a>
                <?php endif; ?>
                <a href="/profile.php">Profile</a>
                <a href="/logout.php">Logout</a>
                <button id="darkModeToggle" class="dark-mode-toggle" title="Toggle dark mode">🌓</button>
            </div>

            <div class="notification-dropdown">
    <button id="notificationBell" class="notification-bell">
        🔔
        <span id="notificationCount" class="notification-count" style="display: none;">0</span>
    </button>
    <div id="notificationList" class="notification-list">
        <div class="notification-header">
            <h4>Notifications</h4>
            <a href="#" id="markAllRead">Mark all read</a>
        </div>
        <div id="notificationItems">
            <!-- Loaded via AJAX -->
            <div class="notification-loading">Loading...</div>
        </div>
        <div class="notification-footer">
            <a href="/notifications.php">View all</a>
        </div>
    </div>
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

// Load unread notifications count
function loadNotificationCount() {
    fetch('get_notifications.php?count=1')
        .then(response => response.json())
        .then(data => {
            const countSpan = document.getElementById('notificationCount');
            if (data.count > 0) {
                countSpan.textContent = data.count;
                countSpan.style.display = 'inline';
            } else {
                countSpan.style.display = 'none';
            }
        });
}

// Load notification list dropdown
function loadNotifications() {
    fetch('get_notifications.php?limit=5')
        .then(response => response.json())
        .then(data => {
            const container = document.getElementById('notificationItems');
            if (data.notifications && data.notifications.length > 0) {
                let html = '';
                data.notifications.forEach(n => {
                    html += `
                        <div class="notification-item ${n.is_read ? '' : 'unread'}">
                            <div class="notification-message">${n.message}</div>
                            <div class="notification-time">${n.time_ago}</div>
                        </div>
                    `;
                });
                container.innerHTML = html;
            } else {
                container.innerHTML = '<div class="notification-item">No notifications</div>';
            }
        });
}

// Mark all as read
document.getElementById('markAllRead').addEventListener('click', function(e) {
    e.preventDefault();
    fetch('mark_notifications.php?all=1', { method: 'POST' })
        .then(() => {
            loadNotificationCount();
            loadNotifications();
        });
});

// Load on page load
document.addEventListener('DOMContentLoaded', function() {
    loadNotificationCount();
    loadNotifications();
    
    // Refresh count every 60 seconds
    setInterval(loadNotificationCount, 60000);
});
</script>