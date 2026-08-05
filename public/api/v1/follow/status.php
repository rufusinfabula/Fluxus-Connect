<?php
// Fluxus Connect — API pubblica v1: lettura dello stato (follow). GET,
// autenticato con una sotto-chiave di scope 'follow' o 'follow+control'
// (chi può controllare può sempre anche seguire). Vedi
// docs/NOTE-TECNICHE.md, "Livello 1 — Follow": specchio di sola lettura
// dello stato pubblicato dal Pi via public/api/pi/status.php, mai
// un'estensione della sua configurazione interna.
//
// status.php (Fase 3) whitelist già i campi in scrittura, ma qui si
// whitelista di nuovo in lettura — difesa in profondità, stesso principio
// di fcApiRequireTenant in includes/api.php: non fidarsi che un vincolo
// imposto altrove resti vero per sempre. Non finiscono mai in risposta:
// percorsi filesystem, PID, note interne, configurazione delle sorgenti.
//
// Fluxus può registrare da più sorgenti insieme: 'registrations' riporta
// tutte quelle attualmente attive (anche vuoto). I campi in cima
// (state/id/source/...) restano per compatibilità con chi ha integrato
// prima di questa estensione e continuano a rispecchiare la prima
// registrazione dell'elenco così come pubblicata dal Pi — non una scelta
// di Connect su quale sia "la" registrazione che conta. Con più di una
// registrazione attiva quello specchio in cima non basta più a
// rappresentare la situazione per intero: chi vuole vederle tutte, o scegliere
// a quale indirizzare un comando (vedi control/commands.php), deve leggere
// 'registrations'. Vedi docs/NOTE-TECNICHE.md, "Follow multi-registrazione"
// per il perché di questa forma additiva invece di aprire /api/v2/.

require_once __DIR__ . '/../../../../includes/api_public.php';

fcApiRequireMethod('GET');
[$tenantHash] = fcApiRequireSubkey(['follow', 'follow+control']);

$meta = fcReadTenantMeta($tenantHash);
$status = fcReadStatus($tenantHash);

$registrationFields = ['id', 'state', 'source', 'media_type', 'started_at', 'elapsed_seconds', 'marker_count'];

$registrations = [];
if ($status !== null && is_array($status['registrations'] ?? null)) {
    foreach ($status['registrations'] as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $registration = [];
        foreach ($registrationFields as $field) {
            if (array_key_exists($field, $entry)) {
                $registration[$field] = $entry[$field];
            }
        }
        $registrations[] = $registration;
    }
}

$response = [
    'node_name' => $meta['name'] ?? null,
    'state' => $registrations[0]['state'] ?? 'unknown',
    'registrations' => $registrations,
];

if (isset($registrations[0])) {
    foreach ($registrationFields as $field) {
        if (array_key_exists($field, $registrations[0])) {
            $response[$field] = $registrations[0][$field];
        }
    }
}

if ($status !== null && array_key_exists('received_at', $status)) {
    $response['received_at'] = $status['received_at'];
}

fcApiJsonResponse(200, $response);
