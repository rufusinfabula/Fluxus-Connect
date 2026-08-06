<?php
// Fluxus Connect — tenants.php
// Un tenant = un Pi, identificato dall'hash del proprio token di primo
// livello. Il token lo genera sempre Connect, mai il Pi — vedi
// docs/NOTE-TECNICHE.md, "Chi genera i token, e perché".

require_once __DIR__ . '/storage.php';
require_once __DIR__ . '/tokens.php';
require_once __DIR__ . '/activity_log.php';

function fcTenantMetaPath(string $tenantHash): string
{
    return fcTenantDir($tenantHash) . '/meta.json';
}

// Crea un nuovo Pi: genera il token, salva su disco solo il suo hash. Il
// token in chiaro torna esclusivamente nel valore di ritorno — chi chiama
// lo mostra una volta sola (pannello) e non lo scrive mai su disco.
function fcCreateTenant(string $name): array
{
    $name = trim($name);
    if ($name === '') {
        throw new InvalidArgumentException('il nome del Pi non può essere vuoto');
    }

    $token = fcGenerateToken();
    $tenantHash = fcHashToken($token);

    if (is_dir(fcTenantDir($tenantHash))) {
        // Collisione su 256 bit: astronomicamente improbabile, ma meglio
        // fallire rumorosamente che sovrascrivere un tenant esistente.
        throw new RuntimeException('collisione di token generando il tenant, riprovare');
    }

    $meta = [
        'name' => $name,
        'token_hash' => $tenantHash,
        'created_at' => fcTimestamp(),
    ];
    fcAtomicWriteFile(
        fcTenantMetaPath($tenantHash),
        json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
    );

    fcLogAppend($tenantHash, 'tenant_created', ['name' => $name]);

    return ['token' => $token, 'tenant_hash' => $tenantHash, 'name' => $name];
}

function fcReadTenantMeta(string $tenantHash): ?array
{
    $path = fcTenantMetaPath($tenantHash);
    if (!is_file($path)) {
        return null;
    }
    $raw = file_get_contents($path);
    if ($raw === false || $raw === '') {
        return null;
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

function fcTenantExists(string $tenantHash): bool
{
    return fcReadTenantMeta($tenantHash) !== null;
}

// Rigenera il token di un tenant esistente: nome, sotto-chiavi, coda e log
// restano intatti, solo il token di primo livello cambia (e con esso
// l'hash che nomina la sua cartella — le sotto-chiavi non lo referenziano
// mai direttamente, vengono ritrovate per scansione da fcFindSubkeyTenant,
// quindi il rename non richiede altri aggiornamenti). Serve per il caso in
// cui il token mostrato una sola volta va perso: l'alternativa sarebbe
// cancellare il tenant e ricrearlo, perdendo sotto-chiavi e log.
function fcRegenerateTenantToken(string $tenantHash): array
{
    $meta = fcReadTenantMeta($tenantHash);
    if ($meta === null) {
        throw new InvalidArgumentException('tenant inesistente');
    }

    $token = fcGenerateToken();
    $newHash = fcHashToken($token);

    if (is_dir(fcTenantDir($newHash))) {
        throw new RuntimeException('collisione di token rigenerando il tenant, riprovare');
    }

    $meta['token_hash'] = $newHash;
    fcAtomicWriteFile(
        fcTenantMetaPath($tenantHash),
        json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
    );

    if (!rename(fcTenantDir($tenantHash), fcTenantDir($newHash))) {
        throw new RuntimeException('impossibile rinominare la cartella del tenant durante la rigenerazione del token');
    }

    fcLogAppend($newHash, 'tenant_token_regenerated', ['name' => $meta['name'] ?? null]);

    return ['token' => $token, 'tenant_hash' => $newHash, 'name' => $meta['name'] ?? ''];
}

// Cancella un tenant e tutto ciò che contiene (sotto-chiavi, coda, log) —
// irreversibile, nessun modo di recuperarlo dopo. Idempotente: cancellare
// un tenant già assente non è un errore, il risultato voluto (nessun
// tenant con questo hash) è comunque raggiunto.
function fcDeleteTenant(string $tenantHash): bool
{
    if (!fcTenantExists($tenantHash)) {
        return false;
    }
    fcDeleteDirRecursive(fcTenantDir($tenantHash));
    return true;
}

function fcDeleteDirRecursive(string $dir): void
{
    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $dir . '/' . $entry;
        if (is_dir($path)) {
            fcDeleteDirRecursive($path);
        } else {
            unlink($path);
        }
    }
    rmdir($dir);
}

// Elenco di tutti i tenant, più recenti prima — per la dashboard del
// pannello.
function fcListTenants(): array
{
    $dir = FC_DATA_DIR . '/tenants';
    if (!is_dir($dir)) {
        return [];
    }

    $tenants = [];
    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        if (!preg_match('/^[0-9a-f]{64}$/', $entry)) {
            continue; // cartella inattesa, non un tenant valido: ignorata
        }
        $meta = fcReadTenantMeta($entry);
        if ($meta !== null) {
            $tenants[] = $meta;
        }
    }

    usort($tenants, fn(array $a, array $b) => ($b['created_at'] ?? '') <=> ($a['created_at'] ?? ''));
    return $tenants;
}
