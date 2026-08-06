<?php
// Fluxus Connect — pannello: dati in tempo reale per la card "Comunicazione
// in tempo reale" nella pagina di dettaglio istanza (public/tenant.php).
// Sola lettura, autenticato dalla sessione del proprietario come il resto
// del pannello — non è né l'API pubblica v1 (sotto-chiave) né quella
// riservata al Pi (token di primo livello): è solo lo specchio di ciò che
// il pannello mostrerebbe comunque, interrogato dal JS della pagina a
// intervalli mentre la card è aperta, per verificare a colpo d'occhio che
// l'istanza sia davvero collegata.

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/includes/session.php';

fcSessionStart();
fcRequireLogin();

header('Content-Type: application/json; charset=utf-8');

$tenantHash = (string) ($_GET['id'] ?? '');
if (!preg_match('/^[0-9a-f]{64}$/', $tenantHash) || !fcTenantExists($tenantHash)) {
    http_response_code(404);
    echo json_encode(['error' => 'istanza non trovata']);
    exit;
}

echo json_encode([
    'status' => fcReadStatus($tenantHash),
    'queue' => fcQueueList($tenantHash),
], JSON_UNESCAPED_SLASHES);
