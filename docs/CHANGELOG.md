# Changelog — Fluxus Connect

Sotto l'1.0 il numero di versione coincide con la fase della roadmap (vedi
[ROADMAP.md](ROADMAP.md)), salvo estensioni a una fase già chiusa — come
questa — che restano nella versione della fase che estendono invece di
aprirne una nuova, quando il cambio è additivo.

## 0.5.0

Estensione multi-registrazione dell'API pubblica (fasi 4-5), per sbloccare
la Fase 6 (script sul Pi, in `fluxus-src`): Fluxus può registrare da più
sorgenti contemporaneamente, e oggi il contratto assumeva una sola
registrazione attiva per Pi. Vedi [NOTE-TECNICHE.md](NOTE-TECNICHE.md),
sezione "Multi-registrazione", per il ragionamento completo dietro ogni
scelta.

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
  distruttivo — vedi il ragionamento in NOTE-TECNICHE.md.

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
