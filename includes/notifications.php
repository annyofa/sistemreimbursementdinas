<?php

/**
 * includes/notifications.php
 *
 * Sistem notifikasi in-app untuk Reimbursement Perjalanan Dinas.
 *
 * Fungsi yang tersedia:
 *   - createNotification()  : Buat notifikasi baru untuk seorang user
 *   - getUnreadCount()      : Hitung notifikasi yang belum dibaca
 *   - getNotifications()    : Ambil daftar notifikasi terbaru milik user
 *   - markAsRead()          : Tandai satu notifikasi sebagai sudah dibaca
 *   - markAllAsRead()       : Tandai semua notifikasi belum dibaca milik user menjadi sudah dibaca
 *
 * Requirements: 8.1, 8.2, 8.3, 8.7
 */

require_once __DIR__ . '/../config/database.php';

// ---------------------------------------------------------------------------
// createNotification
// ---------------------------------------------------------------------------

/**
 * Membuat satu record notifikasi in-app.
 *
 * Dipanggil setiap kali status pengajuan berubah (Requirement 8.1).
 * Notifikasi ditujukan kepada pengguna yang relevan dengan alur status
 * (pegawai pengaju, atasan, finance, atau kasir).
 *
 * @param int    $userId   ID penerima notifikasi.
 * @param int    $reimbId  ID pengajuan yang terkait.
 * @param string $title    Judul singkat notifikasi.
 * @param string $msg      Isi pesan notifikasi.
 *
 * @return void
 * @throws PDOException Jika query INSERT gagal.
 */
function createNotification(int $userId, int $reimbId, string $title, string $msg): void
{
    $pdo = getDB();

    $sql = 'INSERT INTO notifications (user_id, reimbursement_id, title, message, is_read, created_at)
            VALUES (:user_id, :reimbursement_id, :title, :message, 0, NOW())';

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':user_id'          => $userId,
        ':reimbursement_id' => $reimbId,
        ':title'            => $title,
        ':message'          => $msg,
    ]);
}

// ---------------------------------------------------------------------------
// getUnreadCount
// ---------------------------------------------------------------------------

/**
 * Mengembalikan jumlah notifikasi belum dibaca milik user.
 *
 * Digunakan untuk menampilkan badge/penghitung pada ikon notifikasi di
 * navigation bar setiap halaman (Requirement 8.2).
 *
 * @param int $userId ID pengguna yang sedang login.
 *
 * @return int Jumlah notifikasi dengan is_read = 0.
 */
function getUnreadCount(int $userId): int
{
    $pdo = getDB();

    $sql  = 'SELECT COUNT(*) FROM notifications WHERE user_id = :user_id AND is_read = 0';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':user_id' => $userId]);

    return (int) $stmt->fetchColumn();
}

// ---------------------------------------------------------------------------
// getNotifications
// ---------------------------------------------------------------------------

/**
 * Mengambil daftar notifikasi terbaru milik user, diurutkan dari yang paling baru.
 *
 * Mengembalikan maksimal $limit notifikasi (default 10) untuk ditampilkan
 * pada panel dropdown notifikasi di navigation bar (Requirement 8.2, 8.3).
 *
 * @param int $userId ID pengguna yang sedang login.
 * @param int $limit  Jumlah maksimal notifikasi yang diambil (default 10).
 *
 * @return array<int, array{
 *   id: int,
 *   user_id: int,
 *   reimbursement_id: int,
 *   title: string,
 *   message: string,
 *   is_read: int,
 *   created_at: string
 * }> Array asosiatif berisi baris notifikasi.
 */
function getNotifications(int $userId, int $limit = 10): array
{
    $pdo = getDB();

    // LIMIT tidak bisa dipakai sebagai named placeholder pada beberapa driver;
    // gunakan cast integer untuk mencegah SQL injection sambil mempertahankan
    // native prepared statement untuk parameter lainnya.
    $limit = max(1, $limit);

    $sql  = 'SELECT id, user_id, reimbursement_id, title, message, is_read, created_at
             FROM   notifications
             WHERE  user_id = :user_id
             ORDER  BY created_at DESC
             LIMIT  ' . $limit;

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':user_id' => $userId]);

    return $stmt->fetchAll();
}

// ---------------------------------------------------------------------------
// markAsRead
// ---------------------------------------------------------------------------

/**
 * Menandai satu notifikasi sebagai sudah dibaca.
 *
 * Validasi kepemilikan (WHERE id = ? AND user_id = ?) diterapkan untuk
 * mencegah user menandai notifikasi milik user lain (Requirement 8.3).
 *
 * @param int $notifId ID notifikasi yang akan ditandai.
 * @param int $userId  ID pengguna yang sedang login (validasi kepemilikan).
 *
 * @return void
 * @throws PDOException Jika query UPDATE gagal.
 */
function markAsRead(int $notifId, int $userId): void
{
    $pdo = getDB();

    $sql  = 'UPDATE notifications
             SET    is_read = 1
             WHERE  id      = :id
               AND  user_id = :user_id';

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':id'      => $notifId,
        ':user_id' => $userId,
    ]);
    // Jika rowCount() = 0 berarti notif tidak ditemukan atau bukan milik user ini;
    // tidak perlu melempar exception karena kondisi ini tidak berbahaya (idempoten).
}

// ---------------------------------------------------------------------------
// markAllAsRead
// ---------------------------------------------------------------------------

/**
 * Menandai semua notifikasi belum dibaca milik user menjadi sudah dibaca.
 *
 * Dipanggil ketika pengguna menekan tombol "Tandai Semua Dibaca" pada panel
 * notifikasi, dan penghitung harus diperbarui menjadi nol (Requirement 8.7).
 *
 * @param int $userId ID pengguna yang sedang login.
 *
 * @return void
 * @throws PDOException Jika query UPDATE gagal.
 */
function markAllAsRead(int $userId): void
{
    $pdo = getDB();

    $sql  = 'UPDATE notifications
             SET    is_read = 1
             WHERE  user_id = :user_id
               AND  is_read = 0';

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':user_id' => $userId]);
}
