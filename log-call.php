<?php
require_once 'includes/auth.php';
require_once 'includes/config.php';
require_once 'includes/functions.php';

$lead_id = isset($_GET['lead_id']) ? (int)$_GET['lead_id'] : 0;

// Verify lead access
if ($lead_id) {
    if (!canViewLead($pdo, $lead_id, $_SESSION['user_id'])) {
        die('Lead not found.');
    }
    $stmt = $pdo->prepare("SELECT name FROM leads WHERE id = ?");
    $stmt->execute([$lead_id]);
    $lead = $stmt->fetch();
} else {
    die('No lead specified.');
}

$error = '';

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
    <div style="margin-bottom: 20px;">
        <button id="startTimer" class="btn">Start Call Timer</button>
        <button id="stopTimer" class="btn-secondary" disabled>Stop Timer</button>
        <span id="timerDisplay" style="font-size: 1.5rem; margin-left: 20px;">00:00</span>
    </div>

    <form method="post" id="callForm">
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
            <input type="number" id="duration" name="duration" value="0" min="0" readonly>
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

<script>
let timerInterval;
let startTime;
let running = false;

document.getElementById('startTimer').addEventListener('click', function() {
    startTime = Date.now();
    running = true;
    document.getElementById('startTimer').disabled = true;
    document.getElementById('stopTimer').disabled = false;
    
    timerInterval = setInterval(() => {
        const elapsed = Math.floor((Date.now() - startTime) / 1000);
        const minutes = Math.floor(elapsed / 60);
        const seconds = elapsed % 60;
        document.getElementById('timerDisplay').textContent = 
            `${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
    }, 1000);
});

document.getElementById('stopTimer').addEventListener('click', function() {
    clearInterval(timerInterval);
    running = false;
    const elapsed = Math.floor((Date.now() - startTime) / 1000);
    document.getElementById('duration').value = elapsed;
    document.getElementById('startTimer').disabled = false;
    document.getElementById('stopTimer').disabled = true;
});

// If user tries to submit without stopping timer, stop automatically
document.getElementById('callForm').addEventListener('submit', function(e) {
    if (running) {
        clearInterval(timerInterval);
        const elapsed = Math.floor((Date.now() - startTime) / 1000);
        document.getElementById('duration').value = elapsed;
    }
});
</script>

<?php include 'includes/footer.php'; ?>