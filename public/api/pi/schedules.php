<?php
// Fluxus Connect — API riservata al Pi: specchio pieno degli orari
// programmati. POST, autenticato col token di primo livello del Pi. Ogni
// chiamata (ogni 30s, da connect_catalog_sync.php sul Pi) sostituisce per
// intero l'elenco orari noto a Connect per questo tenant.

require_once __DIR__ . '/../../../includes/api.php';

fcApiRequireMethod('POST');
$tenantHash = fcApiRequireTenant();
$payload = fcApiReadJsonBody();

$input = $payload['schedules'] ?? null;
if (!is_array($input) || !array_is_list($input)) {
    fcApiError(400, "campo 'schedules' obbligatorio: un array (anche vuoto)");
}

$schedules = [];
$seenIds = [];

foreach ($input as $index => $entry) {
    if (!is_array($entry)) {
        fcApiError(400, "schedules[{$index}] deve essere un oggetto");
    }
    $context = "schedules[{$index}]";

    $id = fcApiRequireEntryId($entry, 'id', $context);
    if (isset($seenIds[$id])) {
        fcApiError(400, "{$context}.id duplicato: {$id}");
    }
    $seenIds[$id] = true;

    $schedules[] = [
        'id' => $id,
        'source_id' => fcApiRequireEntryId($entry, 'source_id', $context),
        'source_name' => fcApiRequireEntryString($entry, 'source_name', $context),
        'label' => fcApiRequireEntryString($entry, 'label', $context),
        'on_calendar' => fcApiRequireEntryString($entry, 'on_calendar', $context),
        'slot_duration' => fcApiRequireEntryInt($entry, 'slot_duration', $context),
        'active' => fcApiRequireEntryBool($entry, 'active', $context),
    ];
}

fcWriteCatalog($tenantHash, 'schedules', $schedules);

fcApiJsonResponse(200, ['ok' => true, 'count' => count($schedules), 'received_at' => fcTimestamp()]);
