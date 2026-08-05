<?php
// Fluxus Connect — activity_log.php
// Log append-only per tenant (log.jsonl), per la vista "log/attività" del
// pannello di amministrazione. Vedi docs/NOTE-TECNICHE.md, "Struttura su
// disco".

require_once __DIR__ . '/storage.php';

function fcLogPath(string $tenantHash): string
{
    return fcTenantDir($tenantHash) . '/log.jsonl';
}

// Scrittura in append: niente temporaneo+rename (romperebbe l'append, ogni
// scrittura rimpiazzerebbe l'intero file) — basta O_APPEND con lock
// esclusivo. Su POSIX una singola write() di poche centinaia di byte è già
// atomica; LOCK_EX evita solo l'interleaving fra processi lenti.
function fcLogAppend(string $tenantHash, string $event, array $context = []): void
{
    $dir = fcTenantDir($tenantHash);
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException("impossibile creare la cartella: $dir");
    }

    $entry = [
        'at' => fcTimestamp(),
        'event' => $event,
        'context' => $context,
    ];
    $line = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";

    $path = fcLogPath($tenantHash);
    $handle = fopen($path, 'a');
    if ($handle === false) {
        throw new RuntimeException("impossibile aprire il log: $path");
    }
    flock($handle, LOCK_EX);
    fwrite($handle, $line);
    flock($handle, LOCK_UN);
    fclose($handle);
    chmod($path, 0600);
}

// Voci più recenti prima, al massimo $limit. Il file resta piccolo per il
// carico atteso in questa fase (azioni da pannello, non i cicli di polling
// del Pi che arriveranno con la Fase 3): non serve un indice, leggerlo per
// intero e girarlo basta.
function fcLogRead(string $tenantHash, int $limit = 200): array
{
    $path = fcLogPath($tenantHash);
    if (!is_file($path)) {
        return [];
    }
    $raw = file_get_contents($path);
    if ($raw === false || $raw === '') {
        return [];
    }

    $entries = [];
    foreach (explode("\n", $raw) as $line) {
        if ($line === '') {
            continue;
        }
        $data = json_decode($line, true);
        if (is_array($data)) {
            $entries[] = $data;
        }
    }
    return array_slice(array_reverse($entries), 0, $limit);
}
