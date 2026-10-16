<?php

/**
 * public/index.php
 *
 * Entry point aplikasi. Redirect ke halaman login.
 */

require_once __DIR__ . '/../config/database.php';

header('Location: ' . BASE_URL . '/login.php');
exit;
