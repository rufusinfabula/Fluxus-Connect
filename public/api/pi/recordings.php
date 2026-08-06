<?php
// Fluxus Connect — API riservata al Pi: specchio pieno dello storico
// registrazioni. POST, autenticato col token di primo livello del Pi.
// Ogni chiamata (ogni 30s, da connect_catalog_sync.php sul Pi) sostituisce
// per intero lo storico noto a Connect per questo tenant — uno specchio,
// non una coda né un diff incrementale, stesso principio di status.php.
//
// Nessuna whitelist in scrittura qui (a differenza di status.php): il Pi
// esclude già alla fonte percorsi filesystem, PID e note interne. I
// validatori di campo condivisi con markers.php/sources.php/schedules.php
// stanno in includes/api.php.

require_once __DIR__ . '/../../../includes/api.php';

const FC_RECORDING_MEDIA_TYPES = ['audio', 'video', 'clock'];
const FC_RECORDING_STATUSES = ['recording', 'completed', 'failed'];

fcApiRequireMethod('POST');
$tenantHash = fcApiRequireTenant();
$payload = fcApiReadJsonBody();

$input = $payload['recordings'] ?? null;
if (!is_array($input) || !array_is_list($input)) {
    fcApiError(400, "campo 'recordings' obbligatorio: un array (anche vuoto)");
}

$recordings = [];
$seenIds = [];

foreach ($input as $index => $entry) {
    if (!is_array($entry)) {
        fcApiError(400, "recordings[{$index}] deve essere un oggetto");
    }
    $context = "recordings[{$index}]";

    $id = fcApiRequireEntryId($entry, 'id', $context);
    if (isset($seenIds[$id])) {
        fcApiError(400, "{$context}.id duplicato: {$id}");
    }
    $seenIds[$id] = true;

    $recordings[] = [
        'id' => $id,
        'source_id' => fcApiRequireEntryId($entry, 'source_id', $context),
        'source_name' => fcApiRequireEntryString($entry, 'source_name', $context),
        'media_type' => fcApiRequireEntryEnum($entry, 'media_type', FC_RECORDING_MEDIA_TYPES, $context),
        'status' => fcApiRequireEntryEnum($entry, 'status', FC_RECORDING_STATUSES, $context),
        'start_time' => fcApiRequireEntryString($entry, 'start_time', $context),
        'end_time' => fcApiRequireEntryString($entry, 'end_time', $context),
        'duration_seconds' => fcApiRequireEntryNullableInt($entry, 'duration_seconds', $context),
        'marker_count' => fcApiRequireEntryInt($entry, 'marker_count', $context),
        'clip_count' => fcApiRequireEntryInt($entry, 'clip_count', $context),
    ];
}

fcWriteCatalog($tenantHash, 'recordings', $recordings);

fcApiJsonResponse(200, ['ok' => true, 'count' => count($recordings), 'received_at' => fcTimestamp()]);
