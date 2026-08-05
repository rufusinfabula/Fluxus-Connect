<?php
// Fluxus Connect — API pubblica v1: deposito di un comando (controllo).
// POST, autenticato con una sotto-chiave di scope 'follow+control' — mai
// 'follow' da solo, vedi docs/NOTE-TECNICHE.md "Livello 2 — Controllo".
//
// Whitelist stretta sulle azioni accettate: solo marker/cue. Avvio/stop
// restano PENDING/FUTURO (raggio di danno troppo alto per il v1, vedi
// NOTE-TECNICHE.md) — non è un'omissione da completare qui.
//
// Stessa forma (type/label) di fmCreateMarker() in fluxus-src, per restare
// diretto da rigirare quando la Fase 6 (script sul Pi, non ancora
// costruita) leggerà questa coda e la passerà a quella funzione — ma non è
// un accoppiamento: se lo shape dovesse cambiare in un domani, resta comunque
// solo Connect a deciderlo, non fluxus-src.
//
// Bersaglio esplicito ('target_id'): con più registrazioni attive
// contemporaneamente, la scelta di quale riceve il comando spetta a chi
// chiama (la console), mai a un'euristica qui o sul Pi — vedi
// docs/NOTE-TECNICHE.md, "Comando con bersaglio esplicito". Whitelist
// stretta contro le registrazioni che follow/status.php espone in questo
// momento per lo stesso tenant, stessa forma della whitelist già in vigore
// su 'type'.

require_once __DIR__ . '/../../../../includes/api_public.php';

const FC_COMMAND_TYPES = ['marker', 'cue'];
const FC_COMMAND_LABEL_MAX_LENGTH = 500;

fcApiRequireMethod('POST');
[$tenantHash, , $subkey] = fcApiRequireSubkey(['follow+control']);
$payload = fcApiReadJsonBody();

$type = $payload['type'] ?? null;
if (!is_string($type) || !in_array($type, FC_COMMAND_TYPES, true)) {
    fcApiError(400, "campo 'type' obbligatorio: ammessi solo " . implode(', ', FC_COMMAND_TYPES));
}

$command = ['type' => $type];

if (array_key_exists('label', $payload)) {
    if (!is_string($payload['label'])) {
        fcApiError(400, "campo 'label' deve essere una stringa");
    }
    $label = trim($payload['label']);
    if (strlen($label) > FC_COMMAND_LABEL_MAX_LENGTH) {
        fcApiError(400, "campo 'label' troppo lungo (massimo " . FC_COMMAND_LABEL_MAX_LENGTH . ' caratteri)');
    }
    if ($label !== '') {
        $command['label'] = $label;
    }
}

$status = fcReadStatus($tenantHash);
$activeIds = [];
if ($status !== null && is_array($status['registrations'] ?? null)) {
    foreach ($status['registrations'] as $entry) {
        if (is_array($entry) && is_string($entry['id'] ?? null)) {
            $activeIds[] = $entry['id'];
        }
    }
}

$targetId = $payload['target_id'] ?? null;
if ($targetId !== null) {
    if (!is_string($targetId) || !in_array($targetId, $activeIds, true)) {
        $known = $activeIds === [] ? 'nessuna registrazione è attualmente attiva' : 'registrazioni attive: ' . implode(', ', $activeIds);
        fcApiError(400, "campo 'target_id' non valido: {$known}");
    }
    $command['target_id'] = $targetId;
} elseif (count($activeIds) > 1) {
    fcApiError(400, "campo 'target_id' obbligatorio: più di una registrazione è attualmente attiva (" . implode(', ', $activeIds) . ')');
} elseif (count($activeIds) === 1) {
    $command['target_id'] = $activeIds[0];
}

$command['subkey_name'] = $subkey['name'] ?? null;
$command['created_at'] = fcTimestamp();

$id = fcQueueEnqueue($tenantHash, $command);

fcLogAppend($tenantHash, 'command_enqueued', [
    'id' => $id,
    'type' => $type,
    'label' => $command['label'] ?? null,
    'target_id' => $command['target_id'] ?? null,
    'subkey_name' => $subkey['name'] ?? null,
]);

fcApiJsonResponse(200, ['ok' => true, 'id' => $id]);
