<?php

/**
 * public/pegawai/revisi.php
 *
 * Halaman untuk pegawai menjawab revision request dari atasan atau finance.
 *
 * Alur:
 *  GET  ?id={reimbursement_id} — tampilkan daftar revision_requests terbuka + form jawaban
 *  POST — update setiap revision_request, tentukan status baru, update reimbursement,
 *         kirim notifikasi ke pembuat request, catat audit, redirect ke detail.php
 *
 * Requirements: 2.7
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireAuth();
requireRole(['pegawai']);

$user   = getCurrentUser();
$userId = (int) $user['user_id'];
$db     = getDB();

// ── Ambil dan validasi parameter id ──────────────────────────────────────────
$reimbId = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($reimbId <= 0) {
    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'ID pengajuan tidak valid.'];
    header('Location: ' . BASE_URL . '/pegawai/index.php');
    exit;
}

// ── Ambil data reimbursement; pastikan milik user ini dan status = perlu_revisi ─
$stmtReimb = $db->prepare(
    "SELECT r.id, r.reimbursement_no, r.status, r.total_amount,
            bt.destination, bt.purpose, bt.start_date, bt.end_date
     FROM reimbursements r
     LEFT JOIN business_trips bt ON bt.id = r.trip_id
     WHERE r.id = :id
       AND r.user_id = :user_id"
);
$stmtReimb->execute([':id' => $reimbId, ':user_id' => $userId]);
$reimb = $stmtReimb->fetch();

if ($reimb === false) {
    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Pengajuan tidak ditemukan atau bukan milik Anda.'];
    header('Location: ' . BASE_URL . '/pegawai/index.php');
    exit;
}

if ($reimb['status'] !== 'perlu_revisi') {
    $_SESSION['flash'] = [
        'type'    => 'warning',
        'message' => 'Pengajuan ini tidak sedang dalam status perlu revisi.',
    ];
    header('Location: ' . BASE_URL . '/pegawai/detail.php?id=' . $reimbId);
    exit;
}

// ── Ambil semua revision_requests terbuka untuk pengajuan ini ────────────────
$stmtRevs = $db->prepare(
    "SELECT rr.id, rr.item_id, rr.requested_by, rr.message, rr.status,
            u.name AS requester_name, u.role AS requester_role
     FROM revision_requests rr
     JOIN users u ON u.id = rr.requested_by
     WHERE rr.reimbursement_id = :reimb_id
       AND rr.status = 'terbuka'
     ORDER BY rr.id ASC"
);
$stmtRevs->execute([':reimb_id' => $reimbId]);
$revisions = $stmtRevs->fetchAll();

if (empty($revisions)) {
    $_SESSION['flash'] = [
        'type'    => 'info',
        'message' => 'Tidak ada revision request yang terbuka untuk pengajuan ini.',
    ];
    header('Location: ' . BASE_URL . '/pegawai/detail.php?id=' . $reimbId);
    exit;
}

// ── Proses POST ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $errors = [];

    // Kumpulkan jawaban per revision_request
    $responses = $_POST['response'] ?? [];

    // Validasi: semua revision harus dijawab
    foreach ($revisions as $rev) {
        $rid      = (int) $rev['id'];
        $response = trim($responses[$rid] ?? '');
        if ($response === '') {
            $errors[] = 'Jawaban untuk catatan revisi #' . $rid . ' tidak boleh kosong.';
        }
    }

    if (empty($errors)) {
        try {
            $db->beginTransaction();

            // Update setiap revision_request
            $stmtUpdate = $db->prepare(
                "UPDATE revision_requests
                 SET response     = :response,
                     status       = 'dijawab',
                     responded_at = NOW()
                 WHERE id = :id
                   AND reimbursement_id = :reimb_id
                   AND status = 'terbuka'"
            );

            foreach ($revisions as $rev) {
                $rid      = (int) $rev['id'];
                $response = trim($responses[$rid] ?? '');
                $stmtUpdate->execute([
                    ':response'  => $response,
                    ':id'        => $rid,
                    ':reimb_id'  => $reimbId,
                ]);
            }

            // Tentukan status baru berdasarkan role pembuat revision_request.
            // Jika ada satu saja requester dengan role='finance' → 'review_finance',
            // jika semua dari 'atasan' → 'diajukan'.
            $newStatus = 'diajukan'; // default: dari atasan
            foreach ($revisions as $rev) {
                if ($rev['requester_role'] === 'finance') {
                    $newStatus = 'review_finance';
                    break;
                }
            }

            // Update status reimbursement
            updateReimbursementStatus($reimbId, $newStatus, $userId, 'Pegawai menjawab revision request.');

            // Kirim notifikasi ke setiap pembuat revision_request (unik per user)
            $notifiedUsers = [];
            foreach ($revisions as $rev) {
                $requesterId = (int) $rev['requested_by'];
                if (!in_array($requesterId, $notifiedUsers, true)) {
                    createNotification(
                        $requesterId,
                        $reimbId,
                        'Revision Request Dijawab',
                        'Pegawai telah menjawab revision request pada pengajuan ' .
                            htmlspecialchars($reimb['reimbursement_no'], ENT_QUOTES, 'UTF-8') . '.'
                    );
                    $notifiedUsers[] = $requesterId;
                }
            }

            // Catat audit
            logAudit(
                $userId,
                'ANSWER_REVISION',
                'revision_requests',
                $reimbId,
                [
                    'reimbursement_no' => $reimb['reimbursement_no'],
                    'new_status'       => $newStatus,
                    'revision_ids'     => array_column($revisions, 'id'),
                ]
            );

            $db->commit();

            $_SESSION['flash'] = [
                'type'    => 'success',
                'message' => 'Jawaban revisi berhasil dikirim. Pengajuan telah dilanjutkan.',
            ];
            header('Location: ' . BASE_URL . '/pegawai/detail.php?id=' . $reimbId);
            exit;

        } catch (\Throwable $e) {
            $db->rollBack();
            $errors[] = 'Terjadi kesalahan saat menyimpan jawaban. Silakan coba lagi.';
        }
    }
}

// ── Render halaman ────────────────────────────────────────────────────────────
$pageTitle = 'Jawab Revisi Pengajuan';
require_once __DIR__ . '/../../includes/layout/header.php';
require_once __DIR__ . '/../../includes/layout/navbar.php';
?>

<!-- Breadcrumb -->
<nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb">
        <li class="breadcrumb-item">
            <a href="<?= BASE_URL ?>/pegawai/index.php">Pengajuan Saya</a>
        </li>
        <li class="breadcrumb-item">
            <a href="<?= BASE_URL ?>/pegawai/detail.php?id=<?= $reimbId ?>">
                <?= htmlspecialchars($reimb['reimbursement_no'], ENT_QUOTES, 'UTF-8') ?>
            </a>
        </li>
        <li class="breadcrumb-item active" aria-current="page">Jawab Revisi</li>
    </ol>
</nav>

<!-- Judul -->
<div class="d-flex justify-content-between align-items-start mb-4">
    <div>
        <h1 class="h3 fw-bold mb-0">
            <i class="bi bi-pencil-square me-2 text-warning"></i>Jawab Revision Request
        </h1>
        <p class="text-muted mb-0 mt-1 small">
            Nomor Pengajuan:
            <span class="font-monospace fw-semibold">
                <?= htmlspecialchars($reimb['reimbursement_no'], ENT_QUOTES, 'UTF-8') ?>
            </span>
            &mdash; <?= getStatusLabel($reimb['status']) ?>
        </p>
    </div>
    <a href="<?= BASE_URL ?>/pegawai/detail.php?id=<?= $reimbId ?>"
       class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i>Kembali
    </a>
</div>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger" role="alert">
        <i class="bi bi-exclamation-triangle-fill me-2"></i>
        <strong>Terdapat kesalahan:</strong>
        <ul class="mb-0 mt-2">
            <?php foreach ($errors as $err): ?>
                <li><?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<!-- Info perjalanan -->
<?php if (!empty($reimb['destination'])): ?>
<div class="card mb-4 border-0 shadow-sm">
    <div class="card-body">
        <h6 class="card-title text-muted mb-2">
            <i class="bi bi-geo-alt me-1"></i>Detail Perjalanan
        </h6>
        <div class="row g-2 small">
            <div class="col-md-4">
                <span class="text-muted">Tujuan:</span>
                <span class="fw-medium ms-1">
                    <?= htmlspecialchars($reimb['destination'], ENT_QUOTES, 'UTF-8') ?>
                </span>
            </div>
            <?php if (!empty($reimb['purpose'])): ?>
            <div class="col-md-4">
                <span class="text-muted">Keperluan:</span>
                <span class="ms-1">
                    <?= htmlspecialchars($reimb['purpose'], ENT_QUOTES, 'UTF-8') ?>
                </span>
            </div>
            <?php endif; ?>
            <?php if (!empty($reimb['start_date']) && !empty($reimb['end_date'])): ?>
            <div class="col-md-4">
                <span class="text-muted">Tanggal:</span>
                <span class="ms-1">
                    <?= htmlspecialchars(date('d M Y', strtotime($reimb['start_date'])), ENT_QUOTES, 'UTF-8') ?>
                    &ndash;
                    <?= htmlspecialchars(date('d M Y', strtotime($reimb['end_date'])), ENT_QUOTES, 'UTF-8') ?>
                </span>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Form jawaban revisi -->
<form method="POST"
      action="<?= BASE_URL ?>/pegawai/revisi.php?id=<?= $reimbId ?>"
      novalidate>
    <?= csrfField() ?>

    <?php foreach ($revisions as $idx => $rev): ?>
        <?php $revId = (int) $rev['id']; ?>
        <div class="card mb-4 shadow-sm border-start border-warning border-3">
            <div class="card-header bg-transparent d-flex align-items-center gap-2 py-2">
                <i class="bi bi-chat-left-text text-warning"></i>
                <span class="fw-semibold">Catatan Revisi #<?= $idx + 1 ?></span>
                <span class="ms-auto text-muted small">
                    Dari: <strong><?= htmlspecialchars($rev['requester_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                    (<?= htmlspecialchars(ucfirst($rev['requester_role']), ENT_QUOTES, 'UTF-8') ?>)
                </span>
            </div>
            <div class="card-body">
                <!-- Pesan dari atasan/finance -->
                <div class="mb-3">
                    <label class="form-label text-muted small fw-semibold mb-1">
                        <i class="bi bi-quote me-1"></i>Catatan dari
                        <?= htmlspecialchars(ucfirst($rev['requester_role']), ENT_QUOTES, 'UTF-8') ?>:
                    </label>
                    <div class="p-3 bg-light rounded border">
                        <?= nl2br(htmlspecialchars($rev['message'], ENT_QUOTES, 'UTF-8')) ?>
                    </div>
                </div>

                <!-- Textarea jawaban -->
                <div class="mb-0">
                    <label for="response_<?= $revId ?>"
                           class="form-label fw-semibold">
                        Jawaban Anda <span class="text-danger">*</span>
                    </label>
                    <textarea
                        id="response_<?= $revId ?>"
                        name="response[<?= $revId ?>]"
                        class="form-control<?= isset($errors) && !empty($errors) ? ' is-invalid' : '' ?>"
                        rows="4"
                        placeholder="Tuliskan penjelasan atau koreksi Anda di sini…"
                        required
                    ><?= htmlspecialchars(
                        isset($_POST['response'][$revId])
                            ? $_POST['response'][$revId]
                            : '',
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?></textarea>
                    <div class="form-text text-muted">
                        Jawaban Anda akan diteruskan ke
                        <?= htmlspecialchars($rev['requester_name'], ENT_QUOTES, 'UTF-8') ?>.
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>

    <!-- Informasi tindak lanjut -->
    <?php
    // Tentukan status tujuan untuk ditampilkan ke pengguna
    $previewStatus = 'diajukan';
    foreach ($revisions as $rev) {
        if ($rev['requester_role'] === 'finance') {
            $previewStatus = 'review_finance';
            break;
        }
    }
    ?>
    <div class="alert alert-info d-flex align-items-start gap-2 mb-4" role="alert">
        <i class="bi bi-info-circle-fill fs-5 flex-shrink-0 mt-1"></i>
        <div>
            Setelah mengirim jawaban, status pengajuan akan berubah menjadi
            <?= getStatusLabel($previewStatus) ?>.
        </div>
    </div>

    <!-- Tombol aksi -->
    <div class="d-flex gap-2 justify-content-end">
        <a href="<?= BASE_URL ?>/pegawai/detail.php?id=<?= $reimbId ?>"
           class="btn btn-outline-secondary">
            <i class="bi bi-x-circle me-1"></i>Batal
        </a>
        <button type="submit" class="btn btn-warning fw-semibold">
            <i class="bi bi-send me-1"></i>Kirim Jawaban
        </button>
    </div>
</form>

<?php require_once __DIR__ . '/../../includes/layout/footer.php'; ?>
