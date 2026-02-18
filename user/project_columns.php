<?php
require_once '../includes/auth.php';
require_once '../includes/config.php';

$project_id = isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0;
if (!$project_id) {
    header('Location: projects.php');
    exit;
}

// Verify project ownership
$stmt = $pdo->prepare("SELECT * FROM projects WHERE id = ? AND user_id = ?");
$stmt->execute([$project_id, $_SESSION['user_id']]);
$project = $stmt->fetch();
if (!$project) {
    header('Location: projects.php');
    exit;
}

$message = '';
$error = '';

// Handle delete column
if (isset($_GET['delete'])) {
    $col_id = (int)$_GET['delete'];
    $stmt = $pdo->prepare("DELETE FROM project_columns WHERE id = ? AND project_id = ?");
    if ($stmt->execute([$col_id, $project_id])) {
        $message = "Column deleted.";
    } else {
        $error = "Failed to delete column.";
    }
}

// Handle add/edit column
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_column'])) {
    $col_id = (int)($_POST['col_id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $type = $_POST['type'] ?? 'text';
    $options = trim($_POST['options'] ?? '');
    
    if (empty($name)) {
        $error = "Column name is required.";
    } else {
        $allowed_types = ['text', 'email', 'phone', 'number', 'date', 'select'];
        if (!in_array($type, $allowed_types)) {
            $type = 'text';
        }
        if ($col_id > 0) {
            // Update
            $stmt = $pdo->prepare("UPDATE project_columns SET name = ?, column_type = ?, options = ? WHERE id = ? AND project_id = ?");
            if ($stmt->execute([$name, $type, $options, $col_id, $project_id])) {
                $message = "Column updated.";
            } else {
                $error = "Failed to update column.";
            }
        } else {
            // Insert with max sort_order
            $max = $pdo->prepare("SELECT MAX(sort_order) FROM project_columns WHERE project_id = ?");
            $max->execute([$project_id]);
            $max_order = (int)$max->fetchColumn();
            $stmt = $pdo->prepare("INSERT INTO project_columns (project_id, name, column_type, options, sort_order) VALUES (?, ?, ?, ?, ?)");
            if ($stmt->execute([$project_id, $name, $type, $options, $max_order + 1])) {
                $message = "Column added.";
            } else {
                $error = "Failed to add column.";
            }
        }
    }
}

// Handle move up/down
if (isset($_GET['move'])) {
    $col_id = (int)$_GET['move'];
    $direction = $_GET['dir'] ?? 'up';
    
    $stmt = $pdo->prepare("SELECT sort_order FROM project_columns WHERE id = ? AND project_id = ?");
    $stmt->execute([$col_id, $project_id]);
    $current = $stmt->fetchColumn();
    if ($current === false) {
        $error = "Column not found.";
    } else {
        if ($direction === 'up') {
            $new_order = $current - 1;
            $swap = $pdo->prepare("SELECT id FROM project_columns WHERE project_id = ? AND sort_order = ?");
            $swap->execute([$project_id, $new_order]);
            $swap_id = $swap->fetchColumn();
            if ($swap_id) {
                $pdo->beginTransaction();
                $pdo->prepare("UPDATE project_columns SET sort_order = ? WHERE id = ?")->execute([$current, $swap_id]);
                $pdo->prepare("UPDATE project_columns SET sort_order = ? WHERE id = ?")->execute([$new_order, $col_id]);
                $pdo->commit();
                $message = "Column moved up.";
            }
        } else {
            $new_order = $current + 1;
            $swap = $pdo->prepare("SELECT id FROM project_columns WHERE project_id = ? AND sort_order = ?");
            $swap->execute([$project_id, $new_order]);
            $swap_id = $swap->fetchColumn();
            if ($swap_id) {
                $pdo->beginTransaction();
                $pdo->prepare("UPDATE project_columns SET sort_order = ? WHERE id = ?")->execute([$current, $swap_id]);
                $pdo->prepare("UPDATE project_columns SET sort_order = ? WHERE id = ?")->execute([$new_order, $col_id]);
                $pdo->commit();
                $message = "Column moved down.";
            }
        }
    }
}

// Fetch columns
$stmt = $pdo->prepare("SELECT * FROM project_columns WHERE project_id = ? ORDER BY sort_order");
$stmt->execute([$project_id]);
$columns = $stmt->fetchAll();

include '../includes/header.php';
?>

<h1>Manage Columns: <?= htmlspecialchars($project['name']) ?></h1>

<?php if ($message): ?>
    <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<div class="card">
    <h2>Add New Column</h2>
    <form method="post" style="margin-bottom: 20px;">
        <input type="hidden" name="save_column" value="1">
        <input type="hidden" name="col_id" value="0">
        <div style="display: flex; gap: 10px; flex-wrap: wrap;">
            <div style="flex: 2;">
                <label>Name</label>
                <input type="text" name="name" required>
            </div>
            <div style="flex: 1;">
                <label>Type</label>
                <select name="type" id="new_type">
                    <option value="text">Text</option>
                    <option value="email">Email</option>
                    <option value="phone">Phone</option>
                    <option value="number">Number</option>
                    <option value="date">Date</option>
                    <option value="select">Dropdown</option>
                </select>
            </div>
            <div style="flex: 3;" id="options_div" style="display:none;">
                <label>Options (one per line)</label>
                <textarea name="options" rows="2"></textarea>
            </div>
            <div style="align-self: flex-end;">
                <button type="submit" class="btn">Add Column</button>
            </div>
        </div>
    </form>

    <h2>Existing Columns</h2>
    <?php if (count($columns) > 0): ?>
        <table class="table">
            <thead>
                <tr>
                    <th>Order</th>
                    <th>Name</th>
                    <th>Type</th>
                    <th>Options</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($columns as $col): ?>
                <tr>
                    <td>
                        <?php if ($col['sort_order'] > 1): ?>
                            <a href="?project_id=<?= $project_id ?>&move=<?= $col['id'] ?>&dir=up" class="btn-small">↑</a>
                        <?php endif; ?>
                        <?php if ($col['sort_order'] < count($columns)): ?>
                            <a href="?project_id=<?= $project_id ?>&move=<?= $col['id'] ?>&dir=down" class="btn-small">↓</a>
                        <?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars($col['name']) ?></td>
                    <td><?= $col['column_type'] ?></td>
                    <td><?= nl2br(htmlspecialchars($col['options'] ?? '')) ?></td>
                    <td>
                        <a href="#" class="btn-secondary btn-small edit-col" data-id="<?= $col['id'] ?>" data-name="<?= htmlspecialchars($col['name']) ?>" data-type="<?= $col['column_type'] ?>" data-options="<?= htmlspecialchars($col['options'] ?? '') ?>">Edit</a>
                        <a href="?project_id=<?= $project_id ?>&delete=<?= $col['id'] ?>" class="btn-danger btn-small" onclick="return confirm('Delete this column? This will remove all data in this column for all leads.')">Delete</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <p>No columns yet. Add your first column above.</p>
    <?php endif; ?>
</div>

<!-- Edit Modal -->
<div id="editModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); align-items:center; justify-content:center;">
    <div style="background:#fff; padding:20px; border-radius:8px; max-width:500px; width:100%;">
        <h3>Edit Column</h3>
        <form method="post">
            <input type="hidden" name="save_column" value="1">
            <input type="hidden" name="col_id" id="edit_id" value="">
            <div class="form-group">
                <label>Name</label>
                <input type="text" name="name" id="edit_name" required>
            </div>
            <div class="form-group">
                <label>Type</label>
                <select name="type" id="edit_type">
                    <option value="text">Text</option>
                    <option value="email">Email</option>
                    <option value="phone">Phone</option>
                    <option value="number">Number</option>
                    <option value="date">Date</option>
                    <option value="select">Dropdown</option>
                </select>
            </div>
            <div class="form-group" id="edit_options_div">
                <label>Options (one per line)</label>
                <textarea name="options" id="edit_options" rows="3"></textarea>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn">Save Changes</button>
                <button type="button" class="btn-secondary" onclick="closeEditModal()">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
document.querySelectorAll('.edit-col').forEach(btn => {
    btn.addEventListener('click', function(e) {
        e.preventDefault();
        document.getElementById('edit_id').value = this.dataset.id;
        document.getElementById('edit_name').value = this.dataset.name;
        document.getElementById('edit_type').value = this.dataset.type;
        document.getElementById('edit_options').value = this.dataset.options;
        if (this.dataset.type === 'select') {
            document.getElementById('edit_options_div').style.display = 'block';
        } else {
            document.getElementById('edit_options_div').style.display = 'none';
        }
        document.getElementById('editModal').style.display = 'flex';
    });
});

function closeEditModal() {
    document.getElementById('editModal').style.display = 'none';
}

document.getElementById('new_type').addEventListener('change', function() {
    const div = document.getElementById('options_div');
    div.style.display = this.value === 'select' ? 'block' : 'none';
});
</script>

<?php include '../includes/footer.php'; ?>