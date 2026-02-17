<?php
require_once '../includes/auth.php';
require_once '../includes/config.php';
requireAdmin(); // Only admins can access

// Get all users
$users = $pdo->query("SELECT id, name, email, created_at FROM users ORDER BY name")->fetchAll();

$stats = [];

foreach ($users as $user) {
    $userId = $user['id'];
    
    // Lead counts
    $stmt = $pdo->prepare("SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'new' THEN 1 ELSE 0 END) as new,
        SUM(CASE WHEN status = 'contacted' THEN 1 ELSE 0 END) as contacted,
        SUM(CASE WHEN status = 'interested' THEN 1 ELSE 0 END) as interested,
        SUM(CASE WHEN status = 'not_interested' THEN 1 ELSE 0 END) as not_interested,
        SUM(CASE WHEN status = 'converted' THEN 1 ELSE 0 END) as converted
        FROM leads WHERE user_id = ?");
    $stmt->execute([$userId]);
    $leadStats = $stmt->fetch();
    
    // Call counts
    $stmt = $pdo->prepare("SELECT 
        COUNT(*) as total_calls,
        SUM(CASE WHEN outcome = 'converted' THEN 1 ELSE 0 END) as successful_calls
        FROM calls WHERE user_id = ?");
    $stmt->execute([$userId]);
    $callStats = $stmt->fetch();
    
    // Follow-ups due today
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM calls c 
                           JOIN leads l ON c.lead_id = l.id 
                           WHERE l.user_id = ? AND c.follow_up_date = CURDATE()");
    $stmt->execute([$userId]);
    $followUpsToday = $stmt->fetchColumn();
    
    // Conversion rate
    $conversionRate = $leadStats['total'] > 0 ? round(($leadStats['converted'] / $leadStats['total']) * 100, 2) : 0;
    
    $stats[$userId] = [
        'name' => $user['name'],
        'email' => $user['email'],
        'leads' => $leadStats,
        'calls' => $callStats,
        'follow_ups_today' => $followUpsToday,
        'conversion_rate' => $conversionRate
    ];
}

include '../includes/header.php';
?>

<h1>Team Dashboard</h1>

<div class="card">
    <h2>Team Performance Overview</h2>
    <table class="table">
        <thead>
            <tr>
                <th>Name</th>
                <th>Email</th>
                <th>Total Leads</th>
                <th>New</th>
                <th>Interested</th>
                <th>Converted</th>
                <th>Conversion Rate</th>
                <th>Total Calls</th>
                <th>Follow-ups Today</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($stats as $userId => $stat): ?>
            <tr>
                <td><?= htmlspecialchars($stat['name']) ?></td>
                <td><?= htmlspecialchars($stat['email']) ?></td>
                <td><?= $stat['leads']['total'] ?></td>
                <td><?= $stat['leads']['new'] ?></td>
                <td><?= $stat['leads']['interested'] ?></td>
                <td><?= $stat['leads']['converted'] ?></td>
                <td><?= $stat['conversion_rate'] ?>%</td>
                <td><?= $stat['calls']['total_calls'] ?></td>
                <td><?= $stat['follow_ups_today'] ?></td>
                <td>
                    <a href="../leads.php?user_id=<?= $userId ?>" class="btn-secondary btn-small">View Leads</a>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div class="card">
    <h2>Team Comparison</h2>
    <canvas id="teamChart" width="400" height="200"></canvas>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
const ctx = document.getElementById('teamChart').getContext('2d');
const teamChart = new Chart(ctx, {
    type: 'bar',
    data: {
        labels: <?= json_encode(array_column($stats, 'name')) ?>,
        datasets: [
            {
                label: 'Total Leads',
                data: <?= json_encode(array_map(function($s) { return $s['leads']['total']; }, $stats)) ?>,
                backgroundColor: '#3b82f6'
            },
            {
                label: 'Converted',
                data: <?= json_encode(array_map(function($s) { return $s['leads']['converted']; }, $stats)) ?>,
                backgroundColor: '#10b981'
            }
        ]
    },
    options: {
        responsive: true,
        scales: {
            y: {
                beginAtZero: true
            }
        }
    }
});
</script>

<?php include '../includes/footer.php'; ?>