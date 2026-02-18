<?php
require_once 'includes/auth.php';
require_once 'includes/config.php';
require_once 'includes/functions.php';

// Handle bulk delete (admin or owner only)
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
        $ids      = $_POST['lead_ids'] ?? [];
        $newOwner = (int)($_POST['new_owner'] ?? 0);
        if (!empty($ids) && $newOwner) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt   = $pdo->prepare("UPDATE leads SET user_id = ? WHERE id IN ($placeholders)");
            $params = array_merge([$newOwner], $ids);
            if ($stmt->execute($params)) {
                $success = count($ids) . " leads reassigned.";
            } else {
                $error = "Failed to reassign leads.";
            }
        }
    }
}

// ── Filter parameters ────────────────────────────────────────────────────
$search         = $_GET['search']         ?? '';
$status         = $_GET['status']         ?? '';
$userId         = isset($_GET['user_id'])    ? (int)$_GET['user_id']    : 0;
$projectId      = isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0;
$show_imported  = isset($_GET['imported']) && $_GET['imported'] == 1;
$date_from      = $_GET['date_from']      ?? '';
$date_to        = $_GET['date_to']        ?? '';
$last_contacted = $_GET['last_contacted'] ?? '';

// ── Custom fields (admin only for filtering) ─────────────────────────────
$customFields = [];
if (isAdmin()) {
    $stmt = $pdo->prepare("SELECT * FROM custom_fields WHERE user_id = ? ORDER BY sort_order");
    $stmt->execute([$_SESSION['user_id']]);
    $customFields = $stmt->fetchAll();
}

// ── Accessible lead IDs ──────────────────────────────────────────────────
if (isAdmin()) {
    $accessibleIds = getAccessibleLeadIds($pdo, $_SESSION['user_id'], true);
} else {
    $accessibleIds = getAccessibleLeadIds($pdo, $_SESSION['user_id']);
}

// ── Projects visible as tabs ─────────────────────────────────────────────
// A user sees a project tab if they have at least one accessible lead in it.
// This covers: leads they own + leads shared with them (via lead_shares).
// Admin sees all projects that have any leads.
if (!empty($accessibleIds)) {
    $ph = implode(',', array_fill(0, count($accessibleIds), '?'));
    if (isAdmin()) {
        // Admin: all projects that have at least one lead
        $projStmt = $pdo->prepare(
            "SELECT DISTINCT p.id, p.name
             FROM projects p
             INNER JOIN leads l ON l.project_id = p.id
             WHERE l.id IN ($ph)
             ORDER BY p.name"
        );
        $projStmt->execute($accessibleIds);
    } else {
        // Regular user: projects where they own the project AND have leads in it,
        // OR projects where leads were shared with them exist
        $projStmt = $pdo->prepare(
            "SELECT DISTINCT p.id, p.name
             FROM projects p
             INNER JOIN leads l ON l.project_id = p.id
             WHERE l.id IN ($ph)
             ORDER BY p.name"
        );
        $projStmt->execute($accessibleIds);
    }
    $projects = $projStmt->fetchAll();
} else {
    $projects = [];
}

// ── Per-project lead counts (tab badges) ─────────────────────────────────
$projectCounts = [];
if (!empty($accessibleIds)) {
    $ph      = implode(',', array_fill(0, count($accessibleIds), '?'));
    $cntStmt = $pdo->prepare(
        "SELECT project_id, COUNT(*) AS cnt
         FROM leads
         WHERE id IN ($ph)
         GROUP BY project_id"
    );
    $cntStmt->execute($accessibleIds);
    foreach ($cntStmt->fetchAll() as $row) {
        $projectCounts[$row['project_id']] = (int)$row['cnt'];
    }
}

// ── Date column for the active project ───────────────────────────────────
// project_columns stores per-project custom columns (type: text/number/date/select).
// Values are stored in lead_column_values (column_id → project_columns.id).
// When the active project has a date-type column, we show & filter by it as
// "Last Contacted". The user can edit it inline — saved via quick-status.php.
$dateColumn = null;
if ($projectId > 0) {
    $dcStmt = $pdo->prepare(
        "SELECT * FROM project_columns
         WHERE project_id = ? AND column_type = 'date'
         ORDER BY sort_order ASC
         LIMIT 1"
    );
    $dcStmt->execute([$projectId]);
    $dateColumn = $dcStmt->fetch() ?: null;
}

// ── Build the leads query ────────────────────────────────────────────────
$leads = [];
if (!empty($accessibleIds)) {
    $ph = implode(',', array_fill(0, count($accessibleIds), '?'));

    // Sub-select for last_contacted:
    //   • If active project has a date column → read from lead_column_values
    //   • Otherwise → fall back to the most recent call in the calls table
    if ($dateColumn) {
        $dcId = (int)$dateColumn['id'];
        $lastContactedSub =
            "(SELECT lcv.value
              FROM lead_column_values lcv
              WHERE lcv.lead_id = l.id AND lcv.column_id = $dcId
              LIMIT 1) AS last_contacted";
    } else {
        $lastContactedSub =
            "(SELECT created_at
              FROM calls
              WHERE lead_id = l.id
              ORDER BY created_at DESC LIMIT 1) AS last_contacted";
    }

    $sql = "SELECT l.*,
                   p.name AS project_name,
                   $lastContactedSub,
                   u.name AS owner_name,
                   (l.user_id = ?) AS is_owner,
                   (SELECT permission FROM lead_shares
                    WHERE lead_id = l.id AND user_id = ?) AS shared_permission
            FROM leads l
            LEFT JOIN projects p ON l.project_id = p.id
            LEFT JOIN users u    ON l.user_id    = u.id
            WHERE l.id IN ($ph)";

    $params = [$_SESSION['user_id'], $_SESSION['user_id']];
    $params = array_merge($params, $accessibleIds);

    // Project tab filter
    if ($projectId > 0) {
        $sql      .= " AND l.project_id = ?";
        $params[]  = $projectId;
    }

    // User filter (admin only)
    if ($userId > 0 && isAdmin()) {
        $sql      .= " AND l.user_id = ?";
        $params[]  = $userId;
    }

    // Search
    if ($search) {
        $sql .= " AND (l.name LIKE ? OR l.company LIKE ? OR l.email LIKE ? OR l.phone LIKE ?)";
        $t    = "%$search%";
        $params = array_merge($params, [$t, $t, $t, $t]);
    }

    // Status
    if ($status && $status !== 'all') {
        $sql      .= " AND l.status = ?";
        $params[]  = $status;
    }

    // Imported filter
    if ($show_imported && isset($_SESSION['last_import_time'])) {
        $sql      .= " AND l.created_at >= ?";
        $params[]  = $_SESSION['last_import_time'];
    }

    // Date created range
    if (!empty($date_from)) {
        $sql      .= " AND DATE(l.created_at) >= ?";
        $params[]  = $date_from;
    }
    if (!empty($date_to)) {
        $sql      .= " AND DATE(l.created_at) <= ?";
        $params[]  = $date_to;
    }

    // Last contacted filter
    if (!empty($last_contacted)) {
        if ($dateColumn) {
            // Filter against lead_column_values for this project's date column
            $dcId = (int)$dateColumn['id'];
            $sub  = "(SELECT lcv.value FROM lead_column_values lcv
                      WHERE lcv.lead_id = l.id AND lcv.column_id = $dcId LIMIT 1)";
            switch ($last_contacted) {
                case 'today':
                    $sql .= " AND $sub = CURDATE()"; break;
                case 'yesterday':
                    $sql .= " AND $sub = DATE_SUB(CURDATE(), INTERVAL 1 DAY)"; break;
                case 'week':
                    $sql .= " AND $sub >= DATE_SUB(CURDATE(), INTERVAL 1 WEEK)"; break;
                case 'month':
                    $sql .= " AND $sub >= DATE_SUB(CURDATE(), INTERVAL 1 MONTH)"; break;
                case 'older':
                    $sql .= " AND ($sub < DATE_SUB(CURDATE(), INTERVAL 30 DAY) OR $sub IS NULL)"; break;
                case 'never':
                    $sql .= " AND $sub IS NULL"; break;
            }
        } else {
            // Fall back: filter against calls table
            $sub = "(SELECT MAX(created_at) FROM calls WHERE lead_id = l.id)";
            switch ($last_contacted) {
                case 'today':
                    $sql .= " AND $sub >= CURDATE()"; break;
                case 'yesterday':
                    $sql .= " AND $sub >= DATE_SUB(CURDATE(), INTERVAL 1 DAY) AND $sub < CURDATE()"; break;
                case 'week':
                    $sql .= " AND $sub >= DATE_SUB(CURDATE(), INTERVAL 1 WEEK)"; break;
                case 'month':
                    $sql .= " AND $sub >= DATE_SUB(CURDATE(), INTERVAL 1 MONTH)"; break;
                case 'older':
                    $sql .= " AND ($sub < DATE_SUB(CURDATE(), INTERVAL 30 DAY) OR $sub IS NULL)"; break;
                case 'never':
                    $sql .= " AND $sub IS NULL"; break;
            }
        }
    }

    // Custom field filters (admin only, uses lead_custom_values / custom_fields table)
    if (isAdmin() && !empty($customFields)) {
        foreach ($customFields as $field) {
            $paramName = 'custom_' . $field['id'];
            if (!empty($_GET[$paramName])) {
                $sql .= " AND EXISTS (
                    SELECT 1 FROM lead_custom_values lcv
                    WHERE lcv.lead_id = l.id AND lcv.field_id = ? AND lcv.value LIKE ?)";
                $params[] = $field['id'];
                $params[] = '%' . $_GET[$paramName] . '%';
            }
        }
    }

    $sql .= " ORDER BY l.created_at DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $leads = $stmt->fetchAll();
}

// ── Users list (admin only) ──────────────────────────────────────────────
$users = [];
if (isAdmin()) {
    $users = $pdo->query("SELECT id, name, email FROM users ORDER BY name")->fetchAll();
}

// ── Helper: build tab URL preserving current filters ─────────────────────
function projectTabUrl(int $pid): string {
    $params = $_GET;
    if ($pid === 0) {
        unset($params['project_id']);
    } else {
        $params['project_id'] = $pid;
    }
    $query = http_build_query($params);
    return 'leads.php' . ($query ? '?' . $query : '');
}

// ── Active filter flag ───────────────────────────────────────────────────
$hasFilters = $search || ($status && $status !== 'all') || $userId
           || $date_from || $date_to || $last_contacted
           || !empty(array_filter($_GET, fn($k) => str_starts_with($k, 'custom_'), ARRAY_FILTER_USE_KEY));

// Pass dateColumn info to JS for inline editing
$dateColumnId   = $dateColumn ? (int)$dateColumn['id']   : 0;
$dateColumnName = $dateColumn ? htmlspecialchars($dateColumn['name']) : 'Last Contacted';

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

<!-- ══ Project Tabs ═══════════════════════════════════════════════════════ -->
<?php if (!empty($projects)): ?>
<div class="project-tabs-wrapper" id="tabsWrapper">
    <nav class="project-tabs" id="projectTabs" role="tablist" aria-label="Projects">
        <?php foreach ($projects as $proj):
            $tabCount = $projectCounts[$proj['id']] ?? 0;
            $isActive = ($projectId === (int)$proj['id']);
        ?>
        <a href="<?= projectTabUrl((int)$proj['id']) ?>"
           class="project-tab <?= $isActive ? 'active' : '' ?>"
           role="tab"
           aria-selected="<?= $isActive ? 'true' : 'false' ?>">
            <?= htmlspecialchars($proj['name']) ?>
            <span class="tab-count"><?= $tabCount ?></span>
        </a>
        <?php endforeach; ?>

        <?php if (isAdmin()): ?>
        <a href="admin/projects.php" class="project-tab project-tab--manage" title="Manage Projects">
            ⚙ Manage
        </a>
        <?php endif; ?>
    </nav>
    <div class="tabs-fade-right" aria-hidden="true"></div>
</div>
<?php endif; ?>

<!-- ══ Advanced Filters ════════════════════════════════════════════════════ -->
<div class="card card--filters">
    <div class="filters-header">
        <h3>Advanced Filters</h3>
        <button id="toggleFilters" class="btn-secondary btn-small"
                aria-expanded="false" aria-controls="advancedFilters">Show</button>
    </div>
    <div id="advancedFilters" style="display:none; margin-top:15px;">
        <form method="get" id="filterForm">
            <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">
            <input type="hidden" name="status" value="<?= htmlspecialchars($status) ?>">
            <?php if ($userId):    ?><input type="hidden" name="user_id"    value="<?= $userId ?>"><?php endif; ?>
            <?php if ($projectId): ?><input type="hidden" name="project_id" value="<?= $projectId ?>"><?php endif; ?>

            <div class="filters-grid">
                <div class="form-group">
                    <label>Created: Date Range</label>
                    <div class="date-range-row">
                        <input type="date" name="date_from" value="<?= htmlspecialchars($date_from) ?>">
                        <input type="date" name="date_to"   value="<?= htmlspecialchars($date_to) ?>">
                    </div>
                </div>

                <div class="form-group">
                    <label>
                        <?= $dateColumnName ?>
                        <?php if ($dateColumn): ?>
                            <span class="field-hint">(project date column)</span>
                        <?php elseif (!$projectId): ?>
                            <span class="field-hint">— select a project tab to filter by date</span>
                        <?php endif; ?>
                    </label>
                    <select name="last_contacted">
                        <option value="">Any time</option>
                        <option value="today"     <?= $last_contacted === 'today'     ? 'selected' : '' ?>>Today</option>
                        <option value="yesterday" <?= $last_contacted === 'yesterday' ? 'selected' : '' ?>>Yesterday</option>
                        <option value="week"      <?= $last_contacted === 'week'      ? 'selected' : '' ?>>This week</option>
                        <option value="month"     <?= $last_contacted === 'month'     ? 'selected' : '' ?>>This month</option>
                        <option value="older"     <?= $last_contacted === 'older'     ? 'selected' : '' ?>>Older than 30 days</option>
                        <option value="never"     <?= $last_contacted === 'never'     ? 'selected' : '' ?>>Never</option>
                    </select>
                </div>

                <?php if (isAdmin() && !empty($customFields)): ?>
                    <?php foreach ($customFields as $field): ?>
                    <div class="form-group">
                        <label><?= htmlspecialchars($field['field_name']) ?></label>
                        <?php if ($field['field_type'] === 'select' && $field['field_options']): ?>
                            <?php $opts = json_decode($field['field_options'], true); ?>
                            <select name="custom_<?= $field['id'] ?>">
                                <option value="">Any</option>
                                <?php foreach ((array)$opts as $opt): ?>
                                    <option value="<?= htmlspecialchars($opt) ?>"
                                        <?= (($_GET['custom_' . $field['id']] ?? '') === $opt) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($opt) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        <?php else: ?>
                            <input type="<?= $field['field_type'] === 'number' ? 'number' : 'text' ?>"
                                   name="custom_<?= $field['id'] ?>"
                                   value="<?= htmlspecialchars($_GET['custom_' . $field['id']] ?? '') ?>"
                                   placeholder="Search…">
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <div class="filter-actions">
                <button type="submit" class="btn">Apply Filters</button>
                <a href="leads.php<?= $projectId ? '?project_id=' . $projectId : '' ?>"
                   class="btn-secondary">Clear Filters</a>
            </div>
        </form>
    </div>
</div>

<!-- ══ Main Card ══════════════════════════════════════════════════════════ -->
<div class="card">

    <!-- Toolbar -->
    <div class="leads-toolbar">
        <div class="toolbar-actions">
            <a href="lead.php?action=add" class="btn">+ Add Lead</a>
            <a href="import.php"          class="btn-secondary">Import</a>
            <a href="download_sample.php" class="btn-secondary">Sample CSV</a>
            <a href="export.php"          class="btn-secondary">Export CSV</a>
            <a href="analytics.php"       class="btn-secondary">Analytics</a>
            <?php if (isAdmin()): ?>
                <a href="admin/team.php"  class="btn-secondary">Team</a>
            <?php endif; ?>
        </div>

        <form method="get" class="toolbar-filters" id="quickFilterForm">
            <?php if ($projectId): ?>
                <input type="hidden" name="project_id" value="<?= $projectId ?>">
            <?php endif; ?>
            <?php if ($date_from):      ?><input type="hidden" name="date_from"      value="<?= htmlspecialchars($date_from) ?>"><?php endif; ?>
            <?php if ($date_to):        ?><input type="hidden" name="date_to"        value="<?= htmlspecialchars($date_to) ?>"><?php endif; ?>
            <?php if ($last_contacted): ?><input type="hidden" name="last_contacted" value="<?= htmlspecialchars($last_contacted) ?>"><?php endif; ?>

            <input type="text" name="search" placeholder="Search leads…"
                   value="<?= htmlspecialchars($search) ?>" class="toolbar-search">

            <select name="status" class="toolbar-select">
                <option value="all">All Status</option>
                <option value="new"            <?= $status === 'new'            ? 'selected' : '' ?>>New</option>
                <option value="contacted"      <?= $status === 'contacted'      ? 'selected' : '' ?>>Contacted</option>
                <option value="interested"     <?= $status === 'interested'     ? 'selected' : '' ?>>Interested</option>
                <option value="not_interested" <?= $status === 'not_interested' ? 'selected' : '' ?>>Not Interested</option>
                <option value="converted"      <?= $status === 'converted'      ? 'selected' : '' ?>>Converted</option>
            </select>

            <?php if (isAdmin() && !empty($users)): ?>
            <select name="user_id" class="toolbar-select">
                <option value="0">All Users</option>
                <?php foreach ($users as $user): ?>
                    <option value="<?= $user['id'] ?>" <?= $userId == $user['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($user['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php endif; ?>

            <button type="submit" class="btn">Filter</button>
            <?php if ($hasFilters): ?>
                <a href="leads.php<?= $projectId ? '?project_id=' . $projectId : '' ?>"
                   class="btn-secondary">Clear</a>
            <?php endif; ?>
        </form>
    </div>

    <!-- ── Leads list ── -->
    <?php if (count($leads) > 0): ?>
    <form method="post" id="bulk-actions-form">

        <div class="bulk-actions-bar">
            <button type="button" class="btn-secondary btn-small" onclick="selectAll()">Select All</button>
            <button type="submit" name="delete_selected" class="btn-danger btn-small"
                    onclick="return confirm('Delete selected leads?')">Delete Selected</button>
            <?php if (isAdmin()): ?>
                <select name="new_owner" class="bulk-select">
                    <option value="">Reassign to…</option>
                    <?php foreach ($users as $user): ?>
                        <option value="<?= $user['id'] ?>"><?= htmlspecialchars($user['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" name="assign_owner" class="btn-secondary btn-small"
                        onclick="return confirm('Reassign selected leads?')">Assign</button>
                <a href="?delete_all=confirm<?= $projectId ? '&project_id=' . $projectId : '' ?>"
                   class="btn-danger btn-small"
                   onclick="return confirm('Delete ALL leads? This cannot be undone.')">Delete All</a>
            <?php endif; ?>
        </div>

        <!-- ─── Desktop table ──────────────────────────────────────── -->
        <div class="table-container desktop-table">
            <table class="table" id="leads-table">
                <thead>
                    <tr>
                        <th><input type="checkbox" id="select-all" title="Select all"></th>
                        <th>Name</th>
                        <th>Company</th>
                        <th>Phone</th>
                        <th>Email</th>
                        <th>Status</th>
                        <?php if (!$projectId): ?><th>Project</th><?php endif; ?>
                        <th class="date-col-header">
                            <?= $dateColumnName ?>
                            <?php if ($dateColumn): ?>
                                <span class="col-hint" title="Click a date to edit it">✏</span>
                            <?php endif; ?>
                        </th>
                        <?php if (isAdmin()): ?><th>Owner</th><?php endif; ?>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($leads as $lead):
                        $rowClass = 'row-' . str_replace('_', '-', $lead['status']);
                    ?>
                    <tr data-lead-id="<?= $lead['id'] ?>" class="<?= $rowClass ?>">
                        <td><input type="checkbox" name="lead_ids[]" value="<?= $lead['id'] ?>" class="lead-checkbox"></td>
                        <td>
                            <?php if (!$lead['is_owner'] && !isAdmin()): ?>
                                <span class="shared-badge" title="Shared (<?= $lead['shared_permission'] ?>)">🔗</span>
                            <?php endif; ?>
                            <span class="editable" data-field="name"><?= htmlspecialchars($lead['name']) ?></span>
                        </td>
                        <td><span class="editable" data-field="company"><?= htmlspecialchars($lead['company'] ?: '—') ?></span></td>
                        <td>
                            <?php if (!empty($lead['phone'])): ?>
                            <div class="contact-actions">
                                <a href="tel:<?= urlencode($lead['phone']) ?>" class="contact-icon" title="Call">📞</a>
                                <a href="https://wa.me/<?= preg_replace('/[^0-9]/', '', $lead['phone']) ?>"
                                   target="_blank" class="contact-icon whatsapp" title="WhatsApp">💬</a>
                                <span class="editable" data-field="phone"><?= htmlspecialchars($lead['phone']) ?></span>
                            </div>
                            <?php else: ?>
                            <span class="editable" data-field="phone">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($lead['email'])): ?>
                            <div class="contact-actions">
                                <span class="contact-icon email copy-email"
                                      data-email="<?= htmlspecialchars($lead['email']) ?>"
                                      title="Copy email">📧</span>
                                <span class="editable" data-field="email"><?= htmlspecialchars($lead['email']) ?></span>
                            </div>
                            <?php else: ?>
                            <span class="editable" data-field="email">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <select class="status-select" data-lead-id="<?= $lead['id'] ?>" data-current="<?= $lead['status'] ?>">
                                <option value="new"            <?= $lead['status'] === 'new'            ? 'selected' : '' ?>>New</option>
                                <option value="contacted"      <?= $lead['status'] === 'contacted'      ? 'selected' : '' ?>>Contacted</option>
                                <option value="interested"     <?= $lead['status'] === 'interested'     ? 'selected' : '' ?>>Interested</option>
                                <option value="not_interested" <?= $lead['status'] === 'not_interested' ? 'selected' : '' ?>>Not Interested</option>
                                <option value="converted"      <?= $lead['status'] === 'converted'      ? 'selected' : '' ?>>Converted</option>
                            </select>
                        </td>
                        <?php if (!$projectId): ?>
                        <td><?= htmlspecialchars($lead['project_name'] ?: '—') ?></td>
                        <?php endif; ?>
                        <td class="last-contacted-cell"
                            <?php if ($dateColumn): ?>
                                data-column-id="<?= $dateColumn['id'] ?>"
                                data-lead-id="<?= $lead['id'] ?>"
                                data-value="<?= htmlspecialchars($lead['last_contacted'] ?? '') ?>"
                                title="Click to edit date"
                            <?php endif; ?>>
                            <?php
                            $lcVal = $lead['last_contacted'] ?? '';
                            if ($lcVal) {
                                $ts = strtotime($lcVal);
                                echo $ts ? '<span class="date-display">' . date('M d, Y', $ts) . '</span>'
                                         : '<span class="date-display">' . htmlspecialchars($lcVal) . '</span>';
                            } else {
                                echo '<span class="never-badge">Never</span>';
                            }
                            ?>
                            <?php if ($dateColumn): ?>
                                <input type="date"
                                       class="date-inline-input"
                                       value="<?= htmlspecialchars($lcVal) ?>"
                                       style="display:none;">
                            <?php endif; ?>
                        </td>
                        <?php if (isAdmin()): ?>
                        <td>
                            <select class="owner-select" data-lead-id="<?= $lead['id'] ?>">
                                <?php foreach ($users as $user): ?>
                                    <option value="<?= $user['id'] ?>" <?= $lead['user_id'] == $user['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($user['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <?php endif; ?>
                        <td><?= date('M d, Y', strtotime($lead['created_at'])) ?></td>
                        <td>
                            <div class="dropdown">
                                <button type="button" class="btn-secondary btn-small dropdown-toggle">Actions ▼</button>
                                <div class="dropdown-content">
                                    <a href="lead.php?id=<?= $lead['id'] ?>">View Details</a>
                                    <a href="lead.php?action=edit&id=<?= $lead['id'] ?>">Edit</a>
                                    <a href="log-call.php?lead_id=<?= $lead['id'] ?>">Log Call</a>
                                    <a href="#" class="quick-note" data-lead-id="<?= $lead['id'] ?>">Add Note</a>
                                    <?php if (canDeleteLead($pdo, $lead['id'], $_SESSION['user_id'])): ?>
                                        <a href="#" class="delete-single" data-lead-id="<?= $lead['id'] ?>">Delete</a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- ─── Mobile cards ──────────────────────────────────────── -->
        <div class="mobile-cards">
            <?php foreach ($leads as $lead):
                $statusLabel = ucfirst(str_replace('_', ' ', $lead['status']));
                $cardClass   = 'card-' . str_replace('_', '-', $lead['status']);
                $statusClass = 'status-' . str_replace('_', '-', $lead['status']);
                $lcVal       = $lead['last_contacted'] ?? '';
            ?>
            <div class="lead-card <?= $cardClass ?>" data-lead-id="<?= $lead['id'] ?>">
                <!-- Header -->
                <div class="lead-card__header">
                    <input type="checkbox" name="lead_ids[]" value="<?= $lead['id'] ?>"
                           class="lead-checkbox" style="flex-shrink:0;margin-top:3px;">
                    <div class="lead-card__name">
                        <?php if (!$lead['is_owner'] && !isAdmin()): ?>
                            <span class="shared-badge" title="Shared">🔗</span>
                        <?php endif; ?>
                        <strong><?= htmlspecialchars($lead['name']) ?></strong>
                        <?php if ($lead['company']): ?>
                            <span class="lead-card__company"><?= htmlspecialchars($lead['company']) ?></span>
                        <?php endif; ?>
                    </div>
                    <span class="status-badge <?= $statusClass ?>"><?= $statusLabel ?></span>
                </div>

                <!-- Body -->
                <div class="lead-card__body">
                    <?php if (!empty($lead['phone'])): ?>
                    <div class="lead-card__row">
                        <span class="lead-card__label">Phone</span>
                        <span class="lead-card__value">
                            <a href="tel:<?= urlencode($lead['phone']) ?>" class="contact-icon" title="Call">📞</a>
                            <a href="https://wa.me/<?= preg_replace('/[^0-9]/', '', $lead['phone']) ?>"
                               target="_blank" class="contact-icon whatsapp" title="WhatsApp">💬</a>
                            <?= htmlspecialchars($lead['phone']) ?>
                        </span>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($lead['email'])): ?>
                    <div class="lead-card__row">
                        <span class="lead-card__label">Email</span>
                        <span class="lead-card__value">
                            <span class="contact-icon email copy-email"
                                  data-email="<?= htmlspecialchars($lead['email']) ?>"
                                  title="Copy email">📧</span>
                            <?= htmlspecialchars($lead['email']) ?>
                        </span>
                    </div>
                    <?php endif; ?>

                    <div class="lead-card__row">
                        <span class="lead-card__label"><?= $dateColumnName ?></span>
                        <span class="lead-card__value">
                            <?php if ($dateColumn): ?>
                                <span class="last-contacted-cell mobile-date-cell"
                                      data-column-id="<?= $dateColumn['id'] ?>"
                                      data-lead-id="<?= $lead['id'] ?>"
                                      data-value="<?= htmlspecialchars($lcVal) ?>">
                                    <?php if ($lcVal):
                                        $ts = strtotime($lcVal);
                                        echo '<span class="date-display">' . ($ts ? date('M d, Y', $ts) : htmlspecialchars($lcVal)) . '</span>';
                                    else: ?>
                                        <span class="never-badge">Never</span>
                                    <?php endif; ?>
                                    <input type="date" class="date-inline-input"
                                           value="<?= htmlspecialchars($lcVal) ?>"
                                           style="display:none;">
                                </span>
                            <?php else: ?>
                                <?php if ($lcVal): $ts = strtotime($lcVal);
                                    echo $ts ? date('M d, Y', $ts) : htmlspecialchars($lcVal);
                                else: ?><span class="never-badge">Never</span><?php endif; ?>
                            <?php endif; ?>
                        </span>
                    </div>

                    <?php if (!$projectId): ?>
                    <div class="lead-card__row">
                        <span class="lead-card__label">Project</span>
                        <span class="lead-card__value"><?= htmlspecialchars($lead['project_name'] ?: '—') ?></span>
                    </div>
                    <?php endif; ?>

                    <div class="lead-card__row">
                        <span class="lead-card__label">Status</span>
                        <span class="lead-card__value">
                            <select class="status-select" data-lead-id="<?= $lead['id'] ?>" data-current="<?= $lead['status'] ?>">
                                <option value="new"            <?= $lead['status'] === 'new'            ? 'selected' : '' ?>>New</option>
                                <option value="contacted"      <?= $lead['status'] === 'contacted'      ? 'selected' : '' ?>>Contacted</option>
                                <option value="interested"     <?= $lead['status'] === 'interested'     ? 'selected' : '' ?>>Interested</option>
                                <option value="not_interested" <?= $lead['status'] === 'not_interested' ? 'selected' : '' ?>>Not Interested</option>
                                <option value="converted"      <?= $lead['status'] === 'converted'      ? 'selected' : '' ?>>Converted</option>
                            </select>
                        </span>
                    </div>

                    <?php if (isAdmin()): ?>
                    <div class="lead-card__row">
                        <span class="lead-card__label">Owner</span>
                        <span class="lead-card__value">
                            <select class="owner-select" data-lead-id="<?= $lead['id'] ?>">
                                <?php foreach ($users as $user): ?>
                                    <option value="<?= $user['id'] ?>" <?= $lead['user_id'] == $user['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($user['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </span>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Footer -->
                <div class="lead-card__footer">
                    <span class="lead-card__created">Created <?= date('M d, Y', strtotime($lead['created_at'])) ?></span>
                    <div class="lead-card__actions">
                        <a href="lead.php?id=<?= $lead['id'] ?>"              class="btn-secondary btn-small">View</a>
                        <a href="lead.php?action=edit&id=<?= $lead['id'] ?>"  class="btn-secondary btn-small">Edit</a>
                        <a href="log-call.php?lead_id=<?= $lead['id'] ?>"     class="btn-secondary btn-small">Log Call</a>
                        <button type="button" class="btn-secondary btn-small quick-note"
                                data-lead-id="<?= $lead['id'] ?>">Note</button>
                        <?php if (canDeleteLead($pdo, $lead['id'], $_SESSION['user_id'])): ?>
                            <button type="button" class="btn-danger btn-small delete-single"
                                    data-lead-id="<?= $lead['id'] ?>">Delete</button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

    </form>
    <?php else: ?>
    <div class="empty-state">
        <p>No leads found<?= $projectId ? ' in this project' : '' ?>.</p>
        <?php if ($hasFilters): ?>
            <a href="leads.php<?= $projectId ? '?project_id=' . $projectId : '' ?>"
               class="btn-secondary">Clear filters</a>
        <?php endif; ?>
        <a href="lead.php?action=add" class="btn">Add your first lead</a>
    </div>
    <?php endif; ?>
</div>

<!-- ══ Quick Note Modal ════════════════════════════════════════════════════ -->
<div id="quickNoteModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="noteModalTitle">
    <div class="modal-content">
        <button class="close" aria-label="Close">&times;</button>
        <h3 id="noteModalTitle">Add Quick Note</h3>
        <input type="hidden" id="noteLeadId">
        <textarea id="noteText" rows="4" placeholder="Enter note…" style="width:100%;margin:10px 0;"></textarea>
        <button id="saveNote" class="btn">Save Note</button>
    </div>
</div>

<!-- ══ Styles ═════════════════════════════════════════════════════════════ -->
<style>
/* ── Project Tabs ──────────────────────────────────────────────────────── */
.project-tabs-wrapper {
    position: relative;
    margin-bottom: 20px;
}
.project-tabs {
    display: flex;
    gap: 4px;
    overflow-x: auto;
    scrollbar-width: none;
    -ms-overflow-style: none;
    border-bottom: 2px solid var(--border-color);
    padding-bottom: 0;
}
.project-tabs::-webkit-scrollbar { display: none; }

.project-tab {
    flex-shrink: 0;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 9px 16px;
    border: 1px solid transparent;
    border-bottom: none;
    border-radius: 6px 6px 0 0;
    font-size: 0.9rem;
    font-weight: 500;
    color: var(--navbar-text);
    text-decoration: none;
    background: transparent;
    transition: background-color 0.15s, color 0.15s;
    position: relative;
    bottom: -2px;
    white-space: nowrap;
    cursor: pointer;
}
.project-tab:hover {
    background-color: var(--table-row-hover);
    color: var(--text-color);
    text-decoration: none;
}
.project-tab.active {
    background-color: var(--card-bg);
    border-color: var(--border-color);
    border-bottom-color: var(--card-bg);
    color: var(--link-color);
    font-weight: 600;
}
.project-tab--manage {
    margin-left: auto;
    color: var(--footer-text);
    font-size: 0.85rem;
    border-style: dashed;
    opacity: 0.8;
}
.project-tab--manage:hover { opacity: 1; color: var(--text-color); }

.tab-count {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 20px;
    height: 20px;
    padding: 0 5px;
    border-radius: 10px;
    font-size: 0.75rem;
    font-weight: 600;
    background-color: var(--btn-secondary-bg);
    color: var(--btn-secondary-text);
}
.project-tab.active .tab-count {
    background-color: var(--link-color);
    color: #fff;
}

.tabs-fade-right {
    position: absolute;
    right: 0;
    top: 0;
    height: calc(100% - 2px);
    width: 50px;
    background: linear-gradient(to right, transparent, var(--bg-color) 80%);
    pointer-events: none;
    opacity: 0;
    transition: opacity 0.2s;
}
.project-tabs-wrapper.has-overflow .tabs-fade-right { opacity: 1; }

/* ── Filters ───────────────────────────────────────────────────────────── */
.card--filters { margin-bottom: 20px; }
.filters-header { display: flex; justify-content: space-between; align-items: center; }
.filters-header h3 { margin: 0; }
.filters-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 15px; }
.date-range-row { display: flex; gap: 6px; }
.date-range-row input { flex: 1; }
.filter-actions { display: flex; gap: 10px; margin-top: 12px; flex-wrap: wrap; }
.field-hint { font-weight: 400; font-size: 0.78rem; color: var(--footer-text); }

/* ── Toolbar ───────────────────────────────────────────────────────────── */
.leads-toolbar {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 12px;
    margin-bottom: 16px;
    flex-wrap: wrap;
}
.toolbar-actions { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; }
.toolbar-filters { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
.toolbar-search, .toolbar-select {
    padding: 8px 12px;
    border: 1px solid var(--input-border);
    border-radius: 6px;
    font-size: 0.95rem;
    background-color: var(--input-bg);
    color: var(--text-color);
    width: auto;
}
.toolbar-search { min-width: 160px; }

/* ── Bulk actions ──────────────────────────────────────────────────────── */
.bulk-actions-bar {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    align-items: center;
    margin-bottom: 12px;
    padding: 8px 12px;
    background-color: var(--table-header-bg);
    border-radius: 6px;
    border: 1px solid var(--border-color);
}
.bulk-select {
    padding: 6px 8px;
    font-size: 0.9rem;
    border-radius: 4px;
    width: auto;
    border: 1px solid var(--input-border);
    background-color: var(--input-bg);
    color: var(--text-color);
}

/* ── Date column ───────────────────────────────────────────────────────── */
.date-col-header { white-space: nowrap; }
.col-hint { font-size: 0.75rem; opacity: 0.6; margin-left: 3px; cursor: default; }

.last-contacted-cell[data-column-id] {
    cursor: pointer;
    min-width: 110px;
}
.last-contacted-cell[data-column-id]:hover .date-display {
    text-decoration: underline dotted;
    color: var(--link-color);
}
.last-contacted-cell[data-column-id]:hover .never-badge {
    color: var(--link-color);
    text-decoration: underline dotted;
}
.date-inline-input {
    width: 130px;
    padding: 4px 6px;
    border: 2px solid var(--link-color);
    border-radius: 4px;
    font-size: 0.9rem;
    background-color: var(--input-bg);
    color: var(--text-color);
}

/* ── Desktop table vs mobile cards ────────────────────────────────────── */
.desktop-table { display: block; }
.mobile-cards  { display: none; }

/* ── Mobile lead cards ─────────────────────────────────────────────────── */
.lead-card {
    border: 1px solid var(--border-color);
    border-radius: 8px;
    margin-bottom: 12px;
    overflow: hidden;
    background: var(--card-bg);
}
.lead-card__header {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    padding: 12px 14px 8px;
    border-bottom: 1px solid var(--border-color);
}
.lead-card__name {
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 2px;
    min-width: 0;
}
.lead-card__name strong {
    font-size: 1rem;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.lead-card__company { font-size: 0.82rem; color: var(--footer-text); }
.lead-card__body { padding: 4px 14px; }
.lead-card__row {
    display: flex;
    gap: 8px;
    padding: 6px 0;
    border-bottom: 1px solid var(--border-color);
    align-items: center;
    flex-wrap: wrap;
}
.lead-card__row:last-child { border-bottom: none; }
.lead-card__label {
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    color: var(--footer-text);
    min-width: 90px;
    flex-shrink: 0;
}
.lead-card__value {
    font-size: 0.92rem;
    display: flex;
    align-items: center;
    gap: 6px;
    flex-wrap: wrap;
}
.lead-card__value select {
    padding: 4px 6px;
    font-size: 0.85rem;
    width: auto;
    border-radius: 4px;
    border: 1px solid var(--input-border);
    background-color: var(--input-bg);
    color: var(--text-color);
}
.lead-card__footer {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 8px 14px;
    background-color: var(--table-header-bg);
    border-top: 1px solid var(--border-color);
    flex-wrap: wrap;
    gap: 8px;
}
.lead-card__created { font-size: 0.78rem; color: var(--footer-text); }
.lead-card__actions { display: flex; gap: 6px; flex-wrap: wrap; }

/* Card status colour strips */
.card-new            { border-left: 4px solid #3b82f6; }
.card-contacted      { border-left: 4px solid #f59e0b; }
.card-interested     { border-left: 4px solid #10b981; }
.card-not-interested { border-left: 4px solid #ef4444; opacity:.88; }
.card-converted      { border-left: 4px solid #8b5cf6; }

/* ── Misc ──────────────────────────────────────────────────────────────── */
.never-badge { font-size: 0.82rem; color: var(--footer-text); font-style: italic; }
.empty-state {
    text-align: center;
    padding: 40px 20px;
    color: var(--footer-text);
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 12px;
}

/* ── Responsive ─────────────────────────────────────────────────────────── */
@media (max-width: 900px) {
    .desktop-table { display: none !important; }
    .mobile-cards  { display: block; }

    .leads-toolbar      { flex-direction: column; }
    .toolbar-actions    { width: 100%; }
    .toolbar-filters    { width: 100%; flex-direction: column; align-items: stretch; }
    .toolbar-search,
    .toolbar-select     { width: 100%; font-size: 16px; }
    .toolbar-actions .btn,
    .toolbar-actions .btn-secondary { flex: 1 1 calc(50% - 4px); text-align: center; }

    .bulk-actions-bar           { flex-direction: column; align-items: stretch; }
    .bulk-actions-bar button,
    .bulk-actions-bar .btn,
    .bulk-actions-bar .btn-secondary,
    .bulk-actions-bar .btn-danger,
    .bulk-actions-bar a         { width: 100%; text-align: center; }
    .bulk-select                { width: 100%; font-size: 16px; }

    .filters-grid   { grid-template-columns: 1fr; }
    .date-range-row { flex-direction: column; }

    .project-tab { padding: 7px 11px; font-size: 0.82rem; }
}
</style>

<!-- ══ Scripts ════════════════════════════════════════════════════════════ -->
<script>
// Config passed from PHP
const DATE_COLUMN_ID   = <?= $dateColumnId ?>;
const DATE_COLUMN_NAME = <?= json_encode($dateColumnName) ?>;

// ── Tab overflow fade ────────────────────────────────────────────────────
(function () {
    const wrapper = document.querySelector('.project-tabs-wrapper');
    const tabs    = document.getElementById('projectTabs');
    if (!tabs || !wrapper) return;
    const check = () => wrapper.classList.toggle('has-overflow', tabs.scrollWidth > tabs.clientWidth + 2);
    check();
    window.addEventListener('resize', check);
    tabs.querySelector('.project-tab.active')?.scrollIntoView({ inline: 'nearest', behavior: 'auto' });
})();

// ── Toggle advanced filters ──────────────────────────────────────────────
document.getElementById('toggleFilters').addEventListener('click', function () {
    const panel  = document.getElementById('advancedFilters');
    const hiding = panel.style.display !== 'none';
    panel.style.display = hiding ? 'none' : 'block';
    this.textContent    = hiding ? 'Show' : 'Hide';
    this.setAttribute('aria-expanded', hiding ? 'false' : 'true');
});
(function () {
    const p = new URLSearchParams(window.location.search);
    if (['date_from','date_to','last_contacted'].some(k => p.get(k))
        || [...p.keys()].some(k => k.startsWith('custom_'))) {
        document.getElementById('advancedFilters').style.display = 'block';
        document.getElementById('toggleFilters').textContent     = 'Hide';
        document.getElementById('toggleFilters').setAttribute('aria-expanded','true');
    }
})();

// ── Select all ───────────────────────────────────────────────────────────
document.getElementById('select-all')?.addEventListener('change', function () {
    document.querySelectorAll('.lead-checkbox').forEach(cb => cb.checked = this.checked);
});
function selectAll() {
    document.querySelectorAll('.lead-checkbox').forEach(cb => cb.checked = true);
    const sa = document.getElementById('select-all');
    if (sa) sa.checked = true;
}

// ── Inline date editing for Last Contacted (lead_column_values) ──────────
// Clicking the date cell shows a native date picker.
// On change/blur it POSTs to quick-status.php with field='column_value'.
if (DATE_COLUMN_ID > 0) {
    document.querySelectorAll('.last-contacted-cell[data-column-id]').forEach(cell => {
        const input = cell.querySelector('.date-inline-input');
        if (!input) return;

        cell.addEventListener('click', function (e) {
            if (e.target === input) return;
            // Show input, hide display
            cell.querySelector('.date-display, .never-badge')?.style && (cell.querySelector('.date-display') || cell.querySelector('.never-badge')).style.setProperty('display','none');
            input.style.display = 'inline-block';
            input.focus();
            // Try to open the picker
            try { input.showPicker(); } catch(ex) {}
        });

        const saveDate = function () {
            const leadId  = cell.dataset.leadId;
            const colId   = cell.dataset.columnId;
            const newVal  = input.value; // YYYY-MM-DD or ''

            fetch('quick-status.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    lead_id:   leadId,
                    field:     'column_value',
                    column_id: colId,
                    value:     newVal
                })
            })
            .then(r => r.json())
            .then(data => {
                input.style.display = 'none';
                // Update the visible label
                let display = cell.querySelector('.date-display');
                let never   = cell.querySelector('.never-badge');

                if (data.success) {
                    cell.dataset.value = newVal;
                    if (newVal) {
                        const d = new Date(newVal + 'T00:00:00');
                        const label = d.toLocaleDateString('en-US', { month:'short', day:'2-digit', year:'numeric' });
                        if (display) {
                            display.textContent  = label;
                            display.style.display = '';
                        } else {
                            // Was "Never" — swap never badge for date span
                            if (never) never.remove();
                            const span = document.createElement('span');
                            span.className   = 'date-display';
                            span.textContent = label;
                            cell.insertBefore(span, input);
                        }
                        if (never) never.remove();
                    } else {
                        // Cleared — show Never
                        if (display) display.remove();
                        if (!never) {
                            const span = document.createElement('span');
                            span.className   = 'never-badge';
                            span.textContent = 'Never';
                            cell.insertBefore(span, input);
                        } else {
                            never.style.display = '';
                        }
                    }
                    showNotification(DATE_COLUMN_NAME + ' updated', 'success');
                } else {
                    // Restore old display
                    if (display) display.style.display = '';
                    if (never)   never.style.display   = '';
                    alert('Error: ' + data.error);
                }
            })
            .catch(() => {
                input.style.display = 'none';
                const display = cell.querySelector('.date-display');
                const never   = cell.querySelector('.never-badge');
                if (display) display.style.display = '';
                if (never)   never.style.display   = '';
                alert('Network error');
            });
        };

        input.addEventListener('change', saveDate);
        input.addEventListener('blur',   saveDate);
        input.addEventListener('keydown', e => {
            if (e.key === 'Escape') {
                input.style.display = 'none';
                const display = cell.querySelector('.date-display');
                const never   = cell.querySelector('.never-badge');
                if (display) display.style.display = '';
                if (never)   never.style.display   = '';
            }
        });
    });
}

// ── Inline status update ─────────────────────────────────────────────────
document.querySelectorAll('.status-select').forEach(sel => {
    sel.addEventListener('change', function () {
        const leadId    = this.dataset.leadId;
        const newStatus = this.value;
        fetch('quick-status.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ lead_id: leadId, field: 'status', value: newStatus })
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                this.dataset.current = newStatus;
                const tr = this.closest('tr');
                if (tr) {
                    tr.className = tr.className.replace(/row-[\w-]+/g, '');
                    tr.classList.add('row-' + newStatus.replace(/_/g, '-'));
                }
                const card = this.closest('.lead-card');
                if (card) {
                    card.className = card.className.replace(/card-[\w-]+/g, '');
                    card.classList.add('card-' + newStatus.replace(/_/g, '-'));
                    const badge = card.querySelector('.status-badge');
                    if (badge) {
                        badge.className   = 'status-badge status-' + newStatus.replace(/_/g, '-');
                        badge.textContent = newStatus.replace(/_/g,' ').replace(/\b\w/g, c => c.toUpperCase());
                    }
                }
                showNotification('Status updated', 'success');
            } else {
                alert('Error: ' + data.error);
                this.value = this.dataset.current;
            }
        })
        .catch(() => { alert('Network error'); this.value = this.dataset.current; });
    });
});

// ── Inline edit text fields (double-click, desktop) ──────────────────────
document.querySelectorAll('.editable').forEach(cell => {
    cell.addEventListener('dblclick', function () {
        if (this.classList.contains('editing')) return;
        const field        = this.dataset.field;
        const leadId       = this.closest('tr')?.dataset.leadId;
        if (!leadId) return;
        const currentValue = this.innerText === '—' ? '' : this.innerText;

        const input = document.createElement('input');
        input.type  = 'text';
        input.value = currentValue;
        this.innerHTML = '';
        this.appendChild(input);
        this.classList.add('editing');
        input.focus(); input.select();

        const save = () => {
            const v = input.value.trim();
            if (v === currentValue) { this.innerHTML = v || '—'; this.classList.remove('editing'); return; }
            fetch('quick-status.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ lead_id: leadId, field, value: v })
            })
            .then(r => r.json())
            .then(data => {
                this.innerHTML = data.success ? (v || '—') : (currentValue || '—');
                if (data.success) showNotification('Updated', 'success');
                else alert('Error: ' + data.error);
                this.classList.remove('editing');
            })
            .catch(() => { this.innerHTML = currentValue || '—'; this.classList.remove('editing'); });
        };
        input.addEventListener('blur', save);
        input.addEventListener('keydown', e => {
            if (e.key === 'Enter')  { e.preventDefault(); save(); }
            if (e.key === 'Escape') { this.innerHTML = currentValue || '—'; this.classList.remove('editing'); }
        });
    });
});

// ── Quick note modal ─────────────────────────────────────────────────────
const modal      = document.getElementById('quickNoteModal');
const noteLeadId = document.getElementById('noteLeadId');
const noteText   = document.getElementById('noteText');

document.querySelectorAll('.quick-note').forEach(el => {
    el.addEventListener('click', function (e) {
        e.preventDefault();
        noteLeadId.value    = this.dataset.leadId;
        noteText.value      = '';
        modal.style.display = 'block';
        noteText.focus();
    });
});
modal.querySelector('.close').onclick = () => modal.style.display = 'none';
window.addEventListener('click',   e => { if (e.target === modal) modal.style.display = 'none'; });
window.addEventListener('keydown', e => { if (e.key === 'Escape') modal.style.display = 'none'; });

document.getElementById('saveNote').addEventListener('click', function () {
    const note = noteText.value.trim();
    if (!note) { alert('Please enter a note'); return; }
    fetch('quick-status.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ lead_id: noteLeadId.value, field: 'notes', value: note })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) { showNotification('Note added', 'success'); modal.style.display = 'none'; }
        else alert('Error: ' + data.error);
    })
    .catch(() => alert('Network error'));
});

// ── Copy email ───────────────────────────────────────────────────────────
document.querySelectorAll('.copy-email').forEach(icon => {
    icon.addEventListener('click', e => {
        e.preventDefault();
        navigator.clipboard.writeText(icon.dataset.email)
            .then(() => showNotification('Email copied!', 'success'))
            .catch(() => alert('Could not copy'));
    });
});

// ── Delete single ────────────────────────────────────────────────────────
document.querySelectorAll('.delete-single').forEach(el => {
    el.addEventListener('click', function (e) {
        e.preventDefault();
        if (!confirm('Delete this lead?')) return;
        const leadId = this.dataset.leadId;
        fetch('quick-status.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ lead_id: leadId, field: 'delete', value: '' })
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                document.querySelector(`tr[data-lead-id="${leadId}"]`)?.remove();
                document.querySelector(`.lead-card[data-lead-id="${leadId}"]`)?.remove();
                showNotification('Lead deleted', 'success');
            } else { alert('Error: ' + data.error); }
        })
        .catch(() => alert('Network error'));
    });
});

// ── Owner change (admin only) ────────────────────────────────────────────
<?php if (isAdmin()): ?>
document.querySelectorAll('.owner-select').forEach(sel => {
    sel.addEventListener('change', function () {
        const leadId   = this.dataset.leadId;
        const newOwner = this.value;
        const prev     = this.querySelector('option[selected]')?.value || '';
        if (!confirm('Reassign this lead?')) { this.value = prev; return; }
        fetch('quick-status.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ lead_id: leadId, field: 'owner', value: newOwner })
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                this.querySelectorAll('option').forEach(o => o.removeAttribute('selected'));
                this.querySelector(`option[value="${newOwner}"]`)?.setAttribute('selected', '');
                showNotification('Owner updated', 'success');
            } else { alert('Error: ' + data.error); this.value = prev; }
        })
        .catch(() => { alert('Network error'); this.value = prev; });
    });
});
<?php endif; ?>

// ── Toast notification ───────────────────────────────────────────────────
function showNotification(msg, type) {
    const n = document.createElement('div');
    n.className = `alert alert-${type}`;
    n.style.cssText = 'position:fixed;top:20px;right:20px;z-index:9999;min-width:220px;'
                    + 'box-shadow:0 4px 12px rgba(0,0,0,.18);';
    n.innerText = msg;
    document.body.appendChild(n);
    setTimeout(() => n.remove(), 3000);
}
</script>

<?php include 'includes/footer.php'; ?>