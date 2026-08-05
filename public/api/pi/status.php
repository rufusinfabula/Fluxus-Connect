<?php
// Fluxus Connect — API riservata al Pi: pubblicazione dello stato (follow).
// POST, autenticato col token di primo livello del Pi. Vedi
// docs/NOTE-TECNICHE.md, "Livello 1 — Follow": campi previsti e, soprattutto,
// cosa non va mai esposto (percorsi filesystem, PID, note interne,
// configurazione delle sorgenti). Questo endpoint accetta solo i campi
// whitelisted qui sotto — tutto il resto del corpo viene scartato in
// silenzio, così status.json non può mai contenere più di quanto la Fase 4
// dovrà un giorno restituire in lettura.
//
// Fluxus può registrare da più sorgenti contemporaneamente: il Pi pubblica
// un elenco di registrazioni attive ('registrations', anche vuoto se non
// sta registrando nulla), non più un singolo stato scalare — vedi
// docs/NOTE-TECNICHE.md, "Follow multi-registrazione" per il perché di
// questa forma. Ogni registrazione richiede un 'id' stabile: è il
// bersaglio che le console useranno per indirizzare un comando a una
// registrazione precisa (vedi public/api/v1/control/commands.php).

require_once __DIR__ . '/../../../includes/api.php';

fcApiRequireMethod('POST');
$tenantHash = fcApiRequireTenant();
$payload = fcApiReadJsonBody();

$registrationsInput = $payload['registrations'] ?? null;
if (!is_array($registrationsInput) || !array_is_list($registrationsInput)) {
    fcApiError(400, "campo 'registrations' obbligatorio: un array (anche vuoto, se nessuna registrazione è attiva)");
}

$registrations = [];
$seenIds = [];

foreach ($registrationsInput as $index => $entry) {
    if (!is_array($entry)) {
        fcApiError(400, "registrations[{$index}] deve essere un oggetto");
    }

    $id = $entry['id'] ?? null;
    if (!is_string($id) || trim($id) === '') {
        fcApiError(400, "registrations[{$index}].id obbligatorio e non vuoto");
    }
    $id = trim($id);
    if (isset($seenIds[$id])) {
        fcApiError(400, "registrations[{$index}].id duplicato: {$id}");
    }
    $seenIds[$id] = true;

    $state = $entry['state'] ?? null;
    if (!is_string($state) || trim($state) === '') {
        fcApiError(400, "registrations[{$index}].state obbligatorio e non vuoto");
    }

    $registration = ['id' => $id, 'state' => $state];

    foreach (['source', 'media_type', 'started_at'] as $field) {
        if (array_key_exists($field, $entry)) {
            if (!is_string($entry[$field])) {
                fcApiError(400, "registrations[{$index}].{$field} deve essere una stringa");
            }
            $registration[$field] = $entry[$field];
        }
    }

    foreach (['elapsed_seconds', 'marker_count'] as $field) {
        if (array_key_exists($field, $entry)) {
            if (!is_int($entry[$field]) || $entry[$field] < 0) {
                fcApiError(400, "registrations[{$index}].{$field} deve essere un intero non negativo");
            }
            $registration[$field] = $entry[$field];
        }
    }

    $registrations[] = $registration;
}

$status = [
    'registrations' => $registrations,
    'received_at' => fcTimestamp(),
];

fcWriteStatus($tenantHash, $status);

fcApiJsonResponse(200, ['ok' => true, 'received_at' => $status['received_at']]);
