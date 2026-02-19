<?php
require_once '../includes/auth.php';
require_once '../includes/config.php';
require_once '../includes/functions.php';

$lead_id = isset($_GET['lead_id']) ? (int)$_GET['lead_id'] : 0;
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$status = $_GET['status'] ?? '';

// Build query
$sql = "SELECT el.*, l.name as lead_name, l.email as lead_email 
        FROM email_logs el
        LEFT JOIN leads l ON el.lead_id = l.id
        WHERE el.user_id = ?";
$params = [$_SESSION['user_id']];

if ($lead_id > 0) {
    $sql .= " AND el.lead_id = ?";
    $params[] = $lead_id;
}

if (!empty($date_from)) {
    $sql .= " AND DATE(el.sent_at) >= ?";
    $params[] = $date_from;
}

if (!empty($date_to)) {
    $sql .= " AND DATE(el.sent_at) <= ?";
    $params[] = $date_to;
}

if (!empty($status)) {
    $sql .= " AND el.status = ?";
    $params[] = $status;
}

$sql .= " ORDER BY el.sent_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$emails = $stmt->fetchAll();

// Get unique leads for filter dropdown
$leads_stmt = $pdo->prepare("SELECT DISTINCT l.id, l.name FROM leads l 
                             JOIN email_logs el ON el.lead_id = l.id 
                             WHERE el.user_id = ? ORDER BY l.name");
$leads_stmt->execute([$_SESSION['user_id']]);
$leads = $leads_stmt->fetchAll();

include '../includes/header.php';
?>

<h1>Email History</h1>

<div class="card card--filters">
    <div class="filters-header">
        <h3>Filters</h3>
        <button id="toggleFilters" class="btn-secondary btn-small">Show</button>
    </div>
    <div id="advancedFilters" style="display:none; margin-top:15px;">
        <form method="get">
            <?php if ($lead_id > 0): ?>
                <input type="hidden" name="lead_id" value="<?= $lead_id ?>">
            <?php endif; ?>
            
            <div class="filters-grid">
                <div class="form-group">
                    <label>Date Range</label>
                    <div class="date-range-row">
                        <input type="date" name="date_from" value="<?= htmlspecialchars($date_from) ?>">
                        <input type="date" name="date_to"   value="<?= htmlspecialchars($date_to) ?>">
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Status</label>
                    <select name="status">
                        <option value="">All</option>
                        <option value="sent" <?= $status === 'sent' ? 'selected' : '' ?>>Sent</option>
                        <option value="failed" <?= $status === 'failed' ? 'selected' : '' ?>>Failed</option>
                    </select>
                </div>
            </div>
            
            <div class="filter-actions">
                <button type="submit" class="btn">Apply Filters</button>
                <a href="history.php<?= $lead_id > 0 ? '?lead_id='.$lead_id : '' ?>" class="btn-secondary">Clear</a>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <?php if (!empty($leads) && $lead_id == 0): ?>
    <div style="margin-bottom: 20px;">
        <label>Quick filter by lead:</label>
        <select onchange="if(this.value) window.location.href='history.php?lead_id='+this.value">
            <option value="">Select a lead...</option>
            <?php foreach ($leads as $l): ?>
                <option value="<?= $l['id'] ?>"><?= htmlspecialchars($l['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php endif; ?>

    <?php if (count($emails) > 0): ?>
        <table class="table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Lead</th>
                    <th>Recipient</th>
                    <th>Subject</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($emails as $email): ?>
                <tr>
                    <td><?= date('M d, Y H:i', strtotime($email['sent_at'])) ?></td>
                    <td>
                        <a href="history.php?lead_id=<?= $email['lead_id'] ?>">
                            <?= htmlspecialchars($email['lead_name'] ?: 'Unknown') ?>
                        </a>
                    </td>
                    <td><?= htmlspecialchars($email['recipient_email']) ?></td>
                    <td><?= htmlspecialchars($email['subject']) ?></td>
                    <td>
                        <span class="status-badge status-<?= $email['status'] ?>">
                            <?= ucfirst($email['status']) ?>
                        </span>
                    </td>
                    <td>
                        <a href="#" class="btn-secondary btn-small view-email" 
                           data-id="<?= $email['id'] ?>"
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
        <div class="empty-state">
            <p>No emails found.</p>
            <?php if ($lead_id > 0): ?>
                <a href="compose.php?lead_id=<?= $lead_id ?>" class="btn">Send an Email</a>
            <?php endif; ?>
        </div>
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

<style>
.status-badge {
    display: inline-block;
    padding: 3px 8px;
    border-radius: 12px;
    font-size: 0.8rem;
    font-weight: 500;
}
.status-sent {
    background-color: #d4edda;
    color: #155724;
}
.status-failed {
    background-color: #f8d7da;
    color: #721c24;
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
// Toggle filters
document.getElementById('toggleFilters')?.addEventListener('click', function () {
    const panel = document.getElementById('advancedFilters');
    const hiding = panel.style.display !== 'none';
    panel.style.display = hiding ? 'none' : 'block';
    this.textContent = hiding ? 'Show' : 'Hide';
});

// Email modal
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

function closeEmailModal() {
    document.getElementById('emailModal').style.display = 'none';
}
</script>

<?php include '../includes/footer.php'; ?>