<?php
/** @var string $fcTitle */
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= fcE($fcTitle ?? 'Fluxus Connect') ?></title>
<link rel="stylesheet" href="assets/style.css">
<link rel="icon" type="image/png" sizes="32x32" href="icons/fluxus-connect-32.png">
<link rel="icon" type="image/png" sizes="64x64" href="icons/fluxus-connect-64.png">
<link rel="apple-touch-icon" sizes="180x180" href="icons/fluxus-connect-180.png">
</head>
<body>
<header class="fc-header">
  <div class="fc-header-inner">
    <a class="fc-brand" href="dashboard.php"><img class="fc-brand-icon" src="icons/fluxus-connect-64.png" alt="" width="24" height="24">Fluxus Connect</a>
    <?php if (fcIsLoggedIn()): ?>
    <form method="post" action="logout.php" class="fc-logout-form">
      <?= fcCsrfField() ?>
      <button type="submit" class="fc-link-button">Esci</button>
    </form>
    <?php endif; ?>
  </div>
</header>
<main class="fc-main">
