<?php
require_once '../includes/auth.php';
require_once '../includes/config.php';
requireAdmin();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$project = null;

if ($id) {
    $stmt = $pdo->prepare("SELECT * FROM projects WHERE id = ? AND user_id = ?");
    $stmt->execute([$id, $_SESSION['user_id']]);
    $project = $stmt->fetch();
    if (!$project) {
        die('Project not found.');
    }
}

include '../includes/header.php';
?>

<h1><?= $id ? 'Edit Project' : 'Add New Project' ?></h1>

<div class="card">
    <form method="post" action="projects.php">
        <?php if ($id): ?>
            <input type="hidden" name="id" value="<?= $id ?>">
        <?php endif; ?>
        
        <div class="form-group">
            <label for="name">Project Name *</label>
            <input type="text" id="name" name="name" value="<?= $project ? htmlspecialchars($project['name']) : '' ?>" required>
        </div>
        
        <div class="form-group">
            <label for="description">Description</label>
            <textarea id="description" name="description" rows="3"><?= $project ? htmlspecialchars($project['description']) : '' ?></textarea>
        </div>
        
        <button type="submit" class="btn"><?= $id ? 'Update Project' : 'Create Project' ?></button>
        <a href="projects.php" class="btn-secondary">Cancel</a>
    </form>
</div>

<?php include '../includes/footer.php'; ?>