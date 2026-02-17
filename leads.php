<?php
require_once 'includes/auth.php';
require_once 'includes/config.php';
require_once 'includes/functions.php';

// Handle bulk delete (admin or owner only – we'll check each lead)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_selected'])) {
    $ids = $_POST['lead_ids'] ?? [];
    if (!empty($ids) && is_array($ids)) {
        $deleted = 0;
        foreach ($ids as $leadId) {
            if (canDeleteLead($pdo, $leadId, $_SESSION['user_id'])) {
                $stmt = $pdo->prepare("DELETE FROM leads WHERE id = ?");
                $stmt->execute([$leadId]);
                $deleted++;
            }
        }
        $success = "$deleted lead(s) deleted.";
    }
}

// Handle delete all (admin only)
if (isset($_GET['delete_all']) && $_GET['delete_all'] === 'confirm') {
    if (!isAdmin()) {
        $error = "Only admin can delete all leads.";
    } else {
        $stmt = $pdo->prepare("DELETE FROM leads");
        if ($stmt->execute()) {
            $success = "All leads deleted.";
        } else {
            $error = "Failed to delete all leads.";
        }
    }
}

// Handle assign owner (admin only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_owner'])) {
    if (!isAdmin()) {
        $error = "Only admin can assign leads.";
    } else {
        $ids = $_POST['lead_ids'] ?? [];
        $newOwner = (int)($_POST['new_owner'] ?? 0);
        if (!empty($ids) && $newOwner) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare("UPDATE leads SET user_id = ? WHERE id IN ($placeholders)");
            $params = array_merge([$newOwner], $ids);
            if ($stmt->execute($params)) {
                $success = count($ids) . " leads reassigned.";
            } else {
                $error = "Failed to reassign leads.";
            }
        }
    }
}

// Get search and filter parameters
$search = $_GET['search'] ?? '';
$status = $_GET['status'] ?? '';
$userId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
$show_imported = isset($_GET['imported']) && $_GET['imported'] == 1;

// Determine which leads to show
if (isAdmin()) {
    // Admin can filter by user or see all
    $accessibleIds = getAccessibleLeadIds($pdo, $_SESSION['user_id'], true); // all leads
} else {
    // Regular user only sees own/shared
    $accessibleIds = getAccessibleLeadIds($pdo, $_SESSION['user_id']);
}

$leads = [];
if (!empty($accessibleIds)) {
    $placeholders = implode(',', array_fill(0, count($accessibleIds), '?'));
    $sql = "SELECT l.*, 
                   u.name as owner_name,
                   (l.user_id = ?) as is_owner,
                   (SELECT permission FROM lead_shares WHERE lead_id = l.id AND user_id = ?) as shared_permission
            FROM leads l
            LEFT JOIN users u ON l.user_id = u.id
            WHERE l.id IN ($placeholders)";
    
    $params = [$_SESSION['user_id'], $_SESSION['user_id']];
    $params = array_merge($params, $accessibleIds);
    
    // Apply user filter (admin only)
    if ($userId > 0 && isAdmin()) {
        $sql .= " AND l.user_id = ?";
        $params[] = $userId;
    }
    
    // Add search condition
    if ($search) {
        $sql .= " AND (l.name LIKE ? OR l.company LIKE ? OR l.email LIKE ? OR l.phone LIKE ?)";
        $searchTerm = "%$search%";
        $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm, $searchTerm]);
    }
    
    // Add status filter
    if ($status && $status !== 'all') {
        $sql .= " AND l.status = ?";
        $params[] = $status;
    }
    
    // Add imported filter
    if ($show_imported && isset($_SESSION['last_import_time'])) {
        $sql .= " AND l.created_at >= ?";
        $params[] = $_SESSION['last_import_time'];
    }
    
    $sql .= " ORDER BY l.created_at DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $leads = $stmt->fetchAll();
}

// Get list of users for filter and assignment (admin only)
$users = [];
if (isAdmin()) {
    $users = $pdo->query("SELECT id, name, email FROM users ORDER BY name")->fetchAll();
}

include 'includes/header.php';
?>

<h1>Leads</h1>

<?php if (isset($_GET['imported'])): ?>
    <div class="alert alert-success">
        <?= htmlspecialchars($_GET['count'] ?? '') ?> leads imported successfully.
        <?php if (isset($_SESSION['last_import_time'])): ?>
            <a href="leads.php?imported=1">View newly imported</a> |
            <a href="leads.php">View all</a>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if (isset($success)): ?>
    <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
<?php endif; ?>
<?php if (isset($error)): ?>
    <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<div class="card">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 10px;">
        <div>
            <a href="lead.php?action=add" class="btn">Add New Lead</a>
            <a href="import.php" class="btn-secondary">Import Leads</a>
            <a href="download_sample.php" class="btn-secondary">Download Sample CSV</a>
            <a href="export.php" class="btn-secondary">Export to CSV</a>
            <a href="analytics.php" class="btn-secondary">Analytics</a>
            <?php if (isAdmin()): ?>
                <a href="admin/team.php" class="btn-secondary">Team Dashboard</a>
            <?php endif; ?>
        </div>
        <form method="get" style="display: flex; gap: 10px; flex-wrap: wrap;">
            <input type="text" name="search" placeholder="Search leads..." value="<?= htmlspecialchars($search) ?>" style="padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
            <select name="status" style="padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
                <option value="all">All Status</option>
                <option value="new" <?= $status === 'new' ? 'selected' : '' ?>>New</option>
                <option value="contacted" <?= $status === 'contacted' ? 'selected' : '' ?>>Contacted</option>
                <option value="interested" <?= $status === 'interested' ? 'selected' : '' ?>>Interested</option>
                <option value="not_interested" <?= $status === 'not_interested' ? 'selected' : '' ?>>Not Interested</option>
                <option value="converted" <?= $status === 'converted' ? 'selected' : '' ?>>Converted</option>
            </select>
            <?php if (isAdmin() && !empty($users)): ?>
                <select name="user_id" style="padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
                    <option value="0">All Users</option>
                    <?php foreach ($users as $user): ?>
                        <option value="<?= $user['id'] ?>" <?= $userId == $user['id'] ? 'selected' : '' ?>><?= htmlspecialchars($user['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>
            <button type="submit" class="btn">Filter</button>
            <?php if ($search || $status || $userId): ?>
                <a href="leads.php" class="btn-secondary">Clear</a>
            <?php endif; ?>
        </form>
    </div>

    <?php if (count($leads) > 0): ?>
        <form method="post" id="bulk-actions-form">
            <div style="margin-bottom: 10px; display: flex; gap: 10px; flex-wrap: wrap;">
                <button type="button" class="btn-secondary" onclick="selectAll()">Select All</button>
                <button type="submit" name="delete_selected" class="btn-danger" onclick="return confirm('Delete selected leads?')">Delete Selected</button>
                <?php if (isAdmin()): ?>
                    <select name="new_owner" id="new_owner" style="padding: 8px;">
                        <option value="">Reassign selected to...</option>
                        <?php foreach ($users as $user): ?>
                            <option value="<?= $user['id'] ?>"><?= htmlspecialchars($user['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" name="assign_owner" class="btn-secondary" onclick="return confirm('Reassign selected leads?')">Assign</button>
                    <?php if (isAdmin()): ?>
                        <a href="?delete_all=confirm" class="btn-danger" onclick="return confirm('Delete ALL leads? This cannot be undone.')">Delete All</a>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            <table class="table" id="leads-table">
                <thead>
                    <tr>
                        <th><input type="checkbox" id="select-all"></th>
                        <th>Name</th>
                        <th>Company</th>
                        <th>Phone</th>
                        <th>Email</th>
                        <th>Status</th>
                        <?php if (isAdmin()): ?>
                            <th>Owner</th>
                        <?php endif; ?>
                        <th>Created</th>
                        <th>Quick Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($leads as $lead): ?>
                    <tr data-lead-id="<?= $lead['id'] ?>">
                        <td><input type="checkbox" name="lead_ids[]" value="<?= $lead['id'] ?>" class="lead-checkbox"></td>
                        <td>
                            <?php if (!$lead['is_owner'] && !isAdmin()): ?>
                                <span class="shared-badge" title="Shared with you (<?= $lead['shared_permission'] ?>)">🔗</span>
                            <?php endif; ?>
                            <span class="editable" data-field="name"><?= htmlspecialchars($lead['name']) ?></span>
                        </td>
                        <td class="editable" data-field="company"><?= htmlspecialchars($lead['company'] ?: '—') ?></td>
                        <td class="editable" data-field="phone"><?= htmlspecialchars($lead['phone'] ?: '—') ?></td>
                        <td class="editable" data-field="email"><?= htmlspecialchars($lead['email'] ?: '—') ?></td>
                        <td>
                            <select class="status-select" data-lead-id="<?= $lead['id'] ?>" data-current="<?= $lead['status'] ?>">
                                <option value="new" <?= $lead['status'] === 'new' ? 'selected' : '' ?>>New</option>
                                <option value="contacted" <?= $lead['status'] === 'contacted' ? 'selected' : '' ?>>Contacted</option>
                                <option value="interested" <?= $lead['status'] === 'interested' ? 'selected' : '' ?>>Interested</option>
                                <option value="not_interested" <?= $lead['status'] === 'not_interested' ? 'selected' : '' ?>>Not Interested</option>
                                <option value="converted" <?= $lead['status'] === 'converted' ? 'selected' : '' ?>>Converted</option>
                            </select>
                        </td>
                        <?php if (isAdmin()): ?>
                            <td>
                                <select class="owner-select" data-lead-id="<?= $lead['id'] ?>">
                                    <?php foreach ($users as $user): ?>
                                        <option value="<?= $user['id'] ?>" <?= $lead['user_id'] == $user['id'] ? 'selected' : '' ?>><?= htmlspecialchars($user['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        <?php endif; ?>
                        <td><?= date('M d, Y', strtotime($lead['created_at'])) ?></td>
                        <td>
                            <div class="dropdown">
                                <button class="btn-secondary btn-small dropdown-toggle">Actions ▼</button>
                                <div class="dropdown-content">
                                    <a href="lead.php?id=<?= $lead['id'] ?>">View Details</a>
                                    <a href="lead.php?action=edit&id=<?= $lead['id'] ?>">Edit</a>
                                    <a href="log-call.php?lead_id=<?= $lead['id'] ?>">Log Call</a>
                                    <a href="#" class="quick-note" data-lead-id="<?= $lead['id'] ?>">Add Quick Note</a>
                                    <?php if (canDeleteLead($pdo, $lead['id'], $_SESSION['user_id'])): ?>
                                        <a href="#" class="delete-single" data-lead-id="<?= $lead['id'] ?>" onclick="return confirm('Delete this lead?')">Delete</a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </form>
    <?php else: ?>
        <p>No leads found. 
            <?php if ($search || $status || $userId): ?>
                <a href="leads.php">Clear filters</a> or 
            <?php endif; ?>
            <a href="lead.php?action=add">add your first lead</a>.
        </p>
    <?php endif; ?>
</div>

<!-- Quick Note Modal -->
<div id="quickNoteModal" class="modal">
    <div class="modal-content">
        <span class="close">&times;</span>
        <h3>Add Quick Note</h3>
        <input type="hidden" id="noteLeadId">
        <textarea id="noteText" rows="4" style="width: 100%;"></textarea>
        <button id="saveNote" class="btn">Save Note</button>
    </div>
</div>

<style>
/* Dropdown styles */
.dropdown {
    position: relative;
    display: inline-block;
}

.dropdown-content {
    display: none;
    position: absolute;
    background-color: white;
    min-width: 160px;
    box-shadow: 0px 8px 16px 0px rgba(0,0,0,0.2);
    z-index: 1;
    border-radius: 4px;
}

.dropdown-content a {
    color: black;
    padding: 8px 12px;
    text-decoration: none;
    display: block;
    font-size: 0.9rem;
}

.dropdown-content a:hover {
    background-color: #f1f1f1;
}

.dropdown:hover .dropdown-content {
    display: block;
}

/* Modal styles */
.modal {
    display: none;
    position: fixed;
    z-index: 1000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0,0,0,0.4);
}

.modal-content {
    background-color: white;
    margin: 15% auto;
    padding: 20px;
    border: 1px solid #888;
    width: 400px;
    border-radius: 8px;
    position: relative;
}

.close {
    position: absolute;
    right: 15px;
    top: 10px;
    font-size: 24px;
    cursor: pointer;
}

/* Editable cell styles */
.editable {
    cursor: pointer;
    position: relative;
}

.editable:hover {
    background-color: #f0f0f0;
}

.editable.editing {
    padding: 0;
}

.editable input {
    width: 100%;
    padding: 8px;
    border: 2px solid #3b82f6;
    border-radius: 4px;
    font-size: inherit;
}

/* Shared badge */
.shared-badge {
    font-size: 1.2rem;
    margin-right: 5px;
    cursor: help;
}

/* Owner select */
.owner-select {
    padding: 4px;
    font-size: 0.9rem;
    border-radius: 4px;
}
</style>

<script>
// Select all functionality
document.getElementById('select-all').addEventListener('change', function() {
    var checkboxes = document.getElementsByClassName('lead-checkbox');
    for (var i = 0; i < checkboxes.length; i++) {
        checkboxes[i].checked = this.checked;
    }
});

function selectAll() {
    var checkboxes = document.getElementsByClassName('lead-checkbox');
    var selectAllCheckbox = document.getElementById('select-all');
    for (var i = 0; i < checkboxes.length; i++) {
        checkboxes[i].checked = true;
    }
    selectAllCheckbox.checked = true;
}

// Inline status update via AJAX
document.querySelectorAll('.status-select').forEach(select => {
    select.addEventListener('change', function() {
        const leadId = this.dataset.leadId;
        const newStatus = this.value;
        
        fetch('quick-status.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ lead_id: leadId, field: 'status', value: newStatus })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                this.dataset.current = newStatus;
                showNotification('Status updated', 'success');
            } else {
                alert('Error updating status: ' + data.error);
                this.value = this.dataset.current;
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Network error');
            this.value = this.dataset.current;
        });
    });
});

// Inline editing for name, company, phone, email
document.querySelectorAll('.editable').forEach(cell => {
    cell.addEventListener('dblclick', function(e) {
        if (this.classList.contains('editing')) return;
        
        const field = this.dataset.field;
        const leadId = this.closest('tr').dataset.leadId;
        const currentValue = this.innerText === '—' ? '' : this.innerText;
        
        const input = document.createElement('input');
        input.type = 'text';
        input.value = currentValue;
        input.style.width = '100%';
        
        this.innerHTML = '';
        this.appendChild(input);
        this.classList.add('editing');
        input.focus();
        
        const saveEdit = () => {
            const newValue = input.value.trim();
            if (newValue === currentValue) {
                this.innerHTML = newValue || '—';
                this.classList.remove('editing');
                return;
            }
            
            fetch('quick-status.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ lead_id: leadId, field: field, value: newValue })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    this.innerHTML = newValue || '—';
                    showNotification('Field updated', 'success');
                } else {
                    alert('Error updating: ' + data.error);
                    this.innerHTML = currentValue || '—';
                }
                this.classList.remove('editing');
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Network error');
                this.innerHTML = currentValue || '—';
                this.classList.remove('editing');
            });
        };
        
        input.addEventListener('blur', saveEdit);
        input.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                saveEdit();
            }
        });
    });
});

// Quick note modal
const modal = document.getElementById('quickNoteModal');
const span = document.getElementsByClassName('close')[0];
const saveNoteBtn = document.getElementById('saveNote');
const noteLeadId = document.getElementById('noteLeadId');
const noteText = document.getElementById('noteText');

document.querySelectorAll('.quick-note').forEach(link => {
    link.addEventListener('click', function(e) {
        e.preventDefault();
        const leadId = this.dataset.leadId;
        noteLeadId.value = leadId;
        noteText.value = '';
        modal.style.display = 'block';
    });
});

span.onclick = function() {
    modal.style.display = 'none';
}

window.onclick = function(event) {
    if (event.target == modal) {
        modal.style.display = 'none';
    }
}

saveNoteBtn.addEventListener('click', function() {
    const leadId = noteLeadId.value;
    const note = noteText.value.trim();
    
    if (!note) {
        alert('Please enter a note');
        return;
    }
    
    fetch('quick-status.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ lead_id: leadId, field: 'notes', value: note })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showNotification('Note added', 'success');
            modal.style.display = 'none';
        } else {
            alert('Error: ' + data.error);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Network error');
    });
});

// Delete single lead (only for owners)
document.querySelectorAll('.delete-single').forEach(link => {
    link.addEventListener('click', function(e) {
        e.preventDefault();
        if (!confirm('Delete this lead?')) return;
        
        const leadId = this.dataset.leadId;
        fetch('quick-status.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ lead_id: leadId, field: 'delete', value: '' })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                this.closest('tr').remove();
                showNotification('Lead deleted', 'success');
            } else {
                alert('Error: ' + data.error);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Network error');
        });
    });
});

// Add owner change handler for admin
<?php if (isAdmin()): ?>
document.querySelectorAll('.owner-select').forEach(select => {
    select.addEventListener('change', function() {
        const leadId = this.dataset.leadId;
        const newOwner = this.value;
        if (!confirm('Reassign this lead?')) {
            this.value = this.querySelector('option[selected]')?.value || '';
            return;
        }
        fetch('quick-status.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ lead_id: leadId, field: 'owner', value: newOwner })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Update selected attribute
                this.querySelectorAll('option').forEach(opt => opt.removeAttribute('selected'));
                this.querySelector(`option[value="${newOwner}"]`).setAttribute('selected', '');
                showNotification('Owner updated', 'success');
            } else {
                alert('Error: ' + data.error);
                this.value = this.querySelector('option[selected]')?.value || '';
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Network error');
        });
    });
});
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