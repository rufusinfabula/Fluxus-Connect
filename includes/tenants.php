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
