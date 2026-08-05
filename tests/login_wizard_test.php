<?php
// Fluxus Connect — test del wizard di creazione del proprietario al primo
// accesso (Fase X, docs/NOTE-TECNICHE.md "Configurazione del proprietario
// senza terminale"). A differenza di admin_test.php (funzioni pure), qui
// serve un server HTTP vero — public/login.php dipende da sessione,
// cookie, redirect e CSRF, non solo dalle funzioni di includes/owner.php
// già coperte altrove. Stesso metodo di tests/api_test.php: server
// integrato di PHP (`php -S`).
// Uso: php tests/login_wizard_test.php

$fcTestDir = sys_get_temp_dir() . '/fluxus-connect-login-wizard-test-' . bin2hex(random_bytes(4));
putenv("FC_DATA_DIR={$fcTestDir}");

require_once __DIR__ . '/../includes/bootstrap.php';

$fcPass = 0;
$fcFail = 0;

function fcCheck(string $label, bool $condition): void
{
    global $fcPass, $fcFail;
    if ($condition) {
        $fcPass++;
        echo "  ok  - {$label}\n";
    } else {
        $fcFail++;
        echo "FAIL  - {$label}\n";
    }
}

function fcRemoveDirRecursive(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = "{$dir}/{$item}";
        is_dir($path) ? fcRemoveDirRecursive($path) : unlink($path);
    }
    rmdir($dir);
}

// Richiesta HTTP via stream context (niente dipendenza da curl). A
// differenza dell'omologa in api_test.php, restituisce anche gli header di
// risposta (serve il cookie di sessione) e non segue i redirect da solo
// (serve verificare lo status 302 e la sua destinazione).
function fcHttpRequest(string $method, string $url, array $headers = [], ?string $body = null): array
{
    $context = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'content' => $body ?? '',
            'ignore_errors' => true,
            'timeout' => 5,
            'follow_location' => false,
        ],
    ]);
    $raw = @file_get_contents($url, false, $context);
    $status = 0;
    $responseHeaders = $http_response_header ?? [];
    foreach ($responseHeaders as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d+)#', $line, $m)) {
            $status = (int) $m[1];
        }
    }
    return [$status, $raw === false ? '' : $raw, $responseHeaders];
}

// Estrae "NOME=valore" dal primo Set-Cookie utile, da rimandare come
// header Cookie nella richiesta successiva.
function fcExtractCookie(array $responseHeaders): ?string
{
    foreach ($responseHeaders as $line) {
        if (stripos($line, 'Set-Cookie:') === 0) {
            $value = trim(substr($line, strlen('Set-Cookie:')));
            [$pair] = explode(';', $value, 2);
            return trim($pair);
        }
    }
    return null;
}

function fcExtractLocation(array $responseHeaders): ?string
{
    foreach ($responseHeaders as $line) {
        if (stripos($line, 'Location:') === 0) {
            return trim(substr($line, strlen('Location:')));
        }
    }
    return null;
}

// Il csrf token è un campo hidden nel form: <input ... name="csrf" value="...">
function fcExtractCsrf(string $body): ?string
{
    if (preg_match('/name="csrf" value="([0-9a-f]+)"/', $body, $m)) {
        return $m[1];
    }
    return null;
}

echo "Fluxus Connect — test wizard di creazione del proprietario\n";
echo "Sandbox: {$fcTestDir}\n\n";

// --- Avvio del server integrato di PHP -----------------------------------

$publicDir = __DIR__ . '/../public';
$port = 20000 + (getmypid() % 20000);
$host = "127.0.0.1:{$port}";

$env = array_merge(array_filter($_SERVER, 'is_string'), ['FC_DATA_DIR' => $fcTestDir]);
$descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$process = proc_open(['php', '-S', $host, '-t', $publicDir], $descriptors, $pipes, null, $env);

if (!is_resource($process)) {
    fwrite(STDERR, "impossibile avviare il server di test\n");
    exit(1);
}
foreach ($pipes as $pipe) {
    stream_set_blocking($pipe, false);
}

$ready = false;
for ($i = 0; $i < 40; $i++) {
    $conn = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
    if ($conn !== false) {
        fclose($conn);
        $ready = true;
        break;
    }
    usleep(50000);
}
fcCheck('il server di test risponde entro 2s', $ready);

$loginUrl = "http://{$host}/login.php";

// --- Nessun proprietario: la rotta mostra il form di creazione ------------

echo "\nPrima del primo accesso:\n";
[$status, $body, $headers] = fcHttpRequest('GET', $loginUrl);
$cookieA = fcExtractCookie($headers);
$csrfA = fcExtractCsrf($body);

fcCheck('GET /login.php senza proprietario: 200', $status === 200);
fcCheck('mostra il form di creazione', str_contains($body, 'Crea il proprietario del pannello'));
fcCheck('non mostra il form di login', !str_contains($body, '<h1>Accedi</h1>'));
fcCheck('un cookie di sessione è stato assegnato', $cookieA !== null);
fcCheck('un token CSRF è presente nel form', $csrfA !== null);

// --- CSRF applicato anche al form di creazione ----------------------------

echo "\nCSRF sul form di creazione:\n";
[$status, $body] = fcHttpRequest('POST', $loginUrl, [
    "Cookie: {$cookieA}",
    'Content-Type: application/x-www-form-urlencoded',
], http_build_query([
    'csrf' => 'token-inventato-non-valido',
    'username' => 'admin',
    'password' => 'una-password-lunga-abbastanza',
    'password_confirm' => 'una-password-lunga-abbastanza',
]));
fcCheck('csrf sbagliato: 403', $status === 403);
fcCheck('nessun proprietario creato con csrf sbagliato', !fcOwnerExists());

// --- Validazione: username vuoto -------------------------------------------

echo "\nValidazione — username vuoto:\n";
[$status, $body] = fcHttpRequest('POST', $loginUrl, [
    "Cookie: {$cookieA}",
    'Content-Type: application/x-www-form-urlencoded',
], http_build_query([
    'csrf' => $csrfA,
    'username' => '',
    'password' => 'una-password-lunga-abbastanza',
    'password_confirm' => 'una-password-lunga-abbastanza',
]));
fcCheck('username vuoto: 200 con form ripresentato', $status === 200);
fcCheck('messaggio di errore username vuoto', str_contains($body, 'username non può essere vuoto'));
fcCheck('nessun proprietario creato', !fcOwnerExists());

// --- Validazione: password troppo corta ------------------------------------

echo "\nValidazione — password troppo corta:\n";
[$status, $body] = fcHttpRequest('POST', $loginUrl, [
    "Cookie: {$cookieA}",
    'Content-Type: application/x-www-form-urlencoded',
], http_build_query([
    'csrf' => $csrfA,
    'username' => 'admin',
    'password' => 'corta',
    'password_confirm' => 'corta',
]));
fcCheck('password corta: 200 con form ripresentato', $status === 200);
fcCheck('messaggio di errore password corta', str_contains($body, 'almeno 12 caratteri'));
fcCheck('nessun proprietario creato', !fcOwnerExists());

// --- Validazione: le due password non coincidono ---------------------------

echo "\nValidazione — password non coincidenti:\n";
[$status, $body] = fcHttpRequest('POST', $loginUrl, [
    "Cookie: {$cookieA}",
    'Content-Type: application/x-www-form-urlencoded',
], http_build_query([
    'csrf' => $csrfA,
    'username' => 'admin',
    'password' => 'una-password-lunga-abbastanza',
    'password_confirm' => 'unaltra-password-diversa',
]));
fcCheck('password non coincidenti: 200 con form ripresentato', $status === 200);
fcCheck('messaggio di errore coerenza password', str_contains($body, 'non coincidono'));
fcCheck('nessun proprietario creato', !fcOwnerExists());

// --- Creazione valida: login automatico -------------------------------------

echo "\nCreazione valida:\n";
[$status, $body, $headers] = fcHttpRequest('POST', $loginUrl, [
    "Cookie: {$cookieA}",
    'Content-Type: application/x-www-form-urlencoded',
], http_build_query([
    'csrf' => $csrfA,
    'username' => 'admin',
    'password' => 'una-password-lunga-abbastanza',
    'password_confirm' => 'una-password-lunga-abbastanza',
]));
$location = fcExtractLocation($headers);
$cookieB = fcExtractCookie($headers) ?? $cookieA;

fcCheck('creazione valida: redirect 302', $status === 302);
fcCheck('redirect verso dashboard.php (login automatico)', $location === 'dashboard.php');
fcCheck('il proprietario ora esiste su disco', fcOwnerExists());

$owner = fcOwnerRead();
fcCheck('created_via registra "wizard"', $owner['created_via'] === 'wizard');
fcCheck("created_by_ip registra l'indirizzo del chiamante", $owner['created_by_ip'] === '127.0.0.1');
$createdAt = $owner['created_at'];

// Il redirect ha regenerato l'id di sessione: verificare che la sessione
// restituita sia davvero già autenticata, aprendo la dashboard con quel
// cookie senza rifare il login.
[$status, $body] = fcHttpRequest('GET', "http://{$host}/dashboard.php", ["Cookie: {$cookieB}"]);
fcCheck('login automatico riuscito: dashboard.php raggiungibile subito dopo', $status === 200);
fcCheck('dashboard.php mostra il pannello autenticato', str_contains($body, 'Pi registrati'));

// --- Dopo la creazione: la stessa rotta mostra login, non più creazione ----

echo "\nDopo la creazione:\n";
[$status, $body, $headers] = fcHttpRequest('GET', $loginUrl);
$cookieC = fcExtractCookie($headers);
$csrfC = fcExtractCsrf($body);

fcCheck('GET /login.php ora mostra il login', str_contains($body, '<h1>Accedi</h1>'));
fcCheck('non mostra più il form di creazione', !str_contains($body, 'Crea il proprietario del pannello'));

// Un secondo tentativo di creazione (stessi campi di prima, sessione e
// csrf nuovi) non deve avere alcun effetto: la rotta ormai processa questi
// campi come un tentativo di login, non come una creazione.
[$status, $body] = fcHttpRequest('POST', $loginUrl, [
    "Cookie: {$cookieC}",
    'Content-Type: application/x-www-form-urlencoded',
], http_build_query([
    'csrf' => $csrfC,
    'username' => 'occupante-abusivo',
    'password' => 'unaltra-password-lunghissima',
    'password_confirm' => 'unaltra-password-lunghissima',
]));
fcCheck('secondo tentativo: nessun redirect (non è più creazione)', $status === 200);
fcCheck('secondo tentativo: credenziali non valide (verificate come login)', str_contains($body, 'Credenziali non valide'));

$ownerAfter = fcOwnerRead();
fcCheck('username invariato dopo il secondo tentativo', $ownerAfter['username'] === 'admin');
fcCheck('created_at invariato: nessuna sovrascrittura', $ownerAfter['created_at'] === $createdAt);

// Le credenziali originali continuano a funzionare.
[$status, $body, $headers] = fcHttpRequest('POST', $loginUrl, [
    "Cookie: {$cookieC}",
    'Content-Type: application/x-www-form-urlencoded',
], http_build_query([
    'csrf' => $csrfC,
    'username' => 'admin',
    'password' => 'una-password-lunga-abbastanza',
]));
fcCheck('login con le credenziali originali riesce', $status === 302 && fcExtractLocation($headers) === 'dashboard.php');

// --- Chiusura -----------------------------------------------------------------

proc_terminate($process);
proc_close($process);
fcRemoveDirRecursive($fcTestDir);

echo "\n{$fcPass} ok, {$fcFail} falliti.\n";
exit($fcFail === 0 ? 0 : 1);
