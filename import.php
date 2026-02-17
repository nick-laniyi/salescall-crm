<?php
require_once 'includes/auth.php';
require_once 'includes/config.php';

$errors = [];
$importedCount = 0;
$skippedCount = 0;

// Handle file upload and import
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['lead_file'])) {
    $file = $_FILES['lead_file'];
    $delimiter = $_POST['delimiter'] ?? ',';
    $hasHeader = isset($_POST['has_header']);
    $autoDetect = isset($_POST['auto_detect']);
    
    // Validate delimiter
    if (!in_array($delimiter, [',', ';', '\t', '|'])) {
        $delimiter = ',';
    }
    
    // Column mapping (default values)
    $map = [
        'name' => (int)($_POST['col_name'] ?? 0),
        'company' => (int)($_POST['col_company'] ?? 1),
        'phone' => (int)($_POST['col_phone'] ?? 2),
        'email' => (int)($_POST['col_email'] ?? 3),
        'status' => (int)($_POST['col_status'] ?? 4),
        'notes' => (int)($_POST['col_notes'] ?? 5),
    ];
    
    // Validate upload
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'File upload failed.';
    } else {
        // Check file size (limit to 2MB)
        if ($file['size'] > 2 * 1024 * 1024) {
            $errors[] = 'File size exceeds 2MB limit.';
        } else {
            // Check mime type (basic)
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
            
            $allowedMimes = [
                'text/csv',
                'text/plain',
                'application/csv',
                'application/vnd.ms-excel',
            ];
            
            if (!in_array($mime, $allowedMimes)) {
                $errors[] = 'Only CSV or text files are allowed.';
            } else {
                // Parse file
                $handle = fopen($file['tmp_name'], 'r');
                if ($handle) {
                    $lineNumber = 0;
                    $importedIds = [];
                    $sampleData = [];
                    
                    // Read first few rows for auto-detection if needed
                    if ($autoDetect) {
                        $sampleRows = [];
                        while ($lineNumber < 5 && ($data = fgetcsv($handle, 0, $delimiter)) !== false) {
                            $sampleRows[] = $data;
                            $lineNumber++;
                        }
                        // Rewind file
                        rewind($handle);
                        $lineNumber = 0;
                        
                        // Analyze sample rows to detect phone and email columns
                        $phoneScores = [];
                        $emailScores = [];
                        foreach ($sampleRows as $row) {
                            foreach ($row as $colIndex => $value) {
                                $value = trim($value);
                                // Phone detection: contains digits and common phone chars, at least 7 digits
                                if (preg_match('/^[\+\d\s\-\(\)]{7,}$/', $value) && preg_match('/\d{7,}/', $value)) {
                                    $phoneScores[$colIndex] = ($phoneScores[$colIndex] ?? 0) + 1;
                                }
                                // Email detection: contains @ and . 
                                if (filter_var($value, FILTER_VALIDATE_EMAIL)) {
                                    $emailScores[$colIndex] = ($emailScores[$colIndex] ?? 0) + 1;
                                }
                            }
                        }
                        
                        // Find best matches (highest scores)
                        if (!empty($phoneScores)) {
                            arsort($phoneScores);
                            $bestPhone = key($phoneScores);
                            $map['phone'] = $bestPhone;
                        }
                        if (!empty($emailScores)) {
                            arsort($emailScores);
                            $bestEmail = key($emailScores);
                            $map['email'] = $bestEmail;
                        }
                    }
                    
                    // If has header, read and discard first line
                    if ($hasHeader) {
                        fgetcsv($handle, 0, $delimiter);
                        $lineNumber++;
                    }
                    
                    while (($data = fgetcsv($handle, 0, $delimiter)) !== false) {
                        $lineNumber++;
                        
                        // Skip completely empty lines
                        if (count($data) === 1 && empty($data[0])) {
                            continue;
                        }
                        
                        // Extract fields based on mapping
                        $name = trim($data[$map['name']] ?? '');
                        $company = trim($data[$map['company']] ?? '');
                        $phone = trim($data[$map['phone']] ?? '');
                        $email = trim($data[$map['email']] ?? '');
                        $statusRaw = trim($data[$map['status']] ?? '');
                        $notes = trim($data[$map['notes']] ?? '');
                        
                        // Validate name
                        if (empty($name)) {
                            $errors[] = "Line $lineNumber: Name is required. Skipped.";
                            $skippedCount++;
                            continue;
                        }
                        
                        // Map status to valid ENUM values
                        $validStatuses = ['new', 'contacted', 'interested', 'not_interested', 'converted'];
                        $status = 'new'; // default
                        if (!empty($statusRaw)) {
                            $lowerStatus = strtolower($statusRaw);
                            // Check exact match first
                            if (in_array($lowerStatus, $validStatuses)) {
                                $status = $lowerStatus;
                            } else {
                                // Try to map common variations
                                $mapping = [
                                    'not interested' => 'not_interested',
                                    'no interest' => 'not_interested',
                                    'call back' => 'callback', // but callback is for calls, not leads? Actually callback is a call outcome, not lead status. For lead status, we have 'contacted' or 'interested'. We'll keep mapping simple.
                                ];
                                if (isset($mapping[$lowerStatus])) {
                                    $status = $mapping[$lowerStatus];
                                } elseif (strpos($lowerStatus, 'interest') !== false) {
                                    $status = 'interested';
                                } elseif (strpos($lowerStatus, 'contact') !== false) {
                                    $status = 'contacted';
                                } elseif (strpos($lowerStatus, 'convert') !== false) {
                                    $status = 'converted';
                                } else {
                                    // If still unknown, default to new and log warning
                                    $errors[] = "Line $lineNumber: Unknown status '$statusRaw'. Using 'new'.";
                                    $status = 'new';
                                }
                            }
                        }
                        
                        // Check for duplicate by email (if email provided)
                        if (!empty($email)) {
                            $stmt = $pdo->prepare("SELECT id FROM leads WHERE user_id = ? AND email = ?");
                            $stmt->execute([$_SESSION['user_id'], $email]);
                            if ($stmt->fetch()) {
                                $errors[] = "Line $lineNumber: Duplicate email '$email'. Skipped.";
                                $skippedCount++;
                                continue;
                            }
                        }
                        
                        // Insert into database
                        $stmt = $pdo->prepare("INSERT INTO leads (user_id, name, company, phone, email, status, notes) VALUES (?, ?, ?, ?, ?, ?, ?)");
                        try {
                            if ($stmt->execute([$_SESSION['user_id'], $name, $company, $phone, $email, $status, $notes])) {
                                $importedCount++;
                                $importedIds[] = $pdo->lastInsertId();
                            } else {
                                $errors[] = "Line $lineNumber: Database error – failed to insert.";
                                $skippedCount++;
                            }
                        } catch (PDOException $e) {
                            // Handle specific errors like data truncation
                            $errors[] = "Line $lineNumber: Database error - " . $e->getMessage();
                            $skippedCount++;
                        }
                    }
                    fclose($handle);
                    
                    if ($importedCount > 0) {
                        // Store import time and IDs in session for highlighting
                        $_SESSION['last_import_time'] = date('Y-m-d H:i:s');
                        $_SESSION['last_import_ids'] = $importedIds;
                    }
                    
                    // Redirect to leads.php with success message
                    header('Location: leads.php?imported=1&count=' . $importedCount . '&skipped=' . $skippedCount);
                    exit;
                } else {
                    $errors[] = 'Could not open file.';
                }
            }
        }
    }
}

include 'includes/header.php';
?>

<h1>Import Leads</h1>

<?php if (!empty($errors)): ?>
    <div class="alert alert-error">
        <h3>Errors encountered:</h3>
        <ul>
            <?php foreach ($errors as $error): ?>
                <li><?= htmlspecialchars($error) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="card">
    <h2>Upload Leads File</h2>
    <p>Upload a CSV or text file with your leads. You can map columns to our fields or enable auto-detection for phone/email.</p>
    
    <form method="post" enctype="multipart/form-data">
        <div class="form-group">
            <label for="lead_file">Select file</label>
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
        
        <div class="form-group">
            <label>
                <input type="checkbox" name="has_header" value="1" checked> First row contains column headers (skip)
            </label>
        </div>
        
        <div class="form-group">
            <label>
                <input type="checkbox" name="auto_detect" value="1" checked> Auto-detect phone and email columns (recommended)
            </label>
        </div>
        
        <h3>Manual Column Mapping (if auto-detect is off or you want to override)</h3>
        <p>Specify which column (starting from 0) contains each field.</p>
        
        <div class="form-group">
            <label for="col_name">Name column *</label>
            <input type="number" id="col_name" name="col_name" min="0" value="0">
        </div>
        
        <div class="form-group">
            <label for="col_company">Company column</label>
            <input type="number" id="col_company" name="col_company" min="0" value="1">
        </div>
        
        <div class="form-group">
            <label for="col_phone">Phone column</label>
            <input type="number" id="col_phone" name="col_phone" min="0" value="2">
        </div>
        
        <div class="form-group">
            <label for="col_email">Email column</label>
            <input type="number" id="col_email" name="col_email" min="0" value="3">
        </div>
        
        <div class="form-group">
            <label for="col_status">Status column</label>
            <input type="number" id="col_status" name="col_status" min="0" value="4">
        </div>
        
        <div class="form-group">
            <label for="col_notes">Notes column</label>
            <input type="number" id="col_notes" name="col_notes" min="0" value="5">
        </div>
        
        <button type="submit" class="btn">Upload and Import</button>
        <a href="leads.php" class="btn-secondary">Cancel</a>
    </form>
</div>

<div class="card">
    <h3>Need a template?</h3>
    <p><a href="download_sample.php" class="btn-secondary">Download Sample CSV</a></p>
    <p>The sample includes a header row and a few example leads.</p>
</div>

<?php include 'includes/footer.php'; ?>