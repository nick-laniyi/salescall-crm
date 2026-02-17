<?php
require_once 'includes/auth.php';
require_once 'includes/config.php';
require_once 'includes/functions.php';

$action = $_GET['action'] ?? 'view';
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$error = '';
$success = '';

// Handle form submission for add/edit
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $company = trim($_POST['company'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $status = $_POST['status'] ?? 'new';
    $notes = trim($_POST['notes'] ?? '');

    if (empty($name)) {
        $error = 'Lead name is required.';
    } else {
        if ($action === 'add') {
            $stmt = $pdo->prepare("INSERT INTO leads (user_id, name, company, phone, email, status, notes) VALUES (?, ?, ?, ?, ?, ?, ?)");
            if ($stmt->execute([$_SESSION['user_id'], $name, $company, $phone, $email, $status, $notes])) {
                $newId = $pdo->lastInsertId();
                header("Location: lead.php?id=$newId&added=1");
                exit;
            } else {
                $error = 'Failed to add lead.';
            }
        } elseif ($action === 'edit' && $id) {
            // Check edit permission
            if (!canEditLead($pdo, $id, $_SESSION['user_id'])) {
                die('You do not have permission to edit this lead.');
            }
            $stmt = $pdo->prepare("UPDATE leads SET name=?, company=?, phone=?, email=?, status=?, notes=? WHERE id=?");
            if ($stmt->execute([$name, $company, $phone, $email, $status, $notes, $id])) {
                header("Location: lead.php?id=$id&updated=1");
                exit;
            } else {
                $error = 'Failed to update lead.';
            }
        }
    }
}

// Fetch lead data for editing or viewing
$lead = null;
if ($id) {
    $stmt = $pdo->prepare("SELECT * FROM leads WHERE id = ?");
    $stmt->execute([$id]);
    $lead = $stmt->fetch();
    if (!$lead && $action !== 'add') {
        die('Lead not found.');
    }
    // Check view permission if viewing/editing existing lead
    if ($lead && !canViewLead($pdo, $id, $_SESSION['user_id'])) {
        die('You do not have permission to view this lead.');
    }
}

// For add action, we don't have lead data
if ($action === 'add') {
    $lead = [
        'id' => 0,
        'user_id' => $_SESSION['user_id'],
        'name' => '',
        'company' => '',
        'phone' => '',
        'email' => '',
        'status' => 'new',
        'notes' => ''
    ];
}

// Fetch call history if viewing a lead
$calls = [];
if ($id && $action === 'view' && $lead) {
    $stmt = $pdo->prepare("SELECT * FROM calls WHERE lead_id = ? ORDER BY created_at DESC");
    $stmt->execute([$id]);
    $calls = $stmt->fetchAll();
}

include 'includes/header.php';
?>

<?php if ($action === 'add' || $action === 'edit'): ?>
    <h1><?= $action === 'add' ? 'Add New Lead' : 'Edit Lead' ?></h1>

    <?php if ($error): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="card">
        <form method="post">
            <div class="form-group">
                <label for="name">Lead Name *</label>
                <input type="text" id="name" name="name" value="<?= htmlspecialchars($lead['name']) ?>" required>
            </div>
            <div class="form-group">
                <label for="company">Company</label>
                <input type="text" id="company" name="company" value="<?= htmlspecialchars($lead['company']) ?>">
            </div>
            <div class="form-group">
                <label for="phone">Phone</label>
                <input type="text" id="phone" name="phone" value="<?= htmlspecialchars($lead['phone']) ?>">
            </div>
            <div class="form-group">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" value="<?= htmlspecialchars($lead['email']) ?>">
            </div>
            <div class="form-group">
                <label for="status">Status</label>
                <select id="status" name="status">
                    <option value="new" <?= $lead['status'] === 'new' ? 'selected' : '' ?>>New</option>
                    <option value="contacted" <?= $lead['status'] === 'contacted' ? 'selected' : '' ?>>Contacted</option>
                    <option value="interested" <?= $lead['status'] === 'interested' ? 'selected' : '' ?>>Interested</option>
                    <option value="not_interested" <?= $lead['status'] === 'not_interested' ? 'selected' : '' ?>>Not Interested</option>
                    <option value="converted" <?= $lead['status'] === 'converted' ? 'selected' : '' ?>>Converted</option>
                </select>
            </div>
            <div class="form-group">
                <label for="notes">Notes</label>
                <textarea id="notes" name="notes" rows="4"><?= htmlspecialchars($lead['notes']) ?></textarea>
            </div>
            <button type="submit" class="btn"><?= $action === 'add' ? 'Add Lead' : 'Update Lead' ?></button>
            <a href="leads.php" class="btn-secondary">Cancel</a>
        </form>
    </div>

<?php elseif ($action === 'view' && $lead): ?>
    <h1><?= htmlspecialchars($lead['name']) ?></h1>
    
    <?php if (isset($_GET['added'])): ?>
        <div class="alert alert-success">Lead added successfully.</div>
    <?php elseif (isset($_GET['updated'])): ?>
        <div class="alert alert-success">Lead updated successfully.</div>
    <?php elseif (isset($_GET['call_logged'])): ?>
        <div class="alert alert-success">Call logged successfully.</div>
    <?php endif; ?>

    <div class="card">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <h2>Lead Details</h2>
            <div>
                <?php if (canEditLead($pdo, $lead['id'], $_SESSION['user_id'])): ?>
                    <a href="lead.php?action=edit&id=<?= $lead['id'] ?>" class="btn-secondary">Edit</a>
                <?php endif; ?>
                <a href="log-call.php?lead_id=<?= $lead['id'] ?>" class="btn">Log Call</a>
            </div>
        </div>

        <table class="table" style="width: auto;">
            <tr>
                <th>Owner</th>
                <td><?= $lead['user_id'] == $_SESSION['user_id'] ? 'You' : 'Shared with you' ?></td>
            </tr>
            <tr>
                <th>Company</th>
                <td><?= htmlspecialchars($lead['company'] ?: '—') ?></td>
            </tr>
            <tr>
                <th>Phone</th>
                <td>
                    <?php if (!empty($lead['phone'])): ?>
                        <div class="contact-actions">
                            <a href="tel:<?= urlencode($lead['phone']) ?>" class="contact-icon" title="Call">📞</a>
                            <a href="https://wa.me/<?= preg_replace('/[^0-9]/', '', $lead['phone']) ?>" target="_blank" class="contact-icon whatsapp" title="WhatsApp">💬</a>
                            <?= htmlspecialchars($lead['phone']) ?>
                        </div>
                    <?php else: ?>
                        —
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th>Email</th>
                <td>
                    <?php if (!empty($lead['email'])): ?>
                        <div class="contact-actions">
                            <span class="contact-icon email copy-email" data-email="<?= htmlspecialchars($lead['email']) ?>" title="Copy email">📧</span>
                            <?= htmlspecialchars($lead['email']) ?>
                        </div>
                    <?php else: ?>
                        —
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th>Status</th>
                <td><?= htmlspecialchars($lead['status']) ?></td>
            </tr>
            <tr>
                <th>Notes</th>
                <td><?= nl2br(htmlspecialchars($lead['notes'] ?: '—')) ?></td>
            </tr>
            <tr>
                <th>Created</th>
                <td><?= date('F j, Y g:i a', strtotime($lead['created_at'])) ?></td>
            </tr>
            <tr>
                <th>Last Updated</th>
                <td><?= date('F j, Y g:i a', strtotime($lead['updated_at'])) ?></td>
            </tr>
        </table>
    </div>

    <div class="card">
        <h2>Call History</h2>
        <?php if (count($calls) > 0): ?>
            <table class="table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Outcome</th>
                        <th>Duration (sec)</th>
                        <th>Follow-up</th>
                        <th>Notes</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($calls as $call): ?>
                    <tr>
                        <td><?= date('M d, Y H:i', strtotime($call['created_at'])) ?></td>
                        <td><?= htmlspecialchars($call['outcome']) ?></td>
                        <td><?= htmlspecialchars($call['duration']) ?></td>
                        <td><?= htmlspecialchars($call['follow_up_date'] ?: '—') ?></td>
                        <td><?= nl2br(htmlspecialchars($call['notes'] ?: '—')) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p>No calls logged yet. <a href="log-call.php?lead_id=<?= $lead['id'] ?>">Log your first call</a>.</p>
        <?php endif; ?>
    </div>

    <!-- Sharing Section (only visible to owner) -->
    <?php if ($lead['user_id'] == $_SESSION['user_id']): ?>
    <div class="card">
        <h2>Sharing</h2>
        <div id="share-list"></div>
        
        <h3>Share with another user</h3>
        <form id="share-form">
            <input type="hidden" name="lead_id" value="<?= $lead['id'] ?>">
            <div class="form-group">
                <label for="user_id">User</label>
                <select id="user_id" name="user_id" required>
                    <option value="">Select user...</option>
                </select>
            </div>
            <div class="form-group">
                <label for="permission">Permission</label>
                <select id="permission" name="permission">
                    <option value="view">View only</option>
                    <option value="edit">View and edit</option>
                </select>
            </div>
            <button type="submit" class="btn">Add Share</button>
        </form>
    </div>

    <script>
    // Load users and current shares
    document.addEventListener('DOMContentLoaded', function() {
        loadShares();
        loadUsers();
    });

    function loadShares() {
        fetch('share.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=list&lead_id=<?= $lead['id'] ?>'
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                let html = '<h4>Currently shared with:</h4><ul>';
                data.shares.forEach(share => {
                    html += `<li>${share.name} (${share.email}) - ${share.permission} 
                            <button onclick="removeShare(${share.user_id})">Remove</button></li>`;
                });
                html += '</ul>';
                document.getElementById('share-list').innerHTML = html;
            }
        });
    }

    function loadUsers() {
        fetch('share.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=get_users'
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                let select = document.getElementById('user_id');
                data.users.forEach(user => {
                    let option = document.createElement('option');
                    option.value = user.id;
                    option.textContent = user.name + ' (' + user.email + ')';
                    select.appendChild(option);
                });
            }
        });
    }

    document.getElementById('share-form').addEventListener('submit', function(e) {
        e.preventDefault();
        const formData = new FormData(this);
        formData.append('action', 'add');
        fetch('share.php', {
            method: 'POST',
            body: new URLSearchParams(formData)
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('Shared successfully');
                loadShares();
            } else {
                alert('Error: ' + data.error);
            }
        });
    });

    function removeShare(userId) {
        if (!confirm('Remove share from this user?')) return;
        const formData = new FormData();
        formData.append('action', 'remove');
        formData.append('lead_id', '<?= $lead['id'] ?>');
        formData.append('user_id', userId);
        fetch('share.php', {
            method: 'POST',
            body: new URLSearchParams(formData)
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                loadShares();
            } else {
                alert('Error: ' + data.error);
            }
        });
    }
    </script>
    <?php endif; ?>

<?php endif; ?>

<!-- Add contact action styles and copy email functionality -->
<style>
/* Contact actions */
.contact-actions {
    display: flex;
    align-items: center;
    gap: 8px;
}

.contact-icon {
    font-size: 1.2rem;
    text-decoration: none;
    cursor: pointer;
    opacity: 0.7;
    transition: opacity 0.2s;
}

.contact-icon:hover {
    opacity: 1;
}

.contact-icon.whatsapp {
    color: #25D366;
}

.contact-icon.email {
    color: #333;
}
</style>

<script>
// Copy email functionality
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.copy-email').forEach(icon => {
        icon.addEventListener('click', function(e) {
            e.preventDefault();
            const email = this.dataset.email;
            navigator.clipboard.writeText(email).then(() => {
                showNotification('Email copied!', 'success');
            }).catch(() => {
                alert('Could not copy email');
            });
        });
    });
});

// Notification helper (copied from leads.php)
function showNotification(message, type) {
    const notification = document.createElement('div');
    notification.className = `alert alert-${type}`;
    notification.style.position = 'fixed';
    notification.style.top = '20px';
    notification.style.right = '20px';
    notification.style.zIndex = '9999';
    notification.style.minWidth = '200px';
    notification.innerText = message;
    document.body.appendChild(notification);
    
    setTimeout(() => {
        notification.remove();
    }, 3000);
}
</script>

<?php include 'includes/footer.php'; ?>