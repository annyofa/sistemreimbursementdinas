<?php

/**
 * public/api/upload_receipt.php
 *
 * Endpoint untuk mengunggah foto struk per item biaya reimbursement.
 *
 * Method  : POST (multipart/form-data)
 * Fields  : file    — file struk (JPEG, PNG, atau PDF)
 *           item_id — ID item biaya yang dikaitkan dengan struk ini
 *
 * Response sukses (HTTP 200):
 *   { "item_id": 42, "file_path": "uploads/receipts/receipt_xxx.jpg",
 *     "url": "/reimbursement/uploads/receipts/receipt_xxx.jpg" }
 *
 * Response error (HTTP 4xx / 5xx):
 *   { "error": "pesan kesalahan" }
 *
 * Validasi:
 *   - Autentikasi sesi (semua role yang sudah login)
 *   - MIME via finfo_file(): image/jpeg, image/png, application/pdf
 *   - Ekstensi: jpg, jpeg, png, pdf
 *   - Ukuran maksimum: 5 MB
 *
 * Requirements: 3.1, 3.6
 */

// Matikan display_errors agar PHP warning/notice tidak mencemari respons JSON
error_reporting(0);
ini_set('display_errors', '0');

// Buffer output: tangkap whitespace/BOM dari require agar tidak masuk respons
ob_start();

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

// Selalu kembalikan JSON; bersihkan buffer sebelum header
ob_clean();
header('Content-Type: application/json; charset=utf-8');

// ── Helper: kirim error JSON dan hentikan eksekusi ────────────────────────────
function sendJsonError(int $httpCode, string $message): void
{
    http_response_code($httpCode);
    echo json_encode(['error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Bungkus seluruh logika dalam try/catch agar exception tidak bocor sebagai HTML ──
try {

    // ── Autentikasi: semua role yang sudah login ──────────────────────────────
    requireAuth();

    $currentUser = getCurrentUser();
    $userId      = (int) ($currentUser['user_id'] ?? 0);

    // ── Hanya terima POST ─────────────────────────────────────────────────────
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendJsonError(405, 'Method not allowed');
    }

    // ── Validasi item_id ──────────────────────────────────────────────────────
    $itemId = (int) ($_POST['item_id'] ?? 0);
    if ($itemId <= 0) {
        sendJsonError(400, 'Parameter item_id diperlukan dan harus berupa bilangan positif.');
    }

    // ── Cek keberadaan file di $_FILES ────────────────────────────────────────
    if (
        !isset($_FILES['file']) ||
        $_FILES['file']['error'] === UPLOAD_ERR_NO_FILE
    ) {
        sendJsonError(400, 'File struk wajib diunggah.');
    }

    // Tangani error upload level PHP
    $uploadError = $_FILES['file']['error'];
    if ($uploadError !== UPLOAD_ERR_OK) {
        $phpUploadMessages = [
            UPLOAD_ERR_INI_SIZE   => 'Ukuran file melebihi batas konfigurasi server.',
            UPLOAD_ERR_FORM_SIZE  => 'Ukuran file melebihi batas form.',
            UPLOAD_ERR_PARTIAL    => 'File hanya terunggah sebagian.',
            UPLOAD_ERR_NO_TMP_DIR => 'Direktori sementara tidak tersedia.',
            UPLOAD_ERR_CANT_WRITE => 'Gagal menulis file ke disk.',
            UPLOAD_ERR_EXTENSION  => 'Ekstensi PHP menghentikan proses upload.',
        ];
        $msg = $phpUploadMessages[$uploadError] ?? 'Terjadi kesalahan saat mengunggah file.';
        sendJsonError(500, $msg);
    }

    $tmpPath      = $_FILES['file']['tmp_name'];
    $originalName = $_FILES['file']['name'];
    $fileSize     = (int) $_FILES['file']['size'];

    // ── Validasi ukuran: maksimum 5 MB ────────────────────────────────────────
    $maxFileSize = 5 * 1024 * 1024;
    if ($fileSize > $maxFileSize) {
        sendJsonError(413, 'Ukuran file melebihi batas maksimum 5 MB.');
    }

    // ── Validasi ekstensi dari nama file asli ─────────────────────────────────
    $allowedExtensions = ['jpg', 'jpeg', 'png', 'pdf'];
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExtensions, true)) {
        sendJsonError(415, 'Format file tidak didukung. Gunakan JPG, PNG, atau PDF.');
    }

    // ── Validasi MIME type via finfo_file() ───────────────────────────────────
    $allowedMimeByExt = [
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'pdf'  => 'application/pdf',
    ];

    $finfo        = finfo_open(FILEINFO_MIME_TYPE);
    $detectedMime = finfo_file($finfo, $tmpPath);
    finfo_close($finfo);

    if ($detectedMime === false || $detectedMime !== ($allowedMimeByExt[$ext] ?? '')) {
        sendJsonError(415, 'Format file tidak didukung. MIME type tidak sesuai.');
    }

    // ── Pastikan item_id merujuk ke item yang ada ─────────────────────────────
    $db       = getDB();
    $stmtItem = $db->prepare(
        "SELECT ri.id, ri.reimbursement_id, r.user_id
         FROM reimbursement_items ri
         JOIN reimbursements r ON r.id = ri.reimbursement_id
         WHERE ri.id = :item_id"
    );
    $stmtItem->execute([':item_id' => $itemId]);
    $item = $stmtItem->fetch();

    if ($item === false) {
        sendJsonError(404, 'Item biaya tidak ditemukan.');
    }

    // ── Siapkan direktori tujuan ──────────────────────────────────────────────
    $uploadDir = __DIR__ . '/../../uploads/receipts/';
    if (!is_dir($uploadDir)) {
        if (!mkdir($uploadDir, 0755, true)) {
            sendJsonError(500, 'Gagal membuat direktori penyimpanan. Hubungi administrator.');
        }
    }

    // ── Generate nama file unik ───────────────────────────────────────────────
    $uniqueFilename = uniqid('receipt_', true) . '.' . $ext;
    $destPath       = $uploadDir . $uniqueFilename;

    // ── Pindahkan file dari lokasi sementara ke tujuan ────────────────────────
    if (!move_uploaded_file($tmpPath, $destPath)) {
        sendJsonError(500, 'Gagal menyimpan file. Hubungi administrator.');
    }

    // ── Path relatif dan URL publik ───────────────────────────────────────────
    $filePath = 'uploads/receipts/' . $uniqueFilename;
    $fileUrl  = UPLOADS_URL . '/receipts/' . $uniqueFilename;

    // ── Audit log ─────────────────────────────────────────────────────────────
    logAudit(
        $userId,
        'UPLOAD_RECEIPT',
        'reimbursement_items',
        $itemId,
        [
            'context' => [
                'item_id'          => $itemId,
                'reimbursement_id' => (int) $item['reimbursement_id'],
                'file_path'        => $filePath,
                'mime_type'        => $detectedMime,
                'file_size_bytes'  => $fileSize,
            ],
        ]
    );

    // ── Respons sukses ────────────────────────────────────────────────────────
    http_response_code(200);
    echo json_encode(
        [
            'item_id'   => $itemId,
            'file_path' => $filePath,
            'url'       => $fileUrl,
        ],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;

} catch (\Throwable $e) {
    // Tangkap semua exception/error agar tidak bocor sebagai HTML
    http_response_code(500);
    echo json_encode(['error' => 'Terjadi kesalahan internal server.'], JSON_UNESCAPED_UNICODE);
    exit;
}