<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/helpers.php';

fcSessionStart();
fcRequireLogin();

$tenantHash = (string) ($_GET['id'] ?? '');
if (!preg_match('/^[0-9a-f]{64}$/', $tenantHash) || !fcTenantExists($tenantHash)) {
    http_response_code(404);
    $fcTitle = 'Non trovato — Fluxus Connect';
    require __DIR__ . '/includes/layout_header.php';
    echo '<div class="fc-card"><h1>Pi non trovato</h1><p><a href="dashboard.php">Torna al pannello</a></p></div>';
    require __DIR__ . '/includes/layout_footer.php';
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    fcCsrfCheck();
    $action = $_POST['action'] ?? '';

    if ($action === 'create_subkey') {
        try {
            $result = fcCreateSubkey($tenantHash, (string) ($_POST['name'] ?? ''), (string) ($_POST['scope'] ?? ''));
            $_SESSION['fc_flash_token'] = $result['token'];
            $_SESSION['fc_flash_token_tenant'] = $tenantHash;
            header('Location: tenant.php?id=' . $tenantHash);
            exit;
        } catch (InvalidArgumentException $e) {
            $error = $e->getMessage();
        }
    } elseif ($action === 'revoke_subkey') {
        $subkeyHash = (string) ($_POST['subkey_hash'] ?? '');
        if (preg_match('/^[0-9a-f]{64}$/', $subkeyHash)) {
            fcRevokeSubkey($tenantHash, $subkeyHash);
        }
        header('Location: tenant.php?id=' . $tenantHash);
        exit;
    }
}

$meta = fcReadTenantMeta($tenantHash);
$subkeys = fcListSubkeys($tenantHash);
$logEntries = fcLogRead($tenantHash, 100);

$flashToken = null;
if (($_SESSION['fc_flash_token_tenant'] ?? null) === $tenantHash) {
    $flashToken = fcFlash('fc_flash_token');
    fcFlash('fc_flash_token_tenant');
} else {
    // Pulizia difensiva: un flash rimasto agganciato a un altro tenant
    // (es. tab lasciata aperta) non va mai mostrato qui.
    unset($_SESSION['fc_flash_token'], $_SESSION['fc_flash_token_tenant']);
}

$fcTitle = ($meta['name'] ?? 'Pi') . ' — Fluxus Connect';
require __DIR__ . '/includes/layout_header.php';
?>
<p><a href="dashboard.php">&larr; Tutti i Pi</a></p>
<div class="fc-card">
  <h1><?= fcE($meta['name'] ?? '') ?></h1>
  <p class="fc-muted">Creato il <?= fcE($meta['created_at'] ?? '') ?></p>
</div>

<?php if ($flashToken !== null): ?>
<div class="fc-card fc-token-reveal">
  <h2>Token generato</h2>
  <p>Copialo ora: <strong>non sarà più possibile recuperarlo</strong>. Se lo perdi, dovrai
  creare un nuovo Pi (per il token del Pi) o revocare e ricreare la sotto-chiave (per una console).</p>
  <code class="fc-token"><?= fcE($flashToken) ?></code>
</div>
<?php endif; ?>

<div class="fc-card">
  <h2>Nuova sotto-chiave</h2>
  <?php if ($error): ?>
    <p class="fc-alert"><?= fcE($error) ?></p>
  <?php endif; ?>
  <form method="post" class="fc-inline-form">
    <?= fcCsrfField() ?>
    <input type="hidden" name="action" value="create_subkey">
    <label for="subkey-name">Nome console</label>
    <input type="text" id="subkey-name" name="name" required maxlength="120" placeholder="es. Regia video">
    <label for="subkey-scope">Permessi</label>
    <select id="subkey-scope" name="scope">
      <option value="follow">follow (sola lettura)</option>
      <option value="follow+control">follow+control (lettura + comandi)</option>
    </select>
    <button type="submit" class="fc-button">Crea sotto-chiave</button>
  </form>
</div>

<div class="fc-card">
  <h2>Sotto-chiavi</h2>
  <?php if (!$subkeys): ?>
    <p class="fc-muted">Nessuna sotto-chiave ancora creata.</p>
  <?php else: ?>
    <table class="fc-table">
      <thead><tr><th>Nome</th><th>Permessi</th><th>Creata il</th><th>Stato</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($subkeys as $sk): ?>
        <tr>
          <td><?= fcE($sk['name'] ?? '') ?></td>
          <td><?= fcE($sk['scope'] ?? '') ?></td>
          <td><?= fcE($sk['created_at'] ?? '') ?></td>
          <td>
            <?php if (!empty($sk['revoked_at'])): ?>
              <span class="fc-badge fc-badge-revoked">revocata il <?= fcE($sk['revoked_at']) ?></span>
            <?php else: ?>
              <span class="fc-badge fc-badge-active">attiva</span>
            <?php endif; ?>
          </td>
          <td>
            <?php if (empty($sk['revoked_at'])): ?>
            <form method="post" onsubmit="return confirm('Revocare questa sotto-chiave? La console che la usa perderà l\'accesso.');">
              <?= fcCsrfField() ?>
              <input type="hidden" name="action" value="revoke_subkey">
              <input type="hidden" name="subkey_hash" value="<?= fcE($sk['subkey_hash'] ?? '') ?>">
              <button type="submit" class="fc-link-button fc-danger">Revoca</button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<div class="fc-card">
  <h2>Attività</h2>
  <?php if (!$logEntries): ?>
    <p class="fc-muted">Nessuna attività registrata.</p>
  <?php else: ?>
    <ul class="fc-log">
      <?php foreach ($logEntries as $entry): ?>
      <li><span class="fc-log-time"><?= fcE($entry['at'] ?? '') ?></span><?= fcE(fcDescribeLogEvent($entry)) ?></li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/layout_footer.php'; ?>
