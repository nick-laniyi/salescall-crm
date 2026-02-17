<?php
// functions.php - Helper functions for permissions and common tasks

// Original user-only permission checks (renamed)
function userCanViewLead($pdo, $leadId, $userId) {
    $stmt = $pdo->prepare("SELECT l.user_id = ? as is_owner, ls.permission 
                           FROM leads l 
                           LEFT JOIN lead_shares ls ON l.id = ls.lead_id AND ls.user_id = ?
                           WHERE l.id = ?");
    $stmt->execute([$userId, $userId, $leadId]);
    $result = $stmt->fetch();
    if (!$result) return false;
    return ($result['is_owner'] || $result['permission'] !== null);
}

function userCanEditLead($pdo, $leadId, $userId) {
    $stmt = $pdo->prepare("SELECT l.user_id = ? as is_owner, ls.permission 
                           FROM leads l 
                           LEFT JOIN lead_shares ls ON l.id = ls.lead_id AND ls.user_id = ?
                           WHERE l.id = ?");
    $stmt->execute([$userId, $userId, $leadId]);
    $result = $stmt->fetch();
    if (!$result) return false;
    return ($result['is_owner'] || $result['permission'] === 'edit');
}

function userCanDeleteLead($pdo, $leadId, $userId) {
    $stmt = $pdo->prepare("SELECT id FROM leads WHERE id = ? AND user_id = ?");
    $stmt->execute([$leadId, $userId]);
    return $stmt->fetch() !== false;
}

function getAccessibleLeadIds($pdo, $userId, $includeAll = false) {
    // For admin, return all leads if $includeAll is true
    if ($includeAll) {
        $stmt = $pdo->prepare("SELECT id FROM leads");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
    // For regular user: own or shared
    $stmt = $pdo->prepare("SELECT l.id 
                           FROM leads l 
                           LEFT JOIN lead_shares ls ON l.id = ls.lead_id AND ls.user_id = ? 
                           WHERE l.user_id = ? OR ls.user_id IS NOT NULL");
    $stmt->execute([$userId, $userId]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}