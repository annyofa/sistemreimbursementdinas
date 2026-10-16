<?php

/**
 * public/manajemen/laporan.php
 *
 * Halaman laporan reimbursement untuk role Manajemen.
 *
 * Fitur:
 *   - Laporan Bulanan per Departemen (v_laporan_reimbursement GROUP BY departemen)
 *   - Laporan per Kategori Biaya (v_rekap_kategori)
 *   - Laporan Rata-rata Lama Proses per Departemen
 *   - Ekspor CSV (?export=csv)
 *   - Ekspor XLSX opsional (jika PhpSpreadsheet tersedia via Composer)
 *   - Cetak / PDF via window.print() + @media print CSS
 *   - Filter via GET: bulan (YYYY-MM), department_id, category_id
 *
 * Requirements: 7.2, 7.3, 7.4, 7.5, 7.6, 7.7
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireAuth();
requireRole(['manajemen']);

$db = getDB();

// ── Filter periode ──────────────────────────────────────────────────────────────

$bulanRaw = trim($_GET['bulan'] ?? '');
if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $bulanRaw)) {
    $bulan = $bulanRaw;
} else {
    $bulan = date('Y-m'); // default: bulan berjalan
}

[$tahun, $bulanAngka] = explode('-', $bulan);
$tahun      = (int) $tahun;
$bulanAngka = (int) $bulanAngka;

$bulanLabel = date('F Y', mktime(0, 0, 0, $bulanAngka, 1, $tahun));

// ── Filter departemen (opsional) ────────────────────────────────────────────────

$departmentIdRaw = isset($_GET['department_id']) ? (int) $_GET['department_id'] : 0;
$departmentId    = $departmentIdRaw > 0 ? $departmentIdRaw : 0;

// ── Filter kategori (opsional) ──────────────────────────────────────────────────

$categoryIdRaw = isset($_GET['category_id']) ? (int) $_GET['category_id'] : 0;
$categoryId    = $categoryIdRaw > 0 ? $categoryIdRaw : 0;

// ── Daftar departemen & kategori untuk dropdown filter ──────────────────────────

$departments = $db->query("SELECT id, name FROM departments ORDER BY name ASC")->fetchAll();
$categories  = $db->query("SELECT id, name FROM expense_categories WHERE is_active = 1 ORDER BY name ASC")->fetchAll();

// ── Ekspor CSV ──────────────────────────────────────────────────────────────────

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    // Bangun query dengan filter aktif
    $csvSql    = "SELECT
                    r.reimbursement_no,
                    u.name          AS pegawai,
                    d.name          AS departemen,
                    t.destination   AS tujuan,
                    r.submitted_at  AS tanggal_pengajuan,
                    r.total_amount  AS jumlah,
                    r.status,
                    r.paid_at       AS tanggal_bayar,
                    DATEDIFF(COALESCE(r.paid_at, NOW()), r.submitted_at) AS lama_proses_hari
                  FROM reimbursements r
                  JOIN users u ON u.id = r.user_id
                  LEFT JOIN departments d ON d.id = u.department_id
                  LEFT JOIN business_trips t ON t.id = r.trip_id
                  WHERE YEAR(r.submitted_at)  = :tahun
                    AND MONTH(r.submitted_at) = :bulan";
    $csvParams = [':tahun' => $tahun, ':bulan' => $bulanAngka];

    if ($departmentId > 0) {
        $csvSql              .= " AND u.department_id = :dept_id";
        $csvParams[':dept_id'] = $departmentId;
    }

    $csvSql .= " ORDER BY r.submitted_at ASC";

    $stmtCsv = $db->prepare($csvSql);
    $stmtCsv->execute($csvParams);
    $csvRows = $stmtCsv->fetchAll();

    // Peta status ke label yang dapat dibaca
    $statusLabels = [
        'draft'          => 'Draft',
        'diajukan'       => 'Diajukan',
        'review_atasan'  => 'Review Atasan',
        'review_finance' => 'Review Finance',
        'perlu_revisi'   => 'Perlu Revisi',
        'disetujui'      => 'Disetujui',
        'dibayar'        => 'Dibayar',
        'ditolak'        => 'Ditolak',
    ];

    // Set header HTTP untuk download CSV
    $filename = 'laporan_reimb_' . $bulan . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    // BOM UTF-8 agar Excel membaca karakter dengan benar
    echo "\xEF\xBB\xBF";

    $output = fopen('php://output', 'w');

    // Header kolom
    fputcsv($output, [
        'No. Pengajuan',
        'Pegawai',
        'Departemen',
        'Tujuan',
        'Tanggal Pengajuan',
        'Total Nominal',
        'Status',
        'Tanggal Bayar',
        'Lama Proses (Hari)',
    ]);

    // Baris data
    foreach ($csvRows as $row) {
        fputcsv($output, [
            $row['reimbursement_no'] ?? '',
            $row['pegawai']          ?? '',
            $row['departemen']       ?? '-',
            $row['tujuan']           ?? '-',
            $row['tanggal_pengajuan'] !== null
                ? date('d/m/Y', strtotime($row['tanggal_pengajuan']))
                : '',
            number_format((float) ($row['jumlah'] ?? 0), 2, ',', '.'),
            $statusLabels[$row['status'] ?? ''] ?? ($row['status'] ?? ''),
            $row['tanggal_bayar'] !== null
                ? date('d/m/Y', strtotime($row['tanggal_bayar']))
                : '',
            $row['lama_proses_hari'] ?? '',
        ]);
    }

    fclose($output);
    exit;
}

// ── Ekspor XLSX (opsional via PhpSpreadsheet) ────────────────────────────────────

$composerAvailable = file_exists(__DIR__ . '/../../vendor/autoload.php');

if ($composerAvailable && isset($_GET['export']) && $_GET['export'] === 'xlsx') {
    require_once __DIR__ . '/../../vendor/autoload.php';

    $xlsxSql    = "SELECT
                    r.reimbursement_no,
                    u.name          AS pegawai,
                    d.name          AS departemen,
                    t.destination   AS tujuan,
                    r.submitted_at  AS tanggal_pengajuan,
                    r.total_amount  AS jumlah,
                    r.status,
                    r.paid_at       AS tanggal_bayar,
                    DATEDIFF(COALESCE(r.paid_at, NOW()), r.submitted_at) AS lama_proses_hari
                  FROM reimbursements r
                  JOIN users u ON u.id = r.user_id
                  LEFT JOIN departments d ON d.id = u.department_id
                  LEFT JOIN business_trips t ON t.id = r.trip_id
                  WHERE YEAR(r.submitted_at)  = :tahun
                    AND MONTH(r.submitted_at) = :bulan";
    $xlsxParams = [':tahun' => $tahun, ':bulan' => $bulanAngka];

    if ($departmentId > 0) {
        $xlsxSql              .= " AND u.department_id = :dept_id";
        $xlsxParams[':dept_id'] = $departmentId;
    }

    $xlsxSql .= " ORDER BY r.submitted_at ASC";

    $stmtXlsx = $db->prepare($xlsxSql);
    $stmtXlsx->execute($xlsxParams);
    $xlsxRows = $stmtXlsx->fetchAll();

    $xlsxStatusLabels = [
        'draft'          => 'Draft',
        'diajukan'       => 'Diajukan',
        'review_atasan'  => 'Review Atasan',
        'review_finance' => 'Review Finance',
        'perlu_revisi'   => 'Perlu Revisi',
        'disetujui'      => 'Disetujui',
        'dibayar'        => 'Dibayar',
        'ditolak'        => 'Ditolak',
    ];

    // Gunakan fully-qualified class names (tidak bisa pakai 'use' di dalam blok if)
    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet       = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Laporan Reimbursement');

    // Header kolom
    $xlsxHeaders = [
        'No. Pengajuan', 'Pegawai', 'Departemen', 'Tujuan',
        'Tanggal Pengajuan', 'Total Nominal', 'Status',
        'Tanggal Bayar', 'Lama Proses (Hari)',
    ];
    foreach ($xlsxHeaders as $colIdx => $headerText) {
        $sheet->setCellValueByColumnAndRow($colIdx + 1, 1, $headerText);
    }

    // Baris data
    foreach ($xlsxRows as $rowIdx => $row) {
        $rowNum = $rowIdx + 2;
        $sheet->setCellValueByColumnAndRow(1, $rowNum, $row['reimbursement_no'] ?? '');
        $sheet->setCellValueByColumnAndRow(2, $rowNum, $row['pegawai'] ?? '');
        $sheet->setCellValueByColumnAndRow(3, $rowNum, $row['departemen'] ?? '-');
        $sheet->setCellValueByColumnAndRow(4, $rowNum, $row['tujuan'] ?? '-');
        $sheet->setCellValueByColumnAndRow(5, $rowNum,
            $row['tanggal_pengajuan'] !== null
                ? date('d/m/Y', strtotime($row['tanggal_pengajuan']))
                : ''
        );
        $sheet->setCellValueByColumnAndRow(6, $rowNum, (float) ($row['jumlah'] ?? 0));
        $sheet->setCellValueByColumnAndRow(7, $rowNum,
            $xlsxStatusLabels[$row['status'] ?? ''] ?? ($row['status'] ?? '')
        );
        $sheet->setCellValueByColumnAndRow(8, $rowNum,
            $row['tanggal_bayar'] !== null
                ? date('d/m/Y', strtotime($row['tanggal_bayar']))
                : ''
        );
        $sheet->setCellValueByColumnAndRow(9, $rowNum, $row['lama_proses_hari'] ?? '');
    }

    $filename = 'laporan_reimb_' . $bulan . '.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');

    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
}

// ── Query: Laporan Bulanan per Departemen ───────────────────────────────────────

$deptSql    = "SELECT
                 COALESCE(v.departemen, '(Tanpa Departemen)') AS departemen,
                 COUNT(*)                                      AS jumlah_pengajuan,
                 COALESCE(SUM(v.jumlah), 0)                   AS total_diajukan,
                 COALESCE(SUM(CASE WHEN v.status IN ('disetujui','dibayar') THEN v.jumlah ELSE 0 END), 0)
                                                               AS total_disetujui,
                 COALESCE(SUM(CASE WHEN v.status = 'dibayar' THEN v.jumlah ELSE 0 END), 0)
                                                               AS total_dibayar
               FROM v_laporan_reimbursement v
               JOIN users u ON u.name = v.pegawai
               WHERE YEAR(v.tanggal_pengajuan)  = :tahun
                 AND MONTH(v.tanggal_pengajuan) = :bulan";
$deptParams = [':tahun' => $tahun, ':bulan' => $bulanAngka];

if ($departmentId > 0) {
    $deptSql              .= " AND u.department_id = :dept_id";
    $deptParams[':dept_id'] = $departmentId;
}

$deptSql .= " GROUP BY v.departemen ORDER BY total_diajukan DESC";

$stmtDept = $db->prepare($deptSql);
$stmtDept->execute($deptParams);
$laporanDept = $stmtDept->fetchAll();

// ── Query: Laporan per Kategori Biaya ───────────────────────────────────────────

$katSql    = "SELECT
                c.name         AS kategori,
                COUNT(i.id)    AS jumlah_item,
                COALESCE(SUM(i.amount), 0) AS total_nominal
              FROM reimbursement_items i
              JOIN expense_categories c ON c.id = i.category_id
              JOIN reimbursements r ON r.id = i.reimbursement_id
              WHERE YEAR(r.submitted_at)  = :tahun
                AND MONTH(r.submitted_at) = :bulan";
$katParams = [':tahun' => $tahun, ':bulan' => $bulanAngka];

if ($categoryId > 0) {
    $katSql              .= " AND i.category_id = :cat_id";
    $katParams[':cat_id'] = $categoryId;
}

if ($departmentId > 0) {
    $katSql              .= " AND EXISTS (
                                SELECT 1 FROM users u2
                                WHERE u2.id = r.user_id
                                  AND u2.department_id = :dept_id2
                              )";
    $katParams[':dept_id2'] = $departmentId;
}

$katSql .= " GROUP BY c.name ORDER BY total_nominal DESC";

$stmtKat = $db->prepare($katSql);
$stmtKat->execute($katParams);
$laporanKategori = $stmtKat->fetchAll();

// ── Query: Rata-rata Lama Proses per Departemen ─────────────────────────────────

$prosesSql    = "SELECT
                   COALESCE(v.departemen, '(Tanpa Departemen)') AS departemen,
                   COUNT(*)                                      AS jumlah,
                   ROUND(AVG(v.lama_proses_hari), 1)            AS rata_rata_hari
                 FROM v_laporan_reimbursement v
                 JOIN users u ON u.name = v.pegawai
                 WHERE YEAR(v.tanggal_pengajuan)  = :tahun
                   AND MONTH(v.tanggal_pengajuan) = :bulan
                   AND v.lama_proses_hari IS NOT NULL";
$prosesParams = [':tahun' => $tahun, ':bulan' => $bulanAngka];

if ($departmentId > 0) {
    $prosesSql              .= " AND u.department_id = :dept_id";
    $prosesParams[':dept_id'] = $departmentId;
}

$prosesSql .= " GROUP BY v.departemen ORDER BY rata_rata_hari ASC";

$stmtProses = $db->prepare($prosesSql);
$stmtProses->execute($prosesParams);
$laporanProses = $stmtProses->fetchAll();

// ── Bangun URL filter (tanpa export) untuk link ekspor ──────────────────────────

$filterQuery = http_build_query([
    'bulan'         => $bulan,
    'department_id' => $departmentId ?: '',
    'category_id'   => $categoryId ?: '',
]);

$exportCsvUrl  = BASE_URL . '/manajemen/laporan.php?' . $filterQuery . '&export=csv';
$exportXlsxUrl = BASE_URL . '/manajemen/laporan.php?' . $filterQuery . '&export=xlsx';

// ── Render ──────────────────────────────────────────────────────────────────────

$pageTitle = 'Laporan Reimbursement';
require_once __DIR__ . '/../../includes/layout/header.php';
?>
<style>
    /* ── Print styles ────────────────────────────────────────────────────── */
    @media print {
        .no-print {
            display: none !important;
        }
        body {
            background-color: #fff !important;
        }
        .card {
            border: 1px solid #dee2e6 !important;
            box-shadow: none !important;
        }
        .container-fluid {
            padding: 0 !important;
        }
        h1 { font-size: 1.4rem !important; }
    }
</style>
<?php require_once __DIR__ . '/../../includes/layout/navbar.php'; ?>

<!-- ── Judul + tombol aksi ─────────────────────────────────────────────────── -->
<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div>
        <h1 class="h3 fw-bold mb-0">
            <i class="bi bi-bar-chart-line me-2 text-primary"></i>Laporan Reimbursement
        </h1>
        <p class="text-muted mb-0 mt-1 small">
            Periode: <strong><?= htmlspecialchars($bulanLabel, ENT_QUOTES, 'UTF-8') ?></strong>
        </p>
    </div>

    <!-- Tombol ekspor dan cetak -->
    <div class="d-flex flex-wrap gap-2 no-print">
        <a
            href="<?= htmlspecialchars($exportCsvUrl, ENT_QUOTES, 'UTF-8') ?>"
            class="btn btn-sm btn-outline-success"
            title="Unduh data sebagai CSV"
        >
            <i class="bi bi-filetype-csv me-1"></i>Ekspor CSV
        </a>

        <?php if ($composerAvailable): ?>
        <a
            href="<?= htmlspecialchars($exportXlsxUrl, ENT_QUOTES, 'UTF-8') ?>"
            class="btn btn-sm btn-outline-success"
            title="Unduh data sebagai Excel (XLSX)"
        >
            <i class="bi bi-file-earmark-spreadsheet me-1"></i>Ekspor XLSX
        </a>
        <?php endif; ?>

        <button
            type="button"
            class="btn btn-sm btn-outline-secondary"
            onclick="window.print()"
            title="Cetak halaman atau simpan sebagai PDF"
        >
            <i class="bi bi-printer me-1"></i>Cetak / PDF
        </button>
    </div>
</div>

<!-- ── Form filter ──────────────────────────────────────────────────────────── -->
<div class="card shadow-sm border-0 mb-4 no-print">
    <div class="card-body py-3">
        <form
            method="GET"
            action="<?= BASE_URL ?>/manajemen/laporan.php"
            class="row g-3 align-items-end"
        >
            <!-- Filter periode (bulan) -->
            <div class="col-12 col-sm-6 col-md-3">
                <label for="filter-bulan" class="form-label fw-medium mb-1">
                    <i class="bi bi-calendar3 me-1"></i>Periode
                </label>
                <input
                    type="month"
                    id="filter-bulan"
                    name="bulan"
                    class="form-control form-control-sm"
                    value="<?= htmlspecialchars($bulan, ENT_QUOTES, 'UTF-8') ?>"
                    max="<?= htmlspecialchars(date('Y-m'), ENT_QUOTES, 'UTF-8') ?>"
                    aria-label="Pilih periode laporan"
                >
            </div>

            <!-- Filter departemen -->
            <div class="col-12 col-sm-6 col-md-3">
                <label for="filter-dept" class="form-label fw-medium mb-1">
                    <i class="bi bi-building me-1"></i>Departemen
                </label>
                <select
                    id="filter-dept"
                    name="department_id"
                    class="form-select form-select-sm"
                    aria-label="Filter departemen"
                >
                    <option value="">Semua Departemen</option>
                    <?php foreach ($departments as $dept): ?>
                    <option
                        value="<?= htmlspecialchars((string) $dept['id'], ENT_QUOTES, 'UTF-8') ?>"
                        <?= $departmentId === (int) $dept['id'] ? 'selected' : '' ?>
                    >
                        <?= htmlspecialchars($dept['name'], ENT_QUOTES, 'UTF-8') ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Filter kategori -->
            <div class="col-12 col-sm-6 col-md-3">
                <label for="filter-cat" class="form-label fw-medium mb-1">
                    <i class="bi bi-tags me-1"></i>Kategori Biaya
                </label>
                <select
                    id="filter-cat"
                    name="category_id"
                    class="form-select form-select-sm"
                    aria-label="Filter kategori biaya"
                >
                    <option value="">Semua Kategori</option>
                    <?php foreach ($categories as $cat): ?>
                    <option
                        value="<?= htmlspecialchars((string) $cat['id'], ENT_QUOTES, 'UTF-8') ?>"
                        <?= $categoryId === (int) $cat['id'] ? 'selected' : '' ?>
                    >
                        <?= htmlspecialchars($cat['name'], ENT_QUOTES, 'UTF-8') ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Tombol submit -->
            <div class="col-12 col-sm-6 col-md-3">
                <button type="submit" class="btn btn-sm btn-primary w-100">
                    <i class="bi bi-funnel me-1"></i>Terapkan Filter
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ════════════════════════════════════════════════════════════════════════════ -->
<!-- Tabel 1 — Laporan Bulanan per Departemen                                    -->
<!-- ════════════════════════════════════════════════════════════════════════════ -->
<div class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-white border-bottom py-3">
        <h2 class="h6 fw-bold mb-0">
            <i class="bi bi-building me-2 text-primary"></i>
            Rekap per Departemen —
            <span class="text-muted fw-normal">
                <?= htmlspecialchars($bulanLabel, ENT_QUOTES, 'UTF-8') ?>
            </span>
        </h2>
    </div>
    <div class="card-body p-0">
        <?php if (empty($laporanDept)): ?>
            <div class="text-center py-5">
                <i class="bi bi-inbox fs-1 text-muted d-block mb-3"></i>
                <p class="text-muted mb-0">
                    Tidak ada data untuk periode
                    <strong><?= htmlspecialchars($bulanLabel, ENT_QUOTES, 'UTF-8') ?></strong>.
                </p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th scope="col" class="ps-4">Departemen</th>
                            <th scope="col" class="text-center">Jml Pengajuan</th>
                            <th scope="col" class="text-end">Total Diajukan</th>
                            <th scope="col" class="text-end">Total Disetujui</th>
                            <th scope="col" class="text-end pe-4">Total Dibayar</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $grandTotalDiajukan  = 0;
                        $grandTotalDisetujui = 0;
                        $grandTotalDibayar   = 0;
                        $grandJumlahPengajuan = 0;
                        foreach ($laporanDept as $row):
                            $grandJumlahPengajuan += (int) $row['jumlah_pengajuan'];
                            $grandTotalDiajukan   += (float) $row['total_diajukan'];
                            $grandTotalDisetujui  += (float) $row['total_disetujui'];
                            $grandTotalDibayar    += (float) $row['total_dibayar'];
                        ?>
                        <tr>
                            <td class="ps-4 fw-medium">
                                <?= htmlspecialchars($row['departemen'], ENT_QUOTES, 'UTF-8') ?>
                            </td>
                            <td class="text-center">
                                <?= htmlspecialchars((string)(int) $row['jumlah_pengajuan'], ENT_QUOTES, 'UTF-8') ?>
                            </td>
                            <td class="text-end">
                                <?= htmlspecialchars(formatRupiah((float) $row['total_diajukan']), ENT_QUOTES, 'UTF-8') ?>
                            </td>
                            <td class="text-end text-success fw-medium">
                                <?= htmlspecialchars(formatRupiah((float) $row['total_disetujui']), ENT_QUOTES, 'UTF-8') ?>
                            </td>
                            <td class="text-end pe-4 fw-bold text-success">
                                <?= htmlspecialchars(formatRupiah((float) $row['total_dibayar']), ENT_QUOTES, 'UTF-8') ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="table-light fw-bold">
                        <tr>
                            <th class="ps-4">Total</th>
                            <th class="text-center">
                                <?= htmlspecialchars((string) $grandJumlahPengajuan, ENT_QUOTES, 'UTF-8') ?>
                            </th>
                            <th class="text-end">
                                <?= htmlspecialchars(formatRupiah($grandTotalDiajukan), ENT_QUOTES, 'UTF-8') ?>
                            </th>
                            <th class="text-end text-success">
                                <?= htmlspecialchars(formatRupiah($grandTotalDisetujui), ENT_QUOTES, 'UTF-8') ?>
                            </th>
                            <th class="text-end pe-4 text-success">
                                <?= htmlspecialchars(formatRupiah($grandTotalDibayar), ENT_QUOTES, 'UTF-8') ?>
                            </th>
                        </tr>
                    </tfoot>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- ════════════════════════════════════════════════════════════════════════════ -->
<!-- Tabel 2 — Laporan per Kategori Biaya                                        -->
<!-- ════════════════════════════════════════════════════════════════════════════ -->
<div class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-white border-bottom py-3">
        <h2 class="h6 fw-bold mb-0">
            <i class="bi bi-tags me-2 text-warning"></i>
            Rekap per Kategori Biaya —
            <span class="text-muted fw-normal">
                <?= htmlspecialchars($bulanLabel, ENT_QUOTES, 'UTF-8') ?>
            </span>
        </h2>
    </div>
    <div class="card-body p-0">
        <?php if (empty($laporanKategori)): ?>
            <div class="text-center py-5">
                <i class="bi bi-inbox fs-1 text-muted d-block mb-3"></i>
                <p class="text-muted mb-0">
                    Tidak ada data kategori untuk periode ini.
                </p>
            </div>
        <?php else:
            // Hitung total untuk progress bar
            $maxKatNominal = max(array_column($laporanKategori, 'total_nominal'));
        ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th scope="col" class="ps-4">Kategori</th>
                            <th scope="col" class="text-center">Jml Item</th>
                            <th scope="col" class="text-end pe-4">Total Nominal</th>
                            <th scope="col" class="d-none d-md-table-cell" style="width: 200px;">Proporsi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $grandTotalKat = 0;
                        $grandItemKat  = 0;
                        foreach ($laporanKategori as $row):
                            $grandTotalKat += (float) $row['total_nominal'];
                            $grandItemKat  += (int) $row['jumlah_item'];
                            $pct = $maxKatNominal > 0
                                ? round((float) $row['total_nominal'] / (float) $maxKatNominal * 100, 1)
                                : 0;
                        ?>
                        <tr>
                            <td class="ps-4 fw-medium">
                                <?= htmlspecialchars($row['kategori'], ENT_QUOTES, 'UTF-8') ?>
                            </td>
                            <td class="text-center">
                                <?= htmlspecialchars((string)(int) $row['jumlah_item'], ENT_QUOTES, 'UTF-8') ?>
                            </td>
                            <td class="text-end pe-4 fw-semibold">
                                <?= htmlspecialchars(formatRupiah((float) $row['total_nominal']), ENT_QUOTES, 'UTF-8') ?>
                            </td>
                            <td class="d-none d-md-table-cell">
                                <div class="d-flex align-items-center gap-2">
                                    <div
                                        class="progress flex-grow-1"
                                        style="height: 8px;"
                                        role="progressbar"
                                        aria-valuenow="<?= $pct ?>"
                                        aria-valuemin="0"
                                        aria-valuemax="100"
                                    >
                                        <div class="progress-bar bg-warning" style="width: <?= $pct ?>%"></div>
                                    </div>
                                    <span class="text-muted small text-nowrap" style="min-width: 38px;">
                                        <?= htmlspecialchars($pct . '%', ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="table-light fw-bold">
                        <tr>
                            <th class="ps-4">Total</th>
                            <th class="text-center">
                                <?= htmlspecialchars((string) $grandItemKat, ENT_QUOTES, 'UTF-8') ?>
                            </th>
                            <th class="text-end pe-4">
                                <?= htmlspecialchars(formatRupiah($grandTotalKat), ENT_QUOTES, 'UTF-8') ?>
                            </th>
                            <th class="d-none d-md-table-cell"></th>
                        </tr>
                    </tfoot>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- ════════════════════════════════════════════════════════════════════════════ -->
<!-- Tabel 3 — Rata-rata Lama Proses per Departemen                              -->
<!-- ════════════════════════════════════════════════════════════════════════════ -->
<div class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-white border-bottom py-3">
        <h2 class="h6 fw-bold mb-0">
            <i class="bi bi-clock-history me-2 text-info"></i>
            Rata-rata Lama Proses per Departemen —
            <span class="text-muted fw-normal">
                <?= htmlspecialchars($bulanLabel, ENT_QUOTES, 'UTF-8') ?>
            </span>
        </h2>
    </div>
    <div class="card-body p-0">
        <?php if (empty($laporanProses)): ?>
            <div class="text-center py-5">
                <i class="bi bi-inbox fs-1 text-muted d-block mb-3"></i>
                <p class="text-muted mb-0">
                    Tidak ada data waktu proses untuk periode ini.
                </p>
            </div>
        <?php else:
            $maxRataRata = max(array_column($laporanProses, 'rata_rata_hari'));
        ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th scope="col" class="ps-4">Departemen</th>
                            <th scope="col" class="text-center">Jml Pengajuan</th>
                            <th scope="col" class="text-center pe-4">Rata-rata Lama Proses</th>
                            <th scope="col" class="d-none d-md-table-cell" style="width: 200px;">Indikator</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($laporanProses as $row):
                            $rataRata = (float) ($row['rata_rata_hari'] ?? 0);
                            $pctProses = ($maxRataRata > 0)
                                ? round($rataRata / (float) $maxRataRata * 100, 1)
                                : 0;

                            // Warna indikator: hijau <= 3, kuning <= 7, merah > 7
                            if ($rataRata <= 3) {
                                $barColor = 'bg-success';
                            } elseif ($rataRata <= 7) {
                                $barColor = 'bg-warning';
                            } else {
                                $barColor = 'bg-danger';
                            }
                        ?>
                        <tr>
                            <td class="ps-4 fw-medium">
                                <?= htmlspecialchars($row['departemen'], ENT_QUOTES, 'UTF-8') ?>
                            </td>
                            <td class="text-center">
                                <?= htmlspecialchars((string)(int) $row['jumlah'], ENT_QUOTES, 'UTF-8') ?>
                            </td>
                            <td class="text-center pe-4 fw-semibold">
                                <?= htmlspecialchars(
                                    $row['rata_rata_hari'] !== null
                                        ? $row['rata_rata_hari'] . ' hari'
                                        : '—',
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>
                            </td>
                            <td class="d-none d-md-table-cell">
                                <div class="d-flex align-items-center gap-2">
                                    <div
                                        class="progress flex-grow-1"
                                        style="height: 8px;"
                                        role="progressbar"
                                        aria-valuenow="<?= $pctProses ?>"
                                        aria-valuemin="0"
                                        aria-valuemax="100"
                                    >
                                        <div class="progress-bar <?= htmlspecialchars($barColor, ENT_QUOTES, 'UTF-8') ?>" style="width: <?= $pctProses ?>%"></div>
                                    </div>
                                    <span class="text-muted small text-nowrap" style="min-width: 42px;">
                                        <?= htmlspecialchars($rataRata . ' hr', ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="px-4 py-2 border-top">
                <small class="text-muted">
                    <span class="badge bg-success me-1">≤ 3 hari</span>Baik &nbsp;
                    <span class="badge bg-warning text-dark me-1">4–7 hari</span>Perlu perhatian &nbsp;
                    <span class="badge bg-danger me-1">> 7 hari</span>Perlu tindakan
                </small>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/layout/footer.php'; ?>
