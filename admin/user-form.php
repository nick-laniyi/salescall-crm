<?php
require_once '../includes/auth.php';
require_once '../includes/config.php';
requireAdmin();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$user = null;
$isEdit = $id > 0;

if ($isEdit) {
    $stmt = $pdo->prepare("SELECT id, name, email, role FROM users WHERE id = ?");
    $stmt->execute([$id]);
    $user = $stmt->fetch();
    if (!$user) {
        die('User not found.');
    }
}

include '../includes/header.php';
?>

<h1><?= $isEdit ? 'Edit User' : 'Add New User' ?></h1>

<div class="card">
    <form method="post" action="users.php">
        <?php if ($isEdit): ?>
            <input type="hidden" name="id" value="<?= $user['id'] ?>">
        <?php endif; ?>
        
        <div class="form-group">
            <label for="name">Full Name *</label>
            <input type="text" id="name" name="name" value="<?= $isEdit ? htmlspecialchars($user['name']) : '' ?>" required>
        </div>
        
        <div class="form-group">
            <label for="email">Email *</label>
            <input type="email" id="email" name="email" value="<?= $isEdit ? htmlspecialchars($user['email']) : '' ?>" required>
        </div>
        
        <div class="form-group">
            <label for="role">Role</label>
            <select id="role" name="role">
                <option value="user" <?= $isEdit && $user['role'] === 'user' ? 'selected' : '' ?>>User</option>
                <option value="admin" <?= $isEdit && $user['role'] === 'admin' ? 'selected' : '' ?>>Admin</option>
            </select>
        </div>
        
        <div class="form-group">
            <label for="password"><?= $isEdit ? 'New Password (leave blank to keep current)' : 'Password *' ?></label>
            <input type="password" id="password" name="password" <?= $isEdit ? '' : 'required' ?>>
        </div>
        
        <button type="submit" class="btn"><?= $isEdit ? 'Update User' : 'Create User' ?></button>
        <a href="users.php" class="btn-secondary">Cancel</a>
    </form>
</div>

<?php include '../includes/footer.php'; ?>