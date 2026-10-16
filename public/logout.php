<?php

/**
 * public/logout.php
 *
 * Menangani proses logout pengguna:
 *  1. Catat audit log aksi LOGOUT (sebelum sesi dihancurkan)
 *  2. Panggil logout() untuk menghancurkan sesi dan redirect ke login
 *
 * @see Requirements 1.4 — Pengguna dapat keluar dari sistem
 * @see Requirements 1.6 — Setiap aksi autentikasi dicatat di audit log
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/audit.php';

// Pastikan pengguna sudah login sebelum proses logout
requireAuth();

// Ambil data pengguna SEBELUM sesi dihancurkan
$user = getCurrentUser();

// Catat audit log SEBELUM memanggil logout() karena logout() menghancurkan sesi
logAudit(
    $user['user_id'],
    'LOGOUT',
    'users',
    $user['user_id'],
    [
        'context' => [
            'email' => $user['email'],
            'role'  => $user['role'],
        ],
    ]
);

// Hancurkan sesi dan redirect ke halaman login
logout();

