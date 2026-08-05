<?php
// Fluxus Connect — test minimo del motore per il pannello di
// amministrazione (Fase 2): tenant, sotto-chiavi, proprietario, log di
// attività. Stesso stile di storage_test.php: nessuna dipendenza esterna,
// contatore di esito, pensato per girare anche sull'hosting più povero.
// Uso: php tests/admin_test.php

$fcTestDir = sys_get_temp_dir() . '/fluxus-connect-admin-test-' . bin2hex(random_bytes(4));
putenv("FC_DATA_DIR={$fcTestDir}");

require_once __DIR__ . '/../includes/bootstrap.php';

$fcPass = 0;
$fcFail = 0;

function fcCheck(string $label, bool $condition): void
{
    global $fcPass, $fcFail;
    if ($condition) {
        $fcPass++;
        echo "  ok  - {$label}\n";
    } else {
        $fcFail++;
        echo "FAIL  - {$label}\n";
    }
}

function fcRemoveDirRecursive(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = "{$dir}/{$item}";
        is_dir($path) ? fcRemoveDirRecursive($path) : unlink($path);
    }
    rmdir($dir);
}

echo "Fluxus Connect — test motore del pannello di amministrazione\n";
echo "Sandbox: {$fcTestDir}\n\n";

// --- Tenant -----------------------------------------------------------------

echo "Tenant:\n";
fcCheck('nessun tenant prima di qualunque creazione', fcListTenants() === []);

$created = fcCreateTenant('Sala regia 1');
fcCheck('la creazione restituisce un token da 64 esadecimali', (bool) preg_match('/^[0-9a-f]{64}$/', $created['token']));
fcCheck("l'hash del tenant coincide con l'hash del token", $created['tenant_hash'] === fcHashToken($created['token']));
fcCheck('il tenant esiste dopo la creazione', fcTenantExists($created['tenant_hash']));

$meta = fcReadTenantMeta($created['tenant_hash']);
fcCheck('meta.json riporta il nome corretto', $meta !== null && $meta['name'] === 'Sala regia 1');
fcCheck('meta.json non contiene il token in chiaro', !in_array($created['token'], $meta, true));

try {
    fcCreateTenant('   ');
    fcCheck('nome vuoto rifiutato', false);
} catch (InvalidArgumentException $e) {
    fcCheck('nome vuoto rifiutato', true);
}

fcCreateTenant('Sala regia 2');
$tenants = fcListTenants();
fcCheck('la lista contiene entrambi i tenant creati', count($tenants) === 2);
fcCheck('la lista è ordinata dal più recente', $tenants[0]['name'] === 'Sala regia 2');

$tenantHash = $created['tenant_hash'];

// --- Sotto-chiavi -------------------------------------------------------------

echo "\nSotto-chiavi:\n";
fcCheck('nessuna sotto-chiave prima di qualunque creazione', fcListSubkeys($tenantHash) === []);

try {
    fcCreateSubkey($tenantHash, 'Regia video', 'scope-a-caso');
    fcCheck('scope non valido rifiutato', false);
} catch (InvalidArgumentException $e) {
    fcCheck('scope non valido rifiutato', true);
}

try {
    fcCreateSubkey(str_repeat('0', 64), 'Console fantasma', 'follow');
    fcCheck('tenant inesistente rifiutato', false);
} catch (InvalidArgumentException $e) {
    fcCheck('tenant inesistente rifiutato', true);
}

$subkey1 = fcCreateSubkey($tenantHash, 'Regia video', 'follow');
usleep(2000);
$subkey2 = fcCreateSubkey($tenantHash, 'Software di scaletta', 'follow+control');

fcCheck('token di sotto-chiave da 64 esadecimali', (bool) preg_match('/^[0-9a-f]{64}$/', $subkey1['token']));
fcCheck('due sotto-chiavi generate non coincidono', $subkey1['token'] !== $subkey2['token']);

$subkeys = fcListSubkeys($tenantHash);
fcCheck('la lista contiene le due sotto-chiavi create', count($subkeys) === 2);
fcCheck('ordine dal più recente', $subkeys[0]['name'] === 'Software di scaletta');
fcCheck('ogni sotto-chiave riporta il proprio hash', $subkeys[0]['subkey_hash'] === $subkey2['subkey_hash']);
fcCheck('sotto-chiave appena creata non è revocata', $subkeys[0]['revoked_at'] === null);

fcCheck('revoca di una sotto-chiave esistente riesce', fcRevokeSubkey($tenantHash, $subkey1['subkey_hash']) === true);
$afterRevoke = fcReadSubkey($tenantHash, $subkey1['subkey_hash']);
fcCheck('la sotto-chiave revocata riporta revoked_at valorizzato', !empty($afterRevoke['revoked_at']));
fcCheck('la revoca è idempotente (rifatta due volte: sempre true)', fcRevokeSubkey($tenantHash, $subkey1['subkey_hash']) === true);
fcCheck('revoca di una sotto-chiave inesistente restituisce false', fcRevokeSubkey($tenantHash, str_repeat('a', 64)) === false);

try {
    fcSubkeyPath($tenantHash, '../../../etc/passwd');
    fcCheck('hash sotto-chiave malformato rifiutato', false);
} catch (InvalidArgumentException $e) {
    fcCheck('hash sotto-chiave malformato rifiutato', true);
}

// --- Log di attività ------------------------------------------------------------

echo "\nLog di attività:\n";
$logEntries = fcLogRead($tenantHash);
fcCheck('il log contiene creazione tenant + 2 sotto-chiavi + 1 revoca', count($logEntries) === 4);
fcCheck('la voce più recente è la revoca', $logEntries[0]['event'] === 'subkey_revoked');
fcCheck('la voce più vecchia è la creazione del tenant', end($logEntries)['event'] === 'tenant_created');
fcCheck('il limite di fcLogRead viene rispettato', count(fcLogRead($tenantHash, 2)) === 2);
fcCheck('nessuna attività per un tenant senza log', fcLogRead(str_repeat('0', 64)) === []);

// --- Proprietario ---------------------------------------------------------------

echo "\nProprietario:\n";
fcCheck('nessun proprietario prima della configurazione', !fcOwnerExists());

try {
    fcOwnerSetPassword('admin', 'corta');
    fcCheck('password troppo corta rifiutata', false);
} catch (InvalidArgumentException $e) {
    fcCheck('password troppo corta rifiutata', true);
}

fcOwnerSetPassword('admin', 'una-password-lunga-abbastanza');
fcCheck('il proprietario esiste dopo la configurazione', fcOwnerExists());

// Due fallimenti prima del successo, per verificare che un login riuscito
// azzeri il contatore invece di limitarsi a non incrementarlo.
fcCheck('una password sbagliata non verifica', !fcOwnerVerify('admin', 'password-sbagliata'));
fcCheck('uno username sbagliato non verifica', !fcOwnerVerify('qualcun-altro', 'una-password-lunga-abbastanza'));
fcCheck('le credenziali corrette verificano', fcOwnerVerify('admin', 'una-password-lunga-abbastanza'));

$ownerData = fcOwnerRead();
fcCheck('owner.json non contiene la password in chiaro', strpos(json_encode($ownerData), 'una-password-lunga-abbastanza') === false);
fcCheck('un tentativo riuscito azzera il contatore di fallimenti', $ownerData['failed_attempts'] === 0);

for ($i = 0; $i < FC_OWNER_MAX_ATTEMPTS; $i++) {
    fcOwnerVerify('admin', 'password-sbagliata');
}
fcCheck('dopo troppi fallimenti scatta il blocco', fcOwnerLockedForSeconds() > 0);
fcCheck('durante il blocco anche le credenziali corrette falliscono', !fcOwnerVerify('admin', 'una-password-lunga-abbastanza'));

// --- Esito -------------------------------------------------------------------

fcRemoveDirRecursive($fcTestDir);

echo "\n{$fcPass} ok, {$fcFail} falliti.\n";
exit($fcFail === 0 ? 0 : 1);
