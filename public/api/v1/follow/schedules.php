<?php
// Fluxus Connect — API pubblica v1: elenco orari programmati. GET,
// autenticato con una sotto-chiave di scope 'follow' o 'follow+control' —
// stesso scope di follow/status.php.
//
// Specchio di sola lettura di quanto pubblicato dal Pi via
// public/api/pi/schedules.php. Whitelist in lettura riapplicata qui,
// difesa in profondità, stesso principio degli altri endpoint follow/*.

require_once __DIR__ . '/../../../../includes/api_public.php';

const FC_SCHEDULE_FIELDS = [
    'id', 'source_id', 'source_name', 'label', 'on_calendar', 'slot_duration', 'active',
];

fcApiRequireMethod('GET');
[$tenantHash] = fcApiRequireSubkey(['follow', 'follow+control']);

$schedules = [];
foreach (fcReadCatalog($tenantHash, 'schedules') as $entry) {
    if (!is_array($entry)) {
        continue;
    }
    $schedule = [];
    foreach (FC_SCHEDULE_FIELDS as $field) {
        if (array_key_exists($field, $entry)) {
            $schedule[$field] = $entry[$field];
        }
    }
    $schedules[] = $schedule;
}

fcApiJsonResponse(200, ['schedules' => $schedules]);
