<?php
// Fluxus Connect — API riservata al Pi: specchio pieno del catalogo
// sorgenti. POST, autenticato col token di primo livello del Pi. Ogni
// chiamata (ogni 30s, da connect_catalog_sync.php sul Pi) sostituisce per
// intero il catalogo sorgenti noto a Connect per questo tenant.

require_once __DIR__ . '/../../../includes/api.php';

const FC_SOURCE_MEDIA_TYPES = ['audio', 'video', 'clock'];

fcApiRequireMethod('POST');
$tenantHash = fcApiRequireTenant();
$payload = fcApiReadJsonBody();

$input = $payload['sources'] ?? null;
if (!is_array($input) || !array_is_list($input)) {
    fcApiError(400, "campo 'sources' obbligatorio: un array (anche vuoto)");
}

$sources = [];
$seenIds = [];

foreach ($input as $index => $entry) {
    if (!is_array($entry)) {
        fcApiError(400, "sources[{$index}] deve essere un oggetto");
    }
    $context = "sources[{$index}]";

    $id = fcApiRequireEntryId($entry, 'id', $context);
    if (isset($seenIds[$id])) {
        fcApiError(400, "{$context}.id duplicato: {$id}");
    }
    $seenIds[$id] = true;

    $sources[] = [
        'id' => $id,
        'name' => fcApiRequireEntryString($entry, 'name', $context),
        'media_type' => fcApiRequireEntryEnum($entry, 'media_type', FC_SOURCE_MEDIA_TYPES, $context),
        'active' => fcApiRequireEntryBool($entry, 'active', $context),
    ];
}

fcWriteCatalog($tenantHash, 'sources', $sources);

fcApiJsonResponse(200, ['ok' => true, 'count' => count($sources), 'received_at' => fcTimestamp()]);
