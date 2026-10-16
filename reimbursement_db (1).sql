-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Oct 16, 2026 at 11:21 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `reimbursement_db`
--
CREATE DATABASE IF NOT EXISTS `reimbursement_db` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `reimbursement_db`;

-- --------------------------------------------------------

--
-- Table structure for table `approvals`
--

DROP TABLE IF EXISTS `approvals`;
CREATE TABLE `approvals` (
  `id` int(11) NOT NULL,
  `reimbursement_id` int(11) NOT NULL,
  `level` enum('atasan','finance') NOT NULL,
  `approver_id` int(11) NOT NULL,
  `decision` enum('menunggu','disetujui','ditolak','revisi') NOT NULL DEFAULT 'menunggu',
  `comment` text DEFAULT NULL,
  `digital_signature` varchar(255) DEFAULT NULL,
  `acted_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `approvals`
--

INSERT INTO `approvals` (`id`, `reimbursement_id`, `level`, `approver_id`, `decision`, `comment`, `digital_signature`, `acted_at`, `created_at`) VALUES
(1, 1, 'atasan', 3, 'disetujui', 'OK', NULL, '2026-09-29 10:00:00', '2026-10-14 03:25:30'),
(2, 1, 'finance', 4, 'revisi', 'Struk buram, mohon foto ulang', NULL, NULL, '2026-10-14 03:25:30'),
(3, 5, 'atasan', 3, 'disetujui', NULL, 'uploads/signatures/paraf_5_3_20261016105843.png', '2026-10-16 15:58:43', '2026-10-16 08:58:43'),
(4, 1, 'finance', 4, 'disetujui', NULL, NULL, '2026-10-16 16:00:49', '2026-10-16 09:00:49');

-- --------------------------------------------------------

--
-- Table structure for table `audit_logs`
--

DROP TABLE IF EXISTS `audit_logs`;
CREATE TABLE `audit_logs` (
  `id` bigint(20) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(60) NOT NULL,
  `table_name` varchar(60) DEFAULT NULL,
  `record_id` int(11) DEFAULT NULL,
  `detail` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `audit_logs`
--

INSERT INTO `audit_logs` (`id`, `user_id`, `action`, `table_name`, `record_id`, `detail`, `ip_address`, `created_at`) VALUES
(1, 6, 'LOGIN', 'users', 6, '{\"ip\":\"::1\"}', '::1', '2026-10-16 03:22:54'),
(2, 6, 'UPDATE_STATUS', 'reimbursements', 2, '{\"before\":{\"status\":\"draft\"},\"after\":{\"status\":\"diajukan\"},\"note\":\"Test submit\"}', '', '2026-10-16 04:21:22'),
(3, 6, 'UPDATE_STATUS', 'reimbursements', 2, '{\"before\":{\"status\":\"perlu_revisi\"},\"after\":{\"status\":\"diajukan\"},\"note\":\"Pegawai menjawab revisi test\"}', '', '2026-10-16 04:21:22'),
(4, 6, 'UPDATE_STATUS', 'reimbursements', 3, '{\"before\":{\"status\":\"draft\"},\"after\":{\"status\":\"diajukan\"},\"note\":\"Pengajuan dikirim oleh pegawai.\"}', '', '2026-10-16 04:31:26'),
(5, 6, 'UPDATE_STATUS', 'reimbursements', 3, '{\"before\":{\"status\":\"perlu_revisi\"},\"after\":{\"status\":\"diajukan\"},\"note\":\"Jawaban revisi atasan\"}', '', '2026-10-16 04:31:26'),
(6, 6, 'LOGIN', 'users', 6, '{\"ip\":\"::1\"}', '::1', '2026-10-16 06:43:16'),
(7, 6, 'LOGOUT', 'users', 6, '{\"context\":{\"email\":\"rudi@perusahaan.com\",\"role\":\"pegawai\"}}', '::1', '2026-10-16 06:46:59'),
(8, 3, 'LOGIN', 'users', 3, '{\"ip\":\"::1\"}', '::1', '2026-10-16 06:47:30'),
(9, 6, 'LOGIN', 'users', 6, '{\"ip\":\"::1\"}', '::1', '2026-10-16 06:49:03'),
(10, 6, 'CREATE_REIMBURSEMENT', 'reimbursements', 5, '{\"after\":{\"reimbursement_no\":\"RMB-2026-000002\",\"trip_id\":4,\"destination\":\"Semarang\",\"purpose\":\"Rapat Koordinasi\",\"start_date\":\"2026-10-16\",\"end_date\":\"2026-10-17\",\"status\":\"draft\"}}', '::1', '2026-10-16 06:54:22'),
(11, 6, 'ADD_ITEM', 'reimbursement_items', 6, '{\"reimbursement_id\":5,\"category_id\":1,\"expense_date\":\"2026-10-16\",\"merchant\":\"KAI\",\"description\":\"Tiket kereta\",\"amount\":100000}', '::1', '2026-10-16 06:55:47'),
(12, 6, 'DELETE_ITEM', 'reimbursement_items', 6, '{\"reimbursement_id\":5}', '::1', '2026-10-16 06:57:46'),
(13, 6, 'ADD_ITEM', 'reimbursement_items', 7, '{\"reimbursement_id\":5,\"category_id\":1,\"expense_date\":\"2026-10-16\",\"merchant\":\"KAI\",\"description\":\"Tiket kereta\",\"amount\":100000}', '::1', '2026-10-16 06:58:11'),
(14, 6, 'DELETE_ITEM', 'reimbursement_items', 7, '{\"reimbursement_id\":5}', '::1', '2026-10-16 06:58:43'),
(15, 6, 'LOGIN', 'users', 6, '{\"ip\":\"::1\"}', '::1', '2026-10-16 07:10:17'),
(16, 6, 'ADD_ITEM', 'reimbursement_items', 8, '{\"reimbursement_id\":5,\"category_id\":1,\"expense_date\":\"2026-10-16\",\"merchant\":\"kai\",\"description\":\"Tiket\",\"amount\":50000}', '::1', '2026-10-16 07:10:52'),
(17, 6, 'LOGIN', 'users', 6, '{\"ip\":\"::1\"}', '::1', '2026-10-16 08:23:24'),
(18, 6, 'LOGOUT', 'users', 6, '{\"context\":{\"email\":\"rudi@perusahaan.com\",\"role\":\"pegawai\"}}', '::1', '2026-10-16 08:24:11'),
(19, 6, 'LOGIN', 'users', 6, '{\"ip\":\"::1\"}', '::1', '2026-10-16 08:24:50'),
(20, 6, 'UPLOAD_RECEIPT', 'reimbursement_items', 8, '{\"context\":{\"item_id\":8,\"reimbursement_id\":5,\"file_path\":\"uploads/receipts/receipt_6ad1e301bac678.07141469.png\",\"mime_type\":\"image/png\",\"file_size_bytes\":385694}}', '::1', '2026-10-16 08:40:33'),
(21, 6, 'UPLOAD_RECEIPT', 'reimbursement_items', 8, '{\"context\":{\"item_id\":8,\"reimbursement_id\":5,\"file_path\":\"uploads/receipts/receipt_6ad1e4a23cb502.96675578.png\",\"mime_type\":\"image/png\",\"file_size_bytes\":385694}}', '::1', '2026-10-16 08:47:30'),
(22, 6, 'UPLOAD_RECEIPT', 'reimbursement_items', 8, '{\"context\":{\"item_id\":8,\"reimbursement_id\":5,\"file_path\":\"uploads/receipts/receipt_6ad1e595389409.79622958.png\",\"mime_type\":\"image/png\",\"file_size_bytes\":385694}}', '::1', '2026-10-16 08:51:33'),
(23, 6, 'UPLOAD_RECEIPT', 'reimbursement_items', 8, '{\"context\":{\"item_id\":8,\"reimbursement_id\":5,\"file_path\":\"uploads/receipts/receipt_6ad1e5fdcd27d8.04771952.png\",\"mime_type\":\"image/png\",\"file_size_bytes\":15108}}', '::1', '2026-10-16 08:53:17'),
(24, 6, 'UPLOAD_RECEIPT', 'reimbursement_items', 8, '{\"context\":{\"item_id\":8,\"reimbursement_id\":5,\"file_path\":\"uploads/receipts/receipt_6ad1e6057b3003.92776559.png\",\"mime_type\":\"image/png\",\"file_size_bytes\":659242}}', '::1', '2026-10-16 08:53:25'),
(25, 6, 'UPLOAD_RECEIPT', 'reimbursement_items', 8, '{\"context\":{\"item_id\":8,\"reimbursement_id\":5,\"file_path\":\"uploads/receipts/receipt_6ad1e63088b181.74965111.png\",\"mime_type\":\"image/png\",\"file_size_bytes\":155190}}', '::1', '2026-10-16 08:54:08'),
(26, 6, 'UPLOAD_RECEIPT', 'reimbursement_items', 8, '{\"context\":{\"item_id\":8,\"reimbursement_id\":5,\"file_path\":\"uploads/receipts/receipt_6ad1e63f2875d3.90893776.jpg\",\"mime_type\":\"image/jpeg\",\"file_size_bytes\":49582}}', '::1', '2026-10-16 08:54:23'),
(27, 6, 'UPDATE_STATUS', 'reimbursements', 5, '{\"before\":{\"status\":\"draft\"},\"after\":{\"status\":\"diajukan\"},\"note\":\"Pengajuan dikirim oleh pegawai.\"}', '::1', '2026-10-16 08:56:07'),
(28, 6, 'LOGOUT', 'users', 6, '{\"context\":{\"email\":\"rudi@perusahaan.com\",\"role\":\"pegawai\"}}', '::1', '2026-10-16 08:56:13'),
(29, 3, 'LOGIN', 'users', 3, '{\"ip\":\"::1\"}', '::1', '2026-10-16 08:57:47'),
(30, 3, 'UPDATE_STATUS', 'reimbursements', 5, '{\"before\":{\"status\":\"diajukan\"},\"after\":{\"status\":\"review_atasan\"},\"note\":\"Pengajuan mulai direview oleh atasan.\"}', '::1', '2026-10-16 08:57:54'),
(31, 3, 'UPDATE_STATUS', 'reimbursements', 5, '{\"before\":{\"status\":\"review_atasan\"},\"after\":{\"status\":\"review_finance\"},\"note\":\"Disetujui oleh atasan. Pengajuan diteruskan ke Finance.\"}', '::1', '2026-10-16 08:58:43'),
(32, 3, 'SAVE_APPROVAL', 'approvals', 3, '{\"reimbursement_id\":5,\"level\":\"atasan\",\"decision\":\"disetujui\",\"digital_signature\":\"uploads/signatures/paraf_5_3_20261016105843.png\"}', '::1', '2026-10-16 08:58:43'),
(33, 3, 'LOGOUT', 'users', 3, '{\"context\":{\"email\":\"atasan@perusahaan.com\",\"role\":\"atasan\"}}', '::1', '2026-10-16 08:59:29'),
(34, 4, 'LOGIN', 'users', 4, '{\"ip\":\"::1\"}', '::1', '2026-10-16 08:59:51'),
(35, 4, 'VERIFY_ITEM', 'reimbursement_items', 1, '{\"before\":{\"verification_status\":\"belum\",\"approved_amount\":null,\"verified_by\":null,\"verified_at\":null},\"after\":{\"verification_status\":\"diverifikasi\",\"approved_amount\":45.5,\"verified_by\":4},\"context\":{\"reimbursement_id\":1}}', '::1', '2026-10-16 09:00:44'),
(36, 4, 'UPDATE_STATUS', 'reimbursements', 1, '{\"before\":{\"status\":\"review_finance\"},\"after\":{\"status\":\"disetujui\"},\"note\":\"Disetujui oleh finance.\"}', '::1', '2026-10-16 09:00:49'),
(37, 4, 'SAVE_APPROVAL', 'approvals', 4, '{\"reimbursement_id\":1,\"level\":\"finance\",\"decision\":\"disetujui\",\"comment\":\"\"}', '::1', '2026-10-16 09:00:49'),
(38, 4, 'LOGOUT', 'users', 4, '{\"context\":{\"email\":\"finance@perusahaan.com\",\"role\":\"finance\"}}', '::1', '2026-10-16 09:00:56'),
(39, 5, 'LOGIN', 'users', 5, '{\"ip\":\"::1\"}', '::1', '2026-10-16 09:01:13'),
(40, 5, 'UPDATE_STATUS', 'reimbursements', 1, '{\"before\":{\"status\":\"disetujui\"},\"after\":{\"status\":\"dibayar\"},\"note\":\"Pembayaran dicatat oleh kasir. Metode: transfer\"}', '::1', '2026-10-16 09:01:56'),
(41, 5, 'SAVE_PAYMENT', 'payments', 1, '{\"reimbursement_id\":1,\"cashier_id\":5,\"cashier_name\":\"Pak Kasir\",\"amount\":45500,\"method\":\"transfer\",\"reference_no\":null,\"paid_at\":\"2026-10-16T11:01\",\"proof_path\":null}', '::1', '2026-10-16 09:01:56'),
(42, 5, 'LOGOUT', 'users', 5, '{\"context\":{\"email\":\"kasir@perusahaan.com\",\"role\":\"kasir\"}}', '::1', '2026-10-16 09:02:00'),
(43, 2, 'LOGIN', 'users', 2, '{\"ip\":\"::1\"}', '::1', '2026-10-16 09:02:15'),
(44, 2, 'LOGOUT', 'users', 2, '{\"context\":{\"email\":\"manajemen@perusahaan.com\",\"role\":\"manajemen\"}}', '::1', '2026-10-16 09:12:56'),
(45, 6, 'LOGIN', 'users', 6, '{\"ip\":\"::1\"}', '::1', '2026-10-16 09:13:42');

-- --------------------------------------------------------

--
-- Table structure for table `business_trips`
--

DROP TABLE IF EXISTS `business_trips`;
CREATE TABLE `business_trips` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `destination` varchar(100) NOT NULL,
  `purpose` varchar(255) DEFAULT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `business_trips`
--

INSERT INTO `business_trips` (`id`, `user_id`, `destination`, `purpose`, `start_date`, `end_date`, `created_at`) VALUES
(1, 6, 'Yogyakarta', 'Perjalanan dinas 3 hari', '2026-09-23', '2026-09-25', '2026-10-14 03:25:30'),
(4, 6, 'Semarang', 'Rapat Koordinasi', '2026-10-16', '2026-10-17', '2026-10-16 06:54:22');

-- --------------------------------------------------------

--
-- Table structure for table `departments`
--

DROP TABLE IF EXISTS `departments`;
CREATE TABLE `departments` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `departments`
--

INSERT INTO `departments` (`id`, `name`, `created_at`) VALUES
(1, 'Operasional', '2026-10-14 03:25:30'),
(2, 'Keuangan', '2026-10-14 03:25:30'),
(3, 'Manajemen', '2026-10-14 03:25:30');

-- --------------------------------------------------------

--
-- Table structure for table `expense_categories`
--

DROP TABLE IF EXISTS `expense_categories`;
CREATE TABLE `expense_categories` (
  `id` int(11) NOT NULL,
  `name` varchar(60) NOT NULL,
  `max_amount` decimal(15,2) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `expense_categories`
--

INSERT INTO `expense_categories` (`id`, `name`, `max_amount`, `is_active`) VALUES
(1, 'Tiket', NULL, 1),
(2, 'Hotel', NULL, 1),
(3, 'Makan', NULL, 1),
(4, 'Transportasi', NULL, 1),
(5, 'Lain-lain', NULL, 1);

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

DROP TABLE IF EXISTS `notifications`;
CREATE TABLE `notifications` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `reimbursement_id` int(11) DEFAULT NULL,
  `title` varchar(120) NOT NULL,
  `message` varchar(255) DEFAULT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `notifications`
--

INSERT INTO `notifications` (`id`, `user_id`, `reimbursement_id`, `title`, `message`, `is_read`, `created_at`) VALUES
(6, 3, 5, 'Pengajuan Baru Menunggu Review', 'Terdapat pengajuan reimbursement baru yang membutuhkan review Anda.', 0, '2026-10-16 08:56:07'),
(7, 3, 5, 'Pengajuan Reimbursement Baru', 'Pegawai Rudi telah mengajukan reimbursement RMB-2026-000002 dan membutuhkan review Anda.', 0, '2026-10-16 08:56:07'),
(8, 4, 5, 'Pengajuan Siap Diverifikasi Finance', 'Terdapat pengajuan reimbursement yang perlu diverifikasi oleh tim finance.', 0, '2026-10-16 08:58:43'),
(9, 4, 5, 'Pengajuan Disetujui Atasan — Perlu Verifikasi Finance', 'Pengajuan reimbursement RMB-2026-000002 telah disetujui atasan dan menunggu verifikasi Finance.', 0, '2026-10-16 08:58:43'),
(10, 5, 1, 'Pengajuan Disetujui — Siap Dibayar', 'Terdapat pengajuan reimbursement yang telah disetujui dan siap untuk pembayaran.', 0, '2026-10-16 09:00:49'),
(11, 5, 1, 'Pengajuan Disetujui Finance — Siap Dibayar', 'Pengajuan reimbursement RMB-2026-000001 telah disetujui finance dan siap untuk pembayaran.', 0, '2026-10-16 09:00:49'),
(12, 6, 1, 'Reimbursement Telah Dibayar', 'Pembayaran reimbursement Anda telah diproses.', 0, '2026-10-16 09:01:56'),
(13, 6, 1, 'Reimbursement Telah Dibayar', 'Pembayaran reimbursement RMB-2026-000001 sebesar Rp 45.500 telah diproses oleh kasir via transfer.', 0, '2026-10-16 09:01:56');

-- --------------------------------------------------------

--
-- Table structure for table `payments`
--

DROP TABLE IF EXISTS `payments`;
CREATE TABLE `payments` (
  `id` int(11) NOT NULL,
  `reimbursement_id` int(11) NOT NULL,
  `cashier_id` int(11) NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `method` enum('transfer','tunai') NOT NULL DEFAULT 'transfer',
  `bank_name` varchar(60) DEFAULT NULL,
  `account_no` varchar(40) DEFAULT NULL,
  `reference_no` varchar(60) DEFAULT NULL,
  `paid_at` datetime NOT NULL,
  `proof_path` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `payments`
--

INSERT INTO `payments` (`id`, `reimbursement_id`, `cashier_id`, `amount`, `method`, `bank_name`, `account_no`, `reference_no`, `paid_at`, `proof_path`) VALUES
(1, 1, 5, 45500.00, 'transfer', NULL, NULL, NULL, '2026-10-16 11:01:00', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `receipts`
--

DROP TABLE IF EXISTS `receipts`;
CREATE TABLE `receipts` (
  `id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `file_type` varchar(20) DEFAULT NULL,
  `ocr_status` enum('pending','sukses','gagal','buram') DEFAULT 'pending',
  `ocr_raw_text` mediumtext DEFAULT NULL,
  `ocr_merchant` varchar(150) DEFAULT NULL,
  `ocr_date` date DEFAULT NULL,
  `ocr_total` decimal(15,2) DEFAULT NULL,
  `ocr_confidence` decimal(5,2) DEFAULT NULL,
  `is_verified` tinyint(1) DEFAULT 0,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `receipts`
--

INSERT INTO `receipts` (`id`, `item_id`, `file_path`, `file_type`, `ocr_status`, `ocr_raw_text`, `ocr_merchant`, `ocr_date`, `ocr_total`, `ocr_confidence`, `is_verified`, `uploaded_at`) VALUES
(1, 1, 'uploads/receipts/rmb1_item1.jpg', 'image/jpeg', 'buram', NULL, 'Indomaret', '2026-09-25', 45500.00, 62.50, 0, '2026-10-14 03:25:30'),
(2, 8, 'uploads/receipts/receipt_6ad1e301bac678.07141469.png', NULL, 'buram', NULL, NULL, NULL, NULL, 0.00, 0, '2026-10-16 08:40:36'),
(3, 8, 'uploads/receipts/receipt_6ad1e4a23cb502.96675578.png', NULL, 'buram', NULL, NULL, NULL, NULL, 0.00, 0, '2026-10-16 08:47:31'),
(4, 8, 'uploads/receipts/receipt_6ad1e595389409.79622958.png', NULL, 'buram', 'la H BASU GEDE 2—\nnomor identitas / 4 humber z\n5 % ~ S r g a\nD i ALUMNLUGM EXO SE\nBN s tan ot s s ttt Er\nSIL — SENGAWAN/ 282 oy R ]\nS0 gt depaiee sinsaan /o g\nO | CIKARANG(CKR) PURWOSARI PWS)\n£ i 02 Sep 202509:45 WB 02 Sep 2025 18:05 WiB a', 'la H BASU GEDE 2—', NULL, NULL, 41.00, 0, '2026-10-16 08:51:36'),
(5, 8, 'uploads/receipts/receipt_6ad1e5fdcd27d8.04771952.png', NULL, 'buram', NULL, NULL, NULL, NULL, 0.00, 0, '2026-10-16 08:53:18'),
(6, 8, 'uploads/receipts/receipt_6ad1e6057b3003.92776559.png', NULL, 'buram', 'KARYA SELAMA PKL 01 DISKOMINFO KOTA SEMARANG\n(GAME EDUKASI SGS \"SEMARANG GAME SERIES\"\nv \\ ”\\ / / : “\ne \\ if\n“1 = MAonlai 0 -\'A\"._A_-“.\'.\nv PEA\nFA \' { g P\nY b\ne l S\n. — Ee DDII\n@l . 5 R |\ni 1l =S a| |- Da an\nMe /l o |emm= v,\nr . SE— B\nW3J a) (emar) =i\nNE AE u\n\\ 7 \\ &\'I A | o u gy, 0\n& OB\nse lu B\n\\\\ ) N\\ \'.:.:?‘f?:‘:\" o\n| N JA I J', 'KARYA SELAMA PKL 01 DISKOMINFO KOTA SEMARANG', NULL, NULL, 41.00, 0, '2026-10-16 08:53:32'),
(7, 8, 'uploads/receipts/receipt_6ad1e63088b181.74965111.png', NULL, 'buram', 'ZN\n\\00\nv V', 'ZN', NULL, NULL, 62.00, 0, '2026-10-16 08:54:10'),
(8, 8, 'uploads/receipts/receipt_6ad1e63f2875d3.90893776.jpg', NULL, 'buram', 'ENA 4\n7%\n< )\nz >\no o\n4 V\n%’4 NG \'1\'1&“0+', 'ENA 4', NULL, NULL, 56.00, 0, '2026-10-16 08:54:24');

-- --------------------------------------------------------

--
-- Table structure for table `reimbursements`
--

DROP TABLE IF EXISTS `reimbursements`;
CREATE TABLE `reimbursements` (
  `id` int(11) NOT NULL,
  `reimbursement_no` varchar(30) NOT NULL,
  `user_id` int(11) NOT NULL,
  `trip_id` int(11) DEFAULT NULL,
  `total_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `status` enum('draft','diajukan','review_atasan','review_finance','perlu_revisi','disetujui','ditolak','dibayar') NOT NULL DEFAULT 'draft',
  `notes` text DEFAULT NULL,
  `submitted_at` datetime DEFAULT NULL,
  `paid_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `reimbursements`
--

INSERT INTO `reimbursements` (`id`, `reimbursement_no`, `user_id`, `trip_id`, `total_amount`, `status`, `notes`, `submitted_at`, `paid_at`, `created_at`, `updated_at`) VALUES
(1, 'RMB-2026-000001', 6, 1, 45500.00, 'dibayar', NULL, '2026-09-28 09:00:00', '2026-10-16 11:01:00', '2026-10-14 03:25:30', '2026-10-16 09:01:56'),
(5, 'RMB-2026-000002', 6, 4, 50000.00, 'review_finance', NULL, '2026-10-16 15:56:07', NULL, '2026-10-16 06:54:22', '2026-10-16 08:58:43');

--
-- Triggers `reimbursements`
--
DROP TRIGGER IF EXISTS `trg_reimb_status_au`;
DELIMITER $$
CREATE TRIGGER `trg_reimb_status_au` AFTER UPDATE ON `reimbursements` FOR EACH ROW BEGIN
  IF OLD.status <> NEW.status THEN
    INSERT INTO status_history (reimbursement_id, old_status, new_status, changed_by, note)
    VALUES (NEW.id, OLD.status, NEW.status, @actor_id, @status_note);
  END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `reimbursement_items`
--

DROP TABLE IF EXISTS `reimbursement_items`;
CREATE TABLE `reimbursement_items` (
  `id` int(11) NOT NULL,
  `reimbursement_id` int(11) NOT NULL,
  `category_id` int(11) NOT NULL,
  `expense_date` date NOT NULL,
  `merchant` varchar(150) DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `amount` decimal(15,2) NOT NULL,
  `approved_amount` decimal(15,2) DEFAULT NULL,
  `verification_status` enum('belum','diverifikasi','ditolak') NOT NULL DEFAULT 'belum',
  `verified_by` int(11) DEFAULT NULL,
  `verified_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `reimbursement_items`
--

INSERT INTO `reimbursement_items` (`id`, `reimbursement_id`, `category_id`, `expense_date`, `merchant`, `description`, `amount`, `approved_amount`, `verification_status`, `verified_by`, `verified_at`) VALUES
(1, 1, 3, '2026-09-25', 'Indomaret Jl. Malioboro No. 12', 'Air mineral, roti, snack', 45500.00, 45.50, 'diverifikasi', 4, '2026-10-16 16:00:44'),
(8, 5, 1, '2026-10-16', 'kai', 'Tiket', 50000.00, NULL, 'belum', NULL, NULL);

--
-- Triggers `reimbursement_items`
--
DROP TRIGGER IF EXISTS `trg_item_ad`;
DELIMITER $$
CREATE TRIGGER `trg_item_ad` AFTER DELETE ON `reimbursement_items` FOR EACH ROW BEGIN
  UPDATE reimbursements
  SET total_amount = (SELECT COALESCE(SUM(amount),0) FROM reimbursement_items WHERE reimbursement_id = OLD.reimbursement_id)
  WHERE id = OLD.reimbursement_id;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trg_item_ai`;
DELIMITER $$
CREATE TRIGGER `trg_item_ai` AFTER INSERT ON `reimbursement_items` FOR EACH ROW BEGIN
  UPDATE reimbursements
  SET total_amount = (SELECT COALESCE(SUM(amount),0) FROM reimbursement_items WHERE reimbursement_id = NEW.reimbursement_id)
  WHERE id = NEW.reimbursement_id;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trg_item_au`;
DELIMITER $$
CREATE TRIGGER `trg_item_au` AFTER UPDATE ON `reimbursement_items` FOR EACH ROW BEGIN
  UPDATE reimbursements
  SET total_amount = (SELECT COALESCE(SUM(amount),0) FROM reimbursement_items WHERE reimbursement_id = NEW.reimbursement_id)
  WHERE id = NEW.reimbursement_id;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `revision_requests`
--

DROP TABLE IF EXISTS `revision_requests`;
CREATE TABLE `revision_requests` (
  `id` int(11) NOT NULL,
  `reimbursement_id` int(11) NOT NULL,
  `item_id` int(11) DEFAULT NULL,
  `requested_by` int(11) NOT NULL,
  `message` text NOT NULL,
  `response` text DEFAULT NULL,
  `status` enum('terbuka','dijawab','selesai') DEFAULT 'terbuka',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `responded_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `revision_requests`
--

INSERT INTO `revision_requests` (`id`, `reimbursement_id`, `item_id`, `requested_by`, `message`, `response`, `status`, `created_at`, `responded_at`) VALUES
(1, 1, 1, 4, 'Nominal pada struk makan tidak terlihat jelas. Mohon upload foto yang lebih jelas.', NULL, 'terbuka', '2026-10-14 03:25:30', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `status_history`
--

DROP TABLE IF EXISTS `status_history`;
CREATE TABLE `status_history` (
  `id` int(11) NOT NULL,
  `reimbursement_id` int(11) NOT NULL,
  `old_status` varchar(30) DEFAULT NULL,
  `new_status` varchar(30) NOT NULL,
  `changed_by` int(11) DEFAULT NULL,
  `note` varchar(255) DEFAULT NULL,
  `changed_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `status_history`
--

INSERT INTO `status_history` (`id`, `reimbursement_id`, `old_status`, `new_status`, `changed_by`, `note`, `changed_at`) VALUES
(9, 5, 'draft', 'diajukan', 6, 'Pengajuan dikirim oleh pegawai.', '2026-10-16 08:56:07'),
(10, 5, 'diajukan', 'review_atasan', 3, 'Pengajuan mulai direview oleh atasan.', '2026-10-16 08:57:54'),
(11, 5, 'review_atasan', 'review_finance', 3, 'Disetujui oleh atasan. Pengajuan diteruskan ke Finance.', '2026-10-16 08:58:43'),
(12, 1, 'review_finance', 'disetujui', 4, 'Disetujui oleh finance.', '2026-10-16 09:00:49'),
(13, 1, 'disetujui', 'dibayar', 5, 'Pembayaran dicatat oleh kasir. Metode: transfer', '2026-10-16 09:01:56');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `employee_no` varchar(30) NOT NULL,
  `name` varchar(100) NOT NULL,
  `email` varchar(120) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('pegawai','atasan','finance','kasir','manajemen','admin') NOT NULL DEFAULT 'pegawai',
  `department_id` int(11) DEFAULT NULL,
  `supervisor_id` int(11) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `employee_no`, `name`, `email`, `phone`, `password_hash`, `role`, `department_id`, `supervisor_id`, `is_active`, `created_at`) VALUES
(1, 'EMP001', 'Admin Sistem', 'admin@perusahaan.com', NULL, '$2y$10$aX6GDQ0iQPGJmQLaxVNz2OOu98gx8b2Y8iXxNi/5n7eR6cUTra5gW', 'admin', 1, NULL, 1, '2026-10-14 03:25:30'),
(2, 'EMP002', 'Direktur', 'manajemen@perusahaan.com', NULL, '$2y$10$aX6GDQ0iQPGJmQLaxVNz2OOu98gx8b2Y8iXxNi/5n7eR6cUTra5gW', 'manajemen', 3, NULL, 1, '2026-10-14 03:25:30'),
(3, 'EMP003', 'Bu Atasan', 'atasan@perusahaan.com', NULL, '$2y$10$aX6GDQ0iQPGJmQLaxVNz2OOu98gx8b2Y8iXxNi/5n7eR6cUTra5gW', 'atasan', 1, 2, 1, '2026-10-14 03:25:30'),
(4, 'EMP004', 'Bu Sari', 'finance@perusahaan.com', NULL, '$2y$10$aX6GDQ0iQPGJmQLaxVNz2OOu98gx8b2Y8iXxNi/5n7eR6cUTra5gW', 'finance', 2, 2, 1, '2026-10-14 03:25:30'),
(5, 'EMP005', 'Pak Kasir', 'kasir@perusahaan.com', NULL, '$2y$10$aX6GDQ0iQPGJmQLaxVNz2OOu98gx8b2Y8iXxNi/5n7eR6cUTra5gW', 'kasir', 2, 2, 1, '2026-10-14 03:25:30'),
(6, 'EMP006', 'Rudi', 'rudi@perusahaan.com', NULL, '$2y$10$aX6GDQ0iQPGJmQLaxVNz2OOu98gx8b2Y8iXxNi/5n7eR6cUTra5gW', 'pegawai', 1, 3, 1, '2026-10-14 03:25:30'),
(7, 'EMP007', 'Siti', 'siti@perusahaan.com', NULL, '$2y$10$aX6GDQ0iQPGJmQLaxVNz2OOu98gx8b2Y8iXxNi/5n7eR6cUTra5gW', 'pegawai', 1, 3, 1, '2026-10-14 03:25:30'),
(8, 'EMP008', 'Budi', 'budi@perusahaan.com', NULL, '$2y$10$aX6GDQ0iQPGJmQLaxVNz2OOu98gx8b2Y8iXxNi/5n7eR6cUTra5gW', 'pegawai', 1, 3, 1, '2026-10-14 03:25:30');

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_laporan_reimbursement`
-- (See below for the actual view)
--
DROP VIEW IF EXISTS `v_laporan_reimbursement`;
CREATE TABLE `v_laporan_reimbursement` (
`reimbursement_no` varchar(30)
,`pegawai` varchar(100)
,`departemen` varchar(100)
,`tujuan` varchar(100)
,`tanggal_pengajuan` datetime
,`jumlah` decimal(15,2)
,`status` enum('draft','diajukan','review_atasan','review_finance','perlu_revisi','disetujui','ditolak','dibayar')
,`tanggal_bayar` datetime
,`lama_proses_hari` int(7)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_rekap_bulanan`
-- (See below for the actual view)
--
DROP VIEW IF EXISTS `v_rekap_bulanan`;
CREATE TABLE `v_rekap_bulanan` (
`bulan` varchar(7)
,`jumlah_pengajuan` bigint(21)
,`total_pengajuan` decimal(37,2)
,`total_dibayar` decimal(37,2)
,`rata_rata_hari_proses` decimal(10,4)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_rekap_kategori`
-- (See below for the actual view)
--
DROP VIEW IF EXISTS `v_rekap_kategori`;
CREATE TABLE `v_rekap_kategori` (
`kategori` varchar(60)
,`jumlah_item` bigint(21)
,`total` decimal(37,2)
);

-- --------------------------------------------------------

--
-- Structure for view `v_laporan_reimbursement`
--
DROP TABLE IF EXISTS `v_laporan_reimbursement`;

DROP VIEW IF EXISTS `v_laporan_reimbursement`;
CREATE OR REPLACE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v_laporan_reimbursement`  AS SELECT `r`.`reimbursement_no` AS `reimbursement_no`, `u`.`name` AS `pegawai`, `d`.`name` AS `departemen`, `t`.`destination` AS `tujuan`, `r`.`submitted_at` AS `tanggal_pengajuan`, `r`.`total_amount` AS `jumlah`, `r`.`status` AS `status`, `r`.`paid_at` AS `tanggal_bayar`, to_days(coalesce(`r`.`paid_at`,current_timestamp())) - to_days(`r`.`submitted_at`) AS `lama_proses_hari` FROM (((`reimbursements` `r` join `users` `u` on(`u`.`id` = `r`.`user_id`)) left join `departments` `d` on(`d`.`id` = `u`.`department_id`)) left join `business_trips` `t` on(`t`.`id` = `r`.`trip_id`)) ;

-- --------------------------------------------------------

--
-- Structure for view `v_rekap_bulanan`
--
DROP TABLE IF EXISTS `v_rekap_bulanan`;

DROP VIEW IF EXISTS `v_rekap_bulanan`;
CREATE OR REPLACE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v_rekap_bulanan`  AS SELECT date_format(`reimbursements`.`submitted_at`,'%Y-%m') AS `bulan`, count(0) AS `jumlah_pengajuan`, sum(`reimbursements`.`total_amount`) AS `total_pengajuan`, sum(case when `reimbursements`.`status` = 'dibayar' then `reimbursements`.`total_amount` else 0 end) AS `total_dibayar`, avg(to_days(coalesce(`reimbursements`.`paid_at`,current_timestamp())) - to_days(`reimbursements`.`submitted_at`)) AS `rata_rata_hari_proses` FROM `reimbursements` WHERE `reimbursements`.`submitted_at` is not null GROUP BY date_format(`reimbursements`.`submitted_at`,'%Y-%m') ;

-- --------------------------------------------------------

--
-- Structure for view `v_rekap_kategori`
--
DROP TABLE IF EXISTS `v_rekap_kategori`;

DROP VIEW IF EXISTS `v_rekap_kategori`;
CREATE OR REPLACE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v_rekap_kategori`  AS SELECT `c`.`name` AS `kategori`, count(`i`.`id`) AS `jumlah_item`, sum(`i`.`amount`) AS `total` FROM (`reimbursement_items` `i` join `expense_categories` `c` on(`c`.`id` = `i`.`category_id`)) GROUP BY `c`.`name` ;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `approvals`
--
ALTER TABLE `approvals`
  ADD PRIMARY KEY (`id`),
  ADD KEY `reimbursement_id` (`reimbursement_id`),
  ADD KEY `approver_id` (`approver_id`);

--
-- Indexes for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `business_trips`
--
ALTER TABLE `business_trips`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `departments`
--
ALTER TABLE `departments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`);

--
-- Indexes for table `expense_categories`
--
ALTER TABLE `expense_categories`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `reimbursement_id` (`reimbursement_id`);

--
-- Indexes for table `payments`
--
ALTER TABLE `payments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `reimbursement_id` (`reimbursement_id`),
  ADD KEY `cashier_id` (`cashier_id`);

--
-- Indexes for table `receipts`
--
ALTER TABLE `receipts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `item_id` (`item_id`);

--
-- Indexes for table `reimbursements`
--
ALTER TABLE `reimbursements`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `reimbursement_no` (`reimbursement_no`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `trip_id` (`trip_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_submitted` (`submitted_at`);

--
-- Indexes for table `reimbursement_items`
--
ALTER TABLE `reimbursement_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `reimbursement_id` (`reimbursement_id`),
  ADD KEY `category_id` (`category_id`),
  ADD KEY `verified_by` (`verified_by`);

--
-- Indexes for table `revision_requests`
--
ALTER TABLE `revision_requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `reimbursement_id` (`reimbursement_id`),
  ADD KEY `item_id` (`item_id`),
  ADD KEY `requested_by` (`requested_by`);

--
-- Indexes for table `status_history`
--
ALTER TABLE `status_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `reimbursement_id` (`reimbursement_id`),
  ADD KEY `changed_by` (`changed_by`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `employee_no` (`employee_no`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `department_id` (`department_id`),
  ADD KEY `supervisor_id` (`supervisor_id`),
  ADD KEY `idx_role` (`role`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `approvals`
--
ALTER TABLE `approvals`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `audit_logs`
--
ALTER TABLE `audit_logs`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=46;

--
-- AUTO_INCREMENT for table `business_trips`
--
ALTER TABLE `business_trips`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `departments`
--
ALTER TABLE `departments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `expense_categories`
--
ALTER TABLE `expense_categories`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `payments`
--
ALTER TABLE `payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `receipts`
--
ALTER TABLE `receipts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `reimbursements`
--
ALTER TABLE `reimbursements`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `reimbursement_items`
--
ALTER TABLE `reimbursement_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `revision_requests`
--
ALTER TABLE `revision_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `status_history`
--
ALTER TABLE `status_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `approvals`
--
ALTER TABLE `approvals`
  ADD CONSTRAINT `approvals_ibfk_1` FOREIGN KEY (`reimbursement_id`) REFERENCES `reimbursements` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `approvals_ibfk_2` FOREIGN KEY (`approver_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD CONSTRAINT `audit_logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `business_trips`
--
ALTER TABLE `business_trips`
  ADD CONSTRAINT `business_trips_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `notifications_ibfk_2` FOREIGN KEY (`reimbursement_id`) REFERENCES `reimbursements` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `payments`
--
ALTER TABLE `payments`
  ADD CONSTRAINT `payments_ibfk_1` FOREIGN KEY (`reimbursement_id`) REFERENCES `reimbursements` (`id`),
  ADD CONSTRAINT `payments_ibfk_2` FOREIGN KEY (`cashier_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `receipts`
--
ALTER TABLE `receipts`
  ADD CONSTRAINT `receipts_ibfk_1` FOREIGN KEY (`item_id`) REFERENCES `reimbursement_items` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `reimbursements`
--
ALTER TABLE `reimbursements`
  ADD CONSTRAINT `reimbursements_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `reimbursements_ibfk_2` FOREIGN KEY (`trip_id`) REFERENCES `business_trips` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `reimbursement_items`
--
ALTER TABLE `reimbursement_items`
  ADD CONSTRAINT `reimbursement_items_ibfk_1` FOREIGN KEY (`reimbursement_id`) REFERENCES `reimbursements` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `reimbursement_items_ibfk_2` FOREIGN KEY (`category_id`) REFERENCES `expense_categories` (`id`),
  ADD CONSTRAINT `reimbursement_items_ibfk_3` FOREIGN KEY (`verified_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `revision_requests`
--
ALTER TABLE `revision_requests`
  ADD CONSTRAINT `revision_requests_ibfk_1` FOREIGN KEY (`reimbursement_id`) REFERENCES `reimbursements` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `revision_requests_ibfk_2` FOREIGN KEY (`item_id`) REFERENCES `reimbursement_items` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `revision_requests_ibfk_3` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `status_history`
--
ALTER TABLE `status_history`
  ADD CONSTRAINT `status_history_ibfk_1` FOREIGN KEY (`reimbursement_id`) REFERENCES `reimbursements` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `status_history_ibfk_2` FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `users_ibfk_1` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`),
  ADD CONSTRAINT `users_ibfk_2` FOREIGN KEY (`supervisor_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
