<?php
require_once '../includes/auth.php';
require_once '../includes/config.php';
requireAdmin();

$project_id = isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0;

// Verify project ownership
$stmt = $pdo->prepare("SELECT * FROM projects WHERE id = ? AND user_id = ?");
$stmt->execute([$project_id, $_SESSION['user_id']]);
$project = $stmt->fetch();
if (!$project) {
    die('Project not found.');
}

$message = '';
$error = '';

// Handle delete
if (isset($_GET['delete'])) {
    $col_id = (int)$_GET['delete'];
    $stmt = $pdo->prepare("DELETE FROM project_columns WHERE id = ? AND project_id = ?");
    if ($stmt->execute([$col_id, $project_id])) {
        $message = "Column deleted.";
    } else {
        $error = "Failed to delete column.";
    }
}

// Handle reorder (simple: just update sort_order via AJAX later, but for now we'll list by sort_order)

include '../includes/header.php';
?>

<h1>Columns for Project: <?= htmlspecialchars($project['name']) ?></h1>

<?php if ($message): ?>
    <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<div class="card">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <h2>Custom Columns</h2>
        <a href="project_column_form.php?project_id=<?= $project_id ?>" class="btn">Add New Column</a>
    </div>

    <?php
    $stmt = $pdo->prepare("SELECT * FROM project_columns WHERE project_id = ? ORDER BY sort_order, id");
    $stmt->execute([$project_id]);
    $columns = $stmt->fetchAll();
    ?>

    <?php if (count($columns) > 0): ?>
        <table class="table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Type</th>
                    <th>Options</th>
                    <th>Order</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($columns as $col): ?>
                <tr>
                    <td><?= htmlspecialchars($col['name']) ?></td>
                    <td><?= $col['column_type'] ?></td>
                    <td>
                        <?php if ($col['column_type'] === 'select' && $col['options']): ?>
                            <?php $opts = json_decode($col['options'], true); ?>
                            <?= implode(', ', $opts) ?>
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </td>
                    <td><?= $col['sort_order'] ?></td>
                    <td>
                        <a href="project_column_form.php?project_id=<?= $project_id ?>&id=<?= $col['id'] ?>" class="btn-secondary btn-small">Edit</a>
                        <a href="?project_id=<?= $project_id ?>&delete=<?= $col['id'] ?>" class="btn-danger btn-small" onclick="return confirm('Delete this column? This will remove all data for this column from leads.')">Delete</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <p>No columns yet. <a href="project_column_form.php?project_id=<?= $project_id ?>">Add your first column</a>.</p>
    <?php endif; ?>
    
    <div style="margin-top: 20px;">
        <a href="projects.php" class="btn-secondary">Back to Projects</a>
    </div>
</div>

<?php include '../includes/footer.php'; ?>