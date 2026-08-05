<?php
// Fluxus Connect — subkeys.php
// Sotto-chiavi per console/sistemi esterni, annidate sotto il tenant a cui
// appartengono. Stesso principio dei token di primo livello: le genera
// sempre Connect (docs/NOTE-TECNICHE.md, "Chi genera i token, e perché"),
// revocabili singolarmente senza toccare le altre.

require_once __DIR__ . '/storage.php';
require_once __DIR__ . '/tokens.php';
require_once __DIR__ . '/activity_log.php';

const FC_SUBKEY_SCOPES = ['follow', 'follow+control'];

// Stessa forma di un hash sha256 (64 esadecimali) del tenant, ma è un
// concetto diverso: una validazione dedicata rende i punti di chiamata
// autodocumentanti, come già fa fcAssertValidQueueId per gli id di coda.
function fcAssertValidSubkeyHash(string $subkeyHash): void
{
    if (!preg_match('/^[0-9a-f]{64}$/', $subkeyHash)) {
        throw new InvalidArgumentException("hash sotto-chiave non valido: $subkeyHash");
    }
}

function fcSubkeysDir(string $tenantHash): string
{
    return fcTenantDir($tenantHash) . '/subkeys';
}

function fcSubkeyPath(string $tenantHash, string $subkeyHash): string
{
    fcAssertValidSubkeyHash($subkeyHash);
    return fcSubkeysDir($tenantHash) . "/{$subkeyHash}.json";
}

function fcCreateSubkey(string $tenantHash, string $name, string $scope): array
{
    if (!fcTenantExists($tenantHash)) {
        throw new InvalidArgumentException('tenant inesistente');
    }
    $name = trim($name);
    if ($name === '') {
        throw new InvalidArgumentException('il nome della sotto-chiave non può essere vuoto');
    }
    if (!in_array($scope, FC_SUBKEY_SCOPES, true)) {
        throw new InvalidArgumentException("scope non valido: $scope");
    }

    $token = fcGenerateToken();
    $subkeyHash = fcHashToken($token);

    if (is_file(fcSubkeyPath($tenantHash, $subkeyHash))) {
        throw new RuntimeException('collisione di token generando la sotto-chiave, riprovare');
    }

    $data = [
        'name' => $name,
        'scope' => $scope,
        'created_at' => fcTimestamp(),
        'last_used_at' => null,
        'revoked_at' => null,
    ];
    fcAtomicWriteFile(
        fcSubkeyPath($tenantHash, $subkeyHash),
        json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
    );

    fcLogAppend($tenantHash, 'subkey_created', ['name' => $name, 'scope' => $scope]);

    return ['token' => $token, 'subkey_hash' => $subkeyHash, 'name' => $name, 'scope' => $scope];
}

function fcReadSubkey(string $tenantHash, string $subkeyHash): ?array
{
    $path = fcSubkeyPath($tenantHash, $subkeyHash);
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

// Tutte le sotto-chiavi del tenant, comprese quelle revocate (il pannello
// le mostra comunque, marcate come tali: la revoca non è una
// cancellazione, serve tenerne traccia in "Attività").
function fcListSubkeys(string $tenantHash): array
{
    $dir = fcSubkeysDir($tenantHash);
    if (!is_dir($dir)) {
        return [];
    }

    $subkeys = [];
    foreach (glob($dir . '/*.json') ?: [] as $path) {
        $raw = file_get_contents($path);
        if ($raw === false) {
            continue;
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            continue;
        }
        $data['subkey_hash'] = basename($path, '.json');
        $subkeys[] = $data;
    }

    usort($subkeys, fn(array $a, array $b) => ($b['created_at'] ?? '') <=> ($a['created_at'] ?? ''));
    return $subkeys;
}

// Trova a quale tenant appartiene una sotto-chiave, dato solo il suo hash:
// a differenza del token di primo livello (dove l'hash è il nome della
// cartella, vedi fcApiRequireTenant in includes/api.php), una sotto-chiave
// sta annidata sotto un tenant che non si può derivare dall'hash stesso —
// va cercata. Con multi-tenant fin da subito ma un numero di Pi ospitati
// che resta piccolo (docs/NOTE-TECNICHE.md), una scansione lineare di
// fcListTenants() è più che sufficiente: non serve un indice dedicato.
function fcFindSubkeyTenant(string $subkeyHash): ?array
{
    foreach (fcListTenants() as $meta) {
        $tenantHash = (string) ($meta['token_hash'] ?? '');
        if ($tenantHash === '') {
            continue;
        }
        $subkey = fcReadSubkey($tenantHash, $subkeyHash);
        if ($subkey !== null) {
            return ['tenant_hash' => $tenantHash, 'subkey' => $subkey];
        }
    }
    return null;
}

// Aggiorna last_used_at a ogni autenticazione riuscita dell'API pubblica
// (Fase 4) — il campo esiste nello schema fin dalla Fase 2 ma prima d'ora
// nessun endpoint autenticava mai davvero una sotto-chiave. Sola scrittura,
// nessun evento nel log di attività: altrimenti il polling frequente di una
// console lo riempirebbe di rumore (stesso motivo per cui queue.php, in
// Fase 3, non logga le proprie letture).
function fcTouchSubkeyLastUsed(string $tenantHash, string $subkeyHash): void
{
    fcUpdateJsonFile(fcSubkeyPath($tenantHash, $subkeyHash), function (array $data) {
        $data['last_used_at'] = fcTimestamp();
        return $data;
    });
}

// Idempotente: revocare una chiave già revocata (o inesistente) non è un
// errore, il risultato voluto (chiave non più valida) è comunque
// raggiunto.
function fcRevokeSubkey(string $tenantHash, string $subkeyHash): bool
{
    $data = fcReadSubkey($tenantHash, $subkeyHash);
    if ($data === null) {
        return false;
    }
    if (!empty($data['revoked_at'])) {
        return true; // già revocata
    }

    $data['revoked_at'] = fcTimestamp();
    fcAtomicWriteFile(
        fcSubkeyPath($tenantHash, $subkeyHash),
        json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
    );
    fcLogAppend($tenantHash, 'subkey_revoked', ['name' => $data['name'] ?? null]);
    return true;
}
