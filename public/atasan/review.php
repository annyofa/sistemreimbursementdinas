<?php

/**
 * public/atasan/review.php
 *
 * Halaman review dan aksi atasan untuk pengajuan reimbursement bawahan.
 *
 * Aksi yang tersedia:
 *   - Otomatis: saat halaman dibuka dengan status 'diajukan', ubah ke 'review_atasan'
 *   - Setujui  (POST action=setujui)       : upload paraf → INSERT approvals → review_finance
 *   - Tolak    (POST action=tolak)         : isi alasan  → INSERT approvals → ditolak
 *   - Revisi   (POST action=minta_revisi)  : isi catatan → INSERT revision_requests → perlu_revisi
 *
 * Requirements: 4.2, 4.3, 4.4, 4.5, 4.6, 4.7, 4.8
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireAuth();
requireRole(['atasan']);

$user   = getCurrentUser();
$userId = (int) $user['user_id'];
$db     = getDB();

// ── Validasi parameter GET ?id= ───────────────────────────────────────────────
$reimbId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($reimbId <= 0) {
    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'ID pengajuan tidak valid.'];
    header('Location: ' . BASE_URL . '/atasan/index.php');
    exit;
}

// ── Ambil data reimbursement ──────────────────────────────────────────────────
$stmtR = $db->prepare(
    "SELECT
         r.id,
         r.reimbursement_no,
         r.user_id,
         r.trip_id,
         r.total_amount,
         r.status,
         r.notes,
         r.submitted_at,
         r.created_at,
         bt.destination,
         bt.purpose,
         bt.start_date,
         bt.end_date,
         u.name          AS pegawai_name,
         u.email         AS pegawai_email,
         u.supervisor_id
     FROM reimbursements r
     LEFT JOIN business_trips bt ON bt.id = r.trip_id
     JOIN users u ON u.id = r.user_id
     WHERE r.id = :id"
);
$stmtR->execute([':id' => $reimbId]);
$reimb = $stmtR->fetch();

if ($reimb === false) {
    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Pengajuan tidak ditemukan.'];
    header('Location: ' . BASE_URL . '/atasan/index.php');
    exit;
}

// ── Cek ownership: hanya atasan langsung pegawai yang boleh review ────────────
if ((int) $reimb['supervisor_id'] !== $userId) {
    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Anda tidak memiliki akses untuk mereview pengajuan ini.'];
    header('Location: ' . BASE_URL . '/atasan/index.php');
    exit;
}

// ── Cek status: hanya boleh review jika diajukan atau review_atasan ───────────
$allowedStatuses = ['diajukan', 'review_atasan'];
if (!in_array($reimb['status'], $allowedStatuses, true)) {
    $_SESSION['flash'] = [
        'type'    => 'warning',
        'message' => 'Pengajuan ini tidak dapat lagi direview oleh atasan (status: ' .
                     htmlspecialchars($reimb['status'], ENT_QUOTES, 'UTF-8') . ').',
    ];
    header('Location: ' . BASE_URL . '/atasan/index.php');
    exit;
}

// ── Otomatis ubah status dari 'diajukan' → 'review_atasan' ───────────────────
// (hanya jika GET request dan status masih 'diajukan')
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $reimb['status'] === 'diajukan') {
    try {
        updateReimbursementStatus(
            $reimbId,
            'review_atasan',
            $userId,
            'Pengajuan mulai direview oleh atasan.'
        );
        // Perbarui data lokal agar tampilan konsisten
        $reimb['status'] = 'review_atasan';
    } catch (\Exception $e) {
        // Gagal update status — lanjutkan tampil halaman (status tetap 'diajukan')
        // tidak perlu menghentikan akses
    }
}

// =============================================================================
// POST HANDLERS
// =============================================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $action = trim($_POST['action'] ?? '');

    // ── AKSI: SETUJUI ─────────────────────────────────────────────────────────
    if ($action === 'setujui') {

        // Validasi: paraf digital wajib
        if (empty($_FILES['paraf']['name']) || $_FILES['paraf']['error'] !== UPLOAD_ERR_OK) {
            $_SESSION['flash'] = [
                'type'    => 'danger',
                'message' => 'Paraf digital wajib dilampirkan untuk menyetujui pengajuan.',
            ];
            header('Location: ' . BASE_URL . '/atasan/review.php?id=' . $reimbId);
            exit;
        }

        $tmpPath  = $_FILES['paraf']['tmp_name'];
        $origName = $_FILES['paraf']['name'];

        // Validasi MIME via finfo (JPEG / PNG / PDF)
        $finfo        = finfo_open(FILEINFO_MIME_TYPE);
        $detectedMime = finfo_file($finfo, $tmpPath);
        finfo_close($finfo);

        $allowedMimes = ['image/jpeg', 'image/png', 'application/pdf'];
        $allowedExts  = ['jpg', 'jpeg', 'png', 'pdf'];
        $origExt      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

        if (!in_array($detectedMime, $allowedMimes, true) || !in_array($origExt, $allowedExts, true)) {
            $_SESSION['flash'] = [
                'type'    => 'danger',
                'message' => 'Format file paraf tidak valid. Gunakan JPEG, PNG, atau PDF.',
            ];
            header('Location: ' . BASE_URL . '/atasan/review.php?id=' . $reimbId);
            exit;
        }

        // Pastikan direktori tujuan ada
        $sigDir = __DIR__ . '/../../uploads/signatures/';
        if (!is_dir($sigDir)) {
            mkdir($sigDir, 0755, true);
        }

        // Simpan file paraf dengan nama unik
        $safeName  = sprintf(
            'paraf_%d_%d_%s.%s',
            $reimbId,
            $userId,
            date('YmdHis'),
            $origExt
        );
        $destPath  = $sigDir . $safeName;
        $sigDbPath = 'uploads/signatures/' . $safeName;

        if (!move_uploaded_file($tmpPath, $destPath)) {
            $_SESSION['flash'] = [
                'type'    => 'danger',
                'message' => 'Gagal menyimpan file paraf. Silakan coba lagi.',
            ];
            header('Location: ' . BASE_URL . '/atasan/review.php?id=' . $reimbId);
            exit;
        }

        try {
            $db->beginTransaction();

            // INSERT ke tabel approvals
            $stmtApproval = $db->prepare(
                "INSERT INTO approvals
                     (reimbursement_id, level, approver_id, decision, comment, digital_signature, acted_at)
                 VALUES
                     (:reimbursement_id, 'atasan', :approver_id, 'disetujui', NULL, :digital_signature, NOW())"
            );
            $stmtApproval->execute([
                ':reimbursement_id'  => $reimbId,
                ':approver_id'       => $userId,
                ':digital_signature' => $sigDbPath,
            ]);
            $approvalId = (int) $db->lastInsertId();

            // Update status → review_finance
            // (updateReimbursementStatus sudah mengirim notifikasi ke finance via _sendStatusNotifications)
            updateReimbursementStatus(
                $reimbId,
                'review_finance',
                $userId,
                'Disetujui oleh atasan. Pengajuan diteruskan ke Finance.'
            );

            // Notifikasi eksplisit ke semua finance (sesuai task spec)
            $stmtFinance = $db->prepare(
                "SELECT id FROM users WHERE role = 'finance' AND is_active = 1"
            );
            $stmtFinance->execute();
            foreach ($stmtFinance->fetchAll() as $financeUser) {
                createNotification(
                    (int) $financeUser['id'],
                    $reimbId,
                    'Pengajuan Disetujui Atasan — Perlu Verifikasi Finance',
                    'Pengajuan reimbursement ' .
                    htmlspecialchars($reimb['reimbursement_no'], ENT_QUOTES, 'UTF-8') .
                    ' telah disetujui atasan dan menunggu verifikasi Finance.'
                );
            }

            // Audit log
            logAudit($userId, 'SAVE_APPROVAL', 'approvals', $approvalId, [
                'reimbursement_id'  => $reimbId,
                'level'             => 'atasan',
                'decision'          => 'disetujui',
                'digital_signature' => $sigDbPath,
            ]);

            $db->commit();

        } catch (\Exception $e) {
            $db->rollBack();
            // Hapus file yang sudah terupload jika transaksi gagal
            if (file_exists($destPath)) {
                unlink($destPath);
            }
            $_SESSION['flash'] = [
                'type'    => 'danger',
                'message' => 'Gagal memproses persetujuan: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'),
            ];
            header('Location: ' . BASE_URL . '/atasan/review.php?id=' . $reimbId);
            exit;
        }

        $_SESSION['flash'] = [
            'type'    => 'success',
            'message' => 'Pengajuan berhasil disetujui dan diteruskan ke Finance.',
        ];
        header('Location: ' . BASE_URL . '/atasan/index.php');
        exit;
    }

    // ── AKSI: TOLAK ───────────────────────────────────────────────────────────
    if ($action === 'tolak') {

        $alasan = trim($_POST['alasan'] ?? '');

        // Validasi: alasan wajib diisi
        if ($alasan === '') {
            $_SESSION['flash'] = [
                'type'    => 'danger',
                'message' => 'Alasan penolakan wajib diisi.',
            ];
            header('Location: ' . BASE_URL . '/atasan/review.php?id=' . $reimbId);
            exit;
        }

        try {
            $db->beginTransaction();

            // INSERT ke tabel approvals
            $stmtApproval = $db->prepare(
                "INSERT INTO approvals
                     (reimbursement_id, level, approver_id, decision, comment, digital_signature, acted_at)
                 VALUES
                     (:reimbursement_id, 'atasan', :approver_id, 'ditolak', :comment, NULL, NOW())"
            );
            $stmtApproval->execute([
                ':reimbursement_id' => $reimbId,
                ':approver_id'      => $userId,
                ':comment'          => $alasan,
            ]);
            $approvalId = (int) $db->lastInsertId();

            // Update status → ditolak
            // (updateReimbursementStatus mengirim notifikasi ke pegawai via _sendStatusNotifications)
            updateReimbursementStatus(
                $reimbId,
                'ditolak',
                $userId,
                'Ditolak oleh atasan. Alasan: ' . $alasan
            );

            // Notifikasi eksplisit ke pegawai pengaju (sesuai task spec)
            createNotification(
                (int) $reimb['user_id'],
                $reimbId,
                'Pengajuan Reimbursement Ditolak',
                'Pengajuan reimbursement ' .
                htmlspecialchars($reimb['reimbursement_no'], ENT_QUOTES, 'UTF-8') .
                ' Anda ditolak oleh atasan. Alasan: ' .
                htmlspecialchars($alasan, ENT_QUOTES, 'UTF-8')
            );

            // Audit log
            logAudit($userId, 'SAVE_APPROVAL', 'approvals', $approvalId, [
                'reimbursement_id' => $reimbId,
                'level'            => 'atasan',
                'decision'         => 'ditolak',
                'comment'          => $alasan,
            ]);

            $db->commit();

        } catch (\Exception $e) {
            $db->rollBack();
            $_SESSION['flash'] = [
                'type'    => 'danger',
                'message' => 'Gagal memproses penolakan: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'),
            ];
            header('Location: ' . BASE_URL . '/atasan/review.php?id=' . $reimbId);
            exit;
        }

        $_SESSION['flash'] = [
            'type'    => 'success',
            'message' => 'Pengajuan berhasil ditolak.',
        ];
        header('Location: ' . BASE_URL . '/atasan/index.php');
        exit;
    }

    // ── AKSI: MINTA REVISI ────────────────────────────────────────────────────
    if ($action === 'minta_revisi') {

        $catatan = trim($_POST['catatan_revisi'] ?? '');

        // Validasi: catatan wajib diisi
        if ($catatan === '') {
            $_SESSION['flash'] = [
                'type'    => 'danger',
                'message' => 'Catatan revisi wajib diisi.',
            ];
            header('Location: ' . BASE_URL . '/atasan/review.php?id=' . $reimbId);
            exit;
        }

        try {
            $db->beginTransaction();

            // INSERT ke tabel revision_requests
            $stmtRevision = $db->prepare(
                "INSERT INTO revision_requests
                     (reimbursement_id, item_id, requested_by, message, status)
                 VALUES
                     (:reimbursement_id, NULL, :requested_by, :message, 'terbuka')"
            );
            $stmtRevision->execute([
                ':reimbursement_id' => $reimbId,
                ':requested_by'     => $userId,
                ':message'          => $catatan,
            ]);
            $revisionId = (int) $db->lastInsertId();

            // Update status → perlu_revisi
            updateReimbursementStatus(
                $reimbId,
                'perlu_revisi',
                $userId,
                'Revisi diminta oleh atasan. Catatan: ' . $catatan
            );

            // Notifikasi ke pegawai pengaju dengan isi catatan (sesuai task spec)
            createNotification(
                (int) $reimb['user_id'],
                $reimbId,
                'Pengajuan Membutuhkan Revisi',
                'Atasan meminta revisi pada pengajuan reimbursement ' .
                htmlspecialchars($reimb['reimbursement_no'], ENT_QUOTES, 'UTF-8') .
                '. Catatan: ' .
                htmlspecialchars($catatan, ENT_QUOTES, 'UTF-8')
            );

            // Audit log
            logAudit($userId, 'CREATE_REVISION', 'revision_requests', $revisionId, [
                'reimbursement_id' => $reimbId,
                'requested_by'     => $userId,
                'message'          => $catatan,
            ]);

            $db->commit();

        } catch (\Exception $e) {
            $db->rollBack();
            $_SESSION['flash'] = [
                'type'    => 'danger',
                'message' => 'Gagal memproses permintaan revisi: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'),
            ];
            header('Location: ' . BASE_URL . '/atasan/review.php?id=' . $reimbId);
            exit;
        }

        $_SESSION['flash'] = [
            'type'    => 'success',
            'message' => 'Permintaan revisi berhasil dikirim ke pegawai.',
        ];
        header('Location: ' . BASE_URL . '/atasan/index.php');
        exit;
    }
}

// =============================================================================
// DATA UNTUK TAMPILAN
// =============================================================================

// Item-item biaya beserta kategori, struk, dan data OCR
$stmtItems = $db->prepare(
    "SELECT
         ri.id,
         ri.category_id,
         ri.expense_date,
         ri.merchant,
         ri.description,
         ri.amount,
         ri.approved_amount,
         ri.verification_status,
         ri.verified_at,
         ec.name        AS category_name,
         ec.max_amount  AS category_max
     FROM reimbursement_items ri
     LEFT JOIN expense_categories ec ON ec.id = ri.category_id
     WHERE ri.reimbursement_id = :rid
     ORDER BY ri.expense_date ASC, ri.id ASC"
);
$stmtItems->execute([':rid' => $reimbId]);
$items = $stmtItems->fetchAll();

// Kumpulkan receipt data (foto + OCR) per item
$itemIds = array_column($items, 'id');
$receiptsMap = [];

if (!empty($itemIds)) {
    $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
    $stmtReceipts = $db->prepare(
        "SELECT
             id,
             item_id,
             file_path,
             file_type,
             ocr_status,
             ocr_merchant,
             ocr_date,
             ocr_total,
             ocr_confidence,
             uploaded_at
         FROM receipts
         WHERE item_id IN ($placeholders)
         ORDER BY item_id ASC, id ASC"
    );
    $stmtReceipts->execute($itemIds);
    foreach ($stmtReceipts->fetchAll() as $receipt) {
        $receiptsMap[(int) $receipt['item_id']][] = $receipt;
    }
}

// Riwayat status
$history = getStatusHistory($reimbId);

// Hitung total dari items
$totalAmount = array_sum(array_column($items, 'amount'));

// ─────────────────────────────────────────────────────────────────────────────
$pageTitle = 'Review Pengajuan ' . htmlspecialchars($reimb['reimbursement_no'] ?? '', ENT_QUOTES, 'UTF-8');
require_once __DIR__ . '/../../includes/layout/header.php';
require_once __DIR__ . '/../../includes/layout/navbar.php';
?>

<!-- Breadcrumb -->
<nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb">
        <li class="breadcrumb-item">
            <a href="<?= BASE_URL ?>/atasan/index.php">Review Pengajuan</a>
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
            <i class="bi bi-clipboard-check me-2 text-primary"></i>
            <?= htmlspecialchars($reimb['reimbursement_no'] ?? '', ENT_QUOTES, 'UTF-8') ?>
        </h1>
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <?= getStatusLabel($reimb['status']) ?>
            <span class="text-muted small">
                Pegawai:
                <strong><?= htmlspecialchars($reimb['pegawai_name'] ?? '—', ENT_QUOTES, 'UTF-8') ?></strong>
            </span>
            <?php if (!empty($reimb['submitted_at'])): ?>
            <span class="text-muted small">
                Diajukan: <?= htmlspecialchars(date('d M Y H:i', strtotime($reimb['submitted_at'])), ENT_QUOTES, 'UTF-8') ?>
            </span>
            <?php endif; ?>
        </div>
    </div>
    <a href="<?= BASE_URL ?>/atasan/index.php" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i>Kembali
    </a>
</div>

<div class="row g-4">

    <!-- ================================================================
         Kolom kiri: Info perjalanan + Item biaya
         ================================================================ -->
    <div class="col-lg-8">

        <!-- Info Perjalanan Dinas -->
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-white fw-semibold py-3">
                <i class="bi bi-geo-alt me-2 text-primary"></i>Informasi Perjalanan Dinas
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-sm-6">
                        <p class="text-muted small mb-0">Tujuan</p>
                        <p class="fw-semibold mb-0">
                            <?= htmlspecialchars($reimb['destination'] ?? '—', ENT_QUOTES, 'UTF-8') ?>
                        </p>
                    </div>
                    <div class="col-sm-6">
                        <p class="text-muted small mb-0">Keperluan</p>
                        <p class="fw-semibold mb-0">
                            <?= htmlspecialchars($reimb['purpose'] ?? '—', ENT_QUOTES, 'UTF-8') ?>
                        </p>
                    </div>
                    <div class="col-sm-6">
                        <p class="text-muted small mb-0">Tanggal Mulai</p>
                        <p class="fw-semibold mb-0">
                            <?= !empty($reimb['start_date'])
                                ? htmlspecialchars(date('d M Y', strtotime($reimb['start_date'])), ENT_QUOTES, 'UTF-8')
                                : '—' ?>
                        </p>
                    </div>
                    <div class="col-sm-6">
                        <p class="text-muted small mb-0">Tanggal Selesai</p>
                        <p class="fw-semibold mb-0">
                            <?= !empty($reimb['end_date'])
                                ? htmlspecialchars(date('d M Y', strtotime($reimb['end_date'])), ENT_QUOTES, 'UTF-8')
                                : '—' ?>
                        </p>
                    </div>
                    <?php if (!empty($reimb['notes'])): ?>
                    <div class="col-12">
                        <p class="text-muted small mb-0">Catatan Pegawai</p>
                        <p class="mb-0"><?= htmlspecialchars($reimb['notes'], ENT_QUOTES, 'UTF-8') ?></p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Daftar Item Biaya -->
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-white d-flex justify-content-between align-items-center py-3">
                <span class="fw-semibold">
                    <i class="bi bi-receipt me-2 text-primary"></i>Item Biaya
                    <span class="badge bg-secondary ms-1"><?= count($items) ?></span>
                </span>
                <span class="fw-semibold text-primary">
                    Total: <?= htmlspecialchars(formatRupiah($totalAmount), ENT_QUOTES, 'UTF-8') ?>
                </span>
            </div>

            <?php if (empty($items)): ?>
            <div class="card-body text-center py-4 text-muted">
                <i class="bi bi-inbox fs-2 d-block mb-2"></i>
                Tidak ada item biaya pada pengajuan ini.
            </div>
            <?php else: ?>

            <!-- Accordion item biaya -->
            <div class="accordion accordion-flush" id="accordionItems">
                <?php foreach ($items as $idx => $item): ?>
                <?php $itemReceipts = $receiptsMap[(int) $item['id']] ?? []; ?>
                <div class="accordion-item">
                    <h2 class="accordion-header" id="heading-item-<?= (int) $item['id'] ?>">
                        <button
                            class="accordion-button <?= $idx > 0 ? 'collapsed' : '' ?>"
                            type="button"
                            data-bs-toggle="collapse"
                            data-bs-target="#collapse-item-<?= (int) $item['id'] ?>"
                            aria-expanded="<?= $idx === 0 ? 'true' : 'false' ?>"
                            aria-controls="collapse-item-<?= (int) $item['id'] ?>"
                        >
                            <div class="d-flex justify-content-between align-items-center w-100 me-3 flex-wrap gap-1">
                                <div>
                                    <span class="fw-semibold">
                                        <?= htmlspecialchars($item['description'], ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                    <span class="badge bg-light text-dark border ms-2 small">
                                        <?= htmlspecialchars($item['category_name'] ?? '—', ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                </div>
                                <div class="d-flex align-items-center gap-2">
                                    <span class="fw-bold text-primary text-nowrap">
                                        <?= htmlspecialchars(formatRupiah((float) $item['amount']), ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                    <?php
                                    $vs = $item['verification_status'] ?? 'belum';
                                    $vsMap = [
                                        'belum'        => ['label' => 'Belum Diverifikasi', 'class' => 'bg-secondary'],
                                        'diverifikasi' => ['label' => 'Diverifikasi',        'class' => 'bg-success'],
                                        'ditolak'      => ['label' => 'Ditolak',             'class' => 'bg-danger'],
                                    ];
                                    $vsEntry = $vsMap[$vs] ?? ['label' => htmlspecialchars($vs, ENT_QUOTES, 'UTF-8'), 'class' => 'bg-secondary'];
                                    ?>
                                    <span class="badge <?= $vsEntry['class'] ?> small">
                                        <?= htmlspecialchars($vsEntry['label'], ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                    <?php if (!empty($itemReceipts)): ?>
                                    <span class="badge bg-info text-dark small">
                                        <i class="bi bi-image me-1"></i><?= count($itemReceipts) ?> struk
                                    </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </button>
                    </h2>
                    <div
                        id="collapse-item-<?= (int) $item['id'] ?>"
                        class="accordion-collapse collapse <?= $idx === 0 ? 'show' : '' ?>"
                        aria-labelledby="heading-item-<?= (int) $item['id'] ?>"
                        data-bs-parent="#accordionItems"
                    >
                        <div class="accordion-body">
                            <!-- Detail item -->
                            <div class="row g-3 mb-3">
                                <div class="col-sm-4">
                                    <p class="text-muted small mb-0">Tanggal</p>
                                    <p class="fw-medium mb-0">
                                        <?= !empty($item['expense_date'])
                                            ? htmlspecialchars(date('d M Y', strtotime($item['expense_date'])), ENT_QUOTES, 'UTF-8')
                                            : '—' ?>
                                    </p>
                                </div>
                                <div class="col-sm-4">
                                    <p class="text-muted small mb-0">Merchant</p>
                                    <p class="fw-medium mb-0">
                                        <?= htmlspecialchars($item['merchant'] ?? '—', ENT_QUOTES, 'UTF-8') ?>
                                    </p>
                                </div>
                                <div class="col-sm-4">
                                    <p class="text-muted small mb-0">Nominal Diajukan</p>
                                    <p class="fw-bold text-primary mb-0">
                                        <?= htmlspecialchars(formatRupiah((float) $item['amount']), ENT_QUOTES, 'UTF-8') ?>
                                    </p>
                                    <?php if (!empty($item['category_max']) && (float)$item['amount'] > (float)$item['category_max']): ?>
                                    <span class="badge bg-warning text-dark small">
                                        <i class="bi bi-exclamation-triangle me-1"></i>Melebihi batas
                                        (maks. <?= htmlspecialchars(formatRupiah((float) $item['category_max']), ENT_QUOTES, 'UTF-8') ?>)
                                    </span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Foto Struk -->
                            <?php if (!empty($itemReceipts)): ?>
                            <div class="mb-3">
                                <p class="fw-semibold small mb-2">
                                    <i class="bi bi-images me-1 text-primary"></i>Foto Struk
                                </p>
                                <div class="row g-2">
                                    <?php foreach ($itemReceipts as $receipt): ?>
                                    <div class="col-sm-6 col-md-4">
                                        <div class="card h-100 border">
                                            <?php
                                            $receiptUrl = BASE_URL . '/../' . htmlspecialchars($receipt['file_path'], ENT_QUOTES, 'UTF-8');
                                            $isPdf = strtolower(pathinfo($receipt['file_path'], PATHINFO_EXTENSION)) === 'pdf';
                                            ?>
                                            <?php if ($isPdf): ?>
                                                <div class="card-body text-center py-4">
                                                    <i class="bi bi-file-earmark-pdf fs-1 text-danger"></i>
                                                    <p class="small mt-2 mb-0">
                                                        <a href="<?= $receiptUrl ?>" target="_blank" rel="noopener noreferrer">
                                                            Lihat PDF
                                                        </a>
                                                    </p>
                                                </div>
                                            <?php else: ?>
                                                <a href="<?= $receiptUrl ?>" target="_blank" rel="noopener noreferrer">
                                                    <img
                                                        src="<?= $receiptUrl ?>"
                                                        class="card-img-top"
                                                        alt="Struk <?= htmlspecialchars($item['description'], ENT_QUOTES, 'UTF-8') ?>"
                                                        style="object-fit:cover; height:140px;"
                                                    >
                                                </a>
                                            <?php endif; ?>

                                            <!-- Data OCR -->
                                            <div class="card-body p-2">
                                                <p class="fw-semibold small mb-1 text-muted">
                                                    <i class="bi bi-cpu me-1"></i>Data OCR
                                                </p>
                                                <?php
                                                $ocrStatus = $receipt['ocr_status'] ?? 'pending';
                                                $ocrBadge  = [
                                                    'pending' => ['label' => 'Pending',  'class' => 'bg-secondary'],
                                                    'sukses'  => ['label' => 'Sukses',   'class' => 'bg-success'],
                                                    'gagal'   => ['label' => 'Gagal',    'class' => 'bg-danger'],
                                                    'buram'   => ['label' => 'Buram',    'class' => 'bg-warning text-dark'],
                                                ];
                                                $ocrEntry  = $ocrBadge[$ocrStatus] ?? ['label' => htmlspecialchars($ocrStatus, ENT_QUOTES, 'UTF-8'), 'class' => 'bg-secondary'];
                                                ?>
                                                <div class="mb-1">
                                                    <span class="badge <?= $ocrEntry['class'] ?> small">
                                                        <?= htmlspecialchars($ocrEntry['label'], ENT_QUOTES, 'UTF-8') ?>
                                                    </span>
                                                    <?php if ($receipt['ocr_confidence'] !== null): ?>
                                                    <span class="small text-muted ms-1">
                                                        (<?= htmlspecialchars(number_format((float)$receipt['ocr_confidence'], 1), ENT_QUOTES, 'UTF-8') ?>%)
                                                    </span>
                                                    <?php endif; ?>
                                                </div>
                                                <table class="table table-sm mb-0" style="font-size:0.75rem;">
                                                    <tbody>
                                                        <tr>
                                                            <td class="text-muted pe-2">Merchant</td>
                                                            <td class="fw-medium">
                                                                <?= htmlspecialchars($receipt['ocr_merchant'] ?? '—', ENT_QUOTES, 'UTF-8') ?>
                                                            </td>
                                                        </tr>
                                                        <tr>
                                                            <td class="text-muted pe-2">Tanggal</td>
                                                            <td>
                                                                <?= !empty($receipt['ocr_date'])
                                                                    ? htmlspecialchars(date('d M Y', strtotime($receipt['ocr_date'])), ENT_QUOTES, 'UTF-8')
                                                                    : '—' ?>
                                                            </td>
                                                        </tr>
                                                        <tr>
                                                            <td class="text-muted pe-2">Total OCR</td>
                                                            <td class="fw-medium">
                                                                <?= !empty($receipt['ocr_total'])
                                                                    ? htmlspecialchars(formatRupiah((float) $receipt['ocr_total']), ENT_QUOTES, 'UTF-8')
                                                                    : '—' ?>
                                                            </td>
                                                        </tr>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php else: ?>
                            <div class="alert alert-light border small py-2 mb-0">
                                <i class="bi bi-image text-muted me-1"></i>
                                Tidak ada foto struk yang diunggah untuk item ini.
                            </div>
                            <?php endif; ?>

                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Footer total -->
            <div class="card-footer bg-white d-flex justify-content-end">
                <span class="fw-bold text-primary">
                    Total Pengajuan: <?= htmlspecialchars(formatRupiah($totalAmount), ENT_QUOTES, 'UTF-8') ?>
                </span>
            </div>
            <?php endif; ?>
        </div>

    </div><!-- /.col-lg-8 -->

    <!-- ================================================================
         Kolom kanan: Riwayat status + Panel aksi
         ================================================================ -->
    <div class="col-lg-4">

        <!-- Riwayat Status -->
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-white fw-semibold py-3">
                <i class="bi bi-clock-history me-2 text-primary"></i>Riwayat Status
            </div>
            <div class="card-body p-3">
                <?php if (empty($history)): ?>
                    <p class="text-muted small mb-0">Belum ada riwayat status.</p>
                <?php else: ?>
                <ul class="list-unstyled mb-0">
                    <?php foreach ($history as $hist): ?>
                    <li class="d-flex gap-2 mb-3">
                        <div class="flex-shrink-0 mt-1">
                            <span class="badge bg-secondary rounded-pill"
                                  style="width:10px; height:10px; display:inline-block; padding:0;"></span>
                        </div>
                        <div>
                            <div class="d-flex align-items-center gap-1 flex-wrap">
                                <?php if ($hist['old_status']): ?>
                                    <?= getStatusLabel($hist['old_status']) ?>
                                    <i class="bi bi-arrow-right small text-muted"></i>
                                <?php endif; ?>
                                <?= getStatusLabel($hist['new_status']) ?>
                            </div>
                            <div class="text-muted small mt-1">
                                <?php if (!empty($hist['actor_name'])): ?>
                                <span class="fw-medium">
                                    <?= htmlspecialchars($hist['actor_name'], ENT_QUOTES, 'UTF-8') ?>
                                </span>
                                &mdash;
                                <?php endif; ?>
                                <?= htmlspecialchars(
                                    date('d M Y H:i', strtotime($hist['changed_at'])),
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>
                            </div>
                            <?php if (!empty($hist['note'])): ?>
                            <div class="text-muted small fst-italic">
                                <?= htmlspecialchars($hist['note'], ENT_QUOTES, 'UTF-8') ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>
        </div>

        <!-- Panel Aksi Atasan -->
        <div class="card shadow-sm sticky-top" style="top: 80px;">
            <div class="card-header bg-primary text-white fw-semibold py-3">
                <i class="bi bi-person-check me-2"></i>Aksi Atasan
            </div>
            <div class="card-body p-3">

                <!-- ── SETUJUI ───────────────────────────────────────────── -->
                <div class="mb-4">
                    <h6 class="fw-semibold text-success mb-2">
                        <i class="bi bi-check-circle me-1"></i>Setujui Pengajuan
                    </h6>
                    <form
                        method="POST"
                        action="<?= BASE_URL ?>/atasan/review.php?id=<?= $reimbId ?>"
                        enctype="multipart/form-data"
                        id="form-setujui"
                        onsubmit="return confirmSetujui()"
                    >
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="setujui">
                        <div class="mb-2">
                            <label for="paraf" class="form-label small fw-medium">
                                Paraf Digital <span class="text-danger">*</span>
                            </label>
                            <input
                                type="file"
                                id="paraf"
                                name="paraf"
                                class="form-control form-control-sm"
                                accept=".jpg,.jpeg,.png,.pdf"
                                required
                            >
                            <div class="form-text">Format: JPEG, PNG, atau PDF</div>
                        </div>
                        <button type="submit" class="btn btn-success btn-sm w-100">
                            <i class="bi bi-check-lg me-1"></i>Setujui & Teruskan ke Finance
                        </button>
                    </form>
                </div>

                <hr>

                <!-- ── MINTA REVISI ──────────────────────────────────────── -->
                <div class="mb-4">
                    <h6 class="fw-semibold text-warning mb-2">
                        <i class="bi bi-pencil-square me-1"></i>Minta Revisi
                    </h6>
                    <form
                        method="POST"
                        action="<?= BASE_URL ?>/atasan/review.php?id=<?= $reimbId ?>"
                        id="form-revisi"
                    >
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="minta_revisi">
                        <div class="mb-2">
                            <label for="catatan_revisi" class="form-label small fw-medium">
                                Catatan Revisi <span class="text-danger">*</span>
                            </label>
                            <textarea
                                id="catatan_revisi"
                                name="catatan_revisi"
                                class="form-control form-control-sm"
                                rows="3"
                                placeholder="Jelaskan apa yang perlu direvisi…"
                                required
                                maxlength="1000"
                            ></textarea>
                        </div>
                        <button type="submit" class="btn btn-warning btn-sm w-100">
                            <i class="bi bi-arrow-counterclockwise me-1"></i>Kirim Permintaan Revisi
                        </button>
                    </form>
                </div>

                <hr>

                <!-- ── TOLAK ─────────────────────────────────────────────── -->
                <div>
                    <h6 class="fw-semibold text-danger mb-2">
                        <i class="bi bi-x-circle me-1"></i>Tolak Pengajuan
                    </h6>
                    <form
                        method="POST"
                        action="<?= BASE_URL ?>/atasan/review.php?id=<?= $reimbId ?>"
                        id="form-tolak"
                        onsubmit="return confirmTolak()"
                    >
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="tolak">
                        <div class="mb-2">
                            <label for="alasan" class="form-label small fw-medium">
                                Alasan Penolakan <span class="text-danger">*</span>
                            </label>
                            <textarea
                                id="alasan"
                                name="alasan"
                                class="form-control form-control-sm"
                                rows="3"
                                placeholder="Berikan alasan penolakan yang jelas…"
                                required
                                maxlength="1000"
                            ></textarea>
                        </div>
                        <button type="submit" class="btn btn-danger btn-sm w-100">
                            <i class="bi bi-x-lg me-1"></i>Tolak Pengajuan
                        </button>
                    </form>
                </div>

            </div><!-- /.card-body -->
        </div><!-- /.card -->

    </div><!-- /.col-lg-4 -->

</div><!-- /.row -->

<script>
(function () {
    'use strict';

    /**
     * Konfirmasi sebelum menyetujui pengajuan.
     * @returns {boolean}
     */
    function confirmSetujui() {
        var fileInput = document.getElementById('paraf');
        if (!fileInput || !fileInput.files || fileInput.files.length === 0) {
            alert('Paraf digital wajib dilampirkan untuk menyetujui pengajuan.');
            return false;
        }
        return confirm(
            'Anda yakin ingin menyetujui pengajuan ini?\n' +
            'Pengajuan akan diteruskan ke tim Finance.'
        );
    }

    /**
     * Konfirmasi sebelum menolak pengajuan.
     * @returns {boolean}
     */
    function confirmTolak() {
        var alasan = document.getElementById('alasan');
        if (!alasan || alasan.value.trim() === '') {
            alert('Alasan penolakan wajib diisi.');
            return false;
        }
        return confirm(
            'Anda yakin ingin MENOLAK pengajuan ini?\n' +
            'Tindakan ini tidak dapat dibatalkan.'
        );
    }

    // Expose ke global scope untuk atribut onsubmit inline
    window.confirmSetujui = confirmSetujui;
    window.confirmTolak   = confirmTolak;
}());
</script>

<?php require_once __DIR__ . '/../../includes/layout/footer.php'; ?>
