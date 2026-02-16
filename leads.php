<?php
// leads.php - List and manage leads
require_once 'includes/auth.php';
require_once 'includes/config.php';

// Handle bulk delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_selected'])) {
    $ids = $_POST['lead_ids'] ?? [];
    if (!empty($ids) && is_array($ids)) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("DELETE FROM leads WHERE id IN ($placeholders) AND user_id = ?");
        $params = array_merge($ids, [$_SESSION['user_id']]);
        if ($stmt->execute($params)) {
            $deleted = $stmt->rowCount();
            $success = "$deleted lead(s) deleted.";
        } else {
            $error = "Failed to delete leads.";
        }
    }
}

// Handle delete all
if (isset($_GET['delete_all']) && $_GET['delete_all'] === 'confirm') {
    $stmt = $pdo->prepare("DELETE FROM leads WHERE user_id = ?");
    if ($stmt->execute([$_SESSION['user_id']])) {
        $success = "All leads deleted.";
    } else {
        $error = "Failed to delete all leads.";
    }
}

// Get search and filter parameters
$search = $_GET['search'] ?? '';
$status = $_GET['status'] ?? '';
$show_imported = isset($_GET['imported']) && $_GET['imported'] == 1;

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
if ($show_imported && isset($_SESSION['last_import_time'])) {
    $sql .= " AND created_at >= :import_time";
    $params[':import_time'] = $_SESSION['last_import_time'];
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
    <div class="alert alert-success">
        <?= htmlspecialchars($_GET['count'] ?? '') ?> leads imported successfully.
        <?php if (isset($_SESSION['last_import_time'])): ?>
            <a href="leads.php?imported=1">View newly imported</a> |
            <a href="leads.php">View all</a>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if (isset($success)): ?>
    <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
<?php endif; ?>
<?php if (isset($error)): ?>
    <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
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
            <?php if ($search || $status || $show_imported): ?>
                <a href="leads.php" class="btn-secondary">Clear</a>
            <?php endif; ?>
        </form>
    </div>

    <?php if (count($leads) > 0): ?>
        <form method="post" id="bulk-delete-form">
            <div style="margin-bottom: 10px;">
                <button type="button" class="btn-secondary" onclick="selectAll()">Select All</button>
                <button type="submit" name="delete_selected" class="btn-danger" onclick="return confirm('Delete selected leads?')">Delete Selected</button>
                <a href="?delete_all=confirm" class="btn-danger" onclick="return confirm('Delete ALL leads? This cannot be undone.')">Delete All</a>
            </div>
            <table class="table">
                <thead>
                    <tr>
                        <th><input type="checkbox" id="select-all"></th>
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
                        <td><input type="checkbox" name="lead_ids[]" value="<?= $lead['id'] ?>" class="lead-checkbox"></td>
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
        </form>
    <?php else: ?>
        <p>No leads found. 
            <?php if ($search || $status || $show_imported): ?>
                <a href="leads.php">Clear filters</a> or 
            <?php endif; ?>
            <a href="lead.php?action=add">add your first lead</a>.
        </p>
    <?php endif; ?>
</div>

<script>
function selectAll() {
    var checkboxes = document.getElementsByClassName('lead-checkbox');
    var selectAllCheckbox = document.getElementById('select-all');
    for (var i = 0; i < checkboxes.length; i++) {
        checkboxes[i].checked = true;
    }
    selectAllCheckbox.checked = true;
}

document.getElementById('select-all').addEventListener('change', function() {
    var checkboxes = document.getElementsByClassName('lead-checkbox');
    for (var i = 0; i < checkboxes.length; i++) {
        checkboxes[i].checked = this.checked;
    }
});
</script>

<?php include 'includes/footer.php'; ?>