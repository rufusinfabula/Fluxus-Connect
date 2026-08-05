<?php
// Fluxus Connect — test minimo del motore di storage.
// Uso: php tests/storage_test.php
// Nessuna dipendenza esterna (niente PHPUnit): una serie di controlli con
// un contatore di esito, pensato per girare anche sull'hosting più povero
// dove installare un framework di test non è scontato.

// Sandbox isolata da data/ reale: FC_DATA_DIR va impostato PRIMA di
// caricare config.php, che la legge una sola volta con getenv().
$fcTestDir = sys_get_temp_dir() . '/fluxus-connect-test-' . bin2hex(random_bytes(4));
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
    $items = scandir($dir);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = "{$dir}/{$item}";
        is_dir($path) ? fcRemoveDirRecursive($path) : unlink($path);
    }
    rmdir($dir);
}

echo "Fluxus Connect — test motore di storage\n";
echo "Sandbox: {$fcTestDir}\n\n";

// --- Token ----------------------------------------------------------------

echo "Token:\n";
$token = fcGenerateToken();
$otherToken = fcGenerateToken();
fcCheck('token da 64 caratteri esadecimali (256 bit)', (bool) preg_match('/^[0-9a-f]{64}$/', $token));
fcCheck('due token generati non coincidono', $token !== $otherToken);

$hash = fcHashToken($token);
fcCheck('hash da 64 caratteri esadecimali (sha256)', (bool) preg_match('/^[0-9a-f]{64}$/', $hash));
fcCheck('il token giusto verifica contro il proprio hash', fcVerifyToken($token, $hash));
fcCheck('un token sbagliato non verifica', !fcVerifyToken($otherToken, $hash));
fcCheck('una stringa vuota non verifica', !fcVerifyToken('', $hash));

// L'hash del token fa anche da nome di cartella per il tenant.
$tenantHash = $hash;

// --- status.json ------------------------------------------------------------

echo "\nstatus.json:\n";
fcCheck('nessuno status prima della prima scrittura', fcReadStatus($tenantHash) === null);

fcWriteStatus($tenantHash, ['stato' => 'in registrazione', 'sorgente' => 'ingresso-1', 'secondi' => 12]);
$status = fcReadStatus($tenantHash);
fcCheck('lo status scritto si rilegge identico', $status !== null
    && $status['stato'] === 'in registrazione'
    && $status['sorgente'] === 'ingresso-1'
    && $status['secondi'] === 12);

fcCheck('status.json esiste su disco dentro la cartella del tenant', is_file(fcStatusPath($tenantHash)));
fcCheck('nessun file temporaneo residuo dopo la scrittura', count(glob(dirname(fcStatusPath($tenantHash)) . '/*.tmp')) === 0);

// Riscrittura: deve sostituire, non accumulare.
fcWriteStatus($tenantHash, ['stato' => 'fermo']);
$status2 = fcReadStatus($tenantHash);
fcCheck('la riscrittura sostituisce lo stato precedente', $status2 === ['stato' => 'fermo']);

// --- Coda comandi -----------------------------------------------------------

echo "\nCoda comandi:\n";
fcCheck('coda vuota prima di qualunque inserimento', fcQueueList($tenantHash) === []);

$id1 = fcQueueEnqueue($tenantHash, ['azione' => 'marker', 'etichetta' => 'primo']);
usleep(2000); // margine per garantire timestamp diversi fra i tre id
$id2 = fcQueueEnqueue($tenantHash, ['azione' => 'marker', 'etichetta' => 'secondo']);
usleep(2000);
$id3 = fcQueueEnqueue($tenantHash, ['azione' => 'marker', 'etichetta' => 'terzo']);

fcCheck('tre id di coda distinti', count(array_unique([$id1, $id2, $id3])) === 3);

$queued = fcQueueList($tenantHash);
fcCheck('la lista contiene i tre comandi inseriti', count($queued) === 3);
fcCheck('ordine FIFO: il più vecchio è il primo della lista', $queued[0]['etichetta'] === 'primo'
    && $queued[1]['etichetta'] === 'secondo'
    && $queued[2]['etichetta'] === 'terzo');
fcCheck('ogni comando riporta il proprio id', $queued[0]['id'] === $id1);

fcCheck('rimozione di un comando esistente riesce', fcQueueRemove($tenantHash, $id1) === true);
fcCheck('il comando rimosso non compare più nella lista', count(fcQueueList($tenantHash)) === 2);
fcCheck('la rimozione è idempotente (secondo tentativo: false, non un errore)', fcQueueRemove($tenantHash, $id1) === false);

// --- Validazione ai confini (percorsi mai costruiti da input non controllato) -

echo "\nValidazione:\n";
$fcRejected = 0;
foreach (['../../../etc/passwd', 'troppo-corto', str_repeat('g', 64)] as $badHash) {
    try {
        fcReadStatus($badHash);
    } catch (InvalidArgumentException $e) {
        $fcRejected++;
    }
}
fcCheck('hash tenant malformati o con tentativo di traversal vengono rifiutati', $fcRejected === 3);

$fcRejectedIds = 0;
foreach (['../../../etc/passwd', 'non-un-id-valido'] as $badId) {
    try {
        fcQueueRemove($tenantHash, $badId);
    } catch (InvalidArgumentException $e) {
        $fcRejectedIds++;
    }
}
fcCheck('id di coda malformati o con tentativo di traversal vengono rifiutati', $fcRejectedIds === 2);

// --- Esito -------------------------------------------------------------------

fcRemoveDirRecursive($fcTestDir);

echo "\n{$fcPass} ok, {$fcFail} falliti.\n";
exit($fcFail === 0 ? 0 : 1);
