<?php
require_once '../includes/auth.php';
require_once '../includes/config.php';
requireAdmin();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$field = null;

if ($id) {
    $stmt = $pdo->prepare("SELECT * FROM custom_fields WHERE id = ? AND user_id = ?");
    $stmt->execute([$id, $_SESSION['user_id']]);
    $field = $stmt->fetch();
    if (!$field) {
        die('Field not found.');
    }
}

include '../includes/header.php';
?>

<h1><?= $id ? 'Edit Custom Field' : 'Add Custom Field' ?></h1>

<div class="card">
    <form method="post" action="custom_fields.php">
        <?php if ($id): ?>
            <input type="hidden" name="id" value="<?= $id ?>">
        <?php endif; ?>
        
        <div class="form-group">
            <label for="name">Field Name *</label>
            <input type="text" id="name" name="name" value="<?= $field ? htmlspecialchars($field['name']) : '' ?>" required>
        </div>
        
        <div class="form-group">
            <label for="type">Field Type *</label>
            <select id="type" name="type" required onchange="toggleOptions()">
                <option value="text" <?= $field && $field['field_type'] === 'text' ? 'selected' : '' ?>>Text</option>
                <option value="number" <?= $field && $field['field_type'] === 'number' ? 'selected' : '' ?>>Number</option>
                <option value="date" <?= $field && $field['field_type'] === 'date' ? 'selected' : '' ?>>Date</option>
                <option value="select" <?= $field && $field['field_type'] === 'select' ? 'selected' : '' ?>>Dropdown (Select)</option>
            </select>
        </div>
        
        <div class="form-group" id="options-group" style="<?= $field && $field['field_type'] === 'select' ? '' : 'display: none;' ?>">
            <label for="options">Options (one per line)</label>
            <textarea id="options" name="options" rows="5"><?php
                if ($field && $field['field_type'] === 'select' && $field['options']) {
                    $opts = json_decode($field['options'], true);
                    echo htmlspecialchars(implode("\n", $opts));
                }
            ?></textarea>
        </div>
        
        <button type="submit" class="btn"><?= $id ? 'Update Field' : 'Add Field' ?></button>
        <a href="custom_fields.php" class="btn-secondary">Cancel</a>
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