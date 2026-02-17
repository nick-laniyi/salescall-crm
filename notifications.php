<?php
require_once 'includes/auth.php';
require_once 'includes/config.php';

// Mark single as read if clicked
if (isset($_GET['read']) && $_GET['read']) {
    $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
    $stmt->execute([$_GET['read'], $_SESSION['user_id']]);
    header('Location: notifications.php');
    exit;
}

// Mark all as read
if (isset($_GET['read_all'])) {
    $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    header('Location: notifications.php');
    exit;
}

// Fetch all notifications
$stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC");
$stmt->execute([$_SESSION['user_id']]);
$notifications = $stmt->fetchAll();

include 'includes/header.php';
?>

<h1>Notifications</h1>

<div class="card">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <h2>All Notifications</h2>
        <a href="?read_all=1" class="btn-secondary" onclick="return confirm('Mark all as read?')">Mark All as Read</a>
    </div>

    <?php if (count($notifications) > 0): ?>
        <div class="table-container">
            <table class="table">
                <thead>
                    <tr>
                        <th>Status</th>
                        <th>Message</th>
                        <th>Time</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($notifications as $n): ?>
                    <tr>
                        <td><?= $n['is_read'] ? 'Read' : 'Unread' ?></td>
                        <td><?= htmlspecialchars($n['message']) ?></td>
                        <td><?= date('M j, Y g:i a', strtotime($n['created_at'])) ?></td>
                        <td>
                            <?php if (!$n['is_read']): ?>
                                <a href="?read=<?= $n['id'] ?>" class="btn-secondary btn-small">Mark Read</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <p>No notifications.</p>
    <?php endif; ?>
</div>

<?php include 'includes/footer.php'; ?>