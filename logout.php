<?php
require_once __DIR__ . '/includes/auth.php';
logoutUser();
header('Location: ' . SITE_URL . '/login.php');
exit;
