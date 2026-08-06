<?php
// Fluxus Connect — pannello: helper di presentazione.

function fcE(?string $s): string
{
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}

// Legge un valore "usa e getta" dalla sessione (es. il token appena
// generato, mostrato una sola volta) e lo rimuove subito dopo — un
// secondo refresh della stessa pagina non lo mostra più.
function fcFlash(string $key): mixed
{
    $value = $_SESSION[$key] ?? null;
    unset($_SESSION[$key]);
    return $value;
}

// Indirizzo assoluto dell'API riservata al Pi (Fase 3), calcolato dalla
// richiesta corrente invece che scritto a mano da qualche parte: su hosting
// economico Connect può vivere alla radice del dominio o in una
// sottocartella (se il document root non è configurabile su public/, vedi
// docs/NOTE-TECNICHE.md), quindi solo la richiesta in corso sa davvero dove
// si trova. Va copiato così com'è nel file di segreti del Pi (Fase 6, vive
// in fluxus-src) — meno probabilità di sbagliarlo a mano.
function fcApiPiBaseUrl(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scriptDir = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/tenant.php'))), '/');
    return "{$scheme}://{$host}{$scriptDir}/api/pi/";
}

function fcDescribeLogEvent(array $entry): string
{
    $event = $entry['event'] ?? '';
    $ctx = is_array($entry['context'] ?? null) ? $entry['context'] : [];
    return match ($event) {
        'tenant_created' => 'Istanza creata: ' . ($ctx['name'] ?? ''),
        'tenant_token_regenerated' => 'Token rigenerato: ' . ($ctx['name'] ?? ''),
        'subkey_created' => 'Sotto-chiave creata: ' . ($ctx['name'] ?? '') . ' (' . ($ctx['scope'] ?? '') . ')',
        'subkey_revoked' => 'Sotto-chiave revocata: ' . ($ctx['name'] ?? ''),
        'command_enqueued' => 'Comando depositato da ' . ($ctx['subkey_name'] ?? 'una console') . ': '
            . ($ctx['type'] ?? '') . (($ctx['label'] ?? '') !== '' ? ' — ' . $ctx['label'] : '')
            . (($ctx['target_id'] ?? '') !== '' ? ' (su ' . $ctx['target_id'] . ')' : ''),
        'command_acknowledged' => 'Comando eseguito e confermato dall\'istanza: ' . ($ctx['id'] ?? ''),
        default => $event !== '' ? $event : 'evento sconosciuto',
    };
}
