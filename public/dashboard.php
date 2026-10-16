<?php

/**
 * public/dashboard.php
 *
 * Dispatcher dashboard: memastikan pengguna sudah login lalu redirect
 * ke halaman utama sesuai role masing-masing.
 *
 * Mapping role → halaman tujuan:
 *   pegawai   → /reimbursement/public/pegawai/index.php
 *   atasan    → /reimbursement/public/atasan/index.php
 *   finance   → /reimbursement/public/finance/index.php
 *   kasir     → /reimbursement/public/kasir/index.php
 *   manajemen → /reimbursement/public/manajemen/dashboard.php
 *   admin     → /reimbursement/public/admin/users.php
 *   (default) → /reimbursement/public/login.php
 *
 * @see Requirements 1.4 — Sistem mengarahkan pengguna sesuai role setelah login
 * @see Requirements 1.6 — Akses hanya untuk pengguna yang sudah terautentikasi
 */

require_once __DIR__ . '/../includes/auth.php';

// Pastikan pengguna sudah login; redirect ke login jika belum
requireAuth();

$user = getCurrentUser();
$role = $user['role'] ?? '';

// Peta role ke URL tujuan
$roleRedirectMap = [
    'pegawai'   => BASE_URL . '/pegawai/index.php',
    'atasan'    => BASE_URL . '/atasan/index.php',
    'finance'   => BASE_URL . '/finance/index.php',
    'kasir'     => BASE_URL . '/kasir/index.php',
    'manajemen' => BASE_URL . '/manajemen/dashboard.php',
    'admin'     => BASE_URL . '/admin/users.php',
];

// Tentukan URL tujuan; gunakan login.php sebagai fallback untuk role tidak dikenal
$redirectUrl = $roleRedirectMap[$role] ?? (BASE_URL . '/login.php');

header('Location: ' . $redirectUrl);
exit;

