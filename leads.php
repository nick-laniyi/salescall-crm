<?php
// leads.php - List and manage leads
require_once 'includes/auth.php';
require_once 'includes/config.php';

// Get search and filter parameters
$search = $_GET['search'] ?? '';
$status = $_GET['status'] ?? '';

// Build query
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

// Execute query
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$leads = $stmt->fetchAll();

include 'includes/header.php';
?>

<h1>Leads</h1>

<?php if (isset($_GET['imported'])): ?>
    <div class="alert alert-success">Leads imported successfully.</div>
<?php endif; ?>

<div class="card">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 10px;">
        <div>
            <a href="lead.php?action=add" class="btn">Add New Lead</a>
            <a href="import.php" class="btn-secondary">Import Leads</a>
            <a href="download_sample.php" class="btn-secondary">Download Sample CSV</a>
        </div>
        <form method="get" style="display: flex; gap: 10px; flex-wrap: wrap;">
            <input type="text" name="search" placeholder="Search leads..." value="<?= htmlspecialchars($search) ?>" style="padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
            <select name="status" style="padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
                <option value="all">All Status</option>
                <option value="new" <?= $status === 'new' ? 'selected' : '' ?>>New</option>
                <option value="contacted" <?= $status === 'contacted' ? 'selected' : '' ?>>Contacted</option>
                <option value="interested" <?= $status === 'interested' ? 'selected' : '' ?>>Interested</option>
                <option value="not_interested" <?= $status === 'not_interested' ? 'selected' : '' ?>>Not Interested</option>
                <option value="converted" <?= $status === 'converted' ? 'selected' : '' ?>>Converted</option>
            </select>
            <button type="submit" class="btn">Filter</button>
            <?php if ($search || $status): ?>
                <a href="leads.php" class="btn-secondary">Clear</a>
            <?php endif; ?>
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
                    <td><?= htmlspecialchars($lead['company'] ?: '—') ?></td>
                    <td><?= htmlspecialchars($lead['phone'] ?: '—') ?></td>
                    <td><?= htmlspecialchars($lead['email'] ?: '—') ?></td>
                    <td>
                        <span class="status-badge status-<?= htmlspecialchars($lead['status']) ?>">
                            <?= htmlspecialchars($lead['status']) ?>
                        </span>
                    </td>
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
        <p>No leads found. 
            <?php if ($search || $status): ?>
                <a href="leads.php">Clear filters</a> or 
            <?php endif; ?>
            <a href="lead.php?action=add">add your first lead</a>.
        </p>
    <?php endif; ?>
</div>

<?php include 'includes/footer.php'; ?>