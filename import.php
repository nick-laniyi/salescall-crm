<?php
require_once 'includes/auth.php';
require_once 'includes/config.php';

$errors = [];
$importedCount = 0;
$skippedCount = 0;
$step = isset($_POST['step']) ? (int)$_POST['step'] : 1;

// Writable temp directory
function getWritableTempDir() {
    $projectTemp = __DIR__ . '/temp_uploads';
    if (is_dir($projectTemp) && is_writable($projectTemp)) {
        return $projectTemp;
    }
    if (!is_dir($projectTemp)) {
        if (mkdir($projectTemp, 0777, true)) {
            return $projectTemp;
        }
    }
    $sysTemp = sys_get_temp_dir() . '/salescalls_imports';
    if (!is_dir($sysTemp)) {
        mkdir($sysTemp, 0777, true);
    }
    return $sysTemp;
}

$tempDir = getWritableTempDir();

// Get existing projects (admin only)
$projects = [];
if (isAdmin()) {
    $stmt = $pdo->prepare("SELECT id, name FROM projects WHERE user_id = ? ORDER BY name");
    $stmt->execute([$_SESSION['user_id']]);
    $projects = $stmt->fetchAll();
}

// Auto-detect column type based on header name and sample values
function detectColumnType(string $headerName, array $sampleValues): string {
    $headerLower = strtolower(trim($headerName));
    
    // Email detection
    if (preg_match('/email|e-mail|mail/i', $headerLower)) {
        return 'email';
    }
    
    // Phone detection
    if (preg_match('/phone|mobile|tel|cell|contact/i', $headerLower)) {
        return 'phone';
    }
    
    // Date detection
    if (preg_match('/date|contacted|called|follow.?up|scheduled/i', $headerLower)) {
        // Check sample values for date patterns
        foreach ($sampleValues as $val) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$|^\d{1,2}\/\d{1,2}\/\d{2,4}$/', trim($val))) {
                return 'date';
            }
        }
    }
    
    // Number detection (check sample values are numeric)
    $numericCount = 0;
    $totalSamples = count(array_filter($sampleValues, fn($v) => trim($v) !== ''));
    if ($totalSamples > 0) {
        foreach ($sampleValues as $val) {
            $v = trim($val);
            if ($v !== '' && is_numeric(preg_replace('/[^\d.]/', '', $v))) {
                $numericCount++;
            }
        }
        if ($numericCount / $totalSamples > 0.7) { // 70% numeric = number field
            return 'number';
        }
    }
    
    return 'text';
}

// Validate value against column type
function validateColumnValue(string $value, string $type): bool {
    $value = trim($value);
    if ($value === '') return true; // empty is always valid
    
    switch ($type) {
        case 'email':
            return filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
        case 'phone':
            // Allow digits, spaces, dashes, parentheses, plus
            return preg_match('/^[\d\s\-\(\)\+]+$/', $value);
        case 'number':
            return is_numeric($value);
        case 'date':
            return strtotime($value) !== false;
        default:
            return true;
    }
}

// Normalize value for column type
function normalizeValue(string $value, string $type): string {
    $value = trim($value);
    if ($value === '') return '';
    
    switch ($type) {
        case 'phone':
            // Keep only valid phone characters
            return preg_replace('/[^\d\s\-\(\)\+]/', '', $value);
        case 'number':
            return preg_replace('/[^\d.]/', '', $value);
        case 'date':
            $ts = strtotime($value);
            return $ts ? date('Y-m-d', $ts) : $value;
        default:
            return $value;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['lead_file']) && $step === 1) {
    // ── Step 1: File uploaded, parse & detect ────────────────────────────
    $file = $_FILES['lead_file'];
    $delimiter = $_POST['delimiter'] ?? ',';
    
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'File upload failed.';
    } elseif ($file['size'] > 5 * 1024 * 1024) {
        $errors[] = 'File size exceeds 5MB limit.';
    } else {
        $tempFilename = uniqid('import_') . '_' . basename($file['name']);
        $tempPath = $tempDir . '/' . $tempFilename;
        
        if (move_uploaded_file($file['tmp_name'], $tempPath)) {
            $handle = fopen($tempPath, 'r');
            if ($handle) {
                $headers = fgetcsv($handle, 0, $delimiter);
                
                // Read first 10 rows for type detection
                $sampleRows = [];
                $rowCount = 0;
                while (($data = fgetcsv($handle, 0, $delimiter)) !== false && $rowCount < 10) {
                    $sampleRows[] = $data;
                    $rowCount++;
                }
                fclose($handle);
                
                if (!$headers || empty($sampleRows)) {
                    $errors[] = "Could not parse CSV headers or file is empty.";
                    unlink($tempPath);
                } else {
                    // Auto-detect column types
                    $detectedTypes = [];
                    foreach ($headers as $index => $header) {
                        $sampleValues = array_column($sampleRows, $index);
                        $detectedTypes[$index] = detectColumnType($header, $sampleValues);
                    }
                    
                    $_SESSION['import_file'] = [
                        'path'           => $tempPath,
                        'name'           => $file['name'],
                        'delimiter'      => $delimiter,
                        'headers'        => $headers,
                        'detected_types' => $detectedTypes
                    ];
                    $step = 2;
                }
            } else {
                $errors[] = "Could not open uploaded file.";
                unlink($tempPath);
            }
        } else {
            $errors[] = "Failed to move uploaded file.";
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_import']) && $step === 2) {
    // ── Step 2: Import with validation ───────────────────────────────────
    $project_action = $_POST['project_action'] ?? 'new';
    $project_id = null;
    
    if (!isset($_SESSION['import_file'])) {
        $errors[] = "Session expired. Please upload again.";
        $step = 1;
    } else {
        $fileData = $_SESSION['import_file'];
        $tempPath = $fileData['path'];
        
        if ($project_action === 'existing') {
            $project_id = (int)($_POST['project_id'] ?? 0);
            if (!$project_id) {
                $errors[] = "Please select a project.";
            }
        } else {
            // Create new project with columns
            $project_name = trim($_POST['new_project_name'] ?? '');
            if (empty($project_name)) {
                $errors[] = "New project name required.";
            }
            
            $col_names = $_POST['col_name'] ?? [];
            $col_types = $_POST['col_type'] ?? [];
            $col_options = $_POST['col_options'] ?? [];
            
            if (empty($col_names)) {
                $errors[] = "No columns defined.";
            }
            
            if (empty($errors)) {
                $pdo->beginTransaction();
                try {
                    $stmt = $pdo->prepare("INSERT INTO projects (user_id, name, description) VALUES (?, ?, ?)");
                    $stmt->execute([$_SESSION['user_id'], $project_name, 'Imported project']);
                    $project_id = $pdo->lastInsertId();
                    
                    $colStmt = $pdo->prepare("INSERT INTO project_columns (project_id, name, column_type, options, sort_order) VALUES (?, ?, ?, ?, ?)");
                    $order = 0;
                    foreach ($col_names as $i => $col_name) {
                        $col_name = trim($col_name);
                        if (empty($col_name)) continue;
                        
                        $type = $col_types[$i] ?? 'text';
                        $options = null;
                        if ($type === 'select' && !empty($col_options[$i])) {
                            $options = json_encode(array_map('trim', explode("\n", trim($col_options[$i]))));
                        }
                        $colStmt->execute([$project_id, $col_name, $type, $options, $order++]);
                    }
                    $pdo->commit();
                } catch (Exception $e) {
                    $pdo->rollBack();
                    $errors[] = "Failed to create project: " . $e->getMessage();
                }
            }
        }
        
        // ── Import leads ─────────────────────────────────────────────────
        if (empty($errors) && $project_id && file_exists($tempPath)) {
            $handle = fopen($tempPath, 'r');
            $headers = $fileData['headers'];
            $delimiter = $fileData['delimiter'];
            
            fgetcsv($handle, 0, $delimiter); // Skip header row
            
            $importedCount = 0;
            $skippedCount = 0;
            $lineNumber = 1;
            
            // Get project columns with types
            $colStmt = $pdo->prepare("SELECT id, name, column_type FROM project_columns WHERE project_id = ?");
            $colStmt->execute([$project_id]);
            $projectColumns = [];
            foreach ($colStmt->fetchAll() as $col) {
                $projectColumns[$col['name']] = ['id' => $col['id'], 'type' => $col['column_type']];
            }
            
            // Find "Name" column (required)
            $nameColumnIndex = null;
            foreach ($headers as $idx => $h) {
                if (stripos($h, 'name') !== false || stripos($h, 'lead') !== false) {
                    $nameColumnIndex = $idx;
                    break;
                }
            }
            if ($nameColumnIndex === null) {
                $nameColumnIndex = 0; // fallback: first column
            }
            
            $leadStmt = $pdo->prepare("INSERT INTO leads (user_id, project_id, name, status) VALUES (?, ?, ?, 'new')");
            $valueStmt = $pdo->prepare("INSERT INTO lead_column_values (lead_id, column_id, value) VALUES (?, ?, ?)");
            
            while (($data = fgetcsv($handle, 0, $delimiter)) !== false) {
                $lineNumber++;
                if (count($data) < 1) continue;
                
                $leadName = trim($data[$nameColumnIndex] ?? '');
                if (empty($leadName)) {
                    $skippedCount++;
                    $errors[] = "Line $lineNumber: Skipped (no name).";
                    continue;
                }
                
                try {
                    // Create lead with only name
                    $leadStmt->execute([$_SESSION['user_id'], $project_id, $leadName]);
                    $leadId = $pdo->lastInsertId();
                    
                    // Store all columns (including the name column itself) in lead_column_values
                    foreach ($headers as $colIndex => $headerName) {
                        $headerName = trim($headerName);
                        $rawValue   = trim($data[$colIndex] ?? '');
                        
                        if (empty($headerName) || $rawValue === '') continue;
                        
                        // Find matching project column
                        $colInfo = null;
                        foreach ($projectColumns as $colName => $info) {
                            if (strcasecmp($colName, $headerName) === 0) {
                                $colInfo = $info;
                                break;
                            }
                        }
                        
                        if (!$colInfo) continue;
                        
                        $colType = $colInfo['type'];
                        
                        // Validate & normalize
                        if (!validateColumnValue($rawValue, $colType)) {
                            $errors[] = "Line $lineNumber, column '$headerName': Invalid $colType value '$rawValue' (skipped).";
                            continue;
                        }
                        
                        $normalizedValue = normalizeValue($rawValue, $colType);
                        if ($normalizedValue === '') continue;
                        
                        $valueStmt->execute([$leadId, $colInfo['id'], $normalizedValue]);
                    }
                    
                    $importedCount++;
                } catch (PDOException $e) {
                    $skippedCount++;
                    $errors[] = "Line $lineNumber: Database error - " . $e->getMessage();
                }
            }
            
            fclose($handle);
            unlink($tempPath);
            unset($_SESSION['import_file']);
            
            // Store import timestamp
            $_SESSION['last_import_time'] = date('Y-m-d H:i:s');
            
            header("Location: leads.php?project_id=$project_id&imported=1&count=$importedCount&skipped=$skippedCount");
            exit;
        } else {
            if (!file_exists($tempPath)) {
                $errors[] = "Temporary file missing. Please upload again.";
                unset($_SESSION['import_file']);
                $step = 1;
            }
        }
    }
}

include 'includes/header.php';
?>

<h1>Import Leads</h1>

<?php if (!empty($errors)): ?>
    <div class="alert alert-error">
        <strong>Import Issues:</strong>
        <ul style="margin:5px 0 0 20px;">
            <?php foreach (array_slice($errors, 0, 20) as $error): ?>
                <li><?= htmlspecialchars($error) ?></li>
            <?php endforeach; ?>
            <?php if (count($errors) > 20): ?>
                <li><em>... and <?= count($errors) - 20 ?> more issues.</em></li>
            <?php endif; ?>
        </ul>
    </div>
<?php endif; ?>

<?php if ($step === 1): ?>
    <!-- ══ Step 1: Upload ═══════════════════════════════════════════════════ -->
    <div class="card">
        <h2>Step 1: Upload CSV File</h2>
        <p>Upload a CSV file with your leads. The first row must contain column headers.</p>
        
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="step" value="1">
            
            <div class="form-group">
                <label for="lead_file">Select CSV file (max 5MB)</label>
                <input type="file" id="lead_file" name="lead_file" accept=".csv,.txt,text/csv" required>
            </div>
            
            <div class="form-group">
                <label for="delimiter">Column Delimiter</label>
                <select id="delimiter" name="delimiter">
                    <option value="," selected>Comma (,)</option>
                    <option value=";">Semicolon (;)</option>
                    <option value="\t">Tab</option>
                    <option value="|">Pipe (|)</option>
                </select>
            </div>
            
            <div style="display:flex; gap:10px;">
                <button type="submit" class="btn">Upload and Continue</button>
                <a href="leads.php" class="btn-secondary">Cancel</a>
            </div>
        </form>
    </div>
    
<?php elseif ($step === 2 && isset($_SESSION['import_file'])): ?>
    <!-- ══ Step 2: Configure Columns ════════════════════════════════════════ -->
    <?php
    $headers        = $_SESSION['import_file']['headers'];
    $detectedTypes  = $_SESSION['import_file']['detected_types'] ?? [];
    ?>
    
    <div class="card">
        <h2>Step 2: Configure Import</h2>
        <p>Review detected column types and adjust if needed. Columns will be validated during import.</p>
        
        <form method="post" id="import-form">
            <input type="hidden" name="step" value="2">
            <input type="hidden" name="confirm_import" value="1">
            
            <!-- Project Selection -->
            <div class="form-group">
                <label><strong>Import Destination</strong></label>
                <div style="display:flex; flex-direction:column; gap:8px; margin-top:8px;">
                    <label style="display:flex; align-items:center; gap:6px;">
                        <input type="radio" name="project_action" value="new" checked onchange="toggleProjectOptions()">
                        <span>Create new project (recommended)</span>
                    </label>
                    
                    <?php if (!empty($projects)): ?>
                    <label style="display:flex; align-items:center; gap:6px;">
                        <input type="radio" name="project_action" value="existing" onchange="toggleProjectOptions()">
                        <span>Add to existing project</span>
                    </label>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- New Project Section -->
            <div id="new-project-group" style="margin-top:15px;">
                <div class="form-group">
                    <label for="new_project_name">New Project Name *</label>
                    <input type="text" id="new_project_name" name="new_project_name"
                           placeholder="e.g., Q1 2026 Outreach"
                           value="<?= htmlspecialchars($_SESSION['import_file']['name'] ?? '') ?>" required>
                </div>
                
                <h3 style="margin:20px 0 10px;">Column Mapping (<?= count($headers) ?> columns detected)</h3>
                <div id="csv-columns">
                    <?php foreach ($headers as $index => $header):
                        $detectedType = $detectedTypes[$index] ?? 'text';
                        $typeIcon = [
                            'email' => '📧',
                            'phone' => '📞',
                            'date'  => '📅',
                            'number'=> '#️⃣',
                            'text'  => '📝'
                        ][$detectedType] ?? '📝';
                    ?>
                    <div class="column-card">
                        <div class="column-card__header">
                            <strong><?= $typeIcon ?> <?= htmlspecialchars($header) ?></strong>
                            <span class="type-badge type-<?= $detectedType ?>"><?= ucfirst($detectedType) ?></span>
                        </div>
                        
                        <input type="hidden" name="col_index[]" value="<?= $index ?>">
                        
                        <div class="column-card__body">
                            <div class="form-row">
                                <div class="form-group" style="flex:2;">
                                    <label>Column Name</label>
                                    <input type="text" name="col_name[]"
                                           value="<?= htmlspecialchars($header) ?>" required>
                                </div>
                                
                                <div class="form-group" style="flex:1;">
                                    <label>Data Type</label>
                                    <select name="col_type[]" class="col-type" onchange="toggleOptions(this)">
                                        <option value="text"   <?= $detectedType === 'text'   ? 'selected' : '' ?>>Text</option>
                                        <option value="email"  <?= $detectedType === 'email'  ? 'selected' : '' ?>>Email</option>
                                        <option value="phone"  <?= $detectedType === 'phone'  ? 'selected' : '' ?>>Phone</option>
                                        <option value="number" <?= $detectedType === 'number' ? 'selected' : '' ?>>Number</option>
                                        <option value="date"   <?= $detectedType === 'date'   ? 'selected' : '' ?>>Date</option>
                                        <option value="select">Dropdown</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="form-group col-options" style="display:none;">
                                <label>Dropdown Options (one per line)</label>
                                <textarea name="col_options[]" rows="2" placeholder="Option 1&#10;Option 2&#10;Option 3"></textarea>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <!-- Existing Project Section -->
            <?php if (!empty($projects)): ?>
            <div id="existing-project-group" style="display:none; margin-top:15px;">
                <div class="form-group">
                    <label for="existing_project_id">Select Project *</label>
                    <select name="project_id" id="existing_project_id" class="form-control">
                        <option value="">-- Choose a project --</option>
                        <?php foreach ($projects as $p): ?>
                            <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <p class="field-hint">Leads will be added to the selected project's existing columns.</p>
            </div>
            <?php endif; ?>
            
            <div style="display:flex; gap:10px; margin-top:20px;">
                <button type="submit" class="btn">Import <?= count($headers) ?> Columns</button>
                <a href="import.php" class="btn-secondary">Start Over</a>
            </div>
        </form>
    </div>
<?php endif; ?>

<style>
.column-card {
    border: 1px solid var(--border-color);
    border-radius: 6px;
    margin-bottom: 12px;
    overflow: hidden;
    background: var(--card-bg);
}
.column-card__header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 10px 12px;
    background-color: var(--table-header-bg);
    border-bottom: 1px solid var(--border-color);
}
.column-card__body {
    padding: 12px;
}
.form-row {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}
.form-row .form-group {
    margin-bottom: 0;
}
.type-badge {
    font-size: 0.75rem;
    padding: 3px 8px;
    border-radius: 12px;
    font-weight: 600;
    text-transform: uppercase;
}
.type-email  { background:#dbeafe; color:#1e40af; }
.type-phone  { background:#d1fae5; color:#065f46; }
.type-date   { background:#fef3c7; color:#92400e; }
.type-number { background:#e0e7ff; color:#3730a3; }
.type-text   { background:#f3f4f6; color:#374151; }

body.dark-mode .type-email  { background:#1e3a8a; color:#bfdbfe; }
body.dark-mode .type-phone  { background:#14532d; color:#bbf7d0; }
body.dark-mode .type-date   { background:#78350f; color:#fef3c7; }
body.dark-mode .type-number { background:#312e81; color:#c7d2fe; }
body.dark-mode .type-text   { background:#374151; color:#f3f4f6; }

.field-hint {
    font-size: 0.85rem;
    color: var(--footer-text);
    margin-top: 5px;
}
</style>

<script>
function toggleProjectOptions() {
    const isNew = document.querySelector('input[name="project_action"]:checked').value === 'new';
    document.getElementById('new-project-group').style.display     = isNew ? 'block' : 'none';
    const existingGroup = document.getElementById('existing-project-group');
    if (existingGroup) existingGroup.style.display = isNew ? 'none' : 'block';
    
    // Toggle required attribute
    document.getElementById('new_project_name').required = isNew;
    const existingSelect = document.getElementById('existing_project_id');
    if (existingSelect) existingSelect.required = !isNew;
}

function toggleOptions(select) {
    const optionsDiv = select.closest('.column-card__body').querySelector('.col-options');
    if (optionsDiv) {
        optionsDiv.style.display = select.value === 'select' ? 'block' : 'none';
    }
}

// Initialize
document.querySelectorAll('.col-type').forEach(select => toggleOptions(select));
toggleProjectOptions();
</script>

<?php include 'includes/footer.php'; ?>