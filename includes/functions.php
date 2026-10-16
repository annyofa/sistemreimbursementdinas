<?php

/**
 * includes/functions.php
 *
 * Helper bisnis umum untuk Sistem Reimbursement Perjalanan Dinas.
 *
 * Requirements: 1.1, 2.2, 2.3, 2.7, 2.8, 3.3, 3.6, 4.4, 5.5, 5.7, 6.2, 6.4, 6.5, 9.7
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/notifications.php';

// ---------------------------------------------------------------------------
// Exception
// ---------------------------------------------------------------------------

/**
 * Dilempar saat transisi status tidak valid sesuai VALID_TRANSITIONS.
 */
class InvalidStatusTransitionException extends \RuntimeException {}

// ---------------------------------------------------------------------------
// Konstanta
// ---------------------------------------------------------------------------

/**
 * Peta transisi status yang diizinkan.
 * Key   = status saat ini
 * Value = array status berikutnya yang valid
 */
const VALID_TRANSITIONS = [
    'draft'          => ['diajukan'],
    'diajukan'       => ['review_atasan'],
    'review_atasan'  => ['review_finance', 'perlu_revisi', 'ditolak'],
    'review_finance' => ['disetujui', 'perlu_revisi', 'ditolak'],
    'perlu_revisi'   => ['diajukan', 'review_finance'],
    'disetujui'      => ['dibayar'],
];

// ---------------------------------------------------------------------------
// 1. generateReimbNo(): string
// ---------------------------------------------------------------------------

/**
 * Menghasilkan nomor pengajuan unik dengan format RMB-{YYYY}-{000001}.
 *
 * Angka urut di-increment berdasarkan COUNT pengajuan pada tahun berjalan.
 * Contoh: RMB-2025-000001, RMB-2025-000002, …
 *
 * @return string Nomor pengajuan yang belum digunakan.
 */
function generateReimbNo(): string
{
    $db   = getDB();
    $year = (int) date('Y');

    $stmt = $db->prepare(
        "SELECT COUNT(*) FROM reimbursements WHERE YEAR(created_at) = :year"
    );
    $stmt->execute([':year' => $year]);
    $count = (int) $stmt->fetchColumn();

    $seq = $count + 1;

    return sprintf('RMB-%d-%06d', $year, $seq);
}

// ---------------------------------------------------------------------------
// 2. formatRupiah(float $n): string
// ---------------------------------------------------------------------------

/**
 * Memformat angka menjadi string mata uang Rupiah.
 *
 * Contoh: 1234000 → "Rp 1.234.000"
 *
 * @param float $n Nilai numerik.
 * @return string  Representasi Rupiah tanpa desimal.
 */
function formatRupiah(float $n): string
{
    return 'Rp ' . number_format($n, 0, ',', '.');
}

// ---------------------------------------------------------------------------
// 3. getStatusLabel(string $status): string
// ---------------------------------------------------------------------------

/**
 * Mengembalikan HTML badge Bootstrap untuk status pengajuan.
 *
 * @param string $status Nilai status dari kolom `reimbursements.status`.
 * @return string HTML <span> dengan class badge Bootstrap.
 */
function getStatusLabel(string $status): string
{
    $map = [
        'draft'          => ['label' => 'Draft',          'class' => 'bg-secondary'],
        'diajukan'       => ['label' => 'Diajukan',       'class' => 'bg-primary'],
        'review_atasan'  => ['label' => 'Review Atasan',  'class' => 'bg-info text-dark'],
        'review_finance' => ['label' => 'Review Finance', 'class' => 'bg-warning text-dark'],
        'perlu_revisi'   => ['label' => 'Perlu Revisi',   'class' => 'bg-warning text-dark'],
        'disetujui'      => ['label' => 'Disetujui',      'class' => 'bg-success'],
        'dibayar'        => ['label' => 'Dibayar',        'class' => 'bg-success'],
        'ditolak'        => ['label' => 'Ditolak',        'class' => 'bg-danger'],
    ];

    if (!isset($map[$status])) {
        return '<span class="badge bg-secondary">' . htmlspecialchars($status, ENT_QUOTES, 'UTF-8') . '</span>';
    }

    $entry = $map[$status];
    return '<span class="badge ' . $entry['class'] . '">' . $entry['label'] . '</span>';
}

// ---------------------------------------------------------------------------
// 4. getStatusHistory(int $reimbId): array
// ---------------------------------------------------------------------------

/**
 * Mengambil riwayat perubahan status sebuah pengajuan, diurutkan dari terlama.
 *
 * Menggabungkan tabel `status_history` dengan `users` untuk mendapatkan nama
 * aktor yang melakukan perubahan.
 *
 * @param int $reimbId ID pengajuan reimbursement.
 * @return array Array asosiatif berisi kolom: id, reimbursement_id, old_status,
 *               new_status, changed_at, note, actor_id, actor_name.
 */
function getStatusHistory(int $reimbId): array
{
    $db   = getDB();
    $stmt = $db->prepare(
        "SELECT
            sh.id,
            sh.reimbursement_id,
            sh.old_status,
            sh.new_status,
            sh.changed_at,
            sh.note,
            sh.changed_by  AS actor_id,
            u.name         AS actor_name
         FROM status_history sh
         LEFT JOIN users u ON u.id = sh.changed_by
         WHERE sh.reimbursement_id = :reimb_id
         ORDER BY sh.changed_at ASC"
    );
    $stmt->execute([':reimb_id' => $reimbId]);
    return $stmt->fetchAll();
}

// ---------------------------------------------------------------------------
// 5. canEdit(array $reimbursement): bool
// ---------------------------------------------------------------------------

/**
 * Menentukan apakah sebuah pengajuan masih dapat diedit.
 *
 * Pengeditan hanya diizinkan pada status 'draft' atau 'perlu_revisi'.
 *
 * @param array $reimbursement Baris dari tabel `reimbursements` (harus ada key 'status').
 * @return bool true jika bisa diedit, false jika tidak.
 */
function canEdit(array $reimbursement): bool
{
    return in_array($reimbursement['status'], ['draft', 'perlu_revisi'], true);
}

// ---------------------------------------------------------------------------
// 6. getSubordinates(int $supervisorId): array
// ---------------------------------------------------------------------------

/**
 * Mengambil daftar user yang berada di bawah supervisor tertentu.
 *
 * @param int $supervisorId ID supervisor / atasan.
 * @return array Array user (id, name, email, role, …) yang memiliki
 *               supervisor_id = $supervisorId dan aktif.
 */
function getSubordinates(int $supervisorId): array
{
    $db   = getDB();
    $stmt = $db->prepare(
        "SELECT id, name, email, role, department_id, is_active
         FROM users
         WHERE supervisor_id = :supervisor_id
           AND is_active = 1"
    );
    $stmt->execute([':supervisor_id' => $supervisorId]);
    return $stmt->fetchAll();
}

// ---------------------------------------------------------------------------
// 7. updateReimbursementStatus(…): bool
// ---------------------------------------------------------------------------

/**
 * Memperbarui status pengajuan reimbursement secara atomik.
 *
 * Urutan operasi:
 *  1. SELECT status saat ini; lempar RuntimeException jika tidak ditemukan.
 *  2. Validasi transisi via VALID_TRANSITIONS; lempar InvalidStatusTransitionException jika gagal.
 *  3. SET @actor_id dan @status_note sebagai MySQL user-variable pada koneksi PDO yang sama
 *     (dibaca oleh trigger trg_reimb_status_au untuk mengisi status_history).
 *  4. UPDATE reimbursements SET status = ? WHERE id = ?
 *  5. Panggil logAudit() — catat before/after status beserta note.
 *  6. Kirim notifikasi ke pihak yang relevan sesuai newStatus.
 *
 * @param int    $reimbId   ID pengajuan.
 * @param string $newStatus Status tujuan.
 * @param int    $actorId   ID user yang melakukan aksi.
 * @param string $note      Catatan opsional (diteruskan ke trigger via @status_note).
 * @return bool  true jika berhasil.
 * @throws InvalidStatusTransitionException Jika transisi tidak valid.
 * @throws \RuntimeException               Jika pengajuan tidak ditemukan.
 * @throws \PDOException                   Pada kegagalan query database.
 */
function updateReimbursementStatus(
    int    $reimbId,
    string $newStatus,
    int    $actorId,
    string $note = ''
): bool {
    $db = getDB();

    // 1. Ambil status saat ini dan user_id pengaju
    $stmt = $db->prepare(
        "SELECT r.status, r.user_id, u.supervisor_id
         FROM reimbursements r
         JOIN users u ON u.id = r.user_id
         WHERE r.id = :id"
    );
    $stmt->execute([':id' => $reimbId]);
    $row = $stmt->fetch();

    if ($row === false) {
        throw new \RuntimeException("Pengajuan dengan id={$reimbId} tidak ditemukan.");
    }

    $currentStatus = $row['status'];
    $pegawaiId     = (int) $row['user_id'];
    $supervisorId  = $row['supervisor_id'] ? (int) $row['supervisor_id'] : null;

    // 2. Validasi transisi
    $allowed = VALID_TRANSITIONS[$currentStatus] ?? [];
    if (!in_array($newStatus, $allowed, true)) {
        throw new InvalidStatusTransitionException(
            "Transisi status tidak valid: '{$currentStatus}' → '{$newStatus}'."
        );
    }

    // 3. Set MySQL user-variables yang dibaca oleh trigger trg_reimb_status_au
    $stmtActor = $db->prepare("SET @actor_id = ?");
    $stmtActor->execute([$actorId]);

    $stmtNote = $db->prepare("SET @status_note = ?");
    $stmtNote->execute([$note]);

    // 4. UPDATE status pengajuan
    $stmtUpdate = $db->prepare(
        "UPDATE reimbursements SET status = :status WHERE id = :id"
    );
    $stmtUpdate->execute([':status' => $newStatus, ':id' => $reimbId]);

    // 5. Audit trail
    logAudit(
        $actorId,
        'UPDATE_STATUS',
        'reimbursements',
        $reimbId,
        [
            'before' => ['status' => $currentStatus],
            'after'  => ['status' => $newStatus],
            'note'   => $note,
        ]
    );

    // 6. Notifikasi berdasarkan status baru
    _sendStatusNotifications($db, $reimbId, $newStatus, $pegawaiId, $supervisorId);

    return true;
}

/**
 * Helper internal: kirim notifikasi ke pihak yang relevan sesuai status baru.
 *
 * @param \PDO     $db
 * @param int      $reimbId
 * @param string   $newStatus
 * @param int      $pegawaiId    ID pegawai pengaju.
 * @param int|null $supervisorId ID atasan langsung pegawai (jika ada).
 * @return void
 */
function _sendStatusNotifications(
    \PDO   $db,
    int    $reimbId,
    string $newStatus,
    int    $pegawaiId,
    ?int   $supervisorId
): void {
    switch ($newStatus) {
        case 'diajukan':
            // Notifikasi ke atasan langsung pegawai
            if ($supervisorId !== null) {
                createNotification(
                    $supervisorId,
                    $reimbId,
                    'Pengajuan Baru Menunggu Review',
                    "Terdapat pengajuan reimbursement baru yang membutuhkan review Anda."
                );
            }
            break;

        case 'review_finance':
            // Notifikasi ke semua user dengan role='finance'
            $stmt = $db->prepare(
                "SELECT id FROM users WHERE role = 'finance' AND is_active = 1"
            );
            $stmt->execute();
            foreach ($stmt->fetchAll() as $financeUser) {
                createNotification(
                    (int) $financeUser['id'],
                    $reimbId,
                    'Pengajuan Siap Diverifikasi Finance',
                    "Terdapat pengajuan reimbursement yang perlu diverifikasi oleh tim finance."
                );
            }
            break;

        case 'disetujui':
            // Notifikasi ke semua user dengan role='kasir'
            $stmt = $db->prepare(
                "SELECT id FROM users WHERE role = 'kasir' AND is_active = 1"
            );
            $stmt->execute();
            foreach ($stmt->fetchAll() as $kasirUser) {
                createNotification(
                    (int) $kasirUser['id'],
                    $reimbId,
                    'Pengajuan Disetujui — Siap Dibayar',
                    "Terdapat pengajuan reimbursement yang telah disetujui dan siap untuk pembayaran."
                );
            }
            break;

        case 'perlu_revisi':
            createNotification(
                $pegawaiId,
                $reimbId,
                'Pengajuan Membutuhkan Revisi',
                "Pengajuan reimbursement Anda membutuhkan revisi sebelum dapat dilanjutkan."
            );
            break;

        case 'ditolak':
            createNotification(
                $pegawaiId,
                $reimbId,
                'Pengajuan Ditolak',
                "Pengajuan reimbursement Anda telah ditolak."
            );
            break;

        case 'dibayar':
            createNotification(
                $pegawaiId,
                $reimbId,
                'Reimbursement Telah Dibayar',
                "Pembayaran reimbursement Anda telah diproses."
            );
            break;
    }
}

// ---------------------------------------------------------------------------
// 8. canFinanceApprove(int $reimbId): bool
// ---------------------------------------------------------------------------

/**
 * Memeriksa apakah pengajuan sudah boleh disetujui oleh finance.
 *
 * Syarat: pengajuan memiliki setidaknya satu item biaya DAN
 * SEMUA item sudah diverifikasi (verification_status ≠ 'belum').
 *
 * @param int $reimbId ID pengajuan.
 * @return bool true jika semua item sudah diverifikasi (dan minimal 1 item ada).
 */
function canFinanceApprove(int $reimbId): bool
{
    $db = getDB();

    // Pastikan ada minimal 1 item
    $stmtTotal = $db->prepare(
        "SELECT COUNT(*) FROM reimbursement_items WHERE reimbursement_id = :reimb_id"
    );
    $stmtTotal->execute([':reimb_id' => $reimbId]);
    $totalCount = (int) $stmtTotal->fetchColumn();

    if ($totalCount === 0) {
        return false;
    }

    // Hitung item yang masih berstatus 'belum'
    $stmtUnverified = $db->prepare(
        "SELECT COUNT(*) FROM reimbursement_items
         WHERE reimbursement_id = :reimb_id
           AND verification_status = 'belum'"
    );
    $stmtUnverified->execute([':reimb_id' => $reimbId]);
    $unverifiedCount = (int) $stmtUnverified->fetchColumn();

    return $unverifiedCount === 0;
}

// ---------------------------------------------------------------------------
// 9. isValidPaymentAmount($amount): bool
// ---------------------------------------------------------------------------

/**
 * Memvalidasi bahwa nilai pembayaran adalah numerik dan lebih dari nol.
 *
 * @param mixed $amount Nilai yang akan divalidasi.
 * @return bool true jika valid (numerik dan > 0).
 */
function isValidPaymentAmount($amount): bool
{
    return is_numeric($amount) && (float) $amount > 0;
}

// ---------------------------------------------------------------------------
// 10. isValidUploadFile(string $filePath, string $mimeType): bool
// ---------------------------------------------------------------------------

/**
 * Memvalidasi file upload berdasarkan ekstensi DAN MIME type via finfo.
 *
 * Ekstensi yang diizinkan : jpg, jpeg, png, pdf
 * MIME type yang diizinkan: image/jpeg, image/png, application/pdf
 *
 * Validasi dilakukan terhadap bytes aktual file (finfo), bukan hanya header
 * yang diklaim klien.
 *
 * @param string $filePath Path lengkap ke file di server (misal dari $_FILES['tmp_name']).
 * @param string $mimeType MIME type yang diklaim klien (tidak digunakan sebagai sumber validasi;
 *                         validasi tetap melalui finfo terhadap $filePath).
 * @return bool true jika ekstensi dan MIME type keduanya valid dan sesuai.
 */
function isValidUploadFile(string $filePath, string $mimeType): bool
{
    if (!file_exists($filePath)) {
        return false;
    }

    $allowedExtensions = ['jpg', 'jpeg', 'png', 'pdf'];
    $allowedMimeByExt  = [
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'pdf'  => 'application/pdf',
    ];

    // Validasi ekstensi dari nama path yang diberikan
    $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExtensions, true)) {
        return false;
    }

    // Validasi MIME via finfo (membaca bytes aktual file)
    $finfo        = finfo_open(FILEINFO_MIME_TYPE);
    $detectedMime = finfo_file($finfo, $filePath);
    finfo_close($finfo);

    if ($detectedMime === false) {
        return false;
    }

    // Pastikan MIME yang terdeteksi sesuai dengan ekstensi yang diklaim
    return $detectedMime === ($allowedMimeByExt[$ext] ?? '');
}

// ---------------------------------------------------------------------------
// 11. canDeactivateUser(int $userId): bool
// ---------------------------------------------------------------------------

/**
 * Memeriksa apakah akun user boleh dinonaktifkan.
 *
 * Tidak boleh dinonaktifkan jika masih ada pengajuan aktif
 * (status selain 'dibayar' atau 'ditolak').
 *
 * @param int $userId ID user yang hendak dinonaktifkan.
 * @return bool true jika user boleh dinonaktifkan, false jika tidak.
 */
function canDeactivateUser(int $userId): bool
{
    $db   = getDB();
    $stmt = $db->prepare(
        "SELECT COUNT(*) FROM reimbursements
         WHERE user_id = :user_id
           AND status NOT IN ('dibayar', 'ditolak')"
    );
    $stmt->execute([':user_id' => $userId]);
    $activeCount = (int) $stmt->fetchColumn();

    return $activeCount === 0;
}

// ---------------------------------------------------------------------------
// 12. determineOcrStatus(float $confidence): string
// ---------------------------------------------------------------------------

/**
 * Menentukan status OCR berdasarkan nilai confidence Tesseract.
 *
 * - confidence < 70  → 'buram'
 * - confidence >= 70 → 'sukses'
 *
 * @param float $confidence Nilai confidence dari Tesseract (0–100).
 * @return string 'buram' atau 'sukses'.
 */
function determineOcrStatus(float $confidence): string
{
    return $confidence >= 70.0 ? 'sukses' : 'buram';
}

// ---------------------------------------------------------------------------
// 13. csrfField(): string
// ---------------------------------------------------------------------------

/**
 * Menghasilkan hidden input field HTML berisi CSRF token.
 *
 * Jika token belum ada di sesi, token baru dibuat dengan random_bytes()
 * dan disimpan ke $_SESSION['csrf_token'].
 *
 * @return string HTML: <input type="hidden" name="csrf_token" value="…">
 */
function csrfField(): string
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    $token = htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8');
    return '<input type="hidden" name="csrf_token" value="' . $token . '">';
}

// ---------------------------------------------------------------------------
// 14. verifyCsrf(): void
// ---------------------------------------------------------------------------

/**
 * Memverifikasi CSRF token dari POST request.
 *
 * Membandingkan $_POST['csrf_token'] dengan $_SESSION['csrf_token'] menggunakan
 * hash_equals() untuk mencegah timing attack.
 * Jika tidak cocok: kirim HTTP 403 dan hentikan eksekusi.
 *
 * @return void
 */
function verifyCsrf(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $postToken    = $_POST['csrf_token'] ?? '';
    $sessionToken = $_SESSION['csrf_token'] ?? '';

    if (
        $sessionToken === '' ||
        !hash_equals($sessionToken, $postToken)
    ) {
        http_response_code(403);
        die('Token CSRF tidak valid');
    }
}
