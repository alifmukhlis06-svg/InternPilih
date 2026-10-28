<?php

// Placement verification is derived from the latest version of each required
// document linked to an accepted offer.
const REQUIRED_PLACEMENT_DOCUMENT_TYPES = ['offer_letter', 'student_id', 'agreement'];

function recalculatePlacementVerification(int $offerId, ?int $updatedBy = null): array {
    $offerStmt = db()->prepare("
        SELECT id, status, placement_verification_status
        FROM offers
        WHERE id = ?
        LIMIT 1
    ");
    $offerStmt->bind_param('i', $offerId);
    $offerStmt->execute();
    $offer = $offerStmt->get_result()->fetch_assoc();
    if (!$offer) {
        return [
            'found' => false,
            'changed' => false,
            'previous_status' => null,
            'status' => null,
        ];
    }

    $newStatus = 'not_submitted';
    if ($offer['status'] === 'accepted') {
        $documentStmt = db()->prepare("
            SELECT d.doc_type, d.status
            FROM documents d
            JOIN (
                SELECT doc_type, MAX(id) AS latest_id
                FROM documents
                WHERE offer_id = ?
                  AND doc_type IN ('offer_letter', 'student_id', 'agreement')
                GROUP BY doc_type
            ) latest ON latest.latest_id = d.id
        ");
        $documentStmt->bind_param('i', $offerId);
        $documentStmt->execute();
        $documents = $documentStmt->get_result()->fetch_all(MYSQLI_ASSOC);

        if ($documents !== []) {
            $statuses = [];
            foreach ($documents as $document) {
                $statuses[$document['doc_type']] = $document['status'];
            }

            if (in_array('rejected', $statuses, true)) {
                $newStatus = 'rejected';
            } else {
                $allApproved = true;
                foreach (REQUIRED_PLACEMENT_DOCUMENT_TYPES as $requiredType) {
                    if (($statuses[$requiredType] ?? null) !== 'approved') {
                        $allApproved = false;
                        break;
                    }
                }
                $newStatus = $allApproved ? 'verified' : 'pending';
            }
        }
    }

    $previousStatus = $offer['placement_verification_status'] ?: 'not_submitted';
    $changed = $previousStatus !== $newStatus;
    if ($changed) {
        $update = db()->prepare("
            UPDATE offers
            SET placement_verification_status = ?,
                placement_verification_updated_at = NOW(),
                placement_verification_updated_by = ?
            WHERE id = ?
        ");
        $update->bind_param('sii', $newStatus, $updatedBy, $offerId);
        $update->execute();
    }

    return [
        'found' => true,
        'changed' => $changed,
        'previous_status' => $previousStatus,
        'status' => $newStatus,
    ];
}
