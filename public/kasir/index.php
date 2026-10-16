<?php

/**
 * public/kasir/index.php
 *
 * Halaman daftar pengajuan reimbursement siap dibayar oleh kasir.
 *
 * Fitur:
 *   - Hanya dapat diakses oleh user dengan role 'kasir'
 *   - Query seluruh pengajuan berstatus 'disetujui', urut updated_at ASC
 *     (updated_at merepresentasikan tanggal persetujuan terakhir)
 *   - Tabel: nama pegawai, departemen, total nominal, tanggal persetujuan
 *   - Tombol Bayar per baris menuju bayar.php?id={id}
 *
 * Requirements: 6.1
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

// Hanya kasir yang boleh mengakses halaman ini
requireAuth();
requireRole(['kasir']);

// ── Query seluruh pengajuan yang siap dibayar ─────────────────────────────────
$db   = getDB();
$stmt = $db->query(
    "SELECT
         r.id,
         r.reimbursement_no,
         r.total_amount,
         r.status,
         r.updated_at,
         u.name     AS pegawai_name,
         d.name     AS dept_name
     FROM reimbursements r
     JOIN users u        ON u.id = r.user_id
     JOIN departments d  ON d.id = u.department_id
     WHERE r.status = 'disetujui'
     ORDER BY r.updated_at ASC"
);
$reimbursements = $stmt->fetchAll();

$pageTitle = 'Daftar Pembayaran';
require_once __DIR__ . '/../../includes/layout/header.php';
require_once __DIR__ . '/../../includes/layout/navbar.php';
?>

<!-- Judul halaman -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 fw-bold mb-0">
            <i class="bi bi-cash-coin me-2 text-primary"></i>Daftar Pembayaran
        </h1>
        <p class="text-muted mb-0 mt-1 small">
            Daftar seluruh pengajuan reimbursement yang telah disetujui dan siap dibayarkan.
        </p>
    </div>
    <?php if (!empty($reimbursements)): ?>
    <span class="badge bg-primary fs-6 px-3 py-2">
        <?= count($reimbursements) ?> Siap Dibayar
    </span>
    <?php endif; ?>
</div>

<!-- Tabel pengajuan -->
<div class="card shadow-sm">
    <div class="card-body p-0">
        <?php if (empty($reimbursements)): ?>
            <div class="text-center py-5">
                <i class="bi bi-check2-circle fs-1 text-success d-block mb-3"></i>
                <p class="text-muted mb-0">Tidak ada pengajuan yang siap dibayar saat ini.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th scope="col" class="ps-4">#</th>
                            <th scope="col">Nama Pegawai</th>
                            <th scope="col">Departemen</th>
                            <th scope="col">Nomor Pengajuan</th>
                            <th scope="col" class="text-end">Total Nominal</th>
                            <th scope="col">Tanggal Persetujuan</th>
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

                            <!-- Departemen -->
                            <td>
                                <?= htmlspecialchars($r['dept_name'] ?? '—', ENT_QUOTES, 'UTF-8') ?>
                            </td>

                            <!-- Nomor pengajuan -->
                            <td>
                                <?php if (!empty($r['reimbursement_no'])): ?>
                                    <span class="font-monospace small">
                                        <?= htmlspecialchars($r['reimbursement_no'], ENT_QUOTES, 'UTF-8') ?>
                                    </span>
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

                            <!-- Tanggal persetujuan (updated_at) -->
                            <td class="text-nowrap small">
                                <?php if (!empty($r['updated_at'])): ?>
                                    <?= htmlspecialchars(
                                            date('d M Y H:i', strtotime($r['updated_at'])),
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

                            <!-- Tombol bayar -->
                            <td class="text-center">
                                <a
                                    href="<?= BASE_URL ?>/kasir/bayar.php?id=<?= (int) $r['id'] ?>"
                                    class="btn btn-sm btn-success"
                                    title="Proses pembayaran pengajuan ini"
                                >
                                    <i class="bi bi-cash-coin me-1"></i>Bayar
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Ringkasan -->
            <div class="px-4 py-2 border-top text-muted small">
                Total <?= count($reimbursements) ?> pengajuan siap dibayar
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/layout/footer.php'; ?>
