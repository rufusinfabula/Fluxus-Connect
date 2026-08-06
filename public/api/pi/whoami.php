<?php
// Fluxus Connect — API riservata al Pi: identità del tenant (controllo).
// GET, autenticato col token di primo livello del Pi. Contratto già fissato
// dal lato Fluxus (fmConnectTest() in includes/helpers.php di fluxus-src,
// dietro il pulsante "Testa connessione" delle Impostazioni): 200 JSON
// {"subkeys": [...]} coi nomi delle sotto-chiavi attive del tenant (array
// vuoto se nessuna), 401 per token mancante/malformato/sconosciuto come
// gli altri endpoint /api/pi/*. Solo attive: una sotto-chiave revocata non
// può più autenticarsi su Connect, elencarla qui suggerirebbe una console
// ancora collegata che in realtà non lo è più.

require_once __DIR__ . '/../../../includes/api.php';

fcApiRequireMethod('GET');
$tenantHash = fcApiRequireTenant();

$subkeys = [];
foreach (fcListSubkeys($tenantHash) as $subkey) {
    if (empty($subkey['revoked_at']) && is_string($subkey['name'] ?? null)) {
        $subkeys[] = $subkey['name'];
    }
}

fcApiJsonResponse(200, ['subkeys' => $subkeys]);
