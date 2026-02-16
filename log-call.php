<?php
require_once 'includes/auth.php';
require_once 'includes/config.php';

$lead_id = isset($_GET['lead_id']) ? (int)$_GET['lead_id'] : 0;

// Verify lead belongs to user
if ($lead_id) {
    $stmt = $pdo->prepare("SELECT id, name FROM leads WHERE id = ? AND user_id = ?");
    $stmt->execute([$lead_id, $_SESSION['user_id']]);
    $lead = $stmt->fetch();
    if (!$lead) {
        die('Lead not found.');
    }
} else {
    die('No lead specified.');
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $outcome = $_POST['outcome'] ?? '';
    $duration = (int)($_POST['duration'] ?? 0);
    $follow_up = $_POST['follow_up_date'] ?? null;
    $notes = trim($_POST['notes'] ?? '');

    if (empty($outcome)) {
        $error = 'Call outcome is required.';
    } else {
        $stmt = $pdo->prepare("INSERT INTO calls (lead_id, user_id, outcome, duration, follow_up_date, notes) VALUES (?, ?, ?, ?, ?, ?)");
        if ($stmt->execute([$lead_id, $_SESSION['user_id'], $outcome, $duration, $follow_up ?: null, $notes])) {
            // Optionally update lead status based on outcome
            // For simplicity, we'll leave status update to user.
            header("Location: lead.php?id=$lead_id&call_logged=1");
            exit;
        } else {
            $error = 'Failed to log call.';
        }
    }
}

include 'includes/header.php';
?>

<h1>Log Call for <?= htmlspecialchars($lead['name']) ?></h1>

<?php if ($error): ?>
    <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<div class="card">
    <form method="post">
        <div class="form-group">
            <label for="outcome">Call Outcome *</label>
            <select id="outcome" name="outcome" required>
                <option value="">-- Select --</option>
                <option value="no_answer">No Answer</option>
                <option value="left_message">Left Message</option>
                <option value="interested">Interested</option>
                <option value="not_interested">Not Interested</option>
                <option value="callback">Callback Requested</option>
                <option value="converted">Converted</option>
            </select>
        </div>
        <div class="form-group">
            <label for="duration">Duration (seconds)</label>
            <input type="number" id="duration" name="duration" value="0" min="0">
        </div>
        <div class="form-group">
            <label for="follow_up_date">Follow-up Date (if any)</label>
            <input type="date" id="follow_up_date" name="follow_up_date">
        </div>
        <div class="form-group">
            <label for="notes">Notes</label>
            <textarea id="notes" name="notes" rows="4"></textarea>
        </div>
        <button type="submit" class="btn">Log Call</button>
        <a href="lead.php?id=<?= $lead_id ?>" class="btn-secondary">Cancel</a>
    </form>
</div>

<?php include 'includes/footer.php'; ?>