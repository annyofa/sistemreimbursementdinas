<?php

/**
 * public/shared/detail.php
 *
 * Halaman detail pengajuan reimbursement — read-only, diakses oleh role
 * admin, manajemen, finance, dan kasir.
 *
 * Menampilkan:
 *   - Informasi perjalanan dinas (destination, purpose, start/end date, pegawai, departemen)
 *   - Daftar item biaya + foto struk + data OCR
 *   - Timeline riwayat status via getStatusHistory()
 *   - Semua revision_requests beserta jawaban pegawai
 *   - Data pembayaran jika status = dibayar
 *   - Keputusan approval (termasuk paraf digital jika ada)
 *
 * TIDAK ada tombol aksi (setujui/tolak/revisi/bayar) — murni read-only.
 *
 * Requirements: 9.6, 9.8
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireAuth();
requireRole(['admin', 'manajemen', 'finance', 'kasir']);

// ── Sanitize back-link ────────────────────────────────────────────────────────
// Hanya terima referer yang berasal dari aplikasi sendiri (BASE_URL),
// sehingga mencegah open-redirect ke domain luar.
$defaultBack = BASE_URL . '/dashboard.php';
$backUrl     = $defaultBack;

if (!empty($_SERVER['HTTP_REFERER'])) {
    $referer = $_SERVER['HTTP_REFERER'];

    // Bangun prefix yang diizinkan: skema + host + BASE_URL
    $scheme          = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host            = $_SERVER['HTTP_HOST'] ?? '';
    $allowedAbsolute = $scheme . '://' . $host . BASE_URL;
    $allowedRelative = BASE_URL;

    if (
        str_starts_with($referer, $allowedAbsolute) ||
        str_starts_with($referer, $allowedRelative)
    ) {
        $backUrl = $referer;
    }
}

// ── Terima dan validasi ?id ───────────────────────────────────────────────────
$reimbId = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($reimbId <= 0) {
    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'ID pengajuan tidak valid.'];
    header('Location: ' . $backUrl);
    exit;
}

$db = getDB();

// ── Query utama: reimbursement + trip + pegawai + departemen ─────────────────
$stmtMain = $db->prepare(
    "SELECT
         r.id,
         r.reimbursement_no,
         r.total_amount,
         r.status,
         r.notes,
         r.submitted_at,
         r.paid_at,
         r.created_at,
         bt.destination,
         bt.purpose,
         bt.start_date,
         bt.end_date,
         u.id           AS pegawai_id,
         u.name         AS pegawai_name,
         u.email        AS pegawai_email,
         d.name         AS departemen_name
     FROM reimbursements r
     LEFT JOIN business_trips bt ON bt.id = r.trip_id
     LEFT JOIN users u            ON u.id  = r.user_id
     LEFT JOIN departments d      ON d.id  = u.department_id
     WHERE r.id = :id
     LIMIT 1"
);
$stmtMain->execute([':id' => $reimbId]);
$reimb = $stmtMain->fetch();

if ($reimb === false) {
    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Pengajuan tidak ditemukan.'];
    header('Location: ' . $backUrl);
    exit;
}

// ── Query item biaya + kategori ───────────────────────────────────────────────
$stmtItems = $db->prepare(
    "SELECT
         ri.id,
         ri.expense_date,
         ri.merchant,
         ri.description,
         ri.amount,
         ri.approved_amount,
         ri.verification_status,
         ec.name AS category_name
     FROM reimbursement_items ri
     LEFT JOIN expense_categories ec ON ec.id = ri.category_id
     WHERE ri.reimbursement_id = :reimb_id
     ORDER BY ri.expense_date ASC, ri.id ASC"
);
$stmtItems->execute([':reimb_id' => $reimbId]);
$items = $stmtItems->fetchAll();

// ── Kumpulkan item IDs untuk query struk (receipts) ───────────────────────────
$itemIds = array_column($items, 'id');
$receiptsByItem = [];

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
         WHERE item_id IN ({$placeholders})
         ORDER BY id ASC"
    );
    $stmtReceipts->execute($itemIds);

    foreach ($stmtReceipts->fetchAll() as $receipt) {
        $receiptsByItem[(int) $receipt['item_id']][] = $receipt;
    }
}

// ── Query revision_requests ───────────────────────────────────────────────────
$stmtRevisions = $db->prepare(
    "SELECT
         rr.id,
         rr.item_id,
         rr.message,
         rr.response,
         rr.status        AS rr_status,
         rr.responded_at,
         rr.created_at    AS rr_created_at,
         u.name           AS requester_name,
         u.role           AS requester_role
     FROM revision_requests rr
     LEFT JOIN users u ON u.id = rr.requested_by
     WHERE rr.reimbursement_id = :reimb_id
     ORDER BY rr.created_at ASC"
);
$stmtRevisions->execute([':reimb_id' => $reimbId]);
$revisions = $stmtRevisions->fetchAll();

// ── Query payments (jika status dibayar) ─────────────────────────────────────
$payment = null;
if ($reimb['status'] === 'dibayar') {
    $stmtPayment = $db->prepare(
        "SELECT
             p.id,
             p.amount,
             p.method,
             p.reference_no,
             p.paid_at,
             p.proof_path,
             u.name AS kasir_name
         FROM payments p
         LEFT JOIN users u ON u.id = p.cashier_id
         WHERE p.reimbursement_id = :reimb_id
         ORDER BY p.id DESC
         LIMIT 1"
    );
    $stmtPayment->execute([':reimb_id' => $reimbId]);
    $payment = $stmtPayment->fetch() ?: null;
}

// ── Query approvals ───────────────────────────────────────────────────────────
$stmtApprovals = $db->prepare(
    "SELECT
         a.id,
         a.level,
         a.decision,
         a.comment,
         a.digital_signature,
         a.acted_at,
         u.name AS approver_name,
         u.role AS approver_role
     FROM approvals a
     LEFT JOIN users u ON u.id = a.approver_id
     WHERE a.reimbursement_id = :reimb_id
     ORDER BY a.acted_at ASC"
);
$stmtApprovals->execute([':reimb_id' => $reimbId]);
$approvals = $stmtApprovals->fetchAll();

// ── Riwayat status ────────────────────────────────────────────────────────────
$statusHistory = getStatusHistory($reimbId);

// ── Hitung total approved ─────────────────────────────────────────────────────
$totalApproved = 0.0;
foreach ($items as $item) {
    if ($item['approved_amount'] !== null && $item['approved_amount'] > 0) {
        $totalApproved += (float) $item['approved_amount'];
    }
}

// ── Siapkan halaman ───────────────────────────────────────────────────────────
$pageTitle = 'Detail Pengajuan ' . htmlspecialchars($reimb['reimbursement_no'] ?? '', ENT_QUOTES, 'UTF-8');
require_once __DIR__ . '/../../includes/layout/header.php';
require_once __DIR__ . '/../../includes/layout/navbar.php';

// ── Helper: badge verification_status ────────────────────────────────────────
function verificationBadge(string $status): string
{
    $map = [
        'belum'        => ['Belum Diverifikasi', 'bg-secondary'],
        'diverifikasi' => ['Diverifikasi',        'bg-success'],
        'ditolak'      => ['Ditolak',             'bg-danger'],
    ];
    $entry = $map[$status] ?? [$status, 'bg-secondary'];
    return '<span class="badge ' . $entry[1] . '">'
        . htmlspecialchars($entry[0], ENT_QUOTES, 'UTF-8')
        . '</span>';
}

// ── Helper: badge revision status ────────────────────────────────────────────
function revisionStatusBadge(string $status): string
{
    $map = [
        'terbuka' => ['Terbuka', 'bg-warning text-dark'],
        'dijawab' => ['Dijawab', 'bg-success'],
        'selesai' => ['Selesai', 'bg-secondary'],
    ];
    $entry = $map[$status] ?? [$status, 'bg-secondary'];
    return '<span class="badge ' . $entry[1] . '">'
        . htmlspecialchars($entry[0], ENT_QUOTES, 'UTF-8')
        . '</span>';
}
?>

<!-- ── Header halaman ──────────────────────────────────────────────────────── -->
<div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-2">
    <div>
        <h1 class="h3 fw-bold mb-0">
            <i class="bi bi-file-earmark-text me-2 text-primary"></i>
            Detail Pengajuan
        </h1>
        <p class="text-muted mb-0 mt-1 small">
            Read-only — tidak ada aksi yang dapat dilakukan dari halaman ini.
        </p>
    </div>
    <div class="d-flex align-items-center gap-2">
        <?= getStatusLabel($reimb['status'] ?? '') ?>
        <a href="<?= htmlspecialchars($backUrl, ENT_QUOTES, 'UTF-8') ?>"
           class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Kembali
        </a>
    </div>
</div>

<!-- ── Baris atas: Info Pengajuan + Info Perjalanan ──────────────────────── -->
<div class="row g-4 mb-4">

    <!-- Kartu: Informasi Pengajuan -->
    <div class="col-md-6">
        <div class="card shadow-sm h-100">
            <div class="card-header bg-white fw-semibold py-3">
                <i class="bi bi-receipt me-2 text-primary"></i>Informasi Pengajuan
            </div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-sm-5 text-muted small">Nomor Pengajuan</dt>
                    <dd class="col-sm-7 fw-semibold font-monospace">
                        <?= htmlspecialchars($reimb['reimbursement_no'] ?? '—', ENT_QUOTES, 'UTF-8') ?>
                    </dd>

                    <dt class="col-sm-5 text-muted small">Status</dt>
                    <dd class="col-sm-7">
                        <?= getStatusLabel($reimb['status'] ?? '') ?>
                    </dd>

                    <dt class="col-sm-5 text-muted small">Total Pengajuan</dt>
                    <dd class="col-sm-7 fw-semibold text-primary">
                        <?= htmlspecialchars(formatRupiah((float) ($reimb['total_amount'] ?? 0)), ENT_QUOTES, 'UTF-8') ?>
                    </dd>

                    <?php if ($totalApproved > 0): ?>
                    <dt class="col-sm-5 text-muted small">Total Disetujui</dt>
                    <dd class="col-sm-7 fw-semibold text-success">
                        <?= htmlspecialchars(formatRupiah($totalApproved), ENT_QUOTES, 'UTF-8') ?>
                    </dd>
                    <?php endif; ?>

                    <dt class="col-sm-5 text-muted small">Tanggal Dibuat</dt>
                    <dd class="col-sm-7">
                        <?= htmlspecialchars(
                            !empty($reimb['created_at'])
                                ? date('d M Y, H:i', strtotime($reimb['created_at']))
                                : '—',
                            ENT_QUOTES, 'UTF-8'
                        ) ?>
                    </dd>

                    <?php if (!empty($reimb['submitted_at'])): ?>
                    <dt class="col-sm-5 text-muted small">Tanggal Diajukan</dt>
                    <dd class="col-sm-7">
                        <?= htmlspecialchars(
                            date('d M Y, H:i', strtotime($reimb['submitted_at'])),
                            ENT_QUOTES, 'UTF-8'
                        ) ?>
                    </dd>
                    <?php endif; ?>

                    <?php if (!empty($reimb['paid_at'])): ?>
                    <dt class="col-sm-5 text-muted small">Tanggal Dibayar</dt>
                    <dd class="col-sm-7">
                        <?= htmlspecialchars(
                            date('d M Y, H:i', strtotime($reimb['paid_at'])),
                            ENT_QUOTES, 'UTF-8'
                        ) ?>
                    </dd>
                    <?php endif; ?>

                    <?php if (!empty($reimb['notes'])): ?>
                    <dt class="col-sm-5 text-muted small">Catatan</dt>
                    <dd class="col-sm-7">
                        <?= htmlspecialchars($reimb['notes'], ENT_QUOTES, 'UTF-8') ?>
                    </dd>
                    <?php endif; ?>
                </dl>
            </div>
        </div>
    </div>

    <!-- Kartu: Informasi Perjalanan Dinas -->
    <div class="col-md-6">
        <div class="card shadow-sm h-100">
            <div class="card-header bg-white fw-semibold py-3">
                <i class="bi bi-geo-alt me-2 text-primary"></i>Informasi Perjalanan Dinas
            </div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-sm-5 text-muted small">Pegawai</dt>
                    <dd class="col-sm-7">
                        <span class="fw-semibold">
                            <?= htmlspecialchars($reimb['pegawai_name'] ?? '—', ENT_QUOTES, 'UTF-8') ?>
                        </span>
                        <?php if (!empty($reimb['pegawai_email'])): ?>
                            <br>
                            <span class="text-muted small">
                                <?= htmlspecialchars($reimb['pegawai_email'], ENT_QUOTES, 'UTF-8') ?>
                            </span>
                        <?php endif; ?>
                    </dd>

                    <dt class="col-sm-5 text-muted small">Departemen</dt>
                    <dd class="col-sm-7">
                        <?= htmlspecialchars($reimb['departemen_name'] ?? '—', ENT_QUOTES, 'UTF-8') ?>
                    </dd>

                    <dt class="col-sm-5 text-muted small">Tujuan</dt>
                    <dd class="col-sm-7 fw-semibold">
                        <?= htmlspecialchars($reimb['destination'] ?? '—', ENT_QUOTES, 'UTF-8') ?>
                    </dd>

                    <dt class="col-sm-5 text-muted small">Keperluan</dt>
                    <dd class="col-sm-7">
                        <?= htmlspecialchars($reimb['purpose'] ?? '—', ENT_QUOTES, 'UTF-8') ?>
                    </dd>

                    <dt class="col-sm-5 text-muted small">Tanggal Mulai</dt>
                    <dd class="col-sm-7">
                        <?= htmlspecialchars(
                            !empty($reimb['start_date'])
                                ? date('d M Y', strtotime($reimb['start_date']))
                                : '—',
                            ENT_QUOTES, 'UTF-8'
                        ) ?>
                    </dd>

                    <dt class="col-sm-5 text-muted small">Tanggal Selesai</dt>
                    <dd class="col-sm-7">
                        <?= htmlspecialchars(
                            !empty($reimb['end_date'])
                                ? date('d M Y', strtotime($reimb['end_date']))
                                : '—',
                            ENT_QUOTES, 'UTF-8'
                        ) ?>
                    </dd>

                    <?php if (!empty($reimb['start_date']) && !empty($reimb['end_date'])): ?>
                    <dt class="col-sm-5 text-muted small">Durasi</dt>
                    <dd class="col-sm-7">
                        <?php
                        $durasi = (int) ceil(
                            (strtotime($reimb['end_date']) - strtotime($reimb['start_date'])) / 86400
                        ) + 1;
                        echo htmlspecialchars($durasi . ' hari', ENT_QUOTES, 'UTF-8');
                        ?>
                    </dd>
                    <?php endif; ?>
                </dl>
            </div>
        </div>
    </div>

</div>

<!-- ── Daftar Item Biaya ─────────────────────────────────────────────────── -->
<div class="card shadow-sm mb-4">
    <div class="card-header bg-white fw-semibold py-3 d-flex justify-content-between align-items-center">
        <span><i class="bi bi-list-ul me-2 text-primary"></i>Daftar Item Biaya</span>
        <span class="text-muted small fw-normal"><?= count($items) ?> item</span>
    </div>

    <?php if (empty($items)): ?>
        <div class="card-body text-center text-muted py-4">
            <i class="bi bi-inbox fs-2 d-block mb-2"></i>
            Belum ada item biaya.
        </div>
    <?php else: ?>
        <div class="accordion accordion-flush" id="accordionItems">
        <?php foreach ($items as $idx => $item): ?>
            <?php $receipts = $receiptsByItem[(int) $item['id']] ?? []; ?>
            <div class="accordion-item border-bottom">
                <h2 class="accordion-header" id="heading-item-<?= (int) $item['id'] ?>">
                    <button
                        class="accordion-button <?= $idx > 0 ? 'collapsed' : '' ?>"
                        type="button"
                        data-bs-toggle="collapse"
                        data-bs-target="#collapse-item-<?= (int) $item['id'] ?>"
                        aria-expanded="<?= $idx === 0 ? 'true' : 'false' ?>"
                        aria-controls="collapse-item-<?= (int) $item['id'] ?>"
                    >
                        <span class="me-3 text-muted small"><?= $idx + 1 ?>.</span>
                        <span class="fw-semibold flex-grow-1">
                            <?= htmlspecialchars($item['description'] ?? '—', ENT_QUOTES, 'UTF-8') ?>
                        </span>
                        <span class="me-3 small text-muted">
                            <?= htmlspecialchars($item['category_name'] ?? '—', ENT_QUOTES, 'UTF-8') ?>
                        </span>
                        <span class="me-3 fw-semibold text-nowrap">
                            <?= htmlspecialchars(formatRupiah((float) ($item['amount'] ?? 0)), ENT_QUOTES, 'UTF-8') ?>
                        </span>
                        <span class="me-2">
                            <?= verificationBadge($item['verification_status'] ?? 'belum') ?>
                        </span>
                    </button>
                </h2>
                <div
                    id="collapse-item-<?= (int) $item['id'] ?>"
                    class="accordion-collapse collapse <?= $idx === 0 ? 'show' : '' ?>"
                    aria-labelledby="heading-item-<?= (int) $item['id'] ?>"
                    data-bs-parent="#accordionItems"
                >
                    <div class="accordion-body bg-light">
                        <div class="row g-3">
                            <!-- Detail item -->
                            <div class="col-md-5">
                                <div class="card border-0 bg-white shadow-sm h-100">
                                    <div class="card-body">
                                        <h6 class="card-title text-muted small mb-3 text-uppercase tracking-wide">
                                            Detail Item
                                        </h6>
                                        <dl class="row mb-0 small">
                                            <dt class="col-6 text-muted">Kategori</dt>
                                            <dd class="col-6">
                                                <?= htmlspecialchars($item['category_name'] ?? '—', ENT_QUOTES, 'UTF-8') ?>
                                            </dd>

                                            <dt class="col-6 text-muted">Merchant / Toko</dt>
                                            <dd class="col-6">
                                                <?= htmlspecialchars($item['merchant'] ?? '—', ENT_QUOTES, 'UTF-8') ?>
                                            </dd>

                                            <dt class="col-6 text-muted">Tanggal Pengeluaran</dt>
                                            <dd class="col-6">
                                                <?= htmlspecialchars(
                                                    !empty($item['expense_date'])
                                                        ? date('d M Y', strtotime($item['expense_date']))
                                                        : '—',
                                                    ENT_QUOTES, 'UTF-8'
                                                ) ?>
                                            </dd>

                                            <dt class="col-6 text-muted">Nominal Diajukan</dt>
                                            <dd class="col-6 fw-semibold">
                                                <?= htmlspecialchars(formatRupiah((float) ($item['amount'] ?? 0)), ENT_QUOTES, 'UTF-8') ?>
                                            </dd>

                                            <?php if ($item['approved_amount'] !== null && $item['approved_amount'] > 0): ?>
                                            <dt class="col-6 text-muted">Nominal Disetujui</dt>
                                            <dd class="col-6 fw-semibold text-success">
                                                <?= htmlspecialchars(formatRupiah((float) $item['approved_amount']), ENT_QUOTES, 'UTF-8') ?>
                                            </dd>
                                            <?php endif; ?>

                                            <dt class="col-6 text-muted">Status Verifikasi</dt>
                                            <dd class="col-6">
                                                <?= verificationBadge($item['verification_status'] ?? 'belum') ?>
                                            </dd>
                                        </dl>
                                    </div>
                                </div>
                            </div>

                            <!-- Struk & OCR -->
                            <div class="col-md-7">
                                <?php if (empty($receipts)): ?>
                                    <div class="card border-0 bg-white shadow-sm h-100">
                                        <div class="card-body text-center text-muted py-4">
                                            <i class="bi bi-image fs-2 d-block mb-2"></i>
                                            Belum ada struk yang diunggah.
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($receipts as $receipt): ?>
                                    <div class="card border-0 bg-white shadow-sm mb-2">
                                        <div class="card-body p-3">
                                            <div class="row g-2 align-items-start">
                                                <!-- Foto struk -->
                                                <div class="col-auto">
                                                    <?php
                                                    $filePath = htmlspecialchars($receipt['file_path'] ?? '', ENT_QUOTES, 'UTF-8');
                                                    $ext      = strtolower(pathinfo($receipt['file_path'] ?? '', PATHINFO_EXTENSION));
                                                    $fileUrl  = BASE_URL . '/' . ltrim($filePath, '/');
                                                    ?>
                                                    <?php if (in_array($ext, ['jpg', 'jpeg', 'png'], true)): ?>
                                                        <a href="<?= $fileUrl ?>" target="_blank" rel="noopener noreferrer">
                                                            <img
                                                                src="<?= $fileUrl ?>"
                                                                alt="Struk"
                                                                class="img-thumbnail"
                                                                style="max-height:100px; max-width:100px; object-fit:cover;"
                                                            >
                                                        </a>
                                                    <?php elseif ($ext === 'pdf'): ?>
                                                        <a
                                                            href="<?= $fileUrl ?>"
                                                            target="_blank"
                                                            rel="noopener noreferrer"
                                                            class="btn btn-sm btn-outline-secondary"
                                                        >
                                                            <i class="bi bi-file-earmark-pdf me-1 text-danger"></i>Lihat PDF
                                                        </a>
                                                    <?php else: ?>
                                                        <span class="text-muted small">—</span>
                                                    <?php endif; ?>
                                                </div>

                                                <!-- Data OCR -->
                                                <div class="col">
                                                    <div class="d-flex align-items-center gap-2 mb-1">
                                                        <span class="small fw-semibold text-muted text-uppercase">Data OCR</span>
                                                        <?php if (($receipt['ocr_status'] ?? '') === 'buram'): ?>
                                                            <span class="badge bg-warning text-dark opacity-75">
                                                                <i class="bi bi-exclamation-triangle me-1"></i>Buram
                                                            </span>
                                                        <?php elseif (($receipt['ocr_status'] ?? '') === 'sukses'): ?>
                                                            <span class="badge bg-success">
                                                                <i class="bi bi-check-circle me-1"></i>Sukses
                                                            </span>
                                                        <?php endif; ?>
                                                    </div>

                                                    <dl class="row mb-0 small">
                                                        <?php if (!empty($receipt['ocr_merchant'])): ?>
                                                        <dt class="col-5 text-muted">Merchant (OCR)</dt>
                                                        <dd class="col-7">
                                                            <?= htmlspecialchars($receipt['ocr_merchant'], ENT_QUOTES, 'UTF-8') ?>
                                                        </dd>
                                                        <?php endif; ?>

                                                        <?php if (!empty($receipt['ocr_date'])): ?>
                                                        <dt class="col-5 text-muted">Tanggal (OCR)</dt>
                                                        <dd class="col-7">
                                                            <?= htmlspecialchars($receipt['ocr_date'], ENT_QUOTES, 'UTF-8') ?>
                                                        </dd>
                                                        <?php endif; ?>

                                                        <?php if (!empty($receipt['ocr_total'])): ?>
                                                        <dt class="col-5 text-muted">Total (OCR)</dt>
                                                        <dd class="col-7 fw-semibold">
                                                            <?= htmlspecialchars(
                                                                formatRupiah((float) $receipt['ocr_total']),
                                                                ENT_QUOTES, 'UTF-8'
                                                            ) ?>
                                                        </dd>
                                                        <?php endif; ?>

                                                        <?php if (isset($receipt['ocr_confidence']) && $receipt['ocr_confidence'] !== null): ?>
                                                        <dt class="col-5 text-muted">Confidence</dt>
                                                        <dd class="col-7">
                                                            <?php
                                                            $conf = (float) $receipt['ocr_confidence'];
                                                            $confClass = $conf >= 70 ? 'text-success' : 'text-warning';
                                                            ?>
                                                            <span class="<?= $confClass ?> fw-semibold">
                                                                <?= htmlspecialchars(number_format($conf, 1), ENT_QUOTES, 'UTF-8') ?>%
                                                            </span>
                                                        </dd>
                                                        <?php endif; ?>
                                                    </dl>

                                                    <?php if (($receipt['ocr_status'] ?? '') === 'buram' && !empty($receipt['ocr_raw_text'])): ?>
                                                        <details class="mt-2">
                                                            <summary class="small text-muted" style="cursor:pointer;">
                                                                Lihat teks OCR mentah
                                                            </summary>
                                                            <pre class="bg-light rounded p-2 mt-1 small text-muted" style="max-height:100px; overflow-y:auto; font-size:0.75rem;"><?= htmlspecialchars($receipt['ocr_raw_text'], ENT_QUOTES, 'UTF-8') ?></pre>
                                                        </details>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div><!-- /.col-md-7 -->
                        </div><!-- /.row -->
                    </div><!-- /.accordion-body -->
                </div><!-- /.accordion-collapse -->
            </div><!-- /.accordion-item -->
        <?php endforeach; ?>
        </div><!-- /#accordionItems -->

        <!-- Ringkasan total -->
        <div class="card-footer bg-white d-flex justify-content-end gap-4 py-3 border-top">
            <span class="text-muted small">
                Total Pengajuan:
                <strong class="ms-1 text-primary">
                    <?= htmlspecialchars(formatRupiah((float) ($reimb['total_amount'] ?? 0)), ENT_QUOTES, 'UTF-8') ?>
                </strong>
            </span>
            <?php if ($totalApproved > 0): ?>
            <span class="text-muted small">
                Total Disetujui:
                <strong class="ms-1 text-success">
                    <?= htmlspecialchars(formatRupiah($totalApproved), ENT_QUOTES, 'UTF-8') ?>
                </strong>
            </span>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<!-- ── Keputusan Approval ────────────────────────────────────────────────── -->
<?php if (!empty($approvals)): ?>
<div class="card shadow-sm mb-4">
    <div class="card-header bg-white fw-semibold py-3">
        <i class="bi bi-patch-check me-2 text-primary"></i>Keputusan Approval
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-4">Level</th>
                        <th>Approver</th>
                        <th>Keputusan</th>
                        <th>Komentar</th>
                        <th>Paraf Digital</th>
                        <th>Tanggal</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($approvals as $approval): ?>
                    <tr>
                        <td class="ps-4">
                            <span class="badge bg-info text-dark text-capitalize">
                                <?= htmlspecialchars(ucfirst($approval['level'] ?? '—'), ENT_QUOTES, 'UTF-8') ?>
                            </span>
                        </td>
                        <td>
                            <?= htmlspecialchars($approval['approver_name'] ?? '—', ENT_QUOTES, 'UTF-8') ?>
                            <?php if (!empty($approval['approver_role'])): ?>
                                <br>
                                <span class="text-muted small text-capitalize">
                                    <?= htmlspecialchars(ucfirst($approval['approver_role']), ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php
                            $dec      = $approval['decision'] ?? '';
                            $decClass = match ($dec) {
                                'disetujui' => 'bg-success',
                                'ditolak'   => 'bg-danger',
                                default     => 'bg-secondary',
                            };
                            ?>
                            <span class="badge <?= $decClass ?> text-capitalize">
                                <?= htmlspecialchars(ucfirst($dec), ENT_QUOTES, 'UTF-8') ?>
                            </span>
                        </td>
                        <td class="text-muted small">
                            <?= htmlspecialchars($approval['comment'] ?? '—', ENT_QUOTES, 'UTF-8') ?>
                        </td>
                        <td>
                            <?php if (!empty($approval['digital_signature'])): ?>
                                <?php
                                $sigPath = htmlspecialchars($approval['digital_signature'], ENT_QUOTES, 'UTF-8');
                                $sigExt  = strtolower(pathinfo($approval['digital_signature'], PATHINFO_EXTENSION));
                                $sigUrl  = BASE_URL . '/' . ltrim($sigPath, '/');
                                ?>
                                <?php if (in_array($sigExt, ['jpg', 'jpeg', 'png'], true)): ?>
                                    <a href="<?= $sigUrl ?>" target="_blank" rel="noopener noreferrer">
                                        <img
                                            src="<?= $sigUrl ?>"
                                            alt="Paraf digital"
                                            class="img-thumbnail"
                                            style="max-height:50px; max-width:80px; object-fit:contain;"
                                        >
                                    </a>
                                <?php elseif ($sigExt === 'pdf'): ?>
                                    <a
                                        href="<?= $sigUrl ?>"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="btn btn-sm btn-outline-secondary"
                                    >
                                        <i class="bi bi-file-earmark-pdf me-1 text-danger"></i>Lihat
                                    </a>
                                <?php else: ?>
                                    <span class="text-muted small">Ada (format lain)</span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="text-muted small">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-muted small text-nowrap">
                            <?= htmlspecialchars(
                                !empty($approval['acted_at'])
                                    ? date('d M Y, H:i', strtotime($approval['acted_at']))
                                    : '—',
                                ENT_QUOTES, 'UTF-8'
                            ) ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ── Permintaan Revisi ─────────────────────────────────────────────────── -->
<?php if (!empty($revisions)): ?>
<div class="card shadow-sm mb-4">
    <div class="card-header bg-white fw-semibold py-3">
        <i class="bi bi-pencil-square me-2 text-warning"></i>Riwayat Permintaan Revisi
    </div>
    <div class="card-body">
        <?php foreach ($revisions as $rev): ?>
        <div class="border rounded p-3 mb-3 bg-light">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2">
                <div>
                    <span class="fw-semibold">
                        <?= htmlspecialchars($rev['requester_name'] ?? '—', ENT_QUOTES, 'UTF-8') ?>
                    </span>
                    <?php if (!empty($rev['requester_role'])): ?>
                        <span class="badge bg-secondary ms-1 text-capitalize">
                            <?= htmlspecialchars(ucfirst($rev['requester_role']), ENT_QUOTES, 'UTF-8') ?>
                        </span>
                    <?php endif; ?>
                    <?php if (!empty($rev['rr_created_at'])): ?>
                        <span class="text-muted small ms-2">
                            <?= htmlspecialchars(date('d M Y, H:i', strtotime($rev['rr_created_at'])), ENT_QUOTES, 'UTF-8') ?>
                        </span>
                    <?php endif; ?>
                </div>
                <?= revisionStatusBadge($rev['rr_status'] ?? '') ?>
            </div>

            <!-- Pesan permintaan revisi -->
            <div class="mb-2">
                <p class="mb-1 small text-muted fw-semibold">Catatan Revisi:</p>
                <p class="mb-0">
                    <?= nl2br(htmlspecialchars($rev['message'] ?? '—', ENT_QUOTES, 'UTF-8')) ?>
                </p>
            </div>

            <!-- Jawaban pegawai -->
            <?php if (!empty($rev['response'])): ?>
            <div class="border-top pt-2 mt-2">
                <p class="mb-1 small text-muted fw-semibold">Jawaban Pegawai:</p>
                <p class="mb-0 text-success">
                    <?= nl2br(htmlspecialchars($rev['response'], ENT_QUOTES, 'UTF-8')) ?>
                </p>
                <?php if (!empty($rev['responded_at'])): ?>
                <p class="text-muted small mt-1 mb-0">
                    Dijawab: <?= htmlspecialchars(date('d M Y, H:i', strtotime($rev['responded_at'])), ENT_QUOTES, 'UTF-8') ?>
                </p>
                <?php endif; ?>
            </div>
            <?php else: ?>
            <div class="border-top pt-2 mt-2">
                <p class="mb-0 small text-muted fst-italic">Belum ada jawaban dari pegawai.</p>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- ── Data Pembayaran ───────────────────────────────────────────────────── -->
<?php if ($payment !== null): ?>
<div class="card shadow-sm mb-4 border-success">
    <div class="card-header bg-success bg-opacity-10 fw-semibold py-3 text-success">
        <i class="bi bi-cash-coin me-2"></i>Data Pembayaran
    </div>
    <div class="card-body">
        <dl class="row mb-0">
            <dt class="col-sm-3 text-muted">Kasir</dt>
            <dd class="col-sm-9">
                <?= htmlspecialchars($payment['kasir_name'] ?? '—', ENT_QUOTES, 'UTF-8') ?>
            </dd>

            <dt class="col-sm-3 text-muted">Metode Pembayaran</dt>
            <dd class="col-sm-9 text-capitalize fw-semibold">
                <?= htmlspecialchars(ucfirst($payment['method'] ?? '—'), ENT_QUOTES, 'UTF-8') ?>
            </dd>

            <dt class="col-sm-3 text-muted">Nominal Dibayar</dt>
            <dd class="col-sm-9 fw-semibold text-success">
                <?= htmlspecialchars(formatRupiah((float) ($payment['amount'] ?? 0)), ENT_QUOTES, 'UTF-8') ?>
            </dd>

            <?php if (!empty($payment['reference_no'])): ?>
            <dt class="col-sm-3 text-muted">Nomor Referensi</dt>
            <dd class="col-sm-9 font-monospace">
                <?= htmlspecialchars($payment['reference_no'], ENT_QUOTES, 'UTF-8') ?>
            </dd>
            <?php endif; ?>

            <dt class="col-sm-3 text-muted">Tanggal Pembayaran</dt>
            <dd class="col-sm-9">
                <?= htmlspecialchars(
                    !empty($payment['paid_at'])
                        ? date('d M Y, H:i', strtotime($payment['paid_at']))
                        : '—',
                    ENT_QUOTES, 'UTF-8'
                ) ?>
            </dd>

            <?php if (!empty($payment['proof_path'])): ?>
            <dt class="col-sm-3 text-muted">Bukti Pembayaran</dt>
            <dd class="col-sm-9">
                <?php
                $proofExt = strtolower(pathinfo($payment['proof_path'], PATHINFO_EXTENSION));
                $proofUrl = UPLOADS_URL . '/' . substr(htmlspecialchars($payment['proof_path'], ENT_QUOTES, 'UTF-8'), strlen('uploads/'));
                ?>
                <?php if (in_array($proofExt, ['jpg', 'jpeg', 'png'], true)): ?>
                    <a href="<?= $proofUrl ?>" target="_blank" rel="noopener noreferrer">
                        <img
                            src="<?= $proofUrl ?>"
                            alt="Bukti pembayaran"
                            class="img-thumbnail"
                            style="max-height:120px; object-fit:cover;"
                        >
                    </a>
                <?php elseif ($proofExt === 'pdf'): ?>
                    <a
                        href="<?= $proofUrl ?>"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="btn btn-sm btn-outline-secondary"
                    >
                        <i class="bi bi-file-earmark-pdf me-1 text-danger"></i>Lihat Bukti PDF
                    </a>
                <?php endif; ?>
            </dd>
            <?php endif; ?>
        </dl>
    </div>
</div>
<?php endif; ?>

<!-- ── Timeline Riwayat Status ──────────────────────────────────────────── -->
<div class="card shadow-sm mb-4">
    <div class="card-header bg-white fw-semibold py-3">
        <i class="bi bi-clock-history me-2 text-primary"></i>Riwayat Status
    </div>
    <div class="card-body">
        <?php if (empty($statusHistory)): ?>
            <p class="text-muted mb-0 text-center py-2">Belum ada riwayat perubahan status.</p>
        <?php else: ?>
            <!-- Timeline vertikal -->
            <div class="position-relative ps-4" style="border-left: 2px solid #dee2e6;">
                <?php foreach ($statusHistory as $i => $hist): ?>
                <?php
                $isLast    = ($i === count($statusHistory) - 1);
                $dotClass  = $isLast ? 'bg-primary' : 'bg-white border border-primary';
                ?>
                <div class="position-relative mb-4 <?= $isLast ? '' : '' ?>">
                    <!-- Titik timeline -->
                    <span
                        class="position-absolute d-inline-block rounded-circle <?= $dotClass ?>"
                        style="width:12px; height:12px; left:-22px; top:4px;"
                        aria-hidden="true"
                    ></span>

                    <!-- Konten -->
                    <div class="d-flex flex-wrap gap-2 align-items-start">
                        <div class="flex-grow-1">
                            <div class="d-flex flex-wrap align-items-center gap-2">
                                <?php if (!empty($hist['old_status'])): ?>
                                    <?= getStatusLabel($hist['old_status']) ?>
                                    <i class="bi bi-arrow-right text-muted small"></i>
                                <?php endif; ?>
                                <?= getStatusLabel($hist['new_status'] ?? '') ?>
                            </div>
                            <?php if (!empty($hist['actor_name'])): ?>
                            <p class="mb-0 small text-muted mt-1">
                                <i class="bi bi-person me-1"></i>
                                <?= htmlspecialchars($hist['actor_name'], ENT_QUOTES, 'UTF-8') ?>
                            </p>
                            <?php endif; ?>
                            <?php if (!empty($hist['note'])): ?>
                            <p class="mb-0 small text-muted mt-1 fst-italic">
                                <i class="bi bi-chat-left-text me-1"></i>
                                <?= htmlspecialchars($hist['note'], ENT_QUOTES, 'UTF-8') ?>
                            </p>
                            <?php endif; ?>
                        </div>
                        <div class="text-muted small text-nowrap">
                            <?= htmlspecialchars(
                                !empty($hist['changed_at'])
                                    ? date('d M Y, H:i', strtotime($hist['changed_at']))
                                    : '—',
                                ENT_QUOTES, 'UTF-8'
                            ) ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- ── Tombol kembali bawah ──────────────────────────────────────────────── -->
<div class="d-flex justify-content-end mb-2">
    <a href="<?= htmlspecialchars($backUrl, ENT_QUOTES, 'UTF-8') ?>"
       class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i>Kembali
    </a>
</div>

<?php require_once __DIR__ . '/../../includes/layout/footer.php'; ?>
