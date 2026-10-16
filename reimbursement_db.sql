-- =====================================================
-- Sistem Reimbursement Perjalanan Dinas (MySQL / XAMPP)
-- Import via phpMyAdmin atau: mysql -u root < reimbursement_db.sql
-- =====================================================
CREATE DATABASE IF NOT EXISTS reimbursement_db
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE reimbursement_db;

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS audit_logs, notifications, status_history, payments,
  revision_requests, approvals, receipts, reimbursement_items, reimbursements,
  business_trips, expense_categories, users, departments;
SET FOREIGN_KEY_CHECKS = 1;

-- 1. Departemen
CREATE TABLE departments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL UNIQUE,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- 2. Pengguna (Pegawai, Atasan, Finance, Kasir, Manajemen, Admin)
CREATE TABLE users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  employee_no VARCHAR(30) NOT NULL UNIQUE,
  name VARCHAR(100) NOT NULL,
  email VARCHAR(120) NOT NULL UNIQUE,
  phone VARCHAR(20),
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('pegawai','atasan','finance','kasir','manajemen','admin') NOT NULL DEFAULT 'pegawai',
  department_id INT,
  supervisor_id INT NULL,            -- atasan langsung
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (department_id) REFERENCES departments(id),
  FOREIGN KEY (supervisor_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_role (role)
) ENGINE=InnoDB;

-- 3. Kategori biaya
CREATE TABLE expense_categories (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(60) NOT NULL UNIQUE,
  max_amount DECIMAL(15,2) NULL,     -- batas per item (opsional)
  is_active TINYINT(1) DEFAULT 1
) ENGINE=InnoDB;

-- 4. Perjalanan dinas
CREATE TABLE business_trips (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  destination VARCHAR(100) NOT NULL,
  purpose VARCHAR(255),
  start_date DATE NOT NULL,
  end_date DATE NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

-- 5. Pengajuan reimbursement (header)
CREATE TABLE reimbursements (
  id INT AUTO_INCREMENT PRIMARY KEY,
  reimbursement_no VARCHAR(30) NOT NULL UNIQUE,   -- contoh: RMB-2026-000001
  user_id INT NOT NULL,
  trip_id INT NULL,
  total_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
  status ENUM('draft','diajukan','review_atasan','review_finance','perlu_revisi',
              'disetujui','ditolak','dibayar') NOT NULL DEFAULT 'draft',
  notes TEXT,
  submitted_at DATETIME NULL,
  paid_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id),
  FOREIGN KEY (trip_id) REFERENCES business_trips(id) ON DELETE SET NULL,
  INDEX idx_status (status),
  INDEX idx_submitted (submitted_at)
) ENGINE=InnoDB;

-- 6. Item biaya
CREATE TABLE reimbursement_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  reimbursement_id INT NOT NULL,
  category_id INT NOT NULL,
  expense_date DATE NOT NULL,
  merchant VARCHAR(150),
  description VARCHAR(255),
  amount DECIMAL(15,2) NOT NULL,
  approved_amount DECIMAL(15,2) NULL,             -- nominal disetujui finance
  FOREIGN KEY (reimbursement_id) REFERENCES reimbursements(id) ON DELETE CASCADE,
  FOREIGN KEY (category_id) REFERENCES expense_categories(id)
) ENGINE=InnoDB;

-- 7. Bukti transaksi + hasil OCR/ICR
CREATE TABLE receipts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  item_id INT NOT NULL,
  file_path VARCHAR(255) NOT NULL,
  file_type VARCHAR(20),
  ocr_status ENUM('pending','sukses','gagal','buram') DEFAULT 'pending',
  ocr_raw_text MEDIUMTEXT,
  ocr_merchant VARCHAR(150),
  ocr_date DATE NULL,
  ocr_total DECIMAL(15,2) NULL,
  ocr_confidence DECIMAL(5,2) NULL,               -- 0 - 100
  is_verified TINYINT(1) DEFAULT 0,
  uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (item_id) REFERENCES reimbursement_items(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 8. Persetujuan berjenjang (atasan -> finance)
CREATE TABLE approvals (
  id INT AUTO_INCREMENT PRIMARY KEY,
  reimbursement_id INT NOT NULL,
  level ENUM('atasan','finance') NOT NULL,
  approver_id INT NOT NULL,
  decision ENUM('menunggu','disetujui','ditolak','revisi') NOT NULL DEFAULT 'menunggu',
  comment TEXT,
  digital_signature VARCHAR(255) NULL,            -- paraf digital
  acted_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (reimbursement_id) REFERENCES reimbursements(id) ON DELETE CASCADE,
  FOREIGN KEY (approver_id) REFERENCES users(id)
) ENGINE=InnoDB;

-- 9. Permintaan revisi / klarifikasi (pengganti chat WA)
CREATE TABLE revision_requests (
  id INT AUTO_INCREMENT PRIMARY KEY,
  reimbursement_id INT NOT NULL,
  item_id INT NULL,
  requested_by INT NOT NULL,
  message TEXT NOT NULL,
  response TEXT NULL,
  status ENUM('terbuka','dijawab','selesai') DEFAULT 'terbuka',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  responded_at DATETIME NULL,
  FOREIGN KEY (reimbursement_id) REFERENCES reimbursements(id) ON DELETE CASCADE,
  FOREIGN KEY (item_id) REFERENCES reimbursement_items(id) ON DELETE SET NULL,
  FOREIGN KEY (requested_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- 10. Pembayaran oleh Kasir
CREATE TABLE payments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  reimbursement_id INT NOT NULL UNIQUE,
  cashier_id INT NOT NULL,
  amount DECIMAL(15,2) NOT NULL,
  method ENUM('transfer','tunai') NOT NULL DEFAULT 'transfer',
  bank_name VARCHAR(60),
  account_no VARCHAR(40),
  reference_no VARCHAR(60),
  paid_at DATETIME NOT NULL,
  FOREIGN KEY (reimbursement_id) REFERENCES reimbursements(id),
  FOREIGN KEY (cashier_id) REFERENCES users(id)
) ENGINE=InnoDB;

-- 11. Riwayat status (tracking real-time)
CREATE TABLE status_history (
  id INT AUTO_INCREMENT PRIMARY KEY,
  reimbursement_id INT NOT NULL,
  old_status VARCHAR(30),
  new_status VARCHAR(30) NOT NULL,
  changed_by INT NULL,
  note VARCHAR(255),
  changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (reimbursement_id) REFERENCES reimbursements(id) ON DELETE CASCADE,
  FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- 12. Notifikasi
CREATE TABLE notifications (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  reimbursement_id INT NULL,
  title VARCHAR(120) NOT NULL,
  message VARCHAR(255),
  is_read TINYINT(1) DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (reimbursement_id) REFERENCES reimbursements(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 13. Audit log
CREATE TABLE audit_logs (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NULL,
  action VARCHAR(60) NOT NULL,
  table_name VARCHAR(60),
  record_id INT,
  detail TEXT,
  ip_address VARCHAR(45),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- =====================================================
-- TRIGGERS
-- =====================================================
DELIMITER $$

-- Hitung ulang total saat item berubah
CREATE TRIGGER trg_item_ai AFTER INSERT ON reimbursement_items FOR EACH ROW
BEGIN
  UPDATE reimbursements
  SET total_amount = (SELECT COALESCE(SUM(amount),0) FROM reimbursement_items WHERE reimbursement_id = NEW.reimbursement_id)
  WHERE id = NEW.reimbursement_id;
END$$

CREATE TRIGGER trg_item_au AFTER UPDATE ON reimbursement_items FOR EACH ROW
BEGIN
  UPDATE reimbursements
  SET total_amount = (SELECT COALESCE(SUM(amount),0) FROM reimbursement_items WHERE reimbursement_id = NEW.reimbursement_id)
  WHERE id = NEW.reimbursement_id;
END$$

CREATE TRIGGER trg_item_ad AFTER DELETE ON reimbursement_items FOR EACH ROW
BEGIN
  UPDATE reimbursements
  SET total_amount = (SELECT COALESCE(SUM(amount),0) FROM reimbursement_items WHERE reimbursement_id = OLD.reimbursement_id)
  WHERE id = OLD.reimbursement_id;
END$$

-- Catat otomatis perubahan status
CREATE TRIGGER trg_reimb_status_au AFTER UPDATE ON reimbursements FOR EACH ROW
BEGIN
  IF OLD.status <> NEW.status THEN
    INSERT INTO status_history (reimbursement_id, old_status, new_status)
    VALUES (NEW.id, OLD.status, NEW.status);
  END IF;
END$$

DELIMITER ;

-- =====================================================
-- VIEWS UNTUK LAPORAN OTOMATIS (Manajemen)
-- =====================================================
CREATE OR REPLACE VIEW v_laporan_reimbursement AS
SELECT r.reimbursement_no, u.name AS pegawai, d.name AS departemen,
       t.destination AS tujuan, r.submitted_at AS tanggal_pengajuan,
       r.total_amount AS jumlah, r.status, r.paid_at AS tanggal_bayar,
       DATEDIFF(COALESCE(r.paid_at, NOW()), r.submitted_at) AS lama_proses_hari
FROM reimbursements r
JOIN users u ON u.id = r.user_id
LEFT JOIN departments d ON d.id = u.department_id
LEFT JOIN business_trips t ON t.id = r.trip_id;

CREATE OR REPLACE VIEW v_rekap_bulanan AS
SELECT DATE_FORMAT(submitted_at,'%Y-%m') AS bulan,
       COUNT(*) AS jumlah_pengajuan,
       SUM(total_amount) AS total_pengajuan,
       SUM(CASE WHEN status='dibayar' THEN total_amount ELSE 0 END) AS total_dibayar,
       AVG(DATEDIFF(COALESCE(paid_at, NOW()), submitted_at)) AS rata_rata_hari_proses
FROM reimbursements
WHERE submitted_at IS NOT NULL
GROUP BY DATE_FORMAT(submitted_at,'%Y-%m');

CREATE OR REPLACE VIEW v_rekap_kategori AS
SELECT c.name AS kategori, COUNT(i.id) AS jumlah_item, SUM(i.amount) AS total
FROM reimbursement_items i
JOIN expense_categories c ON c.id = i.category_id
GROUP BY c.name;

-- =====================================================
-- DATA AWAL (SEED) - berdasarkan cerita pada gambar
-- Password contoh: ganti hash dengan password_hash() PHP / bcrypt
-- =====================================================
INSERT INTO departments (name) VALUES ('Operasional'),('Keuangan'),('Manajemen');

INSERT INTO users (employee_no,name,email,password_hash,role,department_id,supervisor_id) VALUES
('EMP001','Admin Sistem','admin@perusahaan.com','GANTI_HASH','admin',1,NULL),
('EMP002','Direktur','manajemen@perusahaan.com','GANTI_HASH','manajemen',3,NULL),
('EMP003','Bu Atasan','atasan@perusahaan.com','GANTI_HASH','atasan',1,2),
('EMP004','Bu Sari','finance@perusahaan.com','GANTI_HASH','finance',2,2),
('EMP005','Pak Kasir','kasir@perusahaan.com','GANTI_HASH','kasir',2,2),
('EMP006','Rudi','rudi@perusahaan.com','GANTI_HASH','pegawai',1,3),
('EMP007','Siti','siti@perusahaan.com','GANTI_HASH','pegawai',1,3),
('EMP008','Budi','budi@perusahaan.com','GANTI_HASH','pegawai',1,3);

INSERT INTO expense_categories (name) VALUES
('Tiket'),('Hotel'),('Makan'),('Transportasi'),('Lain-lain');

INSERT INTO business_trips (user_id,destination,purpose,start_date,end_date)
VALUES (6,'Yogyakarta','Perjalanan dinas 3 hari','2026-09-23','2026-09-25');

INSERT INTO reimbursements (reimbursement_no,user_id,trip_id,status,submitted_at)
VALUES ('RMB-2026-000001',6,1,'review_finance','2026-09-28 09:00:00');

INSERT INTO reimbursement_items (reimbursement_id,category_id,expense_date,merchant,description,amount) VALUES
(1,3,'2026-09-25','Indomaret Jl. Malioboro No. 12','Air mineral, roti, snack',45500.00);

INSERT INTO receipts (item_id,file_path,file_type,ocr_status,ocr_merchant,ocr_date,ocr_total,ocr_confidence)
VALUES (1,'uploads/receipts/rmb1_item1.jpg','image/jpeg','buram','Indomaret','2026-09-25',45500.00,62.50);

INSERT INTO approvals (reimbursement_id,level,approver_id,decision,comment,acted_at)
VALUES (1,'atasan',3,'disetujui','OK','2026-09-29 10:00:00'),
       (1,'finance',4,'revisi','Struk buram, mohon foto ulang',NULL);

INSERT INTO revision_requests (reimbursement_id,item_id,requested_by,message,status)
VALUES (1,1,4,'Nominal pada struk makan tidak terlihat jelas. Mohon upload foto yang lebih jelas.','terbuka');

-- =====================================================
-- MIGRASI — tambahkan kolom yang dibutuhkan modul kasir
-- Jalankan sekali jika database sudah ada sebelumnya.
-- =====================================================

-- Kolom proof_path pada tabel payments (bukti pembayaran opsional, Req 6.4)
ALTER TABLE payments
  ADD COLUMN IF NOT EXISTS proof_path VARCHAR(255) NULL AFTER paid_at;

-- Kolom verification_status pada reimbursement_items (digunakan finance & helper canFinanceApprove)
ALTER TABLE reimbursement_items
  ADD COLUMN IF NOT EXISTS verification_status ENUM('belum','diverifikasi','ditolak') NOT NULL DEFAULT 'belum' AFTER approved_amount,
  ADD COLUMN IF NOT EXISTS verified_by INT NULL AFTER verification_status,
  ADD COLUMN IF NOT EXISTS verified_at DATETIME NULL AFTER verified_by;
