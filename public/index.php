<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/includes/session.php';

fcSessionStart();
header('Location: ' . (fcIsLoggedIn() ? 'dashboard.php' : 'login.php'));
exit;
