<?php
require_once 'includes/auth.php';
require_once 'includes/config.php';

$user = $currentUser; // from auth.php

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    $errors = [];

    if (empty($name) || empty($email)) {
        $errors[] = 'Name and email are required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Invalid email format.';
    }

    // Check if email already taken by another user
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
    $stmt->execute([$email, $user['id']]);
    if ($stmt->fetch()) {
        $errors[] = 'Email already in use by another account.';
    }

    // If changing password
    if (!empty($new_password)) {
        if (strlen($new_password) < 6) {
            $errors[] = 'New password must be at least 6 characters.';
        } elseif ($new_password !== $confirm) {
            $errors[] = 'New passwords do not match.';
        } else {
            // Verify current password
            $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = ?");
            $stmt->execute([$user['id']]);
            $hash = $stmt->fetchColumn();
            if (!password_verify($current_password, $hash)) {
                $errors[] = 'Current password is incorrect.';
            }
        }
    }

    if (empty($errors)) {
        // Update name and email
        $sql = "UPDATE users SET name = ?, email = ? WHERE id = ?";
        $params = [$name, $email, $user['id']];

        if (!empty($new_password)) {
            $sql = "UPDATE users SET name = ?, email = ?, password_hash = ? WHERE id = ?";
            $newHash = password_hash($new_password, PASSWORD_DEFAULT);
            $params = [$name, $email, $newHash, $user['id']];
        }

        $stmt = $pdo->prepare($sql);
        if ($stmt->execute($params)) {
            $_SESSION['user_name'] = $name;
            $success = 'Profile updated successfully.';
            // Refresh user data
            $stmt = $pdo->prepare("SELECT id, name, email FROM users WHERE id = ?");
            $stmt->execute([$user['id']]);
            $user = $stmt->fetch();
        } else {
            $errors[] = 'Update failed.';
        }
    }
}

include 'includes/header.php';
?>

<h1>Profile</h1>

<?php if (!empty($errors)): ?>
    <div class="alert alert-error">
        <?php foreach ($errors as $err): ?>
            <div><?= htmlspecialchars($err) ?></div>
        <?php endforeach; ?>
    </div>
<?php elseif (isset($success)): ?>
    <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
<?php endif; ?>

<div class="card">
    <form method="post">
        <div class="form-group">
            <label for="name">Full Name</label>
            <input type="text" id="name" name="name" value="<?= htmlspecialchars($user['name']) ?>" required>
        </div>
        <div class="form-group">
            <label for="email">Email</label>
            <input type="email" id="email" name="email" value="<?= htmlspecialchars($user['email']) ?>" required>
        </div>

        <hr style="margin: 20px 0;">

        <h3>Change Password (leave blank to keep current)</h3>
        <div class="form-group">
            <label for="current_password">Current Password</label>
            <input type="password" id="current_password" name="current_password">
        </div>
        <div class="form-group">
            <label for="new_password">New Password</label>
            <input type="password" id="new_password" name="new_password">
        </div>
        <div class="form-group">
            <label for="confirm_password">Confirm New Password</label>
            <input type="password" id="confirm_password" name="confirm_password">
        </div>

        <button type="submit" class="btn">Update Profile</button>
    </form>
</div>

<?php include 'includes/footer.php'; ?>