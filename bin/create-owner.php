#!/usr/bin/env php
<?php
// Fluxus Connect — crea o reimposta le credenziali del proprietario del
// pannello, da riga di comando (SSH). Percorso alternativo al wizard nel
// browser (public/login.php, mostrato al posto del login quando non esiste
// ancora un proprietario — vedi docs/NOTE-TECNICHE.md, "Configurazione del
// proprietario senza terminale"): utile su hosting con accesso SSH, sia per
// la creazione iniziale sia per il recupero (sostituisce un proprietario
// esistente con conferma esplicita, comportamento invariato qui sotto).
//
// Uso: php bin/create-owner.php [username]
//      (la password si inserisce in modo interattivo, mai come
//      argomento — finirebbe nella cronologia della shell e in `ps`)

require_once __DIR__ . '/../includes/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Questo script va lanciato da riga di comando.\n");
    exit(1);
}

function fcReadHidden(string $prompt): string
{
    echo $prompt;
    if (stripos(PHP_OS, 'WIN') === 0) {
        return trim((string) fgets(STDIN));
    }
    system('stty -echo');
    $value = trim((string) fgets(STDIN));
    system('stty echo');
    echo "\n";
    return $value;
}

$username = $argv[1] ?? 'admin';

if (fcOwnerExists()) {
    $existing = fcOwnerRead();
    echo "Esiste già un proprietario ('" . ($existing['username'] ?? '?') . "'). Procedendo lo sostituirai.\n";
    echo 'Continuare? [s/N] ';
    $confirm = trim((string) fgets(STDIN));
    if (strtolower($confirm) !== 's') {
        echo "Annullato.\n";
        exit(0);
    }
}

$password = fcReadHidden("Password per '{$username}' (almeno 12 caratteri): ");
$confirmPassword = fcReadHidden('Ripeti la password: ');

if ($password !== $confirmPassword) {
    fwrite(STDERR, "Le due password non coincidono.\n");
    exit(1);
}

try {
    fcOwnerSetPassword($username, $password);
} catch (InvalidArgumentException $e) {
    fwrite(STDERR, 'Errore: ' . $e->getMessage() . "\n");
    exit(1);
}

echo "Proprietario '{$username}' configurato.\n";
