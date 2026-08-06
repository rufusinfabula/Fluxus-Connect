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
$ownerExists = fcOwnerExists();

// Primo avvio: se non esiste ancora un proprietario, questa stessa rotta
// mostra un form di creazione al posto del login, senza prova preventiva
// di controllo del file system — stesso schema di WordPress e affini. Vedi
// docs/NOTE-TECNICHE.md, "Configurazione del proprietario senza
// terminale", per il perché e per il rischio di corsa accettato
// deliberatamente. Una volta creato, la porta si chiude per sempre:
// fcOwnerCreateIfAbsent() scrive sotto lock e non sovrascrive un
// proprietario già esistente.
if (!$ownerExists && $_SERVER['REQUEST_METHOD'] === 'POST') {
    fcCsrfCheck();

    $username = (string) ($_POST['username'] ?? '');
    $password = (string) ($_POST['password'] ?? '');
    $passwordConfirm = (string) ($_POST['password_confirm'] ?? '');

    if ($password !== $passwordConfirm) {
        $error = 'Le due password non coincidono.';
    } else {
        try {
            $ip = ($_SERVER['REMOTE_ADDR'] ?? null);
            $ip = is_string($ip) && $ip !== '' ? $ip : null;

            if (fcOwnerCreateIfAbsent($username, $password, $ip)) {
                // Login automatico dopo la creazione, come al primo avvio di
                // WordPress: chi ha appena compilato il form ha già
                // dimostrato di conoscere le credenziali, farglielo
                // reinserire subito non aggiungerebbe sicurezza.
                fcLoginSession(trim($username));
                header('Location: dashboard.php');
                exit;
            }

            // Corsa persa: un'altra richiesta ha creato il proprietario nel
            // frattempo. Non si ritenta: da qui in poi questa stessa rotta
            // mostra il login, come per qualunque richiesta successiva.
            $ownerExists = true;
            $error = 'Il proprietario è già stato configurato da un\'altra richiesta. Accedi con le credenziali impostate.';
        } catch (InvalidArgumentException $e) {
            $error = $e->getMessage();
        }
    }
}

if (!$ownerExists) {
    $fcTitle = 'Crea il proprietario — Fluxus Connect';
    require __DIR__ . '/includes/layout_header.php';
    ?>
    <div class="fc-card fc-card-narrow">
      <img class="fc-auth-logo" src="icons/fluxus-connect-120.png" alt="Fluxus Connect">
      <h1>Crea il proprietario del pannello</h1>
      <p class="fc-muted">Nessun proprietario è ancora configurato: la prima creazione valida diventa l'unico account del pannello.</p>
      <?php if ($error): ?>
        <p class="fc-alert"><?= fcE($error) ?></p>
      <?php endif; ?>
      <form method="post" novalidate>
        <?= fcCsrfField() ?>
        <label for="username">Nome utente</label>
        <input type="text" id="username" name="username" autocomplete="username" required autofocus value="<?= fcE((string) ($_POST['username'] ?? '')) ?>">

        <label for="password">Password (almeno 12 caratteri)</label>
        <input type="password" id="password" name="password" autocomplete="new-password" required minlength="12">

        <label for="password_confirm">Ripeti la password</label>
        <input type="password" id="password_confirm" name="password_confirm" autocomplete="new-password" required minlength="12">

        <button type="submit" class="fc-button">Crea proprietario</button>
      </form>
    </div>
    <?php
    require __DIR__ . '/includes/layout_footer.php';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    fcCsrfCheck();

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

$fcTitle = 'Accedi — Fluxus Connect';
require __DIR__ . '/includes/layout_header.php';
?>
<div class="fc-card fc-card-narrow">
  <img class="fc-auth-logo" src="icons/fluxus-connect-120.png" alt="Fluxus Connect">
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
