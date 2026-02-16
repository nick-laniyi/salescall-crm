<?php
// Force download of a sample CSV file
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="sample_leads.csv"');

$output = fopen('php://output', 'w');

// Header row
fputcsv($output, ['Name', 'Company', 'Phone', 'Email', 'Status', 'Notes']);

// Sample data
fputcsv($output, ['John Doe', 'Acme Inc', '+2348012345678', 'john@example.com', 'new', 'Cold lead from website']);
fputcsv($output, ['Jane Smith', 'Globex', '+2348098765432', 'jane@globex.com', 'interested', 'Follow up next week']);
fputcsv($output, ['Bob Johnson', '', '+2348022334455', '', 'contacted', 'Left voicemail']);

fclose($output);
exit;