<?php
require_once 'includes/auth.php';
require_once 'includes/config.php';

$errors = [];
$success = [];
$importedCount = 0;
$skippedCount = 0;

// Handle sample download (separate file)
// Handle file upload and import
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['lead_file'])) {
    $file = $_FILES['lead_file'];
    $delimiter = $_POST['delimiter'] ?? ',';
    $hasHeader = isset($_POST['has_header']);
    
    // Validate delimiter
    if (!in_array($delimiter, [',', ';', '\t', '|'])) {
        $delimiter = ',';
    }
    
    // Column mapping
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
        // Check file size (optional: limit to 2MB)
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
                        $status = trim($data[$map['status']] ?? 'new');
                        $notes = trim($data[$map['notes']] ?? '');
                        
                        // Validate name
                        if (empty($name)) {
                            $errors[] = "Line $lineNumber: Name is required. Skipped.";
                            $skippedCount++;
                            continue;
                        }
                        
                        // Validate status
                        $validStatuses = ['new', 'contacted', 'interested', 'not_interested', 'converted'];
                        if (!empty($status) && !in_array($status, $validStatuses)) {
                            $errors[] = "Line $lineNumber: Invalid status '$status'. Using 'new'.";
                            $status = 'new';
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
                        if ($stmt->execute([$_SESSION['user_id'], $name, $company, $phone, $email, $status, $notes])) {
                            $importedCount++;
                        } else {
                            $errors[] = "Line $lineNumber: Database error – failed to insert.";
                            $skippedCount++;
                        }
                    }
                    fclose($handle);
                    
                    if ($importedCount > 0) {
                        $success[] = "$importedCount leads imported successfully.";
                    }
                    if ($skippedCount > 0) {
                        $success[] = "$skippedCount lines skipped (see errors for details).";
                    }
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
        <?php foreach ($errors as $error): ?>
            <div><?= htmlspecialchars($error) ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if (!empty($success)): ?>
    <div class="alert alert-success">
        <?php foreach ($success as $msg): ?>
            <div><?= htmlspecialchars($msg) ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="card">
    <h2>Upload Leads File</h2>
    <p>Upload a CSV or text file with your leads. You can map columns to our fields.</p>
    
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
                <input type="checkbox" name="has_header" value="1"> First row contains column headers (skip)
            </label>
        </div>
        
        <h3>Column Mapping</h3>
        <p>Specify which column (starting from 0) contains each field.</p>
        
        <div class="form-group">
            <label for="col_name">Name column *</label>
            <input type="number" id="col_name" name="col_name" min="0" value="0" required>
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