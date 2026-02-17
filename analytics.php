<?php
require_once 'includes/auth.php';
require_once 'includes/config.php';
require_once 'includes/functions.php';

$userId = 0;
if (isAdmin() && isset($_GET['user_id'])) {
    $userId = (int)$_GET['user_id'];
}

// Get accessible lead IDs based on role and filter
if (isAdmin()) {
    if ($userId > 0) {
        // Admin viewing specific user's leads
        $accessibleIds = getAccessibleLeadIds($pdo, $userId, false);
    } else {
        // Admin viewing all leads
        $accessibleIds = getAccessibleLeadIds($pdo, $_SESSION['user_id'], true);
    }
} else {
    // Regular user
    $accessibleIds = getAccessibleLeadIds($pdo, $_SESSION['user_id']);
}

$totalLeads = count($accessibleIds);

$statusStats = [];
$callsPerDay = [];
$outcomeStats = [];
$followUpsToday = [];

if (!empty($accessibleIds)) {
    $placeholders = implode(',', array_fill(0, count($accessibleIds), '?'));
    
    // Leads by status
    $stmt = $pdo->prepare("SELECT status, COUNT(*) as count FROM leads WHERE id IN ($placeholders) GROUP BY status");
    $stmt->execute($accessibleIds);
    $statusStats = $stmt->fetchAll();
    
    // Calls per day (last 30 days)
    $stmt = $pdo->prepare("SELECT DATE(created_at) as date, COUNT(*) as count 
                           FROM calls WHERE lead_id IN ($placeholders) 
                           AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                           GROUP BY DATE(created_at) ORDER BY date");
    $stmt->execute($accessibleIds);
    $callsPerDay = $stmt->fetchAll();
    
    // Call outcomes distribution
    $stmt = $pdo->prepare("SELECT outcome, COUNT(*) as count FROM calls WHERE lead_id IN ($placeholders) GROUP BY outcome");
    $stmt->execute($accessibleIds);
    $outcomeStats = $stmt->fetchAll();
    
    // Follow-ups due today
    $stmt = $pdo->prepare("SELECT c.*, l.name as lead_name 
                           FROM calls c 
                           JOIN leads l ON c.lead_id = l.id 
                           WHERE l.id IN ($placeholders) 
                           AND c.follow_up_date = CURDATE()");
    $stmt->execute($accessibleIds);
    $followUpsToday = $stmt->fetchAll();
}

// Conversion rate
$converted = 0;
foreach ($statusStats as $stat) {
    if ($stat['status'] === 'converted') {
        $converted = $stat['count'];
        break;
    }
}
$conversionRate = $totalLeads > 0 ? round(($converted / $totalLeads) * 100, 2) : 0;

// Get list of users for dropdown (admin only)
$users = [];
if (isAdmin()) {
    $users = $pdo->query("SELECT id, name FROM users ORDER BY name")->fetchAll();
}

include 'includes/header.php';
?>

<h1>Analytics</h1>

<?php if (isAdmin()): ?>
<div class="card">
    <form method="get" style="display: flex; gap: 10px; align-items: center;">
        <label for="user_id">View analytics for:</label>
        <select name="user_id" id="user_id">
            <option value="0">All Users</option>
            <?php foreach ($users as $user): ?>
                <option value="<?= $user['id'] ?>" <?= $userId == $user['id'] ? 'selected' : '' ?>><?= htmlspecialchars($user['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn">Go</button>
    </form>
</div>
<?php endif; ?>

<div class="stats-grid">
    <div class="stat-card">
        <h3>Total Leads</h3>
        <p class="stat-number"><?= $totalLeads ?></p>
    </div>
    <div class="stat-card">
        <h3>Converted</h3>
        <p class="stat-number"><?= $converted ?></p>
    </div>
    <div class="stat-card">
        <h3>Conversion Rate</h3>
        <p class="stat-number"><?= $conversionRate ?>%</p>
    </div>
    <div class="stat-card">
        <h3>Follow-ups Today</h3>
        <p class="stat-number"><?= count($followUpsToday) ?></p>
    </div>
</div>

<div class="card">
    <h2>Leads by Status</h2>
    <canvas id="statusChart" width="400" height="200"></canvas>
</div>

<div class="card">
    <h2>Calls Per Day (Last 30 Days)</h2>
    <canvas id="callsChart" width="400" height="200"></canvas>
</div>

<div class="card">
    <h2>Call Outcomes</h2>
    <canvas id="outcomeChart" width="400" height="200"></canvas>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
// Status chart
const statusCtx = document.getElementById('statusChart').getContext('2d');
new Chart(statusCtx, {
    type: 'pie',
    data: {
        labels: <?= json_encode(array_column($statusStats, 'status')) ?>,
        datasets: [{
            data: <?= json_encode(array_column($statusStats, 'count')) ?>,
            backgroundColor: ['#e2e8f0', '#dbeafe', '#dcfce7', '#fee2e2', '#fef9c3']
        }]
    }
});

// Calls per day chart
const callsCtx = document.getElementById('callsChart').getContext('2d');
const callsLabels = <?= json_encode(array_column($callsPerDay, 'date')) ?>;
const callsData = <?= json_encode(array_column($callsPerDay, 'count')) ?>;
new Chart(callsCtx, {
    type: 'line',
    data: {
        labels: callsLabels,
        datasets: [{
            label: 'Calls',
            data: callsData,
            borderColor: '#3b82f6',
            tension: 0.1
        }]
    }
});

// Outcome chart
const outcomeCtx = document.getElementById('outcomeChart').getContext('2d');
new Chart(outcomeCtx, {
    type: 'bar',
    data: {
        labels: <?= json_encode(array_column($outcomeStats, 'outcome')) ?>,
        datasets: [{
            label: 'Count',
            data: <?= json_encode(array_column($outcomeStats, 'count')) ?>,
            backgroundColor: '#3b82f6'
        }]
    }
});
</script>

<?php include 'includes/footer.php'; ?>