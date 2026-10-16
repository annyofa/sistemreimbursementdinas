<?php

/**
 * public/pegawai/index.php
 *
 * Halaman daftar pengajuan reimbursement milik pegawai yang sedang login.
 *
 * Fitur:
 *   - Menampilkan semua pengajuan milik user (user_id = session user)
 *   - Tabel: nomor pengajuan, tujuan perjalanan, tanggal, total (formatRupiah), badge status
 *   - Tombol Buat Pengajuan Baru dan Lihat Detail per baris
 *   - Urut berdasarkan created_at DESC
 *
 * Requirements: 2.5
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

// Pastikan sudah login dan hanya role pegawai yang boleh akses
requireAuth();
requireRole(['pegawai']);

$user   = getCurrentUser();
$userId = (int) $user['user_id'];

// ── Query daftar pengajuan milik pegawai ──────────────────────────────────────
$db   = getDB();
$stmt = $db->prepare(
    "SELECT
         r.id,
         r.reimbursement_no,
         r.total_amount,
         r.status,
         r.created_at,
         bt.destination,
         bt.purpose,
         bt.start_date,
         bt.end_date
     FROM reimbursements r
     LEFT JOIN business_trips bt ON bt.id = r.trip_id
     WHERE r.user_id = :user_id
     ORDER BY r.created_at DESC"
);
$stmt->execute([':user_id' => $userId]);
$reimbursements = $stmt->fetchAll();

$pageTitle = 'Pengajuan Saya';
require_once __DIR__ . '/../../includes/layout/header.php';
require_once __DIR__ . '/../../includes/layout/navbar.php';
?>

<!-- Judul halaman + tombol buat baru -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 fw-bold mb-0">
            <i class="bi bi-file-earmark-text me-2 text-primary"></i>Pengajuan Saya
        </h1>
        <p class="text-muted mb-0 mt-1 small">
            Daftar seluruh pengajuan reimbursement perjalanan dinas Anda.
        </p>
    </div>
    <a href="<?= BASE_URL ?>/pegawai/create.php" class="btn btn-primary">
        <i class="bi bi-plus-circle me-1"></i>Buat Pengajuan Baru
    </a>
</div>

<!-- Tabel pengajuan -->
<div class="card shadow-sm">
    <div class="card-body p-0">
        <?php if (empty($reimbursements)): ?>
            <div class="text-center py-5">
                <i class="bi bi-inbox fs-1 text-muted d-block mb-3"></i>
                <p class="text-muted mb-3">Anda belum memiliki pengajuan reimbursement.</p>
                <a href="<?= BASE_URL ?>/pegawai/create.php" class="btn btn-primary">
                    <i class="bi bi-plus-circle me-1"></i>Buat Pengajuan Pertama
                </a>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th scope="col" class="ps-4">#</th>
                            <th scope="col">Nomor Pengajuan</th>
                            <th scope="col">Tujuan Perjalanan</th>
                            <th scope="col">Tanggal</th>
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

                            <!-- Nomor pengajuan -->
                            <td>
                                <span class="fw-semibold font-monospace">
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

                            <!-- Tanggal perjalanan -->
                            <td class="text-nowrap">
                                <?php if (!empty($r['start_date']) && !empty($r['end_date'])): ?>
                                    <?= htmlspecialchars(
                                            date('d M Y', strtotime($r['start_date'])),
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>
                                    <span class="text-muted mx-1">–</span>
                                    <?= htmlspecialchars(
                                            date('d M Y', strtotime($r['end_date'])),
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>
                                <?php elseif (!empty($r['created_at'])): ?>
                                    <span class="text-muted small">
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

                            <!-- Total (formatRupiah) -->
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

                            <!-- Tombol aksi -->
                            <td class="text-center">
                                <a
                                    href="<?= BASE_URL ?>/pegawai/detail.php?id=<?= (int) $r['id'] ?>"
                                    class="btn btn-sm btn-outline-primary"
                                    title="Lihat Detail"
                                >
                                    <i class="bi bi-eye me-1"></i>Detail
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Ringkasan jumlah pengajuan -->
            <div class="px-4 py-2 border-top text-muted small">
                Total <?= count($reimbursements) ?> pengajuan
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/layout/footer.php'; ?>
