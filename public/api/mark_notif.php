<?php

/**
 * public/api/mark_notif.php
 *
 * Endpoint AJAX untuk menandai notifikasi sebagai sudah dibaca.
 *
 * Method  : POST
 * Header  : X-CSRF-Token: <token>
 *
 * Mode 1 — Tandai semua dibaca (?all=1):
 *   Query string : ?all=1
 *   Response     : { "success": true, "count": 0 }
 *
 * Mode 2 — Tandai satu notifikasi dibaca:
 *   Payload      : notif_id (int, wajib) — via form-data atau JSON body
 *   Response     : { "success": true, "redirect_url": "/reimbursement/public/shared/detail.php?id=X" }
 *
 * Response error : { "error": "pesan" } dengan HTTP status sesuai
 *
 * Requirements: 8.3, 8.7
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/notifications.php';

// ── Autentikasi ──────────────────────────────────────────────────────────────
// Jika belum login, requireAuth() redirect ke login.php.
// Untuk request AJAX yang tidak ingin redirect, cek sesi secara eksplisit
// dan kembalikan JSON 401 sebelum memanggil requireAuth() yang bisa redirect.
if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Sesi ada — periksa timeout dan perbarui last_activity
requireAuth();

// ── Selalu kembalikan JSON ────────────────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');

// ── Hanya terima POST ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed. Gunakan POST.'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Verifikasi CSRF via header X-CSRF-Token ───────────────────────────────────
$csrfHeader   = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
$sessionToken = $_SESSION['csrf_token'] ?? '';

if ($sessionToken === '' || !hash_equals($sessionToken, $csrfHeader)) {
    http_response_code(403);
    echo json_encode(['error' => 'Token CSRF tidak valid.'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Ambil user_id dari sesi ───────────────────────────────────────────────────
$userId = (int) $_SESSION['user_id'];

try {

    // ── MODE 1: Tandai semua notifikasi sebagai dibaca (?all=1) ───────────────
    if (isset($_GET['all']) && $_GET['all'] === '1') {
        markAllAsRead($userId);

        echo json_encode(
            ['success' => true, 'count' => 0],
            JSON_UNESCAPED_UNICODE
        );
        exit;
    }

    // ── MODE 2: Tandai satu notifikasi sebagai dibaca ─────────────────────────

    // Baca payload: JSON body atau POST form fields
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($contentType, 'application/json') !== false) {
        $body = file_get_contents('php://input');
        $data = json_decode($body, true);
        if (!is_array($data)) {
            http_response_code(400);
            echo json_encode(['error' => 'Payload JSON tidak valid.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    } else {
        $data = $_POST;
    }

    $notifId = isset($data['notif_id']) ? (int) $data['notif_id'] : 0;

    if ($notifId <= 0) {
        http_response_code(400);
        echo json_encode(
            ['error' => 'Parameter notif_id diperlukan dan harus berupa bilangan positif.'],
            JSON_UNESCAPED_UNICODE
        );
        exit;
    }

    // Tandai notifikasi sebagai sudah dibaca (validasi kepemilikan ada di dalam fungsi)
    markAsRead($notifId, $userId);

    // Ambil reimbursement_id dari notifikasi untuk membangun redirect_url.
    // Validasi kepemilikan diterapkan pada query agar user tidak bisa mengakses
    // notifikasi milik orang lain.
    $db   = getDB();
    $stmt = $db->prepare(
        "SELECT reimbursement_id
         FROM   notifications
         WHERE  id      = :notif_id
           AND  user_id = :user_id"
    );
    $stmt->execute([
        ':notif_id' => $notifId,
        ':user_id'  => $userId,
    ]);
    $notif = $stmt->fetch();

    if ($notif === false) {
        // Notifikasi tidak ditemukan atau bukan milik user ini
        http_response_code(404);
        echo json_encode(
            ['error' => 'Notifikasi tidak ditemukan.'],
            JSON_UNESCAPED_UNICODE
        );
        exit;
    }

    $reimbursementId = (int) $notif['reimbursement_id'];
    $redirectUrl     = BASE_URL . '/shared/detail.php?id=' . $reimbursementId;

    echo json_encode(
        ['success' => true, 'redirect_url' => $redirectUrl],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;

} catch (\PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Kesalahan database.'], JSON_UNESCAPED_UNICODE);
    exit;
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Terjadi kesalahan internal server.'], JSON_UNESCAPED_UNICODE);
    exit;
}
