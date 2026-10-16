<?php

/**
 * public/pegawai/detail.php
 *
 * Detail pengajuan reimbursement milik pegawai:
 *   - Info perjalanan dinas
 *   - Tabel item biaya (deskripsi, kategori, nominal, status verifikasi, struk)
 *   - Timeline riwayat status
 *   - Form tambah item biaya (draft/perlu_revisi)
 *   - Area upload struk per item (placeholder — di-wire di task 6)
 *   - Tombol hapus item (draft/perlu_revisi)
 *   - Tombol submit (draft saja) + validasi minimal 1 item
 *
 * Requirements: 2.2, 2.3, 2.4, 2.5, 2.6, 2.8, 8.1
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireAuth();
requireRole(['pegawai']);

$user   = getCurrentUser();
$userId = (int) $user['user_id'];
$db     = getDB();

// ── Validasi parameter GET ?id= ───────────────────────────────────────────────
$reimbId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($reimbId <= 0) {
    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'ID pengajuan tidak valid.'];
    header('Location: ' . BASE_URL . '/pegawai/index.php');
    exit;
}

// ── Ambil data reimbursement (pastikan milik user ini) ────────────────────────
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
         bt.end_date
     FROM reimbursements r
     LEFT JOIN business_trips bt ON bt.id = r.trip_id
     WHERE r.id = :id AND r.user_id = :user_id"
);
$stmtR->execute([':id' => $reimbId, ':user_id' => $userId]);
$reimb = $stmtR->fetch();

if ($reimb === false) {
    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Pengajuan tidak ditemukan atau bukan milik Anda.'];
    header('Location: ' . BASE_URL . '/pegawai/index.php');
    exit;
}

// ── Ambil kategori aktif untuk form tambah item ───────────────────────────────
$stmtCat = $db->prepare(
    "SELECT id, name, max_amount FROM expense_categories WHERE is_active = 1 ORDER BY name ASC"
);
$stmtCat->execute();
$categories = $stmtCat->fetchAll();

// ── Ambil data user lengkap (untuk supervisor_id) ────────────────────────────
$stmtUser = $db->prepare(
    "SELECT supervisor_id FROM users WHERE id = :id"
);
$stmtUser->execute([':id' => $userId]);
$userRow = $stmtUser->fetch();
$supervisorId = $userRow ? (int) $userRow['supervisor_id'] : 0;

// =============================================================================
// POST HANDLERS
// =============================================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $action = $_POST['action'] ?? '';

    // ── Tambah Item Biaya ─────────────────────────────────────────────────────
    if ($action === 'add_item') {

        // Cegah edit jika status bukan draft atau perlu_revisi
        if (!canEdit($reimb)) {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Pengajuan tidak dapat diubah pada status saat ini.'];
            header('Location: ' . BASE_URL . '/pegawai/detail.php?id=' . $reimbId);
            exit;
        }

        // Sanitasi & validasi input
        $categoryId   = (int) ($_POST['category_id'] ?? 0);
        $expenseDate  = trim($_POST['expense_date'] ?? '');
        $merchant     = trim($_POST['merchant'] ?? '');
        $description  = trim($_POST['description'] ?? '');
        $amount       = (float) str_replace(['.', ','], ['', '.'], $_POST['amount'] ?? '0');

        $errors = [];
        if ($categoryId <= 0)      $errors[] = 'Kategori harus dipilih.';
        if ($expenseDate === '')    $errors[] = 'Tanggal pengeluaran harus diisi.';
        if ($description === '')    $errors[] = 'Deskripsi harus diisi.';
        if ($amount <= 0)           $errors[] = 'Nominal harus lebih dari 0.';

        // Validasi batas maksimal kategori
        if ($categoryId > 0 && $amount > 0) {
            $stmtMaxCheck = $db->prepare(
                "SELECT max_amount FROM expense_categories WHERE id = :id AND is_active = 1"
            );
            $stmtMaxCheck->execute([':id' => $categoryId]);
            $catRow = $stmtMaxCheck->fetch();
            if ($catRow && $catRow['max_amount'] > 0 && $amount > $catRow['max_amount']) {
                $errors[] = 'Nominal melebihi batas maksimal kategori (' . formatRupiah($catRow['max_amount']) . ').';
            }
        }

        if (!empty($errors)) {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => implode(' ', $errors)];
            header('Location: ' . BASE_URL . '/pegawai/detail.php?id=' . $reimbId);
            exit;
        }

        // INSERT item
        $stmtInsert = $db->prepare(
            "INSERT INTO reimbursement_items
                 (reimbursement_id, category_id, expense_date, merchant, description, amount,
                  approved_amount, verification_status)
             VALUES
                 (:reimbursement_id, :category_id, :expense_date, :merchant, :description, :amount,
                  NULL, 'belum')"
        );
        $stmtInsert->execute([
            ':reimbursement_id' => $reimbId,
            ':category_id'      => $categoryId,
            ':expense_date'     => $expenseDate,
            ':merchant'         => $merchant,
            ':description'      => $description,
            ':amount'           => $amount,
        ]);
        $newItemId = (int) $db->lastInsertId();

        // Audit log
        logAudit($userId, 'ADD_ITEM', 'reimbursement_items', $newItemId, [
            'reimbursement_id' => $reimbId,
            'category_id'      => $categoryId,
            'expense_date'     => $expenseDate,
            'merchant'         => $merchant,
            'description'      => $description,
            'amount'           => $amount,
        ]);

        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Item biaya berhasil ditambahkan.'];
        header('Location: ' . BASE_URL . '/pegawai/detail.php?id=' . $reimbId);
        exit;
    }

    // ── Submit Pengajuan ──────────────────────────────────────────────────────
    if ($action === 'submit') {

        // Hanya boleh submit jika status draft
        if ($reimb['status'] !== 'draft') {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Pengajuan hanya dapat diajukan saat berstatus draft.'];
            header('Location: ' . BASE_URL . '/pegawai/detail.php?id=' . $reimbId);
            exit;
        }

        // Validasi minimal 1 item biaya
        $stmtCount = $db->prepare(
            "SELECT COUNT(*) FROM reimbursement_items WHERE reimbursement_id = :rid"
        );
        $stmtCount->execute([':rid' => $reimbId]);
        $itemCount = (int) $stmtCount->fetchColumn();

        if ($itemCount === 0) {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Pengajuan harus memiliki minimal satu item biaya.'];
            header('Location: ' . BASE_URL . '/pegawai/detail.php?id=' . $reimbId);
            exit;
        }

        // Update status → 'diajukan' (updateReimbursementStatus menangani trigger, audit, notifikasi)
        try {
            updateReimbursementStatus($reimbId, 'diajukan', $userId, 'Pengajuan dikirim oleh pegawai.');

            // Set submitted_at
            $db->prepare(
                "UPDATE reimbursements SET submitted_at = NOW() WHERE id = :id"
            )->execute([':id' => $reimbId]);

            // Notifikasi tambahan ke atasan langsung (updateReimbursementStatus sudah memanggil
            // _sendStatusNotifications, tapi kita kirim juga secara eksplisit sesuai task spec)
            if ($supervisorId > 0) {
                createNotification(
                    $supervisorId,
                    $reimbId,
                    'Pengajuan Reimbursement Baru',
                    'Pegawai ' . htmlspecialchars($user['name'], ENT_QUOTES, 'UTF-8') .
                    ' telah mengajukan reimbursement ' .
                    htmlspecialchars($reimb['reimbursement_no'], ENT_QUOTES, 'UTF-8') .
                    ' dan membutuhkan review Anda.'
                );
            }

        } catch (InvalidStatusTransitionException $e) {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Transisi status tidak valid: ' . $e->getMessage()];
            header('Location: ' . BASE_URL . '/pegawai/detail.php?id=' . $reimbId);
            exit;
        } catch (\RuntimeException $e) {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Gagal mengajukan: ' . $e->getMessage()];
            header('Location: ' . BASE_URL . '/pegawai/detail.php?id=' . $reimbId);
            exit;
        }

        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Pengajuan berhasil dikirim dan sedang menunggu review atasan.'];
        header('Location: ' . BASE_URL . '/pegawai/index.php');
        exit;
    }
}

// =============================================================================
// DATA UNTUK TAMPILAN
// =============================================================================

// Item-item biaya beserta kategori dan struk
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
         ec.name AS category_name,
         ec.max_amount AS category_max,
         (SELECT COUNT(*) FROM receipts WHERE item_id = ri.id) AS receipt_count,
         (SELECT GROUP_CONCAT(id ORDER BY id ASC) FROM receipts WHERE item_id = ri.id) AS receipt_ids,
         (SELECT file_path FROM receipts WHERE item_id = ri.id ORDER BY id ASC LIMIT 1) AS first_receipt_path
     FROM reimbursement_items ri
     LEFT JOIN expense_categories ec ON ec.id = ri.category_id
     WHERE ri.reimbursement_id = :rid
     ORDER BY ri.expense_date ASC, ri.id ASC"
);
$stmtItems->execute([':rid' => $reimbId]);
$items = $stmtItems->fetchAll();

// Riwayat status
$history = getStatusHistory($reimbId);

// Periksa apakah bisa edit
$editable = canEdit($reimb);

// Revisi request (jika status perlu_revisi, tampilkan catatan revisi)
$revisionNotes = [];
if ($reimb['status'] === 'perlu_revisi') {
    $stmtRev = $db->prepare(
        "SELECT rr.message, rr.item_id, rr.requested_by, u.name AS reviewer_name, rr.status, rr.response
         FROM revision_requests rr
         LEFT JOIN users u ON u.id = rr.requested_by
         WHERE rr.reimbursement_id = :rid
         ORDER BY rr.id DESC"
    );
    $stmtRev->execute([':rid' => $reimbId]);
    $revisionNotes = $stmtRev->fetchAll();
}

// ── Render ────────────────────────────────────────────────────────────────────
$pageTitle = 'Detail Pengajuan ' . htmlspecialchars($reimb['reimbursement_no'] ?? '', ENT_QUOTES, 'UTF-8');
require_once __DIR__ . '/../../includes/layout/header.php';
require_once __DIR__ . '/../../includes/layout/navbar.php';

// Hitung total dari items (live)
$totalAmount = array_sum(array_column($items, 'amount'));
?>

<!-- Breadcrumb -->
<nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb">
        <li class="breadcrumb-item">
            <a href="<?= BASE_URL ?>/pegawai/index.php">Pengajuan Saya</a>
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
            <i class="bi bi-file-earmark-text me-2 text-primary"></i>
            <?= htmlspecialchars($reimb['reimbursement_no'] ?? '', ENT_QUOTES, 'UTF-8') ?>
        </h1>
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <?= getStatusLabel($reimb['status']) ?>
            <span class="text-muted small">
                Dibuat: <?= htmlspecialchars(date('d M Y H:i', strtotime($reimb['created_at'])), ENT_QUOTES, 'UTF-8') ?>
            </span>
            <?php if ($reimb['submitted_at']): ?>
            <span class="text-muted small">
                Diajukan: <?= htmlspecialchars(date('d M Y H:i', strtotime($reimb['submitted_at'])), ENT_QUOTES, 'UTF-8') ?>
            </span>
            <?php endif; ?>
        </div>
    </div>
    <!-- Tombol Submit (hanya tampil jika status draft) -->
    <?php if ($reimb['status'] === 'draft'): ?>
    <form method="POST" action="<?= BASE_URL ?>/pegawai/detail.php?id=<?= $reimbId ?>"
          id="form-submit-reimbursement"
          onsubmit="return confirmSubmit()">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="submit">
        <button type="submit" class="btn btn-success">
            <i class="bi bi-send me-1"></i>Ajukan ke Atasan
        </button>
    </form>
    <?php endif; ?>
</div>

<!-- ============================================================
     Notifikasi Revisi (muncul jika status perlu_revisi)
     ============================================================ -->
<?php if ($reimb['status'] === 'perlu_revisi' && !empty($revisionNotes)): ?>
<div class="alert alert-warning mb-4" role="alert">
    <h6 class="alert-heading fw-bold">
        <i class="bi bi-exclamation-triangle me-2"></i>Pengajuan Membutuhkan Revisi
    </h6>
    <hr>
    <?php foreach ($revisionNotes as $rev): ?>
    <div class="mb-2">
        <strong><?= htmlspecialchars($rev['reviewer_name'] ?? 'Reviewer', ENT_QUOTES, 'UTF-8') ?>:</strong>
        <?= htmlspecialchars($rev['message'] ?? '', ENT_QUOTES, 'UTF-8') ?>
        <?php if (!empty($rev['response'])): ?>
            <br><span class="text-muted small">Respons: <?= htmlspecialchars($rev['response'], ENT_QUOTES, 'UTF-8') ?></span>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="row g-4">

    <!-- ================================================================
         Kolom kiri: Info Perjalanan + Item Biaya
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
                        <label class="form-label text-muted small mb-0">Tujuan</label>
                        <p class="fw-semibold mb-0">
                            <?= htmlspecialchars($reimb['destination'] ?? '—', ENT_QUOTES, 'UTF-8') ?>
                        </p>
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label text-muted small mb-0">Keperluan</label>
                        <p class="fw-semibold mb-0">
                            <?= htmlspecialchars($reimb['purpose'] ?? '—', ENT_QUOTES, 'UTF-8') ?>
                        </p>
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label text-muted small mb-0">Tanggal Mulai</label>
                        <p class="fw-semibold mb-0">
                            <?= !empty($reimb['start_date'])
                                ? htmlspecialchars(date('d M Y', strtotime($reimb['start_date'])), ENT_QUOTES, 'UTF-8')
                                : '—' ?>
                        </p>
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label text-muted small mb-0">Tanggal Selesai</label>
                        <p class="fw-semibold mb-0">
                            <?= !empty($reimb['end_date'])
                                ? htmlspecialchars(date('d M Y', strtotime($reimb['end_date'])), ENT_QUOTES, 'UTF-8')
                                : '—' ?>
                        </p>
                    </div>
                    <?php if (!empty($reimb['notes'])): ?>
                    <div class="col-12">
                        <label class="form-label text-muted small mb-0">Catatan</label>
                        <p class="mb-0"><?= htmlspecialchars($reimb['notes'], ENT_QUOTES, 'UTF-8') ?></p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Tabel Item Biaya -->
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
            <div class="card-body p-0">
                <?php if (empty($items)): ?>
                    <div class="text-center py-4 text-muted">
                        <i class="bi bi-inbox fs-2 d-block mb-2"></i>
                        Belum ada item biaya. <?= $editable ? 'Tambahkan item di bawah.' : '' ?>
                    </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light small">
                            <tr>
                                <th class="ps-3">Deskripsi / Kategori</th>
                                <th>Tanggal</th>
                                <th>Merchant</th>
                                <th class="text-end">Nominal</th>
                                <th class="text-center">Verifikasi</th>
                                <th class="text-center">Struk</th>
                                <?php if ($editable): ?>
                                <th class="text-center">Hapus</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($items as $item): ?>
                            <tr id="item-row-<?= (int) $item['id'] ?>">
                                <!-- Deskripsi + Kategori -->
                                <td class="ps-3">
                                    <div class="fw-medium">
                                        <?= htmlspecialchars($item['description'], ENT_QUOTES, 'UTF-8') ?>
                                    </div>
                                    <span class="badge bg-light text-dark border small">
                                        <?= htmlspecialchars($item['category_name'] ?? '—', ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                    <?php if (!empty($item['category_max']) && (float)$item['amount'] > (float)$item['category_max']): ?>
                                    <span class="badge bg-warning text-dark ms-1 small" title="Melebihi batas kategori">
                                        <i class="bi bi-exclamation-triangle"></i> Melebihi batas
                                    </span>
                                    <?php endif; ?>
                                </td>

                                <!-- Tanggal -->
                                <td class="text-nowrap small">
                                    <?= !empty($item['expense_date'])
                                        ? htmlspecialchars(date('d M Y', strtotime($item['expense_date'])), ENT_QUOTES, 'UTF-8')
                                        : '—' ?>
                                </td>

                                <!-- Merchant -->
                                <td class="small">
                                    <?= htmlspecialchars($item['merchant'] ?? '—', ENT_QUOTES, 'UTF-8') ?>
                                </td>

                                <!-- Nominal -->
                                <td class="text-end text-nowrap fw-semibold">
                                    <?= htmlspecialchars(formatRupiah((float) $item['amount']), ENT_QUOTES, 'UTF-8') ?>
                                    <?php if (!empty($item['approved_amount']) && (float)$item['approved_amount'] !== (float)$item['amount']): ?>
                                    <br>
                                    <span class="small text-success">
                                        Disetujui: <?= htmlspecialchars(formatRupiah((float) $item['approved_amount']), ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                    <?php endif; ?>
                                </td>

                                <!-- Status Verifikasi -->
                                <td class="text-center">
                                    <?php
                                    $vs = $item['verification_status'] ?? 'belum';
                                    $vsMap = [
                                        'belum'        => ['label' => 'Belum Diverifikasi', 'class' => 'bg-secondary'],
                                        'diverifikasi' => ['label' => 'Diverifikasi',        'class' => 'bg-success'],
                                        'ditolak'      => ['label' => 'Ditolak',             'class' => 'bg-danger'],
                                    ];
                                    $vsEntry = $vsMap[$vs] ?? ['label' => htmlspecialchars($vs, ENT_QUOTES, 'UTF-8'), 'class' => 'bg-secondary'];
                                    ?>
                                    <span class="badge <?= $vsEntry['class'] ?>">
                                        <?= htmlspecialchars($vsEntry['label'], ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                    <?php if (!empty($item['verified_at'])): ?>
                                    <br><span class="text-muted" style="font-size:0.7rem;">
                                        <?= htmlspecialchars(date('d/m/Y', strtotime($item['verified_at'])), ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                    <?php endif; ?>
                                </td>

                                <!-- Area Struk (placeholder untuk task 6) -->
                                <td class="text-center">
                                    <div
                                        class="receipt-upload-area"
                                        id="receipt-area-<?= (int) $item['id'] ?>"
                                        data-item-id="<?= (int) $item['id'] ?>"
                                        data-reimb-id="<?= $reimbId ?>"
                                        data-editable="<?= $editable ? '1' : '0' ?>"
                                    >
                                        <?php if ((int) $item['receipt_count'] > 0): ?>
                                            <!-- Struk sudah ada: tampilkan ikon -->
                                            <button type="button"
                                                    class="btn btn-sm btn-outline-success receipt-view-btn"
                                                    data-item-id="<?= (int) $item['id'] ?>"
                                                    title="Lihat struk">
                                                <i class="bi bi-image me-1"></i><?= (int) $item['receipt_count'] ?>
                                            </button>
                                            <?php if ($editable): ?>
                                            <button type="button"
                                                    class="btn btn-sm btn-outline-secondary ms-1 receipt-upload-btn"
                                                    data-item-id="<?= (int) $item['id'] ?>"
                                                    title="Ganti/tambah struk">
                                                <i class="bi bi-upload"></i>
                                            </button>
                                            <?php endif; ?>
                                        <?php elseif ($editable): ?>
                                            <!-- Belum ada struk + bisa upload -->
                                            <button type="button"
                                                    class="btn btn-sm btn-outline-primary receipt-upload-btn"
                                                    data-item-id="<?= (int) $item['id'] ?>"
                                                    title="Upload struk">
                                                <i class="bi bi-upload me-1"></i>Upload
                                            </button>
                                        <?php else: ?>
                                            <span class="text-muted small">—</span>
                                        <?php endif; ?>
                                    </div>
                                </td>

                                <!-- Tombol Hapus (hanya jika editable) -->
                                <?php if ($editable): ?>
                                <td class="text-center">
                                    <form method="POST"
                                          action="<?= BASE_URL ?>/api/delete_item.php"
                                          class="form-delete-item"
                                          onsubmit="return confirm('Hapus item ini?')">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>">
                                        <input type="hidden" name="reimbursement_id" value="<?= $reimbId ?>">
                                        <button type="submit"
                                                class="btn btn-sm btn-outline-danger"
                                                title="Hapus item">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
                                </td>
                                <?php endif; ?>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="table-light">
                            <tr>
                                <td colspan="3" class="ps-3 fw-semibold text-end">
                                    Total Pengajuan:
                                </td>
                                <td class="text-end fw-bold text-primary text-nowrap">
                                    <?= htmlspecialchars(formatRupiah($totalAmount), ENT_QUOTES, 'UTF-8') ?>
                                </td>
                                <td colspan="<?= $editable ? 3 : 2 ?>"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Form Tambah Item Biaya (hanya jika editable) -->
        <?php if ($editable): ?>
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-white fw-semibold py-3">
                <i class="bi bi-plus-circle me-2 text-success"></i>Tambah Item Biaya
            </div>
            <div class="card-body">
                <form method="POST"
                      action="<?= BASE_URL ?>/pegawai/detail.php?id=<?= $reimbId ?>"
                      id="form-add-item"
                      novalidate>
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="add_item">

                    <div class="row g-3">
                        <!-- Kategori -->
                        <div class="col-sm-6">
                            <label for="category_id" class="form-label">
                                Kategori <span class="text-danger">*</span>
                            </label>
                            <select id="category_id" name="category_id"
                                    class="form-select" required>
                                <option value="">— Pilih Kategori —</option>
                                <?php foreach ($categories as $cat): ?>
                                <option value="<?= (int) $cat['id'] ?>"
                                        data-max="<?= (float) ($cat['max_amount'] ?? 0) ?>">
                                    <?= htmlspecialchars($cat['name'], ENT_QUOTES, 'UTF-8') ?>
                                    <?php if ($cat['max_amount'] > 0): ?>
                                        (maks. <?= htmlspecialchars(formatRupiah((float) $cat['max_amount']), ENT_QUOTES, 'UTF-8') ?>)
                                    <?php endif; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Tanggal Pengeluaran -->
                        <div class="col-sm-6">
                            <label for="expense_date" class="form-label">
                                Tanggal Pengeluaran <span class="text-danger">*</span>
                            </label>
                            <input type="date" id="expense_date" name="expense_date"
                                   class="form-control" required
                                   max="<?= date('Y-m-d') ?>">
                        </div>

                        <!-- Merchant -->
                        <div class="col-sm-6">
                            <label for="merchant" class="form-label">Merchant / Nama Toko</label>
                            <input type="text" id="merchant" name="merchant"
                                   class="form-control" maxlength="255"
                                   placeholder="Contoh: Restoran Padang Jaya">
                        </div>

                        <!-- Deskripsi -->
                        <div class="col-sm-6">
                            <label for="description" class="form-label">
                                Deskripsi <span class="text-danger">*</span>
                            </label>
                            <input type="text" id="description" name="description"
                                   class="form-control" maxlength="500"
                                   placeholder="Contoh: Makan siang hari pertama" required>
                        </div>

                        <!-- Nominal -->
                        <div class="col-sm-6">
                            <label for="amount" class="form-label">
                                Nominal (Rp) <span class="text-danger">*</span>
                            </label>
                            <div class="input-group">
                                <span class="input-group-text">Rp</span>
                                <input type="number" id="amount" name="amount"
                                       class="form-control" min="1" step="1"
                                       placeholder="0" required>
                            </div>
                            <div id="amount-hint" class="form-text text-muted"></div>
                        </div>

                        <!-- Tombol simpan -->
                        <div class="col-sm-6 d-flex align-items-end">
                            <button type="submit" class="btn btn-success w-100">
                                <i class="bi bi-plus-circle me-1"></i>Tambah Item
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>

    </div><!-- /col-lg-8 -->

    <!-- ================================================================
         Kolom kanan: Timeline Riwayat Status
         ================================================================ -->
    <div class="col-lg-4">
        <div class="card shadow-sm">
            <div class="card-header bg-white fw-semibold py-3">
                <i class="bi bi-clock-history me-2 text-primary"></i>Riwayat Status
            </div>
            <div class="card-body p-0">
                <?php if (empty($history)): ?>
                    <div class="text-center py-4 text-muted small">
                        <i class="bi bi-hourglass d-block fs-3 mb-2"></i>
                        Belum ada riwayat status.
                    </div>
                <?php else: ?>
                <ul class="list-group list-group-flush timeline-list">
                    <?php foreach ($history as $idx => $h): ?>
                    <li class="list-group-item py-3 px-3">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <div class="small mb-1">
                                    <?php if (!empty($h['old_status'])): ?>
                                        <?= getStatusLabel($h['old_status']) ?>
                                        <i class="bi bi-arrow-right mx-1 text-muted"></i>
                                    <?php endif; ?>
                                    <?= getStatusLabel($h['new_status']) ?>
                                </div>
                                <div class="text-muted" style="font-size:0.78rem;">
                                    <i class="bi bi-person me-1"></i>
                                    <?= htmlspecialchars($h['actor_name'] ?? 'Sistem', ENT_QUOTES, 'UTF-8') ?>
                                </div>
                                <?php if (!empty($h['note'])): ?>
                                <div class="mt-1 small text-secondary fst-italic">
                                    "<?= htmlspecialchars($h['note'], ENT_QUOTES, 'UTF-8') ?>"
                                </div>
                                <?php endif; ?>
                            </div>
                            <div class="text-end text-muted ms-2" style="font-size:0.72rem; white-space:nowrap;">
                                <?= !empty($h['changed_at'])
                                    ? htmlspecialchars(date('d M Y', strtotime($h['changed_at'])), ENT_QUOTES, 'UTF-8') . '<br>' .
                                      htmlspecialchars(date('H:i', strtotime($h['changed_at'])), ENT_QUOTES, 'UTF-8')
                                    : '' ?>
                            </div>
                        </div>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>

            <!-- Info status saat ini -->
            <div class="card-footer bg-white small text-muted">
                Status saat ini: <?= getStatusLabel($reimb['status']) ?>
                <?php if (!$editable && $reimb['status'] !== 'draft' && $reimb['status'] !== 'perlu_revisi'): ?>
                <div class="mt-1 text-info small">
                    <i class="bi bi-lock me-1"></i>Pengajuan tidak dapat diubah.
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div><!-- /col-lg-4 -->

</div><!-- /row -->

<!-- Hidden file input yang di-share untuk semua tombol upload struk -->
<input type="file"
       id="receipt-file-input"
       accept="image/jpeg,image/png,application/pdf"
       style="display:none;"
       aria-hidden="true">

<!-- Tesseract.js v4 dari CDN -->
<script src="https://cdn.jsdelivr.net/npm/tesseract.js@4/dist/tesseract.min.js"></script>

<?php require_once __DIR__ . '/../../includes/layout/footer.php'; ?>

<script>
(function () {
    'use strict';

    // ── Konfirmasi submit pengajuan ──────────────────────────────────────────
    window.confirmSubmit = function () {
        return confirm(
            'Setelah diajukan, pengajuan tidak dapat diubah kembali ke draft.\n\n' +
            'Lanjutkan mengajukan reimbursement ini?'
        );
    };

    // ── Hint batas maksimal kategori pada form tambah item ───────────────────
    var catSelect   = document.getElementById('category_id');
    var amountInput = document.getElementById('amount');
    var amountHint  = document.getElementById('amount-hint');

    if (catSelect && amountHint) {
        catSelect.addEventListener('change', function () {
            var selected = catSelect.options[catSelect.selectedIndex];
            var max = parseFloat(selected.getAttribute('data-max') || '0');
            if (max > 0) {
                amountHint.textContent = 'Batas maks: Rp ' + max.toLocaleString('id-ID');
                amountInput.max = max;
            } else {
                amountHint.textContent = '';
                amountInput.removeAttribute('max');
            }
        });

        // Warna merah jika nominal melebihi batas
        if (amountInput) {
            amountInput.addEventListener('input', function () {
                var selected = catSelect.options[catSelect.selectedIndex];
                var max = parseFloat(selected.getAttribute('data-max') || '0');
                var val = parseFloat(amountInput.value) || 0;
                if (max > 0 && val > max) {
                    amountInput.classList.add('is-invalid');
                    amountHint.classList.add('text-danger');
                    amountHint.classList.remove('text-muted');
                } else {
                    amountInput.classList.remove('is-invalid');
                    amountHint.classList.remove('text-danger');
                    amountHint.classList.add('text-muted');
                }
            });
        }
    }

    // =========================================================================
    // OCR Upload Struk — Tesseract.js Integration (Task 6.2)
    // Requirements: 3.1, 3.2, 3.3, 3.4, 3.5
    // =========================================================================

    var fileInput   = document.getElementById('receipt-file-input');
    var activeItemId = null; // item yang sedang diproses upload

    /**
     * Kembalikan elemen area struk berdasarkan item ID.
     */
    function getReceiptArea(itemId) {
        return document.getElementById('receipt-area-' + itemId);
    }

    /**
     * Tampilkan loading spinner di dalam area struk.
     */
    function showLoading(itemId, message) {
        var area = getReceiptArea(itemId);
        if (!area) return;
        area.innerHTML =
            '<div class="d-flex align-items-center justify-content-center gap-2 py-1">' +
            '  <div class="spinner-border spinner-border-sm text-primary" role="status" aria-label="Memproses…"></div>' +
            '  <span class="small text-muted">' + (message || 'Memproses…') + '</span>' +
            '</div>';
    }

    /**
     * Tampilkan pesan di area struk menggunakan Bootstrap alert.
     * type: 'success' | 'warning' | 'danger' | 'info'
     */
    function showAreaMessage(itemId, type, html) {
        var area = getReceiptArea(itemId);
        if (!area) return;
        area.innerHTML =
            '<div class="alert alert-' + type + ' alert-sm py-1 px-2 mb-0 small" role="alert">' +
            html +
            '</div>';
    }

    /**
     * Tampilkan thumbnail struk + ringkasan OCR di area struk.
     */
    function showReceiptResult(itemId, imageUrl, ocrSummaryHtml, canUploadMore) {
        var area = getReceiptArea(itemId);
        if (!area) return;

        var uploadMoreBtn = '';
        if (canUploadMore) {
            uploadMoreBtn =
                '<button type="button" class="btn btn-sm btn-outline-secondary ms-1 receipt-upload-btn-inline"' +
                ' data-item-id="' + itemId + '" title="Ganti/tambah struk">' +
                '<i class="bi bi-upload"></i>' +
                '</button>';
        }

        area.innerHTML =
            '<div class="d-flex align-items-center gap-1 flex-wrap">' +
            '  <a href="' + imageUrl + '" target="_blank" rel="noopener noreferrer">' +
            '    <img src="' + imageUrl + '" alt="Struk" style="max-height:48px;max-width:60px;object-fit:cover;border-radius:4px;border:1px solid #dee2e6;">' +
            '  </a>' +
            '  <div class="small">' + ocrSummaryHtml + '</div>' +
            uploadMoreBtn +
            '</div>';

        // Re-wire tombol inline upload-more jika ada
        area.querySelectorAll('.receipt-upload-btn-inline').forEach(function (btn) {
            btn.addEventListener('click', function () {
                triggerFileInput(btn.getAttribute('data-item-id'));
            });
        });
    }

    /**
     * Parse teks OCR mentah: total, tanggal, merchant.
     */
    function parseOcrText(rawText) {
        var result = { merchant: '', date: '', total: '' };

        // Merchant: baris pertama non-kosong
        var lines = rawText.split(/\r?\n/);
        for (var i = 0; i < lines.length; i++) {
            var line = lines[i].trim();
            if (line.length > 0) {
                result.merchant = line.substring(0, 100); // potong jika terlalu panjang
                break;
            }
        }

        // Total nominal: pola "total|jumlah|grand total" diikuti angka
        var totalMatch = rawText.match(/(?:total|jumlah|grand total)[^\d]*(\d[\d.,]+)/i);
        if (totalMatch) {
            // Normalkan angka: buang titik ribuan, ganti koma desimal → titik
            result.total = totalMatch[1].replace(/\./g, '').replace(',', '.');
        }

        // Tanggal: pola dd/mm/yyyy atau dd-mm-yyyy atau dd.mm.yyyy
        var dateMatch = rawText.match(/(\d{1,2})[\/\-\.](\d{1,2})[\/\-\.](\d{2,4})/);
        if (dateMatch) {
            var day   = dateMatch[1].padStart(2, '0');
            var month = dateMatch[2].padStart(2, '0');
            var year  = dateMatch[3];
            if (year.length === 2) {
                year = (parseInt(year, 10) < 50 ? '20' : '19') + year;
            }
            result.date = year + '-' + month + '-' + day; // format YYYY-MM-DD untuk input[type=date]
        }

        return result;
    }

    /**
     * Pra-isi field form tambah item jika form sedang tampil (status draft/perlu_revisi).
     */
    function prefillFormFields(parsed) {
        if (parsed.merchant) {
            var merchantInput = document.getElementById('merchant');
            if (merchantInput && merchantInput.value === '') {
                merchantInput.value = parsed.merchant;
            }
        }
        if (parsed.date) {
            var dateInput = document.getElementById('expense_date');
            if (dateInput && dateInput.value === '') {
                dateInput.value = parsed.date;
            }
        }
        if (parsed.total) {
            var amtInput = document.getElementById('amount');
            if (amtInput && amtInput.value === '' || amtInput.value === '0') {
                amtInput.value = Math.round(parseFloat(parsed.total) || 0);
            }
        }
    }

    /**
     * Simpan hasil OCR ke server via ocr_save.php.
     */
    function saveOcrResult(itemId, filePath, parsed, confidence, rawText) {
        var csrfToken = '';
        var csrfMeta = document.querySelector('meta[name="csrf-token"]');
        if (csrfMeta) csrfToken = csrfMeta.content;

        fetch(BASE_URL + '/api/ocr_save.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken
            },
            body: JSON.stringify({
                item_id:    itemId,
                file_path:  filePath,
                merchant:   parsed.merchant,
                date:       parsed.date,
                total:      parsed.total,
                confidence: confidence,
                raw_text:   rawText
            })
        }).catch(function (err) {
            // Simpan OCR gagal tidak kritis — log saja ke console
            console.warn('ocr_save.php error:', err);
        });
    }

    /**
     * Jalankan OCR dengan Tesseract.js pada URL gambar yang diberikan.
     * Req 3.1, 3.2, 3.3, 3.4, 3.5
     */
    function runOcr(itemId, imageUrl, filePath, canEdit) {
        showLoading(itemId, 'Membaca struk dengan OCR…');

        // Req 3.5 / 3.1: Tesseract.recognize dijalankan di try/catch
        Tesseract.recognize(imageUrl, 'ind+eng', { logger: function () {} })
            .then(function (result) {
                var rawText    = result.data.text || '';
                var confidence = result.data.confidence || 0;
                var parsed     = parseOcrText(rawText);

                // Simpan ke server (req 3.2) — fire-and-forget
                saveOcrResult(itemId, filePath, parsed, confidence, rawText);

                if (confidence >= 70) {
                    // Req 3.1 / 3.2: confidence cukup — pra-isi form + tampilkan hasil
                    prefillFormFields(parsed);

                    var summaryHtml =
                        '<span class="badge bg-success-subtle text-success border border-success-subtle me-1">' +
                        '<i class="bi bi-check-circle me-1"></i>OCR ' + Math.round(confidence) + '%</span>' +
                        (parsed.merchant ? '<br><strong>Merchant:</strong> ' + escapeHtml(parsed.merchant) : '') +
                        (parsed.date     ? '<br><strong>Tanggal:</strong> '  + escapeHtml(parsed.date)     : '') +
                        (parsed.total    ? '<br><strong>Total:</strong> Rp '  + Number(parsed.total).toLocaleString('id-ID') : '');

                    showReceiptResult(itemId, imageUrl, summaryHtml, canEdit);

                } else {
                    // Req 3.3: confidence < 70 — tampilkan peringatan buram
                    var area = getReceiptArea(itemId);
                    if (area) {
                        var uploadAgainBtn = canEdit
                            ? '<br><button type="button" class="btn btn-sm btn-outline-warning mt-1 receipt-upload-btn-inline"' +
                              ' data-item-id="' + itemId + '">Upload Ulang</button>'
                            : '';

                        area.innerHTML =
                            '<a href="' + imageUrl + '" target="_blank" rel="noopener noreferrer" class="d-block mb-1">' +
                            '  <img src="' + imageUrl + '" alt="Struk" style="max-height:40px;max-width:50px;object-fit:cover;border-radius:4px;border:1px solid #dee2e6;">' +
                            '</a>' +
                            '<div class="alert alert-warning py-1 px-2 mb-0 small" role="alert">' +
                            '<i class="bi bi-exclamation-triangle me-1"></i>' +
                            'Struk buram, mohon upload ulang dengan kualitas lebih baik ' +
                            '(confidence: ' + Math.round(confidence) + '%).' +
                            uploadAgainBtn +
                            '</div>';

                        // Re-wire tombol upload ulang
                        area.querySelectorAll('.receipt-upload-btn-inline').forEach(function (btn) {
                            btn.addEventListener('click', function () {
                                triggerFileInput(btn.getAttribute('data-item-id'));
                            });
                        });
                    }
                }
            })
            .catch(function (err) {
                // Req 3.5: OCR gagal — tampilkan pesan, izinkan lanjut tanpa OCR
                console.error('Tesseract error:', err);

                var area = getReceiptArea(itemId);
                if (area) {
                    area.innerHTML =
                        '<a href="' + imageUrl + '" target="_blank" rel="noopener noreferrer" class="d-block mb-1">' +
                        '  <img src="' + imageUrl + '" alt="Struk" style="max-height:40px;max-width:50px;object-fit:cover;border-radius:4px;border:1px solid #dee2e6;">' +
                        '</a>' +
                        '<div class="alert alert-info py-1 px-2 mb-0 small" role="alert">' +
                        'OCR tidak dapat memproses file ini. Anda dapat melanjutkan tanpa data OCR.' +
                        '</div>';
                }

                // Simpan ke server dengan confidence 0 dan teks kosong
                saveOcrResult(itemId, filePath, { merchant: '', date: '', total: '' }, 0, '');
            });
    }

    /**
     * Kirim file ke upload_receipt.php lalu jalankan OCR.
     * Req 3.1, 3.6
     */
    function uploadAndOcr(itemId, file, canEdit) {
        showLoading(itemId, 'Mengunggah struk…');

        var csrfToken = '';
        var csrfMeta = document.querySelector('meta[name="csrf-token"]');
        if (csrfMeta) csrfToken = csrfMeta.content;

        var formData = new FormData();
        formData.append('file', file);
        formData.append('item_id', itemId);
        formData.append('csrf_token', csrfToken);

        fetch(BASE_URL + '/api/upload_receipt.php', {
            method: 'POST',
            body: formData
        })
        .then(function (response) {
            if (!response.ok) {
                return response.json().then(function (data) {
                    throw new Error(data.error || 'Upload gagal (HTTP ' + response.status + ').');
                });
            }
            return response.json();
        })
        .then(function (data) {
            if (data.error) {
                throw new Error(data.error);
            }

            // Upload berhasil — ambil URL dan path file dari response
            var imageUrl  = data.url  || (UPLOADS_URL + '/' + data.file_path.replace(/^uploads\//, ''));
            var filePath  = data.file_path || '';

            // Jalankan OCR menggunakan Tesseract.js (req 3.1)
            showLoading(itemId, 'Membaca teks dari struk…');
            runOcr(itemId, imageUrl, filePath, canEdit);
        })
        .catch(function (err) {
            // Upload gagal — tampilkan pesan error
            showAreaMessage(
                itemId,
                'danger',
                '<i class="bi bi-x-circle me-1"></i>' + escapeHtml(err.message || 'Upload gagal.')
            );
        });
    }

    /**
     * Picu file picker yang tersembunyi untuk item tertentu.
     */
    function triggerFileInput(itemId) {
        activeItemId = itemId;
        fileInput.value = ''; // reset agar onChange selalu terpicu
        fileInput.click();
    }
    // Ekspos ke scope global agar tombol-tombol inline bisa memanggilnya
    window.openReceiptUpload = triggerFileInput;

    /**
     * Ketika pengguna memilih file dari dialog.
     */
    if (fileInput) {
        fileInput.addEventListener('change', function () {
            if (!fileInput.files || fileInput.files.length === 0) return;
            if (!activeItemId) return;

            var file    = fileInput.files[0];
            var itemId  = activeItemId;

            // Tentukan apakah item ini boleh diedit (pakai data-editable dari area div)
            var area     = getReceiptArea(itemId);
            var canEdit  = area ? area.getAttribute('data-editable') === '1' : false;

            uploadAndOcr(itemId, file, canEdit);

            // Reset agar pemilihan file berikutnya selalu memicu change
            fileInput.value = '';
            activeItemId = null;
        });
    }

    // ── Wire tombol "Upload Struk" dan "Lihat Struk" ─────────────────────────

    document.querySelectorAll('.receipt-upload-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            triggerFileInput(btn.getAttribute('data-item-id'));
        });
    });

    document.querySelectorAll('.receipt-view-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var itemId = btn.getAttribute('data-item-id');
            if (typeof window.openReceiptViewer === 'function') {
                window.openReceiptViewer(itemId);
            }
            // Tidak ada fallback alert — tombol view hanya muncul jika struk sudah ada,
            // sehingga link gambar langsung tersedia di area struk setelah OCR.
        });
    });

    // ── Utilitas ─────────────────────────────────────────────────────────────

    function escapeHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

}());
</script>
