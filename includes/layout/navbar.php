<?php
/**
 * includes/layout/navbar.php
 *
 * Navbar responsif Bootstrap 5 yang ditampilkan di setiap halaman setelah header.php.
 *
 * - Meng-inject BASE_URL ke JavaScript sebagai konstanta global
 * - Menampilkan dan menghapus flash message dari $_SESSION['flash']
 *   (tipe yang didukung: success, danger, warning, info)
 * - Menampilkan badge notifikasi belum dibaca (#notif-count)
 * - Polling notifikasi setiap 30 detik via Fetch API
 * - Link navigasi berbeda per role
 *
 * Requirements: 8.2
 */

// Pastikan dependency sudah tersedia
if (!defined('BASE_URL')) {
    require_once __DIR__ . '/../../config/database.php';
}

if (!function_exists('getUnreadCount')) {
    require_once __DIR__ . '/../notifications.php';
}

if (!function_exists('requireAuth')) {
    require_once __DIR__ . '/../auth.php';
}

// Pastikan sesi aktif
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Data pengguna dari sesi
$currentUserId   = (int) ($_SESSION['user_id'] ?? 0);
$currentUserName = htmlspecialchars($_SESSION['name'] ?? '', ENT_QUOTES, 'UTF-8');
$currentRole     = $_SESSION['role'] ?? '';

// Hitung notifikasi belum dibaca
$unreadCount = ($currentUserId > 0) ? getUnreadCount($currentUserId) : 0;

// Ambil dan hapus flash message
$flash = null;
if (!empty($_SESSION['flash'])) {
    $flash = $_SESSION['flash']; // ['type' => 'success'|'danger'|'warning'|'info', 'message' => '...']
    unset($_SESSION['flash']);
}
?>

<!-- Inject BASE_URL ke JavaScript sebelum elemen lain membutuhkannya -->
<script>
    const BASE_URL    = '<?= BASE_URL ?>';
    const UPLOADS_URL = '<?= UPLOADS_URL ?>';
</script>

<!-- Flash message container (fixed position, muncul di pojok kanan atas) -->
<?php if ($flash): ?>
<div class="flash-container" id="flash-container" aria-live="polite">
    <div class="alert alert-<?= htmlspecialchars($flash['type'] ?? 'info', ENT_QUOTES, 'UTF-8') ?> alert-dismissible fade show shadow-sm" role="alert">
        <?php
        $flashIcons = [
            'success' => 'bi-check-circle-fill',
            'danger'  => 'bi-x-circle-fill',
            'warning' => 'bi-exclamation-triangle-fill',
            'info'    => 'bi-info-circle-fill',
        ];
        $flashType = $flash['type'] ?? 'info';
        $iconClass = $flashIcons[$flashType] ?? 'bi-info-circle-fill';
        ?>
        <i class="bi <?= htmlspecialchars($iconClass, ENT_QUOTES, 'UTF-8') ?> me-2"></i>
        <?= htmlspecialchars($flash['message'] ?? '', ENT_QUOTES, 'UTF-8') ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Tutup"></button>
    </div>
</div>
<?php endif; ?>

<!-- Navbar utama -->
<nav class="navbar navbar-expand-lg navbar-dark bg-primary shadow-sm">
    <div class="container-fluid">
        <!-- Brand -->
        <a class="navbar-brand" href="<?= BASE_URL ?>/dashboard.php">
            <i class="bi bi-briefcase-fill me-2"></i>Reimbursement
        </a>

        <!-- Tombol hamburger untuk mobile -->
        <button
            class="navbar-toggler"
            type="button"
            data-bs-toggle="collapse"
            data-bs-target="#navbarMain"
            aria-controls="navbarMain"
            aria-expanded="false"
            aria-label="Toggle navigasi"
        >
            <span class="navbar-toggler-icon"></span>
        </button>

        <!-- Menu navigasi -->
        <div class="collapse navbar-collapse" id="navbarMain">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                <?php if ($currentRole === 'pegawai'): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="<?= BASE_URL ?>/pegawai/index.php">
                            <i class="bi bi-file-earmark-text me-1"></i>Pengajuan Saya
                        </a>
                    </li>

                <?php elseif ($currentRole === 'atasan'): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="<?= BASE_URL ?>/atasan/index.php">
                            <i class="bi bi-clipboard-check me-1"></i>Review Pengajuan
                        </a>
                    </li>

                <?php elseif ($currentRole === 'finance'): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="<?= BASE_URL ?>/finance/index.php">
                            <i class="bi bi-shield-check me-1"></i>Verifikasi
                        </a>
                    </li>

                <?php elseif ($currentRole === 'kasir'): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="<?= BASE_URL ?>/kasir/index.php">
                            <i class="bi bi-cash-coin me-1"></i>Pembayaran
                        </a>
                    </li>

                <?php elseif ($currentRole === 'manajemen'): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="<?= BASE_URL ?>/manajemen/dashboard.php">
                            <i class="bi bi-speedometer2 me-1"></i>Dashboard
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?= BASE_URL ?>/manajemen/laporan.php">
                            <i class="bi bi-bar-chart-line me-1"></i>Laporan
                        </a>
                    </li>

                <?php elseif ($currentRole === 'admin'): ?>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="bi bi-gear me-1"></i>Administrasi
                        </a>
                        <ul class="dropdown-menu">
                            <li>
                                <a class="dropdown-item" href="<?= BASE_URL ?>/admin/users.php">
                                    <i class="bi bi-people me-2"></i>Users
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item" href="<?= BASE_URL ?>/admin/categories.php">
                                    <i class="bi bi-tags me-2"></i>Kategori
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item" href="<?= BASE_URL ?>/admin/departments.php">
                                    <i class="bi bi-building me-2"></i>Departemen
                                </a>
                            </li>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <a class="dropdown-item" href="<?= BASE_URL ?>/admin/reimbursements.php">
                                    <i class="bi bi-folder2-open me-2"></i>Semua Pengajuan
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item" href="<?= BASE_URL ?>/admin/audit_log.php">
                                    <i class="bi bi-journal-text me-2"></i>Audit Log
                                </a>
                            </li>
                        </ul>
                    </li>
                <?php endif; ?>
            </ul>

            <!-- Bagian kanan: notifikasi + nama user + logout -->
            <ul class="navbar-nav ms-auto align-items-center gap-1">
                <?php if ($currentUserId > 0): ?>
                <!-- Bell notifikasi (dropdown) -->
                <li class="nav-item dropdown" id="notif-dropdown-item">
                    <a
                        class="nav-link position-relative"
                        href="#"
                        id="notifDropdownToggle"
                        role="button"
                        data-bs-toggle="dropdown"
                        data-bs-auto-close="outside"
                        aria-expanded="false"
                        aria-label="Notifikasi"
                        title="Notifikasi"
                    >
                        <i class="bi bi-bell fs-5"></i>
                        <span
                            id="notif-count"
                            class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger badge-notif<?= $unreadCount === 0 ? ' d-none' : '' ?>"
                            aria-label="<?= $unreadCount ?> notifikasi belum dibaca"
                        ><?= $unreadCount ?></span>
                    </a>
                    <!-- Panel dropdown notifikasi -->
                    <div
                        class="dropdown-menu dropdown-menu-end p-0 shadow"
                        id="notifDropdownMenu"
                        aria-labelledby="notifDropdownToggle"
                        style="min-width: 340px; max-width: 400px;"
                    >
                        <!-- Header panel -->
                        <div class="d-flex justify-content-between align-items-center px-3 py-2 border-bottom bg-light rounded-top">
                            <span class="fw-semibold small">Notifikasi</span>
                            <button
                                type="button"
                                class="btn btn-link btn-sm p-0 text-secondary text-decoration-none"
                                id="markAllReadBtn"
                                title="Tandai Semua Dibaca"
                            >Tandai Semua Dibaca</button>
                        </div>
                        <!-- Daftar notifikasi (diisi via JavaScript) -->
                        <ul
                            class="list-unstyled m-0"
                            id="notifList"
                            style="max-height: 360px; overflow-y: auto;"
                            role="list"
                        >
                            <li class="text-center text-muted small py-3" id="notifListLoading">
                                <span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>
                                Memuat notifikasi&hellip;
                            </li>
                        </ul>
                    </div>
                </li>

                <!-- Nama pengguna (dropdown) -->
                <li class="nav-item dropdown">
                    <a
                        class="nav-link dropdown-toggle d-flex align-items-center gap-1"
                        href="#"
                        role="button"
                        data-bs-toggle="dropdown"
                        aria-expanded="false"
                    >
                        <i class="bi bi-person-circle fs-5"></i>
                        <span class="d-none d-md-inline"><?= $currentUserName ?></span>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li>
                            <span class="dropdown-item-text text-muted small">
                                Role: <?= htmlspecialchars(ucfirst($currentRole), ENT_QUOTES, 'UTF-8') ?>
                            </span>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <a class="dropdown-item text-danger" href="<?= BASE_URL ?>/logout.php">
                                <i class="bi bi-box-arrow-right me-2"></i>Logout
                            </a>
                        </li>
                    </ul>
                </li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</nav>

<!-- Container utama konten halaman -->
<div class="container-fluid py-4 px-4">

<script>
(function () {
    'use strict';

    // ── Util: ambil CSRF token dari meta tag ─────────────────────────────────
    function getCsrfToken() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    }

    // ── Util: perbarui badge #notif-count ────────────────────────────────────
    function setBadgeCount(count) {
        var badge = document.getElementById('notif-count');
        if (!badge) return;
        count = parseInt(count, 10) || 0;
        badge.textContent = count;
        badge.setAttribute('aria-label', count + ' notifikasi belum dibaca');
        if (count > 0) {
            badge.classList.remove('d-none');
        } else {
            badge.classList.add('d-none');
        }
    }

    // ── Sembunyikan flash message otomatis setelah 5 detik ───────────────────
    var flashContainer = document.getElementById('flash-container');
    if (flashContainer) {
        setTimeout(function () {
            var alertEl = flashContainer.querySelector('.alert');
            if (alertEl) {
                var bsAlert = bootstrap.Alert.getOrCreateInstance(alertEl);
                bsAlert.close();
            }
        }, 5000);
    }

    // ---------------------------------------------------------------------------
    // Polling badge notifikasi setiap 30 detik (Requirement 8.2)
    // ---------------------------------------------------------------------------
    function updateNotifBadge() {
        fetch(BASE_URL + '/api/notifications.php?count_only=1', {
            method:  'GET',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-Token':     getCsrfToken()
            },
            credentials: 'same-origin'
        })
        .then(function (r) { return r.ok ? r.json() : Promise.reject(r); })
        .then(function (data) { setBadgeCount(data.count); })
        .catch(function () { /* abaikan secara senyap */ });
    }

    // Polling pertama setelah 30 detik, lalu setiap 30 detik
    setInterval(updateNotifBadge, 30000);

    // ---------------------------------------------------------------------------
    // Dropdown notifikasi — muat daftar saat dropdown dibuka (Requirement 8.3)
    // ---------------------------------------------------------------------------
    var notifToggle  = document.getElementById('notifDropdownToggle');
    var notifList    = document.getElementById('notifList');
    var markAllBtn   = document.getElementById('markAllReadBtn');

    if (notifToggle && notifList) {

        // Render satu item notifikasi
        function renderNotifItem(notif) {
            var li = document.createElement('li');
            li.setAttribute('role', 'listitem');
            li.className = 'border-bottom notif-item' + (notif.is_read ? '' : ' fw-semibold bg-light');
            li.style.cursor = 'pointer';
            li.innerHTML =
                '<div class="px-3 py-2 small notif-item-inner" data-id="' + notif.id + '" data-reimb="' + notif.reimbursement_id + '">' +
                    '<div class="text-dark text-truncate">' + escapeHtml(notif.title) + '</div>' +
                    '<div class="text-muted" style="white-space:normal;word-break:break-word;">' + escapeHtml(notif.message) + '</div>' +
                    '<div class="text-muted mt-1" style="font-size:0.75rem;">' + escapeHtml(notif.created_at) + '</div>' +
                '</div>';

            li.querySelector('.notif-item-inner').addEventListener('click', function () {
                handleNotifClick(notif.id);
            });

            return li;
        }

        // Render daftar kosong
        function renderEmpty() {
            var li = document.createElement('li');
            li.className = 'text-center text-muted small py-4';
            li.textContent = 'Tidak ada notifikasi.';
            return li;
        }

        // Muat notifikasi dari API (Requirement 8.3)
        function loadNotifications() {
            // Tampilkan status loading
            notifList.innerHTML =
                '<li class="text-center text-muted small py-3">' +
                '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>' +
                'Memuat notifikasi\u2026</li>';

            fetch(BASE_URL + '/api/notifications.php', {
                method:  'GET',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-Token':     getCsrfToken()
                },
                credentials: 'same-origin'
            })
            .then(function (r) { return r.ok ? r.json() : Promise.reject(r); })
            .then(function (data) {
                notifList.innerHTML = '';

                // Perbarui badge sesuai data terbaru
                setBadgeCount(data.count);

                var notifications = data.notifications || [];
                if (notifications.length === 0) {
                    notifList.appendChild(renderEmpty());
                    return;
                }
                notifications.forEach(function (n) {
                    notifList.appendChild(renderNotifItem(n));
                });
            })
            .catch(function () {
                notifList.innerHTML =
                    '<li class="text-center text-danger small py-3">Gagal memuat notifikasi.</li>';
            });
        }

        // Klik satu notifikasi: POST mark_notif.php lalu redirect (Requirement 8.3)
        function handleNotifClick(notifId) {
            var formData = new FormData();
            formData.append('notif_id', notifId);

            fetch(BASE_URL + '/api/mark_notif.php', {
                method:  'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-Token':     getCsrfToken()
                },
                body:        formData,
                credentials: 'same-origin'
            })
            .then(function (r) { return r.ok ? r.json() : Promise.reject(r); })
            .then(function (data) {
                if (data.redirect_url) {
                    window.location.href = data.redirect_url;
                }
            })
            .catch(function () {
                // Gagal tandai — tetap redirect ke detail menggunakan reimb_id yang sudah ada di DOM
                // (tidak ideal; senyapkan saja agar tidak memblokir navigasi)
            });
        }

        // Muat notifikasi setiap kali dropdown dibuka
        notifToggle.addEventListener('show.bs.dropdown', function () {
            loadNotifications();
        });
    }

    // ---------------------------------------------------------------------------
    // Tombol "Tandai Semua Dibaca" (Requirement 8.7)
    // ---------------------------------------------------------------------------
    if (markAllBtn) {
        markAllBtn.addEventListener('click', function () {
            markAllBtn.disabled = true;

            fetch(BASE_URL + '/api/mark_notif.php?all=1', {
                method:  'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-Token':     getCsrfToken()
                },
                credentials: 'same-origin'
            })
            .then(function (r) { return r.ok ? r.json() : Promise.reject(r); })
            .then(function (data) {
                if (data.success) {
                    // Perbarui badge ke 0 (Requirement 8.7)
                    setBadgeCount(0);

                    // Hapus highlight bold/bg-light dari semua item di panel
                    if (notifList) {
                        notifList.querySelectorAll('.notif-item').forEach(function (el) {
                            el.classList.remove('fw-semibold', 'bg-light');
                        });
                    }
                }
            })
            .catch(function () { /* abaikan secara senyap */ })
            .finally(function () {
                markAllBtn.disabled = false;
            });
        });
    }

    // ── Helper: escape HTML untuk mencegah XSS ────────────────────────────────
    function escapeHtml(str) {
        if (str === null || str === undefined) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

}());
</script>
