<?php
/**
 * public/login.php
 *
 * Halaman login Sistem Reimbursement Perjalanan Dinas.
 *
 * Alur GET  : Tampilkan form login (email + password + CSRF token).
 *             Jika ?timeout=1, tampilkan pesan sesi kedaluwarsa.
 * Alur POST : Validasi CSRF → panggil login() → catat audit → redirect dashboard.
 *             Jika gagal, set flash error dan redirect kembali ke GET.
 *
 * Requirements: 1.1, 1.2
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/audit.php';

// Jika sudah login, langsung ke dashboard
if (isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/dashboard.php');
    exit;
}

// ── POST: Proses Login ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // 1. Validasi CSRF token (kirim HTTP 403 + die jika tidak cocok)
    verifyCsrf();

    $email    = trim($_POST['email']    ?? '');
    $password = trim($_POST['password'] ?? '');

    // 2. Coba autentikasi
    if (login($email, $password)) {
        // 3. Login berhasil — catat audit log sebelum redirect
        $userId = $_SESSION['user_id'];
        logAudit(
            $userId,
            'LOGIN',
            'users',
            $userId,
            ['ip' => $_SERVER['REMOTE_ADDR'] ?? '']
        );

        // 4. Redirect ke dashboard
        header('Location: ' . BASE_URL . '/dashboard.php');
        exit;
    }

    // 5. Login gagal — set flash error, redirect kembali ke GET
    $_SESSION['flash'] = [
        'type'    => 'danger',
        'message' => 'Email atau password salah.',
    ];
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

// ── GET: Tampilkan Form ───────────────────────────────────────────────────────

// Ambil dan hapus flash message (jika ada)
$flash   = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// Cek parameter timeout
$timeout = isset($_GET['timeout']) && $_GET['timeout'] === '1';

$pageTitle = 'Login';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>Login — Reimbursement Perjalanan Dinas</title>

    <!-- Bootstrap 5.3 CSS -->
    <link
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH"
        crossorigin="anonymous"
    >
    <!-- Bootstrap Icons -->
    <link
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
    >

    <style>
        body {
            background-color: #f0f2f5;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .login-card {
            width: 100%;
            max-width: 420px;
            border-radius: 0.75rem;
            box-shadow: 0 4px 24px rgba(0, 0, 0, 0.08);
        }
        .login-logo {
            font-size: 2.5rem;
            color: #0d6efd;
        }
    </style>
</head>
<body>

<div class="container px-3">
    <div class="card login-card mx-auto">
        <div class="card-body p-4 p-sm-5">

            <!-- Logo & Judul -->
            <div class="text-center mb-4">
                <div class="login-logo mb-2">
                    <i class="bi bi-receipt-cutoff"></i>
                </div>
                <h1 class="h4 fw-bold mb-1">Reimbursement Perjalanan Dinas</h1>
                <p class="text-muted small">Masuk ke akun Anda untuk melanjutkan</p>
            </div>

            <!-- Pesan Timeout Sesi -->
            <?php if ($timeout): ?>
                <div class="alert alert-warning d-flex align-items-center gap-2" role="alert">
                    <i class="bi bi-clock-history flex-shrink-0"></i>
                    <span>Sesi Anda telah berakhir karena tidak aktif. Silakan masuk kembali.</span>
                </div>
            <?php endif; ?>

            <!-- Flash Message (error login) -->
            <?php if ($flash !== null): ?>
                <div class="alert alert-<?= htmlspecialchars($flash['type'], ENT_QUOTES, 'UTF-8') ?> d-flex align-items-center gap-2" role="alert">
                    <i class="bi bi-exclamation-triangle-fill flex-shrink-0"></i>
                    <span><?= htmlspecialchars($flash['message'], ENT_QUOTES, 'UTF-8') ?></span>
                </div>
            <?php endif; ?>

            <!-- Form Login -->
            <form method="POST" action="<?= htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8') ?>/login.php" novalidate>

                <?= csrfField() ?>

                <!-- Email -->
                <div class="mb-3">
                    <label for="email" class="form-label fw-medium">Email</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                        <input
                            type="email"
                            id="email"
                            name="email"
                            class="form-control"
                            placeholder="nama@perusahaan.com"
                            autocomplete="username"
                            required
                            autofocus
                        >
                    </div>
                </div>

                <!-- Password -->
                <div class="mb-4">
                    <label for="password" class="form-label fw-medium">Password</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-lock"></i></span>
                        <input
                            type="password"
                            id="password"
                            name="password"
                            class="form-control"
                            placeholder="••••••••"
                            autocomplete="current-password"
                            required
                        >
                        <button
                            type="button"
                            class="btn btn-outline-secondary"
                            id="togglePassword"
                            aria-label="Tampilkan/sembunyikan password"
                        >
                            <i class="bi bi-eye" id="toggleIcon"></i>
                        </button>
                    </div>
                </div>

                <!-- Tombol Submit -->
                <div class="d-grid">
                    <button type="submit" class="btn btn-primary btn-lg">
                        <i class="bi bi-box-arrow-in-right me-1"></i> Masuk
                    </button>
                </div>

            </form>

        </div><!-- /.card-body -->
    </div><!-- /.card -->

    <p class="text-center text-muted small mt-3">
        &copy; <?= date('Y') ?> Reimbursement Perjalanan Dinas
    </p>
</div><!-- /.container -->

<!-- Bootstrap 5.3 JS Bundle -->
<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
    integrity="sha384-YvpcrYf0tY3lHB60NNkmXc4s9bIOgUxi8T/jzmFnrQ8PBn8DaAEtUo0/UW2PKKCt"
    crossorigin="anonymous"
></script>

<script>
    // Toggle visibilitas password
    (function () {
        const btn  = document.getElementById('togglePassword');
        const inp  = document.getElementById('password');
        const icon = document.getElementById('toggleIcon');

        if (btn && inp && icon) {
            btn.addEventListener('click', function () {
                const isPassword = inp.type === 'password';
                inp.type         = isPassword ? 'text' : 'password';
                icon.className   = isPassword ? 'bi bi-eye-slash' : 'bi bi-eye';
                this.setAttribute('aria-pressed', String(isPassword));
            });
        }
    })();
</script>

</body>
</html>
