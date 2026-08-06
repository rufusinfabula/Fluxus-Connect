<?php
// Fluxus Connect — api.php
// Helper condivisi per gli endpoint HTTP riservati al Pi (Fase 3). Il Pi si
// autentica sempre col proprio token di primo livello (Authorization:
// Bearer <token>) — mai con una sotto-chiave (quelle sono per le console
// esterne, Fase 4) né con la sessione da browser del pannello (quella è
// solo per il proprietario, vedi public/includes/session.php).

require_once __DIR__ . '/bootstrap.php';

// L'header Authorization non arriva sempre in $_SERVER['HTTP_AUTHORIZATION']
// — a seconda di come il server è configurato (PHP-FPM, CGI) può servire
// REDIRECT_HTTP_AUTHORIZATION o getallheaders(). Si prova in ordine finché
// non se ne trova uno.
function fcApiBearerToken(): ?string
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null;
    if ($header === null && function_exists('getallheaders')) {
        foreach (getallheaders() as $name => $value) {
            if (strcasecmp($name, 'Authorization') === 0) {
                $header = $value;
                break;
            }
        }
    }
    if (!is_string($header)) {
        return null;
    }
    if (!preg_match('/^Bearer\s+(\S+)$/i', trim($header), $matches)) {
        return null;
    }
    return $matches[1];
}

function fcApiJsonResponse(int $status, array $body): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
}

function fcApiError(int $status, string $message): never
{
    fcApiJsonResponse($status, ['error' => $message]);
}

function fcApiRequireMethod(string $method): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== $method) {
        fcApiError(405, "metodo non consentito, atteso {$method}");
    }
}

// Autentica il Pi dal proprio token di primo livello. L'hash del token
// coincide sempre col nome della cartella del tenant (fcCreateTenant in
// includes/tenants.php salva token_hash = hash del token generato, che è
// anche il nome della cartella): non serve confrontare contro un elenco,
// basta derivare l'hash e verificare che quella cartella esista davvero —
// con un controllo di coerenza sul contenuto di meta.json a scopo di
// difesa in profondità, mai un confronto diretto sull'hash.
function fcApiRequireTenant(): string
{
    $token = fcApiBearerToken();
    if ($token === null || $token === '') {
        fcApiError(401, 'token mancante o malformato (atteso: Authorization: Bearer <token>)');
    }

    $tenantHash = fcHashToken($token);
    $meta = fcReadTenantMeta($tenantHash);
    if ($meta === null || !hash_equals((string) ($meta['token_hash'] ?? ''), $tenantHash)) {
        fcApiError(401, 'token non valido');
    }

    return $tenantHash;
}

function fcApiReadJsonBody(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        fcApiError(400, 'corpo della richiesta mancante o vuoto');
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        fcApiError(400, 'corpo della richiesta non è un oggetto JSON valido');
    }
    return $data;
}

// --- Validatori di campo per elementi di un elenco (cataloghi del Pi) ----
// Condivisi da recordings.php/markers.php/sources.php/schedules.php
// (specchi pieni pubblicati dal Pi ogni 30s): stessa forma ripetuta
// identica in ciascuno dei quattro, vale la pena di fattorizzarla qui
// invece che nei singoli file. $context è il prefisso descrittivo
// dell'elemento nel messaggio d'errore (es. "recordings[3]").

// Stringa obbligatoria non vuota: per i campi usati come identificativo
// (id, e i vari *_id) — trim applicato, coerente con l'id delle
// registrazioni in status.php.
function fcApiRequireEntryId(array $entry, string $field, string $context): string
{
    $value = $entry[$field] ?? null;
    if (!is_string($value) || trim($value) === '') {
        fcApiError(400, "{$context}.{$field} obbligatorio e non vuoto");
    }
    return trim($value);
}

// Stringa obbligatoria, salvata così com'è (niente trim): per campi non
// identificativi (nomi, timestamp, espressioni opache) dove non spetta a
// Connect deciderne il formato.
function fcApiRequireEntryString(array $entry, string $field, string $context): string
{
    $value = $entry[$field] ?? null;
    if (!is_string($value)) {
        fcApiError(400, "{$context}.{$field} obbligatorio e deve essere una stringa");
    }
    return $value;
}

// Come fcApiRequireEntryString, ma la chiave deve essere presente anche
// quando il valore è null.
function fcApiRequireEntryNullableString(array $entry, string $field, string $context): ?string
{
    if (!array_key_exists($field, $entry)) {
        fcApiError(400, "{$context}.{$field} obbligatorio (stringa o null)");
    }
    $value = $entry[$field];
    if ($value !== null && !is_string($value)) {
        fcApiError(400, "{$context}.{$field} deve essere una stringa o null");
    }
    return $value;
}

function fcApiRequireEntryInt(array $entry, string $field, string $context): int
{
    $value = $entry[$field] ?? null;
    if (!is_int($value) || $value < 0) {
        fcApiError(400, "{$context}.{$field} deve essere un intero non negativo");
    }
    return $value;
}

function fcApiRequireEntryNullableInt(array $entry, string $field, string $context): ?int
{
    if (!array_key_exists($field, $entry)) {
        fcApiError(400, "{$context}.{$field} obbligatorio (intero o null)");
    }
    $value = $entry[$field];
    if ($value !== null && (!is_int($value) || $value < 0)) {
        fcApiError(400, "{$context}.{$field} deve essere un intero non negativo o null");
    }
    return $value;
}

function fcApiRequireEntryBool(array $entry, string $field, string $context): bool
{
    $value = $entry[$field] ?? null;
    if (!is_bool($value)) {
        fcApiError(400, "{$context}.{$field} deve essere un booleano");
    }
    return $value;
}

function fcApiRequireEntryEnum(array $entry, string $field, array $allowed, string $context): string
{
    $value = $entry[$field] ?? null;
    if (!is_string($value) || !in_array($value, $allowed, true)) {
        fcApiError(400, "{$context}.{$field} obbligatorio: ammessi solo " . implode(', ', $allowed));
    }
    return $value;
}
