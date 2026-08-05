<?php
// Fluxus Connect — test dell'API pubblica per le console esterne (Fase 4).
// Uso: php tests/public_api_test.php
// Stesso stile di tests/api_test.php: server HTTP vero (php -S), perché qui
// si collauda l'autenticazione via header, gli scope delle sotto-chiavi e i
// codici di stato — non solo le funzioni PHP sottostanti.

$fcTestDir = sys_get_temp_dir() . '/fluxus-connect-public-api-test-' . bin2hex(random_bytes(4));
putenv("FC_DATA_DIR={$fcTestDir}");

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../public/includes/helpers.php';

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

function fcApiTestRequest(string $method, string $url, ?array $headers = null, ?string $body = null): array
{
    $headerLines = $headers !== null ? implode("\r\n", $headers) : '';
    $context = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => $headerLines,
            'content' => $body ?? '',
            'ignore_errors' => true,
            'timeout' => 5,
        ],
    ]);
    $raw = @file_get_contents($url, false, $context);
    $status = 0;
    foreach ($http_response_header ?? [] as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d+)#', $line, $m)) {
            $status = (int) $m[1];
        }
    }
    $decoded = $raw !== false ? json_decode($raw, true) : null;
    return [$status, is_array($decoded) ? $decoded : null];
}

echo "Fluxus Connect — test API pubblica (Fase 4)\n";
echo "Sandbox: {$fcTestDir}\n\n";

// --- Avvio del server integrato di PHP -------------------------------------

$publicDir = __DIR__ . '/../public';
$port = 20000 + (getmypid() % 20000);
$host = "127.0.0.1:{$port}";

$env = array_merge(array_filter($_SERVER, 'is_string'), ['FC_DATA_DIR' => $fcTestDir]);
$descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$process = proc_open(['php', '-S', $host, '-t', $publicDir], $descriptors, $pipes, null, $env);

if (!is_resource($process)) {
    fwrite(STDERR, "impossibile avviare il server di test\n");
    exit(1);
}
foreach ($pipes as $pipe) {
    stream_set_blocking($pipe, false);
}

$ready = false;
for ($i = 0; $i < 40; $i++) {
    $conn = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
    if ($conn !== false) {
        fclose($conn);
        $ready = true;
        break;
    }
    usleep(50000);
}
fcCheck('il server di test risponde entro 2s', $ready);

// --- Tenant e sotto-chiavi di prova ------------------------------------------

$tenant = fcCreateTenant('Pi di test');
$tenantHash = $tenant['tenant_hash'];

$followKey = fcCreateSubkey($tenantHash, 'Console follow', 'follow');
$controlKey = fcCreateSubkey($tenantHash, 'Software di scaletta', 'follow+control');
$revokedKey = fcCreateSubkey($tenantHash, 'Console revocata', 'follow+control');
fcRevokeSubkey($tenantHash, $revokedKey['subkey_hash']);

$followAuth = "Authorization: Bearer {$followKey['token']}";
$controlAuth = "Authorization: Bearer {$controlKey['token']}";
$revokedAuth = "Authorization: Bearer {$revokedKey['token']}";
$jsonWithControl = ['Content-Type: application/json', $controlAuth];

$base = "http://{$host}/api/v1";

// --- follow/status.php: autenticazione ---------------------------------------

echo "\nfollow/status.php — autenticazione:\n";
[$status, $body] = fcApiTestRequest('GET', "{$base}/follow/status.php");
fcCheck('senza token: 401', $status === 401);
fcCheck('senza token: corpo con "error"', isset($body['error']));

[$status] = fcApiTestRequest('GET', "{$base}/follow/status.php", ['Authorization: Bearer ' . str_repeat('0', 64)]);
fcCheck('sotto-chiave inesistente: 401', $status === 401);

[$status] = fcApiTestRequest('GET', "{$base}/follow/status.php", [$revokedAuth]);
fcCheck('sotto-chiave revocata: 401', $status === 401);

[$status] = fcApiTestRequest('POST', "{$base}/follow/status.php", [$followAuth]);
fcCheck('metodo sbagliato (POST invece di GET): 405', $status === 405);

[$status, $body] = fcApiTestRequest('GET', "{$base}/follow/status.php", [$followAuth]);
fcCheck('scope follow: 200', $status === 200);

[$status, $body] = fcApiTestRequest('GET', "{$base}/follow/status.php", [$controlAuth]);
fcCheck('scope follow+control può anche leggere lo stato: 200', $status === 200);

// --- follow/status.php: nessuno stato ancora pubblicato ----------------------

echo "\nfollow/status.php — nessuno stato pubblicato:\n";
[$status, $body] = fcApiTestRequest('GET', "{$base}/follow/status.php", [$followAuth]);
fcCheck("stato 'unknown' prima di qualunque pubblicazione", $status === 200 && ($body['state'] ?? null) === 'unknown');
fcCheck('il nome del Pi è comunque riportato', ($body['node_name'] ?? null) === 'Pi di test');

// --- follow/status.php: whitelist in lettura e multi-registrazione ----------

echo "\nfollow/status.php — whitelist in lettura e multi-registrazione:\n";
fcWriteStatus($tenantHash, [
    'registrations' => [
        [
            'id' => 'rec-1',
            'state' => 'in registrazione',
            'source' => 'ingresso-1',
            'media_type' => 'video',
            'started_at' => '2026-08-04T10:00:00Z',
            'elapsed_seconds' => 42,
            'marker_count' => 3,
            'pid' => 12345, // mai scritto dalla Fase 3, ma qui simulato per verificare che comunque non esca
            'source_config' => ['password' => 'segreto'],
            'internal_note' => 'non deve mai uscire',
        ],
        [
            'id' => 'rec-2',
            'state' => 'in pausa',
            'source' => 'ingresso-2',
        ],
    ],
    'received_at' => '2026-08-04T10:00:42.000000Z',
]);

[$status, $body] = fcApiTestRequest('GET', "{$base}/follow/status.php", [$followAuth]);
fcCheck('lettura riuscita: 200', $status === 200);
fcCheck('i campi in cima rispecchiano la prima registrazione (compatibilità)', $body['state'] === 'in registrazione'
    && $body['id'] === 'rec-1'
    && $body['source'] === 'ingresso-1'
    && $body['media_type'] === 'video'
    && $body['started_at'] === '2026-08-04T10:00:00Z'
    && $body['elapsed_seconds'] === 42
    && $body['marker_count'] === 3
    && $body['received_at'] === '2026-08-04T10:00:42.000000Z');
fcCheck("'pid' non whitelisted non esce mai in risposta", !array_key_exists('pid', $body));
fcCheck("'source_config' (può contenere credenziali) non esce mai in risposta", !array_key_exists('source_config', $body));
fcCheck("'internal_note' non whitelisted non esce mai in risposta", !array_key_exists('internal_note', $body));

fcCheck("'registrations' riporta entrambe le registrazioni attive", count($body['registrations'] ?? []) === 2);
$reg1 = $body['registrations'][0];
$reg2 = $body['registrations'][1];
fcCheck('la prima registrazione in elenco riporta i campi whitelisted', $reg1['id'] === 'rec-1'
    && $reg1['state'] === 'in registrazione'
    && $reg1['source'] === 'ingresso-1'
    && $reg1['elapsed_seconds'] === 42);
fcCheck("'pid'/'source_config'/'internal_note' non escono nemmeno dentro 'registrations'",
    !array_key_exists('pid', $reg1) && !array_key_exists('source_config', $reg1) && !array_key_exists('internal_note', $reg1));
fcCheck('la seconda registrazione è indipendente e altrettanto ripulita', $reg2['id'] === 'rec-2' && $reg2['state'] === 'in pausa');

// --- control/commands.php: autenticazione e scope ----------------------------

echo "\ncontrol/commands.php — autenticazione e scope:\n";
[$status, $body] = fcApiTestRequest('POST', "{$base}/control/commands.php", ['Content-Type: application/json'], json_encode(['type' => 'marker']));
fcCheck('senza token: 401', $status === 401);

[$status, $body] = fcApiTestRequest('POST', "{$base}/control/commands.php", ['Content-Type: application/json', $followAuth], json_encode(['type' => 'marker']));
fcCheck('scope follow (sola lettura) rifiutato: 403', $status === 403);

[$status, $body] = fcApiTestRequest('POST', "{$base}/control/commands.php", ['Content-Type: application/json', $revokedAuth], json_encode(['type' => 'marker']));
fcCheck('sotto-chiave revocata: 401', $status === 401);

[$status] = fcApiTestRequest('GET', "{$base}/control/commands.php", [$controlAuth]);
fcCheck('metodo sbagliato (GET invece di POST): 405', $status === 405);

// --- control/commands.php: whitelist delle azioni ----------------------------

echo "\ncontrol/commands.php — whitelist delle azioni:\n";
[$status, $body] = fcApiTestRequest('POST', "{$base}/control/commands.php", $jsonWithControl, json_encode(['type' => 'stop']));
fcCheck("azione non whitelisted ('stop', avvio/stop restano PENDING/FUTURO): 400", $status === 400);

[$status, $body] = fcApiTestRequest('POST', "{$base}/control/commands.php", $jsonWithControl, json_encode(['type' => 'start']));
fcCheck("azione non whitelisted ('start'): 400", $status === 400);

[$status, $body] = fcApiTestRequest('POST', "{$base}/control/commands.php", $jsonWithControl, json_encode([]));
fcCheck("senza 'type': 400", $status === 400);

[$status, $body] = fcApiTestRequest('POST', "{$base}/control/commands.php", $jsonWithControl, 'non è json');
fcCheck('corpo non JSON: 400', $status === 400);

[$status, $body] = fcApiTestRequest('POST', "{$base}/control/commands.php", $jsonWithControl, json_encode(['type' => 'marker', 'label' => str_repeat('x', 501)]));
fcCheck('label troppo lunga: 400', $status === 400);

// --- control/commands.php: deposito riuscito ---------------------------------

echo "\ncontrol/commands.php — deposito riuscito:\n";
// Il tenant ha in questo momento due registrazioni attive (rec-1/rec-2,
// scritte più sopra): target_id è quindi obbligatorio, ognuna verso una
// registrazione diversa.
[$status, $body] = fcApiTestRequest('POST', "{$base}/control/commands.php", $jsonWithControl, json_encode(['type' => 'marker', 'label' => '  Ingresso ospite  ', 'target_id' => 'rec-1']));
fcCheck('marker con etichetta e bersaglio: 200, ok:true, con id', $status === 200 && ($body['ok'] ?? null) === true && !empty($body['id']));
$markerId = $body['id'];

[$status, $body] = fcApiTestRequest('POST', "{$base}/control/commands.php", $jsonWithControl, json_encode(['type' => 'cue', 'target_id' => 'rec-2']));
fcCheck('cue senza etichetta, con bersaglio diverso: 200, ok:true', $status === 200 && ($body['ok'] ?? null) === true);

$queued = fcQueueList($tenantHash);
fcCheck('entrambi i comandi sono davvero in coda', count($queued) === 2);
$markerEntry = current(array_filter($queued, fn($c) => $c['id'] === $markerId));
fcCheck("l'etichetta viene salvata già ripulita dagli spazi", $markerEntry !== false && $markerEntry['label'] === 'Ingresso ospite');
fcCheck('il bersaglio scelto dalla console viene salvato nell\'oggetto in coda', $markerEntry !== false && $markerEntry['target_id'] === 'rec-1');
fcCheck("il nome della sotto-chiave finisce nell'oggetto in coda, non solo nel log", $markerEntry !== false && $markerEntry['subkey_name'] === 'Software di scaletta');
$cueEntry = current(array_filter($queued, fn($c) => $c['type'] === 'cue'));
fcCheck("un'etichetta vuota/assente non viene salvata affatto", $cueEntry !== false && !array_key_exists('label', $cueEntry));
fcCheck('il secondo comando porta il proprio bersaglio', $cueEntry !== false && $cueEntry['target_id'] === 'rec-2');

$logEntries = fcLogRead($tenantHash);
fcCheck('il deposito di un comando compare nel log di attività', $logEntries[0]['event'] === 'command_enqueued'
    && $logEntries[0]['context']['type'] === 'cue');
fcCheck('il log riporta anche il bersaglio del comando', $logEntries[0]['context']['target_id'] === 'rec-2');
fcCheck('il log riporta il nome della sotto-chiave che ha depositato il comando', $logEntries[0]['context']['subkey_name'] === 'Software di scaletta');
fcCheck("fcDescribeLogEvent descrive l'evento senza andare in errore", str_contains(fcDescribeLogEvent($logEntries[0]), 'Software di scaletta'));

// --- control/commands.php: bersaglio esplicito (target_id) -------------------
// Tenant dedicato, per non alterare il conteggio della coda usato più sotto
// nei test di isolamento fra tenant.

echo "\ncontrol/commands.php — bersaglio esplicito:\n";
$targetTenant = fcCreateTenant('Pi con più registrazioni');
$targetTenantHash = $targetTenant['tenant_hash'];
$targetKey = fcCreateSubkey($targetTenantHash, 'Console bersagli', 'follow+control');
$targetAuth = ['Content-Type: application/json', 'Authorization: Bearer ' . $targetKey['token']];

// Nessuna registrazione attiva: target_id resta facoltativo e il comando
// mantiene la forma di sempre (nessun target_id salvato) — stesso
// comportamento di prima di questa estensione.
[$status, $body] = fcApiTestRequest('POST', "{$base}/control/commands.php", $targetAuth, json_encode(['type' => 'marker']));
fcCheck('nessuna registrazione attiva, target_id omesso: 200', $status === 200);
$noTargetEntry = current(array_filter(fcQueueList($targetTenantHash), fn($c) => $c['id'] === $body['id']));
fcCheck('senza registrazioni attive non viene salvato alcun target_id (comportamento pre-esistente)', $noTargetEntry !== false && !array_key_exists('target_id', $noTargetEntry));

// Una sola registrazione attiva: il bersaglio è inequivocabile, Connect lo
// assegna da sé — non è un'euristica, è l'unica scelta possibile.
fcWriteStatus($targetTenantHash, ['registrations' => [['id' => 'sola-registrazione', 'state' => 'in registrazione']]]);
[$status, $body] = fcApiTestRequest('POST', "{$base}/control/commands.php", $targetAuth, json_encode(['type' => 'marker']));
fcCheck('una sola registrazione attiva, target_id omesso: 200', $status === 200);
$autoEntry = current(array_filter(fcQueueList($targetTenantHash), fn($c) => $c['id'] === $body['id']));
fcCheck('con una sola registrazione attiva il bersaglio viene assegnato automaticamente', $autoEntry !== false && $autoEntry['target_id'] === 'sola-registrazione');

// Due registrazioni attive: il bersaglio diventa obbligatorio — la scelta
// spetta alla console che chiama, mai a un'euristica qui o sul Pi.
fcWriteStatus($targetTenantHash, ['registrations' => [
    ['id' => 'reg-a', 'state' => 'in registrazione'],
    ['id' => 'reg-b', 'state' => 'in registrazione'],
]]);
[$status, $body] = fcApiTestRequest('POST', "{$base}/control/commands.php", $targetAuth, json_encode(['type' => 'marker']));
fcCheck('con più registrazioni attive, target_id omesso: 400', $status === 400);

[$status, $body] = fcApiTestRequest('POST', "{$base}/control/commands.php", $targetAuth, json_encode(['type' => 'marker', 'target_id' => 'non-esiste']));
fcCheck('target_id fuori whitelist (non fra le registrazioni attive): 400', $status === 400);

[$status, $body] = fcApiTestRequest('POST', "{$base}/control/commands.php", $targetAuth, json_encode(['type' => 'marker', 'target_id' => 'reg-b']));
fcCheck('target_id valido fra le registrazioni attive: 200', $status === 200);
$targetedEntry = current(array_filter(fcQueueList($targetTenantHash), fn($c) => $c['id'] === $body['id']));
fcCheck('il bersaglio scelto dalla console viene salvato', $targetedEntry !== false && $targetedEntry['target_id'] === 'reg-b');

// --- last_used_at ------------------------------------------------------------

echo "\nlast_used_at:\n";
$subkeyAfter = fcReadSubkey($tenantHash, $controlKey['subkey_hash']);
fcCheck("last_used_at viene aggiornato dopo un'autenticazione riuscita", !empty($subkeyAfter['last_used_at']));

// --- Isolamento fra tenant ----------------------------------------------------

echo "\nIsolamento fra tenant:\n";
$otherTenant = fcCreateTenant('Altro Pi');
$otherKey = fcCreateSubkey($otherTenant['tenant_hash'], 'Console altrove', 'follow+control');
fcWriteStatus($otherTenant['tenant_hash'], ['registrations' => [['id' => 'rec-altro', 'state' => 'fermo']]]);

[$status, $body] = fcApiTestRequest('GET', "{$base}/follow/status.php", ['Authorization: Bearer ' . $otherKey['token']]);
fcCheck("una sotto-chiave vede solo lo stato del proprio tenant", $status === 200 && $body['state'] === 'fermo' && $body['node_name'] === 'Altro Pi');

[$status, $body] = fcApiTestRequest('POST', "{$base}/control/commands.php", ['Content-Type: application/json', 'Authorization: Bearer ' . $otherKey['token']], json_encode(['type' => 'marker']));
fcCheck("un comando depositato con la sotto-chiave dell'altro Pi finisce nella sua coda, non in quella del primo", $status === 200);
fcCheck('la coda del primo tenant non è stata toccata', count(fcQueueList($tenantHash)) === 2);
fcCheck("la coda dell'altro tenant riceve il comando", count(fcQueueList($otherTenant['tenant_hash'])) === 1);

// --- Chiusura -----------------------------------------------------------------

proc_terminate($process);
foreach ($pipes as $pipe) {
    fclose($pipe);
}
proc_close($process);

fcRemoveDirRecursive($fcTestDir);

echo "\n{$fcPass} ok, {$fcFail} falliti.\n";
exit($fcFail === 0 ? 0 : 1);
