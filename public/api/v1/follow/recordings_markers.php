<?php
// Fluxus Connect — API pubblica v1: marker/cue di una registrazione. GET,
// autenticato con una sotto-chiave di scope 'follow' o 'follow+control' —
// stesso scope di follow/status.php e follow/recordings.php.
//
// Specchio di sola lettura di quanto pubblicato dal Pi via
// public/api/pi/markers.php, filtrato per 'id' di registrazione
// (parametro di query obbligatorio — nessun instradamento dinamico nel
// progetto, vedi follow/recordings.php). Nessun controllo che l'id
// corrisponda a una registrazione nota: un marker con un recording_id
// "orfano" è innocuo (vedi public/api/pi/markers.php), quindi si applica
// solo il filtro, mai un 404 sull'id della registrazione. Filtro
// opzionale aggiuntivo: 'type' (marker|cue).
//
// Whitelist in lettura riapplicata qui, difesa in profondità, stesso
// principio degli altri endpoint follow/*.

require_once __DIR__ . '/../../../../includes/api_public.php';

const FC_MARKER_FIELDS = [
    'id', 'recording_id', 'elapsed_seconds', 'elapsed_hms', 'absolute_time',
    'label', 'type', 'clip_status', 'origin', 'origin_label', 'created_at',
];
const FC_MARKER_TYPES = ['marker', 'cue'];

fcApiRequireMethod('GET');
[$tenantHash] = fcApiRequireSubkey(['follow', 'follow+control']);

$recordingId = $_GET['id'] ?? null;
if (!is_string($recordingId) || $recordingId === '') {
    fcApiError(400, "parametro 'id' obbligatorio (id della registrazione)");
}

$typeFilter = $_GET['type'] ?? null;
if ($typeFilter !== null && (!is_string($typeFilter) || !in_array($typeFilter, FC_MARKER_TYPES, true))) {
    fcApiError(400, "parametro 'type' non valido: ammessi solo " . implode(', ', FC_MARKER_TYPES));
}

$markers = [];
foreach (fcReadCatalog($tenantHash, 'markers') as $entry) {
    if (!is_array($entry) || ($entry['recording_id'] ?? null) !== $recordingId) {
        continue;
    }
    if ($typeFilter !== null && ($entry['type'] ?? null) !== $typeFilter) {
        continue;
    }
    $marker = [];
    foreach (FC_MARKER_FIELDS as $field) {
        if (array_key_exists($field, $entry)) {
            $marker[$field] = $entry[$field];
        }
    }
    $markers[] = $marker;
}

fcApiJsonResponse(200, ['markers' => $markers]);
