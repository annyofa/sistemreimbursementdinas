<?php

/**
 * public/kasir/bayar.php
 *
 * Halaman catat pembayaran reimbursement oleh kasir.
 *
 * Fitur:
 *   - Hanya dapat diakses oleh user dengan role 'kasir'
 *   - GET  ?id={reimbursement_id}
 *     → Jika status 'disetujui'  : tampilkan form catat pembayaran
 *     → Jika status 'dibayar'    : tampilkan ringkasan pembayaran (read-only)
 *     → Status lain              : redirect ke index.php dengan flash warning
 *   - POST : validasi CSRF + validasi nominal → INSERT payments →
 *            updateReimbursementStatus → UPDATE reimbursements.paid_at →
 *            createNotification (pegawai) → logAudit → redirect index.php
 *
 * Upload bukti pembayaran (opsional):
 *   - Format  : JPEG / PNG / PDF
 *   - Maks    : 5 MB
 *   - Disimpan ke uploads/payments/{reimbursement_id}_{timestamp}.{ext}
 *   - Kolom   : payments.proof_path (VARCHAR, opsional — NULL jika tidak diupload)
 *
 * Requirements: 6.2, 6.3, 6.4, 6.5, 6.6
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireAuth();
requireRole(['kasir']);

$user     = getCurrentUser();
$userId   = (int) $user['user_id'];
$db       = getDB();

// ── Validasi parameter GET ?id= ───────────────────────────────────────────────
$reimbId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($reimbId <= 0) {
    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'ID pengajuan tidak valid.'];
    header('Location: ' . BASE_URL . '/kasir/index.php');
    exit;
}

// ── Ambil data reimbursement beserta info pegawai ─────────────────────────────
$stmtR = $db->prepare(
    "SELECT
         r.id,
         r.reimbursement_no,
         r.user_id,
         r.total_amount,
         r.status,
         r.notes,
         r.submitted_at,
         r.paid_at,
         u.name          AS pegawai_name,
         u.email         AS pegawai_email,
         d.name          AS dept_name
     FROM reimbursements r
     JOIN users u ON u.id = r.user_id
     LEFT JOIN departments d ON d.id = u.department_id
     WHERE r.id = :id"
);
$stmtR->execute([':id' => $reimbId]);
$reimb = $stmtR->fetch();

if ($reimb === false) {
    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Pengajuan tidak ditemukan.'];
    header('Location: ' . BASE_URL . '/kasir/index.php');
    exit;
}

// ── Guard status ─────────────────────────────────────────────────────────────
// Hanya status 'disetujui' (aksi bayar) dan 'dibayar' (ringkasan) yang valid.
if (!in_array($reimb['status'], ['disetujui', 'dibayar'], true)) {
    $_SESSION['flash'] = [
        'type'    => 'warning',
        'message' => 'Pengajuan ini tidak dapat diproses (status: ' .
                     htmlspecialchars($reimb['status'], ENT_QUOTES, 'UTF-8') . ').',
    ];
    header('Location: ' . BASE_URL . '/kasir/index.php');
    exit;
}

// ── Ambil data pembayaran jika sudah dibayar (untuk tampilan ringkasan) ───────
$payment = null;
if ($reimb['status'] === 'dibayar') {
    $stmtPay = $db->prepare(
        "SELECT p.id, p.amount, p.method, p.reference_no, p.paid_at, p.proof_path,
                u.name AS cashier_name
         FROM payments p
         JOIN users u ON u.id = p.cashier_id
         WHERE p.reimbursement_id = :reimb_id
         LIMIT 1"
    );
    $stmtPay->execute([':reimb_id' => $reimbId]);
    $payment = $stmtPay->fetch() ?: null;
}

// =============================================================================
// POST HANDLER — Catat Pembayaran
// =============================================================================

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    // ── Ambil dan bersihkan input ─────────────────────────────────────────────
    $method      = in_array($_POST['method'] ?? '', ['transfer', 'tunai'], true)
                   ? $_POST['method']
                   : 'transfer';
    $paidAt      = trim($_POST['paid_at'] ?? '');
    $amount      = trim($_POST['amount'] ?? '');
    $referenceNo = trim($_POST['reference_no'] ?? '');

    // ── Validasi nominal (Requirement 6.5) ────────────────────────────────────
    if (!isValidPaymentAmount($amount)) {
        $errors[] = 'Nominal pembayaran tidak boleh kosong atau nol';
    }

    // ── Validasi tanggal ──────────────────────────────────────────────────────
    if ($paidAt === '') {
        $errors[] = 'Tanggal pembayaran wajib diisi.';
    }

    // ── Proses upload bukti pembayaran (opsional, Requirement 6.4) ───────────
    $proofPath = null;
    $uploadError = null;

    if (!empty($_FILES['proof']['name'])) {
        $file       = $_FILES['proof'];
        $maxBytes   = 5 * 1024 * 1024; // 5 MB
        $allowedExt = ['jpg', 'jpeg', 'png', 'pdf'];

        if ($file['error'] !== UPLOAD_ERR_OK) {
            $uploadError = 'Gagal mengupload file bukti pembayaran (kode error: ' . $file['error'] . ').';
        } elseif ($file['size'] > $maxBytes) {
            $uploadError = 'Ukuran file bukti melebihi batas maksimal 5 MB.';
        } else {
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, $allowedExt, true)) {
                $uploadError = 'Format file bukti tidak didukung. Gunakan JPEG, PNG, atau PDF.';
            } elseif (!isValidUploadFile($file['tmp_name'], $file['type'])) {
                $uploadError = 'Tipe file bukti tidak valid atau tidak sesuai dengan ekstensi.';
            }
        }

        if ($uploadError) {
            $errors[] = $uploadError;
        }
    }

    // ── Simpan jika tidak ada error ───────────────────────────────────────────
    if (empty($errors)) {
        // Buat direktori uploads/payments/ jika belum ada
        $uploadDir = __DIR__ . '/../../uploads/payments/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        // Upload file bukti
        if (!empty($_FILES['proof']['name']) && $uploadError === null) {
            $ext       = strtolower(pathinfo($_FILES['proof']['name'], PATHINFO_EXTENSION));
            $filename  = $reimbId . '_' . time() . '.' . $ext;
            $destPath  = $uploadDir . $filename;

            if (move_uploaded_file($_FILES['proof']['tmp_name'], $destPath)) {
                $proofPath = 'uploads/payments/' . $filename;
            } else {
                $errors[] = 'Gagal menyimpan file bukti pembayaran ke server.';
            }
        }

        if (empty($errors)) {
            try {
                $db->beginTransaction();

                // ── INSERT ke tabel payments ──────────────────────────────────
                // Coba dengan proof_path; jika kolom tidak ada, fallback tanpa proof_path.
                try {
                    $stmtIns = $db->prepare(
                        "INSERT INTO payments
                             (reimbursement_id, cashier_id, amount, method, reference_no, paid_at, proof_path)
                         VALUES
                             (:reimbursement_id, :cashier_id, :amount, :method, :reference_no, :paid_at, :proof_path)"
                    );
                    $stmtIns->execute([
                        ':reimbursement_id' => $reimbId,
                        ':cashier_id'       => $userId,
                        ':amount'           => (float) $amount,
                        ':method'           => $method,
                        ':reference_no'     => $referenceNo !== '' ? $referenceNo : null,
                        ':paid_at'          => $paidAt,
                        ':proof_path'       => $proofPath,
                    ]);
                } catch (\PDOException $e) {
                    // Jika kolom proof_path belum ada di tabel, INSERT tanpa kolom itu
                    if (str_contains($e->getMessage(), 'proof_path') || $e->getCode() === '42S22') {
                        $stmtIns = $db->prepare(
                            "INSERT INTO payments
                                 (reimbursement_id, cashier_id, amount, method, reference_no, paid_at)
                             VALUES
                                 (:reimbursement_id, :cashier_id, :amount, :method, :reference_no, :paid_at)"
                        );
                        $stmtIns->execute([
                            ':reimbursement_id' => $reimbId,
                            ':cashier_id'       => $userId,
                            ':amount'           => (float) $amount,
                            ':method'           => $method,
                            ':reference_no'     => $referenceNo !== '' ? $referenceNo : null,
                            ':paid_at'          => $paidAt,
                        ]);
                    } else {
                        throw $e;
                    }
                }

                $paymentId = (int) $db->lastInsertId();

                // ── Update status → dibayar ───────────────────────────────────
                updateReimbursementStatus(
                    $reimbId,
                    'dibayar',
                    $userId,
                    'Pembayaran dicatat oleh kasir. Metode: ' . $method .
                    ($referenceNo !== '' ? '. Ref: ' . $referenceNo : '')
                );

                // ── UPDATE reimbursements.paid_at ─────────────────────────────
                $stmtPaidAt = $db->prepare(
                    "UPDATE reimbursements SET paid_at = :paid_at WHERE id = :id"
                );
                $stmtPaidAt->execute([
                    ':paid_at' => $paidAt,
                    ':id'      => $reimbId,
                ]);

                // ── Notifikasi ke pegawai pengaju (Requirement 6.3) ───────────
                createNotification(
                    (int) $reimb['user_id'],
                    $reimbId,
                    'Reimbursement Telah Dibayar',
                    'Pembayaran reimbursement ' .
                    htmlspecialchars($reimb['reimbursement_no'], ENT_QUOTES, 'UTF-8') .
                    ' sebesar ' .
                    htmlspecialchars(formatRupiah((float) $amount), ENT_QUOTES, 'UTF-8') .
                    ' telah diproses oleh kasir via ' . $method . '.'
                );

                // ── Audit log (Requirement 6.3) ───────────────────────────────
                logAudit($userId, 'SAVE_PAYMENT', 'payments', $paymentId, [
                    'reimbursement_id' => $reimbId,
                    'cashier_id'       => $userId,
                    'cashier_name'     => $user['name'],
                    'amount'           => (float) $amount,
                    'method'           => $method,
                    'reference_no'     => $referenceNo !== '' ? $referenceNo : null,
                    'paid_at'          => $paidAt,
                    'proof_path'       => $proofPath,
                ]);

                $db->commit();

                $_SESSION['flash'] = [
                    'type'    => 'success',
                    'message' => 'Pembayaran berhasil dicatat. Pegawai telah menerima notifikasi.',
                ];
                header('Location: ' . BASE_URL . '/kasir/index.php');
                exit;

            } catch (\Exception $e) {
                $db->rollBack();
                $errors[] = 'Gagal menyimpan pembayaran: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
            }
        }
    }
}

// =============================================================================
// TAMPILAN
// =============================================================================

$pageTitle = 'Bayar — ' . htmlspecialchars($reimb['reimbursement_no'] ?? '', ENT_QUOTES, 'UTF-8');
require_once __DIR__ . '/../../includes/layout/header.php';
require_once __DIR__ . '/../../includes/layout/navbar.php';
?>

<!-- Breadcrumb -->
<nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb">
        <li class="breadcrumb-item">
            <a href="<?= BASE_URL ?>/kasir/index.php">Daftar Pembayaran</a>
        </li>
        <li class="breadcrumb-item active" aria-current="page">
            <?= htmlspecialchars($reimb['reimbursement_no'] ?? 'Detail', ENT_QUOTES, 'UTF-8') ?>
        </li>
    </ol>
</nav>

<!-- Header halaman -->
<div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-2">
    <div>
        <h1 class="h3 fw-bold mb-1">
            <i class="bi bi-cash-coin me-2 text-primary"></i>
            <?php if ($reimb['status'] === 'dibayar'): ?>
                Ringkasan Pembayaran
            <?php else: ?>
                Catat Pembayaran
            <?php endif; ?>
        </h1>
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <?= getStatusLabel($reimb['status']) ?>
            <span class="text-muted small">
                Pengaju: <strong><?= htmlspecialchars($reimb['pegawai_name'] ?? '—', ENT_QUOTES, 'UTF-8') ?></strong>
            </span>
            <span class="text-muted small">
                Dept: <strong><?= htmlspecialchars($reimb['dept_name'] ?? '—', ENT_QUOTES, 'UTF-8') ?></strong>
            </span>
            <span class="text-muted small font-monospace">
                <?= htmlspecialchars($reimb['reimbursement_no'] ?? '', ENT_QUOTES, 'UTF-8') ?>
            </span>
        </div>
    </div>
    <a href="<?= BASE_URL ?>/kasir/index.php" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i>Kembali
    </a>
</div>

<!-- Error list -->
<?php if (!empty($errors)): ?>
<div class="alert alert-danger alert-dismissible fade show" role="alert">
    <i class="bi bi-exclamation-triangle-fill me-2"></i>
    <strong>Terjadi kesalahan:</strong>
    <ul class="mb-0 mt-1">
        <?php foreach ($errors as $err): ?>
            <li><?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?></li>
        <?php endforeach; ?>
    </ul>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Tutup"></button>
</div>
<?php endif; ?>

<div class="row g-4">
    <!-- Kolom kiri: Info pengajuan -->
    <div class="col-lg-5">
        <div class="card shadow-sm">
            <div class="card-header bg-white fw-semibold py-3">
                <i class="bi bi-file-earmark-text me-2 text-primary"></i>Detail Pengajuan
            </div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-sm-5 text-muted fw-normal small">Nomor Pengajuan</dt>
                    <dd class="col-sm-7 font-monospace small fw-semibold mb-2">
                        <?= htmlspecialchars($reimb['reimbursement_no'] ?? '—', ENT_QUOTES, 'UTF-8') ?>
                    </dd>

                    <dt class="col-sm-5 text-muted fw-normal small">Pegawai</dt>
                    <dd class="col-sm-7 fw-semibold mb-2">
                        <?= htmlspecialchars($reimb['pegawai_name'] ?? '—', ENT_QUOTES, 'UTF-8') ?>
                    </dd>

                    <dt class="col-sm-5 text-muted fw-normal small">Email</dt>
                    <dd class="col-sm-7 mb-2">
                        <?= htmlspecialchars($reimb['pegawai_email'] ?? '—', ENT_QUOTES, 'UTF-8') ?>
                    </dd>

                    <dt class="col-sm-5 text-muted fw-normal small">Departemen</dt>
                    <dd class="col-sm-7 mb-2">
                        <?= htmlspecialchars($reimb['dept_name'] ?? '—', ENT_QUOTES, 'UTF-8') ?>
                    </dd>

                    <dt class="col-sm-5 text-muted fw-normal small">Total Diajukan</dt>
                    <dd class="col-sm-7 fw-bold text-primary fs-6 mb-2">
                        <?= htmlspecialchars(formatRupiah((float)($reimb['total_amount'] ?? 0)), ENT_QUOTES, 'UTF-8') ?>
                    </dd>

                    <dt class="col-sm-5 text-muted fw-normal small">Status</dt>
                    <dd class="col-sm-7 mb-2">
                        <?= getStatusLabel($reimb['status']) ?>
                    </dd>

                    <?php if (!empty($reimb['submitted_at'])): ?>
                    <dt class="col-sm-5 text-muted fw-normal small">Diajukan</dt>
                    <dd class="col-sm-7 small mb-2">
                        <?= htmlspecialchars(date('d M Y H:i', strtotime($reimb['submitted_at'])), ENT_QUOTES, 'UTF-8') ?>
                    </dd>
                    <?php endif; ?>

                    <?php if (!empty($reimb['notes'])): ?>
                    <dt class="col-sm-5 text-muted fw-normal small">Catatan</dt>
                    <dd class="col-sm-7 small mb-0">
                        <?= htmlspecialchars($reimb['notes'], ENT_QUOTES, 'UTF-8') ?>
                    </dd>
                    <?php endif; ?>
                </dl>
            </div>
        </div>
    </div>

    <!-- Kolom kanan: Form pembayaran ATAU ringkasan pembayaran -->
    <div class="col-lg-7">

        <?php if ($reimb['status'] === 'dibayar' && $payment !== null): ?>
        <!-- ══════════════════════════════════════════════════════════════
             RINGKASAN PEMBAYARAN (Requirement 6.6) — read-only
             ══════════════════════════════════════════════════════════════ -->
        <div class="card shadow-sm border-success">
            <div class="card-header bg-success text-white fw-semibold py-3">
                <i class="bi bi-check-circle-fill me-2"></i>Pembayaran Telah Diproses
            </div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-sm-5 text-muted fw-normal small">Metode Pembayaran</dt>
                    <dd class="col-sm-7 fw-semibold mb-2">
                        <?php
                        $methodLabel = $payment['method'] === 'transfer' ? 'Transfer Bank' : 'Tunai';
                        echo htmlspecialchars($methodLabel, ENT_QUOTES, 'UTF-8');
                        ?>
                        <span class="badge <?= $payment['method'] === 'transfer' ? 'bg-info text-dark' : 'bg-secondary' ?> ms-1">
                            <?= htmlspecialchars(strtoupper($payment['method']), ENT_QUOTES, 'UTF-8') ?>
                        </span>
                    </dd>

                    <dt class="col-sm-5 text-muted fw-normal small">Nominal Dibayar</dt>
                    <dd class="col-sm-7 fw-bold text-success fs-6 mb-2">
                        <?= htmlspecialchars(formatRupiah((float)($payment['amount'] ?? 0)), ENT_QUOTES, 'UTF-8') ?>
                    </dd>

                    <dt class="col-sm-5 text-muted fw-normal small">Tanggal Pembayaran</dt>
                    <dd class="col-sm-7 mb-2">
                        <?= !empty($payment['paid_at'])
                            ? htmlspecialchars(date('d M Y H:i', strtotime($payment['paid_at'])), ENT_QUOTES, 'UTF-8')
                            : '—' ?>
                    </dd>

                    <dt class="col-sm-5 text-muted fw-normal small">No. Referensi</dt>
                    <dd class="col-sm-7 font-monospace small mb-2">
                        <?= !empty($payment['reference_no'])
                            ? htmlspecialchars($payment['reference_no'], ENT_QUOTES, 'UTF-8')
                            : '<span class="text-muted">—</span>' ?>
                    </dd>

                    <dt class="col-sm-5 text-muted fw-normal small">Kasir</dt>
                    <dd class="col-sm-7 mb-2">
                        <?= htmlspecialchars($payment['cashier_name'] ?? '—', ENT_QUOTES, 'UTF-8') ?>
                    </dd>

                    <?php if (!empty($payment['proof_path'])): ?>
                    <dt class="col-sm-5 text-muted fw-normal small">Bukti Pembayaran</dt>
                    <dd class="col-sm-7 mb-0">
                        <?php
                        $proofUrl  = UPLOADS_URL . '/' . substr(htmlspecialchars($payment['proof_path'], ENT_QUOTES, 'UTF-8'), strlen('uploads/'));
                        $proofExt  = strtolower(pathinfo($payment['proof_path'], PATHINFO_EXTENSION));
                        $isPdf     = $proofExt === 'pdf';
                        ?>
                        <?php if ($isPdf): ?>
                            <a href="<?= $proofUrl ?>" target="_blank" rel="noopener noreferrer"
                               class="btn btn-sm btn-outline-primary">
                                <i class="bi bi-file-pdf me-1"></i>Lihat PDF
                            </a>
                        <?php else: ?>
                            <a href="<?= $proofUrl ?>" target="_blank" rel="noopener noreferrer">
                                <img
                                    src="<?= $proofUrl ?>"
                                    alt="Bukti pembayaran"
                                    class="img-thumbnail"
                                    style="max-height: 160px; max-width: 100%;"
                                >
                            </a>
                        <?php endif; ?>
                    </dd>
                    <?php else: ?>
                    <dt class="col-sm-5 text-muted fw-normal small">Bukti Pembayaran</dt>
                    <dd class="col-sm-7 mb-0 text-muted small">Tidak ada bukti yang diupload.</dd>
                    <?php endif; ?>
                </dl>
            </div>
        </div>

        <?php elseif ($reimb['status'] === 'disetujui'): ?>
        <!-- ══════════════════════════════════════════════════════════════
             FORM CATAT PEMBAYARAN (Requirements 6.2, 6.4, 6.5)
             ══════════════════════════════════════════════════════════════ -->
        <div class="card shadow-sm">
            <div class="card-header bg-white fw-semibold py-3">
                <i class="bi bi-pencil-square me-2 text-primary"></i>Form Catat Pembayaran
            </div>
            <div class="card-body">
                <form
                    method="post"
                    action="<?= BASE_URL ?>/kasir/bayar.php?id=<?= (int) $reimbId ?>"
                    enctype="multipart/form-data"
                    novalidate
                >
                    <?= csrfField() ?>

                    <!-- Metode Pembayaran -->
                    <div class="mb-3">
                        <label class="form-label fw-semibold" for="method">
                            Metode Pembayaran <span class="text-danger">*</span>
                        </label>
                        <select
                            class="form-select"
                            id="method"
                            name="method"
                            required
                        >
                            <option value="transfer"
                                <?= ($_POST['method'] ?? 'transfer') === 'transfer' ? 'selected' : '' ?>>
                                Transfer Bank
                            </option>
                            <option value="tunai"
                                <?= ($_POST['method'] ?? '') === 'tunai' ? 'selected' : '' ?>>
                                Tunai
                            </option>
                        </select>
                    </div>

                    <!-- Tanggal Pembayaran -->
                    <div class="mb-3">
                        <label class="form-label fw-semibold" for="paid_at">
                            Tanggal Pembayaran <span class="text-danger">*</span>
                        </label>
                        <input
                            type="datetime-local"
                            class="form-control"
                            id="paid_at"
                            name="paid_at"
                            value="<?= htmlspecialchars($_POST['paid_at'] ?? date('Y-m-d\TH:i'), ENT_QUOTES, 'UTF-8') ?>"
                            required
                        >
                    </div>

                    <!-- Nominal Pembayaran -->
                    <div class="mb-3">
                        <label class="form-label fw-semibold" for="amount">
                            Nominal Pembayaran (Rp) <span class="text-danger">*</span>
                        </label>
                        <div class="input-group">
                            <span class="input-group-text">Rp</span>
                            <input
                                type="number"
                                class="form-control <?= in_array('Nominal pembayaran tidak boleh kosong atau nol', $errors) ? 'is-invalid' : '' ?>"
                                id="amount"
                                name="amount"
                                value="<?= htmlspecialchars($_POST['amount'] ?? number_format((float)$reimb['total_amount'], 0, '.', ''), ENT_QUOTES, 'UTF-8') ?>"
                                min="1"
                                step="1"
                                placeholder="Contoh: 1500000"
                                required
                                aria-describedby="amountHelp"
                            >
                            <?php if (in_array('Nominal pembayaran tidak boleh kosong atau nol', $errors)): ?>
                            <div class="invalid-feedback">
                                Nominal pembayaran tidak boleh kosong atau nol
                            </div>
                            <?php endif; ?>
                        </div>
                        <div id="amountHelp" class="form-text text-muted">
                            Total pengajuan: <strong><?= htmlspecialchars(formatRupiah((float)$reimb['total_amount']), ENT_QUOTES, 'UTF-8') ?></strong>
                        </div>
                    </div>

                    <!-- Nomor Referensi -->
                    <div class="mb-3">
                        <label class="form-label fw-semibold" for="reference_no">
                            Nomor Referensi / Kode Transfer
                            <span class="text-muted fw-normal small">(opsional)</span>
                        </label>
                        <input
                            type="text"
                            class="form-control"
                            id="reference_no"
                            name="reference_no"
                            value="<?= htmlspecialchars($_POST['reference_no'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                            maxlength="60"
                            placeholder="Contoh: TRF202506010001"
                        >
                    </div>

                    <!-- Upload Bukti Pembayaran (opsional, Requirement 6.4) -->
                    <div class="mb-4">
                        <label class="form-label fw-semibold" for="proof">
                            Bukti Pembayaran
                            <span class="text-muted fw-normal small">(opsional — JPEG, PNG, atau PDF, maks 5 MB)</span>
                        </label>
                        <input
                            type="file"
                            class="form-control"
                            id="proof"
                            name="proof"
                            accept=".jpg,.jpeg,.png,.pdf,image/jpeg,image/png,application/pdf"
                        >
                        <div class="form-text text-muted">
                            Lampirkan foto / scan bukti transfer atau kwitansi pembayaran.
                        </div>
                    </div>

                    <!-- Tombol aksi -->
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-success px-4">
                            <i class="bi bi-check-lg me-1"></i>Simpan Pembayaran
                        </button>
                        <a href="<?= BASE_URL ?>/kasir/index.php" class="btn btn-outline-secondary">
                            Batal
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <?php else: ?>
        <!-- Status tidak terduga (sudah ditangani oleh guard di atas, fallback saja) -->
        <div class="alert alert-warning">
            <i class="bi bi-exclamation-triangle me-2"></i>
            Tidak dapat menampilkan form untuk status ini.
        </div>
        <?php endif; ?>

    </div><!-- /.col-lg-7 -->
</div><!-- /.row -->

<?php require_once __DIR__ . '/../../includes/layout/footer.php'; ?>
