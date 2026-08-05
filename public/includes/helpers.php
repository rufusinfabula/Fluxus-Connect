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

function fcDescribeLogEvent(array $entry): string
{
    $event = $entry['event'] ?? '';
    $ctx = is_array($entry['context'] ?? null) ? $entry['context'] : [];
    return match ($event) {
        'tenant_created' => 'Pi creato: ' . ($ctx['name'] ?? ''),
        'subkey_created' => 'Sotto-chiave creata: ' . ($ctx['name'] ?? '') . ' (' . ($ctx['scope'] ?? '') . ')',
        'subkey_revoked' => 'Sotto-chiave revocata: ' . ($ctx['name'] ?? ''),
        'command_enqueued' => 'Comando depositato da ' . ($ctx['subkey_name'] ?? 'una console') . ': '
            . ($ctx['type'] ?? '') . (($ctx['label'] ?? '') !== '' ? ' — ' . $ctx['label'] : '')
            . (($ctx['target_id'] ?? '') !== '' ? ' (su ' . $ctx['target_id'] . ')' : ''),
        'command_acknowledged' => 'Comando eseguito e confermato dal Pi: ' . ($ctx['id'] ?? ''),
        default => $event !== '' ? $event : 'evento sconosciuto',
    };
}
