<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/includes/session.php';

fcSessionStart();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && fcIsLoggedIn()) {
    fcCsrfCheck();
    fcLogoutSession();
}

header('Location: login.php');
exit;
