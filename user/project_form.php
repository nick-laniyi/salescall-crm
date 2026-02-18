<?php
require_once '../includes/auth.php';
require_once '../includes/config.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$project = ['name' => '', 'description' => ''];

if ($id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM projects WHERE id = ? AND user_id = ?");
    $stmt->execute([$id, $_SESSION['user_id']]);
    $project = $stmt->fetch();
    if (!$project) {
        header('Location: projects.php');
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $id = (int)($_POST['id'] ?? 0);
    
    if (empty($name)) {
        $error = "Project name is required.";
    } else {
        if ($id > 0) {
            $stmt = $pdo->prepare("UPDATE projects SET name = ?, description = ? WHERE id = ? AND user_id = ?");
            $stmt->execute([$name, $description, $id, $_SESSION['user_id']]);
            $message = "Project updated.";
        } else {
            $stmt = $pdo->prepare("INSERT INTO projects (user_id, name, description) VALUES (?, ?, ?)");
            $stmt->execute([$_SESSION['user_id'], $name, $description]);
            $message = "Project created.";
            $id = $pdo->lastInsertId();
        }
        header('Location: projects.php');
        exit;
    }
}

include '../includes/header.php';
?>

<h1><?= $id > 0 ? 'Edit Project' : 'New Project' ?></h1>

<?php if (isset($error)): ?>
    <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<div class="card">
    <form method="post">
        <input type="hidden" name="id" value="<?= $id ?>">
        
        <div class="form-group">
            <label for="name">Project Name *</label>
            <input type="text" id="name" name="name" value="<?= htmlspecialchars($project['name']) ?>" required>
        </div>
        
        <div class="form-group">
            <label for="description">Description</label>
            <textarea id="description" name="description" rows="3"><?= htmlspecialchars($project['description']) ?></textarea>
        </div>
        
        <div class="form-actions">
            <button type="submit" class="btn">Save</button>
            <a href="projects.php" class="btn-secondary">Cancel</a>
        </div>
    </form>
</div>

<?php include '../includes/footer.php'; ?>