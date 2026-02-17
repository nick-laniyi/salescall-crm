<?php
require_once 'includes/auth.php';
require_once 'includes/config.php';

if (isset($_GET['all'])) {
    $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
} elseif (isset($_POST['id'])) {
    $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
    $stmt->execute([$_POST['id'], $_SESSION['user_id']]);
}
echo json_encode(['success' => true]);