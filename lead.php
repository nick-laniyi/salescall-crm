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
    $project_id = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;

    if (empty($name)) {
        $error = 'Lead name is required.';
    } else {
        if ($action === 'add') {
            $stmt = $pdo->prepare("INSERT INTO leads (user_id, name, company, phone, email, status, notes, project_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            if ($stmt->execute([$_SESSION['user_id'], $name, $company, $phone, $email, $status, $notes, $project_id])) {
                $newId = $pdo->lastInsertId();

                // Handle custom fields (project columns)
                if (!empty($_POST['custom'])) {
                    foreach ($_POST['custom'] as $colId => $value) {
                        if (trim($value) !== '') {
                            $stmt = $pdo->prepare("INSERT INTO lead_column_values (lead_id, column_id, value) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)");
                            $stmt->execute([$newId, $colId, $value]);
                        } else {
                            $stmt = $pdo->prepare("DELETE FROM lead_column_values WHERE lead_id = ? AND column_id = ?");
                            $stmt->execute([$newId, $colId]);
                        }
                    }
                }

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
            $stmt = $pdo->prepare("UPDATE leads SET name=?, company=?, phone=?, email=?, status=?, notes=?, project_id=? WHERE id=?");
            if ($stmt->execute([$name, $company, $phone, $email, $status, $notes, $project_id, $id])) {

                // Handle custom fields
                if (!empty($_POST['custom'])) {
                    foreach ($_POST['custom'] as $colId => $value) {
                        if (trim($value) !== '') {
                            $stmt = $pdo->prepare("INSERT INTO lead_column_values (lead_id, column_id, value) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)");
                            $stmt->execute([$id, $colId, $value]);
                        } else {
                            $stmt = $pdo->prepare("DELETE FROM lead_column_values WHERE lead_id = ? AND column_id = ?");
                            $stmt->execute([$id, $colId]);
                        }
                    }
                }

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
        'notes' => '',
        'project_id' => 0
    ];
}

// Get all projects (for dropdown)
$projects = [];
$stmt = $pdo->prepare("SELECT id, name FROM projects WHERE user_id = ? ORDER BY name");
$stmt->execute([$_SESSION['user_id']]);
$projects = $stmt->fetchAll();

// For existing lead, get its project
$currentProjectId = $lead ? (int)$lead['project_id'] : 0;

// If project selected via GET (for dynamic loading in add mode) or from lead
$selectedProjectId = $currentProjectId;
if (isset($_GET['project_id'])) {
    $selectedProjectId = (int)$_GET['project_id'];
}

// Load project columns for the selected project
$projectColumns = [];
if ($selectedProjectId > 0) {
    $stmt = $pdo->prepare("SELECT * FROM project_columns WHERE project_id = ? ORDER BY sort_order, id");
    $stmt->execute([$selectedProjectId]);
    $projectColumns = $stmt->fetchAll();
}

// Load existing custom values for the lead (if editing/viewing)
$customValues = [];
if ($id > 0 && $selectedProjectId > 0) {
    $stmt = $pdo->prepare("SELECT column_id, value FROM lead_column_values WHERE lead_id = ?");
    $stmt->execute([$id]);
    foreach ($stmt->fetchAll() as $row) {
        $customValues[$row['column_id']] = $row['value'];
    }
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

            <!-- Project selection -->
            <div class="form-group">
                <label for="project_id">Project (Folder)</label>
                <select id="project_id" name="project_id" onchange="loadProjectColumns(this.value)">
                    <option value="">-- Select Project --</option>
                    <?php foreach ($projects as $p): ?>
                        <option value="<?= $p['id'] ?>" <?= $selectedProjectId == $p['id'] ? 'selected' : '' ?>><?= htmlspecialchars($p['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Dynamic custom fields container -->
            <div id="custom-fields-container">
                <?php if (!empty($projectColumns)): ?>
                    <h3>Custom Fields</h3>
                    <?php foreach ($projectColumns as $col): ?>
                        <div class="form-group">
                            <label for="col_<?= $col['id'] ?>"><?= htmlspecialchars($col['name']) ?></label>
                            <?php if ($col['column_type'] === 'text'): ?>
                                <input type="text" id="col_<?= $col['id'] ?>" name="custom[<?= $col['id'] ?>]" value="<?= isset($customValues[$col['id']]) ? htmlspecialchars($customValues[$col['id']]) : '' ?>">
                            <?php elseif ($col['column_type'] === 'number'): ?>
                                <input type="number" id="col_<?= $col['id'] ?>" name="custom[<?= $col['id'] ?>]" value="<?= isset($customValues[$col['id']]) ? htmlspecialchars($customValues[$col['id']]) : '' ?>">
                            <?php elseif ($col['column_type'] === 'date'): ?>
                                <input type="date" id="col_<?= $col['id'] ?>" name="custom[<?= $col['id'] ?>]" value="<?= isset($customValues[$col['id']]) ? htmlspecialchars($customValues[$col['id']]) : '' ?>">
                            <?php elseif ($col['column_type'] === 'select' && $col['options']): ?>
                                <?php $options = json_decode($col['options'], true); ?>
                                <select id="col_<?= $col['id'] ?>" name="custom[<?= $col['id'] ?>]">
                                    <option value="">-- Select --</option>
                                    <?php foreach ($options as $opt): ?>
                                        <option value="<?= htmlspecialchars($opt) ?>" <?= (isset($customValues[$col['id']]) && $customValues[$col['id']] === $opt) ? 'selected' : '' ?>><?= htmlspecialchars($opt) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <button type="submit" class="btn"><?= $action === 'add' ? 'Add Lead' : 'Update Lead' ?></button>
            <a href="leads.php" class="btn-secondary">Cancel</a>
        </form>
    </div>

    <script>
    function loadProjectColumns(projectId) {
        if (!projectId) {
            document.getElementById('custom-fields-container').innerHTML = '';
            return;
        }
        fetch('get_project_columns.php?project_id=' + projectId)
            .then(response => response.json())
            .then(columns => {
                let html = '<h3>Custom Fields</h3>';
                columns.forEach(col => {
                    html += '<div class="form-group">';
                    html += '<label for="col_' + col.id + '">' + col.name + '</label>';
                    if (col.column_type === 'text') {
                        html += '<input type="text" id="col_' + col.id + '" name="custom[' + col.id + ']">';
                    } else if (col.column_type === 'number') {
                        html += '<input type="number" id="col_' + col.id + '" name="custom[' + col.id + ']">';
                    } else if (col.column_type === 'date') {
                        html += '<input type="date" id="col_' + col.id + '" name="custom[' + col.id + ']">';
                    } else if (col.column_type === 'select' && col.options) {
                        let options = JSON.parse(col.options);
                        html += '<select id="col_' + col.id + '" name="custom[' + col.id + ']">';
                        html += '<option value="">-- Select --</option>';
                        options.forEach(opt => {
                            html += '<option value="' + opt + '">' + opt + '</option>';
                        });
                        html += '</select>';
                    }
                    html += '</div>';
                });
                document.getElementById('custom-fields-container').innerHTML = html;
            })
            .catch(error => console.error('Error loading columns:', error));
    }
    </script>

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
                <th>Project</th>
                <td>
                    <?php
                    if ($lead['project_id']) {
                        $stmt = $pdo->prepare("SELECT name FROM projects WHERE id = ?");
                        $stmt->execute([$lead['project_id']]);
                        $projectName = $stmt->fetchColumn();
                        echo htmlspecialchars($projectName ?: '—');
                    } else {
                        echo '—';
                    }
                    ?>
                </td>
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

    <?php if (!empty($projectColumns) && $action === 'view'): ?>
        <div class="card">
            <h2>Custom Fields</h2>
            <table class="table" style="width: auto;">
                <?php foreach ($projectColumns as $col): ?>
                    <tr>
                        <th><?= htmlspecialchars($col['name']) ?></th>
                        <td><?= isset($customValues[$col['id']]) ? htmlspecialchars($customValues[$col['id']]) : '—' ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        </div>
    <?php endif; ?>

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