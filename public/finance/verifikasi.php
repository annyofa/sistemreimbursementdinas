<?php

/**
 * public/finance/verifikasi.php
 *
 * Halaman verifikasi lengkap pengajuan reimbursement oleh finance.
 *
 * Fitur:
 *   - Tabel item biaya: Deskripsi | Nominal Input | Total OCR | Selisih | Status Verifikasi
 *   - Indikator visual selisih > 5% antara nominal input vs OCR
 *   - Per item: tombol radio diverifikasi/ditolak + field approved_amount (AJAX ke api/item_verify.php)
 *   - Tampilan foto struk dan data OCR per item (Requirement 3.7)
 *   - Aksi Kirim Klarifikasi (POST): INSERT revision_requests → perlu_revisi → notifikasi pegawai
 *   - Aksi Setujui Pengajuan (POST): canFinanceApprove() → INSERT approvals → disetujui → notifikasi kasir
 *   - Aksi Tolak Pengajuan (POST): isi alasan → INSERT approvals (ditolak) → notifikasi pegawai
 *
 * Requirements: 5.2, 5.3, 5.4, 5.5, 5.6, 5.7, 3.7
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireAuth();
requireRole(['finance']);

$user   = getCurrentUser();
$userId = (int) $user['user_id'];
$db     = getDB();

// ── Validasi parameter GET ?id= ───────────────────────────────────────────────
$reimbId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($reimbId <= 0) {
    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'ID pengajuan tidak valid.'];
    header('Location: ' . BASE_URL . '/finance/index.php');
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
         bt.destination,
         bt.purpose,
         bt.start_date,
         bt.end_date,
         u.name          AS pegawai_name,
         u.email         AS pegawai_email,
         d.name          AS dept_name
     FROM reimbursements r
     LEFT JOIN business_trips bt ON bt.id = r.trip_id
     JOIN users u ON u.id = r.user_id
     LEFT JOIN departments d ON d.id = u.department_id
     WHERE r.id = :id"
);
$stmtR->execute([':id' => $reimbId]);
$reimb = $stmtR->fetch();

if ($reimb === false) {
    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Pengajuan tidak ditemukan.'];
    header('Location: ' . BASE_URL . '/finance/index.php');
    exit;
}

// ── Cek status: hanya boleh verifikasi pengajuan berstatus review_finance ─────
if ($reimb['status'] !== 'review_finance') {
    $_SESSION['flash'] = [
        'type'    => 'warning',
        'message' => 'Pengajuan ini tidak dapat diverifikasi (status: ' .
                     htmlspecialchars($reimb['status'], ENT_QUOTES, 'UTF-8') . ').',
    ];
    header('Location: ' . BASE_URL . '/finance/index.php');
    exit;
}

// =============================================================================
// POST HANDLERS
// =============================================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $action = trim($_POST['action'] ?? '');

    // ── AKSI: KIRIM KLARIFIKASI ───────────────────────────────────────────────
    if ($action === 'kirim_klarifikasi') {

        $catatan = trim($_POST['catatan_klarifikasi'] ?? '');

        if ($catatan === '') {
            $_SESSION['flash'] = [
                'type'    => 'danger',
                'message' => 'Catatan klarifikasi wajib diisi.',
            ];
            header('Location: ' . BASE_URL . '/finance/verifikasi.php?id=' . $reimbId);
            exit;
        }

        try {
            $db->beginTransaction();

            // INSERT ke revision_requests (requested_by = finance userId)
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
                'Klarifikasi diminta oleh finance. Catatan: ' . $catatan
            );

            // Notifikasi eksplisit ke pegawai pengaju
            createNotification(
                (int) $reimb['user_id'],
                $reimbId,
                'Finance Meminta Klarifikasi',
                'Finance meminta klarifikasi pada pengajuan reimbursement ' .
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
                'message' => 'Gagal mengirim klarifikasi: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'),
            ];
            header('Location: ' . BASE_URL . '/finance/verifikasi.php?id=' . $reimbId);
            exit;
        }

        $_SESSION['flash'] = [
            'type'    => 'success',
            'message' => 'Permintaan klarifikasi berhasil dikirim ke pegawai.',
        ];
        header('Location: ' . BASE_URL . '/finance/index.php');
        exit;
    }

    // ── AKSI: SETUJUI PENGAJUAN ───────────────────────────────────────────────
    if ($action === 'setujui') {

        // Periksa apakah semua item sudah diverifikasi
        if (!canFinanceApprove($reimbId)) {
            // Ambil daftar item yang belum diverifikasi untuk ditampilkan
            $stmtUnverif = $db->prepare(
                "SELECT ri.description, ec.name AS category_name
                 FROM reimbursement_items ri
                 LEFT JOIN expense_categories ec ON ec.id = ri.category_id
                 WHERE ri.reimbursement_id = :reimb_id
                   AND ri.verification_status = 'belum'"
            );
            $stmtUnverif->execute([':reimb_id' => $reimbId]);
            $unverifiedItems = $stmtUnverif->fetchAll();

            $unverifiedList = implode(', ', array_map(
                fn($i) => htmlspecialchars($i['description'], ENT_QUOTES, 'UTF-8'),
                $unverifiedItems
            ));

            $_SESSION['flash'] = [
                'type'    => 'danger',
                'message' => 'Tidak dapat menyetujui: masih ada item yang belum diverifikasi (' . $unverifiedList . ').',
            ];
            header('Location: ' . BASE_URL . '/finance/verifikasi.php?id=' . $reimbId);
            exit;
        }

        $komentar = trim($_POST['komentar_setujui'] ?? '');

        try {
            $db->beginTransaction();

            // INSERT ke tabel approvals (level=finance, decision=disetujui)
            $stmtApproval = $db->prepare(
                "INSERT INTO approvals
                     (reimbursement_id, level, approver_id, decision, comment, digital_signature, acted_at)
                 VALUES
                     (:reimbursement_id, 'finance', :approver_id, 'disetujui', :comment, NULL, NOW())"
            );
            $stmtApproval->execute([
                ':reimbursement_id' => $reimbId,
                ':approver_id'      => $userId,
                ':comment'          => $komentar !== '' ? $komentar : null,
            ]);
            $approvalId = (int) $db->lastInsertId();

            // Update status → disetujui
            // (updateReimbursementStatus akan notifikasi kasir via _sendStatusNotifications)
            updateReimbursementStatus(
                $reimbId,
                'disetujui',
                $userId,
                'Disetujui oleh finance.'
            );

            // Notifikasi eksplisit ke semua kasir
            $stmtKasir = $db->prepare(
                "SELECT id FROM users WHERE role = 'kasir' AND is_active = 1"
            );
            $stmtKasir->execute();
            foreach ($stmtKasir->fetchAll() as $kasirUser) {
                createNotification(
                    (int) $kasirUser['id'],
                    $reimbId,
                    'Pengajuan Disetujui Finance — Siap Dibayar',
                    'Pengajuan reimbursement ' .
                    htmlspecialchars($reimb['reimbursement_no'], ENT_QUOTES, 'UTF-8') .
                    ' telah disetujui finance dan siap untuk pembayaran.'
                );
            }

            // Audit log
            logAudit($userId, 'SAVE_APPROVAL', 'approvals', $approvalId, [
                'reimbursement_id' => $reimbId,
                'level'            => 'finance',
                'decision'         => 'disetujui',
                'comment'          => $komentar,
            ]);

            $db->commit();

        } catch (\Exception $e) {
            $db->rollBack();
            $_SESSION['flash'] = [
                'type'    => 'danger',
                'message' => 'Gagal memproses persetujuan: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'),
            ];
            header('Location: ' . BASE_URL . '/finance/verifikasi.php?id=' . $reimbId);
            exit;
        }

        $_SESSION['flash'] = [
            'type'    => 'success',
            'message' => 'Pengajuan berhasil disetujui. Kasir akan menerima notifikasi untuk memproses pembayaran.',
        ];
        header('Location: ' . BASE_URL . '/finance/index.php');
        exit;
    }

    // ── AKSI: TOLAK PENGAJUAN ─────────────────────────────────────────────────
    if ($action === 'tolak') {

        $alasan = trim($_POST['alasan_tolak'] ?? '');

        // Validasi: alasan wajib diisi
        if ($alasan === '') {
            $_SESSION['flash'] = [
                'type'    => 'danger',
                'message' => 'Alasan penolakan wajib diisi.',
            ];
            header('Location: ' . BASE_URL . '/finance/verifikasi.php?id=' . $reimbId);
            exit;
        }

        try {
            $db->beginTransaction();

            // INSERT ke tabel approvals (level=finance, decision=ditolak)
            $stmtApproval = $db->prepare(
                "INSERT INTO approvals
                     (reimbursement_id, level, approver_id, decision, comment, digital_signature, acted_at)
                 VALUES
                     (:reimbursement_id, 'finance', :approver_id, 'ditolak', :comment, NULL, NOW())"
            );
            $stmtApproval->execute([
                ':reimbursement_id' => $reimbId,
                ':approver_id'      => $userId,
                ':comment'          => $alasan,
            ]);
            $approvalId = (int) $db->lastInsertId();

            // Update status → ditolak
            updateReimbursementStatus(
                $reimbId,
                'ditolak',
                $userId,
                'Ditolak oleh finance. Alasan: ' . $alasan
            );

            // Notifikasi eksplisit ke pegawai pengaju
            createNotification(
                (int) $reimb['user_id'],
                $reimbId,
                'Pengajuan Reimbursement Ditolak Finance',
                'Pengajuan reimbursement ' .
                htmlspecialchars($reimb['reimbursement_no'], ENT_QUOTES, 'UTF-8') .
                ' Anda ditolak oleh finance. Alasan: ' .
                htmlspecialchars($alasan, ENT_QUOTES, 'UTF-8')
            );

            // Audit log
            logAudit($userId, 'SAVE_APPROVAL', 'approvals', $approvalId, [
                'reimbursement_id' => $reimbId,
                'level'            => 'finance',
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
            header('Location: ' . BASE_URL . '/finance/verifikasi.php?id=' . $reimbId);
            exit;
        }

        $_SESSION['flash'] = [
            'type'    => 'success',
            'message' => 'Pengajuan berhasil ditolak. Pegawai akan menerima notifikasi.',
        ];
        header('Location: ' . BASE_URL . '/finance/index.php');
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
         ri.verified_by,
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
             ocr_status,
             ocr_raw_text,
             ocr_merchant,
             ocr_date,
             ocr_total,
             ocr_confidence
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

// Hitung jumlah item berdasarkan status verifikasi
$totalItems      = count($items);
$verifiedCount   = count(array_filter($items, fn($i) => $i['verification_status'] === 'diverifikasi'));
$rejectedCount   = count(array_filter($items, fn($i) => $i['verification_status'] === 'ditolak'));
$unverifiedCount = count(array_filter($items, fn($i) => $i['verification_status'] === 'belum'));

// ─────────────────────────────────────────────────────────────────────────────
$pageTitle = 'Verifikasi ' . htmlspecialchars($reimb['reimbursement_no'] ?? '', ENT_QUOTES, 'UTF-8');
require_once __DIR__ . '/../../includes/layout/header.php';
require_once __DIR__ . '/../../includes/layout/navbar.php';
?>

<!-- Breadcrumb -->
<nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb">
        <li class="breadcrumb-item">
            <a href="<?= BASE_URL ?>/finance/index.php">Verifikasi Pengajuan</a>
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
            <i class="bi bi-check2-square me-2 text-primary"></i>
            <?= htmlspecialchars($reimb['reimbursement_no'] ?? '', ENT_QUOTES, 'UTF-8') ?>
        </h1>
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <?= getStatusLabel($reimb['status']) ?>
            <span class="text-muted small">
                Pengaju: <strong><?= htmlspecialchars($reimb['pegawai_name'] ?? '—', ENT_QUOTES, 'UTF-8') ?></strong>
            </span>
            <span class="text-muted small">
                Dept: <strong><?= htmlspecialchars($reimb['dept_name'] ?? '—', ENT_QUOTES, 'UTF-8') ?></strong>
            </span>
            <?php if (!empty($reimb['submitted_at'])): ?>
            <span class="text-muted small">
                Diajukan: <?= htmlspecialchars(date('d M Y H:i', strtotime($reimb['submitted_at'])), ENT_QUOTES, 'UTF-8') ?>
            </span>
            <?php endif; ?>
        </div>
    </div>
    <a href="<?= BASE_URL ?>/finance/index.php" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i>Kembali
    </a>
</div>

<!-- Progress verifikasi item -->
<div class="card shadow-sm mb-4" id="progress-summary">
    <div class="card-body py-3">
        <div class="row g-3 text-center">
            <div class="col-sm-3">
                <div class="fs-4 fw-bold text-primary" data-count="total"><?= $totalItems ?></div>
                <div class="small text-muted">Total Item</div>
            </div>
            <div class="col-sm-3">
                <div class="fs-4 fw-bold text-success" data-count="diverifikasi"><?= $verifiedCount ?></div>
                <div class="small text-muted">Diverifikasi</div>
            </div>
            <div class="col-sm-3">
                <div class="fs-4 fw-bold text-danger" data-count="ditolak"><?= $rejectedCount ?></div>
                <div class="small text-muted">Ditolak</div>
            </div>
            <div class="col-sm-3">
                <div class="fs-4 fw-bold text-warning" data-count="belum"><?= $unverifiedCount ?></div>
                <div class="small text-muted">Belum Diverifikasi</div>
            </div>
        </div>
        <?php if ($totalItems > 0): ?>
        <div class="mt-3">
            <?php $pct = (int)(($verifiedCount + $rejectedCount) / $totalItems * 100); ?>
            <div class="d-flex justify-content-between small text-muted mb-1">
                <span>Progress Verifikasi</span>
                <span data-count="pct"><?= $pct ?>%</span>
            </div>
            <div class="progress" style="height: 8px;" role="progressbar" aria-valuenow="<?= $pct ?>" aria-valuemin="0" aria-valuemax="100">
                <div class="progress-bar bg-success" style="width: <?= $pct ?>%"></div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<div class="row g-4">

    <!-- ================================================================
         Kolom kiri: Info perjalanan + Tabel item biaya + Accordion struk
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
                        <p class="text-muted small mb-0">Catatan Pengajuan</p>
                        <p class="mb-0"><?= htmlspecialchars($reimb['notes'], ENT_QUOTES, 'UTF-8') ?></p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Tabel Perbandingan Item Biaya (Requirements 5.2, 3.7) -->
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-white d-flex justify-content-between align-items-center py-3">
                <span class="fw-semibold">
                    <i class="bi bi-table me-2 text-primary"></i>Tabel Verifikasi Item Biaya
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
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" id="tabel-verifikasi">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-3">Deskripsi</th>
                            <th class="text-end">Nominal Input</th>
                            <th class="text-end">Total OCR</th>
                            <th class="text-end">Selisih</th>
                            <th class="text-center">Status Verifikasi</th>
                            <th class="text-center">Aksi Verifikasi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $item): ?>
                        <?php
                        $itemId        = (int) $item['id'];
                        $itemReceipts  = $receiptsMap[$itemId] ?? [];

                        // Ambil total OCR dari struk terbaru yang punya ocr_total
                        $ocrTotal = null;
                        foreach ($itemReceipts as $rcpt) {
                            if (!empty($rcpt['ocr_total']) && (float)$rcpt['ocr_total'] > 0) {
                                $ocrTotal = (float) $rcpt['ocr_total'];
                                break;
                            }
                        }

                        $nominalInput   = (float) $item['amount'];
                        $approvedAmount = !empty($item['approved_amount']) ? (float) $item['approved_amount'] : null;

                        // Hitung selisih dan tentukan kelas visual
                        $selisihPct   = null;
                        $rowHighlight = '';
                        $selisihLabel = '—';
                        if ($ocrTotal !== null && $ocrTotal > 0) {
                            $selisihAbs = abs($nominalInput - $ocrTotal);
                            $selisihPct = ($selisihAbs / $ocrTotal) * 100;
                            $selisihLabel = htmlspecialchars(formatRupiah($nominalInput - $ocrTotal), ENT_QUOTES, 'UTF-8');
                            if ($selisihPct > 5) {
                                $rowHighlight = $selisihPct > 20 ? 'table-danger' : 'table-warning';
                            }
                        }

                        // Badge status verifikasi
                        $vsMap = [
                            'belum'        => ['label' => 'Belum',       'class' => 'bg-secondary'],
                            'diverifikasi' => ['label' => 'Diverifikasi','class' => 'bg-success'],
                            'ditolak'      => ['label' => 'Ditolak',     'class' => 'bg-danger'],
                        ];
                        $vsEntry = $vsMap[$item['verification_status']] ?? ['label' => htmlspecialchars($item['verification_status'], ENT_QUOTES, 'UTF-8'), 'class' => 'bg-secondary'];
                        ?>
                        <tr class="<?= $rowHighlight ?>" id="row-item-<?= $itemId ?>">
                            <!-- Deskripsi -->
                            <td class="ps-3">
                                <div class="fw-semibold">
                                    <?= htmlspecialchars($item['description'], ENT_QUOTES, 'UTF-8') ?>
                                </div>
                                <div class="text-muted small">
                                    <?= htmlspecialchars($item['category_name'] ?? '—', ENT_QUOTES, 'UTF-8') ?>
                                    <?php if (!empty($item['expense_date'])): ?>
                                    · <?= htmlspecialchars(date('d M Y', strtotime($item['expense_date'])), ENT_QUOTES, 'UTF-8') ?>
                                    <?php endif; ?>
                                </div>
                                <?php if (!empty($item['merchant'])): ?>
                                <div class="text-muted small">
                                    <i class="bi bi-shop me-1"></i><?= htmlspecialchars($item['merchant'], ENT_QUOTES, 'UTF-8') ?>
                                </div>
                                <?php endif; ?>
                                <!-- Link ke accordion detail struk -->
                                <a href="#collapse-item-<?= $itemId ?>"
                                   data-bs-toggle="collapse"
                                   class="small text-primary text-decoration-none mt-1 d-inline-block"
                                   aria-controls="collapse-item-<?= $itemId ?>">
                                    <i class="bi bi-images me-1"></i>Lihat Struk
                                    <?php if (!empty($itemReceipts)): ?>
                                    <span class="badge bg-info text-dark"><?= count($itemReceipts) ?></span>
                                    <?php endif; ?>
                                </a>
                            </td>

                            <!-- Nominal Input -->
                            <td class="text-end fw-semibold text-nowrap">
                                <?= htmlspecialchars(formatRupiah($nominalInput), ENT_QUOTES, 'UTF-8') ?>
                                <?php if ($approvedAmount !== null && $approvedAmount !== $nominalInput): ?>
                                <div class="small text-success">
                                    Disetujui: <?= htmlspecialchars(formatRupiah($approvedAmount), ENT_QUOTES, 'UTF-8') ?>
                                </div>
                                <?php endif; ?>
                            </td>

                            <!-- Total OCR -->
                            <td class="text-end text-nowrap">
                                <?php if ($ocrTotal !== null): ?>
                                    <span class="fw-medium">
                                        <?= htmlspecialchars(formatRupiah($ocrTotal), ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>

                            <!-- Selisih dengan indikator visual -->
                            <td class="text-end text-nowrap">
                                <?php if ($selisihPct !== null): ?>
                                    <span class="fw-medium <?= $selisihPct > 5 ? 'text-danger fw-bold' : 'text-muted' ?>">
                                        <?= $selisihLabel ?>
                                    </span>
                                    <div class="small <?= $selisihPct > 5 ? 'text-danger' : 'text-muted' ?>">
                                        <?= htmlspecialchars(number_format($selisihPct, 1), ENT_QUOTES, 'UTF-8') ?>%
                                        <?php if ($selisihPct > 5): ?>
                                        <i class="bi bi-exclamation-triangle-fill text-warning ms-1"
                                           title="Selisih melebihi 5%"></i>
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>

                            <!-- Status Verifikasi -->
                            <td class="text-center">
                                <span class="badge <?= $vsEntry['class'] ?>" id="badge-status-<?= $itemId ?>">
                                    <?= htmlspecialchars($vsEntry['label'], ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            </td>

                            <!-- Aksi Verifikasi (AJAX) -->
                            <td class="text-center" style="min-width: 220px;">
                                <div class="verify-form" data-item-id="<?= $itemId ?>">
                                    <!-- Radio: diverifikasi / ditolak -->
                                    <div class="d-flex justify-content-center gap-3 mb-2">
                                        <div class="form-check form-check-inline mb-0">
                                            <input
                                                class="form-check-input verify-radio"
                                                type="radio"
                                                name="status_<?= $itemId ?>"
                                                id="verif_<?= $itemId ?>"
                                                value="diverifikasi"
                                                data-item-id="<?= $itemId ?>"
                                                <?= $item['verification_status'] === 'diverifikasi' ? 'checked' : '' ?>
                                            >
                                            <label class="form-check-label text-success small" for="verif_<?= $itemId ?>">
                                                <i class="bi bi-check-circle me-1"></i>Verifikasi
                                            </label>
                                        </div>
                                        <div class="form-check form-check-inline mb-0">
                                            <input
                                                class="form-check-input verify-radio"
                                                type="radio"
                                                name="status_<?= $itemId ?>"
                                                id="tolak_<?= $itemId ?>"
                                                value="ditolak"
                                                data-item-id="<?= $itemId ?>"
                                                <?= $item['verification_status'] === 'ditolak' ? 'checked' : '' ?>
                                            >
                                            <label class="form-check-label text-danger small" for="tolak_<?= $itemId ?>">
                                                <i class="bi bi-x-circle me-1"></i>Tolak
                                            </label>
                                        </div>
                                    </div>
                                    <!-- Field approved_amount -->
                                    <div class="input-group input-group-sm mb-2">
                                        <span class="input-group-text" title="Nominal disetujui">Rp</span>
                                        <input
                                            type="number"
                                            class="form-control form-control-sm approved-amount-input"
                                            id="approved_<?= $itemId ?>"
                                            placeholder="Nominal disetujui"
                                            min="0"
                                            step="1"
                                            value="<?= $approvedAmount !== null ? (int) $approvedAmount : '' ?>"
                                            data-item-id="<?= $itemId ?>"
                                            aria-label="Nominal disetujui untuk <?= htmlspecialchars($item['description'], ENT_QUOTES, 'UTF-8') ?>"
                                        >
                                    </div>
                                    <!-- Tombol simpan per item -->
                                    <button
                                        type="button"
                                        class="btn btn-primary btn-sm btn-save-item w-100"
                                        data-item-id="<?= $itemId ?>"
                                        aria-label="Simpan verifikasi item <?= htmlspecialchars($item['description'], ENT_QUOTES, 'UTF-8') ?>"
                                    >
                                        <i class="bi bi-save me-1"></i>Simpan
                                    </button>
                                    <!-- Feedback per item -->
                                    <div class="item-feedback mt-1" id="feedback-<?= $itemId ?>"></div>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="table-light">
                        <tr>
                            <th class="ps-3">Total</th>
                            <th class="text-end">
                                <?= htmlspecialchars(formatRupiah($totalAmount), ENT_QUOTES, 'UTF-8') ?>
                            </th>
                            <th colspan="4"></th>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <!-- Accordion: Foto Struk dan Data OCR per Item (Requirement 3.7) -->
        <?php if (!empty($items)): ?>
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-white fw-semibold py-3">
                <i class="bi bi-images me-2 text-primary"></i>Foto Struk &amp; Data OCR per Item
            </div>
            <div class="accordion accordion-flush" id="accordionStruk">
                <?php foreach ($items as $idx => $item): ?>
                <?php
                $itemId       = (int) $item['id'];
                $itemReceipts = $receiptsMap[$itemId] ?? [];
                ?>
                <div class="accordion-item" id="accord-item-<?= $itemId ?>">
                    <h2 class="accordion-header" id="heading-struk-<?= $itemId ?>">
                        <button
                            class="accordion-button collapsed"
                            type="button"
                            data-bs-toggle="collapse"
                            data-bs-target="#collapse-item-<?= $itemId ?>"
                            aria-expanded="false"
                            aria-controls="collapse-item-<?= $itemId ?>"
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
                                    <?php if (!empty($itemReceipts)): ?>
                                    <span class="badge bg-info text-dark small">
                                        <i class="bi bi-image me-1"></i><?= count($itemReceipts) ?> struk
                                    </span>
                                    <?php else: ?>
                                    <span class="badge bg-secondary small">Tidak ada struk</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </button>
                    </h2>
                    <div
                        id="collapse-item-<?= $itemId ?>"
                        class="accordion-collapse collapse"
                        aria-labelledby="heading-struk-<?= $itemId ?>"
                    >
                        <div class="accordion-body">
                            <?php if (empty($itemReceipts)): ?>
                            <div class="alert alert-light border small py-2 mb-0">
                                <i class="bi bi-image text-muted me-1"></i>
                                Tidak ada foto struk yang diunggah untuk item ini.
                            </div>
                            <?php else: ?>
                            <div class="row g-3">
                                <?php foreach ($itemReceipts as $receipt): ?>
                                <div class="col-sm-6 col-md-4">
                                    <div class="card h-100 border">
                                        <?php
                                        $receiptUrl = BASE_URL . '/../' . htmlspecialchars($receipt['file_path'], ENT_QUOTES, 'UTF-8');
                                        $ext        = strtolower(pathinfo($receipt['file_path'], PATHINFO_EXTENSION));
                                        $isPdf      = $ext === 'pdf';
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
                                                style="object-fit:cover; height:160px;"
                                                loading="lazy"
                                            >
                                        </a>
                                        <?php endif; ?>

                                        <!-- Data OCR per struk -->
                                        <div class="card-body p-2">
                                            <p class="fw-semibold small mb-1 text-muted">
                                                <i class="bi bi-cpu me-1"></i>Data OCR
                                            </p>
                                            <?php
                                            $ocrStatus = $receipt['ocr_status'] ?? 'pending';
                                            $ocrBadges = [
                                                'pending' => ['label' => 'Pending',  'class' => 'bg-secondary'],
                                                'sukses'  => ['label' => 'Sukses',   'class' => 'bg-success'],
                                                'gagal'   => ['label' => 'Gagal',    'class' => 'bg-danger'],
                                                'buram'   => ['label' => 'Buram',    'class' => 'bg-warning text-dark'],
                                            ];
                                            $ocrEntry = $ocrBadges[$ocrStatus] ?? ['label' => htmlspecialchars($ocrStatus, ENT_QUOTES, 'UTF-8'), 'class' => 'bg-secondary'];
                                            ?>
                                            <div class="mb-2">
                                                <span class="badge <?= $ocrEntry['class'] ?> small">
                                                    <?= htmlspecialchars($ocrEntry['label'], ENT_QUOTES, 'UTF-8') ?>
                                                </span>
                                                <?php if ($receipt['ocr_confidence'] !== null): ?>
                                                <span class="small text-muted ms-1">
                                                    (<?= htmlspecialchars(number_format((float)$receipt['ocr_confidence'], 1), ENT_QUOTES, 'UTF-8') ?>%)
                                                </span>
                                                <?php if ((float)$receipt['ocr_confidence'] < 70): ?>
                                                <span class="badge bg-warning text-dark small ms-1">
                                                    <i class="bi bi-exclamation-triangle me-1"></i>Struk Buram
                                                </span>
                                                <?php endif; ?>
                                                <?php endif; ?>
                                            </div>
                                            <table class="table table-sm mb-0" style="font-size:0.75rem;">
                                                <tbody>
                                                    <tr>
                                                        <td class="text-muted pe-2 text-nowrap">Merchant</td>
                                                        <td class="fw-medium">
                                                            <?= htmlspecialchars($receipt['ocr_merchant'] ?? '—', ENT_QUOTES, 'UTF-8') ?>
                                                        </td>
                                                    </tr>
                                                    <tr>
                                                        <td class="text-muted pe-2 text-nowrap">Tanggal</td>
                                                        <td>
                                                            <?= !empty($receipt['ocr_date'])
                                                                ? htmlspecialchars(date('d M Y', strtotime($receipt['ocr_date'])), ENT_QUOTES, 'UTF-8')
                                                                : '—' ?>
                                                        </td>
                                                    </tr>
                                                    <tr>
                                                        <td class="text-muted pe-2 text-nowrap">Total OCR</td>
                                                        <td class="fw-medium">
                                                            <?= !empty($receipt['ocr_total'])
                                                                ? htmlspecialchars(formatRupiah((float) $receipt['ocr_total']), ENT_QUOTES, 'UTF-8')
                                                                : '—' ?>
                                                        </td>
                                                    </tr>
                                                    <?php if (!empty($receipt['ocr_raw_text'])): ?>
                                                    <tr>
                                                        <td class="text-muted pe-2 text-nowrap align-top">Raw OCR</td>
                                                        <td>
                                                            <details>
                                                                <summary class="small text-primary" style="cursor:pointer;">Lihat teks</summary>
                                                                <pre class="small mt-1 mb-0 p-1 bg-light rounded" style="white-space:pre-wrap;font-size:0.65rem;max-height:80px;overflow:auto;"><?= htmlspecialchars($receipt['ocr_raw_text'], ENT_QUOTES, 'UTF-8') ?></pre>
                                                            </details>
                                                        </td>
                                                    </tr>
                                                    <?php endif; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

    </div><!-- /.col-lg-8 -->

    <!-- ================================================================
         Kolom kanan: Riwayat status + Panel aksi finance
         ================================================================ -->
    <div class="col-lg-4">

        <!-- Riwayat Status -->
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-white fw-semibold py-3">
                <i class="bi bi-clock-history me-2 text-primary"></i>Riwayat Status
            </div>
            <div class="card-body p-3" style="max-height: 300px; overflow-y: auto;">
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

        <!-- Panel Aksi Finance -->
        <div class="card shadow-sm sticky-top" style="top: 80px;">
            <div class="card-header bg-primary text-white fw-semibold py-3">
                <i class="bi bi-check2-square me-2"></i>Aksi Finance
            </div>
            <div class="card-body p-3">

                <!-- ── SETUJUI PENGAJUAN ──────────────────────────────────────── -->
                <div class="mb-4">
                    <h6 class="fw-semibold text-success mb-2">
                        <i class="bi bi-check-circle me-1"></i>Setujui Pengajuan
                    </h6>
                    <?php if ($unverifiedCount > 0): ?>
                    <div class="alert alert-warning py-2 px-3 mb-2 small">
                        <i class="bi bi-exclamation-triangle me-1"></i>
                        Masih ada <strong><?= $unverifiedCount ?></strong> item belum diverifikasi.
                        Verifikasi semua item sebelum menyetujui.
                    </div>
                    <?php endif; ?>
                    <form
                        method="POST"
                        action="<?= BASE_URL ?>/finance/verifikasi.php?id=<?= $reimbId ?>"
                        id="form-setujui"
                        onsubmit="return confirmSetujui()"
                    >
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="setujui">
                        <div class="mb-2">
                            <label for="komentar_setujui" class="form-label small fw-medium">
                                Komentar (opsional)
                            </label>
                            <textarea
                                id="komentar_setujui"
                                name="komentar_setujui"
                                class="form-control form-control-sm"
                                rows="2"
                                placeholder="Komentar tambahan (opsional)…"
                                maxlength="500"
                            ></textarea>
                        </div>
                        <button
                            type="submit"
                            class="btn btn-success btn-sm w-100"
                            <?= $unverifiedCount > 0 ? 'disabled' : '' ?>
                        >
                            <i class="bi bi-check-lg me-1"></i>Setujui Pengajuan
                        </button>
                        <?php if ($unverifiedCount > 0): ?>
                        <div class="form-text text-warning">
                            Tombol akan aktif setelah semua item diverifikasi.
                        </div>
                        <?php endif; ?>
                    </form>
                </div>

                <hr>

                <!-- ── KIRIM KLARIFIKASI ─────────────────────────────────────── -->
                <div class="mb-4">
                    <h6 class="fw-semibold text-warning mb-2">
                        <i class="bi bi-chat-square-text me-1"></i>Kirim Klarifikasi
                    </h6>
                    <form
                        method="POST"
                        action="<?= BASE_URL ?>/finance/verifikasi.php?id=<?= $reimbId ?>"
                        id="form-klarifikasi"
                    >
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="kirim_klarifikasi">
                        <div class="mb-2">
                            <label for="catatan_klarifikasi" class="form-label small fw-medium">
                                Catatan Klarifikasi <span class="text-danger">*</span>
                            </label>
                            <textarea
                                id="catatan_klarifikasi"
                                name="catatan_klarifikasi"
                                class="form-control form-control-sm"
                                rows="3"
                                placeholder="Jelaskan apa yang perlu diklarifikasi pegawai…"
                                required
                                maxlength="1000"
                            ></textarea>
                        </div>
                        <button type="submit" class="btn btn-warning btn-sm w-100">
                            <i class="bi bi-send me-1"></i>Kirim ke Pegawai
                        </button>
                    </form>
                </div>

                <hr>

                <!-- ── TOLAK PENGAJUAN ───────────────────────────────────────── -->
                <div>
                    <h6 class="fw-semibold text-danger mb-2">
                        <i class="bi bi-x-circle me-1"></i>Tolak Pengajuan
                    </h6>
                    <form
                        method="POST"
                        action="<?= BASE_URL ?>/finance/verifikasi.php?id=<?= $reimbId ?>"
                        id="form-tolak"
                        onsubmit="return confirmTolak()"
                    >
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="tolak">
                        <div class="mb-2">
                            <label for="alasan_tolak" class="form-label small fw-medium">
                                Alasan Penolakan <span class="text-danger">*</span>
                            </label>
                            <textarea
                                id="alasan_tolak"
                                name="alasan_tolak"
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
        </div><!-- /.card Panel Aksi -->

    </div><!-- /.col-lg-4 -->

</div><!-- /.row -->

<script>
(function () {
    'use strict';

    // ── Ambil CSRF token dari meta tag ────────────────────────────────────────
    var csrfMeta  = document.querySelector('meta[name="csrf-token"]');
    var csrfToken = csrfMeta ? csrfMeta.getAttribute('content') : '';

    // ── Konfirmasi aksi ───────────────────────────────────────────────────────
    window.confirmSetujui = function () {
        return window.confirm(
            'Anda yakin ingin menyetujui pengajuan ini?\n' +
            'Kasir akan menerima notifikasi untuk memproses pembayaran.'
        );
    };

    window.confirmTolak = function () {
        var alasan = document.getElementById('alasan_tolak');
        if (!alasan || alasan.value.trim() === '') {
            alert('Alasan penolakan wajib diisi.');
            return false;
        }
        return window.confirm(
            'Anda yakin ingin MENOLAK pengajuan ini?\n' +
            'Tindakan ini tidak dapat dibatalkan.'
        );
    };

    // ── AJAX: Simpan verifikasi per item ─────────────────────────────────────
    /**
     * Kirim data verifikasi satu item ke api/item_verify.php via AJAX.
     * @param {number} itemId
     */
    function saveItemVerification(itemId) {
        var radioChecked = document.querySelector(
            'input.verify-radio[data-item-id="' + itemId + '"]:checked'
        );
        var amountInput = document.getElementById('approved_' + itemId);
        var feedbackEl  = document.getElementById('feedback-' + itemId);
        var saveBtn     = document.querySelector('.btn-save-item[data-item-id="' + itemId + '"]');

        if (!radioChecked) {
            showFeedback(feedbackEl, 'danger', 'Pilih status verifikasi terlebih dahulu.');
            return;
        }

        var verificationStatus = radioChecked.value;
        var approvedAmount     = amountInput ? amountInput.value.trim() : '';

        // Disable tombol saat proses berlangsung
        if (saveBtn) {
            saveBtn.disabled = true;
            saveBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status"></span>Menyimpan…';
        }

        var payload = {
            item_id:             itemId,
            verification_status: verificationStatus,
            approved_amount:     approvedAmount !== '' ? approvedAmount : null
        };

        fetch(BASE_URL + '/api/item_verify.php', {
            method:      'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type':    'application/json',
                'X-CSRF-Token':    csrfToken,
                'X-Requested-With':'XMLHttpRequest'
            },
            body: JSON.stringify(payload)
        })
        .then(function (response) {
            return response.json().then(function (data) {
                return { ok: response.ok, data: data };
            });
        })
        .then(function (result) {
            if (result.ok && result.data.success) {
                // Perbarui badge status di baris tabel
                updateStatusBadge(itemId, verificationStatus);
                // Perbarui warna baris
                updateRowHighlight(itemId, verificationStatus);
                showFeedback(feedbackEl, 'success', 'Tersimpan.');
                // Update progress counter
                updateProgressCounters();
                // Update tombol setujui
                updateSetujuiButton();
            } else {
                var msg = (result.data && result.data.error) ? result.data.error : 'Gagal menyimpan.';
                showFeedback(feedbackEl, 'danger', msg);
            }
        })
        .catch(function () {
            showFeedback(feedbackEl, 'danger', 'Terjadi kesalahan jaringan. Coba lagi.');
        })
        .finally(function () {
            if (saveBtn) {
                saveBtn.disabled = false;
                saveBtn.innerHTML = '<i class="bi bi-save me-1"></i>Simpan';
            }
        });
    }

    /**
     * Tampilkan pesan feedback kecil di bawah tombol simpan.
     * @param {HTMLElement} el
     * @param {string} type  'success' atau 'danger'
     * @param {string} msg
     */
    function showFeedback(el, type, msg) {
        if (!el) return;
        el.className = 'item-feedback mt-1 small text-' + type;
        el.textContent = msg;
        // Hapus pesan setelah 4 detik
        setTimeout(function () {
            el.textContent = '';
            el.className   = 'item-feedback mt-1';
        }, 4000);
    }

    /**
     * Perbarui badge status di kolom "Status Verifikasi".
     * @param {number} itemId
     * @param {string} status
     */
    function updateStatusBadge(itemId, status) {
        var badge = document.getElementById('badge-status-' + itemId);
        if (!badge) return;

        var labelMap = {
            'belum':        ['Belum',        'bg-secondary'],
            'diverifikasi': ['Diverifikasi', 'bg-success'],
            'ditolak':      ['Ditolak',      'bg-danger']
        };
        var entry = labelMap[status] || [status, 'bg-secondary'];

        badge.className   = 'badge ' + entry[1];
        badge.textContent = entry[0];
    }

    /**
     * Perbarui highlight warna baris setelah verifikasi.
     * (Hanya hapus highlight — tidak perlu set ulang karena selisih tidak berubah.)
     * @param {number} itemId
     * @param {string} status
     */
    function updateRowHighlight(itemId, status) {
        // Tidak ada perubahan pada warna baris yang terkait selisih OCR
        // (warna kuning/merah tetap sebagai informasi selisih data, bukan status verifikasi)
    }

    /**
     * Hitung ulang counter progress (belum/diverifikasi/ditolak) dari badge-badge di DOM.
     */
    function updateProgressCounters() {
        var badges = document.querySelectorAll('[id^="badge-status-"]');
        var counts = { belum: 0, diverifikasi: 0, ditolak: 0 };

        badges.forEach(function (b) {
            var txt = b.textContent.trim().toLowerCase();
            if (txt === 'belum')        counts.belum++;
            else if (txt === 'diverifikasi') counts.diverifikasi++;
            else if (txt === 'ditolak') counts.ditolak++;
        });

        // Update angka di progress summary (card di atas)
        var cells = document.querySelectorAll('.progress-summary-cell');
        // Fallback: cari elemen berdasarkan text label di card progress
        // (gunakan data attribute di card untuk kemudahan update)
        var progressCard = document.getElementById('progress-summary');
        if (progressCard) {
            var totalEl   = progressCard.querySelector('[data-count="total"]');
            var verifEl   = progressCard.querySelector('[data-count="diverifikasi"]');
            var ditolakEl = progressCard.querySelector('[data-count="ditolak"]');
            var belumEl   = progressCard.querySelector('[data-count="belum"]');
            var pctEl     = progressCard.querySelector('[data-count="pct"]');
            var barEl     = progressCard.querySelector('.progress-bar');

            var total = badges.length;
            var done  = counts.diverifikasi + counts.ditolak;
            var pct   = total > 0 ? Math.round(done / total * 100) : 0;

            if (verifEl)   verifEl.textContent   = counts.diverifikasi;
            if (ditolakEl) ditolakEl.textContent  = counts.ditolak;
            if (belumEl)   belumEl.textContent    = counts.belum;
            if (pctEl)     pctEl.textContent      = pct + '%';
            if (barEl) {
                barEl.style.width = pct + '%';
                barEl.setAttribute('aria-valuenow', pct);
            }

            // Perbarui angka di progress card (simpel: update semua fs-4 di card)
        }

        return counts;
    }

    /**
     * Aktifkan/nonaktifkan tombol Setujui berdasarkan jumlah item belum diverifikasi.
     */
    function updateSetujuiButton() {
        var counts  = updateProgressCounters();
        var setujuiBtn = document.querySelector('#form-setujui button[type="submit"]');
        var warningEl  = document.querySelector('#form-setujui .alert-warning');
        var hintEl     = document.querySelector('#form-setujui .form-text');

        if (!setujuiBtn) return;

        if (counts.belum === 0) {
            setujuiBtn.disabled = false;
            if (warningEl) warningEl.classList.add('d-none');
            if (hintEl)    hintEl.classList.add('d-none');
        } else {
            setujuiBtn.disabled = true;
            if (warningEl) {
                warningEl.classList.remove('d-none');
                warningEl.innerHTML = '<i class="bi bi-exclamation-triangle me-1"></i>' +
                    'Masih ada <strong>' + counts.belum + '</strong> item belum diverifikasi.';
            }
        }
    }

    // ── Event listeners: tombol Simpan per item ───────────────────────────────
    document.querySelectorAll('.btn-save-item').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var itemId = parseInt(btn.getAttribute('data-item-id'), 10);
            if (!isNaN(itemId)) {
                saveItemVerification(itemId);
            }
        });
    });

    // ── Inisialisasi: sinkronisasi progress counter saat halaman dimuat ───────
    updateProgressCounters();
    updateSetujuiButton();

}());
</script>

<?php require_once __DIR__ . '/../../includes/layout/footer.php'; ?>
