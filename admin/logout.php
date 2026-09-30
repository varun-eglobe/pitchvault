<?php
// admin/logout.php - Admin Logout Handler
require_once __DIR__ . '/../includes/auth.php';

unset($_SESSION['admin_id']);
unset($_SESSION['admin_name']);
unset($_SESSION['admin_email']);

header("Location: login");
exit;
