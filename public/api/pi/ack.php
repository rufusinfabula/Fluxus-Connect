<?php
// Fluxus Connect — API riservata al Pi: conferma/rimozione di un comando
// dopo l'esecuzione. POST, autenticato col token di primo livello del Pi.
// Idempotente (vedi includes/storage.php, fcQueueRemove): confermare due
// volte lo stesso id non è un errore — "removed" segnala solo se questa
// specifica chiamata ha trovato ed eliminato il file.

require_once __DIR__ . '/../../../includes/api.php';

fcApiRequireMethod('POST');
$tenantHash = fcApiRequireTenant();
$payload = fcApiReadJsonBody();

$id = $payload['id'] ?? null;
if (!is_string($id) || $id === '') {
    fcApiError(400, "campo 'id' obbligatorio");
}

try {
    $removed = fcQueueRemove($tenantHash, $id);
} catch (InvalidArgumentException $e) {
    fcApiError(400, 'id di coda non valido');
}

if ($removed) {
    fcLogAppend($tenantHash, 'command_acknowledged', ['id' => $id]);
}

fcApiJsonResponse(200, ['ok' => true, 'removed' => $removed]);
