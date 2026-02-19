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
                            // Delete empty values
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
    $stmt = $pdo->prepare("SELECT l.*, p.name as project_name FROM leads l LEFT JOIN projects p ON l.project_id = p.id WHERE l.id = ?");
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
        'project_id' => 0,
        'project_name' => ''
    ];
}

// Get all projects the user has access to (owned or shared leads in)
$projects = [];
$accessibleIds = getAccessibleLeadIds($pdo, $_SESSION['user_id']);
if (!empty($accessibleIds)) {
    $ph = implode(',', array_fill(0, count($accessibleIds), '?'));
    $projStmt = $pdo->prepare(
        "SELECT DISTINCT p.id, p.name
         FROM projects p
         WHERE p.user_id = ?
            OR EXISTS (
                SELECT 1 FROM leads l
                WHERE l.project_id = p.id AND l.id IN ($ph)
            )
         ORDER BY p.name"
    );
    $params = [$_SESSION['user_id']];
    $params = array_merge($params, $accessibleIds);
    $projStmt->execute($params);
    $projects = $projStmt->fetchAll();
} else {
    // Only owned projects
    $projStmt = $pdo->prepare("SELECT id, name FROM projects WHERE user_id = ? ORDER BY name");
    $projStmt->execute([$_SESSION['user_id']]);
    $projects = $projStmt->fetchAll();
}

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
    $stmt = $pdo->prepare("SELECT c.*, u.name as user_name FROM calls c LEFT JOIN users u ON c.user_id = u.id WHERE c.lead_id = ? ORDER BY c.created_at DESC");
    $stmt->execute([$id]);
    $calls = $stmt->fetchAll();
}

// Fetch email history if viewing a lead
$emails = [];
if ($id && $action === 'view' && $lead) {
    $stmt = $pdo->prepare("SELECT * FROM email_logs WHERE lead_id = ? ORDER BY sent_at DESC");
    $stmt->execute([$id]);
    $emails = $stmt->fetchAll();
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
                <input type="tel" id="phone" name="phone" value="<?= htmlspecialchars($lead['phone']) ?>">
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
                <select id="project_id" name="project_id" onchange="loadProjectColumns(this.value)" style="width: 100%;">
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
                            <?php elseif ($col['column_type'] === 'email'): ?>
                                <input type="email" id="col_<?= $col['id'] ?>" name="custom[<?= $col['id'] ?>]" value="<?= isset($customValues[$col['id']]) ? htmlspecialchars($customValues[$col['id']]) : '' ?>">
                            <?php elseif ($col['column_type'] === 'phone'): ?>
                                <input type="tel" id="col_<?= $col['id'] ?>" name="custom[<?= $col['id'] ?>]" value="<?= isset($customValues[$col['id']]) ? htmlspecialchars($customValues[$col['id']]) : '' ?>">
                            <?php elseif ($col['column_type'] === 'number'): ?>
                                <input type="number" id="col_<?= $col['id'] ?>" name="custom[<?= $col['id'] ?>]" value="<?= isset($customValues[$col['id']]) ? htmlspecialchars($customValues[$col['id']]) : '' ?>">
                            <?php elseif ($col['column_type'] === 'date'): ?>
                                <input type="date" id="col_<?= $col['id'] ?>" name="custom[<?= $col['id'] ?>]" value="<?= isset($customValues[$col['id']]) ? htmlspecialchars($customValues[$col['id']]) : '' ?>">
                            <?php elseif ($col['column_type'] === 'select' && $col['options']): ?>
                                <?php $options = explode("\n", trim($col['options'])); ?>
                                <select id="col_<?= $col['id'] ?>" name="custom[<?= $col['id'] ?>]">
                                    <option value="">-- Select --</option>
                                    <?php foreach ($options as $opt): ?>
                                        <?php $opt = trim($opt); if (empty($opt)) continue; ?>
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
    // Function to load project columns dynamically
    function loadProjectColumns(projectId) {
        if (!projectId) {
            document.getElementById('custom-fields-container').innerHTML = '';
            return;
        }
        
        // For existing leads, we need to pass lead_id to get existing values
        const leadId = <?= $id ?: 0 ?>;
        const url = leadId ? 'get_project_columns.php?project_id=' + projectId + '&lead_id=' + leadId : 'get_project_columns.php?project_id=' + projectId;
        
        fetch(url)
            .then(response => response.json())
            .then(data => {
                let html = '<h3>Custom Fields</h3>';
                data.columns.forEach(col => {
                    html += '<div class="form-group">';
                    html += '<label for="col_' + col.id + '">' + col.name + '</label>';
                    
                    const value = data.values && data.values[col.id] ? data.values[col.id] : '';
                    
                    if (col.column_type === 'text') {
                        html += '<input type="text" id="col_' + col.id + '" name="custom[' + col.id + ']" value="' + escapeHtml(value) + '">';
                    } else if (col.column_type === 'email') {
                        html += '<input type="email" id="col_' + col.id + '" name="custom[' + col.id + ']" value="' + escapeHtml(value) + '">';
                    } else if (col.column_type === 'phone') {
                        html += '<input type="tel" id="col_' + col.id + '" name="custom[' + col.id + ']" value="' + escapeHtml(value) + '">';
                    } else if (col.column_type === 'number') {
                        html += '<input type="number" id="col_' + col.id + '" name="custom[' + col.id + ']" value="' + escapeHtml(value) + '">';
                    } else if (col.column_type === 'date') {
                        html += '<input type="date" id="col_' + col.id + '" name="custom[' + col.id + ']" value="' + escapeHtml(value) + '">';
                    } else if (col.column_type === 'select' && col.options) {
                        let options = col.options.split('\n');
                        html += '<select id="col_' + col.id + '" name="custom[' + col.id + ']">';
                        html += '<option value="">-- Select --</option>';
                        options.forEach(opt => {
                            opt = opt.trim();
                            if (opt) {
                                html += '<option value="' + escapeHtml(opt) + '" ' + (value === opt ? 'selected' : '') + '>' + escapeHtml(opt) + '</option>';
                            }
                        });
                        html += '</select>';
                    }
                    html += '</div>';
                });
                document.getElementById('custom-fields-container').innerHTML = html;
            })
            .catch(error => console.error('Error loading columns:', error));
    }

    // Helper function to escape HTML
    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
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
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 10px;">
            <h2>Lead Details</h2>
            <div class="action-buttons">
                <?php if (canEditLead($pdo, $lead['id'], $_SESSION['user_id'])): ?>
                    <a href="lead.php?action=edit&id=<?= $lead['id'] ?>" class="btn-secondary">Edit</a>
                <?php endif; ?>
                <a href="log-call.php?lead_id=<?= $lead['id'] ?>" class="btn">Log Call</a>
                <?php if (!empty($lead['email'])): ?>
                    <a href="email/compose.php?lead_id=<?= $lead['id'] ?>&email=<?= urlencode($lead['email']) ?>" class="btn-secondary" target="_blank">Send Email</a>
                <?php endif; ?>
                <a href="email/history.php?lead_id=<?= $lead['id'] ?>" class="btn-secondary">Email History</a>
            </div>
        </div>

        <table class="table" style="width: auto;">
            <tr>
                <th style="width: 150px;">Owner</th>
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
                            <a href="email/compose.php?lead_id=<?= $lead['id'] ?>&email=<?= urlencode($lead['email']) ?>" class="contact-icon" title="Send email" target="_blank">✉️</a>
                            <?= htmlspecialchars($lead['email']) ?>
                        </div>
                    <?php else: ?>
                        —
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th>Status</th>
                <td><span class="status-badge status-<?= str_replace('_', '-', $lead['status']) ?>"><?= ucfirst(str_replace('_', ' ', $lead['status'])) ?></span></td>
            </tr>
            <tr>
                <th>Project</th>
                <td><?= htmlspecialchars($lead['project_name'] ?: '—') ?></td>
            </tr>
            <tr>
                <th>Last Contacted</th>
                <td>
                    <?php 
                    // Get last contact date from calls or email logs
                    $stmt = $pdo->prepare("
                        SELECT MAX(date) as last_contact FROM (
                            SELECT created_at as date FROM calls WHERE lead_id = ?
                            UNION
                            SELECT sent_at as date FROM email_logs WHERE lead_id = ? AND status = 'sent'
                        ) as combined
                    ");
                    $stmt->execute([$lead['id'], $lead['id']]);
                    $lastContact = $stmt->fetchColumn();
                    echo $lastContact ? date('M d, Y', strtotime($lastContact)) : 'Never';
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

    <?php if (!empty($projectColumns)): ?>
        <div class="card">
            <h2>Custom Fields</h2>
            <table class="table" style="width: auto;">
                <?php foreach ($projectColumns as $col): ?>
                    <tr>
                        <th style="width: 150px;"><?= htmlspecialchars($col['name']) ?></th>
                        <td>
                            <?php if (isset($customValues[$col['id']])): ?>
                                <?php if ($col['column_type'] === 'email'): ?>
                                    <div class="contact-actions">
                                        <span class="contact-icon email copy-email" data-email="<?= htmlspecialchars($customValues[$col['id']]) ?>" title="Copy email">📧</span>
                                        <a href="email/compose.php?lead_id=<?= $lead['id'] ?>&email=<?= urlencode($customValues[$col['id']]) ?>" class="contact-icon" title="Send email" target="_blank">✉️</a>
                                        <?= htmlspecialchars($customValues[$col['id']]) ?>
                                    </div>
                                <?php elseif ($col['column_type'] === 'phone'): ?>
                                    <div class="contact-actions">
                                        <a href="tel:<?= urlencode($customValues[$col['id']]) ?>" class="contact-icon" title="Call">📞</a>
                                        <a href="https://wa.me/<?= preg_replace('/[^0-9]/', '', $customValues[$col['id']]) ?>" target="_blank" class="contact-icon whatsapp" title="WhatsApp">💬</a>
                                        <?= htmlspecialchars($customValues[$col['id']]) ?>
                                    </div>
                                <?php else: ?>
                                    <?= htmlspecialchars($customValues[$col['id']]) ?>
                                <?php endif; ?>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
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
                        <th>User</th>
                        <th>Outcome</th>
                        <th>Duration</th>
                        <th>Follow-up</th>
                        <th>Notes</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($calls as $call): ?>
                    <tr>
                        <td><?= date('M d, Y H:i', strtotime($call['created_at'])) ?></td>
                        <td><?= htmlspecialchars($call['user_name'] ?: 'Unknown') ?></td>
                        <td><span class="status-badge status-<?= str_replace('_', '-', $call['outcome']) ?>"><?= ucfirst(str_replace('_', ' ', $call['outcome'])) ?></span></td>
                        <td><?= $call['duration'] ? $call['duration'] . 's' : '—' ?></td>
                        <td><?= $call['follow_up_date'] ? date('M d, Y', strtotime($call['follow_up_date'])) : '—' ?></td>
                        <td><?= nl2br(htmlspecialchars($call['notes'] ?: '—')) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p>No calls logged yet. <a href="log-call.php?lead_id=<?= $lead['id'] ?>">Log your first call</a>.</p>
        <?php endif; ?>
    </div>

    <div class="card">
        <h2>Email History</h2>
        <?php if (count($emails) > 0): ?>
            <table class="table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Recipient</th>
                        <th>Subject</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($emails as $email): ?>
                    <tr>
                        <td><?= date('M d, Y H:i', strtotime($email['sent_at'])) ?></td>
                        <td><?= htmlspecialchars($email['recipient_email']) ?></td>
                        <td><?= htmlspecialchars($email['subject']) ?></td>
                        <td><span class="status-badge status-<?= $email['status'] ?>"><?= ucfirst($email['status']) ?></span></td>
                        <td>
                            <a href="#" class="btn-secondary btn-small view-email" 
                               data-subject="<?= htmlspecialchars($email['subject']) ?>"
                               data-recipient="<?= htmlspecialchars($email['recipient_email']) ?>"
                               data-body="<?= htmlspecialchars($email['body']) ?>"
                               data-date="<?= date('M d, Y H:i', strtotime($email['sent_at'])) ?>">
                                View
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p>No emails sent yet. <?php if (!empty($lead['email'])): ?><a href="email/compose.php?lead_id=<?= $lead['id'] ?>&email=<?= urlencode($lead['email']) ?>" target="_blank">Send your first email</a>.<?php endif; ?></p>
        <?php endif; ?>
    </div>

    <!-- Email View Modal -->
    <div id="emailModal" class="modal" style="display: none;">
        <div class="modal-content" style="max-width: 600px;">
            <span class="close" onclick="closeEmailModal()">&times;</span>
            <h3 id="modalSubject"></h3>
            <div style="margin-bottom: 15px; color: var(--footer-text);">
                <strong>To:</strong> <span id="modalRecipient"></span><br>
                <strong>Sent:</strong> <span id="modalDate"></span>
            </div>
            <div id="modalBody" style="white-space: pre-wrap; background: var(--input-bg); padding: 15px; border-radius: 4px; max-height: 400px; overflow-y: auto;"></div>
            <div style="text-align: right; margin-top: 15px;">
                <button class="btn-secondary" onclick="closeEmailModal()">Close</button>
            </div>
        </div>
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
    <?php endif; ?>

<?php endif; ?>

<style>
/* Status badges */
.status-badge {
    display: inline-block;
    padding: 3px 8px;
    border-radius: 12px;
    font-size: 0.8rem;
    font-weight: 500;
}
.status-new { background-color: #e3f2fd; color: #0d47a1; }
.status-contacted { background-color: #fff3e0; color: #e65100; }
.status-interested { background-color: #e8f5e8; color: #1e5e1e; }
.status-not-interested { background-color: #ffebee; color: #b71c1c; }
.status-converted { background-color: #e8eaf6; color: #1a237e; }
.status-no_answer { background-color: #f5f5f5; color: #424242; }
.status-left_message { background-color: #e1f5fe; color: #01579b; }
.status-callback { background-color: #fff9c4; color: #f57f17; }
.status-sent { background-color: #e8f5e8; color: #1e5e1e; }
.status-failed { background-color: #ffebee; color: #b71c1c; }

/* Contact actions */
.contact-actions {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
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
.contact-icon.whatsapp { color: #25D366; }
.contact-icon.email { color: var(--link-color, #333); }

/* Action buttons */
.action-buttons {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

/* Modal */
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
    max-width: 600px;
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

    // Email view modal
    document.querySelectorAll('.view-email').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            document.getElementById('modalSubject').textContent = this.dataset.subject;
            document.getElementById('modalRecipient').textContent = this.dataset.recipient;
            document.getElementById('modalDate').textContent = this.dataset.date;
            document.getElementById('modalBody').textContent = this.dataset.body;
            document.getElementById('emailModal').style.display = 'flex';
        });
    });
});

function closeEmailModal() {
    document.getElementById('emailModal').style.display = 'none';
}

// Sharing functionality (only if user is owner)
<?php if ($lead && $lead['user_id'] == $_SESSION['user_id']): ?>
function loadShares() {
    fetch('share.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=list&lead_id=<?= $lead['id'] ?>'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            let html = '<h4>Currently shared with:</h4>';
            if (data.shares.length === 0) {
                html += '<p>Not shared with anyone.</p>';
            } else {
                html += '<ul>';
                data.shares.forEach(share => {
                    html += `<li>${share.name} (${share.email}) - ${share.permission} 
                            <button class="btn-danger btn-small" onclick="removeShare(${share.user_id})">Remove</button></li>`;
                });
                html += '</ul>';
            }
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
            select.innerHTML = '<option value="">Select user...</option>';
            data.users.forEach(user => {
                let option = document.createElement('option');
                option.value = user.id;
                option.textContent = user.name + ' (' + user.email + ')';
                select.appendChild(option);
            });
        }
    });
}

document.addEventListener('DOMContentLoaded', function() {
    loadShares();
    loadUsers();

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
                showNotification('Shared successfully', 'success');
                loadShares();
            } else {
                alert('Error: ' + data.error);
            }
        });
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
            showNotification('Share removed', 'success');
            loadShares();
        } else {
            alert('Error: ' + data.error);
        }
    });
}
<?php endif; ?>

// Notification helper
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