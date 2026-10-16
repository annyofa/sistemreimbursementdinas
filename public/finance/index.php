<?php

/**
 * public/finance/index.php
 *
 * Halaman daftar pengajuan reimbursement yang menunggu verifikasi finance.
 *
 * Fitur:
 *   - Hanya dapat diakses oleh user dengan role 'finance'
 *   - Query seluruh pengajuan berstatus 'review_finance', urut submitted_at ASC
 *   - Tabel: nama pengaju, departemen, tujuan, total nominal, tanggal masuk antrian, status
 *   - Tombol Verifikasi per baris menuju verifikasi.php?id={id}
 *
 * Requirements: 5.1
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

// Hanya finance yang boleh mengakses halaman ini
requireAuth();
requireRole(['finance']);

// ── Query seluruh pengajuan yang menunggu verifikasi finance ──────────────────
$db   = getDB();
$stmt = $db->query(
    "SELECT
         r.id,
         r.reimbursement_no,
         r.total_amount,
         r.status,
         r.submitted_at,
         u.name     AS pegawai_name,
         d.name     AS dept_name,
         bt.destination,
         bt.purpose
     FROM reimbursements r
     JOIN users u        ON u.id  = r.user_id
     JOIN departments d  ON d.id  = u.department_id
     LEFT JOIN business_trips bt ON bt.id = r.trip_id
     WHERE r.status = 'review_finance'
     ORDER BY r.submitted_at ASC"
);
$reimbursements = $stmt->fetchAll();

$pageTitle = 'Verifikasi Pengajuan';
require_once __DIR__ . '/../../includes/layout/header.php';
require_once __DIR__ . '/../../includes/layout/navbar.php';
?>

<!-- Judul halaman -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 fw-bold mb-0">
            <i class="bi bi-check2-square me-2 text-primary"></i>Verifikasi Pengajuan
        </h1>
        <p class="text-muted mb-0 mt-1 small">
            Daftar seluruh pengajuan reimbursement yang menunggu verifikasi finance.
        </p>
    </div>
    <?php if (!empty($reimbursements)): ?>
    <span class="badge bg-primary fs-6 px-3 py-2">
        <?= count($reimbursements) ?> Menunggu Verifikasi
    </span>
    <?php endif; ?>
</div>

<!-- Tabel pengajuan -->
<div class="card shadow-sm">
    <div class="card-body p-0">
        <?php if (empty($reimbursements)): ?>
            <div class="text-center py-5">
                <i class="bi bi-check2-circle fs-1 text-success d-block mb-3"></i>
                <p class="text-muted mb-0">Tidak ada pengajuan yang menunggu verifikasi saat ini.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th scope="col" class="ps-4">#</th>
                            <th scope="col">Nama Pengaju</th>
                            <th scope="col">Departemen</th>
                            <th scope="col">Tujuan</th>
                            <th scope="col" class="text-end">Total Nominal</th>
                            <th scope="col">Tanggal Masuk Antrian</th>
                            <th scope="col" class="text-center">Status</th>
                            <th scope="col" class="text-center">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($reimbursements as $i => $r): ?>
                        <tr>
                            <!-- Nomor urut -->
                            <td class="ps-4 text-muted small"><?= $i + 1 ?></td>

                            <!-- Nama pengaju -->
                            <td>
                                <span class="fw-semibold">
                                    <?= htmlspecialchars($r['pegawai_name'] ?? '—', ENT_QUOTES, 'UTF-8') ?>
                                </span>
                                <?php if (!empty($r['reimbursement_no'])): ?>
                                    <br>
                                    <span class="font-monospace text-muted small">
                                        <?= htmlspecialchars($r['reimbursement_no'], ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                <?php endif; ?>
                            </td>

                            <!-- Departemen -->
                            <td>
                                <?= htmlspecialchars($r['dept_name'] ?? '—', ENT_QUOTES, 'UTF-8') ?>
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

                            <!-- Total nominal -->
                            <td class="text-end fw-semibold text-nowrap">
                                <?= htmlspecialchars(
                                        formatRupiah((float) ($r['total_amount'] ?? 0)),
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                            </td>

                            <!-- Tanggal masuk antrian review_finance -->
                            <td class="text-nowrap small">
                                <?php if (!empty($r['submitted_at'])): ?>
                                    <?= htmlspecialchars(
                                            date('d M Y H:i', strtotime($r['submitted_at'])),
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>

                            <!-- Badge status -->
                            <td class="text-center">
                                <?= getStatusLabel($r['status'] ?? '') ?>
                            </td>

                            <!-- Tombol verifikasi -->
                            <td class="text-center">
                                <a
                                    href="<?= BASE_URL ?>/finance/verifikasi.php?id=<?= (int) $r['id'] ?>"
                                    class="btn btn-sm btn-primary"
                                    title="Verifikasi pengajuan ini"
                                >
                                    <i class="bi bi-check2-square me-1"></i>Verifikasi
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Ringkasan -->
            <div class="px-4 py-2 border-top text-muted small">
                Total <?= count($reimbursements) ?> pengajuan menunggu verifikasi
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/layout/footer.php'; ?>
