<?php
require_once 'includes/auth.php';
require_once 'includes/config.php';

$errors = [];
$step = $_GET['step'] ?? 'upload';

// Function to detect field type
function detectFieldType($samples) {
    $emailCount = 0;
    $phoneCount = 0;
    $nameCount = 0;
    $otherCount = 0;
    foreach ($samples as $sample) {
        $sample = trim($sample);
        if (filter_var($sample, FILTER_VALIDATE_EMAIL)) {
            $emailCount++;
        } elseif (preg_match('/^[+]?[(]?[0-9]{3}[)]?[-\s.]?[0-9]{3}[-\s.]?[0-9]{4,}$/', $sample)) {
            $phoneCount++;
        } elseif (strlen($sample) > 2 && !preg_match('/[0-9]/', $sample) && strpos($sample, '@') === false) {
            $nameCount++;
        } else {
            $otherCount++;
        }
    }
    // Determine most likely type
    $counts = ['email' => $emailCount, 'phone' => $phoneCount, 'name' => $nameCount, 'other' => $otherCount];
    arsort($counts);
    return key($counts);
}

// Step 1: Upload file
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['lead_file']) && $step === 'upload') {
    $file = $_FILES['lead_file'];
    $delimiter = $_POST['delimiter'] ?? ',';
    $hasHeader = isset($_POST['has_header']);

    // Validate
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'File upload failed.';
    } elseif ($file['size'] > 2 * 1024 * 1024) {
        $errors[] = 'File size exceeds 2MB limit.';
    } else {
        // Read first 10 rows to analyze
        $handle = fopen($file['tmp_name'], 'r');
        if ($handle) {
            $rows = [];
            $lineNumber = 0;
            while (($data = fgetcsv($handle, 0, $delimiter)) !== false && $lineNumber < 10) {
                if ($hasHeader && $lineNumber === 0) {
                    $headers = $data;
                } else {
                    $rows[] = $data;
                }
                $lineNumber++;
            }
            fclose($handle);

            if (empty($rows)) {
                $errors[] = 'No data rows found.';
            } else {
                // Analyze each column
                $numCols = count($rows[0]);
                $colTypes = [];
                $colSamples = [];
                for ($i = 0; $i < $numCols; $i++) {
                    $samples = array_column($rows, $i);
                    $colTypes[$i] = detectFieldType($samples);
                    $colSamples[$i] = array_slice($samples, 0, 3); // for preview
                }

                // Store file info in session for next step
                $_SESSION['import_file'] = [
                    'tmp_name' => $file['tmp_name'],
                    'name' => $file['name'],
                    'delimiter' => $delimiter,
                    'has_header' => $hasHeader,
                    'col_types' => $colTypes,
                    'col_samples' => $colSamples,
                    'headers' => $headers ?? null,
                ];
                header('Location: import.php?step=map');
                exit;
            }
        } else {
            $errors[] = 'Could not open file.';
        }
    }
}

// Step 2: Map columns and confirm
if ($step === 'map') {
    if (!isset($_SESSION['import_file'])) {
        header('Location: import.php');
        exit;
    }
    $import = $_SESSION['import_file'];

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_import'])) {
        // Perform actual import
        $handle = fopen($import['tmp_name'], 'r');
        if ($handle) {
            $importedCount = 0;
            $skippedCount = 0;
            $errors = [];
            $importedIds = [];

            // If has header, skip first line
            if ($import['has_header']) {
                fgetcsv($handle, 0, $import['delimiter']);
            }

            // Get mapping from form
            $map = [
                'name' => (int)($_POST['col_name'] ?? -1),
                'company' => (int)($_POST['col_company'] ?? -1),
                'phone' => (int)($_POST['col_phone'] ?? -1),
                'email' => (int)($_POST['col_email'] ?? -1),
                'status' => (int)($_POST['col_status'] ?? -1),
                'notes' => (int)($_POST['col_notes'] ?? -1),
            ];

            while (($data = fgetcsv($handle, 0, $import['delimiter'])) !== false) {
                // Extract fields
                $name = $map['name'] >= 0 ? trim($data[$map['name']] ?? '') : '';
                $company = $map['company'] >= 0 ? trim($data[$map['company']] ?? '') : '';
                $phone = $map['phone'] >= 0 ? trim($data[$map['phone']] ?? '') : '';
                $email = $map['email'] >= 0 ? trim($data[$map['email']] ?? '') : '';
                $status = $map['status'] >= 0 ? trim($data[$map['status']] ?? '') : 'new';
                $notes = $map['notes'] >= 0 ? trim($data[$map['notes']] ?? '') : '';

                if (empty($name)) {
                    $skippedCount++;
                    continue;
                }

                // Validate status
                $validStatuses = ['new', 'contacted', 'interested', 'not_interested', 'converted'];
                if (!in_array($status, $validStatuses)) {
                    $status = 'new';
                }

                // Insert
                $stmt = $pdo->prepare("INSERT INTO leads (user_id, name, company, phone, email, status, notes) VALUES (?, ?, ?, ?, ?, ?, ?)");
                if ($stmt->execute([$_SESSION['user_id'], $name, $company, $phone, $email, $status, $notes])) {
                    $importedCount++;
                    $importedIds[] = $pdo->lastInsertId();
                } else {
                    $skippedCount++;
                }
            }
            fclose($handle);

            // Clean up session
            unset($_SESSION['import_file']);
            $_SESSION['last_import_time'] = date('Y-m-d H:i:s');
            $_SESSION['last_import_ids'] = $importedIds;

            header('Location: leads.php?imported=1&count=' . $importedCount . '&skipped=' . $skippedCount);
            exit;
        } else {
            $errors[] = 'Could not open file for import.';
        }
    }
}

include 'includes/header.php';
?>

<?php if ($step === 'upload'): ?>
    <h1>Import Leads</h1>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-error">
            <?php foreach ($errors as $error): ?>
                <div><?= htmlspecialchars($error) ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="card">
        <h2>Upload Leads File</h2>
        <p>Upload a CSV or text file. We'll analyze the first 10 rows to help you map columns.</p>
        <form method="post" enctype="multipart/form-data">
            <div class="form-group">
                <label for="lead_file">Select file</label>
                <input type="file" id="lead_file" name="lead_file" accept=".csv,.txt,text/csv" required>
            </div>
            <div class="form-group">
                <label for="delimiter">Delimiter</label>
                <select id="delimiter" name="delimiter">
                    <option value=",">Comma (,)</option>
                    <option value=";">Semicolon (;)</option>
                    <option value="\t">Tab</option>
                    <option value="|">Pipe (|)</option>
                </select>
            </div>
            <div class="form-group">
                <label>
                    <input type="checkbox" name="has_header" value="1" checked> First row contains column headers
                </label>
            </div>
            <button type="submit" class="btn">Analyze File</button>
            <a href="leads.php" class="btn-secondary">Cancel</a>
        </form>
    </div>

<?php elseif ($step === 'map' && isset($import)): ?>
    <h1>Map Columns</h1>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-error">
            <?php foreach ($errors as $error): ?>
                <div><?= htmlspecialchars($error) ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="card">
        <h2>Preview and Map Fields</h2>
        <p>File: <strong><?= htmlspecialchars($import['name']) ?></strong></p>
        <p>Based on sample data, we've suggested mappings. Adjust as needed.</p>

        <table class="table">
            <thead>
                <tr>
                    <th>Column</th>
                    <th>Sample Data</th>
                    <th>Detected Type</th>
                    <th>Map to Field</th>
                </tr>
            </thead>
            <tbody>
                <?php for ($i = 0; $i < count($import['col_types']); $i++): ?>
                    <tr>
                        <td><?= $import['headers'][$i] ?? 'Column ' . ($i+1) ?></td>
                        <td>
                            <?php foreach ($import['col_samples'][$i] as $sample): ?>
                                <div><?= htmlspecialchars($sample) ?></div>
                            <?php endforeach; ?>
                        </td>
                        <td><?= ucfirst($import['col_types'][$i]) ?></td>
                        <td>
                            <select name="col_map[<?= $i ?>]" form="import-form">
                                <option value="">Ignore</option>
                                <option value="name" <?= $import['col_types'][$i] === 'name' ? 'selected' : '' ?>>Name</option>
                                <option value="company" <?= $import['col_types'][$i] === 'company' ? 'selected' : '' ?>>Company</option>
                                <option value="phone" <?= $import['col_types'][$i] === 'phone' ? 'selected' : '' ?>>Phone</option>
                                <option value="email" <?= $import['col_types'][$i] === 'email' ? 'selected' : '' ?>>Email</option>
                                <option value="status" <?= $import['col_types'][$i] === 'status' ? 'selected' : '' ?>>Status</option>
                                <option value="notes" <?= $import['col_types'][$i] === 'notes' ? 'selected' : '' ?>>Notes</option>
                            </select>
                        </td>
                    </tr>
                <?php endfor; ?>
            </tbody>
        </table>

        <form method="post" id="import-form">
            <?php for ($i = 0; $i < count($import['col_types']); $i++): ?>
                <input type="hidden" name="col_name_<?= $i ?>" value="">
            <?php endfor; ?>
            <button type="submit" name="confirm_import" class="btn">Import Leads</button>
            <a href="import.php" class="btn-secondary">Cancel</a>
        </form>

        <script>
        document.getElementById('import-form').addEventListener('submit', function(e) {
            // Convert column selections to hidden inputs with proper names
            var selects = document.querySelectorAll('select[name^="col_map"]');
            selects.forEach(function(select, index) {
                var field = select.value;
                if (field) {
                    var hidden = document.createElement('input');
                    hidden.type = 'hidden';
                    hidden.name = 'col_' + field;
                    hidden.value = index;
                    document.getElementById('import-form').appendChild(hidden);
                }
            });
        });
        </script>
    </div>
<?php endif; ?>

<?php include 'includes/footer.php'; ?>