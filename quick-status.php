<?php
// quick-status.php — handles AJAX updates from leads.php
// Add this case to your existing switch/if block that handles the 'field' parameter.
// The new case 'column_value' saves to the lead_column_values table.

require_once 'includes/auth.php';
require_once 'includes/config.php';
require_once 'includes/functions.php';

header('Content-Type: application/json');

$data     = json_decode(file_get_contents('php://input'), true);
$leadId   = (int)($data['lead_id']   ?? 0);
$field    = $data['field']            ?? '';
$value    = $data['value']            ?? '';
$columnId = (int)($data['column_id'] ?? 0);

if (!$leadId || !$field) {
    echo json_encode(['success' => false, 'error' => 'Missing parameters']);
    exit;
}

// Verify user can access this lead
$accessibleIds = getAccessibleLeadIds($pdo, $_SESSION['user_id'], isAdmin());
if (!in_array($leadId, $accessibleIds)) {
    echo json_encode(['success' => false, 'error' => 'Access denied']);
    exit;
}

// ── Handle each field type ───────────────────────────────────────────────
switch ($field) {

    // ── Standard lead fields ──────────────────────────────────────────────
    case 'name':
    case 'company':
    case 'phone':
    case 'email':
        $allowed = ['name', 'company', 'phone', 'email'];
        if (!in_array($field, $allowed)) {
            echo json_encode(['success' => false, 'error' => 'Invalid field']);
            exit;
        }
        $stmt = $pdo->prepare("UPDATE leads SET $field = ? WHERE id = ?");
        echo json_encode(['success' => $stmt->execute([$value ?: null, $leadId])]);
        break;

    // ── Status ────────────────────────────────────────────────────────────
    case 'status':
        $allowed = ['new','contacted','interested','not_interested','converted'];
        if (!in_array($value, $allowed)) {
            echo json_encode(['success' => false, 'error' => 'Invalid status']);
            exit;
        }
        $stmt = $pdo->prepare("UPDATE leads SET status = ? WHERE id = ?");
        echo json_encode(['success' => $stmt->execute([$value, $leadId])]);
        break;

    // ── Notes ─────────────────────────────────────────────────────────────
    case 'notes':
        $stmt = $pdo->prepare("UPDATE leads SET notes = CONCAT(IFNULL(notes,''), '\n', ?) WHERE id = ?");
        echo json_encode(['success' => $stmt->execute([$value, $leadId])]);
        break;

    // ── Owner (admin only) ────────────────────────────────────────────────
    case 'owner':
        if (!isAdmin()) {
            echo json_encode(['success' => false, 'error' => 'Admin only']);
            exit;
        }
        $stmt = $pdo->prepare("UPDATE leads SET user_id = ? WHERE id = ?");
        echo json_encode(['success' => $stmt->execute([(int)$value, $leadId])]);
        break;

    // ── New: last_contacted (hardcoded column) ────────────────────────────
    case 'last_contacted':
        // Update the dedicated last_contacted column in leads table
        $stmt = $pdo->prepare("UPDATE leads SET last_contacted = ? WHERE id = ?");
        echo json_encode(['success' => $stmt->execute([$value ?: null, $leadId])]);
        break;

    // ── Project column value (lead_column_values table) ───────────────────
    // This is used for date-type project columns (e.g. "Last Contacted").
    // Saves to lead_column_values using UPSERT (INSERT … ON DUPLICATE KEY UPDATE).
    case 'column_value':
        if (!$columnId) {
            echo json_encode(['success' => false, 'error' => 'Missing column_id']);
            exit;
        }
        // Verify the column belongs to the same project as this lead
        $chk = $pdo->prepare(
            "SELECT pc.id FROM project_columns pc
             INNER JOIN leads l ON l.project_id = pc.project_id
             WHERE pc.id = ? AND l.id = ?
             LIMIT 1"
        );
        $chk->execute([$columnId, $leadId]);
        if (!$chk->fetch()) {
            echo json_encode(['success' => false, 'error' => 'Column does not belong to this lead\'s project']);
            exit;
        }

        if ($value === '' || $value === null) {
            // Clear the value — delete the row so it shows as "Never"
            $stmt = $pdo->prepare(
                "DELETE FROM lead_column_values WHERE lead_id = ? AND column_id = ?"
            );
            echo json_encode(['success' => $stmt->execute([$leadId, $columnId])]);
        } else {
            // Upsert: insert or update if the row already exists
            $stmt = $pdo->prepare(
                "INSERT INTO lead_column_values (lead_id, column_id, value)
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE value = VALUES(value)"
            );
            echo json_encode(['success' => $stmt->execute([$leadId, $columnId, $value])]);
        }
        break;

    // ── Delete lead ───────────────────────────────────────────────────────
    case 'delete':
        if (!canDeleteLead($pdo, $leadId, $_SESSION['user_id'])) {
            echo json_encode(['success' => false, 'error' => 'Permission denied']);
            exit;
        }
        $stmt = $pdo->prepare("DELETE FROM leads WHERE id = ?");
        echo json_encode(['success' => $stmt->execute([$leadId])]);
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Unknown field: ' . htmlspecialchars($field)]);
}