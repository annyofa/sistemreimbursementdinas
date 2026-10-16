<?php

/**
 * public/manajemen/dashboard.php
 *
 * Dashboard KPI untuk role Manajemen.
 *
 * Fitur:
 *   - Kartu KPI: Total Pengajuan, Total Nominal, Jumlah Dibayar
 *   - Tabel breakdown per status untuk periode yang dipilih
 *   - Filter periode via GET parameter ?bulan=YYYY-MM (default bulan berjalan)
 *   - Auto-submit saat nilai input[type=month] berubah
 *
 * Requirements: 7.1, 7.2
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireAuth();
requireRole(['manajemen']);

// ── Periode filter ─────────────────────────────────────────────────────────────

// Validasi dan sanitasi parameter bulan (format: YYYY-MM)
$bulanRaw = trim($_GET['bulan'] ?? '');
if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $bulanRaw)) {
    $bulan = $bulanRaw;
} else {
    $bulan = date('Y-m'); // default: bulan berjalan
}

[$tahun, $bulanAngka] = explode('-', $bulan);
$tahun      = (int) $tahun;
$bulanAngka = (int) $bulanAngka;

// Label human-readable untuk judul
$bulanLabel = date('F Y', mktime(0, 0, 0, $bulanAngka, 1, $tahun));

// ── Query KPI dari view v_rekap_bulanan ────────────────────────────────────────
$db = getDB();

$stmtKpi = $db->prepare(
    "SELECT
         jumlah_pengajuan,
         COALESCE(total_pengajuan, 0)  AS total_pengajuan,
         COALESCE(total_dibayar, 0)    AS total_dibayar,
         rata_rata_hari_proses
     FROM v_rekap_bulanan
     WHERE YEAR(STR_TO_DATE(CONCAT(bulan, '-01'), '%Y-%m-%d')) = :tahun
       AND MONTH(STR_TO_DATE(CONCAT(bulan, '-01'), '%Y-%m-%d')) = :bulan"
);
$stmtKpi->execute([':tahun' => $tahun, ':bulan' => $bulanAngka]);
$kpi = $stmtKpi->fetch();

// Fallback jika tidak ada data untuk periode ini
if ($kpi === false) {
    $kpi = [
        'jumlah_pengajuan'     => 0,
        'total_pengajuan'      => 0,
        'total_dibayar'        => 0,
        'rata_rata_hari_proses' => null,
    ];
}

// ── Query breakdown per status ─────────────────────────────────────────────────
$stmtStatus = $db->prepare(
    "SELECT status, COUNT(*) AS jumlah
     FROM reimbursements
     WHERE YEAR(created_at) = :tahun
       AND MONTH(created_at) = :bulan
     GROUP BY status
     ORDER BY
         FIELD(status,
             'draft', 'diajukan', 'review_atasan', 'review_finance',
             'perlu_revisi', 'disetujui', 'dibayar', 'ditolak'
         )"
);
$stmtStatus->execute([':tahun' => $tahun, ':bulan' => $bulanAngka]);
$breakdown = $stmtStatus->fetchAll();

// Hitung total seluruh baris breakdown
$totalBreakdown = array_sum(array_column($breakdown, 'jumlah'));

// ── Render ─────────────────────────────────────────────────────────────────────
$pageTitle = 'Dashboard KPI Manajemen';
require_once __DIR__ . '/../../includes/layout/header.php';
require_once __DIR__ . '/../../includes/layout/navbar.php';
?>

<!-- Judul halaman + filter periode -->
<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div>
        <h1 class="h3 fw-bold mb-0">
            <i class="bi bi-speedometer2 me-2 text-primary"></i>Dashboard KPI
        </h1>
        <p class="text-muted mb-0 mt-1 small">
            Ringkasan kinerja reimbursement periode
            <strong><?= htmlspecialchars($bulanLabel, ENT_QUOTES, 'UTF-8') ?></strong>
        </p>
    </div>

    <!-- Form filter periode (GET, auto-submit on change) -->
    <form method="GET" action="<?= BASE_URL ?>/manajemen/dashboard.php" class="d-flex align-items-center gap-2">
        <label for="bulan-filter" class="form-label mb-0 fw-medium text-nowrap">
            <i class="bi bi-calendar3 me-1"></i>Periode:
        </label>
        <input
            type="month"
            id="bulan-filter"
            name="bulan"
            class="form-control form-control-sm"
            value="<?= htmlspecialchars($bulan, ENT_QUOTES, 'UTF-8') ?>"
            max="<?= htmlspecialchars(date('Y-m'), ENT_QUOTES, 'UTF-8') ?>"
            onchange="this.form.submit()"
            aria-label="Pilih periode bulan"
            style="width: 160px;"
        >
        <noscript>
            <button type="submit" class="btn btn-sm btn-primary">Tampilkan</button>
        </noscript>
    </form>
</div>

<!-- ── KPI Cards ──────────────────────────────────────────────────────────────── -->
<div class="row g-4 mb-4">

    <!-- Card 1: Total Pengajuan (jumlah dari view) -->
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="rounded-3 p-3 bg-primary bg-opacity-10 flex-shrink-0">
                    <i class="bi bi-file-earmark-text fs-3 text-primary"></i>
                </div>
                <div>
                    <div class="text-muted small fw-medium text-uppercase tracking-wide">
                        Total Pengajuan
                    </div>
                    <div class="h4 fw-bold mb-0">
                        <?= htmlspecialchars((string)(int)$kpi['jumlah_pengajuan'], ENT_QUOTES, 'UTF-8') ?>
                    </div>
                    <div class="text-muted small">pengajuan diajukan</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Card 2: Total Nominal -->
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="rounded-3 p-3 bg-warning bg-opacity-10 flex-shrink-0">
                    <i class="bi bi-cash-stack fs-3 text-warning"></i>
                </div>
                <div>
                    <div class="text-muted small fw-medium text-uppercase tracking-wide">
                        Total Nominal
                    </div>
                    <div class="h5 fw-bold mb-0 text-nowrap">
                        <?= htmlspecialchars(formatRupiah((float)$kpi['total_pengajuan']), ENT_QUOTES, 'UTF-8') ?>
                    </div>
                    <div class="text-muted small">nilai seluruh pengajuan</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Card 3: Jumlah Dibayar -->
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="rounded-3 p-3 bg-success bg-opacity-10 flex-shrink-0">
                    <i class="bi bi-check-circle fs-3 text-success"></i>
                </div>
                <div>
                    <div class="text-muted small fw-medium text-uppercase tracking-wide">
                        Jumlah Dibayar
                    </div>
                    <div class="h5 fw-bold mb-0 text-nowrap">
                        <?= htmlspecialchars(formatRupiah((float)$kpi['total_dibayar']), ENT_QUOTES, 'UTF-8') ?>
                    </div>
                    <div class="text-muted small">reimbursement terbayar</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Card 4: Rata-rata Hari Proses -->
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="rounded-3 p-3 bg-info bg-opacity-10 flex-shrink-0">
                    <i class="bi bi-clock-history fs-3 text-info"></i>
                </div>
                <div>
                    <div class="text-muted small fw-medium text-uppercase tracking-wide">
                        Rata-rata Proses
                    </div>
                    <div class="h4 fw-bold mb-0">
                        <?php
                        $rataRata = $kpi['rata_rata_hari_proses'];
                        echo htmlspecialchars(
                            $rataRata !== null ? round((float)$rataRata, 1) . ' hari' : '—',
                            ENT_QUOTES,
                            'UTF-8'
                        );
                        ?>
                    </div>
                    <div class="text-muted small">waktu rata-rata penyelesaian</div>
                </div>
            </div>
        </div>
    </div>

</div><!-- /.row KPI cards -->

<!-- ── Tabel Breakdown per Status ─────────────────────────────────────────────── -->
<div class="card shadow-sm border-0">
    <div class="card-header bg-white border-bottom py-3">
        <h2 class="h6 fw-bold mb-0">
            <i class="bi bi-bar-chart-steps me-2 text-secondary"></i>
            Breakdown per Status —
            <span class="text-muted fw-normal">
                <?= htmlspecialchars($bulanLabel, ENT_QUOTES, 'UTF-8') ?>
            </span>
        </h2>
    </div>
    <div class="card-body p-0">
        <?php if (empty($breakdown)): ?>
            <div class="text-center py-5">
                <i class="bi bi-inbox fs-1 text-muted d-block mb-3"></i>
                <p class="text-muted mb-0">
                    Tidak ada data pengajuan untuk periode
                    <strong><?= htmlspecialchars($bulanLabel, ENT_QUOTES, 'UTF-8') ?></strong>.
                </p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th scope="col" class="ps-4" style="width: 50%">Status</th>
                            <th scope="col" class="text-center">Jumlah</th>
                            <th scope="col" class="text-end pe-4">Persentase</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($breakdown as $row): ?>
                        <tr>
                            <!-- Status badge -->
                            <td class="ps-4">
                                <?= getStatusLabel(htmlspecialchars($row['status'], ENT_QUOTES, 'UTF-8')) ?>
                            </td>

                            <!-- Jumlah -->
                            <td class="text-center fw-semibold">
                                <?= htmlspecialchars((string)(int)$row['jumlah'], ENT_QUOTES, 'UTF-8') ?>
                            </td>

                            <!-- Persentase + progress bar -->
                            <td class="text-end pe-4" style="min-width: 180px;">
                                <?php
                                $pct = $totalBreakdown > 0
                                    ? round((int)$row['jumlah'] / $totalBreakdown * 100, 1)
                                    : 0;
                                ?>
                                <div class="d-flex align-items-center justify-content-end gap-2">
                                    <div class="progress flex-grow-1" style="height: 8px; max-width: 100px;" role="progressbar"
                                         aria-valuenow="<?= $pct ?>" aria-valuemin="0" aria-valuemax="100">
                                        <div class="progress-bar bg-primary" style="width: <?= $pct ?>%"></div>
                                    </div>
                                    <span class="text-muted small text-nowrap" style="min-width: 42px;">
                                        <?= htmlspecialchars($pct . '%', ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="table-light">
                        <tr>
                            <th class="ps-4">Total</th>
                            <th class="text-center">
                                <?= htmlspecialchars((string)$totalBreakdown, ENT_QUOTES, 'UTF-8') ?>
                            </th>
                            <th class="pe-4 text-end text-muted small">100%</th>
                        </tr>
                    </tfoot>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/layout/footer.php'; ?>
