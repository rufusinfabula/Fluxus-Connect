<?php
// Fluxus Connect — owner.php
// Credenziali del proprietario del pannello (login singolo, non
// multi-utente). A differenza dei token (tokens.php: alta entropia, hash
// veloce a confronto costante), qui la password la sceglie un umano —
// entropia bassa per definizione, serve un hash lento (password_hash).
//
// Nessuna registrazione via web: le credenziali si creano solo da riga di
// comando (bin/create-owner.php). Connect è pensato per stare
// pubblicamente raggiungibile (vedi docs/NOTE-TECNICHE.md) — una
// creazione self-service del primo account aprirebbe una corsa a chi
// arriva prima, esattamente il problema che il primo contatto mediato da
// un umano risolve per i token dei Pi.

require_once __DIR__ . '/storage.php';

const FC_OWNER_MAX_ATTEMPTS = 5;
const FC_OWNER_LOCK_BASE_SECONDS = 30;
const FC_OWNER_LOCK_MAX_SECONDS = 900; // 15 minuti

function fcOwnerPath(): string
{
    return FC_DATA_DIR . '/owner.json';
}

function fcOwnerExists(): bool
{
    return is_file(fcOwnerPath());
}

// Condivisa fra fcOwnerSetPassword() e fcOwnerCreateIfAbsent(): stessa
// regola, un solo posto dove cambiarla.
function fcOwnerValidateCredentials(string $username, string $password): string
{
    $username = trim($username);
    if ($username === '') {
        throw new InvalidArgumentException('username non può essere vuoto');
    }
    if (strlen($password) < 12) {
        throw new InvalidArgumentException('la password deve essere di almeno 12 caratteri');
    }
    return $username;
}

// Usata da bin/create-owner.php: sovrascrive sempre (dietro conferma
// esplicita già chiesta a riga di comando se un proprietario esiste già).
// $createdVia distingue nel record chi ha creato le credenziali — vedi
// docs/NOTE-TECNICHE.md, "Configurazione del proprietario senza terminale".
function fcOwnerSetPassword(string $username, string $password, ?string $createdByIp = null, string $createdVia = 'cli'): void
{
    $username = fcOwnerValidateCredentials($username, $password);

    $data = [
        'username' => $username,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'created_at' => fcTimestamp(),
        'created_by_ip' => $createdByIp,
        'created_via' => $createdVia,
        'failed_attempts' => 0,
        'locked_until' => null,
    ];
    fcAtomicWriteFile(
        fcOwnerPath(),
        json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
    );
}

// Usata dal wizard web (public/login.php) al primissimo accesso: a
// differenza di fcOwnerSetPassword(), non sovrascrive mai un proprietario
// già esistente. Scrive sotto lo stesso lock di fcUpdateJsonFile()
// (storage.php), quindi due submit quasi simultanei non possono corrompere
// owner.json: il primo a ottenere il lock crea, il secondo trova
// 'username' già presente e non tocca nulla. Non impedisce la corsa "chi
// arriva prima" in sé — quel rischio è accettato deliberatamente, vedi
// docs/NOTE-TECNICHE.md — solo la corruzione da scrittura concorrente.
// Restituisce true se questa chiamata ha creato il proprietario, false se
// un'altra richiesta lo aveva già fatto nel frattempo (corsa persa).
function fcOwnerCreateIfAbsent(string $username, string $password, ?string $createdByIp): bool
{
    $username = fcOwnerValidateCredentials($username, $password);

    $created = false;
    fcUpdateJsonFile(fcOwnerPath(), function (array $data) use ($username, $password, $createdByIp, &$created) {
        if (isset($data['username'])) {
            return $data; // già creato da un'altra richiesta: nessuna sovrascrittura
        }
        $created = true;
        return [
            'username' => $username,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'created_at' => fcTimestamp(),
            'created_by_ip' => $createdByIp,
            'created_via' => 'wizard',
            'failed_attempts' => 0,
            'locked_until' => null,
        ];
    });

    return $created;
}

function fcOwnerRead(): ?array
{
    $path = fcOwnerPath();
    if (!is_file($path)) {
        return null;
    }
    $raw = file_get_contents($path);
    if ($raw === false || $raw === '') {
        return null;
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

// Secondi mancanti al prossimo tentativo consentito (0 se nessun blocco
// attivo) — usato dal pannello per il messaggio d'errore.
function fcOwnerLockedForSeconds(): int
{
    $data = fcOwnerRead();
    $lockedUntil = $data['locked_until'] ?? null;
    if ($lockedUntil === null) {
        return 0;
    }
    $remaining = strtotime($lockedUntil) - time();
    return $remaining > 0 ? $remaining : 0;
}

// Verifica credenziali con contatore tentativi falliti: dopo
// FC_OWNER_MAX_ATTEMPTS tentativi consecutivi falliti, blocco crescente
// (30s, 60s, 120s... fino a un tetto di 15 minuti). Tutto in un unico
// aggiornamento con lock (fcUpdateJsonFile) per non perdere conteggi sotto
// richieste quasi simultanee. Il confronto sullo username usa hash_equals
// per lo stesso motivo dei token: non aprire un canale laterale sui tempi
// di risposta.
function fcOwnerVerify(string $username, string $password): bool
{
    if (!fcOwnerExists()) {
        return false;
    }

    $ok = false;
    fcUpdateJsonFile(fcOwnerPath(), function (array $data) use ($username, $password, &$ok) {
        $lockedUntil = $data['locked_until'] ?? null;
        if ($lockedUntil !== null && strtotime($lockedUntil) > time()) {
            $ok = false;
            return $data; // ancora bloccato: non contare nemmeno il tentativo
        }

        $validUsername = hash_equals((string) ($data['username'] ?? ''), $username);
        $validPassword = isset($data['password_hash'])
            && is_string($data['password_hash'])
            && password_verify($password, $data['password_hash']);
        $ok = $validUsername && $validPassword;

        if ($ok) {
            $data['failed_attempts'] = 0;
            $data['locked_until'] = null;
        } else {
            $attempts = (int) ($data['failed_attempts'] ?? 0) + 1;
            $data['failed_attempts'] = $attempts;
            if ($attempts >= FC_OWNER_MAX_ATTEMPTS) {
                $delay = min(
                    FC_OWNER_LOCK_BASE_SECONDS * (2 ** ($attempts - FC_OWNER_MAX_ATTEMPTS)),
                    FC_OWNER_LOCK_MAX_SECONDS
                );
                $data['locked_until'] = gmdate('Y-m-d\TH:i:s\Z', time() + $delay);
            }
        }
        return $data;
    });

    return $ok;
}
