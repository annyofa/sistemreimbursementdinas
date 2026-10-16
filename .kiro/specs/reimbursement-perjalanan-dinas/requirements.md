# Requirements Document

## Introduction

Sistem Reimbursement Perjalanan Dinas adalah aplikasi berbasis web yang dibangun menggunakan PHP 8, MySQL, dan Bootstrap 5. Sistem ini memfasilitasi proses pengajuan, peninjauan, persetujuan, dan pembayaran klaim biaya perjalanan dinas secara digital dan terstruktur.

**Tujuan**: Menggantikan proses manual berbasis dokumen kertas dengan alur digital yang terotomasi, transparan, dan dapat dilacak secara real-time.

**Ruang Lingkup**: Sistem mencakup seluruh siklus hidup pengajuan reimbursement, mulai dari pembuatan draft oleh pegawai hingga pembayaran oleh kasir, termasuk proses OCR struk, review bertingkat, notifikasi, dan pelaporan manajemen.

**Pengguna Sistem**:
- **Pegawai**: Membuat dan mengajukan klaim reimbursement perjalanan dinas
- **Atasan**: Meninjau dan menyetujui pengajuan dari bawahan langsung
- **Finance**: Memverifikasi item biaya dan dokumen pendukung
- **Kasir**: Mencatat dan memproses pembayaran pengajuan yang disetujui
- **Manajemen**: Memantau dashboard dan laporan reimbursement
- **Admin**: Mengelola pengguna, kategori, dan konfigurasi sistem

---

## Glossary

- **Pengajuan**: Dokumen klaim reimbursement yang dibuat oleh pegawai untuk satu perjalanan dinas
- **Item Biaya**: Satu baris klaim biaya dalam pengajuan, mencakup kategori, deskripsi, dan nominal
- **Struk**: Bukti pembayaran fisik (foto) yang diunggah per item biaya
- **OCR (Optical Character Recognition)**: Teknologi pengenalan teks dari gambar struk
- **Confidence Score**: Skor kepercayaan hasil OCR dalam rentang 0-100 persen
- **Struk Buram**: Struk dengan confidence score OCR di bawah 70 persen
- **Draft**: Status awal pengajuan yang belum disubmit
- **Diajukan**: Status pengajuan yang telah disubmit pegawai dan menunggu review atasan
- **Review_Atasan**: Status pengajuan yang sedang ditinjau oleh atasan langsung
- **Review_Finance**: Status pengajuan yang telah disetujui atasan dan sedang diverifikasi finance
- **Perlu_Revisi**: Status pengajuan yang dikembalikan karena memerlukan perbaikan dari pengaju
- **Disetujui**: Status pengajuan yang telah melewati seluruh proses review dan siap dibayar
- **Dibayar**: Status akhir pengajuan yang telah diselesaikan pembayarannya oleh kasir
- **Ditolak**: Status akhir pengajuan yang tidak disetujui pada tahap review manapun
- **Revision_Request**: Catatan permintaan perbaikan yang dikirim oleh atasan atau finance ke pegawai
- **Audit Log**: Rekaman setiap aksi yang dilakukan pengguna beserta waktu dan identitasnya
- **Bawahan Langsung**: Pegawai yang memiliki atasan yang sama berdasarkan kolom `supervisor_id` di tabel pengguna
- **Paraf Digital**: File gambar tanda tangan atau paraf yang diunggah atasan sebagai bukti persetujuan
- **Alur Status**: draft -> diajukan -> review_atasan -> review_finance -> (perlu_revisi) -> disetujui -> dibayar / ditolak

---

## Requirements

> **Catatan Implementasi**: Setiap perubahan status pengajuan dicatat secara otomatis ke tabel `status_history` oleh trigger di database. Aplikasi tidak perlu melakukan INSERT ke tabel `status_history` secara eksplisit; cukup lakukan UPDATE kolom `status` pada tabel `reimbursement_requests`.

---

### Requirement 1: Autentikasi dan Otorisasi

**User Story:** Sebagai pengguna sistem, saya ingin dapat masuk ke sistem menggunakan username dan password, sehingga saya dapat mengakses fitur yang sesuai dengan peran saya secara aman.

#### Acceptance Criteria

1. WHEN pengguna mengirimkan form login dengan email dan password yang valid, THE Sistem SHALL mengautentikasi pengguna, membuat sesi aktif, dan mengarahkan pengguna ke dashboard sesuai rolenya.

2. WHEN pengguna mengirimkan form login dengan email atau password yang tidak valid, THE Sistem SHALL menolak login, menampilkan pesan kesalahan "Email atau password salah", dan tidak membuat sesi.

3. WHEN pengguna yang sudah login mencoba mengakses halaman atau fitur yang tidak sesuai dengan rolenya, THE Sistem SHALL menolak akses dan menampilkan halaman error 403 (Akses Ditolak).

4. WHEN pengguna mengklik tombol Logout, THE Sistem SHALL menghapus seluruh data sesi aktif pengguna tersebut dan mengarahkan ke halaman login.

5. WHILE sesi pengguna tidak aktif selama lebih dari 30 menit, THE Sistem SHALL menginvalidasi sesi secara otomatis dan mengarahkan pengguna ke halaman login saat aksi berikutnya dilakukan.

6. THE Sistem SHALL memberlakukan hak akses berbeda per role, di mana role pegawai hanya dapat mengakses menu pengajuan milik sendiri, role atasan dapat mengakses pengajuan bawahan langsung, role finance dapat mengakses semua pengajuan berstatus review_finance, role kasir dapat mengakses pengajuan berstatus disetujui, role manajemen dapat mengakses dashboard dan laporan, serta role admin dapat mengakses seluruh fitur administrasi.

7. WHEN pengguna belum login dan mencoba mengakses halaman yang memerlukan autentikasi, THE Sistem SHALL mengarahkan pengguna ke halaman login.

---

### Requirement 2: Manajemen Pengajuan oleh Pegawai

**User Story:** Sebagai pegawai, saya ingin dapat membuat, melengkapi, dan mengajukan klaim reimbursement perjalanan dinas beserta item biayanya, sehingga saya dapat memproses penggantian biaya perjalanan dinas yang telah saya keluarkan.

#### Acceptance Criteria

1. WHEN pegawai mengisi dan menyimpan form pengajuan baru dengan data tujuan, tanggal berangkat, tanggal kembali, keperluan, dan departemen yang valid, THE Sistem SHALL membuat record pengajuan baru dengan status "draft" dan menampilkan halaman detail pengajuan tersebut.

2. WHEN pegawai menambahkan item biaya pada pengajuan berstatus draft dengan memilih kategori, mengisi deskripsi, dan memasukkan nominal yang valid, THE Sistem SHALL menyimpan item biaya tersebut dan memperbarui total nominal pengajuan secara otomatis.

3. WHEN pegawai mengklik tombol Submit pada pengajuan berstatus draft yang memiliki minimal satu item biaya, THE Sistem SHALL mengubah status pengajuan menjadi "diajukan", mencatat waktu pengajuan, dan mengirimkan notifikasi kepada atasan langsung pegawai tersebut.

4. IF pegawai mencoba submit pengajuan berstatus draft yang tidak memiliki item biaya, THEN THE Sistem SHALL menolak submit dan menampilkan pesan "Pengajuan harus memiliki minimal satu item biaya".

5. WHEN pegawai mengakses menu Pengajuan Saya, THE Sistem SHALL menampilkan daftar seluruh pengajuan milik pegawai tersebut beserta status terkini, tanggal pengajuan, tujuan, dan total nominal, diurutkan berdasarkan tanggal terbaru.

6. WHEN pegawai membuka detail pengajuan, THE Sistem SHALL menampilkan timeline riwayat perubahan status secara kronologis, mencakup nama aktor, status sebelum dan sesudah, catatan, serta waktu setiap perubahan.

7. WHEN pegawai membuka pengajuan berstatus perlu_revisi dan mengisi form jawaban revisi dengan perbaikan yang diminta, THE Sistem SHALL menyimpan jawaban revisi dan mengubah status pengajuan berdasarkan asal permintaan revisi: jika revision_request dibuat oleh atasan maka status kembali menjadi "diajukan", jika dibuat oleh finance maka status kembali menjadi "review_finance", serta mengirimkan notifikasi kepada pengguna yang mengirimkan permintaan revisi tersebut.

8. IF pegawai mencoba mengedit pengajuan yang statusnya bukan draft atau perlu_revisi, THEN THE Sistem SHALL menolak aksi edit dan menampilkan pesan bahwa pengajuan tidak dapat diubah pada status tersebut.

---

### Requirement 3: OCR Struk

**User Story:** Sebagai pegawai, saya ingin sistem secara otomatis membaca informasi dari foto struk yang saya unggah, sehingga saya tidak perlu menginput ulang data merchant, tanggal, dan nominal secara manual dan validasi data menjadi lebih akurat.

#### Acceptance Criteria

1. WHEN pegawai mengunggah foto struk untuk item biaya, THE OCR_Module SHALL memproses gambar tersebut menggunakan layanan OCR (Tesseract.js sisi klien atau API OCR eksternal) dan mengekstrak informasi nama merchant, tanggal transaksi, dan total nominal dari gambar.

2. WHEN proses OCR berhasil diselesaikan, THE Sistem SHALL menyimpan hasil ekstraksi berupa nama merchant, tanggal transaksi, total nominal, dan nilai confidence score ke tabel penyimpan data OCR yang terhubung dengan item biaya yang bersangkutan.

3. WHEN hasil OCR menunjukkan confidence score kurang dari 70 persen, THE Sistem SHALL menandai struk tersebut sebagai "buram" dan menampilkan peringatan kepada pegawai untuk mengunggah ulang foto struk dengan kualitas yang lebih baik.

4. IF pegawai mencoba menyimpan item biaya dengan struk yang masih berstatus "buram" tanpa mengunggah ulang, THEN THE Sistem SHALL menampilkan konfirmasi peringatan bahwa struk berkualitas rendah dapat mempengaruhi proses verifikasi, namun tetap mengizinkan penyimpanan setelah pegawai mengkonfirmasi.

5. WHEN proses OCR gagal karena format file tidak didukung atau koneksi ke layanan OCR terputus, THE Sistem SHALL mencatat kegagalan tersebut di log, menampilkan pesan error yang informatif kepada pegawai, dan mengizinkan pegawai menyimpan item biaya tanpa data OCR.

6. THE Sistem SHALL mendukung format file gambar JPEG, PNG, dan PDF untuk unggahan struk, dan menolak format lain dengan menampilkan pesan "Format file tidak didukung".

7. WHEN finance membuka detail item biaya saat verifikasi, THE Sistem SHALL menampilkan perbandingan antara nominal yang diinput pegawai dengan total nominal hasil OCR beserta nilai confidence score-nya.

---

### Requirement 4: Review oleh Atasan

**User Story:** Sebagai atasan, saya ingin dapat meninjau, menyetujui, menolak, atau meminta revisi pengajuan reimbursement dari bawahan langsung saya beserta bukti struk, sehingga saya dapat memastikan setiap pengajuan valid dan sesuai dengan kebutuhan dinas sebelum diverifikasi finance.

#### Acceptance Criteria

1. WHEN atasan mengakses menu Review Pengajuan, THE Sistem SHALL menampilkan daftar pengajuan berstatus "diajukan" dari seluruh bawahan langsung atasan tersebut, diurutkan berdasarkan tanggal pengajuan terlama (antrian FIFO).

2. WHEN atasan membuka detail pengajuan berstatus "diajukan" milik bawahan, THE Sistem SHALL mengubah status pengajuan menjadi "review_atasan" secara otomatis dan mencatat perubahan status tersebut.

3. WHEN atasan membuka detail pengajuan bawahan, THE Sistem SHALL menampilkan informasi lengkap pengajuan mencakup data perjalanan dinas, daftar item biaya, foto struk per item, hasil OCR, dan riwayat status.

4. WHEN atasan mengklik tombol Setujui pada detail pengajuan dan mengunggah paraf digital, THE Sistem SHALL menyimpan file paraf, mengubah status pengajuan dari "review_atasan" menjadi "review_finance", mencatat aksi di audit log, dan mengirimkan notifikasi kepada tim finance.

5. WHEN atasan mengklik tombol Tolak pada detail pengajuan dan mengisi alasan penolakan, THE Sistem SHALL mengubah status pengajuan menjadi "ditolak", menyimpan alasan penolakan, mencatat aksi di audit log, dan mengirimkan notifikasi penolakan kepada pegawai pengaju.

6. WHEN atasan mengklik tombol Minta Revisi dan mengisi catatan revisi yang spesifik, THE Sistem SHALL membuat record revision_request dengan catatan tersebut, mengubah status pengajuan menjadi "perlu_revisi", dan mengirimkan notifikasi kepada pegawai pengaju beserta isi catatan revisi.

7. WHEN ada pengajuan baru masuk dari bawahan langsung, THE Sistem SHALL menampilkan notifikasi in-app kepada atasan yang bersangkutan dengan informasi nama pegawai, tujuan perjalanan, dan total nominal pengajuan.

8. IF atasan mencoba menyetujui pengajuan tanpa mengunggah paraf digital, THEN THE Sistem SHALL menolak aksi persetujuan dan menampilkan pesan "Paraf digital wajib dilampirkan untuk menyetujui pengajuan".

---

### Requirement 5: Verifikasi oleh Finance

**User Story:** Sebagai finance, saya ingin dapat memverifikasi setiap item biaya dalam pengajuan yang telah disetujui atasan, membandingkannya dengan data OCR, dan meminta klarifikasi jika diperlukan, sehingga saya dapat memastikan seluruh klaim biaya valid dan akurat sebelum disetujui untuk pembayaran.

#### Acceptance Criteria

1. WHEN finance mengakses menu Verifikasi Pengajuan, THE Sistem SHALL menampilkan daftar seluruh pengajuan berstatus "review_finance" beserta nama pengaju, departemen, tujuan, dan total nominal, diurutkan berdasarkan tanggal pengajuan masuk ke antrian review_finance.

2. WHEN finance membuka detail pengajuan, THE Sistem SHALL menampilkan setiap item biaya dengan kolom perbandingan antara nominal yang diinput pegawai dan total nominal hasil OCR, disertai indikator visual apabila terdapat selisih di atas 5 persen.

3. WHEN finance menandai item biaya sebagai "diverifikasi" atau "ditolak" dan menyimpan hasil verifikasi per item, THE Sistem SHALL memperbarui kolom `verification_status`, `verified_by`, dan `verified_at` pada tabel `reimbursement_items` untuk item yang bersangkutan.

4. WHEN finance mengklik tombol Kirim Klarifikasi dan mengisi catatan permintaan klarifikasi melalui revision_requests, THE Sistem SHALL menyimpan catatan tersebut, mengubah status pengajuan menjadi "perlu_revisi", dan mengirimkan notifikasi kepada pegawai pengaju.

5. WHEN finance mengklik tombol Setujui Pengajuan setelah seluruh item biaya telah diverifikasi, THE Sistem SHALL mengubah status pengajuan menjadi "disetujui", mencatat aksi di audit log, dan mengirimkan notifikasi kepada kasir bahwa ada pengajuan siap dibayar.

6. WHEN finance mengklik tombol Tolak Pengajuan dan mengisi alasan penolakan, THE Sistem SHALL mengubah status pengajuan menjadi "ditolak", menyimpan alasan penolakan, mencatat aksi di audit log, dan mengirimkan notifikasi penolakan kepada pegawai pengaju.

7. IF finance mencoba menyetujui keseluruhan pengajuan sementara masih terdapat item biaya yang belum diverifikasi, THEN THE Sistem SHALL menolak aksi persetujuan dan menampilkan daftar item yang belum diverifikasi.

---

### Requirement 6: Pembayaran oleh Kasir

**User Story:** Sebagai kasir, saya ingin dapat mencatat pembayaran reimbursement untuk pengajuan yang telah disetujui, sehingga proses penggantian biaya kepada pegawai dapat terdokumentasi dengan lengkap dan status pengajuan diperbarui secara akurat.

#### Acceptance Criteria

1. WHEN kasir mengakses menu Pembayaran, THE Sistem SHALL menampilkan daftar seluruh pengajuan berstatus "disetujui" beserta nama pegawai, departemen, total nominal, dan tanggal persetujuan, diurutkan berdasarkan tanggal persetujuan terlama.

2. WHEN kasir mengisi form pembayaran dengan metode pembayaran (transfer atau tunai), tanggal bayar, nominal yang dibayarkan, dan nomor referensi, lalu mengklik Simpan, THE Sistem SHALL menyimpan data pembayaran tersebut dan mengubah status pengajuan menjadi "dibayar".

3. WHEN kasir menyimpan data pembayaran, THE Sistem SHALL mencatat aksi tersebut di audit log beserta nama kasir, waktu, nominal, metode, dan nomor referensi, serta mengirimkan notifikasi kepada pegawai bahwa pembayaran telah diproses.

4. WHERE kasir memilih untuk melampirkan bukti pembayaran, THE Sistem SHALL menerima unggahan file bukti pembayaran dalam format JPEG, PNG, atau PDF dan menyimpan path file tersebut ke kolom `proof_path` di tabel `payments`.

5. IF kasir mencoba menyimpan pembayaran dengan nominal yang diisi nol atau kosong, THEN THE Sistem SHALL menolak penyimpanan dan menampilkan pesan "Nominal pembayaran tidak boleh kosong atau nol".

6. WHEN kasir membuka detail pengajuan yang sudah berstatus "dibayar", THE Sistem SHALL menampilkan ringkasan data pembayaran mencakup metode, tanggal bayar, nominal, nomor referensi, dan bukti pembayaran jika tersedia.

---

### Requirement 7: Dashboard dan Laporan Manajemen

**User Story:** Sebagai manajemen, saya ingin dapat melihat ringkasan dan laporan reimbursement perjalanan dinas berdasarkan berbagai dimensi dan periode, serta mengekspornya ke Excel atau PDF, sehingga saya dapat memantau pengeluaran dinas dan mengambil keputusan berbasis data.

#### Acceptance Criteria

1. WHEN manajemen mengakses halaman Dashboard, THE Sistem SHALL menampilkan ringkasan berupa total jumlah pengajuan, total nominal keseluruhan, dan breakdown jumlah pengajuan per status (draft, diajukan, review_atasan, review_finance, perlu_revisi, disetujui, dibayar, ditolak) untuk periode bulan berjalan secara default.

2. WHEN manajemen menerapkan filter periode, departemen, atau kategori biaya pada dashboard atau halaman laporan, THE Sistem SHALL memperbarui seluruh data yang ditampilkan sesuai kombinasi filter yang dipilih.

3. WHEN manajemen mengakses laporan bulanan, THE Sistem SHALL menampilkan tabel ringkasan per departemen yang memuat jumlah pengajuan, total nominal diajukan, total nominal disetujui, dan total nominal dibayar untuk setiap departemen dalam periode yang dipilih.

4. WHEN manajemen mengakses laporan per kategori biaya, THE Sistem SHALL menampilkan total nominal dan jumlah item per kategori biaya dalam periode yang dipilih, diurutkan dari nominal terbesar.

5. WHEN manajemen mengakses laporan rata-rata lama proses, THE Sistem SHALL menghitung dan menampilkan rata-rata durasi dalam hari dari status "diajukan" hingga status "dibayar" per departemen dan per periode yang dipilih.

6. WHEN manajemen mengklik tombol Ekspor Excel pada halaman laporan, THE Sistem SHALL menghasilkan dan mengunduhkan file berformat .xlsx yang memuat seluruh data laporan sesuai filter aktif.

7. WHEN manajemen mengklik tombol Ekspor PDF pada halaman laporan, THE Sistem SHALL menghasilkan dan mengunduhkan file berformat PDF yang memuat seluruh data laporan sesuai filter aktif dalam tata letak yang siap cetak.

---

### Requirement 8: Notifikasi dan Audit Log

**User Story:** Sebagai pengguna sistem, saya ingin menerima notifikasi setiap kali ada perubahan status yang relevan dengan saya, dan sebagai admin saya ingin dapat melihat seluruh rekaman aktivitas sistem, sehingga seluruh proses reimbursement dapat dipantau dan dipertanggungjawabkan secara penuh.

#### Acceptance Criteria

1. WHEN status pengajuan berubah pada tahap manapun dalam alur status, THE Sistem SHALL membuat record notifikasi in-app yang ditujukan kepada pengguna yang relevan (pegawai pengaju, atasan berikutnya, finance, atau kasir sesuai alur), berisi informasi nomor pengajuan, perubahan status, dan waktu perubahan.

2. WHEN pengguna mengakses halaman manapun dalam sistem, THE Sistem SHALL menampilkan jumlah notifikasi yang belum dibaca di ikon notifikasi pada navigation bar.

3. WHEN pengguna mengklik notifikasi yang belum dibaca, THE Sistem SHALL menandai notifikasi tersebut sebagai "sudah dibaca", memperbarui penghitung notifikasi, dan mengarahkan pengguna ke halaman detail pengajuan yang relevan.

4. WHEN pengguna melakukan aksi apapun yang mengubah data (login, logout, buat pengajuan, ubah status, tambah item, unggah file, edit data, hapus data), THE Sistem SHALL mencatat satu record audit log yang memuat: ID pengguna, nama pengguna, role, aksi yang dilakukan, nama tabel dan ID record yang terpengaruh, IP address, waktu aksi, dan data sebelum-sesudah perubahan jika relevan.

5. WHEN admin mengakses halaman Audit Log, THE Sistem SHALL menampilkan seluruh record audit log dari semua pengguna yang dapat difilter berdasarkan nama pengguna, jenis aksi, rentang tanggal, dan tabel yang terpengaruh.

6. THE Sistem SHALL menyimpan seluruh record audit log secara permanen dan tidak mengizinkan penghapusan record audit log melalui antarmuka pengguna manapun termasuk admin.

7. WHEN pengguna mengklik tombol Tandai Semua Dibaca pada panel notifikasi, THE Sistem SHALL mengubah status seluruh notifikasi belum dibaca milik pengguna tersebut menjadi sudah dibaca dan memperbarui penghitung menjadi nol.

---

### Requirement 9: Administrasi oleh Admin

**User Story:** Sebagai admin, saya ingin dapat mengelola data pengguna, kategori biaya, dan departemen, serta memiliki visibilitas penuh ke seluruh pengajuan lintas departemen, sehingga saya dapat memastikan sistem berjalan dengan konfigurasi yang benar dan mendukung operasional seluruh pengguna.

#### Acceptance Criteria

1. WHEN admin mengakses menu Manajemen User dan mengisi form tambah user dengan nama lengkap, email unik, password, role, departemen, dan data atasan langsung yang valid, THE Sistem SHALL menyimpan record user baru dengan password yang di-hash menggunakan algoritma bcrypt dan menampilkan user baru dalam daftar.

2. WHEN admin mengedit data user yang sudah ada dan menyimpan perubahan, THE Sistem SHALL memperbarui data user (nama, role, departemen, atasan langsung) kecuali kolom password, dan mencatat perubahan di audit log.

3. WHEN admin menggunakan fitur Reset Password untuk user tertentu dan mengisi password baru yang memenuhi ketentuan minimal 8 karakter, THE Sistem SHALL memperbarui password user tersebut dengan hash bcrypt yang baru tanpa menampilkan password lama maupun baru dalam response.

4. WHEN admin mengakses menu Manajemen Kategori Biaya dan menambahkan, mengedit, atau menonaktifkan kategori biaya, THE Sistem SHALL menyimpan perubahan tersebut dan kategori yang dinonaktifkan tidak akan muncul sebagai pilihan pada form pengajuan baru.

5. WHEN admin mengakses menu Manajemen Departemen dan menambahkan atau mengedit departemen, THE Sistem SHALL menyimpan perubahan tersebut dan departemen tersedia sebagai pilihan pada form user dan form pengajuan.

6. WHEN admin mengakses menu Semua Pengajuan, THE Sistem SHALL menampilkan daftar seluruh pengajuan dari semua departemen dan semua pegawai yang dapat difilter berdasarkan status, departemen, pegawai, dan rentang tanggal.

7. IF admin mencoba menonaktifkan atau menghapus user yang masih memiliki pengajuan aktif (berstatus selain dibayar atau ditolak), THEN THE Sistem SHALL menolak aksi tersebut dan menampilkan pesan yang mencantumkan jumlah pengajuan aktif milik user tersebut.

8. WHEN admin mengakses detail pengajuan manapun, THE Sistem SHALL menampilkan informasi lengkap pengajuan termasuk seluruh item biaya, struk, hasil OCR, riwayat status, revision requests, dan data pembayaran jika ada.
