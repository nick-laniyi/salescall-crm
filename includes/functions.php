<?php
// functions.php - Helper functions for permissions and common tasks

function getAccessibleLeadsQuery($pdo, $userId) {
    // Returns a query string and parameters to fetch leads accessible by user (own or shared)
    $sql = "SELECT l.*, 
                   (l.user_id = :user_id) as is_owner,
                   MAX(ls.permission) as shared_permission
            FROM leads l
            LEFT JOIN lead_shares ls ON l.id = ls.lead_id AND ls.user_id = :user_id
            WHERE l.user_id = :user_id OR ls.user_id IS NOT NULL
            GROUP BY l.id";
    return [$sql, [':user_id' => $userId]];
}

function canViewLead($pdo, $leadId, $userId) {
    // Check if user can view a lead (own or shared with view/edit)
    $stmt = $pdo->prepare("SELECT l.user_id = ? as is_owner, ls.permission 
                           FROM leads l 
                           LEFT JOIN lead_shares ls ON l.id = ls.lead_id AND ls.user_id = ?
                           WHERE l.id = ?");
    $stmt->execute([$userId, $userId, $leadId]);
    $result = $stmt->fetch();
    if (!$result) return false;
    return ($result['is_owner'] || $result['permission'] !== null);
}

function canEditLead($pdo, $leadId, $userId) {
    // Check if user can edit a lead (own or shared with edit permission)
    $stmt = $pdo->prepare("SELECT l.user_id = ? as is_owner, ls.permission 
                           FROM leads l 
                           LEFT JOIN lead_shares ls ON l.id = ls.lead_id AND ls.user_id = ?
                           WHERE l.id = ?");
    $stmt->execute([$userId, $userId, $leadId]);
    $result = $stmt->fetch();
    if (!$result) return false;
    return ($result['is_owner'] || $result['permission'] === 'edit');
}

function canDeleteLead($pdo, $leadId, $userId) {
    // Only owner can delete
    $stmt = $pdo->prepare("SELECT id FROM leads WHERE id = ? AND user_id = ?");
    $stmt->execute([$leadId, $userId]);
    return $stmt->fetch() !== false;
}