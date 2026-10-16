<?php

/**
 * public/api/notifications.php
 *
 * Endpoint polling notifikasi in-app.
 *
 * Method : GET
 * Params :
 *   ?count_only=1  — kembalikan hanya { "count": N }
 *   (tanpa param)  — kembalikan { "count": N, "notifications": [...10 terbaru...] }
 *
 * Requirements: 8.2, 8.3
 */

// Matikan display_errors agar PHP warning/notice tidak mencemari respons JSON
error_reporting(0);
ini_set('display_errors', '0');

// Buffer output untuk mencegah whitespace/BOM mencemari JSON
ob_start();

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/notifications.php';

// Selalu kembalikan JSON; bersihkan buffer sebelum header
ob_clean();
header('Content-Type: application/json; charset=utf-8');

try {

    // ── Autentikasi ────────────────────────────────────────────────────────────
    requireAuth();

    $userId = (int) ($_SESSION['user_id'] ?? 0);
    if ($userId <= 0) {
        http_response_code(401);
        echo json_encode(['error' => 'Tidak terautentikasi.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ── Hanya terima GET ───────────────────────────────────────────────────────
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed. Gunakan GET.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $count = getUnreadCount($userId);

    // ── Mode count_only ────────────────────────────────────────────────────────
    if (!empty($_GET['count_only'])) {
        echo json_encode(['count' => $count], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ── Mode lengkap: count + 10 notifikasi terbaru ────────────────────────────
    $notifications = getNotifications($userId, 10);

    // Normalisasi tipe data untuk JSON
    $result = [];
    foreach ($notifications as $n) {
        $result[] = [
            'id'               => (int) $n['id'],
            'reimbursement_id' => (int) $n['reimbursement_id'],
            'title'            => $n['title'],
            'message'          => $n['message'],
            'is_read'          => (bool) $n['is_read'],
            'created_at'       => $n['created_at'],
        ];
    }

    echo json_encode(
        ['count' => $count, 'notifications' => $result],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;

} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Terjadi kesalahan: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine()], JSON_UNESCAPED_UNICODE);
    exit;
}