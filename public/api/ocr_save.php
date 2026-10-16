<?php

/**
 * public/api/ocr_save.php
 *
 * Endpoint untuk menyimpan hasil OCR struk ke tabel `receipts`.
 *
 * Method  : POST
 * Payload : JSON body atau form fields
 *           - item_id       (int, wajib)
 *           - file_path     (string, wajib) — path relatif file yang sudah diupload
 *           - merchant      (string, opsional)
 *           - date          (string, opsional) — format YYYY-MM-DD
 *           - total         (float, opsional)
 *           - confidence    (float, wajib) — nilai 0-100
 *           - raw_text      (string, opsional)
 *
 * Response sukses : JSON { "receipt_id": int, "ocr_status": "sukses"|"buram" }
 * Response error  : JSON { "error": "pesan" } dengan HTTP status sesuai
 *
 * Requirements: 3.2, 3.3
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

try {

    // ── Autentikasi ────────────────────────────────────────────────────────────
    requireAuth();

    // ── Hanya terima POST ──────────────────────────────────────────────────────
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed. Gunakan POST.']);
        exit;
    }

    // ── Baca payload: JSON body atau POST form fields ─────────────────────────
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

    // ── Ekstrak dan validasi parameter ────────────────────────────────────────
    $itemId     = isset($data['item_id'])    ? (int)   $data['item_id']      : 0;
    $filePath   = isset($data['file_path'])  ? trim($data['file_path'])      : '';
    $merchant   = isset($data['merchant'])   ? trim($data['merchant'])       : null;
    $dateRaw    = isset($data['date'])       ? trim($data['date'])           : null;
    $total      = isset($data['total'])      ? (float) $data['total']        : null;
    $confidence = isset($data['confidence']) ? (float) $data['confidence']   : null;
    $rawText    = isset($data['raw_text'])   ? trim($data['raw_text'])       : null;

    if ($itemId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Parameter item_id tidak valid atau tidak diberikan.']);
        exit;
    }

    if ($filePath === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Parameter file_path tidak boleh kosong.']);
        exit;
    }

    if ($confidence === null) {
        http_response_code(400);
        echo json_encode(['error' => 'Parameter confidence tidak boleh kosong.']);
        exit;
    }

    if ($confidence < 0 || $confidence > 100) {
        http_response_code(400);
        echo json_encode(['error' => 'Nilai confidence harus berada di rentang 0-100.']);
        exit;
    }

    // ── Normalisasi tanggal — pastikan format YYYY-MM-DD atau NULL ────────────
    $ocrDate = null;
    if ($dateRaw !== null && $dateRaw !== '') {
        $parsed  = date_create_from_format('Y-m-d', $dateRaw);
        $ocrDate = ($parsed !== false && $parsed->format('Y-m-d') === $dateRaw) ? $dateRaw : null;
    }

    // ── Tentukan ocr_status berdasarkan confidence ────────────────────────────
    $ocrStatus = determineOcrStatus($confidence);

    // ── Verifikasi item_id ada dan dimiliki user yang sedang login ────────────
    $db     = getDB();
    $userId = (int) ($_SESSION['user_id'] ?? 0);

    $stmtItem = $db->prepare(
        "SELECT ri.id
         FROM reimbursement_items ri
         JOIN reimbursements r ON r.id = ri.reimbursement_id
         WHERE ri.id = :item_id
           AND r.user_id = :user_id"
    );
    $stmtItem->execute([
        ':item_id' => $itemId,
        ':user_id' => $userId,
    ]);
    $item = $stmtItem->fetch();

    if ($item === false) {
        http_response_code(404);
        echo json_encode(['error' => 'Item biaya tidak ditemukan atau bukan milik Anda.']);
        exit;
    }

    // ── INSERT ke tabel receipts ──────────────────────────────────────────────
    $stmt = $db->prepare(
        "INSERT INTO receipts
            (item_id, file_path, ocr_status, ocr_raw_text, ocr_merchant, ocr_date, ocr_total, ocr_confidence)
         VALUES
            (:item_id, :file_path, :ocr_status, :ocr_raw_text, :ocr_merchant, :ocr_date, :ocr_total, :ocr_confidence)"
    );

    $stmt->execute([
        ':item_id'        => $itemId,
        ':file_path'      => $filePath,
        ':ocr_status'     => $ocrStatus,
        ':ocr_raw_text'   => ($rawText !== '' ? $rawText : null),
        ':ocr_merchant'   => ($merchant !== null && $merchant !== '' ? $merchant : null),
        ':ocr_date'       => $ocrDate,
        ':ocr_total'      => ($total !== null && $total > 0 ? $total : null),
        ':ocr_confidence' => $confidence,
    ]);

    $receiptId = (int) $db->lastInsertId();

    // ── Kembalikan hasil ──────────────────────────────────────────────────────
    http_response_code(201);
    echo json_encode([
        'receipt_id' => $receiptId,
        'ocr_status' => $ocrStatus,
    ]);
    exit;

} catch (\PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Gagal menyimpan hasil OCR ke database.'], JSON_UNESCAPED_UNICODE);
    exit;
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Terjadi kesalahan: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine()], JSON_UNESCAPED_UNICODE);
    exit;
}