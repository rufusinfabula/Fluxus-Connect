<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/helpers.php';

fcSessionStart();
fcRequireLogin();

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_tenant') {
    fcCsrfCheck();
    try {
        $result = fcCreateTenant((string) ($_POST['name'] ?? ''));
        $_SESSION['fc_flash_token'] = $result['token'];
        $_SESSION['fc_flash_token_tenant'] = $result['tenant_hash'];
        header('Location: tenant.php?id=' . $result['tenant_hash']);
        exit;
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_tenant') {
    fcCsrfCheck();
    $tenantHash = (string) ($_POST['tenant_hash'] ?? '');
    if (preg_match('/^[0-9a-f]{64}$/', $tenantHash)) {
        fcDeleteTenant($tenantHash);
    }
    header('Location: dashboard.php');
    exit;
}

$tenants = fcListTenants();

$fcTitle = 'Pannello — Fluxus Connect';
require __DIR__ . '/includes/layout_header.php';
?>
<div class="fc-card">
  <h1>Nuova istanza</h1>
  <?php if ($error): ?>
    <p class="fc-alert"><?= fcE($error) ?></p>
  <?php endif; ?>
  <form method="post" class="fc-inline-form">
    <?= fcCsrfField() ?>
    <input type="hidden" name="action" value="create_tenant">
    <label for="name">Nome dell'istanza</label>
    <input type="text" id="name" name="name" required maxlength="120" placeholder="es. Sala regia 1">
    <button type="submit" class="fc-button">Crea e genera token</button>
  </form>
</div>

<div class="fc-card">
  <h1>Istanze registrate</h1>
  <?php if (!$tenants): ?>
    <p class="fc-muted">Nessuna istanza ancora registrata.</p>
  <?php else: ?>
    <table class="fc-table">
      <thead><tr><th>Nome</th><th>Creato il</th><th></th><th></th></tr></thead>
      <tbody>
        <?php foreach ($tenants as $t): ?>
        <tr>
          <td><?= fcE($t['name'] ?? '') ?></td>
          <td><?= fcE($t['created_at'] ?? '') ?></td>
          <td><a href="tenant.php?id=<?= fcE($t['token_hash'] ?? '') ?>">Apri</a></td>
          <td>
            <form method="post" onsubmit="return confirm('Eliminare l\'istanza «<?= fcE(addslashes($t['name'] ?? '')) ?>»? Token, sotto-chiavi, coda e log andranno persi per sempre.');">
              <?= fcCsrfField() ?>
              <input type="hidden" name="action" value="delete_tenant">
              <input type="hidden" name="tenant_hash" value="<?= fcE($t['token_hash'] ?? '') ?>">
              <button type="submit" class="fc-link-button fc-danger">Elimina</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/layout_footer.php'; ?>
