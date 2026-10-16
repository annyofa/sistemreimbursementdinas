<?php

/**
 * includes/auth.php
 *
 * Autentikasi dan otorisasi berbasis sesi untuk Sistem Reimbursement Perjalanan Dinas.
 *
 * Fungsi yang tersedia:
 *   - login(string $email, string $password): bool
 *   - logout(): void
 *   - requireAuth(): void
 *   - requireRole(array $roles): void
 *   - isSessionExpired(): bool
 *   - getCurrentUser(): array
 *
 * Setiap halaman yang memerlukan autentikasi harus memanggil:
 *   require_once __DIR__ . '/../includes/auth.php';
 *   requireAuth();
 *   requireRole(['role_yang_diperlukan']);
 */

require_once __DIR__ . '/../config/database.php';

// ── Session Hardening ─────────────────────────────────────────────────────────
// Harus dipanggil SEBELUM session_start(). Abaikan jika sesi sudah aktif
// (session_status() == PHP_SESSION_ACTIVE) agar tidak memicu notice.
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.use_strict_mode', 1);
    session_start();
}

// ─────────────────────────────────────────────────────────────────────────────

/**
 * Autentikasi pengguna berdasarkan email dan password.
 *
 * Alur:
 *  1. SELECT user WHERE email = ? AND is_active = 1
 *  2. password_verify($password, $row['password_hash'])
 *  3. session_regenerate_id(true) untuk mencegah session fixation
 *  4. Isi $_SESSION dengan data user
 *
 * @param string $email    Email pengguna
 * @param string $password Password plain-text
 * @return bool            true jika autentikasi berhasil, false jika gagal
 */
function login(string $email, string $password): bool
{
    $db  = getDB();
    $sql = 'SELECT id, name, email, password_hash, role
              FROM users
             WHERE email = :email
               AND is_active = 1
             LIMIT 1';

    $stmt = $db->prepare($sql);
    $stmt->execute([':email' => $email]);
    $user = $stmt->fetch();

    // Pengguna tidak ditemukan atau password salah → tolak login
    if ($user === false || !password_verify($password, $user['password_hash'])) {
        return false;
    }

    // Regenerasi ID sesi untuk mencegah session fixation
    session_regenerate_id(true);

    // Simpan data pengguna ke sesi
    $_SESSION['user_id']       = (int) $user['id'];
    $_SESSION['name']          = $user['name'];
    $_SESSION['role']          = $user['role'];
    $_SESSION['email']         = $user['email'];
    $_SESSION['last_activity'] = time();

    return true;
}

// ─────────────────────────────────────────────────────────────────────────────

/**
 * Hancurkan sesi aktif dan redirect ke halaman login.
 *
 * @return void  Fungsi ini tidak pernah kembali (exit setelah redirect).
 */
function logout(): void
{
    session_unset();
    session_destroy();

    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────

/**
 * Cek apakah sesi sudah kedaluwarsa (tidak aktif > 1800 detik / 30 menit).
 *
 * Jika kedaluwarsa:
 *  - Hancurkan sesi
 *  - Redirect ke login dengan parameter ?timeout=1
 *
 * Jika belum kedaluwarsa:
 *  - Perbarui $_SESSION['last_activity'] ke waktu sekarang
 *
 * @return bool  true jika sesi sudah kedaluwarsa (dan sudah di-redirect),
 *               false jika sesi masih valid.
 *               Catatan: Dalam praktiknya, nilai true tidak pernah dikembalikan
 *               ke pemanggil karena fungsi sudah memanggil exit saat expired.
 */
function isSessionExpired(): bool
{
    if (!isset($_SESSION['last_activity'])) {
        // Belum ada timestamp → anggap tidak kedaluwarsa; set sekarang
        $_SESSION['last_activity'] = time();
        return false;
    }

    if ((time() - $_SESSION['last_activity']) > 1800) {
        // Sesi sudah kedaluwarsa → hancurkan dan redirect
        session_unset();
        session_destroy();

        header('Location: ' . BASE_URL . '/login.php?timeout=1');
        exit;
    }

    // Sesi masih valid → perbarui timestamp
    $_SESSION['last_activity'] = time();
    return false;
}

// ─────────────────────────────────────────────────────────────────────────────

/**
 * Pastikan pengguna sudah login. Redirect ke login jika belum.
 *
 * Juga memeriksa session timeout: jika sesi sudah kedaluwarsa, fungsi ini
 * akan menghancurkan sesi dan redirect ke login?timeout=1 (via isSessionExpired).
 *
 * @return void  Fungsi ini tidak pernah kembali jika pengguna tidak terotentikasi.
 */
function requireAuth(): void
{
    if (!isset($_SESSION['user_id'])) {
        header('Location: ' . BASE_URL . '/login.php');
        exit;
    }

    // Cek session timeout (akan redirect+exit sendiri jika expired)
    isSessionExpired();
}

// ─────────────────────────────────────────────────────────────────────────────

/**
 * Pastikan pengguna yang sedang login memiliki salah satu role yang diizinkan.
 * Jika tidak sesuai, tampilkan halaman error HTTP 403.
 *
 * Fungsi ini harus dipanggil SETELAH requireAuth().
 *
 * @param array $roles  Daftar role yang diizinkan, misalnya ['pegawai', 'admin']
 * @return void         Fungsi tidak kembali jika akses ditolak.
 */
function requireRole(array $roles): void
{
    $userRole = $_SESSION['role'] ?? '';

    if (!in_array($userRole, $roles, true)) {
        http_response_code(403);
        // Tampilkan halaman error 403 yang ramah pengguna
        ?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>403 — Akses Ditolak</title>
    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <style>
        body { background-color: #f8f9fa; }
        .error-container {
            max-width: 480px;
            margin: 10vh auto;
            text-align: center;
        }
        .error-icon { font-size: 5rem; color: #dc3545; }
    </style>
</head>
<body>
    <div class="error-container">
        <div class="error-icon mb-3">🚫</div>
        <h1 class="h2 fw-bold text-danger">403 — Akses Ditolak</h1>
        <p class="text-muted mt-3">
            Anda tidak memiliki izin untuk mengakses halaman ini.<br>
            Silakan hubungi administrator jika Anda merasa ini adalah kesalahan.
        </p>
        <a href="<?= htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8') ?>/dashboard.php"
           class="btn btn-primary mt-3">
            Kembali ke Dashboard
        </a>
    </div>
</body>
</html>
        <?php
        exit;
    }
}

// ─────────────────────────────────────────────────────────────────────────────

/**
 * Kembalikan data pengguna yang sedang login dari sesi.
 *
 * @return array  Array asosiatif dengan kunci: user_id, name, role, email.
 *                Array kosong jika tidak ada sesi aktif.
 */
function getCurrentUser(): array
{
    if (!isset($_SESSION['user_id'])) {
        return [];
    }

    return [
        'user_id' => $_SESSION['user_id'],
        'name'    => $_SESSION['name']  ?? '',
        'role'    => $_SESSION['role']  ?? '',
        'email'   => $_SESSION['email'] ?? '',
    ];
}
