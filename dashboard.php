<?php
require_once 'includes/auth.php';
require_once 'includes/config.php';
require_once 'includes/functions.php';

// Get accessible lead IDs (admin sees all, others see own/shared)
$accessibleIds = getAccessibleLeadIds($pdo, $_SESSION['user_id'], isAdmin());
$accessibleCount = count($accessibleIds);

// Total leads
$stats['total_leads'] = $accessibleCount;

// New leads (status = 'new') among accessible leads
if ($accessibleCount > 0) {
    $placeholders = implode(',', array_fill(0, $accessibleCount, '?'));
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM leads WHERE id IN ($placeholders) AND status = 'new'");
    $stmt->execute($accessibleIds);
    $stats['new_leads'] = $stmt->fetchColumn();

    // Interested leads
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM leads WHERE id IN ($placeholders) AND status = 'interested'");
    $stmt->execute($accessibleIds);
    $stats['interested'] = $stmt->fetchColumn();
} else {
    $stats['new_leads'] = 0;
    $stats['interested'] = 0;
}

// Calls today (user's own calls)
$stmt = $pdo->prepare("SELECT COUNT(*) FROM calls WHERE user_id = ? AND DATE(created_at) = CURDATE()");
$stmt->execute([$_SESSION['user_id']]);
$stats['calls_today'] = $stmt->fetchColumn();

// Follow-ups due (today or past) for user's own calls
$stmt = $pdo->prepare("
    SELECT c.*, l.name as lead_name 
    FROM calls c 
    JOIN leads l ON c.lead_id = l.id 
    WHERE c.user_id = ? AND c.follow_up_date <= CURDATE() AND c.follow_up_date IS NOT NULL
    ORDER BY c.follow_up_date ASC
");
$stmt->execute([$_SESSION['user_id']]);
$followUps = $stmt->fetchAll();

include 'includes/header.php';
?>

<h1>Dashboard</h1>

<div class="stats-grid">
    <div class="stat-card">
        <h3>Total Leads</h3>
        <p class="stat-number"><?= $stats['total_leads'] ?></p>
    </div>
    <div class="stat-card">
        <h3>New Leads</h3>
        <p class="stat-number"><?= $stats['new_leads'] ?></p>
    </div>
    <div class="stat-card">
        <h3>Interested</h3>
        <p class="stat-number"><?= $stats['interested'] ?></p>
    </div>
    <div class="stat-card">
        <h3>Calls Today</h3>
        <p class="stat-number"><?= $stats['calls_today'] ?></p>
    </div>
</div>

<div class="card">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
        <h2>Follow-ups Due</h2>
        <a href="leads.php" class="btn btn-secondary">View All Leads</a>
    </div>
    <?php if (count($followUps) > 0): ?>
        <div class="table-container">
            <table class="table">
                <thead>
                    <tr>
                        <th>Lead</th>
                        <th>Last Outcome</th>
                        <th>Follow-up Date</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($followUps as $call): ?>
                    <tr>
                        <td><?= htmlspecialchars($call['lead_name']) ?></td>
                        <td><?= htmlspecialchars($call['outcome']) ?></td>
                        <td><?= htmlspecialchars($call['follow_up_date']) ?></td>
                        <td><a href="lead.php?id=<?= $call['lead_id'] ?>" class="btn-secondary btn-small">View</a></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <p>No follow-ups due.</p>
    <?php endif; ?>
</div>

<?php include 'includes/footer.php'; ?>