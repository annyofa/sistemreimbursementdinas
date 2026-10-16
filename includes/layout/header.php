<?php
/**
 * includes/layout/header.php
 *
 * Bagian awal setiap halaman: DOCTYPE, <head>, import CSS, meta CSRF.
 *
 * Variabel yang diharapkan dari halaman pemanggil:
 *   $pageTitle (string) — judul halaman yang ditampilkan di <title>
 *
 * Requirements: 8.2
 */

// Pastikan sesi sudah aktif sebelum membaca session data
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Pastikan BASE_URL tersedia
if (!defined('BASE_URL')) {
    require_once __DIR__ . '/../../config/database.php';
}

// Sediakan CSRF token jika belum ada
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$pageTitle = isset($pageTitle) ? htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') : 'Reimbursement Perjalanan Dinas';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">

    <!-- CSRF Token untuk AJAX POST -->
    <meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">

    <title><?= $pageTitle ?> — Reimbursement Perjalanan Dinas</title>

    <!-- Bootstrap 5.3.3 CSS — tanpa integrity agar tidak terganggu cache/versi CDN -->
    <link
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
    >

    <!-- Bootstrap Icons 1.11.3 -->
    <link
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
    >

    <style>
        body {
            background-color: #f8f9fa;
        }
        .navbar-brand {
            font-weight: 600;
        }
        .badge-notif {
            font-size: 0.65rem;
        }
        .flash-container {
            position: fixed;
            top: 70px;
            right: 1rem;
            z-index: 1055;
            min-width: 280px;
            max-width: 400px;
        }
    </style>
</head>
<body>