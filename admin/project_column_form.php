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

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$column = null;

if ($id) {
    $stmt = $pdo->prepare("SELECT * FROM project_columns WHERE id = ? AND project_id = ?");
    $stmt->execute([$id, $project_id]);
    $column = $stmt->fetch();
    if (!$column) {
        die('Column not found.');
    }
}

include '../includes/header.php';
?>

<h1><?= $id ? 'Edit Column' : 'Add Column' ?> for <?= htmlspecialchars($project['name']) ?></h1>

<div class="card">
    <form method="post" action="project_column_save.php">
        <input type="hidden" name="project_id" value="<?= $project_id ?>">
        <?php if ($id): ?>
            <input type="hidden" name="id" value="<?= $id ?>">
        <?php endif; ?>
        
        <div class="form-group">
            <label for="name">Column Name *</label>
            <input type="text" id="name" name="name" value="<?= $column ? htmlspecialchars($column['name']) : '' ?>" required>
        </div>
        
        <div class="form-group">
            <label for="type">Data Type *</label>
            <select id="type" name="type" required onchange="toggleOptions()">
                <option value="text" <?= $column && $column['column_type'] === 'text' ? 'selected' : '' ?>>Text</option>
                <option value="number" <?= $column && $column['column_type'] === 'number' ? 'selected' : '' ?>>Number</option>
                <option value="date" <?= $column && $column['column_type'] === 'date' ? 'selected' : '' ?>>Date</option>
                <option value="select" <?= $column && $column['column_type'] === 'select' ? 'selected' : '' ?>>Dropdown</option>
            </select>
        </div>
        
        <div class="form-group" id="options-group" style="<?= $column && $column['column_type'] === 'select' ? '' : 'display: none;' ?>">
            <label for="options">Options (one per line)</label>
            <textarea id="options" name="options" rows="5"><?php
                if ($column && $column['column_type'] === 'select' && $column['options']) {
                    $opts = json_decode($column['options'], true);
                    echo htmlspecialchars(implode("\n", $opts));
                }
            ?></textarea>
        </div>
        
        <div class="form-group">
            <label for="sort_order">Sort Order</label>
            <input type="number" id="sort_order" name="sort_order" value="<?= $column ? $column['sort_order'] : '0' ?>" min="0">
        </div>
        
        <button type="submit" class="btn"><?= $id ? 'Update Column' : 'Add Column' ?></button>
        <a href="project_columns.php?project_id=<?= $project_id ?>" class="btn-secondary">Cancel</a>
    </form>
</div>

<script>
function toggleOptions() {
    const type = document.getElementById('type').value;
    const optionsGroup = document.getElementById('options-group');
    optionsGroup.style.display = type === 'select' ? 'block' : 'none';
}
</script>

<?php include '../includes/footer.php'; ?>