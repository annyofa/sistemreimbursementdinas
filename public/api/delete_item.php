<?php

/**
 * public/api/delete_item.php
 *
 * Endpoint untuk menghapus item biaya dari pengajuan reimbursement.
 *
 * Method : POST
 * Payload: item_id, reimbursement_id, csrf_token
 *
 * Syarat:
 *   - User sudah login dengan role 'pegawai'
 *   - Pengajuan milik user yang sedang login
 *   - Status pengajuan harus 'draft'
 *
 * Requirements: 2.8
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/audit.php';

// ── Autentikasi & otorisasi ───────────────────────────────────────────────────
requireAuth();
requireRole(['pegawai']);

// ── Header JSON ───────────────────────────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');

// ── Hanya terima POST ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// ── Verifikasi CSRF ───────────────────────────────────────────────────────────
verifyCsrf();

// ── Ambil dan validasi parameter ──────────────────────────────────────────────
$itemId  = (int) ($_POST['item_id']          ?? 0);
$reimbId = (int) ($_POST['reimbursement_id'] ?? 0);
$userId  = (int) $_SESSION['user_id'];

if ($itemId <= 0 || $reimbId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Parameter item_id dan reimbursement_id diperlukan.']);
    exit;
}

$db = getDB();

// ── Cek pengajuan: harus milik user ini dan berstatus 'draft' ─────────────────
$stmtReimb = $db->prepare(
    "SELECT id, user_id, status
     FROM reimbursements
     WHERE id = :id AND user_id = :user_id"
);
$stmtReimb->execute([':id' => $reimbId, ':user_id' => $userId]);
$reimb = $stmtReimb->fetch();

if ($reimb === false) {
    http_response_code(404);
    echo json_encode(['error' => 'Pengajuan tidak ditemukan atau bukan milik Anda.']);
    exit;
}

if ($reimb['status'] !== 'draft') {
    http_response_code(403);
    echo json_encode(['error' => 'Item tidak dapat dihapus pada status pengajuan ini']);
    exit;
}

// ── Cek item: harus termasuk dalam pengajuan ini ──────────────────────────────
$stmtItem = $db->prepare(
    "SELECT id FROM reimbursement_items
     WHERE id = :item_id AND reimbursement_id = :reimb_id"
);
$stmtItem->execute([':item_id' => $itemId, ':reimb_id' => $reimbId]);
$item = $stmtItem->fetch();

if ($item === false) {
    http_response_code(404);
    echo json_encode(['error' => 'Item tidak ditemukan']);
    exit;
}

// ── Hapus item ────────────────────────────────────────────────────────────────
// Trigger trg_item_ad otomatis memperbarui total_amount di reimbursements
$stmtDel = $db->prepare(
    "DELETE FROM reimbursement_items WHERE id = :item_id AND reimbursement_id = :reimb_id"
);
$stmtDel->execute([':item_id' => $itemId, ':reimb_id' => $reimbId]);

if ($stmtDel->rowCount() === 0) {
    http_response_code(500);
    echo json_encode(['error' => 'Gagal menghapus item biaya.']);
    exit;
}

// ── Audit log ─────────────────────────────────────────────────────────────────
logAudit(
    $userId,
    'DELETE_ITEM',
    'reimbursement_items',
    $itemId,
    ['reimbursement_id' => $reimbId]
);

// ── Respons sukses ────────────────────────────────────────────────────────────
echo json_encode([
    'success' => true,
    'message' => 'Item biaya berhasil dihapus',
]);
exit;
