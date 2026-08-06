# Changelog — Fluxus Connect

Da `1.0.0` il progetto segue il versionamento semantico: `1.0.1`, `1.0.2`,
... per correzioni minori; `1.1.0`, `1.2.0`, ... per evoluzioni di media
entità. Le voci precedenti alla `1.0.0` riflettono il processo di sviluppo
a fasi con cui il progetto è stato costruito (numerate o con nome, es.
"Fase X") — restano qui come cronologia, anche se quello schema non è più
in uso.

## 1.1.0

Il Pi pubblica ora anche uno specchio pieno (non incrementale, sostituito
per intero a ogni giro) di quattro cataloghi ogni 30s: storico
registrazioni, marker/cue, sorgenti, orari programmati. Nuovi endpoint
riservati al Pi (`POST /api/pi/recordings.php`, `markers.php`,
`sources.php`, `schedules.php`) e nuovi endpoint pubblici, stesso scope
`follow` già in uso per `follow/status.php` (`GET
/api/v1/follow/recordings.php`, con filtri `media_type`/`source_id`/
`status` e dettaglio tramite `?id=`; `GET
/api/v1/follow/recordings_markers.php?id=...`; `GET
/api/v1/follow/sources.php`; `GET /api/v1/follow/schedules.php`).
`public/docs/openapi.yaml` aggiornato di conseguenza (`0.6.0`).

## 1.0.0

Primo rilascio definitivo: Fluxus Connect è completo e pronto per l'uso in
produzione. Motore di storage, pannello di amministrazione, API riservata
al Pi, API pubblica versionata (`/api/v1/`) con supporto
multi-registrazione, documentazione OpenAPI e una prima integrazione reale
sono tutti scritti, collaudati end-to-end e in uso. Aggiunto un file
`VERSION` alla radice del repository, fonte unica del numero di versione;
il pannello ora mostra la versione nell'intestazione e in un footer con
anno corrente e credito. Nessun cambio al contratto pubblico rispetto alla
`0.5.2`.

## 0.5.2

Correzione di presentazione nel pannello, emersa discutendo lo strumento
di prova delle API (Fase Z): `public/tenant.php` mostrava il segreto di
una sotto-chiave appena creata nello stesso banner, in cima alla pagina,
usato per il token dell'istanza quando lo si rigenera — facile confondere
i due. Il segreto di una sotto-chiave ora appare dentro la sua riga
nell'elenco, col nome della console; il banner in cima resta riservato al
solo token dell'istanza. Nessun cambio al modello dati (`includes/subkeys.php`
invariato) né al contratto pubblico.

## 0.5.1

Correzioni minori nel codice di Connect, emerse durante il collaudo
end-to-end (Fase 7): impatto trascurabile, nessun cambio al contratto
pubblico (`/api/v1/...` invariato, `public/docs/openapi.yaml` resta alla
`0.5.0`).

## Fase W — Avvio di Fluxus Remote 1.5 (decisione)

Non un cambio di codice, quindi nessuna nuova versione: decisione di
avviare, in un repository separato non ancora creato, la riscrittura di
Fluxus Remote annunciata in "Più avanti" fin dall'impalcatura iniziale. La
condizione posta allora (attendere un margine di uso reale di Connect dopo
la Fase 8) è stata derogata esplicitamente dal proprietario del progetto.
Documentazione e prompt di avvio soltanto — nessun codice applicativo
scritto qui. Il contratto che Remote 1.5 deve rispettare resta quello
già pubblicato in `public/docs/openapi.yaml`.

## Fase Y — `whoami.php`

Nuovo endpoint `GET /api/pi/whoami.php` (autenticato col token di primo
livello, come `status.php`/`queue.php`/`ack.php`): risponde
`{"subkeys": [...]}` con le sotto-chiavi attive del tenant, per il
pulsante "Testa connessione" del pannello di Fluxus — endpoint già atteso
da quel lato ma mai costruito qui.

## Fase 8 — Prima integrazione reale (parte Connect)

Non un cambio di codice, quindi nessuna nuova versione: prima validazione
dell'intero flusso pubblico con un consumatore reale (script a polling
autenticato con sotto-chiave) contro l'ambiente `fluxus-dev`, già
collegato dalla Fase 6. Confermato che `follow/status.php` e
`control/commands.php` si comportano come da contratto anche fuori dai
test automatici, in un giro completo Connect → coda → script sul Pi.

## Fase X — Autenticazione del proprietario senza terminale

Non numerata di proposito: parentesi su
una decisione di Fase 2 rivelatasi incompleta, non la prosecuzione
lineare della roadmap — non apre una nuova versione.

`bin/create-owner.php` (Fase 2) richiedeva accesso a riga di comando
(SSH), non garantito sull'hosting condiviso più economico. Ora
`public/login.php` mostra un form "crea il proprietario" al posto del
login quando non ne esiste ancora uno — stessa protezione CSRF, stessa
validazione già in uso — e il primo invio valido lo crea, con login
automatico, chiudendo la porta per sempre. `includes/owner.php` aggiunge
`fcOwnerCreateIfAbsent()`, che scrive sotto lock e non sovrascrive mai un
proprietario già esistente (due submit quasi simultanei non corrompono
`owner.json`); `owner.json` registra anche `created_by_ip` e
`created_via` (`'wizard'` o `'cli'`). `bin/create-owner.php` resta un
percorso invariato, alternativo per chi ha SSH. Rischio di corsa residuo
("chi arriva prima" fra il caricamento dei file e il primo visitatore)
accettato deliberatamente — motivazione completa nelle note tecniche interne del progetto.

## 0.5.0

Estensione multi-registrazione dell'API pubblica (fasi 4-5), per sbloccare
la Fase 6 (script sul Pi, in `fluxus-src`): Fluxus può registrare da più
sorgenti contemporaneamente, e oggi il contratto assumeva una sola
registrazione attiva per Pi. Ragionamento completo dietro ogni scelta nelle note tecniche interne del
progetto.

### Aggiunto

- `GET /api/v1/follow/status.php` espone `registrations`: l'elenco di
  tutte le registrazioni attualmente attive (`id`, `state`, `source`,
  `media_type`, `started_at`, `elapsed_seconds`, `marker_count`), non più
  una sola. I campi scalari già esistenti in cima alla risposta restano
  come mirror di sola compatibilità della prima registrazione dell'elenco
  — nessuna rottura per chi ha già integrato l'endpoint.
- `POST /api/v1/control/commands.php` accetta `target_id`: l'`id` della
  registrazione a cui si applica il comando, tra quelle elencate da
  `registrations`. Obbligatorio quando più di una registrazione è attiva
  (whitelist stretta, 400 se non corrisponde a nessuna registrazione
  attiva — stessa forma già in uso per `type`); assegnato automaticamente
  da Connect quando ce n'è esattamente una; assente/ignorato quando non
  ce n'è nessuna. La scelta del bersaglio spetta sempre a chi chiama,
  mai a un'euristica di Connect o del Pi.
- L'oggetto comando persistito in coda (letto dal Pi via
  `GET /api/pi/queue.php`) include ora anche `subkey_name` — il nome
  della sotto-chiave/console che ha depositato il comando — non solo il
  log di attività come finora.
- Il pannello di amministrazione (log di attività) mostra anche il
  bersaglio (`target_id`) di un comando depositato, quando presente.

### Cambiato (API riservata al Pi, non pubblica/versionata)

- `POST /api/pi/status.php` accetta ora `{"registrations": [...]}` al
  posto di un singolo stato scalare (`state`/`id`/`source`/...). Non è un
  cambio compatibile all'indietro, ma non richiede una nuova versione:
  questo endpoint è riservato al Pi (mai documentato con OpenAPI, mai
  pensato per essere consumato da terzi) e non ha ancora nessun client,
  dato che la Fase 6 non è stata costruita.

### Note

- Nessuna nuova versione dell'indirizzo pubblico (`/api/v2/`): il cambio
  su `follow/status.php` e `control/commands.php` è additivo, non
  distruttivo.

## 0.4.0

Fase 4 — API pubblica per le console esterne: lettura dello stato
(`GET /api/v1/follow/status.php`, sotto-chiave scope `follow` o
`follow+control`) e deposito di un comando marker/cue
(`POST /api/v1/control/commands.php`, scope `follow+control`, whitelist
stretta su `type`). Avvio/stop registrazione restano PENDING/FUTURO.

## 0.3.0

Fase 3 — API riservata per il Pi: pubblicazione dello stato
(`POST /api/pi/status.php`), lettura della coda comandi
(`GET /api/pi/queue.php`), conferma/rimozione di un comando dopo
l'esecuzione (`POST /api/pi/ack.php`). Autenticazione dal token di primo
livello del Pi.

## 0.2.0

Fase 2 — Pannello di amministrazione: login del proprietario, creazione di
un nuovo Pi (token mostrato una sola volta), creazione/revoca di
sotto-chiavi per console esterne, log di attività per tenant.

## 0.1.0

Fase 1 — Motore di storage: scrittura atomica e lettura di `status.json`
per tenant, coda comandi come file singoli in `queue/`, generazione di
chiavi ad alta entropia con verifica a confronto costante.
