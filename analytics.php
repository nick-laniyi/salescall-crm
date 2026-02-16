<?php
require_once 'includes/auth.php';
require_once 'includes/config.php';
require_once 'includes/functions.php';

// Get accessible leads IDs
list($sql, $params) = getAccessibleLeadsQuery($pdo, $_SESSION['user_id']);
// Modify to get only IDs for counting
$sql = str_replace("l.*, (l.user_id = :user_id) as is_owner, MAX(ls.permission) as shared_permission", "l.id", $sql);
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$leadIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
$leadIdsPlaceholder = implode(',', array_fill(0, count($leadIds), '?'));

// Total leads
$totalLeads = count($leadIds);

// Leads by status
$statusStats = [];
if ($totalLeads > 0) {
    $stmt = $pdo->prepare("SELECT status, COUNT(*) as count FROM leads WHERE id IN ($leadIdsPlaceholder) GROUP BY status");
    $stmt->execute($leadIds);
    $statusStats = $stmt->fetchAll();
}

// Calls per day (last 30 days)
$callsPerDay = [];
if ($totalLeads > 0) {
    $stmt = $pdo->prepare("SELECT DATE(created_at) as date, COUNT(*) as count 
                           FROM calls WHERE lead_id IN ($leadIdsPlaceholder) 
                           AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                           GROUP BY DATE(created_at) ORDER BY date");
    $stmt->execute($leadIds);
    $callsPerDay = $stmt->fetchAll();
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

// Call outcomes distribution
$outcomeStats = [];
if ($totalLeads > 0) {
    $stmt = $pdo->prepare("SELECT outcome, COUNT(*) as count FROM calls WHERE lead_id IN ($leadIdsPlaceholder) GROUP BY outcome");
    $stmt->execute($leadIds);
    $outcomeStats = $stmt->fetchAll();
}

// Follow-ups due today
$stmt = $pdo->prepare("SELECT c.*, l.name as lead_name 
                       FROM calls c 
                       JOIN leads l ON c.lead_id = l.id 
                       WHERE l.id IN ($leadIdsPlaceholder) 
                       AND c.follow_up_date = CURDATE()");
$stmt->execute($leadIds);
$followUpsToday = $stmt->fetchAll();

include 'includes/header.php';
?>

<h1>Analytics</h1>

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