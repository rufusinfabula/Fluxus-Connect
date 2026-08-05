<?php
// Fluxus Connect — tokens.php
// Generazione e verifica dei token (token di primo livello per Pi e
// sotto-chiavi per console: stesso meccanismo per entrambi). Vedi
// docs/NOTE-TECNICHE.md, "Chi genera i token, e perché": entropia alta,
// hash salvato al posto del valore in chiaro, confronto a tempo costante.
// Un hash veloce (sha256) basta: il token è già ad alta entropia, non una
// password umana, quindi non serve un hash lento come bcrypt/argon2.

// 256 bit di entropia, esadecimale (64 caratteri) — comodo anche come nome
// di cartella, a differenza di base64 non richiede caratteri da evitare nei
// percorsi (/, +).
function fcGenerateToken(): string
{
    return bin2hex(random_bytes(32));
}

function fcHashToken(string $token): string
{
    return hash('sha256', $token);
}

// Confronto a tempo costante: mai un === o == diretto fra hash, per non
// aprire un canale laterale sui tempi di risposta.
function fcVerifyToken(string $token, string $storedHash): bool
{
    return hash_equals($storedHash, fcHashToken($token));
}
