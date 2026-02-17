<?php
require_once 'includes/auth.php';
require_once 'includes/config.php';

$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 0;
$countOnly = isset($_GET['count']);

if ($countOnly) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $stmt->execute([$_SESSION['user_id']]);
    echo json_encode(['count' => $stmt->fetchColumn()]);
    exit;
}

// Fetch notifications
$sql = "SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC";
if ($limit > 0) {
    $sql .= " LIMIT $limit";
}
$stmt = $pdo->prepare($sql);
$stmt->execute([$_SESSION['user_id']]);
$notifications = $stmt->fetchAll();

// Format time ago
foreach ($notifications as &$n) {
    $time = strtotime($n['created_at']);
    $diff = time() - $time;
    if ($diff < 60) {
        $n['time_ago'] = 'Just now';
    } elseif ($diff < 3600) {
        $n['time_ago'] = floor($diff / 60) . ' minutes ago';
    } elseif ($diff < 86400) {
        $n['time_ago'] = floor($diff / 3600) . ' hours ago';
    } else {
        $n['time_ago'] = date('M j', $time);
    }
}

echo json_encode(['notifications' => $notifications]);