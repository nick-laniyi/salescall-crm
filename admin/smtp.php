<?php
require_once '../includes/auth.php';
require_once '../includes/config.php';
requireAdmin();

$message = '';
$error = '';

// Load existing settings if any
$stmt = $pdo->query("SELECT * FROM email_settings WHERE user_id = {$_SESSION['user_id']} LIMIT 1");
$settings = $stmt->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $host = trim($_POST['host'] ?? '');
    $port = (int)($_POST['port'] ?? 587);
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $encryption = $_POST['encryption'] ?? 'tls';
    $from_email = trim($_POST['from_email'] ?? '');
    $from_name = trim($_POST['from_name'] ?? '');
    
    if (empty($host) || empty($port) || empty($username) || empty($from_email)) {
        $error = 'All fields except password (if unchanged) are required.';
    } else {
        // If password is empty and we have existing settings, keep old password
        if (empty($password) && $settings) {
            $password = $settings['smtp_pass'];
        } elseif (!empty($password)) {
            // Encrypt password before storing (optional but recommended)
            $password = openssl_encrypt($password, 'AES-128-ECB', APP_SECRET_KEY);
        }
        
        if ($settings) {
            // Update
            $stmt = $pdo->prepare("UPDATE email_settings SET smtp_host = ?, smtp_port = ?, smtp_user = ?, smtp_pass = ?, encryption = ?, from_email = ?, from_name = ? WHERE id = ?");
            $success = $stmt->execute([$host, $port, $username, $password, $encryption, $from_email, $from_name, $settings['id']]);
        } else {
            // Insert
            $stmt = $pdo->prepare("INSERT INTO email_settings (user_id, smtp_host, smtp_port, smtp_user, smtp_pass, encryption, from_email, from_name) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $success = $stmt->execute([$_SESSION['user_id'], $host, $port, $username, $password, $encryption, $from_email, $from_name]);
        }
        
        if ($success) {
            // Generate/update the email_config.php file
            generateEmailConfig($pdo, $_SESSION['user_id']);
            $message = 'SMTP settings saved successfully!';
            // Reload settings
            $stmt = $pdo->prepare("SELECT * FROM email_settings WHERE user_id = ? LIMIT 1");
            $stmt->execute([$_SESSION['user_id']]);
            $settings = $stmt->fetch();
        } else {
            $error = 'Failed to save settings.';
        }
    }
}

// Function to generate email_config.php
function generateEmailConfig($pdo, $user_id) {
    $stmt = $pdo->prepare("SELECT * FROM email_settings WHERE user_id = ? LIMIT 1");
    $stmt->execute([$user_id]);
    $settings = $stmt->fetch();
    
    if ($settings) {
        // Decrypt password for config file
        $password = openssl_decrypt($settings['smtp_pass'], 'AES-128-ECB', APP_SECRET_KEY);
        
        $config = "<?php\n";
        $config .= "// Auto-generated email configuration - DO NOT EDIT MANUALLY\n";
        $config .= "// Generated: " . date('Y-m-d H:i:s') . "\n\n";
        $config .= "define('SMTP_HOST', '" . addslashes($settings['smtp_host']) . "');\n";
        $config .= "define('SMTP_PORT', " . $settings['smtp_port'] . ");\n";
        $config .= "define('SMTP_USER', '" . addslashes($settings['smtp_user']) . "');\n";
        $config .= "define('SMTP_PASS', '" . addslashes($password) . "');\n";
        $config .= "define('SMTP_ENCRYPTION', '" . $settings['encryption'] . "');\n";
        $config .= "define('FROM_EMAIL', '" . addslashes($settings['from_email']) . "');\n";
        $config .= "define('FROM_NAME', '" . addslashes($settings['from_name'] ?: '') . "');\n";
        
        file_put_contents('../includes/email_config.php', $config);
    }
}

include '../includes/header.php';
?>

<h1>SMTP Settings</h1>

<?php if ($message): ?>
    <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<div class="card">
    <form method="post" id="smtpForm">
        <div class="form-group">
            <label for="host">SMTP Host *</label>
            <input type="text" id="host" name="host" value="<?= htmlspecialchars($settings['smtp_host'] ?? '') ?>" required placeholder="e.g., smtp.gmail.com">
        </div>
        
        <div class="form-group">
            <label for="port">SMTP Port *</label>
            <input type="number" id="port" name="port" value="<?= htmlspecialchars($settings['smtp_port'] ?? '587') ?>" required min="1" max="65535">
            <small>Common ports: 25, 465 (SSL), 587 (TLS)</small>
        </div>
        
        <div class="form-group">
            <label for="username">SMTP Username *</label>
            <input type="text" id="username" name="username" value="<?= htmlspecialchars($settings['smtp_user'] ?? '') ?>" required placeholder="your-email@gmail.com">
        </div>
        
        <div class="form-group">
            <label for="password">SMTP Password</label>
            <input type="password" id="password" name="password" <?= $settings ? '' : 'required' ?> placeholder="<?= $settings ? 'Leave empty to keep unchanged' : 'Enter password' ?>">
            <?php if ($settings): ?>
                <small>Leave empty to keep current password</small>
            <?php endif; ?>
        </div>
        
        <div class="form-group">
            <label for="encryption">Encryption</label>
            <select id="encryption" name="encryption">
                <option value="tls" <?= ($settings['encryption'] ?? '') == 'tls' ? 'selected' : '' ?>>TLS</option>
                <option value="ssl" <?= ($settings['encryption'] ?? '') == 'ssl' ? 'selected' : '' ?>>SSL</option>
                <option value="none" <?= ($settings['encryption'] ?? '') == 'none' ? 'selected' : '' ?>>None</option>
            </select>
        </div>
        
        <div class="form-group">
            <label for="from_email">From Email Address *</label>
            <input type="email" id="from_email" name="from_email" value="<?= htmlspecialchars($settings['from_email'] ?? '') ?>" required placeholder="sender@example.com">
        </div>
        
        <div class="form-group">
            <label for="from_name">From Name (optional)</label>
            <input type="text" id="from_name" name="from_name" value="<?= htmlspecialchars($settings['from_name'] ?? '') ?>" placeholder="Your Name or Company">
        </div>
        
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Save Settings</button>
            <button type="button" class="btn-secondary" id="testConnection">Test Connection</button>
        </div>
    </form>
</div>

<script>
document.getElementById('testConnection').addEventListener('click', function() {
    // You could implement an AJAX call to test SMTP connection
    alert('Test connection feature coming soon!');
});
</script>

<?php include '../includes/footer.php'; ?>