<?php

/**
 * public/api/item_verify.php
 *
 * Endpoint AJAX untuk memverifikasi satu item biaya reimbursement oleh finance.
 *
 * Method  : POST
 * Payload : JSON body atau form fields
 *           - item_id             (int,    wajib)
 *           - verification_status (string, wajib) — 'diverifikasi' atau 'ditolak'
 *           - approved_amount     (float,  opsional)
 *
 * Header  : X-CSRF-Token: <token>
 *
 * Response sukses : JSON { "success": true }
 * Response error  : JSON { "error": "pesan" } dengan HTTP status sesuai
 *
 * Requirements: 5.3
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/audit.php';

// ── Autentikasi & otorisasi ───────────────────────────────────────────────────
requireAuth();

// Selalu kembalikan JSON (set setelah auth agar redirect tetap bekerja)
header('Content-Type: application/json; charset=utf-8');

requireRole(['finance']);

// ── Hanya terima POST ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed. Gunakan POST.']);
    exit;
}

// ── Verifikasi CSRF via header X-CSRF-Token ───────────────────────────────────
$csrfHeader   = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
$sessionToken = $_SESSION['csrf_token'] ?? '';

if ($sessionToken === '' || !hash_equals($sessionToken, $csrfHeader)) {
    http_response_code(403);
    echo json_encode(['error' => 'Token CSRF tidak valid.']);
    exit;
}

// ── Baca payload: JSON body atau POST form fields ─────────────────────────────
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (stripos($contentType, 'application/json') !== false) {
    $body = file_get_contents('php://input');
    $data = json_decode($body, true);
    if (!is_array($data)) {
        http_response_code(400);
        echo json_encode(['error' => 'Payload JSON tidak valid.']);
        exit;
    }
} else {
    $data = $_POST;
}

// ── Ekstrak dan validasi parameter ───────────────────────────────────────────
$itemId             = isset($data['item_id'])             ? (int) $data['item_id']              : 0;
$verificationStatus = isset($data['verification_status']) ? trim($data['verification_status'])  : '';
$approvedAmount     = isset($data['approved_amount'])     ? $data['approved_amount']             : null;

$allowedStatuses = ['diverifikasi', 'ditolak'];

if ($itemId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Parameter item_id tidak valid atau tidak diberikan.']);
    exit;
}

if ($verificationStatus === '' || !in_array($verificationStatus, $allowedStatuses, true)) {
    http_response_code(400);
    echo json_encode([
        'error' => 'Parameter verification_status tidak valid. Nilai yang diizinkan: diverifikasi, ditolak.',
    ]);
    exit;
}

// Normalisasi approved_amount: null jika tidak diberikan, float jika ada
$approvedAmountValue = null;
if ($approvedAmount !== null && $approvedAmount !== '') {
    if (!is_numeric($approvedAmount) || (float) $approvedAmount < 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Parameter approved_amount harus berupa angka non-negatif.']);
        exit;
    }
    $approvedAmountValue = (float) $approvedAmount;
}

$db     = getDB();
$userId = (int) $_SESSION['user_id'];

// ── Ambil data item sebelum diubah (untuk audit before/after) ─────────────────
$stmtBefore = $db->prepare(
    "SELECT id, reimbursement_id, description, amount, verification_status, approved_amount, verified_by, verified_at
     FROM reimbursement_items
     WHERE id = :item_id"
);
$stmtBefore->execute([':item_id' => $itemId]);
$itemBefore = $stmtBefore->fetch();

if ($itemBefore === false) {
    http_response_code(404);
    echo json_encode(['error' => 'Item biaya tidak ditemukan.']);
    exit;
}

// ── UPDATE item: verification_status, verified_by, verified_at, approved_amount ──
try {
    $stmt = $db->prepare(
        "UPDATE reimbursement_items
         SET verification_status = :verification_status,
             verified_by         = :verified_by,
             verified_at         = NOW(),
             approved_amount     = :approved_amount
         WHERE id = :item_id"
    );
    $stmt->execute([
        ':verification_status' => $verificationStatus,
        ':verified_by'         => $userId,
        ':approved_amount'     => $approvedAmountValue,
        ':item_id'             => $itemId,
    ]);

    if ($stmt->rowCount() === 0) {
        http_response_code(500);
        echo json_encode(['error' => 'Gagal memperbarui status verifikasi item.']);
        exit;
    }
} catch (\PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Kesalahan database saat memperbarui item.']);
    exit;
}

// ── Audit log ─────────────────────────────────────────────────────────────────
logAudit(
    $userId,
    'VERIFY_ITEM',
    'reimbursement_items',
    $itemId,
    [
        'before' => [
            'verification_status' => $itemBefore['verification_status'],
            'approved_amount'     => $itemBefore['approved_amount'],
            'verified_by'         => $itemBefore['verified_by'],
            'verified_at'         => $itemBefore['verified_at'],
        ],
        'after' => [
            'verification_status' => $verificationStatus,
            'approved_amount'     => $approvedAmountValue,
            'verified_by'         => $userId,
        ],
        'context' => [
            'reimbursement_id' => (int) $itemBefore['reimbursement_id'],
        ],
    ]
);

// ── Respons sukses ────────────────────────────────────────────────────────────
echo json_encode(['success' => true]);
exit;
