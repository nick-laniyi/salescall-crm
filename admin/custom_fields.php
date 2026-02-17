<?php
require_once '../includes/auth.php';
require_once '../includes/config.php';
requireAdmin();

$message = '';
$error = '';

// Handle delete
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $stmt = $pdo->prepare("DELETE FROM custom_fields WHERE id = ? AND user_id = ?");
    if ($stmt->execute([$id, $_SESSION['user_id']])) {
        $message = "Field deleted.";
    } else {
        $error = "Failed to delete field.";
    }
}

// Handle add/edit form
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $type = $_POST['type'] ?? 'text';
    $options = $_POST['options'] ?? '';
    
    if (empty($name)) {
        $error = "Field name is required.";
    } else {
        if ($type === 'select' && empty($options)) {
            $error = "Options are required for select fields.";
        } else {
            $options_json = $type === 'select' ? json_encode(array_map('trim', explode("\n", trim($options)))) : null;
            
            if ($id > 0) {
                $stmt = $pdo->prepare("UPDATE custom_fields SET name = ?, field_type = ?, options = ? WHERE id = ? AND user_id = ?");
                $stmt->execute([$name, $type, $options_json, $id, $_SESSION['user_id']]);
                $message = "Field updated.";
            } else {
                // Get max sort order
                $stmt = $pdo->prepare("SELECT MAX(sort_order) FROM custom_fields WHERE user_id = ?");
                $stmt->execute([$_SESSION['user_id']]);
                $max = $stmt->fetchColumn();
                $sort = $max ? $max + 1 : 0;
                
                $stmt = $pdo->prepare("INSERT INTO custom_fields (user_id, name, field_type, options, sort_order) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$_SESSION['user_id'], $name, $type, $options_json, $sort]);
                $message = "Field added.";
            }
        }
    }
}

// Fetch all custom fields for this admin
$stmt = $pdo->prepare("SELECT * FROM custom_fields WHERE user_id = ? ORDER BY sort_order");
$stmt->execute([$_SESSION['user_id']]);
$fields = $stmt->fetchAll();

include '../includes/header.php';
?>

<h1>Custom Fields</h1>

<?php if ($message): ?>
    <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<div class="card">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <h2>Manage Custom Lead Fields</h2>
        <a href="custom_field_form.php" class="btn">Add New Field</a>
    </div>

    <?php if (count($fields) > 0): ?>
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
                <?php foreach ($fields as $field): ?>
                <tr>
                    <td><?= htmlspecialchars($field['name']) ?></td>
                    <td><?= $field['field_type'] ?></td>
                    <td>
                        <?php if ($field['field_type'] === 'select' && $field['options']): ?>
                            <?php $opts = json_decode($field['options'], true); ?>
                            <?= implode(', ', $opts) ?>
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </td>
                    <td><?= $field['sort_order'] ?></td>
                    <td>
                        <a href="custom_field_form.php?id=<?= $field['id'] ?>" class="btn-secondary btn-small">Edit</a>
                        <a href="?delete=<?= $field['id'] ?>" class="btn-danger btn-small" onclick="return confirm('Delete this field? This will remove all data for this field from leads.')">Delete</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <p>No custom fields yet. <a href="custom_field_form.php">Add your first field</a>.</p>
    <?php endif; ?>
</div>

<?php include '../includes/footer.php'; ?>