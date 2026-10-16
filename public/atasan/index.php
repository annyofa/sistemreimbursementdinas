<?php

/**
 * public/atasan/index.php
 *
 * Halaman daftar pengajuan reimbursement bawahan langsung yang menunggu review.
 *
 * Fitur:
 *   - Hanya dapat diakses oleh user dengan role 'atasan'
 *   - Query pengajuan bawahan langsung (supervisor_id = atasan yang login)
 *     dengan status 'diajukan' atau 'review_atasan', urut submitted_at ASC
 *   - Tabel: nama pegawai, tujuan perjalanan, tanggal pengajuan, total, status
 *   - Tombol Review per baris menuju review.php?id={id}
 *
 * Requirements: 4.1
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

// Hanya atasan yang boleh mengakses halaman ini
requireAuth();
requireRole(['atasan']);

$user   = getCurrentUser();
$userId = (int) $user['user_id'];

// ── Query pengajuan bawahan yang menunggu review ──────────────────────────────
$db   = getDB();
$stmt = $db->prepare(
    "SELECT
         r.id,
         r.reimbursement_no,
         r.total_amount,
         r.status,
         r.submitted_at,
         r.created_at,
         u.name     AS pegawai_name,
         bt.destination,
         bt.purpose,
         bt.start_date,
         bt.end_date
     FROM reimbursements r
     JOIN users u ON u.id = r.user_id
     LEFT JOIN business_trips bt ON bt.id = r.trip_id
     WHERE u.supervisor_id = :supervisor_id
       AND r.status IN ('diajukan', 'review_atasan')
     ORDER BY r.submitted_at ASC"
);
$stmt->execute([':supervisor_id' => $userId]);
$reimbursements = $stmt->fetchAll();

$pageTitle = 'Review Pengajuan Bawahan';
require_once __DIR__ . '/../../includes/layout/header.php';
require_once __DIR__ . '/../../includes/layout/navbar.php';
?>

<!-- Judul halaman -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 fw-bold mb-0">
            <i class="bi bi-clipboard-check me-2 text-primary"></i>Review Pengajuan
        </h1>
        <p class="text-muted mb-0 mt-1 small">
            Daftar pengajuan reimbursement bawahan langsung yang menunggu review Anda.
        </p>
    </div>
    <?php if (!empty($reimbursements)): ?>
    <span class="badge bg-primary fs-6 px-3 py-2">
        <?= count($reimbursements) ?> Menunggu Review
    </span>
    <?php endif; ?>
</div>

<!-- Tabel pengajuan -->
<div class="card shadow-sm">
    <div class="card-body p-0">
        <?php if (empty($reimbursements)): ?>
            <div class="text-center py-5">
                <i class="bi bi-check2-circle fs-1 text-success d-block mb-3"></i>
                <p class="text-muted mb-0">Tidak ada pengajuan yang menunggu review saat ini.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th scope="col" class="ps-4">#</th>
                            <th scope="col">Pegawai</th>
                            <th scope="col">Nomor Pengajuan</th>
                            <th scope="col">Tujuan Perjalanan</th>
                            <th scope="col">Tanggal Pengajuan</th>
                            <th scope="col" class="text-end">Total</th>
                            <th scope="col" class="text-center">Status</th>
                            <th scope="col" class="text-center">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($reimbursements as $i => $r): ?>
                        <tr>
                            <!-- Nomor urut -->
                            <td class="ps-4 text-muted small"><?= $i + 1 ?></td>

                            <!-- Nama pegawai -->
                            <td>
                                <span class="fw-semibold">
                                    <?= htmlspecialchars($r['pegawai_name'] ?? '—', ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            </td>

                            <!-- Nomor pengajuan -->
                            <td>
                                <span class="font-monospace small">
                                    <?= htmlspecialchars($r['reimbursement_no'] ?? '-', ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            </td>

                            <!-- Tujuan perjalanan -->
                            <td>
                                <?php if (!empty($r['destination'])): ?>
                                    <span class="fw-medium">
                                        <?= htmlspecialchars($r['destination'], ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                    <?php if (!empty($r['purpose'])): ?>
                                        <br>
                                        <span class="text-muted small">
                                            <?= htmlspecialchars($r['purpose'], ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>

                            <!-- Tanggal pengajuan -->
                            <td class="text-nowrap small">
                                <?php if (!empty($r['submitted_at'])): ?>
                                    <?= htmlspecialchars(
                                            date('d M Y H:i', strtotime($r['submitted_at'])),
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>
                                <?php elseif (!empty($r['created_at'])): ?>
                                    <span class="text-muted">
                                        <?= htmlspecialchars(
                                                date('d M Y', strtotime($r['created_at'])),
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>

                            <!-- Total -->
                            <td class="text-end fw-semibold text-nowrap">
                                <?= htmlspecialchars(
                                        formatRupiah((float) ($r['total_amount'] ?? 0)),
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                            </td>

                            <!-- Badge status -->
                            <td class="text-center">
                                <?= getStatusLabel($r['status'] ?? '') ?>
                            </td>

                            <!-- Tombol review -->
                            <td class="text-center">
                                <a
                                    href="<?= BASE_URL ?>/atasan/review.php?id=<?= (int) $r['id'] ?>"
                                    class="btn btn-sm btn-primary"
                                    title="Review pengajuan ini"
                                >
                                    <i class="bi bi-clipboard-check me-1"></i>Review
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Ringkasan -->
            <div class="px-4 py-2 border-top text-muted small">
                Total <?= count($reimbursements) ?> pengajuan menunggu review
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/layout/footer.php'; ?>
