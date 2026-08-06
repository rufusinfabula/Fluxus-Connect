<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/helpers.php';

fcSessionStart();
fcRequireLogin();

$tenantHash = (string) ($_GET['id'] ?? '');
if (!preg_match('/^[0-9a-f]{64}$/', $tenantHash) || !fcTenantExists($tenantHash)) {
    http_response_code(404);
    $fcTitle = 'Non trovata — Fluxus Connect';
    require __DIR__ . '/includes/layout_header.php';
    echo '<div class="fc-card"><h1>Istanza non trovata</h1><p><a href="dashboard.php">Torna al pannello</a></p></div>';
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
            $_SESSION['fc_flash_kind'] = 'subkey';
            $_SESSION['fc_flash_subkey_hash'] = $result['subkey_hash'];
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
    } elseif ($action === 'regenerate_token') {
        $result = fcRegenerateTenantToken($tenantHash);
        $_SESSION['fc_flash_token'] = $result['token'];
        $_SESSION['fc_flash_token_tenant'] = $result['tenant_hash'];
        $_SESSION['fc_flash_kind'] = 'tenant';
        header('Location: tenant.php?id=' . $result['tenant_hash']);
        exit;
    } elseif ($action === 'delete_tenant') {
        fcDeleteTenant($tenantHash);
        header('Location: dashboard.php');
        exit;
    }
}

$meta = fcReadTenantMeta($tenantHash);
$subkeys = fcListSubkeys($tenantHash);
$logEntries = fcLogRead($tenantHash, 100);

$flashToken = null;
$flashKind = null;
$flashSubkeyHash = null;
if (($_SESSION['fc_flash_token_tenant'] ?? null) === $tenantHash) {
    $flashToken = fcFlash('fc_flash_token');
    fcFlash('fc_flash_token_tenant');
    $flashKind = fcFlash('fc_flash_kind');
    $flashSubkeyHash = fcFlash('fc_flash_subkey_hash');
} else {
    // Pulizia difensiva: un flash rimasto agganciato a un altro tenant
    // (es. tab lasciata aperta) non va mai mostrato qui.
    unset($_SESSION['fc_flash_token'], $_SESSION['fc_flash_token_tenant'], $_SESSION['fc_flash_kind'], $_SESSION['fc_flash_subkey_hash']);
}

$fcTitle = ($meta['name'] ?? 'Istanza') . ' — Fluxus Connect';
require __DIR__ . '/includes/layout_header.php';
?>
<p><a href="dashboard.php">&larr; Tutte le istanze</a></p>
<div class="fc-card">
  <h1><?= fcE($meta['name'] ?? '') ?></h1>
  <p class="fc-muted">Creata il <?= fcE($meta['created_at'] ?? '') ?></p>
  <label>Indirizzo da inserire in Fluxus</label>
  <code class="fc-token"><?= fcE(fcApiPiBaseUrl()) ?></code>
  <div class="fc-actions">
    <form method="post" onsubmit="return confirm('Rigenerare il token di questa istanza? Il token attuale smetterà subito di funzionare: aggiornalo anche sul Pi.');">
      <?= fcCsrfField() ?>
      <input type="hidden" name="action" value="regenerate_token">
      <button type="submit" class="fc-button">Rigenera token</button>
    </form>
    <form method="post" onsubmit="return confirm('Eliminare questa istanza? Token, sotto-chiavi, coda e log andranno persi per sempre.');">
      <?= fcCsrfField() ?>
      <input type="hidden" name="action" value="delete_tenant">
      <button type="submit" class="fc-link-button fc-danger">Elimina istanza</button>
    </form>
  </div>
</div>

<?php if ($flashToken !== null && $flashKind === 'tenant'): ?>
<div class="fc-card fc-token-reveal">
  <h2>Token generato</h2>
  <p>Copialo ora: <strong>non sarà più possibile recuperarlo</strong>. Se lo perdi, potrai
  sempre rigenerarlo da qui sotto (invalida il precedente) senza perdere sotto-chiavi, coda
  e log — o revocare e ricreare la sotto-chiave, per una singola console.</p>
  <code class="fc-token"><?= fcE($flashToken) ?></code>
</div>
<?php endif; ?>

<details class="fc-card fc-live-card" id="fc-live-status">
  <summary>Stato in tempo reale</summary>
  <p class="fc-muted">Ciò che l'istanza sta dicendo in questo momento (direzione follow) — interrogato ogni 3 secondi finché questa card resta aperta, per verificare a colpo d'occhio che sia davvero collegata.</p>
  <div id="fc-live-status-body">
    <p class="fc-muted">Apertura in corso…</p>
  </div>
</details>

<details class="fc-card fc-live-card" id="fc-live-queue">
  <summary>Coda comandi</summary>
  <p class="fc-muted">Ciò che Connect tiene pronto per l'istanza, in attesa che lo ritiri al giro di polling successivo (direzione controllo).</p>
  <div id="fc-live-queue-body">
    <p class="fc-muted">Apertura in corso…</p>
  </div>
</details>
<script>
(function () {
  var statusDetails = document.getElementById('fc-live-status');
  var statusBody = document.getElementById('fc-live-status-body');
  var queueDetails = document.getElementById('fc-live-queue');
  var queueBody = document.getElementById('fc-live-queue-body');
  var tenantId = <?= json_encode($tenantHash) ?>;
  var timer = null;

  function esc(s) {
    return String(s ?? '').replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function secondsAgo(iso) {
    if (!iso) return null;
    var then = Date.parse(iso);
    if (isNaN(then)) return null;
    return Math.max(0, Math.round((Date.now() - then) / 1000));
  }

  function renderStatus(status) {
    if (!status) {
      return '<p class="fc-muted">Nessuno stato ancora ricevuto da questa istanza.</p>';
    }
    var age = secondsAgo(status.received_at);
    var html = '<p class="fc-muted">Ultimo aggiornamento: ' + esc(status.received_at || 'sconosciuto')
      + (age !== null ? ' (' + age + 's fa)' : '') + '</p>';
    var regs = Array.isArray(status.registrations) ? status.registrations : [];
    if (regs.length === 0) {
      html += '<p class="fc-muted">Nessuna registrazione attiva al momento.</p>';
    } else {
      html += '<ul class="fc-log">';
      regs.forEach(function (r) {
        html += '<li><span class="fc-badge fc-badge-active">' + esc(r.state) + '</span> '
          + esc(r.source || '') + (r.media_type ? ' — ' + esc(r.media_type) : '')
          + (r.elapsed_seconds != null ? ' — ' + esc(r.elapsed_seconds) + 's' : '')
          + (r.marker_count != null ? ' — ' + esc(r.marker_count) + ' marker' : '')
          + '</li>';
      });
      html += '</ul>';
    }
    return html;
  }

  function renderQueue(queue) {
    if (!Array.isArray(queue) || queue.length === 0) {
      return '<p class="fc-muted">Nessun comando in coda.</p>';
    }
    var html = '<ul class="fc-log">';
    queue.forEach(function (c) {
      html += '<li><span class="fc-log-time">' + esc(c.created_at || '') + '</span>'
        + esc(c.type) + (c.label ? ' — ' + esc(c.label) : '')
        + (c.target_id ? ' (su ' + esc(c.target_id) + ')' : '')
        + ' — depositato da ' + esc(c.subkey_name || 'una console')
        + '</li>';
    });
    html += '</ul>';
    return html;
  }

  function poll() {
    if (!statusDetails.open && !queueDetails.open) {
      return;
    }
    fetch('tenant_live.php?id=' + encodeURIComponent(tenantId), { headers: { 'Accept': 'application/json' } })
      .then(function (r) {
        if (!r.ok) throw new Error('http ' + r.status);
        return r.json();
      })
      .then(function (data) {
        if (statusDetails.open) {
          statusBody.innerHTML = renderStatus(data.status);
        }
        if (queueDetails.open) {
          queueBody.innerHTML = renderQueue(data.queue);
        }
      })
      .catch(function () {
        var errorHtml = '<p class="fc-alert">Sessione scaduta o errore di rete: ricarica la pagina.</p>';
        if (statusDetails.open) statusBody.innerHTML = errorHtml;
        if (queueDetails.open) queueBody.innerHTML = errorHtml;
      });
  }

  function onToggle() {
    if (statusDetails.open || queueDetails.open) {
      poll();
      if (!timer) {
        timer = setInterval(poll, 3000);
      }
    } else if (timer) {
      clearInterval(timer);
      timer = null;
    }
  }

  statusDetails.addEventListener('toggle', onToggle);
  queueDetails.addEventListener('toggle', onToggle);
})();
</script>

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
        <?php if ($flashToken !== null && $flashKind === 'subkey' && ($sk['subkey_hash'] ?? null) === $flashSubkeyHash): ?>
        <tr>
          <td colspan="5" class="fc-token-reveal">
            <p>Sotto-chiave per «<?= fcE($sk['name'] ?? '') ?>» generata: copiala ora,
            <strong>non sarà più possibile recuperarla</strong>. Se la perdi, revoca questa
            sotto-chiave e creane una nuova per la stessa console.</p>
            <code class="fc-token"><?= fcE($flashToken) ?></code>
          </td>
        </tr>
        <?php endif; ?>
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
