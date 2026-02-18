<?php
require_once '../includes/auth.php';
require_once '../includes/config.php';

$message = '';
$error = '';

// Handle delete
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    // Check if project has leads
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM leads WHERE project_id = ?");
    $stmt->execute([$id]);
    $count = $stmt->fetchColumn();
    if ($count > 0) {
        $error = "Cannot delete project with leads. Reassign leads first.";
    } else {
        $stmt = $pdo->prepare("DELETE FROM projects WHERE id = ? AND user_id = ?");
        if ($stmt->execute([$id, $_SESSION['user_id']])) {
            $message = "Project deleted.";
        } else {
            $error = "Failed to delete project.";
        }
    }
}

// Fetch user's projects
$stmt = $pdo->prepare("SELECT * FROM projects WHERE user_id = ? ORDER BY name");
$stmt->execute([$_SESSION['user_id']]);
$projects = $stmt->fetchAll();

include '../includes/header.php';
?>

<h1>My Projects</h1>

<?php if ($message): ?>
    <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<div class="card">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <h2>Manage Projects</h2>
        <a href="project_form.php" class="btn">Add New Project</a>
    </div>

    <?php if (count($projects) > 0): ?>
        <table class="table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Description</th>
                    <th>Created</th>
                    <th>Columns</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($projects as $p): ?>
                <tr>
                    <td><?= htmlspecialchars($p['name']) ?></td>
                    <td><?= htmlspecialchars($p['description'] ?: '—') ?></td>
                    <td><?= date('M d, Y', strtotime($p['created_at'])) ?></td>
                    <td>
                        <a href="project_columns.php?project_id=<?= $p['id'] ?>" class="btn-secondary btn-small">Manage Columns</a>
                    </td>
                    <td>
                        <a href="project_form.php?id=<?= $p['id'] ?>" class="btn-secondary btn-small">Edit</a>
                        <a href="?delete=<?= $p['id'] ?>" class="btn-danger btn-small" onclick="return confirm('Delete this project?')">Delete</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <p>No projects yet. <a href="project_form.php">Create your first project</a>.</p>
    <?php endif; ?>
</div>

<?php include '../includes/footer.php'; ?>