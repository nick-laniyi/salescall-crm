<?php
// IMPORTANT: This file expects a 'last_contacted' column in the 'leads' table.
// If you haven't added it yet, run this SQL:
// ALTER TABLE leads ADD COLUMN last_contacted DATE DEFAULT NULL;

require_once 'includes/auth.php';
require_once 'includes/config.php';
require_once 'includes/functions.php';

// ── Handle bulk delete (scoped to current project) ──────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_selected'])) {
    $ids = $_POST['lead_ids'] ?? [];
    $currentProjectId = (int)($_POST['project_id'] ?? 0);
    
    if (!empty($ids) && is_array($ids)) {
        $deleted = 0;
        foreach ($ids as $leadId) {
            if (canDeleteLead($pdo, $leadId, $_SESSION['user_id'])) {
                // Verify lead belongs to current project
                $chk = $pdo->prepare("SELECT id FROM leads WHERE id = ? AND project_id = ?");
                $chk->execute([$leadId, $currentProjectId]);
                if ($chk->fetch()) {
                    $stmt = $pdo->prepare("DELETE FROM leads WHERE id = ?");
                    $stmt->execute([$leadId]);
                    $deleted++;
                }
            }
        }
        $success = "$deleted lead(s) deleted.";
    }
}

// ── Handle delete all (admin only, scoped to current project) ───────────
if (isset($_GET['delete_all']) && $_GET['delete_all'] === 'confirm') {
    $currentProjectId = (int)($_GET['project_id'] ?? 0);
    if (!isAdmin()) {
        $error = "Only admin can delete all leads.";
    } elseif (!$currentProjectId) {
        $error = "No project selected.";
    } else {
        $stmt = $pdo->prepare("DELETE FROM leads WHERE project_id = ?");
        if ($stmt->execute([$currentProjectId])) {
            $success = "All leads in this project deleted.";
        } else {
            $error = "Failed to delete leads.";
        }
    }
}

// ── Handle assign owner (admin only) ─────────────────────────────────────
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

// ── Handle column rename (admin or project owner only, AJAX) ────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['rename_column'])) {
    header('Content-Type: application/json');
    $colId   = (int)($_POST['column_id'] ?? 0);
    $newName = trim($_POST['new_name'] ?? '');
    if (!$colId || !$newName) {
        echo json_encode(['success' => false, 'error' => 'Missing parameters']);
        exit;
    }
    // Check project ownership via the column's project
    $stmt = $pdo->prepare("SELECT project_id FROM project_columns WHERE id = ?");
    $stmt->execute([$colId]);
    $col = $stmt->fetch();
    if (!$col) {
        echo json_encode(['success' => false, 'error' => 'Column not found']);
        exit;
    }
    $projectId = $col['project_id'];
    $ownerCheck = $pdo->prepare("SELECT user_id FROM projects WHERE id = ?");
    $ownerCheck->execute([$projectId]);
    $project = $ownerCheck->fetch();
    if (!isAdmin() && $project['user_id'] != $_SESSION['user_id']) {
        echo json_encode(['success' => false, 'error' => 'Permission denied']);
        exit;
    }
    $stmt = $pdo->prepare("UPDATE project_columns SET name = ? WHERE id = ?");
    echo json_encode(['success' => $stmt->execute([$newName, $colId])]);
    exit;
}

// ── Handle add new column (admin or project owner only, AJAX) ────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_column'])) {
    header('Content-Type: application/json');
    $projectId = (int)($_POST['project_id'] ?? 0);
    $colName   = trim($_POST['column_name'] ?? '');
    $colType   = $_POST['column_type'] ?? 'text';
    $colOptions = $_POST['column_options'] ?? ''; // for select type

    if (!$projectId || !$colName) {
        echo json_encode(['success' => false, 'error' => 'Missing parameters']);
        exit;
    }

    // Check project ownership
    $ownerCheck = $pdo->prepare("SELECT user_id FROM projects WHERE id = ?");
    $ownerCheck->execute([$projectId]);
    $project = $ownerCheck->fetch();
    if (!$project) {
        echo json_encode(['success' => false, 'error' => 'Project not found']);
        exit;
    }
    if (!isAdmin() && $project['user_id'] != $_SESSION['user_id']) {
        echo json_encode(['success' => false, 'error' => 'Permission denied']);
        exit;
    }

    $allowedTypes = ['text', 'email', 'phone', 'number', 'date', 'select'];
    if (!in_array($colType, $allowedTypes)) {
        $colType = 'text';
    }

    // Get current max sort_order
    $maxStmt = $pdo->prepare("SELECT MAX(sort_order) as max_order FROM project_columns WHERE project_id = ?");
    $maxStmt->execute([$projectId]);
    $maxOrder = (int)($maxStmt->fetchColumn() ?? -1);

    $stmt = $pdo->prepare("INSERT INTO project_columns (project_id, name, column_type, options, sort_order) VALUES (?, ?, ?, ?, ?)");
    $success = $stmt->execute([$projectId, $colName, $colType, $colOptions, $maxOrder + 1]);
    echo json_encode([
        'success' => $success,
        'column_id' => $success ? $pdo->lastInsertId() : null
    ]);
    exit;
}

// ── Filter parameters ────────────────────────────────────────────────────
$search         = $_GET['search']         ?? '';
$status         = $_GET['status']         ?? '';
$userId         = isset($_GET['user_id'])    ? (int)$_GET['user_id']    : 0;
$projectId      = isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0;
$show_imported  = isset($_GET['imported']) && $_GET['imported'] == 1;
$date_from      = $_GET['date_from']      ?? '';      // for created_at
$date_to        = $_GET['date_to']        ?? '';
$last_contact_from = $_GET['last_contact_from'] ?? ''; // for last_contacted
$last_contact_to   = $_GET['last_contact_to']   ?? '';

// ── Get current project owner (for column management permissions) ────────
$projectOwnerId = 0;
if ($projectId > 0) {
    $stmt = $pdo->prepare("SELECT user_id FROM projects WHERE id = ?");
    $stmt->execute([$projectId]);
    $projectOwnerId = (int)$stmt->fetchColumn();
}
$canManageColumns = isAdmin() || ($projectOwnerId == $_SESSION['user_id']);

// ── Accessible lead IDs ──────────────────────────────────────────────────
if (isAdmin()) {
    $accessibleIds = getAccessibleLeadIds($pdo, $_SESSION['user_id'], true);
} else {
    $accessibleIds = getAccessibleLeadIds($pdo, $_SESSION['user_id']);
}

// ── Projects visible as tabs ─────────────────────────────────────────────
if (isAdmin()) {
    $projStmt = $pdo->query("SELECT id, name, user_id FROM projects ORDER BY name");
    $projects = $projStmt->fetchAll();
} else {
    if (!empty($accessibleIds)) {
        $ph = implode(',', array_fill(0, count($accessibleIds), '?'));
        $projStmt = $pdo->prepare(
            "SELECT DISTINCT p.id, p.name, p.user_id
             FROM projects p
             WHERE p.user_id = ?
                OR EXISTS (
                    SELECT 1 FROM leads l
                    WHERE l.project_id = p.id AND l.id IN ($ph)
                )
             ORDER BY p.name"
        );
        $params = [$_SESSION['user_id']];
        $params = array_merge($params, $accessibleIds);
        $projStmt->execute($params);
        $projects = $projStmt->fetchAll();
    } else {
        $projStmt = $pdo->prepare("SELECT id, name, user_id FROM projects WHERE user_id = ? ORDER BY name");
        $projStmt->execute([$_SESSION['user_id']]);
        $projects = $projStmt->fetchAll();
    }
}

// Count owned projects for manage link (non-admin)
$ownedProjectCount = 0;
foreach ($projects as $proj) {
    if ($proj['user_id'] == $_SESSION['user_id']) {
        $ownedProjectCount++;
    }
}

// ── Project lead counts ──────────────────────────────────────────────────
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

// ── Load project columns (including options for select) ──────────────────
$projectColumns = [];
if ($projectId > 0) {
    $colStmt = $pdo->prepare(
        "SELECT * FROM project_columns WHERE project_id = ? ORDER BY sort_order ASC"
    );
    $colStmt->execute([$projectId]);
    $projectColumns = $colStmt->fetchAll();
}

// ── Build leads query (including last_contacted) ─────────────────────────
$leads = [];
if (!empty($accessibleIds) && $projectId > 0) {
    $ph = implode(',', array_fill(0, count($accessibleIds), '?'));

    $sql = "SELECT l.id, l.name, l.status, l.user_id, l.created_at, l.last_contacted,
                   p.name AS project_name,
                   u.name AS owner_name,
                   (l.user_id = ?) AS is_owner,
                   (SELECT permission FROM lead_shares
                    WHERE lead_id = l.id AND user_id = ?) AS shared_permission
            FROM leads l
            LEFT JOIN projects p ON l.project_id = p.id
            LEFT JOIN users u    ON l.user_id    = u.id
            WHERE l.id IN ($ph) AND l.project_id = ?";

    $params = [$_SESSION['user_id'], $_SESSION['user_id']];
    $params = array_merge($params, $accessibleIds);
    $params[] = $projectId;

    // User filter (admin only)
    if ($userId > 0 && isAdmin()) {
        $sql      .= " AND l.user_id = ?";
        $params[]  = $userId;
    }

    // Search
    if ($search) {
        $sql .= " AND (l.name LIKE ?
                   OR EXISTS (
                       SELECT 1 FROM lead_column_values lcv
                       WHERE lcv.lead_id = l.id AND lcv.value LIKE ?
                   ))";
        $t = "%$search%";
        $params[] = $t;
        $params[] = $t;
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

    // Date range for created_at
    if (!empty($date_from)) {
        $sql      .= " AND DATE(l.created_at) >= ?";
        $params[]  = $date_from;
    }
    if (!empty($date_to)) {
        $sql      .= " AND DATE(l.created_at) <= ?";
        $params[]  = $date_to;
    }

    // Date range for last_contacted
    if (!empty($last_contact_from)) {
        $sql      .= " AND DATE(l.last_contacted) >= ?";
        $params[]  = $last_contact_from;
    }
    if (!empty($last_contact_to)) {
        $sql      .= " AND DATE(l.last_contacted) <= ?";
        $params[]  = $last_contact_to;
    }

    $sql .= " ORDER BY l.created_at DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $leads = $stmt->fetchAll();
    
    // Fetch column values
    if (!empty($leads) && !empty($projectColumns)) {
        $leadIds = array_column($leads, 'id');
        $ph = implode(',', array_fill(0, count($leadIds), '?'));
        $vStmt = $pdo->prepare(
            "SELECT lead_id, column_id, value
             FROM lead_column_values
             WHERE lead_id IN ($ph)"
        );
        $vStmt->execute($leadIds);
        
        $valuesByLead = [];
        foreach ($vStmt->fetchAll() as $row) {
            $valuesByLead[$row['lead_id']][$row['column_id']] = $row['value'];
        }
        
        foreach ($leads as &$lead) {
            $lead['column_values'] = $valuesByLead[$lead['id']] ?? [];
        }
        unset($lead);
    }
}

// ── Users list (admin only) ──────────────────────────────────────────────
$users = [];
if (isAdmin()) {
    $users = $pdo->query("SELECT id, name, email FROM users ORDER BY name")->fetchAll();
}

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

$hasFilters = $search || ($status && $status !== 'all') || $userId || $date_from || $date_to || $last_contact_from || $last_contact_to;

include 'includes/header.php';
?>

<h1>Leads</h1>

<?php if (isset($_GET['imported'])): ?>
<div class="alert alert-success">
    <?= htmlspecialchars($_GET['count'] ?? '') ?> leads imported successfully.
    <?php if (isset($_GET['skipped']) && (int)$_GET['skipped'] > 0): ?>
        <br><small><?= (int)$_GET['skipped'] ?> rows skipped (validation errors).</small>
    <?php endif; ?>
    <?php if (isset($_SESSION['last_import_time'])): ?>
        <br><a href="leads.php?project_id=<?= $projectId ?>&imported=1">View newly imported</a> |
        <a href="leads.php?project_id=<?= $projectId ?>">View all</a>
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
    <nav class="project-tabs" id="projectTabs" role="tablist">
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

        <?php if (isAdmin() || $ownedProjectCount > 0): ?>
        <a href="<?= isAdmin() ? 'admin/projects.php' : 'user/projects.php' ?>" class="project-tab project-tab--manage" title="Manage Projects">
            ⚙ Manage
        </a>
        <?php endif; ?>
    </nav>
    <div class="tabs-fade-right"></div>
</div>
<?php elseif (isAdmin()): ?>
<div class="alert alert-info">
    No projects yet. <a href="import.php" class="btn-secondary" style="display:inline-block;padding:4px 10px;margin-left:8px;">Import leads</a> to create your first project.
</div>
<?php endif; ?>

<!-- ══ Filters (including last contacted) ═════════════════════════════════ -->
<div class="card card--filters">
    <div class="filters-header">
        <h3>Filters</h3>
        <button id="toggleFilters" class="btn-secondary btn-small">Show</button>
    </div>
    <div id="advancedFilters" style="display:none; margin-top:15px;">
        <form method="get">
            <input type="hidden" name="project_id" value="<?= $projectId ?>">
            
            <div class="filters-grid">
                <div class="form-group">
                    <label>Created: Date Range</label>
                    <div class="date-range-row">
                        <input type="date" name="date_from" value="<?= htmlspecialchars($date_from) ?>">
                        <input type="date" name="date_to"   value="<?= htmlspecialchars($date_to) ?>">
                    </div>
                </div>
                <div class="form-group">
                    <label>Last Contacted: Date Range</label>
                    <div class="date-range-row">
                        <input type="date" name="last_contact_from" value="<?= htmlspecialchars($last_contact_from) ?>">
                        <input type="date" name="last_contact_to"   value="<?= htmlspecialchars($last_contact_to) ?>">
                    </div>
                </div>
            </div>
            
            <div class="filter-actions">
                <button type="submit" class="btn">Apply Filters</button>
                <a href="leads.php?project_id=<?= $projectId ?>" class="btn-secondary">Clear</a>
            </div>
        </form>
    </div>
</div>

<!-- ══ Main Card ══════════════════════════════════════════════════════════ -->
<div class="card">

    <!-- Toolbar -->
    <div class="leads-toolbar">
        <div class="toolbar-actions">
            <a href="lead.php?action=add&project_id=<?= $projectId ?>" class="btn">+ Add Lead</a>
            <a href="import.php" class="btn-secondary">Import</a>
            <a href="export.php?project_id=<?= $projectId ?>" class="btn-secondary">Export CSV</a>
            <a href="analytics.php?project_id=<?= $projectId ?>" class="btn-secondary">Analytics</a>
            <?php if (isAdmin()): ?>
                <a href="admin/team.php" class="btn-secondary">Team</a>
            <?php endif; ?>
        </div>

        <form method="get" class="toolbar-filters">
            <input type="hidden" name="project_id" value="<?= $projectId ?>">
            <?php if ($date_from): ?><input type="hidden" name="date_from" value="<?= htmlspecialchars($date_from) ?>"><?php endif; ?>
            <?php if ($date_to):   ?><input type="hidden" name="date_to"   value="<?= htmlspecialchars($date_to) ?>"><?php endif; ?>
            <?php if ($last_contact_from): ?><input type="hidden" name="last_contact_from" value="<?= htmlspecialchars($last_contact_from) ?>"><?php endif; ?>
            <?php if ($last_contact_to):   ?><input type="hidden" name="last_contact_to"   value="<?= htmlspecialchars($last_contact_to) ?>"><?php endif; ?>

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
                <a href="leads.php?project_id=<?= $projectId ?>" class="btn-secondary">Clear</a>
            <?php endif; ?>
        </form>
    </div>

    <?php if (!$projectId): ?>
    <div class="empty-state">
        <p>Please select a project tab above to view leads.</p>
        <a href="import.php" class="btn">Import Leads</a>
    </div>
    
    <?php elseif (count($leads) > 0 && !empty($projectColumns)): ?>
    <form method="post" id="bulk-actions-form">
        <input type="hidden" name="project_id" value="<?= $projectId ?>">

        <div class="bulk-actions-bar">
            <button type="button" class="btn-secondary btn-small" onclick="selectAll()">
                Select All (<?= count($leads) ?>)
            </button>
            <button type="submit" name="delete_selected" class="btn-danger btn-small"
                    onclick="return confirm('Delete selected leads from this project?')">Delete Selected</button>
            <?php if (isAdmin()): ?>
                <select name="new_owner" class="bulk-select">
                    <option value="">Reassign to…</option>
                    <?php foreach ($users as $user): ?>
                        <option value="<?= $user['id'] ?>"><?= htmlspecialchars($user['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" name="assign_owner" class="btn-secondary btn-small"
                        onclick="return confirm('Reassign selected leads?')">Assign</button>
                <a href="?project_id=<?= $projectId ?>&delete_all=confirm"
                   class="btn-danger btn-small"
                   onclick="return confirm('Delete ALL <?= count($leads) ?> leads in this project? This cannot be undone.')">
                    Delete All in Project
                </a>
            <?php endif; ?>
        </div>

        <!-- Desktop table with improved wrapping and email send button -->
        <div class="table-container desktop-table">
            <table class="table">
                <thead>
                    <tr>
                        <th style="width:40px;"><input type="checkbox" id="select-all"></th>
                        <?php foreach ($projectColumns as $col): ?>
                            <th class="col-header <?= $canManageColumns ? 'col-header--editable' : '' ?>"
                                data-column-id="<?= $col['id'] ?>"
                                title="<?= $canManageColumns ? 'Double-click to rename' : '' ?>">
                                <span class="col-header-text"><?= htmlspecialchars($col['name']) ?></span>
                                <?php if ($canManageColumns): ?>
                                    <input type="text" class="col-header-input" value="<?= htmlspecialchars($col['name']) ?>" style="display:none;">
                                <?php endif; ?>
                            </th>
                        <?php endforeach; ?>
                        
                        <?php if ($canManageColumns): ?>
                            <th style="width:50px;">
                                <button type="button" class="btn-add-column" title="Add column">+</button>
                            </th>
                        <?php endif; ?>
                        
                        <!-- Hardcoded last_contacted column -->
                        <th>Last Contacted</th>
                        <th style="width:100px;">Status</th>
                        <?php if (isAdmin()): ?><th style="width:100px;">Owner</th><?php endif; ?>
                        <th style="width:100px;">Created</th>
                        <th style="width:80px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($leads as $lead):
                        $rowClass = 'row-' . str_replace('_', '-', $lead['status']);
                        $colVals  = $lead['column_values'];
                    ?>
                    <tr data-lead-id="<?= $lead['id'] ?>" class="<?= $rowClass ?>">
                        <td><input type="checkbox" name="lead_ids[]" value="<?= $lead['id'] ?>" class="lead-checkbox"></td>
                        
                        <?php foreach ($projectColumns as $col):
                            $val     = $colVals[$col['id']] ?? '';
                            $colType = $col['column_type'];
                            $options = $col['options'] ?? '';
                        ?>
                        <td class="col-cell col-type-<?= $colType ?>"
                            data-column-id="<?= $col['id'] ?>"
                            data-column-type="<?= $colType ?>"
                            data-lead-id="<?= $lead['id'] ?>"
                            data-value="<?= htmlspecialchars($val) ?>"
                            data-options="<?= htmlspecialchars($options) ?>">
                            
                            <?php if ($val): ?>
                                <?php if ($colType === 'email'): ?>
                                    <div class="contact-actions">
                                        <span class="contact-icon email copy-email"
                                              data-email="<?= htmlspecialchars($val) ?>"
                                              title="Copy">📧</span>
                                        <?php if (!empty($val)): ?>
                                            <a href="email/compose.php?lead_id=<?= $lead['id'] ?>&email=<?= urlencode($val) ?>"
                                               class="contact-icon send-email"
                                               target="_blank"
                                               title="Send Email">✉️</a>
                                        <?php endif; ?>
                                        <span class="cell-display"><?= htmlspecialchars($val) ?></span>
                                    </div>
                                <?php elseif ($colType === 'phone'): ?>
                                    <div class="contact-actions">
                                        <a href="tel:<?= urlencode($val) ?>" class="contact-icon" title="Call">📞</a>
                                        <a href="https://wa.me/<?= preg_replace('/[^0-9]/', '', $val) ?>"
                                           target="_blank" class="contact-icon whatsapp" title="WhatsApp">💬</a>
                                        <span class="cell-display"><?= htmlspecialchars($val) ?></span>
                                    </div>
                                <?php elseif ($colType === 'date'): ?>
                                    <?php
                                    $ts = strtotime($val);
                                    echo '<span class="cell-display">' . ($ts ? date('M d, Y', $ts) : htmlspecialchars($val)) . '</span>';
                                    ?>
                                <?php elseif ($colType === 'select'): ?>
                                    <span class="cell-display"><?= htmlspecialchars($val) ?></span>
                                    <select class="cell-inline-select" style="display:none;">
                                        <?php
                                        $optLines = explode("\n", $options);
                                        foreach ($optLines as $opt):
                                            $opt = trim($opt);
                                            if ($opt === '') continue;
                                        ?>
                                        <option value="<?= htmlspecialchars($opt) ?>" <?= $val == $opt ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($opt) ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php else: ?>
                                    <span class="cell-display"><?= htmlspecialchars($val) ?></span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="never-badge">—</span>
                                <?php if ($colType === 'select'): ?>
                                    <select class="cell-inline-select" style="display:none;">
                                        <?php
                                        $optLines = explode("\n", $options);
                                        foreach ($optLines as $opt):
                                            $opt = trim($opt);
                                            if ($opt === '') continue;
                                        ?>
                                        <option value="<?= htmlspecialchars($opt) ?>"><?= htmlspecialchars($opt) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php endif; ?>
                            <?php endif; ?>
                            
                            <?php if ($colType !== 'select'): ?>
                            <input type="<?= $colType === 'date' ? 'date' : 'text' ?>"
                                   class="cell-inline-input"
                                   value="<?= htmlspecialchars($val) ?>"
                                   style="display:none;">
                            <?php endif; ?>
                        </td>
                        <?php endforeach; ?>
                        
                        <?php if ($canManageColumns): ?><td></td><?php endif; ?>
                        
                        <!-- Last Contacted column -->
                        <td class="col-cell col-type-date"
                            data-field="last_contacted"
                            data-lead-id="<?= $lead['id'] ?>"
                            data-value="<?= htmlspecialchars($lead['last_contacted'] ?? '') ?>">
                            <?php if (!empty($lead['last_contacted'])): ?>
                                <?php $ts = strtotime($lead['last_contacted']); ?>
                                <span class="cell-display"><?= date('M d, Y', $ts) ?></span>
                            <?php else: ?>
                                <span class="never-badge">—</span>
                            <?php endif; ?>
                            <input type="date" class="cell-inline-input"
                                   value="<?= htmlspecialchars($lead['last_contacted'] ?? '') ?>"
                                   style="display:none;">
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
                                <button type="button" class="btn-secondary btn-small dropdown-toggle">⋯</button>
                                <div class="dropdown-content">
                                    <a href="lead.php?id=<?= $lead['id'] ?>">View</a>
                                    <a href="lead.php?action=edit&id=<?= $lead['id'] ?>">Edit</a>
                                    <a href="#" class="log-call" data-lead-id="<?= $lead['id'] ?>">Log Call</a>
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

        <!-- Mobile cards (also updated with email send button) -->
        <div class="mobile-cards">
            <?php foreach ($leads as $lead):
                $statusLabel = ucfirst(str_replace('_', ' ', $lead['status']));
                $cardClass   = 'card-' . str_replace('_', '-', $lead['status']);
                $statusClass = 'status-' . str_replace('_', '-', $lead['status']);
                $colVals     = $lead['column_values'];
            ?>
            <div class="lead-card <?= $cardClass ?>" data-lead-id="<?= $lead['id'] ?>">
                <div class="lead-card__header">
                    <input type="checkbox" name="lead_ids[]" value="<?= $lead['id'] ?>"
                           class="lead-checkbox" style="flex-shrink:0;">
                    <div class="lead-card__name">
                        <strong><?= htmlspecialchars($lead['name']) ?></strong>
                    </div>
                    <span class="status-badge <?= $statusClass ?>"><?= $statusLabel ?></span>
                </div>

                <div class="lead-card__body">
                    <?php foreach ($projectColumns as $col):
                        $val = $colVals[$col['id']] ?? '';
                        if (!$val) continue;
                    ?>
                    <div class="lead-card__row">
                        <span class="lead-card__label"><?= htmlspecialchars($col['name']) ?></span>
                        <span class="lead-card__value">
                            <?php if ($col['column_type'] === 'email'): ?>
                                <span class="contact-icon email copy-email" data-email="<?= htmlspecialchars($val) ?>">📧</span>
                                <a href="email/compose.php?lead_id=<?= $lead['id'] ?>&email=<?= urlencode($val) ?>"
                                   class="contact-icon send-email"
                                   target="_blank"
                                   title="Send Email">✉️</a>
                            <?php elseif ($col['column_type'] === 'phone'): ?>
                                <a href="tel:<?= urlencode($val) ?>" class="contact-icon">📞</a>
                                <a href="https://wa.me/<?= preg_replace('/[^0-9]/', '', $val) ?>"
                                   target="_blank" class="contact-icon whatsapp">💬</a>
                            <?php endif; ?>
                            <?= htmlspecialchars($val) ?>
                        </span>
                    </div>
                    <?php endforeach; ?>

                    <!-- Last Contacted row -->
                    <div class="lead-card__row">
                        <span class="lead-card__label">Last Contacted</span>
                        <span class="lead-card__value">
                            <?= $lead['last_contacted'] ? date('M d, Y', strtotime($lead['last_contacted'])) : '—' ?>
                        </span>
                    </div>

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
                </div>

                <div class="lead-card__footer">
                    <span class="lead-card__created">Created <?= date('M d, Y', strtotime($lead['created_at'])) ?></span>
                    <div class="lead-card__actions">
                        <a href="lead.php?id=<?= $lead['id'] ?>" class="btn-secondary btn-small">View</a>
                        <a href="lead.php?action=edit&id=<?= $lead['id'] ?>" class="btn-secondary btn-small">Edit</a>
                        <button type="button" class="btn-secondary btn-small call-now" data-lead-id="<?= $lead['id'] ?>" title="Log call (auto)">📞 Call</button>
                        <?php if (canDeleteLead($pdo, $lead['id'], $_SESSION['user_id'])): ?>
                            <button type="button" class="btn-danger btn-small delete-single" data-lead-id="<?= $lead['id'] ?>">Del</button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

    </form>
    <?php else: ?>
    <div class="empty-state">
        <p>No leads found in this project.</p>
        <?php if ($hasFilters): ?>
            <a href="leads.php?project_id=<?= $projectId ?>" class="btn-secondary">Clear filters</a>
        <?php endif; ?>
        <a href="import.php" class="btn">Import Leads</a>
    </div>
    <?php endif; ?>
</div>

<!-- Log Call Modal -->
<div id="logCallModal" class="modal" style="display:none;">
    <div class="modal-content" style="max-width:500px;">
        <button class="close" onclick="closeLogCallModal()">&times;</button>
        <h3>Log Call</h3>
        <div style="margin-bottom: 15px;">
            <button id="modalStartTimer" class="btn">Start Timer</button>
            <button id="modalStopTimer" class="btn-secondary" disabled>Stop Timer</button>
            <span id="modalTimerDisplay" style="font-size:1.3rem; margin-left:15px;">00:00</span>
        </div>
        <form id="logCallForm">
            <input type="hidden" name="lead_id" id="modalLeadId">
            <div class="form-group">
                <label for="modalOutcome">Outcome *</label>
                <select id="modalOutcome" name="outcome" required>
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
                <label for="modalDuration">Duration (seconds)</label>
                <input type="number" id="modalDuration" name="duration" value="0" min="0" readonly>
            </div>
            <div class="form-group">
                <label for="modalFollowUp">Follow-up Date</label>
                <input type="date" id="modalFollowUp" name="follow_up_date">
            </div>
            <div class="form-group">
                <label for="modalNotes">Notes</label>
                <textarea id="modalNotes" name="notes" rows="3"></textarea>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn">Log Call</button>
                <button type="button" class="btn-secondary" onclick="closeLogCallModal()">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Add Column Modal (shown only if user can manage columns) -->
<?php if ($projectId > 0 && $canManageColumns): ?>
<div id="addColumnModal" class="modal">
    <div class="modal-content">
        <button class="close">&times;</button>
        <h3>Add New Column</h3>
        <div class="form-group">
            <label>Column Name</label>
            <input type="text" id="newColumnName" placeholder="e.g., Industry">
        </div>
        <div class="form-group">
            <label>Data Type</label>
            <select id="newColumnType">
                <option value="text">Text</option>
                <option value="email">Email</option>
                <option value="phone">Phone</option>
                <option value="number">Number</option>
                <option value="date">Date</option>
                <option value="select">Dropdown</option>
            </select>
        </div>
        <div class="form-group" id="columnOptionsGroup" style="display:none;">
            <label>Dropdown Options (one per line)</label>
            <textarea id="newColumnOptions" rows="4" placeholder="Option 1&#10;Option 2&#10;Option 3"></textarea>
        </div>
        <button class="btn" onclick="saveNewColumn()">Add Column</button>
    </div>
</div>
<?php endif; ?>

<style>
/* Tabs */
.project-tabs-wrapper { position: relative; margin-bottom: 20px; }
.project-tabs {
    display: flex;
    gap: 4px;
    overflow-x: auto;
    scrollbar-width: none;
    border-bottom: 2px solid var(--border-color);
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
    transition: all 0.15s;
    position: relative;
    bottom: -2px;
    white-space: nowrap;
}
.project-tab:hover {
    background-color: var(--table-row-hover);
    color: var(--text-color);
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
}

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

/* Column header editing */
.col-header { position: relative; }
.col-header--editable { cursor: pointer; }
.col-header--editable:hover .col-header-text {
    text-decoration: underline dotted;
    color: var(--link-color);
}
.col-header-input {
    width: 100%;
    padding: 4px 6px;
    border: 2px solid var(--link-color);
    border-radius: 4px;
    font-size: inherit;
    font-weight: 600;
    background-color: var(--input-bg);
    color: var(--text-color);
}

.btn-add-column {
    width: 100%;
    padding: 4px;
    border: 1px dashed var(--border-color);
    border-radius: 4px;
    background: transparent;
    color: var(--link-color);
    cursor: pointer;
    font-size: 1.2rem;
    font-weight: bold;
    transition: all 0.15s;
}
.btn-add-column:hover {
    background-color: var(--table-row-hover);
    border-color: var(--link-color);
}

/* Cell editing */
.col-cell { cursor: pointer; min-width: 100px; }
.col-cell:hover .cell-display {
    text-decoration: underline dotted;
    color: var(--link-color);
}
.cell-inline-input, .cell-inline-select {
    width: 100%;
    max-width: 180px;
    padding: 4px 6px;
    border: 2px solid var(--link-color);
    border-radius: 4px;
    font-size: 0.9rem;
    background-color: var(--input-bg);
    color: var(--text-color);
}
.cell-inline-select {
    height: auto;
}

/* Call button style */
.call-now {
    background: #34a853;
    color: white;
    border: none;
}
.call-now:hover {
    background: #2d8e46;
}

/* Fix table layout and wrapping */
.desktop-table {
    overflow-x: auto;
    display: block;
    width: 100%;
}
.table {
    width: 100%;
    border-collapse: collapse;
    table-layout: fixed; /* Enforces column widths */
}
.table th, .table td {
    word-wrap: break-word;
    white-space: normal;
    padding: 8px;
    vertical-align: middle;
}
/* Fixed widths for non‑dynamic columns */
.table th:first-child, .table td:first-child { width: 40px; }          /* Checkbox */
.table th:last-child, .table td:last-child { width: 80px; }            /* Actions */
.table th:nth-last-child(3), .table td:nth-last-child(3) { width: 100px; } /* Status */
.table th:nth-last-child(2), .table td:nth-last-child(2) { width: 100px; } /* Owner */
.table th:nth-last-child(1), .table td:nth-last-child(1) { width: 100px; } /* Created */
/* The project columns will share the remaining space */
.col-cell {
    min-width: 120px; /* Prevent them from becoming too narrow */
}

/* Email action icons */
.contact-actions {
    display: flex;
    gap: 8px;
    align-items: center;
    flex-wrap: wrap;
}

/* Ensure email column cells wrap properly */
.contact-actions {
    display: flex;
    gap: 8px;
    align-items: flex-start;
    flex-wrap: wrap;
}
.contact-actions .cell-display {
    word-break: break-word;
    flex: 1 1 auto;
    min-width: 120px;
}
.col-cell.col-type-email {
    min-width: 200px; /* Give email column more room */
}

.contact-icon {
    cursor: pointer;
    font-size: 1.2rem;
    text-decoration: none;
    color: var(--link-color);
    transition: opacity 0.2s;
}
.contact-icon:hover {
    opacity: 0.7;
}
.send-email {
    background: none;
    border: none;
    font-size: 1.2rem;
    cursor: pointer;
    color: var(--link-color);
    padding: 0;
}
.send-email:hover {
    opacity: 0.7;
}
/* For mobile cards, ensure icons are visible */
.lead-card__value .contact-icon {
    font-size: 1.1rem;
}

/* Rest of styles */
.card--filters { margin-bottom: 20px; }
.filters-header { display: flex; justify-content: space-between; align-items: center; }
.filters-header h3 { margin: 0; }
.filters-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 15px; }
.date-range-row { display: flex; gap: 6px; }
.date-range-row input { flex: 1; }
.filter-actions { display: flex; gap: 10px; margin-top: 12px; flex-wrap: wrap; }

.leads-toolbar {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 16px;
    flex-wrap: wrap;
}
.toolbar-actions { display: flex; flex-wrap: wrap; gap: 6px; }
.toolbar-filters { display: flex; flex-wrap: wrap; gap: 8px; }
.toolbar-search, .toolbar-select {
    padding: 8px 12px;
    border: 1px solid var(--input-border);
    border-radius: 6px;
    font-size: 0.95rem;
    background-color: var(--input-bg);
    color: var(--text-color);
}
.toolbar-search { min-width: 160px; }

.bulk-actions-bar {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
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
    border: 1px solid var(--input-border);
    background-color: var(--input-bg);
    color: var(--text-color);
}

.desktop-table { display: block; }
.mobile-cards  { display: none; }

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
.lead-card__name { flex: 1; }
.lead-card__body { padding: 4px 14px; }
.lead-card__row {
    display: flex;
    gap: 8px;
    padding: 6px 0;
    border-bottom: 1px solid var(--border-color);
}
.lead-card__row:last-child { border-bottom: none; }
.lead-card__label {
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    color: var(--footer-text);
    min-width: 90px;
}
.lead-card__value { font-size: 0.92rem; display: flex; gap: 6px; }
.lead-card__footer {
    display: flex;
    justify-content: space-between;
    padding: 8px 14px;
    background-color: var(--table-header-bg);
    border-top: 1px solid var(--border-color);
    flex-wrap: wrap;
    gap: 8px;
}
.lead-card__created { font-size: 0.78rem; color: var(--footer-text); }
.lead-card__actions { display: flex; gap: 6px; }

.card-new            { border-left: 4px solid #3b82f6; }
.card-contacted      { border-left: 4px solid #f59e0b; }
.card-interested     { border-left: 4px solid #10b981; }
.card-not-interested { border-left: 4px solid #ef4444; }
.card-converted      { border-left: 4px solid #8b5cf6; }

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

@media (max-width: 900px) {
    .desktop-table { display: none !important; }
    .mobile-cards  { display: block; }
    .leads-toolbar { flex-direction: column; }
    .toolbar-actions, .toolbar-filters { width: 100%; }
    .toolbar-filters { flex-direction: column; }
    .toolbar-search, .toolbar-select { width: 100%; font-size: 16px; }
    .bulk-actions-bar { flex-direction: column; }
    .bulk-actions-bar button, .bulk-actions-bar a, .bulk-select { width: 100%; }
    .filters-grid { grid-template-columns: 1fr; }
    .project-tab { padding: 7px 11px; font-size: 0.82rem; }
}
</style>

<script>
const PROJECT_ID = <?= $projectId ?>;

// Tab overflow
(function () {
    const wrapper = document.querySelector('.project-tabs-wrapper');
    const tabs    = document.getElementById('projectTabs');
    if (!tabs || !wrapper) return;
    const check = () => wrapper.classList.toggle('has-overflow', tabs.scrollWidth > tabs.clientWidth + 2);
    check();
    window.addEventListener('resize', check);
    tabs.querySelector('.project-tab.active')?.scrollIntoView({ inline: 'nearest' });
})();

// Toggle filters
document.getElementById('toggleFilters')?.addEventListener('click', function () {
    const panel = document.getElementById('advancedFilters');
    const hiding = panel.style.display !== 'none';
    panel.style.display = hiding ? 'none' : 'block';
    this.textContent = hiding ? 'Show' : 'Hide';
});

// Select all (project-scoped)
document.getElementById('select-all')?.addEventListener('change', function () {
    document.querySelectorAll('.lead-checkbox').forEach(cb => cb.checked = this.checked);
});
function selectAll() {
    document.querySelectorAll('.lead-checkbox').forEach(cb => cb.checked = true);
    const sa = document.getElementById('select-all');
    if (sa) sa.checked = true;
}

// Column header rename (admin or project owner only)
document.querySelectorAll('.col-header--editable').forEach(th => {
    const textSpan = th.querySelector('.col-header-text');
    const input    = th.querySelector('.col-header-input');
    if (!input || !textSpan) return;

    th.addEventListener('dblclick', () => {
        textSpan.style.display = 'none';
        input.style.display = 'block';
        input.focus();
        input.select();
    });

    const save = () => {
        const colId   = th.dataset.columnId;
        const newName = input.value.trim();
        if (!newName) {
            input.style.display = 'none';
            textSpan.style.display = '';
            return;
        }
        
        const form = new FormData();
        form.append('rename_column', '1');
        form.append('column_id', colId);
        form.append('new_name', newName);

        fetch('', { method: 'POST', body: form })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                textSpan.textContent = newName;
                showNotification('Column renamed', 'success');
            } else {
                alert('Error: ' + (data.error || 'Failed'));
            }
            input.style.display = 'none';
            textSpan.style.display = '';
        })
        .catch(() => {
            alert('Network error');
            input.style.display = 'none';
            textSpan.style.display = '';
        });
    };

    input.addEventListener('blur', save);
    input.addEventListener('keydown', e => {
        if (e.key === 'Enter') { e.preventDefault(); save(); }
        if (e.key === 'Escape') { input.style.display = 'none'; textSpan.style.display = ''; }
    });
});

// Add column button and modal
<?php if ($canManageColumns): ?>
document.querySelector('.btn-add-column')?.addEventListener('click', () => {
    document.getElementById('addColumnModal').style.display = 'block';
    document.getElementById('newColumnName').focus();
});

document.querySelector('#addColumnModal .close')?.addEventListener('click', () => {
    document.getElementById('addColumnModal').style.display = 'none';
});

// Show options field when select type chosen
document.getElementById('newColumnType')?.addEventListener('change', function() {
    const optionsGroup = document.getElementById('columnOptionsGroup');
    if (this.value === 'select') {
        optionsGroup.style.display = 'block';
    } else {
        optionsGroup.style.display = 'none';
    }
});

function saveNewColumn() {
    const name = document.getElementById('newColumnName').value.trim();
    const type = document.getElementById('newColumnType').value;
    let options = '';
    if (type === 'select') {
        options = document.getElementById('newColumnOptions').value.trim();
        if (!options) {
            alert('Please enter at least one option for dropdown.');
            return;
        }
    }
    if (!name) { alert('Column name required'); return; }

    const form = new FormData();
    form.append('add_column', '1');
    form.append('project_id', PROJECT_ID);
    form.append('column_name', name);
    form.append('column_type', type);
    form.append('column_options', options);

    fetch('', { method: 'POST', body: form })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            showNotification('Column added', 'success');
            setTimeout(() => location.reload(), 500);
        } else {
            alert('Error: ' + (data.error || 'Failed'));
        }
    })
    .catch(() => alert('Network error'));
}
<?php endif; ?>

// Cell editing (including select and last_contacted)
document.querySelectorAll('.col-cell').forEach(cell => {
    const isSelect = cell.dataset.columnType === 'select';
    const isLastContacted = cell.dataset.field === 'last_contacted';
    let input;
    if (isSelect) {
        input = cell.querySelector('.cell-inline-select');
    } else {
        input = cell.querySelector('.cell-inline-input');
    }
    if (!input) return;

    cell.addEventListener('click', function (e) {
        if (e.target === input || cell.classList.contains('editing')) return;
        const display = cell.querySelector('.cell-display, .never-badge');
        if (display) display.style.display = 'none';
        input.style.display = 'inline-block';
        if (isSelect) {
            input.focus();
        } else {
            input.focus();
            if (input.type === 'date') {
                try { input.showPicker(); } catch(ex) {}
            } else {
                input.select();
            }
        }
        cell.classList.add('editing');
    });

    const save = () => {
        let value;
        if (isSelect) {
            value = input.value;
        } else {
            value = input.value.trim();
        }
        const leadId  = cell.dataset.leadId;
        const colId   = cell.dataset.columnId;
        const field   = cell.dataset.field; // for last_contacted
        let payload;
        if (field === 'last_contacted') {
            payload = { lead_id: leadId, field: 'last_contacted', value: value };
        } else {
            payload = { lead_id: leadId, field: 'column_value', column_id: colId, value: value };
        }

        fetch('quick-status.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        })
        .then(r => r.json())
        .then(data => {
            cell.classList.remove('editing');
            input.style.display = 'none';
            let display = cell.querySelector('.cell-display');
            let never   = cell.querySelector('.never-badge');

            if (data.success) {
                if (field === 'last_contacted') {
                    cell.dataset.value = value;
                } else {
                    cell.dataset.value = value;
                }
                if (value) {
                    let label = value;
                    if (cell.dataset.columnType === 'date' || field === 'last_contacted') {
                        const d = new Date(value + 'T00:00:00');
                        label = d.toLocaleDateString('en-US', { month:'short', day:'2-digit', year:'numeric' });
                    }
                    if (display) {
                        display.textContent = label;
                        display.style.display = '';
                    } else {
                        if (never) never.remove();
                        const span = document.createElement('span');
                        span.className = 'cell-display';
                        span.textContent = label;
                        cell.insertBefore(span, input);
                    }
                    if (never) never.remove();
                } else {
                    if (display) display.remove();
                    if (!never) {
                        const span = document.createElement('span');
                        span.className = 'never-badge';
                        span.textContent = '—';
                        cell.insertBefore(span, input);
                    } else {
                        never.style.display = '';
                    }
                }
                showNotification('Updated', 'success');
            } else {
                if (display) display.style.display = '';
                if (never) never.style.display = '';
                alert('Error: ' + data.error);
            }
        })
        .catch(() => {
            cell.classList.remove('editing');
            input.style.display = 'none';
            const display = cell.querySelector('.cell-display');
            const never = cell.querySelector('.never-badge');
            if (display) display.style.display = '';
            if (never) never.style.display = '';
            alert('Network error');
        });
    };

    if (isSelect) {
        input.addEventListener('change', save);
        input.addEventListener('blur', save);
    } else {
        input.addEventListener('change', save);
        input.addEventListener('blur', save);
    }
    input.addEventListener('keydown', e => {
        if (e.key === 'Escape') {
            cell.classList.remove('editing');
            input.style.display = 'none';
            const display = cell.querySelector('.cell-display');
            const never = cell.querySelector('.never-badge');
            if (display) display.style.display = '';
            if (never) never.style.display = '';
        }
    });
});

// Status update
document.querySelectorAll('.status-select').forEach(sel => {
    sel.addEventListener('change', function () {
        const leadId = this.dataset.leadId;
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
                        badge.className = 'status-badge status-' + newStatus.replace(/_/g, '-');
                        badge.textContent = newStatus.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
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

// Copy email
document.querySelectorAll('.copy-email').forEach(icon => {
    icon.addEventListener('click', e => {
        e.preventDefault();
        navigator.clipboard.writeText(icon.dataset.email)
            .then(() => showNotification('Email copied', 'success'))
            .catch(() => alert('Could not copy'));
    });
});

// Delete single
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

// Owner change
<?php if (isAdmin()): ?>
document.querySelectorAll('.owner-select').forEach(sel => {
    sel.addEventListener('change', function () {
        const leadId = this.dataset.leadId;
        const newOwner = this.value;
        const prev = this.querySelector('option[selected]')?.value || '';
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

// --- Log Call Modal ---
let modalTimerInterval, modalStartTime, modalRunning = false;

function openLogCallModal(leadId) {
    document.getElementById('modalLeadId').value = leadId;
    document.getElementById('logCallModal').style.display = 'block';
    document.getElementById('modalOutcome').focus();
    // Reset timer
    if (modalRunning) stopModalTimer();
    document.getElementById('modalDuration').value = 0;
    document.getElementById('modalTimerDisplay').textContent = '00:00';
}

function closeLogCallModal() {
    document.getElementById('logCallModal').style.display = 'none';
    if (modalRunning) stopModalTimer();
}

function startModalTimer() {
    modalStartTime = Date.now();
    modalRunning = true;
    document.getElementById('modalStartTimer').disabled = true;
    document.getElementById('modalStopTimer').disabled = false;
    modalTimerInterval = setInterval(() => {
        const elapsed = Math.floor((Date.now() - modalStartTime) / 1000);
        const minutes = Math.floor(elapsed / 60);
        const seconds = elapsed % 60;
        document.getElementById('modalTimerDisplay').textContent = 
            `${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
    }, 1000);
}

function stopModalTimer() {
    clearInterval(modalTimerInterval);
    modalRunning = false;
    const elapsed = Math.floor((Date.now() - modalStartTime) / 1000);
    document.getElementById('modalDuration').value = elapsed;
    document.getElementById('modalStartTimer').disabled = false;
    document.getElementById('modalStopTimer').disabled = true;
}

document.getElementById('modalStartTimer')?.addEventListener('click', startModalTimer);
document.getElementById('modalStopTimer')?.addEventListener('click', stopModalTimer);

// Handle form submission
document.getElementById('logCallForm')?.addEventListener('submit', function(e) {
    e.preventDefault();
    if (modalRunning) stopModalTimer();

    const formData = {
        lead_id: document.getElementById('modalLeadId').value,
        outcome: document.getElementById('modalOutcome').value,
        duration: document.getElementById('modalDuration').value,
        follow_up_date: document.getElementById('modalFollowUp').value,
        notes: document.getElementById('modalNotes').value
    };

    fetch('ajax_log_call.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(formData)
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            showNotification('Call logged', 'success');
            closeLogCallModal();
            const leadId = formData.lead_id;

            // Update last_contacted cell for this lead
            const lastContactedCell = document.querySelector(`td[data-field="last_contacted"][data-lead-id="${leadId}"]`);
            if (lastContactedCell) {
                const display = lastContactedCell.querySelector('.cell-display');
                const never = lastContactedCell.querySelector('.never-badge');
                const today = new Date().toLocaleDateString('en-US', { month:'short', day:'2-digit', year:'numeric' });
                if (display) {
                    display.textContent = today;
                    display.style.display = '';
                } else if (never) {
                    never.remove();
                    const span = document.createElement('span');
                    span.className = 'cell-display';
                    span.textContent = today;
                    lastContactedCell.insertBefore(span, lastContactedCell.querySelector('.cell-inline-input'));
                }
                lastContactedCell.dataset.value = data.last_contacted;
            }

            // Update status if new_status provided
            if (data.new_status) {
                // Desktop status select
                const statusSelect = document.querySelector(`select.status-select[data-lead-id="${leadId}"]`);
                if (statusSelect) {
                    statusSelect.value = data.new_status;
                    statusSelect.dataset.current = data.new_status;
                }
                // Mobile status select
                const mobileStatusSelect = document.querySelector(`.lead-card[data-lead-id="${leadId}"] .status-select`);
                if (mobileStatusSelect) {
                    mobileStatusSelect.value = data.new_status;
                    mobileStatusSelect.dataset.current = data.new_status;
                }
                // Update mobile badge and card class
                const mobileCard = document.querySelector(`.lead-card[data-lead-id="${leadId}"]`);
                if (mobileCard) {
                    const badge = mobileCard.querySelector('.status-badge');
                    if (badge) {
                        const newLabel = data.new_status.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
                        badge.textContent = newLabel;
                        badge.className = badge.className.replace(/status-[\w-]+/g, '');
                        badge.classList.add('status-' + data.new_status.replace(/_/g, '-'));
                    }
                    mobileCard.className = mobileCard.className.replace(/card-[\w-]+/g, '');
                    mobileCard.classList.add('card-' + data.new_status.replace(/_/g, '-'));
                }
                // Update desktop row class
                const row = document.querySelector(`tr[data-lead-id="${leadId}"]`);
                if (row) {
                    row.className = row.className.replace(/row-[\w-]+/g, '');
                    row.classList.add('row-' + data.new_status.replace(/_/g, '-'));
                }
            }

            // Also update mobile card last contacted if present (simple way)
            const mobileCard = document.querySelector(`.lead-card[data-lead-id="${leadId}"] .lead-card__row .lead-card__label:contains("Last Contacted")`);
            if (mobileCard) {
                const valueSpan = mobileCard.closest('.lead-card__row')?.querySelector('.lead-card__value span:not(.contact-icon)');
                if (valueSpan) {
                    valueSpan.textContent = new Date().toLocaleDateString('en-US', { month:'short', day:'2-digit', year:'numeric' });
                }
            }
        } else {
            alert('Error: ' + data.error);
        }
    })
    .catch(() => alert('Network error'));
});

// --- Desktop dropdown "Log Call" ---
document.querySelectorAll('.log-call').forEach(link => {
    link.addEventListener('click', function(e) {
        e.preventDefault();
        openLogCallModal(this.dataset.leadId);
    });
});

// --- Mobile one‑click call button ---
document.querySelectorAll('.call-now').forEach(btn => {
    btn.addEventListener('click', function(e) {
        e.preventDefault();
        const leadId = this.dataset.leadId;
        if (!confirm('Log a call with outcome "Contacted" (no duration/notes)?')) return;

        fetch('ajax_log_call.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                lead_id: leadId,
                outcome: 'contacted',
                duration: 0,
                follow_up_date: '',
                notes: ''
            })
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                showNotification('Call logged', 'success');
                const leadId = this.dataset.leadId;

                // Update last_contacted in mobile card
                const card = this.closest('.lead-card');
                if (card) {
                    const lastContactedRow = Array.from(card.querySelectorAll('.lead-card__row')).find(row => 
                        row.querySelector('.lead-card__label')?.textContent.trim() === 'Last Contacted'
                    );
                    if (lastContactedRow) {
                        const valueSpan = lastContactedRow.querySelector('.lead-card__value span:not(.contact-icon)');
                        if (valueSpan) {
                            valueSpan.textContent = new Date().toLocaleDateString('en-US', { month:'short', day:'2-digit', year:'numeric' });
                        }
                    }
                }

                // Update desktop cell if visible
                const desktopCell = document.querySelector(`td[data-field="last_contacted"][data-lead-id="${leadId}"]`);
                if (desktopCell) {
                    const display = desktopCell.querySelector('.cell-display');
                    const never = desktopCell.querySelector('.never-badge');
                    const today = new Date().toLocaleDateString('en-US', { month:'short', day:'2-digit', year:'numeric' });
                    if (display) {
                        display.textContent = today;
                        display.style.display = '';
                    } else if (never) {
                        never.remove();
                        const span = document.createElement('span');
                        span.className = 'cell-display';
                        span.textContent = today;
                        desktopCell.insertBefore(span, desktopCell.querySelector('.cell-inline-input'));
                    }
                    desktopCell.dataset.value = data.last_contacted;
                }

                // Update status if new_status provided (for 'contacted' outcome, it should set status to 'contacted')
                if (data.new_status) {
                    // Desktop status select
                    const statusSelect = document.querySelector(`select.status-select[data-lead-id="${leadId}"]`);
                    if (statusSelect) {
                        statusSelect.value = data.new_status;
                        statusSelect.dataset.current = data.new_status;
                    }
                    // Mobile status select
                    const mobileStatusSelect = card?.querySelector('.status-select');
                    if (mobileStatusSelect) {
                        mobileStatusSelect.value = data.new_status;
                        mobileStatusSelect.dataset.current = data.new_status;
                    }
                    // Update mobile badge and card class
                    if (card) {
                        const badge = card.querySelector('.status-badge');
                        if (badge) {
                            const newLabel = data.new_status.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
                            badge.textContent = newLabel;
                            badge.className = badge.className.replace(/status-[\w-]+/g, '');
                            badge.classList.add('status-' + data.new_status.replace(/_/g, '-'));
                        }
                        card.className = card.className.replace(/card-[\w-]+/g, '');
                        card.classList.add('card-' + data.new_status.replace(/_/g, '-'));
                    }
                    // Update desktop row class
                    const row = document.querySelector(`tr[data-lead-id="${leadId}"]`);
                    if (row) {
                        row.className = row.className.replace(/row-[\w-]+/g, '');
                        row.classList.add('row-' + data.new_status.replace(/_/g, '-'));
                    }
                }
            } else {
                alert('Error: ' + data.error);
            }
        })
        .catch(() => alert('Network error'));
    });
});

function showNotification(msg, type) {
    const n = document.createElement('div');
    n.className = `alert alert-${type}`;
    n.style.cssText = 'position:fixed;top:20px;right:20px;z-index:9999;min-width:220px;box-shadow:0 4px 12px rgba(0,0,0,.18);';
    n.innerText = msg;
    document.body.appendChild(n);
    setTimeout(() => n.remove(), 3000);
}
</script>

<?php include 'includes/footer.php'; ?>