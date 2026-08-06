<?php
// Fluxus Connect — storage.php
// Motore di storage su file piatti. Vedi docs/NOTE-TECNICHE.md, "Perché
// file piatti e non un database" e "Struttura su disco", per il perché di
// ogni scelta qui sotto — questo file la implementa, non la riprogetta.
//
// Struttura su disco (un tenant = un Pi, identificato dall'hash del suo
// token di primo livello):
//
//   data/tenants/<hash-del-token-pi>/
//     status.json   ← ultimo stato pubblicato dal Pi (follow)
//     queue/
//       <id>.json   ← un file per comando in coda (controllo)

require_once __DIR__ . '/config.php';

// L'hash è sempre un sha256 esadecimale (64 caratteri) prodotto da
// fcHashToken(): è anche il nome della cartella del tenant, quindi va
// validato prima di entrare in un percorso — in Fase 3/4 arriverà da un
// header HTTP, non fidarsi mai a prescindere da chi chiama oggi.
function fcAssertValidTenantHash(string $tenantHash): void
{
    if (!preg_match('/^[0-9a-f]{64}$/', $tenantHash)) {
        throw new InvalidArgumentException("hash tenant non valido: $tenantHash");
    }
}

// Stesso motivo per gli id di coda: generati solo da fcQueueEnqueue(), ma
// fcQueueRemove() li riceverà da fuori (l'API della Fase 3) — validare la
// forma prima di usarli in un percorso.
function fcAssertValidQueueId(string $id): void
{
    if (!preg_match('/^\d{8}T\d{6}\.\d{6}Z-[0-9a-f]{8}$/', $id)) {
        throw new InvalidArgumentException("id di coda non valido: $id");
    }
}

function fcTenantDir(string $tenantHash): string
{
    fcAssertValidTenantHash($tenantHash);
    return FC_DATA_DIR . '/tenants/' . $tenantHash;
}

function fcStatusPath(string $tenantHash): string
{
    return fcTenantDir($tenantHash) . '/status.json';
}

function fcQueueDir(string $tenantHash): string
{
    return fcTenantDir($tenantHash) . '/queue';
}

// Scrittura atomica generica: temporaneo nella stessa cartella (stesso
// filesystem, condizione necessaria perché rename() sia atomico), poi
// rename() sul percorso finale. Chi legge vede sempre un file completo,
// mai uno a metà scrittura.
function fcAtomicWriteFile(string $path, string $contents): void
{
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException("impossibile creare la cartella: $dir");
    }
    $tmpPath = $dir . '/.' . basename($path) . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (file_put_contents($tmpPath, $contents) === false) {
        throw new RuntimeException("scrittura fallita: $tmpPath");
    }
    chmod($tmpPath, 0600);
    if (!rename($tmpPath, $path)) {
        @unlink($tmpPath);
        throw new RuntimeException("rename fallita: $tmpPath -> $path");
    }
}

// Aggiornamento con lock: legge un file JSON, applica una funzione di
// trasformazione, riscrive — per i pochi file che vengono letti-e-
// modificati (non solo sovrascritti in blocco), es. il contatore di
// tentativi di login falliti del proprietario. A differenza di
// fcAtomicWriteFile (rimpiazzo secco), qui serve un lock che copra tutto
// il ciclo lettura-modifica-scrittura, altrimenti due richieste quasi
// simultanee possono perdersi un aggiornamento a vicenda.
function fcUpdateJsonFile(string $path, callable $mutate): array
{
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException("impossibile creare la cartella: $dir");
    }
    $handle = fopen($path, 'c+');
    if ($handle === false) {
        throw new RuntimeException("impossibile aprire: $path");
    }
    try {
        if (!flock($handle, LOCK_EX)) {
            throw new RuntimeException("impossibile ottenere il lock: $path");
        }
        $raw = stream_get_contents($handle);
        $current = ($raw !== false && $raw !== '') ? json_decode($raw, true) : null;
        $current = is_array($current) ? $current : [];

        $updated = $mutate($current);

        $json = json_encode($updated, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, $json);
        fflush($handle);
        chmod($path, 0600);

        return $updated;
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

// Timestamp UTC con risoluzione al microsecondo. Necessario ovunque il
// valore serva anche per ordinare (created_at di tenant e sotto-chiavi):
// alla risoluzione del secondo, due voci create nello stesso secondo
// pareggiano, e l'ordine dei pareggi finirebbe per dipendere da glob()
// (alfabetico sul nome del file, cioè sostanzialmente casuale) invece che
// dalla reale cronologia. Stesso principio già usato in
// fcGenerateQueueId() per gli id di coda.
function fcTimestamp(): string
{
    $now = microtime(true);
    $base = gmdate('Y-m-d\TH:i:s', (int) $now);
    $micros = sprintf('%06d', (int) round(($now - floor($now)) * 1_000_000));
    return "{$base}.{$micros}Z";
}

// --- Specchio di stato (follow) ------------------------------------------

function fcWriteStatus(string $tenantHash, array $status): void
{
    $json = json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    fcAtomicWriteFile(fcStatusPath($tenantHash), $json);
}

// null se il tenant non ha ancora pubblicato nulla (o la cartella non
// esiste proprio) — non un errore, è lo stato iniziale legittimo.
function fcReadStatus(string $tenantHash): ?array
{
    $path = fcStatusPath($tenantHash);
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

// --- Coda comandi (controllo) --------------------------------------------

// Id ordinabile per stringa = ordinabile per tempo (timestamp UTC a
// precisione di microsecondo, leggibile a occhio in un elenco di file),
// con un suffisso casuale per evitare collisioni fra comandi depositati
// nello stesso microsecondo.
function fcGenerateQueueId(): string
{
    $now = microtime(true);
    $timestamp = gmdate('Ymd\THis', (int) $now);
    $micros = sprintf('%06d', (int) round(($now - floor($now)) * 1_000_000));
    $suffix = bin2hex(random_bytes(4));
    return "{$timestamp}.{$micros}Z-{$suffix}";
}

function fcQueueEnqueue(string $tenantHash, array $command): string
{
    $id = fcGenerateQueueId();
    $json = json_encode($command, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    fcAtomicWriteFile(fcQueueDir($tenantHash) . "/{$id}.json", $json);
    return $id;
}

// Elenco dei comandi in attesa, dal più vecchio al più recente (FIFO). Sola
// lettura: non rimuove nulla, così l'API della Fase 3 può restituire la
// coda al Pi e aspettare conferma dell'esecuzione prima di cancellare —
// un comando letto ma non ancora eseguito non va perso se il Pi si blocca
// a metà.
function fcQueueList(string $tenantHash): array
{
    $dir = fcQueueDir($tenantHash);
    if (!is_dir($dir)) {
        return [];
    }
    $paths = glob($dir . '/*.json') ?: [];
    sort($paths, SORT_STRING);

    $commands = [];
    foreach ($paths as $path) {
        $raw = file_get_contents($path);
        if ($raw === false) {
            continue; // rimosso fra glob() e la lettura: normale sotto concorrenza
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            continue;
        }
        $data['id'] = basename($path, '.json');
        $commands[] = $data;
    }
    return $commands;
}

// Cancellazione di un comando specifico dopo l'esecuzione. Idempotente: se
// è già sparito (doppia conferma, o già ripulito) non è un errore, il
// risultato che il chiamante vuole è comunque raggiunto.
function fcQueueRemove(string $tenantHash, string $id): bool
{
    fcAssertValidQueueId($id);
    $path = fcQueueDir($tenantHash) . "/{$id}.json";
    if (!is_file($path)) {
        return false;
    }
    return unlink($path);
}

// --- Cataloghi (storico registrazioni, marker/cue, sorgenti, orari) ------
// Stesso principio di fcWriteStatus/fcReadStatus: specchio pieno, non una
// coda — ogni pubblicazione del Pi (ogni 30s, vedi connect_catalog_sync.php
// sul Pi) sostituisce per intero il catalogo noto a Connect per questo
// tenant. I quattro cataloghi condividono la stessa forma su disco (un
// oggetto con una sola chiave, l'elenco): un'unica coppia di funzioni
// generiche basta, a differenza della coda (fcQueueEnqueue/fcQueueList/
// fcQueueRemove), che ha semantiche diverse fra loro e non si presta alla
// stessa generalizzazione.

const FC_CATALOG_NAMES = ['recordings', 'markers', 'sources', 'schedules'];

function fcCatalogPath(string $tenantHash, string $catalog): string
{
    if (!in_array($catalog, FC_CATALOG_NAMES, true)) {
        throw new InvalidArgumentException("catalogo non valido: $catalog");
    }
    return fcTenantDir($tenantHash) . "/{$catalog}.json";
}

function fcWriteCatalog(string $tenantHash, string $catalog, array $items): void
{
    $json = json_encode([$catalog => $items], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    fcAtomicWriteFile(fcCatalogPath($tenantHash, $catalog), $json);
}

// [] se il tenant non ha ancora pubblicato questo catalogo — non un
// errore, stesso principio di fcReadStatus.
function fcReadCatalog(string $tenantHash, string $catalog): array
{
    $path = fcCatalogPath($tenantHash, $catalog);
    if (!is_file($path)) {
        return [];
    }
    $raw = file_get_contents($path);
    if ($raw === false || $raw === '') {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data[$catalog] ?? null) ? $data[$catalog] : [];
}
