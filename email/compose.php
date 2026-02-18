<?php
require_once '../includes/auth.php';
require_once '../includes/config.php';
require_once '../includes/functions.php';

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

// Check if SMTP settings are configured (placeholder – implement your own check)
$settings_configured = false; // Replace with actual DB check

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $to      = trim($_POST['to'] ?? '');
    $cc      = trim($_POST['cc'] ?? '');
    $bcc     = trim($_POST['bcc'] ?? '');
    $subject = trim($_POST['subject'] ?? '');
    $body    = trim($_POST['body'] ?? '');

    if (empty($to) || empty($subject) || empty($body)) {
        $error = 'To, Subject, and Message are required.';
    } else {
        if (!$settings_configured) {
            // Instead of error, we'll show a modal via JavaScript
            $show_smtp_modal = true;
        } else {
            // Proceed with sending email using PHPMailer or similar
            // For demo, pretend success
            $success = 'Your message has been sent (demo mode).';
        }
    }
}

include '../includes/header.php';
?>

<h1>Compose Email</h1>

<?php if ($error): ?>
    <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>
<?php if ($success): ?>
    <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
<?php endif; ?>

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
            <input type="text" id="subject" name="subject" required>
        </div>

        <div class="form-group">
            <label for="body">Message</label>
            <textarea id="body" name="body" rows="10" required></textarea>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn">Send</button>
            <button type="button" class="btn-secondary" id="cancelBtn">Cancel</button>
        </div>
    </form>
</div>

<!-- SMTP Not Configured Modal -->
<div id="smtpModal" class="modal" style="display: none;">
    <div class="modal-content" style="max-width: 400px;">
        <span class="close" onclick="closeSmtpModal()">&times;</span>
        <h3>Email Not Enabled</h3>
        <p>Email sending is not yet configured. Please add your SMTP settings in the admin panel to enable this feature.</p>
        <p style="font-size: 0.9rem; color: var(--footer-text);">This is a demo version – no emails will be sent.</p>
        <div style="text-align: right;">
            <button class="btn-secondary" onclick="closeSmtpModal()">OK</button>
        </div>
    </div>
</div>

<style>
/* Improved readonly input for dark mode */
.readonly-input {
    background-color: var(--input-disabled-bg, #f0f0f0);
    color: var(--text-color, #333);
    cursor: not-allowed;
    border: 1px solid var(--input-border, #ccc);
}
/* Modal styling – reuse existing modal styles from leads.php */
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
</style>

<script>
// CC/BCC toggle
document.getElementById('toggleCcBcc').addEventListener('click', function() {
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
document.getElementById('cancelBtn').addEventListener('click', function() {
    if (confirm('Are you sure you want to cancel? Any unsaved changes will be lost.')) {
        window.close();
    }
});

// Optional: warn before closing tab if form is dirty
let formDirty = false;
document.querySelectorAll('#composeForm input, #composeForm textarea').forEach(field => {
    field.addEventListener('change', () => formDirty = true);
    field.addEventListener('input', () => formDirty = true);
});
window.addEventListener('beforeunload', function(e) {
    if (formDirty) {
        e.preventDefault();
        e.returnValue = '';
    }
});
</script>

<?php include '../includes/footer.php'; ?>