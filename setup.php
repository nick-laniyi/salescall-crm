<?php
require_once 'includes/auth.php';
require_once 'includes/config.php';
require_once 'includes/functions.php';

// Only admin can access setup
if (!isAdmin()) {
    header('Location: dashboard.php');
    exit;
}

// If setup is already completed, redirect to dashboard
if (isset($_SESSION['setup_completed']) && $_SESSION['setup_completed']) {
    header('Location: dashboard.php');
    exit;
}

$step = isset($_GET['step']) ? (int)$_GET['step'] : 1;
$error = '';
$success = '';

// Step 1: Welcome & Introduction
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['start'])) {
    $step = 2;
}

// Step 2: Add Team Members (optional)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_team'])) {
    $names = $_POST['name'] ?? [];
    $emails = $_POST['email'] ?? [];
    $passwords = $_POST['password'] ?? [];
    
    $added = 0;
    $errors = [];
    
    for ($i = 0; $i < count($names); $i++) {
        if (empty($names[$i]) || empty($emails[$i]) || empty($passwords[$i])) {
            continue; // Skip empty rows
        }
        
        // Validate email
        if (!filter_var($emails[$i], FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Invalid email: {$emails[$i]}";
            continue;
        }
        
        // Check if email exists
        $check = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $check->execute([$emails[$i]]);
        if ($check->fetch()) {
            $errors[] = "Email already exists: {$emails[$i]}";
            continue;
        }
        
        // Create user
        $hashed = password_hash($passwords[$i], PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO users (name, email, password_hash, role, setup_completed, created_at) VALUES (?, ?, ?, 'user', 1, NOW())");
        
        if ($stmt->execute([$names[$i], $emails[$i], $hashed])) {
            $added++;
        } else {
            $errors[] = "Failed to add user: {$emails[$i]}";
        }
    }
    
    if ($added > 0) {
        $success = "$added team member(s) added successfully.";
    }
    if (!empty($errors)) {
        $error = implode('<br>', $errors);
    }
    
    // Stay on step 2 to add more if needed, or provide next button
}

// Step 3: Quick Guide / Tour
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['complete_setup'])) {
    // Mark setup as completed
    $stmt = $pdo->prepare("UPDATE users SET setup_completed = 1 WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    
    $_SESSION['setup_completed'] = true;
    
    header('Location: dashboard.php?welcome=1');
    exit;
}

include 'includes/header.php';
?>

<div class="setup-container" style="max-width: 800px; margin: 40px auto;">
    <div class="card">
        <div style="text-align: center; margin-bottom: 30px;">
            <h1>🚀 Welcome to SalesCalls CRM</h1>
            <p class="lead" style="color: var(--footer-text);">Let's get you set up for success</p>
        </div>
        
        <!-- Progress Steps -->
        <div style="display: flex; justify-content: space-between; margin-bottom: 40px; position: relative;">
            <div style="flex: 1; text-align: center; position: relative;">
                <div style="width: 30px; height: 30px; border-radius: 50%; background: <?= $step >= 1 ? 'var(--link-color)' : '#ddd' ?>; color: white; line-height: 30px; margin: 0 auto 10px;">1</div>
                <div style="font-weight: <?= $step >= 1 ? 'bold' : 'normal' ?>;">Welcome</div>
            </div>
            <div style="flex: 1; text-align: center;">
                <div style="width: 30px; height: 30px; border-radius: 50%; background: <?= $step >= 2 ? 'var(--link-color)' : '#ddd' ?>; color: white; line-height: 30px; margin: 0 auto 10px;">2</div>
                <div style="font-weight: <?= $step >= 2 ? 'bold' : 'normal' ?>;">Team</div>
            </div>
            <div style="flex: 1; text-align: center;">
                <div style="width: 30px; height: 30px; border-radius: 50%; background: <?= $step >= 3 ? 'var(--link-color)' : '#ddd' ?>; color: white; line-height: 30px; margin: 0 auto 10px;">3</div>
                <div style="font-weight: <?= $step >= 3 ? 'bold' : 'normal' ?>;">Guide</div>
            </div>
        </div>
        
        <?php if ($error): ?>
            <div class="alert alert-error"><?= $error ?></div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="alert alert-success"><?= $success ?></div>
        <?php endif; ?>
        
        <!-- Step 1: Welcome -->
        <?php if ($step === 1): ?>
            <div style="text-align: center;">
                <div style="font-size: 4rem; margin-bottom: 20px;">🎉</div>
                <h2>Installation Complete!</h2>
                <p style="font-size: 1.1rem; margin: 20px 0; line-height: 1.6;">
                    Your CRM is now ready to use. In the next few steps, we'll help you:
                </p>
                
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin: 30px 0;">
                    <div style="padding: 20px; background: var(--table-header-bg); border-radius: 8px;">
                        <div style="font-size: 2rem; margin-bottom: 10px;">👥</div>
                        <h3>Add Team Members</h3>
                        <p>Invite your sales team to collaborate</p>
                    </div>
                    <div style="padding: 20px; background: var(--table-header-bg); border-radius: 8px;">
                        <div style="font-size: 2rem; margin-bottom: 10px;">📧</div>
                        <h3>Configure Email</h3>
                        <p>Set up SMTP to send emails from the CRM</p>
                    </div>
                    <div style="padding: 20px; background: var(--table-header-bg); border-radius: 8px;">
                        <div style="font-size: 2rem; margin-bottom: 10px;">📊</div>
                        <h3>Create Projects</h3>
                        <p>Organize leads into folders with custom fields</p>
                    </div>
                </div>
                
                <form method="post">
                    <button type="submit" name="start" class="btn" style="padding: 12px 40px;">Let's Get Started →</button>
                    <a href="dashboard.php" class="btn-secondary" style="margin-left: 10px;">Skip Setup</a>
                </form>
                <p style="margin-top: 20px; color: var(--footer-text);">
                    <small>You can always add team members later in Admin → Team</small>
                </p>
            </div>
        
        <!-- Step 2: Add Team Members -->
        <?php elseif ($step === 2): ?>
            <div>
                <h2>Add Your Team Members</h2>
                <p>Invite your sales team to collaborate. They'll receive login credentials via email (you'll need to configure SMTP first).</p>
                
                <div class="info-box" style="background: #e7f3ff; border-left: 4px solid #2196F3; padding: 15px; margin: 20px 0; border-radius: 4px;">
                    <strong>💡 Pro Tip:</strong> You can configure SMTP settings in <strong>Admin → SMTP Settings</strong> to enable email invitations and send emails from the CRM.
                </div>
                
                <form method="post" id="teamForm">
                    <div style="margin-bottom: 20px;">
                        <button type="button" id="addRowBtn" class="btn-secondary btn-small">+ Add Another Team Member</button>
                    </div>
                    
                    <div id="team-rows">
                        <!-- Row 1 -->
                        <div class="team-row" style="display: flex; gap: 10px; margin-bottom: 10px; align-items: center;">
                            <input type="text" name="name[]" placeholder="Full Name" style="flex: 2;">
                            <input type="email" name="email[]" placeholder="Email Address" style="flex: 2;">
                            <input type="password" name="password[]" placeholder="Password" style="flex: 1;">
                            <button type="button" class="btn-danger btn-small remove-row" style="display: none;">✕</button>
                        </div>
                    </div>
                    
                    <div class="info-box" style="background: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin: 20px 0; border-radius: 4px;">
                        <strong>⚠️ Note:</strong> Passwords will be stored securely (hashed). New users will be created with <strong>setup_completed = 1</strong> and can log in immediately.
                    </div>
                    
                    <div style="display: flex; justify-content: space-between; margin-top: 20px;">
                        <a href="?step=1" class="btn-secondary">← Back</a>
                        <div>
                            <button type="submit" name="add_team" class="btn">Save Team Members</button>
                            <a href="?step=3" class="btn-secondary" style="margin-left: 10px;">Skip →</a>
                        </div>
                    </div>
                </form>
            </div>
        
        <!-- Step 3: Quick Guide -->
        <?php elseif ($step === 3): ?>
            <div>
                <h2>Quick Start Guide</h2>
                <p>Here are the next steps to make the most of your CRM:</p>
                
                <div style="margin: 30px 0;">
                    <div style="display: flex; gap: 20px; margin-bottom: 20px; padding: 15px; background: var(--table-header-bg); border-radius: 8px;">
                        <div style="font-size: 2rem;">1️⃣</div>
                        <div>
                            <h3>Configure Email Settings</h3>
                            <p>Go to <strong>Admin → SMTP Settings</strong> to set up your email provider. This enables sending emails from the CRM and team invitations.</p>
                            <a href="admin/smtp.php" target="_blank" class="btn-secondary btn-small">Configure SMTP</a>
                        </div>
                    </div>
                    
                    <div style="display: flex; gap: 20px; margin-bottom: 20px; padding: 15px; background: var(--table-header-bg); border-radius: 8px;">
                        <div style="font-size: 2rem;">2️⃣</div>
                        <div>
                            <h3>Create Your First Project</h3>
                            <p>Projects help organize leads. Each project can have its own custom fields (like Industry, Location, Budget).</p>
                            <a href="admin/projects.php" target="_blank" class="btn-secondary btn-small">Manage Projects</a>
                        </div>
                    </div>
                    
                    <div style="display: flex; gap: 20px; margin-bottom: 20px; padding: 15px; background: var(--table-header-bg); border-radius: 8px;">
                        <div style="font-size: 2rem;">3️⃣</div>
                        <div>
                            <h3>Import or Add Leads</h3>
                            <p>Upload CSV files with your existing leads, or add them manually one by one.</p>
                            <a href="import.php" target="_blank" class="btn-secondary btn-small">Import Leads</a>
                            <a href="lead.php?action=add" class="btn-secondary btn-small" style="margin-left: 10px;">Add Lead</a>
                        </div>
                    </div>
                    
                    <div style="display: flex; gap: 20px; margin-bottom: 20px; padding: 15px; background: var(--table-header-bg); border-radius: 8px;">
                        <div style="font-size: 2rem;">4️⃣</div>
                        <div>
                            <h3>Set Up Cron Jobs</h3>
                            <p>For follow-up reminders to work, add this to your server's crontab:</p>
                            <code style="display: block; padding: 10px; background: var(--input-bg); margin: 10px 0;">0 8 * * * /usr/bin/php <?= realpath(__DIR__) ?>/cron/send-reminders.php</code>
                        </div>
                    </div>
                </div>
                
                <form method="post" style="text-align: center; margin-top: 30px;">
                    <button type="submit" name="complete_setup" class="btn" style="padding: 12px 40px;">Complete Setup & Go to Dashboard</button>
                </form>
            </div>
        <?php endif; ?>
    </div>
</div>

<style>
.team-row {
    transition: all 0.3s ease;
}
.remove-row {
    opacity: 0.5;
    transition: opacity 0.2s;
}
.remove-row:hover {
    opacity: 1;
}
.info-box {
    font-size: 0.95rem;
}
code {
    font-family: 'Courier New', monospace;
    border-radius: 4px;
}
</style>

<script>
// Add new team member row
document.getElementById('addRowBtn')?.addEventListener('click', function() {
    const container = document.getElementById('team-rows');
    const rows = document.querySelectorAll('.team-row');
    
    const newRow = document.createElement('div');
    newRow.className = 'team-row';
    newRow.style.cssText = 'display: flex; gap: 10px; margin-bottom: 10px; align-items: center;';
    newRow.innerHTML = `
        <input type="text" name="name[]" placeholder="Full Name" style="flex: 2;">
        <input type="email" name="email[]" placeholder="Email Address" style="flex: 2;">
        <input type="password" name="password[]" placeholder="Password" style="flex: 1;">
        <button type="button" class="btn-danger btn-small remove-row">✕</button>
    `;
    container.appendChild(newRow);
    
    // Show remove buttons on all rows if more than one
    if (rows.length + 1 > 1) {
        document.querySelectorAll('.remove-row').forEach(btn => {
            btn.style.display = 'block';
        });
    }
});

// Remove team member row
document.addEventListener('click', function(e) {
    if (e.target.classList.contains('remove-row')) {
        const row = e.target.closest('.team-row');
        row.remove();
        
        // If only one row left, hide its remove button
        const rows = document.querySelectorAll('.team-row');
        if (rows.length === 1) {
            rows[0].querySelector('.remove-row').style.display = 'none';
        }
    }
});

// Hide remove button on first row initially
document.addEventListener('DOMContentLoaded', function() {
    const rows = document.querySelectorAll('.team-row');
    if (rows.length === 1) {
        rows[0].querySelector('.remove-row').style.display = 'none';
    }
});
</script>

<?php include 'includes/footer.php'; ?>