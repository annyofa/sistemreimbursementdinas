<?php

/**
 * public/pegawai/create.php
 *
 * Halaman form pembuatan pengajuan reimbursement baru oleh pegawai.
 *
 * Alur POST:
 *   1. verifyCsrf()
 *   2. Validasi server-side (destination, purpose, start_date, end_date wajib;
 *      end_date >= start_date)
 *   3. INSERT ke business_trips  → dapatkan $tripId
 *   4. generateReimbNo()         → dapatkan nomor pengajuan unik
 *   5. INSERT ke reimbursements  → status = 'draft', submitted_at = NULL
 *   6. logAudit('CREATE_REIMBURSEMENT', ...)
 *   7. Redirect ke detail.php?id={$reimbId}
 *
 * Requirements: 2.1
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

// Pastikan sudah login dan hanya role pegawai yang boleh akses
requireAuth();
requireRole(['pegawai']);

$user   = getCurrentUser();
$userId = (int) $user['user_id'];
$db     = getDB();

// ── Inisialisasi nilai form (untuk re-fill setelah error) ────────────────────
$formData = [
    'destination' => '',
    'purpose'     => '',
    'start_date'  => '',
    'end_date'    => '',
];

$errors = [];

// ── Proses POST ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // 1. CSRF check
    verifyCsrf();

    // 2. Sanitasi dan ambil input
    $formData['destination'] = trim($_POST['destination'] ?? '');
    $formData['purpose']     = trim($_POST['purpose']     ?? '');
    $formData['start_date']  = trim($_POST['start_date']  ?? '');
    $formData['end_date']    = trim($_POST['end_date']    ?? '');

    // 3. Validasi server-side
    if ($formData['destination'] === '') {
        $errors['destination'] = 'Tujuan perjalanan wajib diisi.';
    }

    if ($formData['purpose'] === '') {
        $errors['purpose'] = 'Keperluan perjalanan wajib diisi.';
    }

    if ($formData['start_date'] === '') {
        $errors['start_date'] = 'Tanggal berangkat wajib diisi.';
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $formData['start_date'])) {
        $errors['start_date'] = 'Format tanggal berangkat tidak valid.';
    }

    if ($formData['end_date'] === '') {
        $errors['end_date'] = 'Tanggal kembali wajib diisi.';
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $formData['end_date'])) {
        $errors['end_date'] = 'Format tanggal kembali tidak valid.';
    }

    // Pastikan end_date >= start_date (hanya jika keduanya valid)
    if (
        empty($errors['start_date']) &&
        empty($errors['end_date']) &&
        $formData['end_date'] < $formData['start_date']
    ) {
        $errors['end_date'] = 'Tanggal kembali tidak boleh sebelum tanggal berangkat.';
    }

    // 4. Simpan ke database jika tidak ada error
    if (empty($errors)) {
        try {
            $db->beginTransaction();

            // 4a. INSERT ke business_trips
            $stmtTrip = $db->prepare(
                "INSERT INTO business_trips (user_id, destination, purpose, start_date, end_date)
                 VALUES (:user_id, :destination, :purpose, :start_date, :end_date)"
            );
            $stmtTrip->execute([
                ':user_id'     => $userId,
                ':destination' => $formData['destination'],
                ':purpose'     => $formData['purpose'],
                ':start_date'  => $formData['start_date'],
                ':end_date'    => $formData['end_date'],
            ]);
            $tripId = (int) $db->lastInsertId();

            // 4b. Generate nomor pengajuan unik
            $reimbNo = generateReimbNo();

            // 4c. INSERT ke reimbursements (status = 'draft', total_amount = 0)
            $stmtReimb = $db->prepare(
                "INSERT INTO reimbursements
                     (reimbursement_no, user_id, trip_id, total_amount, status, created_at)
                 VALUES
                     (:reimbursement_no, :user_id, :trip_id, 0, 'draft', NOW())"
            );
            $stmtReimb->execute([
                ':reimbursement_no' => $reimbNo,
                ':user_id'          => $userId,
                ':trip_id'          => $tripId,
            ]);
            $reimbId = (int) $db->lastInsertId();

            $db->commit();

            // 4d. Audit log (di luar transaksi untuk menghindari deadlock pada audit_logs)
            logAudit(
                $userId,
                'CREATE_REIMBURSEMENT',
                'reimbursements',
                $reimbId,
                [
                    'after' => [
                        'reimbursement_no' => $reimbNo,
                        'trip_id'          => $tripId,
                        'destination'      => $formData['destination'],
                        'purpose'          => $formData['purpose'],
                        'start_date'       => $formData['start_date'],
                        'end_date'         => $formData['end_date'],
                        'status'           => 'draft',
                    ],
                ]
            );

            // 4e. Flash success + redirect ke detail
            $_SESSION['flash'] = [
                'type'    => 'success',
                'message' => 'Pengajuan berhasil dibuat. Silakan tambahkan item biaya.',
            ];

            header('Location: ' . BASE_URL . '/pegawai/detail.php?id=' . $reimbId);
            exit;

        } catch (\Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            // Tampilkan pesan generik; log detail error di server
            error_log('[create.php] Gagal membuat pengajuan: ' . $e->getMessage());

            $_SESSION['flash'] = [
                'type'    => 'danger',
                'message' => 'Terjadi kesalahan saat menyimpan pengajuan. Silakan coba lagi.',
            ];
        }
    }
}

// ── Render halaman ────────────────────────────────────────────────────────────
$pageTitle = 'Buat Pengajuan Baru';
require_once __DIR__ . '/../../includes/layout/header.php';
require_once __DIR__ . '/../../includes/layout/navbar.php';
?>

<!-- Breadcrumb -->
<nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb">
        <li class="breadcrumb-item">
            <a href="<?= BASE_URL ?>/pegawai/index.php">Pengajuan Saya</a>
        </li>
        <li class="breadcrumb-item active" aria-current="page">Buat Pengajuan Baru</li>
    </ol>
</nav>

<!-- Judul -->
<div class="mb-4">
    <h1 class="h3 fw-bold mb-0">
        <i class="bi bi-plus-circle me-2 text-primary"></i>Buat Pengajuan Baru
    </h1>
    <p class="text-muted mb-0 mt-1 small">
        Isi data perjalanan dinas. Setelah pengajuan dibuat, Anda dapat menambahkan item biaya pada halaman detail.
    </p>
</div>

<!-- Card form -->
<div class="row justify-content-center">
    <div class="col-12 col-lg-8 col-xl-7">
        <div class="card shadow-sm">
            <div class="card-header bg-white border-bottom py-3">
                <h2 class="h6 fw-semibold mb-0">
                    <i class="bi bi-airplane me-2 text-primary"></i>Data Perjalanan Dinas
                </h2>
            </div>

            <div class="card-body py-4">
                <!-- Ringkasan error (jika ada) -->
                <?php if (!empty($errors)): ?>
                <div class="alert alert-danger" role="alert">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i>
                    <strong>Terdapat kesalahan pada form:</strong>
                    <ul class="mb-0 mt-1">
                        <?php foreach ($errors as $errMsg): ?>
                            <li><?= htmlspecialchars($errMsg, ENT_QUOTES, 'UTF-8') ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>

                <form
                    method="post"
                    action="<?= BASE_URL ?>/pegawai/create.php"
                    novalidate
                    id="form-create-reimb"
                >
                    <?= csrfField() ?>

                    <!-- Destination -->
                    <div class="mb-3">
                        <label for="destination" class="form-label fw-medium">
                            Tujuan Perjalanan <span class="text-danger">*</span>
                        </label>
                        <input
                            type="text"
                            id="destination"
                            name="destination"
                            class="form-control<?= isset($errors['destination']) ? ' is-invalid' : '' ?>"
                            value="<?= htmlspecialchars($formData['destination'], ENT_QUOTES, 'UTF-8') ?>"
                            placeholder="Contoh: Jakarta, Bandung"
                            maxlength="255"
                            required
                            autofocus
                        >
                        <?php if (isset($errors['destination'])): ?>
                            <div class="invalid-feedback">
                                <?= htmlspecialchars($errors['destination'], ENT_QUOTES, 'UTF-8') ?>
                            </div>
                        <?php else: ?>
                            <div class="form-text">Kota atau lokasi tujuan perjalanan dinas.</div>
                        <?php endif; ?>
                    </div>

                    <!-- Purpose -->
                    <div class="mb-3">
                        <label for="purpose" class="form-label fw-medium">
                            Keperluan <span class="text-danger">*</span>
                        </label>
                        <textarea
                            id="purpose"
                            name="purpose"
                            class="form-control<?= isset($errors['purpose']) ? ' is-invalid' : '' ?>"
                            rows="3"
                            maxlength="500"
                            placeholder="Contoh: Rapat koordinasi proyek X dengan klien di kantor pusat"
                            required
                        ><?= htmlspecialchars($formData['purpose'], ENT_QUOTES, 'UTF-8') ?></textarea>
                        <?php if (isset($errors['purpose'])): ?>
                            <div class="invalid-feedback">
                                <?= htmlspecialchars($errors['purpose'], ENT_QUOTES, 'UTF-8') ?>
                            </div>
                        <?php else: ?>
                            <div class="form-text">Jelaskan tujuan dan kegiatan perjalanan dinas ini.</div>
                        <?php endif; ?>
                    </div>

                    <!-- Tanggal Berangkat & Tanggal Kembali (sejajar pada layar md ke atas) -->
                    <div class="row g-3 mb-4">
                        <div class="col-12 col-md-6">
                            <label for="start_date" class="form-label fw-medium">
                                Tanggal Berangkat <span class="text-danger">*</span>
                            </label>
                            <input
                                type="date"
                                id="start_date"
                                name="start_date"
                                class="form-control<?= isset($errors['start_date']) ? ' is-invalid' : '' ?>"
                                value="<?= htmlspecialchars($formData['start_date'], ENT_QUOTES, 'UTF-8') ?>"
                                required
                            >
                            <?php if (isset($errors['start_date'])): ?>
                                <div class="invalid-feedback">
                                    <?= htmlspecialchars($errors['start_date'], ENT_QUOTES, 'UTF-8') ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="col-12 col-md-6">
                            <label for="end_date" class="form-label fw-medium">
                                Tanggal Kembali <span class="text-danger">*</span>
                            </label>
                            <input
                                type="date"
                                id="end_date"
                                name="end_date"
                                class="form-control<?= isset($errors['end_date']) ? ' is-invalid' : '' ?>"
                                value="<?= htmlspecialchars($formData['end_date'], ENT_QUOTES, 'UTF-8') ?>"
                                required
                            >
                            <?php if (isset($errors['end_date'])): ?>
                                <div class="invalid-feedback">
                                    <?= htmlspecialchars($errors['end_date'], ENT_QUOTES, 'UTF-8') ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Durasi (read-only, diisi JS) -->
                    <div class="mb-4" id="duration-row" style="display:none;">
                        <div class="alert alert-info py-2 mb-0">
                            <i class="bi bi-calendar-range me-2"></i>
                            Durasi perjalanan: <strong id="duration-text"></strong>
                        </div>
                    </div>

                    <!-- Tombol aksi -->
                    <div class="d-flex gap-2 justify-content-end pt-2 border-top">
                        <a
                            href="<?= BASE_URL ?>/pegawai/index.php"
                            class="btn btn-outline-secondary"
                        >
                            <i class="bi bi-x-circle me-1"></i>Batal
                        </a>
                        <button type="submit" class="btn btn-primary" id="btn-submit">
                            <i class="bi bi-save me-1"></i>Simpan &amp; Lanjutkan
                        </button>
                    </div>
                </form>
            </div><!-- /.card-body -->
        </div><!-- /.card -->

        <!-- Info box -->
        <div class="alert alert-light border mt-3 small">
            <i class="bi bi-info-circle text-primary me-1"></i>
            Setelah pengajuan berhasil disimpan, Anda akan diarahkan ke halaman detail
            untuk menambahkan <strong>item biaya</strong> dan mengunggah struk.
            Pengajuan hanya dapat disubmit setelah memiliki minimal satu item biaya.
        </div>
    </div>
</div>

<script>
(function () {
    'use strict';

    var startInput    = document.getElementById('start_date');
    var endInput      = document.getElementById('end_date');
    var durationRow   = document.getElementById('duration-row');
    var durationText  = document.getElementById('duration-text');

    /**
     * Hitung dan tampilkan durasi perjalanan.
     * Juga enforce min attribute pada end_date agar tidak bisa pilih
     * tanggal sebelum start_date di date picker.
     */
    function updateDuration() {
        var startVal = startInput.value;
        var endVal   = endInput.value;

        // Set min date pada end_date sesuai start_date yang dipilih
        if (startVal) {
            endInput.min = startVal;
        }

        if (!startVal || !endVal) {
            durationRow.style.display = 'none';
            return;
        }

        var startDate = new Date(startVal);
        var endDate   = new Date(endVal);

        if (isNaN(startDate.getTime()) || isNaN(endDate.getTime())) {
            durationRow.style.display = 'none';
            return;
        }

        var diffMs   = endDate.getTime() - startDate.getTime();
        var diffDays = Math.round(diffMs / (1000 * 60 * 60 * 24));

        if (diffDays < 0) {
            // end_date sebelum start_date — sembunyikan durasi
            durationRow.style.display = 'none';
            return;
        }

        var nights = diffDays; // jumlah malam
        var label  = diffDays === 0
            ? '1 hari (pulang hari)'
            : (diffDays + 1) + ' hari / ' + nights + ' malam';

        durationText.textContent = label;
        durationRow.style.display = '';
    }

    startInput.addEventListener('change', updateDuration);
    endInput.addEventListener('change', updateDuration);

    // Jalankan sekali saat load (untuk re-fill setelah error POST)
    updateDuration();

    // Cegah double-submit
    var form      = document.getElementById('form-create-reimb');
    var btnSubmit = document.getElementById('btn-submit');
    form.addEventListener('submit', function () {
        btnSubmit.disabled = true;
        btnSubmit.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>Menyimpan…';
    });
}());
</script>

<?php require_once __DIR__ . '/../../includes/layout/footer.php'; ?>
