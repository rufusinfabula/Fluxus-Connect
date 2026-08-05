<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/helpers.php';

fcSessionStart();

if (fcIsLoggedIn()) {
    header('Location: dashboard.php');
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    fcCsrfCheck();

    if (!fcOwnerExists()) {
        $error = 'Nessun proprietario configurato. Esegui "php bin/create-owner.php" da riga di comando sul server.';
    } else {
        $locked = fcOwnerLockedForSeconds();
        if ($locked > 0) {
            $error = "Troppi tentativi falliti. Riprova fra {$locked} secondi.";
        } else {
            $username = (string) ($_POST['username'] ?? '');
            $password = (string) ($_POST['password'] ?? '');
            if (fcOwnerVerify($username, $password)) {
                fcLoginSession($username);
                header('Location: dashboard.php');
                exit;
            }
            $error = 'Credenziali non valide.';
        }
    }
}

$fcTitle = 'Accedi — Fluxus Connect';
require __DIR__ . '/includes/layout_header.php';
?>
<div class="fc-card fc-card-narrow">
  <h1>Accedi</h1>
  <?php if ($error): ?>
    <p class="fc-alert"><?= fcE($error) ?></p>
  <?php endif; ?>
  <form method="post" novalidate>
    <?= fcCsrfField() ?>
    <label for="username">Nome utente</label>
    <input type="text" id="username" name="username" autocomplete="username" required autofocus>

    <label for="password">Password</label>
    <input type="password" id="password" name="password" autocomplete="current-password" required>

    <button type="submit" class="fc-button">Accedi</button>
  </form>
</div>
<?php require __DIR__ . '/includes/layout_footer.php'; ?>
