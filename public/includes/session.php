<?php
// Fluxus Connect — pannello: sessione e CSRF.
// Solo per il pannello web (login del proprietario) — l'API del Pi e
// quella pubblica per le console (Fasi 3-4) si autenticano coi token, non
// con una sessione da browser.

function fcSessionStart(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

function fcIsLoggedIn(): bool
{
    return !empty($_SESSION['fc_owner']);
}

function fcRequireLogin(): void
{
    if (!fcIsLoggedIn()) {
        header('Location: login.php');
        exit;
    }
}

function fcLoginSession(string $username): void
{
    session_regenerate_id(true);
    $_SESSION['fc_owner'] = $username;
}

function fcLogoutSession(): void
{
    $_SESSION = [];
    session_regenerate_id(true);
    session_unset();
    session_destroy();
}

function fcCsrfToken(): string
{
    if (empty($_SESSION['fc_csrf'])) {
        $_SESSION['fc_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['fc_csrf'];
}

function fcCsrfField(): string
{
    return '<input type="hidden" name="csrf" value="' . htmlspecialchars(fcCsrfToken(), ENT_QUOTES, 'UTF-8') . '">';
}

// Ferma la richiesta con 403 se il token CSRF non torna — mai un ===
// diretto fra token, stesso motivo dei token di autenticazione.
function fcCsrfCheck(): void
{
    $token = $_POST['csrf'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['fc_csrf'] ?? '', $token)) {
        http_response_code(403);
        exit('Richiesta non valida (token CSRF mancante o scaduto). Ricarica la pagina e riprova.');
    }
}
