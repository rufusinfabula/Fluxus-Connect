<?php
// Fluxus Connect — test dell'API riservata al Pi (Fase 3).
// Uso: php tests/api_test.php
// Stesso stile degli altri test (nessuna dipendenza esterna, contatore di
// esito) ma qui serve un server HTTP vero, perché si sta collaudando
// l'autenticazione via header e i codici di stato — non solo le funzioni
// PHP sottostanti (già coperte da storage_test.php). Si usa il server
// integrato di PHP (`php -S`), lo stesso metodo con cui la Fase 2 è stata
// collaudata a mano, qui automatizzato.

$fcTestDir = sys_get_temp_dir() . '/fluxus-connect-api-test-' . bin2hex(random_bytes(4));
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

// Richiesta HTTP via stream context (niente dipendenza da curl): restituisce
// sempre [status, body-decodificato-o-null], anche sui codici di errore
// (ignore_errors) — è proprio quello che si vuole verificare.
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

echo "Fluxus Connect — test API riservata al Pi\n";
echo "Sandbox: {$fcTestDir}\n\n";

// --- Avvio del server integrato di PHP, sullo stesso FC_DATA_DIR ------------
// Le funzioni di storage lavorano solo su file: processo di test e server
// possono condividere lo stesso sandbox su disco senza altro coordinamento
// (è proprio il punto dei file piatti, vedi docs/NOTE-TECNICHE.md).

$publicDir = __DIR__ . '/../public';
$port = 20000 + (getmypid() % 20000);
$host = "127.0.0.1:{$port}";

// array_filter con is_string: $_SERVER contiene anche voci non scalari
// (es. 'argv'), che proc_open non può passare come variabili d'ambiente.
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

// --- Tenant di prova ----------------------------------------------------------

$tenant = fcCreateTenant('Pi di test');
$token = $tenant['token'];
$tenantHash = $tenant['tenant_hash'];
$authHeader = "Authorization: Bearer {$token}";
$jsonHeaders = ['Content-Type: application/json', $authHeader];

$base = "http://{$host}/api/pi";

// --- status.php: autenticazione ------------------------------------------------

echo "\nstatus.php — autenticazione:\n";
[$status, $body] = fcApiTestRequest('POST', "{$base}/status.php", ['Content-Type: application/json'], json_encode(['state' => 'idle']));
fcCheck('senza token: 401', $status === 401);
fcCheck('senza token: corpo con "error"', isset($body['error']));

[$status] = fcApiTestRequest('POST', "{$base}/status.php", ['Content-Type: application/json', 'Authorization: Bearer ' . str_repeat('0', 64)], json_encode(['state' => 'idle']));
fcCheck('token inesistente: 401', $status === 401);

[$status] = fcApiTestRequest('GET', "{$base}/status.php", [$authHeader]);
fcCheck('metodo sbagliato (GET invece di POST): 405', $status === 405);

// --- status.php: validazione -----------------------------------------------

echo "\nstatus.php — validazione:\n";
[$status, $body] = fcApiTestRequest('POST', "{$base}/status.php", $jsonHeaders, json_encode(['source' => 'ingresso-1']));
fcCheck("senza 'registrations': 400", $status === 400);

[$status] = fcApiTestRequest('POST', "{$base}/status.php", $jsonHeaders, json_encode(['registrations' => 'non un array']));
fcCheck("'registrations' non è un array: 400", $status === 400);

[$status] = fcApiTestRequest('POST', "{$base}/status.php", $jsonHeaders, 'non è json');
fcCheck('corpo non JSON: 400', $status === 400);

[$status] = fcApiTestRequest('POST', "{$base}/status.php", $jsonHeaders, json_encode(['registrations' => [['state' => 'in registrazione']]]));
fcCheck("registrazione senza 'id': 400", $status === 400);

[$status] = fcApiTestRequest('POST', "{$base}/status.php", $jsonHeaders, json_encode(['registrations' => [['id' => 'rec-1']]]));
fcCheck("registrazione senza 'state': 400", $status === 400);

[$status] = fcApiTestRequest('POST', "{$base}/status.php", $jsonHeaders, json_encode(['registrations' => [
    ['id' => 'rec-1', 'state' => 'in registrazione'],
    ['id' => 'rec-1', 'state' => 'in pausa'],
]]));
fcCheck('id duplicato fra due registrazioni: 400', $status === 400);

[$status] = fcApiTestRequest('POST', "{$base}/status.php", $jsonHeaders, json_encode(['registrations' => [
    ['id' => 'rec-1', 'state' => 'in registrazione', 'elapsed_seconds' => -1],
]]));
fcCheck('elapsed_seconds negativo: 400', $status === 400);

[$status] = fcApiTestRequest('POST', "{$base}/status.php", $jsonHeaders, json_encode(['registrations' => [
    ['id' => 'rec-1', 'state' => 'in registrazione', 'marker_count' => 'tre'],
]]));
fcCheck('marker_count non intero: 400', $status === 400);

// --- status.php: scrittura riuscita, e campi non whitelisted scartati ---------

echo "\nstatus.php — scrittura:\n";
$payload = [
    'registrations' => [
        [
            'id' => 'rec-1',
            'state' => 'in registrazione',
            'source' => 'ingresso-1',
            'media_type' => 'video',
            'started_at' => '2026-08-04T10:00:00Z',
            'elapsed_seconds' => 42,
            'marker_count' => 3,
            'pid' => 12345, // non whitelisted: non deve arrivare mai su disco
            'source_config' => ['password' => 'segreto'], // idem
        ],
        [
            'id' => 'rec-2',
            'state' => 'in pausa',
            'source' => 'ingresso-2',
        ],
    ],
];
[$status, $body] = fcApiTestRequest('POST', "{$base}/status.php", $jsonHeaders, json_encode($payload));
fcCheck('scrittura riuscita: 200', $status === 200);
fcCheck('risposta con ok:true', ($body['ok'] ?? null) === true);
fcCheck('risposta con received_at', !empty($body['received_at']));

$stored = fcReadStatus($tenantHash);
fcCheck('lo stato scritto si rilegge con due registrazioni', $stored !== null && count($stored['registrations']) === 2);
$rec1 = $stored['registrations'][0];
fcCheck('la prima registrazione riporta i campi whitelisted', $rec1['id'] === 'rec-1'
    && $rec1['state'] === 'in registrazione'
    && $rec1['source'] === 'ingresso-1'
    && $rec1['media_type'] === 'video'
    && $rec1['elapsed_seconds'] === 42
    && $rec1['marker_count'] === 3);
fcCheck("campo non whitelisted 'pid' mai salvato su disco", !array_key_exists('pid', $rec1));
fcCheck("campo non whitelisted 'source_config' mai salvato su disco", !array_key_exists('source_config', $rec1));
fcCheck('la seconda registrazione è indipendente dalla prima', $stored['registrations'][1]['id'] === 'rec-2'
    && $stored['registrations'][1]['state'] === 'in pausa');

// --- status.php: nessuna registrazione attiva (elenco vuoto) -----------------

echo "\nstatus.php — elenco vuoto:\n";
[$status, $body] = fcApiTestRequest('POST', "{$base}/status.php", $jsonHeaders, json_encode(['registrations' => []]));
fcCheck('elenco vuoto accettato: 200', $status === 200);
fcCheck('elenco vuoto si rilegge come tale', fcReadStatus($tenantHash)['registrations'] === []);

// --- queue.php ------------------------------------------------------------------

echo "\nqueue.php:\n";
[$status, $body] = fcApiTestRequest('GET', "{$base}/queue.php", [$authHeader]);
fcCheck('coda vuota: 200 con lista vuota', $status === 200 && $body['commands'] === []);

[$status] = fcApiTestRequest('GET', "{$base}/queue.php", []);
fcCheck('senza token: 401', $status === 401);

[$status] = fcApiTestRequest('POST', "{$base}/queue.php", [$authHeader]);
fcCheck('metodo sbagliato (POST invece di GET): 405', $status === 405);

$queueId = fcQueueEnqueue($tenantHash, ['azione' => 'marker', 'etichetta' => 'primo']);

[$status, $body] = fcApiTestRequest('GET', "{$base}/queue.php", [$authHeader]);
fcCheck('un comando in coda: 200 con un elemento', $status === 200 && count($body['commands']) === 1);
fcCheck("l'elemento riporta il proprio id", ($body['commands'][0]['id'] ?? null) === $queueId);
fcCheck("l'elemento riporta il proprio contenuto", ($body['commands'][0]['etichetta'] ?? null) === 'primo');

// --- ack.php --------------------------------------------------------------------

echo "\nack.php:\n";
[$status] = fcApiTestRequest('POST', "{$base}/ack.php", ['Content-Type: application/json'], json_encode(['id' => $queueId]));
fcCheck('senza token: 401', $status === 401);

[$status, $body] = fcApiTestRequest('POST', "{$base}/ack.php", $jsonHeaders, json_encode(['id' => '../../../etc/passwd']));
fcCheck('id malformato (tentativo di traversal): 400', $status === 400);

[$status, $body] = fcApiTestRequest('POST', "{$base}/ack.php", $jsonHeaders, json_encode(['id' => $queueId]));
fcCheck('conferma di un comando esistente: 200, removed:true', $status === 200 && ($body['removed'] ?? null) === true);

[$status, $body] = fcApiTestRequest('GET', "{$base}/queue.php", [$authHeader]);
fcCheck('il comando confermato non compare più in coda', $status === 200 && $body['commands'] === []);

[$status, $body] = fcApiTestRequest('POST', "{$base}/ack.php", $jsonHeaders, json_encode(['id' => $queueId]));
fcCheck('riconferma dello stesso id: 200, removed:false (idempotente)', $status === 200 && ($body['removed'] ?? null) === false);

$logEntries = fcLogRead($tenantHash);
fcCheck("l'esecuzione confermata compare nel log di attività", $logEntries[0]['event'] === 'command_acknowledged'
    && $logEntries[0]['context']['id'] === $queueId);

// --- Isolamento fra tenant --------------------------------------------------

echo "\nIsolamento fra tenant:\n";
$otherTenant = fcCreateTenant('Altro Pi');
fcQueueEnqueue($tenantHash, ['azione' => 'marker', 'etichetta' => 'solo per il primo Pi']);

[$status, $body] = fcApiTestRequest('GET', "{$base}/queue.php", ['Authorization: Bearer ' . $otherTenant['token']]);
fcCheck("un Pi non vede la coda di un altro Pi", $status === 200 && $body['commands'] === []);

// --- Chiusura ---------------------------------------------------------------

proc_terminate($process);
foreach ($pipes as $pipe) {
    fclose($pipe);
}
proc_close($process);

fcRemoveDirRecursive($fcTestDir);

echo "\n{$fcPass} ok, {$fcFail} falliti.\n";
exit($fcFail === 0 ? 0 : 1);
