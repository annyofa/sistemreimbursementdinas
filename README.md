# Sistem Reimbursement Perjalanan Dinas

Aplikasi web (PHP 8, MySQL, Bootstrap 5, Tesseract.js) untuk mengganti proses
reimbursement manual menjadi digital.

## Fitur
- Pengajuan reimbursement online (pegawai)
- Upload struk + OCR otomatis (Tesseract.js); struk buram ditandai
- Persetujuan berjenjang: atasan (paraf digital), finance (verifikasi per item)
- Pembayaran oleh kasir
- Tracking status (timeline) dan notifikasi in-app
- Dashboard dan laporan manajemen (ekspor CSV, cetak)
- Audit log

## Cara menjalankan
1. Pasang XAMPP, jalankan Apache dan MySQL.
2. Salin folder ini ke `C:\xampp\htdocs\reimbursement`.
3. Buka `http://localhost/phpmyadmin`, tab Import, pilih `reimbursement_db.sql`.
4. Buka `http://localhost/reimbursement/public/login.php`.

## Akun demo (password: password123)
| Role | Email |
|---|---|
| Pegawai | rudi@perusahaan.com |
| Atasan | atasan@perusahaan.com |
| Finance | finance@perusahaan.com |
| Kasir | kasir@perusahaan.com |
| Manajemen | manajemen@perusahaan.com |
| Admin | admin@perusahaan.com |

## Alur status
draft, diajukan, review atasan, review finance, disetujui, dibayar
(atau ditolak / perlu revisi)
