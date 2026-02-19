<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

require_once '../includes/auth.php';
require_once '../includes/config.php';
require_once '../includes/functions.php';

// Check if user is admin
$is_admin = isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin';

// Check if PHPMailer files exist
$phpmailer_paths = [
    '../phpmailer/src/Exception.php',
    '../phpmailer/src/PHPMailer.php',
    '../phpmailer/src/SMTP.php'
];

$phpmailer_missing = [];
foreach ($phpmailer_paths as $path) {
    if (!file_exists($path)) {
        $phpmailer_missing[] = $path;
    }
}

// Only try to include PHPMailer if files exist
if (empty($phpmailer_missing)) {
    require_once '../phpmailer/src/Exception.php';
    require_once '../phpmailer/src/PHPMailer.php';
    require_once '../phpmailer/src/SMTP.php';
    $phpmailer_available = true;
} else {
    $phpmailer_available = false;
}

// Check if email_config.php exists and has constants
$config_file = '../includes/email_config.php';
$smtp_configured = false;
$smtp_settings = [];

if (file_exists($config_file)) {
    include_once $config_file;
    if (defined('SMTP_HOST') && defined('SMTP_USER') && defined('SMTP_PASS') && defined('FROM_EMAIL')) {
        $smtp_configured = true;
        $smtp_settings = [
            'host' => SMTP_HOST,
            'port' => defined('SMTP_PORT') ? SMTP_PORT : 587,
            'user' => SMTP_USER,
            'encryption' => defined('SMTP_ENCRYPTION') ? SMTP_ENCRYPTION : 'tls',
            'from_email' => FROM_EMAIL,
            'from_name' => defined('FROM_NAME') ? FROM_NAME : ''
        ];
    }
}

// If not configured, try database fallback (in case email_config.php wasn't generated)
if (!$smtp_configured) {
    $stmt = $pdo->prepare("SELECT * FROM email_settings WHERE user_id = ? LIMIT 1");
    $stmt->execute([$_SESSION['user_id']]);
    $db_settings = $stmt->fetch();
    
    if ($db_settings && !empty($db_settings['smtp_host']) && !empty($db_settings['smtp_user']) && !empty($db_settings['smtp_pass'])) {
        $smtp_configured = true;
        $smtp_settings = [
            'host' => $db_settings['smtp_host'],
            'port' => $db_settings['smtp_port'],
            'user' => $db_settings['smtp_user'],
            'pass' => $db_settings['smtp_pass'], // encrypted
            'encryption' => $db_settings['encryption'],
            'from_email' => $db_settings['from_email'],
            'from_name' => $db_settings['from_name'] ?? ''
        ];
    }
}

$lead_id = isset($_GET['lead_id']) ? (int)$_GET['lead_id'] : 0;
$email   = isset($_GET['email']) ? trim($_GET['email']) : '';

// Verify lead access and fetch lead details
if ($lead_id) {
    if (!canViewLead($pdo, $lead_id, $_SESSION['user_id'])) {
        die('Lead not found or access denied.');
    }
    $stmt = $pdo->prepare("SELECT name, email FROM leads WHERE id = ?");
    $stmt->execute([$lead_id]);
    $lead = $stmt->fetch();
    if (!$lead) {
        die('Lead not found.');
    }
    // Use email from lead if not provided via GET
    if (empty($email) && !empty($lead['email'])) {
        $email = $lead['email'];
    }
} else {
    die('No lead specified.');
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $to      = trim($_POST['to'] ?? '');
    $cc      = trim($_POST['cc'] ?? '');
    $bcc     = trim($_POST['bcc'] ?? '');
    $subject = trim($_POST['subject'] ?? '');
    $body    = trim($_POST['body'] ?? '');

    if (empty($to) || empty($subject) || empty($body)) {
        $error = 'To, Subject, and Message are required.';
    } elseif (!$phpmailer_available) {
        $error = 'PHPMailer files are missing. Please contact system administrator.';
    } elseif (!$smtp_configured) {
        $show_smtp_modal = true;
    } else {
        // Decrypt password if coming from database
        $password = $smtp_settings['pass'] ?? '';
        if (isset($db_settings) && $db_settings) {
            $password = openssl_decrypt($db_settings['smtp_pass'], 'AES-128-ECB', APP_SECRET_KEY);
        }
        
        // Send email using PHPMailer
        $mail = new PHPMailer(true);
        $email_status = 'failed';
        $error_message = '';
        
        try {
            // Server settings
            $mail->isSMTP();
            $mail->Host       = $smtp_settings['host'];
            $mail->SMTPAuth   = true;
            $mail->Username   = $smtp_settings['user'];
            $mail->Password   = $password;
            
            // Set encryption based on selection
            if ($smtp_settings['encryption'] === 'ssl') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } elseif ($smtp_settings['encryption'] === 'tls') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            } else {
                $mail->SMTPSecure = false;
                $mail->SMTPAutoTLS = false;
            }
            
            $mail->Port       = $smtp_settings['port'];
            $mail->Timeout    = 30;
            
            // Recipients
            $mail->setFrom($smtp_settings['from_email'], $smtp_settings['from_name'] ?: 'SalesCalls CRM');
            $mail->addAddress($to, $lead['name']);
            
            if (!empty($cc)) {
                $mail->addCC($cc);
            }
            if (!empty($bcc)) {
                $mail->addBCC($bcc);
            }
            
            // Content
            $mail->isHTML(false);
            $mail->Subject = $subject;
            $mail->Body    = $body;
            
            $mail->send();
            $email_status = 'sent';
            $success = 'Email sent successfully!';
        } catch (Exception $e) {
            $error_message = $mail->ErrorInfo;
            $error = "Email could not be sent. Error: {$mail->ErrorInfo}";
        }
        
        // Log the email attempt
        $log_stmt = $pdo->prepare("
            INSERT INTO email_logs 
            (user_id, lead_id, recipient_email, recipient_name, subject, body, status, sent_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $log_stmt->execute([
            $_SESSION['user_id'],
            $lead_id,
            $to,
            $lead['name'],
            $subject,
            $body,
            $email_status
        ]);
    }
}

include '../includes/header.php';
?>

<h1>Compose Email to <?= htmlspecialchars($lead['name']) ?></h1>

<?php if ($error): ?>
    <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>
<?php if ($success): ?>
    <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
<?php endif; ?>

<!-- PHPMailer Missing Warning -->
<?php if (!$phpmailer_available && $is_admin): ?>
    <div class="alert alert-error">
        <strong>System Error:</strong> PHPMailer files are missing. Please upload the PHPMailer library to the correct location:
        <ul style="margin-top: 10px; margin-left: 20px;">
            <?php foreach ($phpmailer_missing as $missing): ?>
                <li><code><?= htmlspecialchars($missing) ?></code></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php elseif (!$phpmailer_available && !$is_admin): ?>
    <div class="alert alert-error">
        Email system is not properly configured. Please contact your administrator.
    </div>
<?php endif; ?>

<!-- SMTP Not Configured Warning (shown only if PHPMailer is available) -->
<?php if ($phpmailer_available && !$smtp_configured && $is_admin): ?>
    <div class="alert alert-warning" style="background: #fff3cd; color: #856404; border-color: #ffeeba;">
        <strong>SMTP Not Configured:</strong> You need to configure SMTP settings before you can send emails.
        <a href="../admin/smtp.php" style="margin-left: 10px; display: inline-block; padding: 5px 10px; background: #856404; color: white; text-decoration: none; border-radius: 4px;">Configure Now</a>
    </div>
<?php elseif ($phpmailer_available && !$smtp_configured && !$is_admin): ?>
    <div class="alert alert-warning" style="background: #fff3cd; color: #856404; border-color: #ffeeba;">
        Email sending is not yet configured. Please contact your administrator to set up SMTP settings.
    </div>
<?php endif; ?>

<!-- Main Compose Form (shown only if PHPMailer is available) -->
<?php if ($phpmailer_available): ?>
<div class="card">
    <form method="post" id="composeForm">
        <div class="form-group">
            <label for="to">To</label>
            <input type="email" id="to" name="to" value="<?= htmlspecialchars($email) ?>" readonly class="readonly-input">
        </div>

        <!-- CC / BCC toggle -->
        <div style="margin-bottom: 10px;">
            <button type="button" id="toggleCcBcc" class="btn-secondary btn-small">Add CC/BCC</button>
        </div>

        <div id="ccBccFields" style="display: none;">
            <div class="form-group">
                <label for="cc">CC</label>
                <input type="email" id="cc" name="cc" placeholder="email@example.com">
            </div>
            <div class="form-group">
                <label for="bcc">BCC</label>
                <input type="email" id="bcc" name="bcc" placeholder="email@example.com">
            </div>
        </div>

        <div class="form-group">
            <label for="subject">Subject</label>
            <input type="text" id="subject" name="subject" required <?= !$smtp_configured ? 'disabled' : '' ?>>
        </div>

        <div class="form-group">
            <label for="body">Message</label>
            <textarea id="body" name="body" rows="10" required <?= !$smtp_configured ? 'disabled' : '' ?>></textarea>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn" <?= !$smtp_configured ? 'disabled' : '' ?>>Send</button>
            <a href="history.php?lead_id=<?= $lead_id ?>" class="btn-secondary">View History</a>
            <button type="button" class="btn-secondary" id="cancelBtn">Cancel</button>
        </div>
    </form>
</div>
<?php endif; ?>

<!-- SMTP Not Configured Modal (for when they try to submit without config) -->
<div id="smtpModal" class="modal" style="display: none;">
    <div class="modal-content" style="max-width: 400px;">
        <span class="close" onclick="closeSmtpModal()">&times;</span>
        <h3>SMTP Not Configured</h3>
        <?php if ($is_admin): ?>
            <p>Email sending requires SMTP settings. Please configure them to enable this feature.</p>
            <p style="margin: 20px 0;">
                <a href="../admin/smtp.php" target="_blank" class="btn" style="display: inline-block;">Go to SMTP Settings</a>
            </p>
            <p style="font-size: 0.9rem; color: var(--footer-text);">After configuring, refresh this page and try again.</p>
        <?php else: ?>
            <p>Email sending is not yet configured. Please contact your administrator to set up SMTP settings.</p>
        <?php endif; ?>
        <div style="text-align: right;">
            <button class="btn-secondary" onclick="closeSmtpModal()">Close</button>
        </div>
    </div>
</div>

<style>
.readonly-input {
    background-color: var(--input-disabled-bg, #f0f0f0);
    color: var(--text-color, #333);
    cursor: not-allowed;
    border: 1px solid var(--input-border, #ccc);
}
.modal {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.5);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 1000;
}
.modal-content {
    background: var(--card-bg, #fff);
    padding: 20px;
    border-radius: 8px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.2);
    max-width: 400px;
    width: 90%;
}
.modal .close {
    float: right;
    font-size: 1.5rem;
    font-weight: bold;
    cursor: pointer;
    color: var(--footer-text);
}
.modal .close:hover {
    color: var(--text-color);
}
.modal .btn {
    display: inline-block;
    text-decoration: none;
}
.alert-warning {
    background: #fff3cd;
    color: #856404;
    border: 1px solid #ffeeba;
    padding: 15px;
    border-radius: 5px;
    margin-bottom: 20px;
}
button:disabled, input:disabled, textarea:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}
</style>

<script>
// CC/BCC toggle
document.getElementById('toggleCcBcc')?.addEventListener('click', function() {
    var fields = document.getElementById('ccBccFields');
    if (fields.style.display === 'none') {
        fields.style.display = 'block';
        this.textContent = 'Hide CC/BCC';
    } else {
        fields.style.display = 'none';
        this.textContent = 'Add CC/BCC';
    }
});

// SMTP modal handling
<?php if (isset($show_smtp_modal) && $show_smtp_modal): ?>
document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('smtpModal').style.display = 'flex';
});
<?php endif; ?>

function closeSmtpModal() {
    document.getElementById('smtpModal').style.display = 'none';
}

// Cancel confirmation
document.getElementById('cancelBtn')?.addEventListener('click', function() {
    if (confirm('Are you sure you want to cancel? Any unsaved changes will be lost.')) {
        window.close();
    }
});

// Warn before closing tab if form is dirty
let formDirty = false;
document.querySelectorAll('#composeForm input, #composeForm textarea').forEach(field => {
    if (!field.disabled) {
        field.addEventListener('change', () => formDirty = true);
        field.addEventListener('input', () => formDirty = true);
    }
});
window.addEventListener('beforeunload', function(e) {
    if (formDirty) {
        e.preventDefault();
        e.returnValue = '';
    }
});
</script>

<?php include '../includes/footer.php'; ?>