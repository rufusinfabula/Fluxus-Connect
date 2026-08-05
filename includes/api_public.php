<?php
// Fluxus Connect — helper per l'API pubblica (Fase 4): sistemi/console terzi
// autenticati con una sotto-chiave, mai col token di primo livello del Pi
// (quello resta riservato a includes/api.php, fcApiRequireTenant — vedi
// docs/NOTE-TECNICHE.md, "Chi genera i token, e perché"). Riusa da api.php
// solo i pezzi generici (bearer token, risposta/errore JSON, metodo
// atteso): l'autenticazione qui sotto è specifica alle sotto-chiavi.

require_once __DIR__ . '/api.php';

// Autentica la sotto-chiave dal proprio bearer token e verifica che abbia
// uno degli scope richiesti — mai fidarsi ciecamente del solo hash trovato:
// una sotto-chiave revocata resta su disco (fcRevokeSubkey non cancella,
// vedi includes/subkeys.php) proprio per restare nel log di attività, quindi
// va sempre controllato revoked_at, non solo l'esistenza del file.
//
// Restituisce [tenantHash, subkeyHash, subkeyData] a chi chiama, cosicché
// ogni endpoint possa registrare nel log con il nome della console corretto
// senza dover rileggere la sotto-chiave una seconda volta.
function fcApiRequireSubkey(array $allowedScopes): array
{
    $token = fcApiBearerToken();
    if ($token === null || $token === '') {
        fcApiError(401, 'sotto-chiave mancante o malformata (atteso: Authorization: Bearer <sotto-chiave>)');
    }

    $subkeyHash = fcHashToken($token);
    $found = fcFindSubkeyTenant($subkeyHash);
    if ($found === null) {
        fcApiError(401, 'sotto-chiave non valida');
    }

    $tenantHash = $found['tenant_hash'];
    $subkey = $found['subkey'];

    if (!empty($subkey['revoked_at'])) {
        fcApiError(401, 'sotto-chiave revocata');
    }
    if (!in_array($subkey['scope'] ?? '', $allowedScopes, true)) {
        fcApiError(403, 'sotto-chiave senza i permessi necessari per questa richiesta');
    }

    fcTouchSubkeyLastUsed($tenantHash, $subkeyHash);

    return [$tenantHash, $subkeyHash, $subkey];
}
