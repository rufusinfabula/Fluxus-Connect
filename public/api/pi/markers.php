<?php
// Fluxus Connect — API riservata al Pi: specchio pieno di marker/cue.
// POST, autenticato col token di primo livello del Pi. Ogni chiamata (ogni
// 30s, da connect_catalog_sync.php sul Pi) sostituisce per intero
// l'elenco marker/cue noto a Connect per questo tenant — uno specchio, non
// una coda né un diff incrementale. Nessun controllo di integrità
// referenziale su recording_id: un marker che punta a una registrazione
// non (più) nota a Connect è innocuo, non un errore da scartare o
// segnalare (vedi anche recordings.php, che pubblica lo storico a parte).

require_once __DIR__ . '/../../../includes/api.php';

const FC_MARKER_TYPES = ['marker', 'cue'];

fcApiRequireMethod('POST');
$tenantHash = fcApiRequireTenant();
$payload = fcApiReadJsonBody();

$input = $payload['markers'] ?? null;
if (!is_array($input) || !array_is_list($input)) {
    fcApiError(400, "campo 'markers' obbligatorio: un array (anche vuoto)");
}

$markers = [];
$seenIds = [];

foreach ($input as $index => $entry) {
    if (!is_array($entry)) {
        fcApiError(400, "markers[{$index}] deve essere un oggetto");
    }
    $context = "markers[{$index}]";

    $id = fcApiRequireEntryId($entry, 'id', $context);
    if (isset($seenIds[$id])) {
        fcApiError(400, "{$context}.id duplicato: {$id}");
    }
    $seenIds[$id] = true;

    $markers[] = [
        'id' => $id,
        'recording_id' => fcApiRequireEntryId($entry, 'recording_id', $context),
        'elapsed_seconds' => fcApiRequireEntryInt($entry, 'elapsed_seconds', $context),
        'elapsed_hms' => fcApiRequireEntryString($entry, 'elapsed_hms', $context),
        'absolute_time' => fcApiRequireEntryString($entry, 'absolute_time', $context),
        'label' => fcApiRequireEntryNullableString($entry, 'label', $context),
        'type' => fcApiRequireEntryEnum($entry, 'type', FC_MARKER_TYPES, $context),
        'clip_status' => fcApiRequireEntryNullableString($entry, 'clip_status', $context),
        'origin' => fcApiRequireEntryNullableString($entry, 'origin', $context),
        'origin_label' => fcApiRequireEntryNullableString($entry, 'origin_label', $context),
        'created_at' => fcApiRequireEntryString($entry, 'created_at', $context),
    ];
}

fcWriteCatalog($tenantHash, 'markers', $markers);

fcApiJsonResponse(200, ['ok' => true, 'count' => count($markers), 'received_at' => fcTimestamp()]);
