<?php
// Fluxus Connect — API pubblica v1: elenco sorgenti. GET, autenticato con
// una sotto-chiave di scope 'follow' o 'follow+control' — stesso scope di
// follow/status.php.
//
// Specchio di sola lettura di quanto pubblicato dal Pi via
// public/api/pi/sources.php. Whitelist in lettura riapplicata qui, difesa
// in profondità, stesso principio degli altri endpoint follow/*.

require_once __DIR__ . '/../../../../includes/api_public.php';

const FC_SOURCE_FIELDS = ['id', 'name', 'media_type', 'active'];

fcApiRequireMethod('GET');
[$tenantHash] = fcApiRequireSubkey(['follow', 'follow+control']);

$sources = [];
foreach (fcReadCatalog($tenantHash, 'sources') as $entry) {
    if (!is_array($entry)) {
        continue;
    }
    $source = [];
    foreach (FC_SOURCE_FIELDS as $field) {
        if (array_key_exists($field, $entry)) {
            $source[$field] = $entry[$field];
        }
    }
    $sources[] = $source;
}

fcApiJsonResponse(200, ['sources' => $sources]);
