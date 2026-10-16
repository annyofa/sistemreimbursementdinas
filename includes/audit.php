<?php

/**
 * includes/audit.php
 *
 * Modul pencatatan audit log untuk seluruh aksi pengguna yang mengubah data.
 *
 * PENTING: File ini HANYA menyediakan fungsi INSERT ke tabel `audit_logs`.
 * Tidak ada fungsi DELETE atau UPDATE pada tabel `audit_logs` — sesuai
 * Requirements 8.4 dan 8.6 (imutabilitas audit log).
 *
 * @see Requirements 8.4 — Setiap aksi yang mengubah data harus dicatat di audit log
 * @see Requirements 8.6 — Audit log bersifat permanen, tidak boleh dihapus atau diubah
 */

require_once __DIR__ . '/../config/database.php';

/**
 * Mencatat satu entri audit log ke tabel `audit_logs`.
 *
 * Contoh pemanggilan:
 *   logAudit($userId, 'UPDATE_STATUS', 'reimbursements', 42, [
 *       'before' => ['status' => 'draft'],
 *       'after'  => ['status' => 'diajukan'],
 *   ]);
 *
 *   logAudit($userId, 'LOGIN', 'users', $userId, [
 *       'context' => ['role' => 'pegawai'],
 *   ]);
 *
 * @param int    $userId    ID pengguna yang melakukan aksi (dari sesi aktif).
 * @param string $action    Kode aksi, misalnya 'LOGIN', 'CREATE_REIMBURSEMENT',
 *                          'UPDATE_STATUS', 'UPLOAD_RECEIPT', 'DELETE_ITEM', dll.
 * @param string $tableName Nama tabel yang terpengaruh, misalnya 'reimbursements',
 *                          'reimbursement_items', 'users', dll.
 * @param int    $recordId  ID primary key record yang terpengaruh pada tabel tersebut.
 * @param array  $detail    Data tambahan opsional. Struktur yang dianjurkan:
 *                          [
 *                              'before'  => [...],  // data sebelum perubahan
 *                              'after'   => [...],  // data sesudah perubahan
 *                              'context' => [...],  // informasi konteks tambahan
 *                          ]
 *                          Array akan di-JSON-encode sebelum disimpan.
 *
 * @return void
 */
function logAudit(
    int    $userId,
    string $action,
    string $tableName,
    int    $recordId,
    array  $detail = []
): void {
    $pdo = getDB();

    // Tangkap IP address klien; gunakan string kosong jika tidak tersedia
    // (misalnya saat dipanggil dari CLI atau unit test).
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '';

    // JSON-encode detail (before/after/context). Gunakan JSON_UNESCAPED_UNICODE
    // agar karakter Indonesia tersimpan secara terbaca tanpa escape sequence.
    $detailJson = json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $sql = '
        INSERT INTO audit_logs
            (user_id, action, table_name, record_id, detail, ip_address, created_at)
        VALUES
            (:user_id, :action, :table_name, :record_id, :detail, :ip_address, NOW())
    ';

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':user_id'    => $userId,
        ':action'     => $action,
        ':table_name' => $tableName,
        ':record_id'  => $recordId,
        ':detail'     => $detailJson,
        ':ip_address' => $ipAddress,
    ]);
}
