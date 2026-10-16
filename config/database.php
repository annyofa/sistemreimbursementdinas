<?php

/**
 * config/database.php
 *
 * Konstanta global BASE_URL dan PDO singleton untuk seluruh aplikasi.
 *
 * Aturan penggunaan BASE_URL:
 *   - Semua header('Location: ...')  → header('Location: ' . BASE_URL . '/path.php');
 *   - Semua <a href="...">           → <?= BASE_URL ?>/path.php
 *   - Semua <form action="...">      → <?= BASE_URL ?>/path.php
 *   - Semua fetch() di JavaScript    → const BASE_URL = '<?= BASE_URL ?>'; (di-inject navbar.php)
 */

define('BASE_URL', '/reimbursement/public');
define('UPLOADS_URL', '/reimbursement/uploads'); // folder uploads ada di luar public/

/**
 * Mengembalikan PDO singleton untuk seluruh siklus request.
 *
 * Konfigurasi:
 *   - charset  : utf8mb4
 *   - ERRMODE  : EXCEPTION  (PDOException dilempar pada error SQL)
 *   - FETCH    : ASSOC      (hasil query berupa array asosiatif)
 */
function getDB(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $dsn = 'mysql:host=localhost;dbname=reimbursement_db;charset=utf8mb4';

        $pdo = new PDO($dsn, 'root', '', [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false, // native prepared statements
        ]);
    }

    return $pdo;
}
