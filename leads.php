<?php
require_once 'includes/auth.php';
require_once 'includes/config.php';

$search = $_GET['search'] ?? '';
$status = $_GET['status'] ?? '';

$sql = "SELECT * FROM leads WHERE user_id = :user_id";
$params = [':user_id' => $_SESSION['user_id']];

if ($search) {
    $sql .= " AND (name LIKE :search OR company LIKE :search OR email LIKE :search OR phone LIKE :search)";
    $params[':search'] = "%$search%";
}
if ($status && $status !== 'all') {
    $sql .= " AND status = :status";
    $params[':status'] = $status;
}
$sql .= " ORDER BY created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$leads = $stmt->fetchAll();

include 'includes/header.php';
?>

<h1>Leads</h1>

<div class="card">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <a href="lead.php?action=add" class="btn">Add New Lead</a>
        <form method="get" style="display: flex; gap: 10px;">
            <input type="text" name="search" placeholder="Search leads..." value="<?= htmlspecialchars($search) ?>">
            <select name="status">
                <option value="all">All Status</option>
                <option value="new" <?= $status === 'new' ? 'selected' : '' ?>>New</option>
                <option value="contacted" <?= $status === 'contacted' ? 'selected' : '' ?>>Contacted</option>
                <option value="interested" <?= $status === 'interested' ? 'selected' : '' ?>>Interested</option>
                <option value="not_interested" <?= $status === 'not_interested' ? 'selected' : '' ?>>Not Interested</option>
                <option value="converted" <?= $status === 'converted' ? 'selected' : '' ?>>Converted</option>
            </select>
            <button type="submit" class="btn">Filter</button>
        </form>
    </div>

    <?php if (count($leads) > 0): ?>
        <table class="table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Company</th>
                    <th>Phone</th>
                    <th>Email</th>
                    <th>Status</th>
                    <th>Created</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($leads as $lead): ?>
                <tr>
                    <td><?= htmlspecialchars($lead['name']) ?></td>
                    <td><?= htmlspecialchars($lead['company']) ?></td>
                    <td><?= htmlspecialchars($lead['phone']) ?></td>
                    <td><?= htmlspecialchars($lead['email']) ?></td>
                    <td><?= htmlspecialchars($lead['status']) ?></td>
                    <td><?= date('M d, Y', strtotime($lead['created_at'])) ?></td>
                    <td>
                        <a href="lead.php?id=<?= $lead['id'] ?>" class="btn-secondary btn-small">View</a>
                        <a href="lead.php?action=edit&id=<?= $lead['id'] ?>" class="btn-secondary btn-small">Edit</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <p>No leads found. <a href="lead.php?action=add">Add your first lead</a>.</p>
    <?php endif; ?>
</div>

<?php include 'includes/footer.php'; ?>