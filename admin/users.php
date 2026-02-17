<?php
require_once '../includes/auth.php';
require_once '../includes/config.php';
requireAdmin(); // Only admins can access

$action = $_GET['action'] ?? 'list';
$message = '';
$error = '';

// Handle delete
if ($action === 'delete' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    if ($id == $_SESSION['user_id']) {
        $error = 'You cannot delete your own account.';
    } else {
        $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
        if ($stmt->execute([$id])) {
            $message = 'User deleted successfully.';
        } else {
            $error = 'Failed to delete user.';
        }
    }
    // Redirect to list after action
    header('Location: users.php?message=' . urlencode($message) . '&error=' . urlencode($error));
    exit;
}

// Handle add/edit form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $role = $_POST['role'] ?? 'user';
    $password = $_POST['password'] ?? '';
    
    if (empty($name) || empty($email)) {
        $error = 'Name and email are required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Invalid email format.';
    } else {
        if ($id > 0) {
            // Update
            if (!empty($password)) {
                // Change password
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE users SET name = ?, email = ?, role = ?, password_hash = ? WHERE id = ?");
                $stmt->execute([$name, $email, $role, $hash, $id]);
            } else {
                $stmt = $pdo->prepare("UPDATE users SET name = ?, email = ?, role = ? WHERE id = ?");
                $stmt->execute([$name, $email, $role, $id]);
            }
            $message = 'User updated successfully.';
        } else {
            // Check if email exists
            $check = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $check->execute([$email]);
            if ($check->fetch()) {
                $error = 'Email already exists.';
            } else {
                if (empty($password)) {
                    $error = 'Password is required for new users.';
                } else {
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$name, $email, $hash, $role]);
                    $message = 'User created successfully.';
                }
            }
        }
    }
    // If error, we'll stay on form; if success, redirect to list
    if (empty($error)) {
        header('Location: users.php?message=' . urlencode($message));
        exit;
    }
}

// Fetch all users
$users = $pdo->query("SELECT id, name, email, role, created_at FROM users ORDER BY created_at DESC")->fetchAll();

include '../includes/header.php';
?>

<h1>User Management</h1>

<?php if (isset($_GET['message']) && $_GET['message']): ?>
    <div class="alert alert-success"><?= htmlspecialchars($_GET['message']) ?></div>
<?php endif; ?>
<?php if (isset($_GET['error']) && $_GET['error']): ?>
    <div class="alert alert-error"><?= htmlspecialchars($_GET['error']) ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<div class="card">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <h2>All Users</h2>
        <a href="user-form.php" class="btn">Add New User</a>
    </div>

    <table class="table">
        <thead>
            <tr>
                <th>ID</th>
                <th>Name</th>
                <th>Email</th>
                <th>Role</th>
                <th>Created</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($users as $user): ?>
            <tr>
                <td><?= $user['id'] ?></td>
                <td><?= htmlspecialchars($user['name']) ?></td>
                <td><?= htmlspecialchars($user['email']) ?></td>
                <td>
                    <span class="role-badge role-<?= $user['role'] ?>"><?= $user['role'] ?></span>
                </td>
                <td><?= date('M d, Y', strtotime($user['created_at'])) ?></td>
                <td>
                    <a href="user-form.php?id=<?= $user['id'] ?>" class="btn-secondary btn-small">Edit</a>
                    <?php if ($user['id'] != $_SESSION['user_id']): ?>
                        <a href="users.php?action=delete&id=<?= $user['id'] ?>" class="btn-danger btn-small" onclick="return confirm('Delete this user?')">Delete</a>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<style>
.role-badge {
    display: inline-block;
    padding: 4px 8px;
    border-radius: 12px;
    font-size: 0.8rem;
    font-weight: 500;
    text-transform: capitalize;
}
.role-admin {
    background-color: #fef9c3;
    color: #854d0e;
}
.role-user {
    background-color: #e2e8f0;
    color: #475569;
}
body.dark-mode .role-admin {
    background-color: #854d0e;
    color: #fef9c3;
}
body.dark-mode .role-user {
    background-color: #4a5568;
    color: #e2e8f0;
}
</style>

<?php include '../includes/footer.php'; ?>