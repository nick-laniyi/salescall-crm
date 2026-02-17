<?php
require_once 'includes/auth.php';
require_once 'includes/config.php';
require_once 'includes/functions.php';

$errors = [];
$importedCount = 0;
$skippedCount = 0;
$step = isset($_POST['step']) ? (int)$_POST['step'] : 1;

// Get all projects for the current user
$projects = [];
$stmt = $pdo->prepare("SELECT id, name FROM projects WHERE user_id = ? ORDER BY name");
$stmt->execute([$_SESSION['user_id']]);
$projects = $stmt->fetchAll();

// Step 1: File upload and header parsing
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['lead_file']) && $step === 1) {
    $file = $_FILES['lead_file'];
    $delimiter = $_POST['delimiter'] ?? ',';

    // Validate delimiter
    if (!in_array($delimiter, [',', ';', '\t', '|'])) {
        $delimiter = ',';
    }

    // File upload validation
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'File upload failed.';
    } elseif ($file['size'] > 2 * 1024 * 1024) {
        $errors[] = 'File size exceeds 2MB limit.';
    } else {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        $allowedMimes = ['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'];
        if (!in_array($mime, $allowedMimes)) {
            $errors[] = 'Only CSV or text files are allowed.';
        } else {
            // Parse headers
            $handle = fopen($file['tmp_name'], 'r');
            $headers = fgetcsv($handle, 0, $delimiter);
            fclose($handle);

            if (!$headers) {
                $errors[] = 'Could not parse CSV headers.';
            } else {
                // Store file info in session for next step
                $_SESSION['import_file'] = [
                    'tmp_name' => $file['tmp_name'],
                    'name' => $file['name'],
                    'delimiter' => $delimiter,
                    'headers' => $headers
                ];
                $step = 2;
            }
        }
    }
}

// Step 2: Confirm mapping and import
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_import']) && $step === 2) {
    $project_action = $_POST['project_action'] ?? 'existing';
    $project_id = null;

    // Validate project selection/creation
    if ($project_action === 'existing') {
        $project_id = (int)($_POST['project_id'] ?? 0);
        if (!$project_id) {
            $errors[] = 'Please select a project.';
        } else {
            // Verify project belongs to user
            $stmt = $pdo->prepare("SELECT id FROM projects WHERE id = ? AND user_id = ?");
            $stmt->execute([$project_id, $_SESSION['user_id']]);
            if (!$stmt->fetch()) {
                $errors[] = 'Selected project not found.';
            }
        }
    } else {
        $project_name = trim($_POST['new_project_name'] ?? '');
        if (empty($project_name)) {
            $errors[] = 'New project name is required.';
        }

        $col_names = $_POST['col_name'] ?? [];
        $col_types = $_POST['col_type'] ?? [];
        $col_options = $_POST['col_options'] ?? [];

        if (empty($col_names)) {
            $errors[] = 'No columns defined.';
        }

        if (empty($errors)) {
            // Create new project and columns
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
                $errors[] = 'Failed to create project: ' . $e->getMessage();
            }
        }
    }

    // If no errors, proceed with import
    if (empty($errors) && $project_id && isset($_SESSION['import_file'])) {
        $fileData = $_SESSION['import_file'];
        $handle = fopen($fileData['tmp_name'], 'r');
        $headers = $fileData['headers'];
        $delimiter = $fileData['delimiter'];

        // Skip header row
        fgetcsv($handle, 0, $delimiter);

        $importedCount = 0;
        $skippedCount = 0;
        $lineNumber = 1;

        // Prepare statements
        $leadStmt = $pdo->prepare("INSERT INTO leads (user_id, project_id, name, company, phone, email, status, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $valueStmt = $pdo->prepare("INSERT INTO lead_column_values (lead_id, column_id, value) VALUES (?, ?, ?)");

        // Get project columns for mapping (name => id)
        $colStmt = $pdo->prepare("SELECT id, name FROM project_columns WHERE project_id = ?");
        $colStmt->execute([$project_id]);
        $projectColumns = [];
        while ($row = $colStmt->fetch()) {
            $projectColumns[strtolower($row['name'])] = $row['id'];
        }

        while (($data = fgetcsv($handle, 0, $delimiter)) !== false) {
            $lineNumber++;
            if (count($data) < 1) continue;

            // Map standard fields (assuming order: name, company, phone, email, status, notes)
            $name = trim($data[0] ?? '');
            $company = trim($data[1] ?? '');
            $phone = trim($data[2] ?? '');
            $email = trim($data[3] ?? '');
            $statusRaw = trim($data[4] ?? '');
            $notes = trim($data[5] ?? '');

            if (empty($name)) {
                $skippedCount++;
                continue;
            }

            // Validate status
            $validStatuses = ['new', 'contacted', 'interested', 'not_interested', 'converted'];
            $status = 'new';
            if (!empty($statusRaw)) {
                $lowerStatus = strtolower($statusRaw);
                if (in_array($lowerStatus, $validStatuses)) {
                    $status = $lowerStatus;
                } else {
                    // Try to map
                    if (strpos($lowerStatus, 'interest') !== false) $status = 'interested';
                    elseif (strpos($lowerStatus, 'contact') !== false) $status = 'contacted';
                    elseif (strpos($lowerStatus, 'convert') !== false) $status = 'converted';
                    elseif (strpos($lowerStatus, 'not') !== false) $status = 'not_interested';
                }
            }

            // Insert lead
            $leadStmt->execute([$_SESSION['user_id'], $project_id, $name, $company, $phone, $email, $status, $notes]);
            $leadId = $pdo->lastInsertId();

            // Insert custom values for remaining columns (index 6+)
            for ($i = 6; $i < count($data); $i++) {
                $headerName = $headers[$i] ?? '';
                if (empty($headerName)) continue;
                $value = trim($data[$i] ?? '');
                if (empty($value)) continue;

                $headerLower = strtolower($headerName);
                if (isset($projectColumns[$headerLower])) {
                    $valueStmt->execute([$leadId, $projectColumns[$headerLower], $value]);
                }
            }
            $importedCount++;
        }
        fclose($handle);

        // Clean up session
        unset($_SESSION['import_file']);

        header("Location: leads.php?imported=1&count=$importedCount&skipped=$skippedCount");
        exit;
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
    <!-- Step 2: Map columns and choose/create project -->
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

    // Initialize visibility for existing options
    document.querySelectorAll('.col-type').forEach(select => {
        toggleOptions(select);
    });
    </script>
<?php endif; ?>

<div class="card">
    <h3>Need a template?</h3>
    <p><a href="download_sample.php" class="btn-secondary">Download Sample CSV</a></p>
    <p>The sample includes a header row and a few example leads.</p>
</div>

<?php include 'includes/footer.php'; ?>