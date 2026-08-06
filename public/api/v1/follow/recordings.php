<?php
// Fluxus Connect — API pubblica v1: elenco/dettaglio registrazioni. GET,
// autenticato con una sotto-chiave di scope 'follow' o 'follow+control' —
// stesso scope già in vigore per follow/status.php, nessuno scope nuovo
// introdotto.
//
// Specchio di sola lettura di quanto pubblicato dal Pi via
// public/api/pi/recordings.php. Whitelist in lettura riapplicata qui,
// difesa in profondità (stesso principio di follow/status.php): solo i
// campi elencati sotto escono mai in risposta, anche se per errore
// finissero su disco.
//
// Nessun instradamento dinamico nel progetto (ogni endpoint è un file
// .php letterale, vedi .htaccess): il dettaglio di una singola
// registrazione passa dal parametro di query 'id', non da un segmento di
// percorso — 404 se non corrisponde a nessuna registrazione nota. Senza
// 'id', filtri opzionali in query string: media_type, source_id, status.

require_once __DIR__ . '/../../../../includes/api_public.php';

const FC_RECORDING_FIELDS = [
    'id', 'source_id', 'source_name', 'media_type', 'status',
    'start_time', 'end_time', 'duration_seconds', 'marker_count', 'clip_count',
];
const FC_RECORDING_MEDIA_TYPES = ['audio', 'video', 'clock'];
const FC_RECORDING_STATUSES = ['recording', 'completed', 'failed'];

fcApiRequireMethod('GET');
[$tenantHash] = fcApiRequireSubkey(['follow', 'follow+control']);

$recordings = [];
foreach (fcReadCatalog($tenantHash, 'recordings') as $entry) {
    if (!is_array($entry)) {
        continue;
    }
    $recording = [];
    foreach (FC_RECORDING_FIELDS as $field) {
        if (array_key_exists($field, $entry)) {
            $recording[$field] = $entry[$field];
        }
    }
    $recordings[] = $recording;
}

$id = $_GET['id'] ?? null;
if ($id !== null) {
    if (!is_string($id) || $id === '') {
        fcApiError(400, "parametro 'id' non valido");
    }
    foreach ($recordings as $recording) {
        if (($recording['id'] ?? null) === $id) {
            fcApiJsonResponse(200, $recording);
        }
    }
    fcApiError(404, "nessuna registrazione nota con id: {$id}");
}

$mediaType = $_GET['media_type'] ?? null;
if ($mediaType !== null && (!is_string($mediaType) || !in_array($mediaType, FC_RECORDING_MEDIA_TYPES, true))) {
    fcApiError(400, "parametro 'media_type' non valido: ammessi solo " . implode(', ', FC_RECORDING_MEDIA_TYPES));
}
$statusFilter = $_GET['status'] ?? null;
if ($statusFilter !== null && (!is_string($statusFilter) || !in_array($statusFilter, FC_RECORDING_STATUSES, true))) {
    fcApiError(400, "parametro 'status' non valido: ammessi solo " . implode(', ', FC_RECORDING_STATUSES));
}
$sourceId = $_GET['source_id'] ?? null;
if ($sourceId !== null && !is_string($sourceId)) {
    fcApiError(400, "parametro 'source_id' non valido");
}

$filtered = array_values(array_filter($recordings, function (array $recording) use ($mediaType, $sourceId, $statusFilter) {
    if ($mediaType !== null && ($recording['media_type'] ?? null) !== $mediaType) {
        return false;
    }
    if ($sourceId !== null && ($recording['source_id'] ?? null) !== $sourceId) {
        return false;
    }
    if ($statusFilter !== null && ($recording['status'] ?? null) !== $statusFilter) {
        return false;
    }
    return true;
}));

fcApiJsonResponse(200, ['recordings' => $filtered]);
