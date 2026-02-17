<?php
require_once 'includes/auth.php';
require_once 'includes/config.php';

$errors = [];
$importedCount = 0;
$skippedCount = 0;
$step = isset($_POST['step']) ? (int)$_POST['step'] : 1;

// Determine a writable temp directory
function getWritableTempDir() {
    // Try project temp_uploads first
    $projectTemp = __DIR__ . '/temp_uploads';
    if (is_dir($projectTemp) && is_writable($projectTemp)) {
        return $projectTemp;
    }
    if (!is_dir($projectTemp)) {
        if (mkdir($projectTemp, 0777, true)) {
            return $projectTemp;
        }
    }
    // Fallback to system temp
    $sysTemp = sys_get_temp_dir() . '/salescalls_imports';
    if (!is_dir($sysTemp)) {
        mkdir($sysTemp, 0777, true);
    }
    return $sysTemp;
}

$tempDir = getWritableTempDir();

// Get all projects
$projects = [];
if (isAdmin()) {
    $stmt = $pdo->prepare("SELECT id, name FROM projects WHERE user_id = ? ORDER BY name");
    $stmt->execute([$_SESSION['user_id']]);
    $projects = $stmt->fetchAll();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['lead_file']) && $step === 1) {
    // Step 1: File uploaded, parse headers
    $file = $_FILES['lead_file'];
    $delimiter = $_POST['delimiter'] ?? ',';
    
    // Validate upload
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'File upload failed.';
    } else {
        // Check file size (limit 2MB)
        if ($file['size'] > 2 * 1024 * 1024) {
            $errors[] = 'File size exceeds 2MB limit.';
        } else {
            // Generate unique filename and move to temp directory
            $tempFilename = uniqid('import_') . '_' . basename($file['name']);
            $tempPath = $tempDir . '/' . $tempFilename;
            
            if (move_uploaded_file($file['tmp_name'], $tempPath)) {
                // Parse first row to get headers
                $handle = fopen($tempPath, 'r');
                if ($handle) {
                    $headers = fgetcsv($handle, 0, $delimiter);
                    fclose($handle);
                    
                    if (!$headers) {
                        $errors[] = "Could not parse CSV headers.";
                        unlink($tempPath);
                    } else {
                        // Store file info in session for next step
                        $_SESSION['import_file'] = [
                            'path' => $tempPath,
                            'name' => $file['name'],
                            'delimiter' => $delimiter,
                            'headers' => $headers
                        ];
                        $step = 2;
                    }
                } else {
                    $errors[] = "Could not open uploaded file.";
                    unlink($tempPath);
                }
            } else {
                $errors[] = "Failed to move uploaded file. Please check directory permissions.";
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_import']) && $step === 2) {
    // Step 2: User confirmed mapping and project
    $project_action = $_POST['project_action'] ?? 'existing';
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
            // Create new project
            $project_name = trim($_POST['new_project_name'] ?? '');
            if (empty($project_name)) {
                $errors[] = "New project name required.";
            }
            
            // Get column mappings
            $col_names = $_POST['col_name'] ?? [];
            $col_types = $_POST['col_type'] ?? [];
            $col_options = $_POST['col_options'] ?? [];
            
            if (empty($col_names)) {
                $errors[] = "No columns defined.";
            }
            
            if (empty($errors)) {
                // Create project and columns
                $pdo->beginTransaction();
                try {
                    $stmt = $pdo->prepare("INSERT INTO projects (user_id, name, description) VALUES (?, ?, ?)");
                    $stmt->execute([$_SESSION['user_id'], $project_name, 'Imported project']);
                    $project_id = $pdo->lastInsertId();
                    
                    $colStmt = $pdo->prepare("INSERT INTO project_columns (project_id, name, column_type, options, sort_order) VALUES (?, ?, ?, ?, ?)");
                    $order = 0;
                    foreach ($col_names as $i => $col_name) {
                        if (empty(trim($col_name))) continue;
                        $type = $col_types[$i] ?? 'text';
                        $options = null;
                        if ($type === 'select' && !empty($col_options[$i])) {
                            $options = json_encode(array_map('trim', explode("\n", trim($col_options[$i]))));
                        }
                        $colStmt->execute([$project_id, trim($col_name), $type, $options, $order++]);
                    }
                    $pdo->commit();
                } catch (Exception $e) {
                    $pdo->rollBack();
                    $errors[] = "Failed to create project: " . $e->getMessage();
                }
            }
        }
        
        if (empty($errors) && $project_id && file_exists($tempPath)) {
            // Now perform the actual import
            $handle = fopen($tempPath, 'r');
            $headers = $fileData['headers'];
            $delimiter = $fileData['delimiter'];
            
            // Skip header row
            fgetcsv($handle, 0, $delimiter);
            
            $importedCount = 0;
            $skippedCount = 0;
            $lineNumber = 1;
            
            // Prepare insert statements
            $leadStmt = $pdo->prepare("INSERT INTO leads (user_id, project_id, name, company, phone, email, status, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $valueStmt = $pdo->prepare("INSERT INTO lead_column_values (lead_id, column_id, value) VALUES (?, ?, ?)");
            
            // Get project columns (for mapping)
            $colStmt = $pdo->prepare("SELECT id, name FROM project_columns WHERE project_id = ?");
            $colStmt->execute([$project_id]);
            $projectColumns = $colStmt->fetchAll(PDO::FETCH_KEY_PAIR); // name => id
            
            while (($data = fgetcsv($handle, 0, $delimiter)) !== false) {
                $lineNumber++;
                if (count($data) < 1) continue;
                
                // Extract standard fields (assuming first few columns)
                $name = trim($data[0] ?? '');
                $company = trim($data[1] ?? '');
                $phone = trim($data[2] ?? '');
                $email = trim($data[3] ?? '');
                $status = trim($data[4] ?? 'new');
                $notes = trim($data[5] ?? '');
                
                if (empty($name)) {
                    $skippedCount++;
                    continue;
                }
                
                // Validate status
                $validStatuses = ['new', 'contacted', 'interested', 'not_interested', 'converted'];
                if (!in_array($status, $validStatuses)) {
                    $status = 'new';
                }
                
                // Insert lead
                $leadStmt->execute([$_SESSION['user_id'], $project_id, $name, $company, $phone, $email, $status, $notes]);
                $leadId = $pdo->lastInsertId();
                
                // Insert custom values based on remaining columns (starting from index 6)
                for ($i = 6; $i < count($data); $i++) {
                    $headerName = $headers[$i] ?? '';
                    if (empty($headerName)) continue;
                    $value = trim($data[$i] ?? '');
                    if (empty($value)) continue;
                    
                    // Find column id by header name (case-insensitive)
                    $colId = null;
                    foreach ($projectColumns as $colName => $cid) {
                        if (strcasecmp($colName, $headerName) === 0) {
                            $colId = $cid;
                            break;
                        }
                    }
                    if ($colId) {
                        $valueStmt->execute([$leadId, $colId, $value]);
                    }
                }
                $importedCount++;
            }
            fclose($handle);
            
            // Clean up temp file
            unlink($tempPath);
            unset($_SESSION['import_file']);
            
            header("Location: leads.php?imported=1&count=$importedCount&skipped=$skippedCount");
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
        <ul>
            <?php foreach ($errors as $error): ?>
                <li><?= htmlspecialchars($error) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<?php if ($step === 1): ?>
    <!-- Step 1: Upload file -->
    <div class="card">
        <h2>Step 1: Upload CSV File</h2>
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="step" value="1">
            <div class="form-group">
                <label for="lead_file">Select CSV file</label>
                <input type="file" id="lead_file" name="lead_file" accept=".csv,.txt,text/csv" required>
            </div>
            <div class="form-group">
                <label for="delimiter">Delimiter</label>
                <select id="delimiter" name="delimiter">
                    <option value="," selected>Comma (,)</option>
                    <option value=";">Semicolon (;)</option>
                    <option value="\t">Tab</option>
                    <option value="|">Pipe (|)</option>
                </select>
            </div>
            <button type="submit" class="btn">Upload and Continue</button>
            <a href="leads.php" class="btn-secondary">Cancel</a>
        </form>
    </div>
<?php elseif ($step === 2 && isset($_SESSION['import_file'])): ?>
    <!-- Step 2: Map columns and choose project -->
    <?php
    $headers = $_SESSION['import_file']['headers'];
    ?>
    <div class="card">
        <h2>Step 2: Map to Project</h2>
        <form method="post">
            <input type="hidden" name="step" value="2">
            <input type="hidden" name="confirm_import" value="1">
            
            <div class="form-group">
                <label>Project</label>
                <div style="margin-bottom: 10px;">
                    <label><input type="radio" name="project_action" value="existing" checked onchange="toggleProjectOptions()"> Select existing project</label>
                    <label><input type="radio" name="project_action" value="new" onchange="toggleProjectOptions()"> Create new project</label>
                </div>
                
                <div id="existing-project-group">
                    <select name="project_id" class="form-control">
                        <option value="">-- Select --</option>
                        <?php foreach ($projects as $p): ?>
                            <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div id="new-project-group" style="display: none;">
                    <div class="form-group">
                        <label for="new_project_name">New Project Name</label>
                        <input type="text" id="new_project_name" name="new_project_name">
                    </div>
                    <h4>Columns (from CSV headers)</h4>
                    <div id="csv-columns">
                        <?php foreach ($headers as $index => $header): ?>
                            <div class="column-mapping" style="border:1px solid #ccc; padding:10px; margin-bottom:10px;">
                                <strong>CSV Column: <?= htmlspecialchars($header) ?></strong>
                                <input type="hidden" name="col_index[]" value="<?= $index ?>">
                                <div class="form-group">
                                    <label>Column Name</label>
                                    <input type="text" name="col_name[]" value="<?= htmlspecialchars($header) ?>" required>
                                </div>
                                <div class="form-group">
                                    <label>Data Type</label>
                                    <select name="col_type[]" class="col-type" onchange="toggleOptions(this)">
                                        <option value="text">Text</option>
                                        <option value="number">Number</option>
                                        <option value="date">Date</option>
                                        <option value="select">Dropdown</option>
                                    </select>
                                </div>
                                <div class="form-group col-options" style="display: none;">
                                    <label>Options (one per line)</label>
                                    <textarea name="col_options[]" rows="2"></textarea>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            
            <button type="submit" class="btn">Import Leads</button>
            <a href="import.php" class="btn-secondary">Start Over</a>
        </form>
    </div>
    
    <script>
    function toggleProjectOptions() {
        const isNew = document.querySelector('input[name="project_action"]:checked').value === 'new';
        document.getElementById('existing-project-group').style.display = isNew ? 'none' : 'block';
        document.getElementById('new-project-group').style.display = isNew ? 'block' : 'none';
    }
    
    function toggleOptions(select) {
        const optionsDiv = select.closest('.column-mapping').querySelector('.col-options');
        optionsDiv.style.display = select.value === 'select' ? 'block' : 'none';
    }
    
    // Initialize options visibility
    document.querySelectorAll('.col-type').forEach(select => {
        toggleOptions(select);
    });
    </script>
<?php endif; ?>

<?php include 'includes/footer.php'; ?>