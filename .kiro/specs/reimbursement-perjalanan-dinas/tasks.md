# Implementation Plan: Sistem Reimbursement Perjalanan Dinas

## Overview

Implementasi aplikasi web Reimbursement Perjalanan Dinas menggunakan PHP 8 native, MySQL (PDO), Bootstrap 5, dan vanilla JS. Seluruh task bersifat incremental — setiap langkah membangun di atas langkah sebelumnya dan diakhiri dengan penggabungan komponen. Database (`reimbursement_db`) beserta trigger dan kolom-kolom tambahan sudah ada; tidak ada task migrasi atau ALTER TABLE.

---

## Tasks

- [x] 1. Project Scaffold — Konfigurasi, Helper, dan Layout

  - [x] 1.1 Buat `config/database.php` dengan PDO singleton dan konstanta BASE_URL
    - Definisikan konstanta `BASE_URL = '/reimbursement/public'`
    - Buat fungsi `getDB(): PDO` (static singleton) dengan `charset=utf8mb4`, `ERRMODE_EXCEPTION`, `FETCH_ASSOC`
    - Semua redirect (`header('Location: ...')`) dan link harus menggunakan `BASE_URL`
    - _Requirements: 1.1, 1.4_

  - [x] 1.2 Buat `includes/auth.php` — autentikasi dan otorisasi berbasis sesi
    - Implementasikan `login(string $email, string $password): bool` — query by email + `password_verify()` + `session_regenerate_id(true)`
    - Implementasikan `logout(): void` — `session_unset()`, `session_destroy()`, redirect ke login
    - Implementasikan `requireAuth(): void` — cek `$_SESSION['user_id']`; redirect ke login jika tidak ada
    - Implementasikan `isSessionExpired(): bool` — cek `$_SESSION['last_activity']`; invalidasi jika > 1800 detik; perbarui `last_activity` setiap akses
    - Implementasikan `requireRole(array $roles): void` — cek `$_SESSION['role']`; keluarkan HTTP 403 jika tidak sesuai
    - Implementasikan `getCurrentUser(): array` — kembalikan data user dari sesi
    - _Requirements: 1.1, 1.2, 1.3, 1.4, 1.5, 1.6, 1.7_

  - [x] 1.3 Buat `includes/functions.php` — helper bisnis umum
    - `generateReimbNo(): string` — format `RMB-{YYYY}-{000001}`, increment berdasarkan COUNT tahun berjalan
    - `formatRupiah(float $n): string` — output `Rp 1.234.000`
    - `getStatusLabel(string $status): string` — kembalikan label + class badge Bootstrap per status
    - `getStatusHistory(int $reimbId): array` — SELECT dari `status_history` JOIN `users` ORDER BY `changed_at ASC`
    - `canEdit(array $reimbursement): bool` — true hanya jika status `draft` atau `perlu_revisi`
    - `getSubordinates(int $supervisorId): array` — SELECT user dengan `supervisor_id = $supervisorId`
    - `updateReimbursementStatus(int $reimbId, string $newStatus, int $actorId, string $note = ''): bool` — validasi `VALID_TRANSITIONS`, jalankan `SET @actor_id=?, @status_note=?` pada koneksi PDO yang sama sebelum UPDATE, panggil `logAudit()` dan `createNotification()` setelah berhasil; lempar `InvalidStatusTransitionException` jika transisi tidak valid
    - `canFinanceApprove(int $reimbId): bool` — true hanya jika semua item `verification_status` ≠ `'belum'`
    - `isValidPaymentAmount($amount): bool` — true hanya jika numerik dan > 0
    - `isValidUploadFile(string $filePath, string $mimeType): bool` — cek ekstensi (`jpg`,`jpeg`,`png`,`pdf`) DAN MIME via `finfo`
    - `canDeactivateUser(int $userId): bool` — false jika ada pengajuan aktif (status selain `dibayar`/`ditolak`)
    - `determineOcrStatus(float $confidence): string` — `'buram'` jika < 70, `'sukses'` jika >= 70
    - `csrfField(): string` — generate token baru jika belum ada di `$_SESSION['csrf_token']`, simpan ke sesi, kembalikan string HTML `<input type="hidden" name="csrf_token" value="{token}">` siap di-echo di dalam form
    - `verifyCsrf(): void` — bandingkan `$_POST['csrf_token']` dengan `$_SESSION['csrf_token']` menggunakan `hash_equals()`; jika tidak cocok kirim HTTP 403 dan `die('Token CSRF tidak valid')`
    - Kedua fungsi ini WAJIB dipakai di semua form POST sejak task 2.1 — setiap halaman form POST memanggil `echo csrfField()` di dalam `<form>` dan `verifyCsrf()` di handler POST
    - _Requirements: 1.1, 2.2, 2.3, 2.7, 2.8, 3.3, 3.6, 4.4, 5.5, 5.7, 6.2, 6.4, 6.5, 9.7_

  - [x] 1.4 Buat `includes/notifications.php` — sistem notifikasi in-app
    - `createNotification(int $userId, int $reimbId, string $title, string $msg): void` — INSERT ke `notifications`
    - `getUnreadCount(int $userId): int` — COUNT `is_read=0`
    - `getNotifications(int $userId, int $limit=10): array` — SELECT 10 notif terbaru ORDER BY `created_at DESC`
    - `markAsRead(int $notifId, int $userId): void` — UPDATE `is_read=1` dengan validasi kepemilikan
    - `markAllAsRead(int $userId): void` — UPDATE semua `is_read=0` milik user menjadi 1
    - _Requirements: 8.1, 8.2, 8.3, 8.7_

  - [x] 1.5 Buat `includes/audit.php` — pencatatan audit log
    - `logAudit(int $userId, string $action, string $tableName, int $recordId, array $detail = []): void`
    - JSON encode `$detail` (format `before`/`after`/`context`), INSERT ke `audit_logs`
    - Tidak ada fungsi DELETE atau UPDATE pada `audit_logs`
    - _Requirements: 8.4, 8.6_

  - [x] 1.6 Buat file layout: `includes/layout/header.php`, `navbar.php`, `footer.php`
    - `header.php`: `<!DOCTYPE html>`, `<head>`, import Bootstrap 5 CSS, meta CSRF token
    - `navbar.php`: navbar responsif, inject `const BASE_URL = '<?= BASE_URL ?>';` sebagai JS variable, tampilkan flash message dari `$_SESSION['flash']` lalu hapus, tampilkan badge notifikasi `#notif-count`, polling AJAX notifikasi setiap 30 detik via `BASE_URL . '/api/notifications.php?count_only=1'`
    - `footer.php`: tutup tag, import Bootstrap 5 JS
    - _Requirements: 8.2_

- [x] 2. Autentikasi — Login, Logout, Dashboard Redirect

  - [x] 2.1 Buat `public/login.php` — halaman login
    - Form GET: tampilkan form email + password + CSRF token, tampilkan pesan timeout jika `?timeout=1`
    - Form POST: validasi CSRF, panggil `login()`, redirect ke `dashboard.php` jika berhasil; set flash message error jika gagal
    - Setelah login berhasil dan sebelum redirect: panggil `logAudit($_SESSION['user_id'], 'LOGIN', 'users', $userId, ['ip' => $_SERVER['REMOTE_ADDR']])` untuk mencatat aksi login di audit log
    - _Requirements: 1.1, 1.2_

  - [x] 2.2 Buat `public/logout.php` dan `public/dashboard.php`
    - `logout.php`: panggil `logout()`, log aksi `LOGOUT` via `logAudit()`
    - `dashboard.php`: panggil `requireAuth()`, redirect ke halaman utama sesuai `$_SESSION['role']` (`pegawai/index.php`, `atasan/index.php`, dst.)
    - _Requirements: 1.4, 1.6_

  - [x] 2.3 Buat `public/index.php` dan halaman error
    - `public/index.php`: redirect ke `login.php`
    - Buat tampilan error 403 (Akses Ditolak) yang ditampilkan oleh `requireRole()` saat role tidak sesuai
    - _Requirements: 1.3, 1.7_

- [x] 3. Checkpoint — Pastikan autentikasi dan scaffold berjalan
  - Verifikasi login berhasil dengan user yang ada di database
  - Verifikasi redirect per role bekerja
  - Verifikasi session timeout terinvalidasi setelah 30 menit idle
  - Pastikan semua test lulus; tanyakan kepada user jika ada pertanyaan.

- [x] 4. Modul Pegawai

  - [x] 4.1 Buat `public/pegawai/index.php` — daftar pengajuan milik pegawai
    - `requireAuth()` + `requireRole(['pegawai'])`
    - Query: `SELECT * FROM reimbursements WHERE user_id=? ORDER BY created_at DESC`
    - Tampilkan tabel: nomor pengajuan, tujuan, tanggal, total (formatRupiah), badge status
    - Tombol Buat Baru (link ke `create.php`) dan Lihat Detail
    - _Requirements: 2.5_

  - [x] 4.2 Buat `public/pegawai/create.php` — form buat pengajuan baru
    - `requireAuth()` + `requireRole(['pegawai'])`
    - Form POST: destination, purpose, start_date, end_date, department → INSERT `business_trips` → INSERT `reimbursements` (status=`draft`, generate `reimbursement_no`) → `logAudit('CREATE_REIMBURSEMENT', ...)` → redirect ke `detail.php?id={id}`
    - Validasi server-side: semua field wajib, end_date >= start_date
    - _Requirements: 2.1_

  - [x] 4.3 Buat `public/pegawai/detail.php` — detail pengajuan, item biaya, upload struk, submit
    - `requireAuth()` + `requireRole(['pegawai'])`
    - Tampilkan: info perjalanan, tabel item biaya (deskripsi, kategori, nominal, status verifikasi, struk), timeline riwayat status via `getStatusHistory()`
    - Form tambah item biaya (POST): category_id, expense_date, merchant, description, amount → INSERT `reimbursement_items` → `logAudit('ADD_ITEM', ...)` → redirect kembali ke halaman yang sama
    - Area upload struk per item (trigger `upload_receipt.php` API + Tesseract.js — diimplementasikan di task 6)
    - Tombol Submit: hanya tampil jika status `draft`; POST → validasi minimal 1 item biaya (jika tidak ada tampilkan flash error "Pengajuan harus memiliki minimal satu item biaya") → `updateReimbursementStatus($id, 'diajukan', ...)` → UPDATE `submitted_at=NOW()` → kirim notifikasi ke atasan langsung via `createNotification()` → `logAudit('UPDATE_STATUS', ...)` → redirect ke `index.php`
    - Tombol hapus item (POST ke `api/delete_item.php`): hanya aktif jika status `draft`
    - Cegah edit jika status bukan `draft` atau `perlu_revisi`
    - _Requirements: 2.2, 2.3, 2.4, 2.5, 2.6, 2.8, 8.1_

  - [x] 4.4 Buat `public/pegawai/revisi.php` — halaman jawab revision request
    - `requireAuth()` + `requireRole(['pegawai'])`
    - Ambil semua `revision_requests` berstatus `terbuka` untuk pengajuan bersangkutan
    - Tampilkan catatan revisi dari atasan/finance
    - Form textarea jawaban; POST → UPDATE `revision_requests` SET `response=?`, `status='dijawab'`, `responded_at=NOW()` → tentukan status baru berdasarkan `role` dari `requested_by` (`atasan` → `diajukan`, `finance` → `review_finance`) → `updateReimbursementStatus()` → kirim notifikasi ke pembuat revision_request → `logAudit('ANSWER_REVISION', ...)` → redirect ke `detail.php`
    - _Requirements: 2.7_

  - [x] 4.5 Buat `public/shared/detail.php` — halaman detail pengajuan read-only bersama

    - `requireAuth()` + `requireRole(['admin','manajemen','finance','kasir'])` — akses untuk role yang perlu melihat detail tanpa aksi edit
    - Terima `?id={reimbursement_id}` via GET; tampilkan:
      - Informasi perjalanan dinas (destination, purpose, start_date, end_date, pegawai, departemen)
      - Daftar item biaya: deskripsi, kategori, nominal, approved_amount, verification_status, foto struk, data OCR (merchant, tanggal, total, confidence, badge buram jika ocr_status='buram')
      - Riwayat status via `getStatusHistory()` dalam format timeline kronologis
      - Semua revision_requests beserta response pegawai
      - Data pembayaran jika status `dibayar` (metode, tanggal, nominal, referensi, bukti)
      - Keputusan approval (paraf atasan jika ada)
    - Tidak ada tombol aksi (setujui/tolak/revisi/bayar) — murni read-only
    - Link "Kembali" ke halaman pemanggil (`$_SERVER['HTTP_REFERER']` atau default ke `dashboard.php`)
    - Halaman `admin/reimbursements.php` mengarahkan tombol "Lihat Detail" ke `shared/detail.php?id={id}`
    - _Requirements: 9.6, 9.8_

- [x] 5. Checkpoint — Pastikan modul pegawai berjalan end-to-end
  - Buat pengajuan baru, tambah item biaya, submit berhasil
  - Verifikasi status berubah ke `diajukan` dan notifikasi atasan dibuat
  - Verifikasi shared/detail.php dapat dibuka oleh admin dan menampilkan semua informasi pengajuan dengan benar
  - Pastikan semua test lulus; tanyakan kepada user jika ada pertanyaan.

- [x] 6. Modul OCR — Upload Struk dan Tesseract.js

  - [x] 6.1 Buat `public/api/upload_receipt.php` — endpoint upload foto struk
    - `requireAuth()` (semua role yang bisa upload); kembalikan JSON
    - Validasi MIME via `finfo_file()` (bukan `$_FILES['type']`): hanya `image/jpeg`, `image/png`, `application/pdf`
    - Validasi ekstensi: `jpg`, `jpeg`, `png`, `pdf`; tolak selain itu dengan pesan "Format file tidak didukung"
    - Batasi ukuran: maksimum 5 MB
    - Generate nama file unik: `uniqid('receipt_', true) . '.' . $ext`
    - Simpan ke `uploads/receipts/`, kembalikan JSON `{item_id, file_path, url}`
    - `logAudit('UPLOAD_RECEIPT', ...)`
    - _Requirements: 3.1, 3.6_

  - [x] 6.2 Integrasikan Tesseract.js di `pegawai/detail.php` — OCR client-side
    - Load Tesseract.js dari CDN di footer halaman detail pegawai
    - Per item biaya: tombol "Upload Struk" → `fetch(BASE_URL + '/api/upload_receipt.php')` → setelah sukses panggil `Tesseract.recognize(imageURL)` → parse hasil `{text, confidence}` menggunakan regex: total nominal (`/(?:total|jumlah|grand total)[^\d]*(\d[\d.,]+)/i`), tanggal (`/(\d{1,2})[\/\-\.](\d{1,2})[\/\-\.](\d{2,4})/`), merchant (baris pertama non-kosong)
    - Jika confidence >= 70: pra-isi field form dengan hasil OCR, tampilkan hasil
    - Jika confidence < 70: tampilkan peringatan "Struk buram, mohon upload ulang dengan kualitas lebih baik"
    - Setelah parsing, `fetch(BASE_URL + '/api/ocr_save.php')` dengan payload OCR
    - Tangkap error Tesseract di blok `catch`: tampilkan pesan "OCR tidak dapat memproses file ini. Anda dapat melanjutkan tanpa data OCR.", izinkan simpan item tanpa data OCR
    - _Requirements: 3.1, 3.2, 3.3, 3.4, 3.5_

  - [x] 6.3 Buat `public/api/ocr_save.php` — simpan hasil OCR ke database
    - `requireAuth()`; kembalikan JSON
    - Terima payload: `item_id`, `merchant`, `date`, `total`, `confidence`, `raw_text`, `status`
    - `determineOcrStatus($confidence)` → tentukan `ocr_status`
    - INSERT ke `receipts` (file_path, ocr_status, ocr_raw_text, ocr_merchant, ocr_date, ocr_total, ocr_confidence)
    - Kembalikan JSON `{receipt_id, ocr_status}`
    - _Requirements: 3.2, 3.3_

  - [x] 6.4 Buat `public/api/delete_item.php` — hapus item biaya
    - `requireAuth()` + cek role `pegawai` + cek status pengajuan = `draft`
    - DELETE `reimbursement_items` WHERE `id=? AND reimbursement_id=?`
    - `logAudit('DELETE_ITEM', ...)`
    - Kembalikan JSON sukses/error
    - _Requirements: 2.8_

- [x] 7. Modul Atasan

  - [x] 7.1 Buat `public/atasan/index.php` — daftar pengajuan bawahan
    - `requireAuth()` + `requireRole(['atasan'])`
    - Query: `SELECT r.*, u.name as pegawai_name FROM reimbursements r JOIN users u ON u.id=r.user_id WHERE u.supervisor_id=? AND r.status IN ('diajukan','review_atasan') ORDER BY r.submitted_at ASC`
    - Tampilkan tabel: nama pegawai, tujuan, total, status, tanggal pengajuan
    - _Requirements: 4.1_

  - [x] 7.2 Buat `public/atasan/review.php` — halaman review dan aksi atasan
    - `requireAuth()` + `requireRole(['atasan'])`
    - Saat halaman dibuka: jika status masih `diajukan`, panggil `updateReimbursementStatus($id, 'review_atasan', ...)` secara otomatis → `logAudit('UPDATE_STATUS', ...)`
    - Tampilkan: detail trip, daftar item biaya, foto struk per item, data OCR (merchant, tanggal, total, confidence), riwayat status via `getStatusHistory()`
    - Aksi **Setujui** (POST): wajib upload paraf digital → validasi file paraf (JPEG/PNG/PDF) → simpan ke `uploads/signatures/` → INSERT ke `approvals` (level=`atasan`, decision=`disetujui`, digital_signature=path) → `updateReimbursementStatus($id, 'review_finance', ...)` → `createNotification()` ke semua user role=`finance` → `logAudit('SAVE_APPROVAL', ...)` → redirect ke `index.php`
    - Jika submit tanpa paraf: flash error "Paraf digital wajib dilampirkan untuk menyetujui pengajuan"
    - Aksi **Tolak** (POST): wajib isi alasan → INSERT ke `approvals` (decision=`ditolak`, comment=alasan) → `updateReimbursementStatus($id, 'ditolak', ...)` → `createNotification()` ke pegawai → `logAudit('SAVE_APPROVAL', ...)` → redirect ke `index.php`
    - Aksi **Minta Revisi** (POST): wajib isi catatan → INSERT ke `revision_requests` (requested_by=atasan) → `updateReimbursementStatus($id, 'perlu_revisi', ...)` → `createNotification()` ke pegawai dengan isi catatan → `logAudit('CREATE_REVISION', ...)` → redirect ke `index.php`
    - _Requirements: 4.2, 4.3, 4.4, 4.5, 4.6, 4.7, 4.8_

- [x] 8. Modul Finance

  - [x] 8.1 Buat `public/finance/index.php` — daftar pengajuan untuk diverifikasi
    - `requireAuth()` + `requireRole(['finance'])`
    - Query: `SELECT r.*, u.name as pegawai_name, d.name as dept_name FROM reimbursements r JOIN users u ON u.id=r.user_id JOIN departments d ON d.id=u.department_id WHERE r.status='review_finance' ORDER BY r.submitted_at ASC`
    - Tampilkan tabel: nama pengaju, departemen, tujuan, total nominal, tanggal masuk antrian
    - _Requirements: 5.1_

  - [x] 8.2 Buat `public/api/item_verify.php` — endpoint AJAX verifikasi per item
    - `requireAuth()` + cek role `finance`; kembalikan JSON
    - Terima payload: `item_id`, `verification_status` (`diverifikasi`/`ditolak`), `approved_amount` (opsional)
    - UPDATE `reimbursement_items` SET `verification_status=?`, `verified_by=?`, `verified_at=NOW()`, `approved_amount=?` WHERE `id=?`
    - `logAudit('VERIFY_ITEM', ...)`
    - Kembalikan JSON sukses/error
    - _Requirements: 5.3_

  - [x] 8.3 Buat `public/finance/verifikasi.php` — halaman verifikasi lengkap
    - `requireAuth()` + `requireRole(['finance'])`
    - Tampilkan tabel item biaya: Deskripsi | Nominal Input | Total OCR | Selisih | Status Verifikasi
    - Selisih > 5% antar nominal input vs OCR: tampilkan indikator visual (latar kuning/merah)
    - Per item: tombol radio `diverifikasi`/`ditolak` + field `approved_amount` → kirim via AJAX ke `api/item_verify.php`
    - Tampilkan foto struk dan data OCR per item (sesuai Requirement 3.7)
    - Aksi **Kirim Klarifikasi** (POST): INSERT ke `revision_requests` (requested_by=finance) → `updateReimbursementStatus($id, 'perlu_revisi', ...)` → `createNotification()` ke pegawai → `logAudit('CREATE_REVISION', ...)` → redirect ke `index.php`
    - Aksi **Setujui Pengajuan** (POST): panggil `canFinanceApprove($reimbId)` — jika false tampilkan daftar item belum diverifikasi; jika true → INSERT ke `approvals` → `updateReimbursementStatus($id, 'disetujui', ...)` → `createNotification()` ke semua user role=`kasir` → `logAudit('SAVE_APPROVAL', ...)` → redirect ke `index.php`
    - Aksi **Tolak Pengajuan** (POST): wajib isi alasan → INSERT ke `approvals` (decision=`ditolak`) → `updateReimbursementStatus($id, 'ditolak', ...)` → `createNotification()` ke pegawai → `logAudit('SAVE_APPROVAL', ...)` → redirect ke `index.php`
    - _Requirements: 5.2, 5.3, 5.4, 5.5, 5.6, 5.7, 3.7_

- [x] 9. Modul Kasir

  - [x] 9.1 Buat `public/kasir/index.php` — daftar pengajuan siap dibayar
    - `requireAuth()` + `requireRole(['kasir'])`
    - Query: `SELECT r.*, u.name as pegawai_name, d.name as dept_name FROM reimbursements r JOIN users u ON u.id=r.user_id JOIN departments d ON d.id=u.department_id WHERE r.status='disetujui' ORDER BY r.updated_at ASC`
    - Tampilkan tabel: nama pegawai, departemen, total nominal, tanggal persetujuan
    - _Requirements: 6.1_

  - [x] 9.2 Buat `public/kasir/bayar.php` — form catat pembayaran
    - `requireAuth()` + `requireRole(['kasir'])`
    - Tampilkan detail pengajuan jika sudah `dibayar` (summary: metode, tanggal, nominal, referensi, bukti)
    - Form POST: method (transfer/tunai), paid_at, amount, reference_no, upload bukti (opsional — JPEG/PNG/PDF → simpan ke `uploads/payments/` → isi `proof_path`)
    - Validasi server: `isValidPaymentAmount($amount)` — jika gagal tampilkan "Nominal pembayaran tidak boleh kosong atau nol"
    - Aksi Simpan: INSERT ke `payments` (reimbursement_id, cashier_id, amount, method, reference_no, paid_at, proof_path) → `updateReimbursementStatus($id, 'dibayar', ...)` → UPDATE `reimbursements.paid_at=?` → `createNotification()` ke pegawai → `logAudit('SAVE_PAYMENT', ...)` → redirect ke `index.php`
    - _Requirements: 6.2, 6.3, 6.4, 6.5, 6.6_

- [ ] 10. Checkpoint — Pastikan alur penuh dari submit hingga pembayaran berjalan
  - Simulasikan alur: pegawai submit → atasan setujui → finance verifikasi + setujui → kasir bayar
  - Verifikasi setiap transisi status benar, notifikasi dibuat, audit log tercatat
  - Pastikan semua test lulus; tanyakan kepada user jika ada pertanyaan.

- [x] 11. Modul Manajemen — Dashboard dan Laporan

  - [x] 11.1 Buat `public/manajemen/dashboard.php` — dashboard KPI
    - `requireAuth()` + `requireRole(['manajemen'])`
    - Default periode: bulan berjalan; terima `?bulan=YYYY-MM` via GET
    - Load data dari view `v_rekap_bulanan` untuk KPI: Total Pengajuan, Total Nominal, Jumlah Dibayar
    - Query breakdown per status: `SELECT status, COUNT(*) as jumlah FROM reimbursements WHERE YEAR(created_at)=? AND MONTH(created_at)=? GROUP BY status`
    - Tampilkan KPI cards + tabel breakdown per status
    - Filter periode diterapkan via GET parameter, form GET di-submit tanpa reload penuh
    - _Requirements: 7.1, 7.2_

  - [x] 11.2 Buat `public/manajemen/laporan.php` — halaman laporan dengan filter dan ekspor
    - `requireAuth()` + `requireRole(['manajemen'])`
    - Filter via GET: periode (bulan/tahun), department_id, category_id
    - **Laporan Bulanan per Departemen**: query `v_laporan_reimbursement` GROUP BY departemen — jumlah pengajuan, total diajukan, total disetujui, total dibayar
    - **Laporan per Kategori Biaya**: query `v_rekap_kategori` dengan filter periode — total nominal dan jumlah item per kategori, diurutkan dari nominal terbesar
    - **Laporan Rata-rata Lama Proses**: `SELECT department, AVG(lama_proses_hari) FROM v_laporan_reimbursement WHERE ... GROUP BY department`
    - Tombol **Ekspor CSV**: jika GET `?export=csv` → set header `Content-Type: text/csv`, `Content-Disposition: attachment; filename="laporan_reimb_{periode}.csv"` → tulis header kolom + data dengan `fputcsv()` → exit
    - Tombol **Cetak/PDF**: `<button onclick="window.print()">` + CSS `@media print` untuk menyembunyikan navbar, tombol, filter
    - Deteksi Composer optional: cek `vendor/autoload.php`; jika ada tampilkan tombol Ekspor XLSX (PhpSpreadsheet) sebagai opsi tambahan
    - Kolom ekspor: No. Pengajuan | Pegawai | Departemen | Tujuan | Tanggal Pengajuan | Total Nominal | Status | Tanggal Bayar | Lama Proses (Hari)
    - _Requirements: 7.2, 7.3, 7.4, 7.5, 7.6, 7.7_

- [ ] 12. Modul Admin

  - [ ] 12.1 Buat `public/admin/users.php` — CRUD user
    - `requireAuth()` + `requireRole(['admin'])`
    - Daftar user + filter role/departemen/is_active; tampilkan nama, email, role, departemen, atasan langsung, status aktif
    - Form **Tambah User**: nama, email unik, password (min 8 karakter), role, departemen, supervisor_id → `password_hash($pass, PASSWORD_BCRYPT)` → INSERT `users` → `logAudit('CREATE_USER', ...)`
    - Form **Edit User**: nama, role, departemen, supervisor_id (BUKAN password) → UPDATE → `logAudit('UPDATE_USER', ...)`
    - **Reset Password**: form password baru (min 8 karakter) → `password_hash()` → UPDATE `password_hash` saja → `logAudit('RESET_PASSWORD', ...)` → tidak tampilkan password lama/baru di response
    - **Toggle Aktif/Nonaktif**: panggil `canDeactivateUser($userId)` — jika false tampilkan pesan dengan jumlah pengajuan aktif; jika true UPDATE `is_active`
    - _Requirements: 9.1, 9.2, 9.3, 9.7_

  - [ ] 12.2 Buat `public/admin/categories.php` — manajemen kategori biaya
    - `requireAuth()` + `requireRole(['admin'])`
    - Daftar kategori + toggle aktif/nonaktif → `logAudit('TOGGLE_CATEGORY', ...)`
    - Form tambah/edit: name, max_amount
    - Kategori `is_active=0` tidak muncul di dropdown form pengajuan baru
    - _Requirements: 9.4_

  - [ ] 12.3 Buat `public/admin/departments.php` — manajemen departemen
    - `requireAuth()` + `requireRole(['admin'])`
    - CRUD departemen; cek FK sebelum hapus (jika digunakan user/pengajuan, tampilkan pesan error)
    - _Requirements: 9.5_

  - [ ] 12.4 Buat `public/admin/reimbursements.php` — semua pengajuan lintas departemen
    - `requireAuth()` + `requireRole(['admin'])`
    - Query dengan JOIN users + departments; filter opsional via GET: status, department_id, user_id, start_date, end_date
    - Tampilkan tabel: No. Pengajuan, Pegawai, Departemen, Tujuan, Total, Status, Tanggal
    - Tombol Lihat Detail: redirect ke halaman detail pengajuan (read-only view yang sudah ada)
    - _Requirements: 9.6, 9.8_

  - [ ] 12.5 Buat `public/admin/audit_log.php` — tampilan audit log read-only
    - `requireAuth()` + `requireRole(['admin'])`
    - Query: `SELECT al.*, u.name, u.role FROM audit_logs al LEFT JOIN users u ON u.id=al.user_id ORDER BY al.created_at DESC`
    - Filter via GET: user_name (LIKE), action, table_name, start_date, end_date
    - Tampilkan tabel: Waktu, Pengguna, Role, Aksi, Tabel, Record ID, IP, Detail (collapsible accordion)
    - Pagination 50 baris per halaman
    - Tidak ada tombol hapus atau edit — murni read-only
    - _Requirements: 8.5, 8.6, 9.8_

- [ ] 13. Sistem Notifikasi — Badge, Panel, dan API

  - [ ] 13.1 Buat `public/api/notifications.php` — endpoint polling notifikasi
    - `requireAuth()`; kembalikan JSON
    - Jika `?count_only=1`: kembalikan `{count: getUnreadCount($userId)}`
    - Jika tidak: kembalikan `{count, notifications: getNotifications($userId, 10)}`
    - _Requirements: 8.2_

  - [x] 13.2 Buat `public/api/mark_notif.php` — tandai notifikasi dibaca
    - `requireAuth()`; kembalikan JSON
    - Jika `?all=1`: panggil `markAllAsRead($userId)`
    - Jika tidak: terima `notif_id` → panggil `markAsRead($notifId, $userId)` → kembalikan `{redirect_url}` berdasarkan `reimbursement_id` notifikasi
    - _Requirements: 8.3, 8.7_

  - [x] 13.3 Wire panel dropdown notifikasi di `navbar.php`
    - Panel dropdown: load notifikasi via `fetch(BASE_URL + '/api/notifications.php')` saat klik ikon lonceng
    - Klik satu notifikasi: POST ke `mark_notif.php` → redirect ke `detail.php?id={reimbursement_id}`
    - Tombol "Tandai Semua Dibaca": POST ke `mark_notif.php?all=1` → perbarui badge ke 0
    - Badge `#notif-count` diperbarui setiap 30 detik via polling (sudah di-setup di task 1.6)
    - _Requirements: 8.2, 8.3, 8.7_

- [ ] 14. Checkpoint — Verifikasi sistem notifikasi dan audit log
  - Verifikasi badge muncul dan terupdate setelah transisi status
  - Verifikasi klik notifikasi mengarahkan ke detail pengajuan yang benar
  - Verifikasi audit log bertambah monoton dan tidak bisa dihapus via UI
  - Pastikan semua test lulus; tanyakan kepada user jika ada pertanyaan.

- [ ] 15. Keamanan — CSRF, XSS, Upload, Session Hardening

  - [ ] 15.1 Implementasikan CSRF protection di seluruh form POST
    - Verifikasi bahwa SEMUA form POST dalam seluruh codebase sudah memanggil `echo csrfField()` di dalam `<form>` dan `verifyCsrf()` di handler POST
    - Lengkapi form yang terlewat dengan menambahkan kedua pemanggilan tersebut
    - Untuk AJAX: pastikan `<meta name="csrf-token">` ada di header.php dan setiap `fetch()` POST menyertakan header `X-CSRF-Token: document.querySelector('meta[name="csrf-token"]').content`; endpoint API memvalidasi header ini
    - Tidak perlu menulis ulang implementasi CSRF dari nol — cukup audit dan lengkapi
    - _Requirements: 1.1_

  - [ ] 15.2 Pastikan XSS prevention dan session hardening diterapkan menyeluruh
    - Semua output dari database/input user dibungkus `htmlspecialchars($val, ENT_QUOTES, 'UTF-8')`
    - Set `ini_set('session.cookie_httponly', 1)`, `session.cookie_samesite=Lax`, `session.use_strict_mode=1` di awal aplikasi (sebelum `session_start()`)
    - Buat `uploads/.htaccess`: `Options -Indexes -ExecCGI`, `php_flag engine off`
    - _Requirements: 1.1, 3.1_

- [ ] 16. Checkpoint Final — Pastikan semua modul terintegrasi
  - Jalankan alur penuh lintas semua role dari login hingga pembayaran
  - Verifikasi ekspor CSV dan fungsi cetak/PDF laporan
  - Verifikasi deaktivasi user dengan pengajuan aktif ditolak
  - Verifikasi semua link menggunakan BASE_URL (tidak ada hard-coded path)
  - Pastikan semua test lulus; tanyakan kepada user jika ada pertanyaan.

---

### Optional: Unit Tests (Semua ditandai dengan *)

- [ ] 17. Unit Tests untuk Helper dan Logika Bisnis

  - [ ]* 17.1 Tulis unit test untuk `auth.php`
    - Test `login()`: email + password benar → sesi dibuat dan return true
    - Test `login()`: email tidak ada → return false
    - Test `isSessionExpired()`: `last_activity` > 1800 detik → return true; <= 1800 → return false
    - _Requirements: 1.1, 1.5_

  - [ ]* 17.2 Tulis unit test untuk `functions.php` — helper dasar
    - Test `generateReimbNo()`: format `RMB-YYYY-000001` dengan padding 6 digit
    - Test `canEdit()`: status `draft` → true; status `review_atasan` → false; status `perlu_revisi` → true
    - Test `determineOcrStatus()`: confidence 65 → `buram`; confidence 70 → `sukses`; confidence 85 → `sukses`
    - _Requirements: 2.1, 2.8, 3.3_

  - [ ]* 17.3 Tulis unit test untuk validasi upload dan pembayaran
    - Test `isValidUploadFile()`: file JPEG 2MB → diterima; file EXE → ditolak; file 6MB → ditolak; MIME mismatch → ditolak
    - Test `isValidPaymentAmount()`: 50000 → true; 0 → false; -1 → false; null → false; '' → false
    - _Requirements: 3.6, 6.4, 6.5_

  - [ ]* 17.4 Tulis unit test untuk routing revisi
    - Test `answerRevision()` dengan revision_request dari atasan → status menjadi `diajukan`
    - Test `answerRevision()` dengan revision_request dari finance → status menjadi `review_finance`
    - _Requirements: 2.7_

  - [ ]* 17.5 Tulis unit test untuk password hashing
    - Test `createUser()`: `password_verify()` berhasil untuk password yang benar; gagal untuk password salah
    - Test `resetPassword()`: hash yang tersimpan tidak sama dengan plain text
    - _Requirements: 9.1, 9.3_

---

### Optional: Property-Based Tests (Semua ditandai dengan *)

- [ ] 18. Property-Based Tests menggunakan PhpQuickCheck

  - [ ]* 18.1 Tulis property test untuk Property 1 — Status Transition Validity
    - **Property 1: Status Transition Validity**
    - *For any* status pengajuan saat ini dan target status, `isValidTransition()` HARUS mengembalikan true jika dan hanya jika pasangan tersebut terdaftar dalam `VALID_TRANSITIONS`
    - Jalankan 100 iterasi dengan kombinasi status acak
    - **Validates: Requirements 2.3, 2.7, 4.4, 4.5, 4.6, 5.5, 5.6, 6.2**

  - [ ]* 18.2 Tulis property test untuk Property 2 — Total Amount Consistency
    - **Property 2: Total Amount Consistency**
    - *For any* pengajuan dengan 1–10 item biaya acak, `total_amount` di tabel `reimbursements` HARUS selalu sama dengan `SUM(amount)` dari semua item terkait
    - Jalankan 100 iterasi dengan jumlah dan nominal item acak
    - **Validates: Requirements 2.2**

  - [ ]* 18.3 Tulis property test untuk Property 4 — OCR Confidence Threshold
    - **Property 4: OCR Confidence Threshold**
    - *For any* nilai confidence dalam rentang 0–100, `determineOcrStatus(confidence)` HARUS mengembalikan `'buram'` jika dan hanya jika `confidence < 70`
    - Jalankan 100 iterasi dengan nilai float acak dalam rentang tersebut
    - **Validates: Requirements 3.3**

  - [ ]* 18.4 Tulis property test untuk Property 5 — Subordinate Visibility
    - **Property 5: Subordinate Visibility**
    - *For any* supervisor, `getSubordinateReimbursements($supervisorId)` HARUS hanya mengembalikan pengajuan dari user dengan `supervisor_id = $supervisorId`
    - Generate struktur org acak (1 supervisor + 2–5 bawahan + 2–3 non-bawahan)
    - **Validates: Requirements 4.1**

  - [ ]* 18.5 Tulis property test untuk Property 6 — Finance Verification Completeness Gate
    - **Property 6: Finance Verification Completeness Gate**
    - *For any* pengajuan dengan N item, `canFinanceApprove()` HARUS mengembalikan true jika dan hanya jika semua item `verification_status ≠ 'belum'`
    - Jalankan 100 iterasi dengan kombinasi jumlah item terverifikasi vs belum
    - **Validates: Requirements 5.7**

  - [ ]* 18.6 Tulis property test untuk Property 7 — Payment Non-Zero Amount
    - **Property 7: Payment Non-Zero Amount**
    - *For any* nilai yang dikirim sebagai nominal pembayaran (termasuk negatif, nol, null, string kosong), `isValidPaymentAmount()` HARUS mengembalikan true jika dan hanya jika nilai tersebut numerik dan > 0
    - **Validates: Requirements 6.5**

  - [ ]* 18.7 Tulis property test untuk Property 10 — Revision Source Routing
    - **Property 10: Revision Source Routing**
    - *For any* `revision_request` yang dibuat oleh atasan, `answerRevision()` HARUS menghasilkan status `'diajukan'`; jika dibuat oleh finance HARUS menghasilkan `'review_finance'`
    - Jalankan 100 iterasi dengan role pembuat revisi dipilih secara acak
    - **Validates: Requirements 2.7**

  - [ ]* 18.8 Tulis property test untuk Property 11 — OCR Data Round-Trip
    - **Property 11: OCR Data Round-Trip**
    - *For any* set hasil OCR yang valid, setelah disimpan via `ocr_save.php` dan dibaca kembali dengan `getReceiptOcrData()`, seluruh field HARUS identik dengan data yang dikirimkan
    - Generate merchant acak, tanggal acak, total acak, confidence acak, raw_text acak
    - **Validates: Requirements 3.2**

  - [ ]* 18.9 Tulis property test untuk Property 12 — File Format Validation
    - **Property 12: File Format Validation**
    - *For any* kombinasi ekstensi file dan MIME type, `isValidUploadFile()` HARUS mengembalikan true jika dan hanya jika ekstensi termasuk `jpg/jpeg/png/pdf` DAN MIME type sesuai
    - Jalankan 100 iterasi dengan ekstensi dipilih dari pool valid + invalid
    - **Validates: Requirements 3.6, 6.4**

  - [ ]* 18.10 Tulis property test untuk Property 13 — Password Always Hashed
    - **Property 13: Password Always Hashed**
    - *For any* string password, nilai yang tersimpan di `password_hash` HARUS merupakan hash bcrypt valid dan tidak pernah identik dengan plaintext
    - Jalankan 100 iterasi dengan password acak (8–20 karakter)
    - **Validates: Requirements 9.1, 9.3**

  - [ ]* 18.11 Tulis property test untuk Property 14 — User Deactivation Guard
    - **Property 14: User Deactivation Guard**
    - *For any* user dengan 0–5 pengajuan berstatus acak, `canDeactivateUser()` HARUS mengembalikan false jika ada pengajuan aktif (status selain `dibayar`/`ditolak`), true jika tidak ada
    - **Validates: Requirements 9.7**

  - [ ]* 18.12 Tulis property test untuk Property 15 — Unread Count Reset After Mark All Read
    - **Property 15: Unread Count Reset After Mark All Read**
    - *For any* user dengan 0–20 notifikasi belum dibaca, setelah `markAllAsRead()`, `getUnreadCount()` HARUS mengembalikan 0; memanggil `markAllAsRead()` dua kali berturut-turut HARUS menghasilkan efek yang sama (idempoten)
    - **Validates: Requirements 8.7**

---

## Notes

- Task bertanda `*` bersifat opsional dan dapat dilewati untuk implementasi MVP yang lebih cepat
- Setiap task merujuk ke requirement spesifik untuk keterlacakan
- Database sudah ada dan tidak memerlukan migrasi — tidak ada task ALTER TABLE
- Kolom `verification_status`, `verified_by`, `verified_at` di `reimbursement_items` dan `proof_path` di `payments` sudah ada di database
- Trigger `trg_reimb_status_au` menangani INSERT ke `status_history` secara otomatis — aplikasi hanya perlu `SET @actor_id=?, @status_note=?` sebelum UPDATE pada koneksi PDO yang sama
- Semua link dan redirect menggunakan konstanta `BASE_URL` yang didefinisikan di `config/database.php`
- OCR dijalankan sepenuhnya di browser (Tesseract.js) — tidak ada beban CPU di server
- Ekspor default menggunakan `fputcsv` (CSV) dan `window.print()` (PDF) tanpa Composer; PhpSpreadsheet/mPDF hanya jika Composer tersedia

## Task Dependency Graph

```json
{
  "waves": [
    { "id": 0, "tasks": ["1.1"] },
    { "id": 1, "tasks": ["1.2", "1.3", "1.4", "1.5"] },
    { "id": 2, "tasks": ["1.6", "2.1", "2.2", "2.3"] },
    { "id": 3, "tasks": ["4.1", "4.2", "4.3", "4.4", "4.5"] },
    { "id": 4, "tasks": ["6.1", "6.3", "6.4"] },
    { "id": 5, "tasks": ["6.2", "7.1", "7.2"] },
    { "id": 6, "tasks": ["8.1", "8.2", "9.1", "9.2"] },
    { "id": 7, "tasks": ["8.3", "11.1", "11.2", "12.1", "12.2", "12.3", "12.4", "12.5", "13.1", "13.2"] },
    { "id": 8, "tasks": ["13.3", "15.1", "15.2"] },
    { "id": 9, "tasks": ["17.1", "17.2", "17.3", "17.4", "17.5"] },
    { "id": 10, "tasks": ["18.1", "18.2", "18.3", "18.4", "18.5", "18.6", "18.7", "18.8", "18.9", "18.10", "18.11", "18.12"] }
  ]
}
```
