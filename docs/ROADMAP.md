# Roadmap — Fluxus Connect

Sotto l'1.0 il numero di fase coincide col numero di versione, come in
Fluxus. Nessuna fase parte da sola: ognuna va confermata prima di scrivere
codice — niente procede di iniziativa.

## Decisioni già prese (Fase 0)

- **Repository separato** sia da `fluxus-src` sia da Fluxus Remote — non un
  plugin, non un monorepo. Storia, versioni e rischio restano isolati fra i
  tre prodotti.
- **Storage in file piatti**, non in un database: una cartella per tenant,
  scritture atomiche (temporaneo + rinomina), coda comandi come un file per
  elemento. Scelto per restare compatibile con l'hosting condiviso più
  economico, dove un motore SQL non è garantito. Vedi
  [NOTE-TECNICHE.md](NOTE-TECNICHE.md).
- **Multi-tenant fin da subito**: più installazioni Fluxus possono
  condividere la stessa installazione di Connect, isolate per cartella.
- **Token gerarchici**: un token per Pi (genera Connect, non il Pi — vedi
  NOTE-TECNICHE.md per il perché), sotto-chiavi per ogni console/sistema
  esterno autorizzato, revocabili singolarmente senza toccare le altre.
- **Niente app-store, niente plugin**: Connect resta un broker con una sola
  API pubblica. Qualunque prodotto la consuma da fuori, come farebbe
  qualunque console esterna — mai codice di terzi in esecuzione dentro il
  processo di Connect. Valutato e scartato in fase di progettazione: un
  meccanismo di plugin indebolirebbe il confine di sicurezza fra tenant per
  un beneficio marginale (vedi *Più avanti*).
- **Fluxus Remote resta un prodotto separato**, non assorbito: è già in
  produzione con marker/cue reali su eventi reali — non si rischia una
  funzionalità che già funziona finché Connect non ha dimostrato di reggere
  sul campo.

## Fase 1 — Motore di storage → `0.1.0`
- Scrittura atomica e lettura di `status.json` per tenant
- Coda comandi come file singoli in `queue/` (prendere-e-cancellare è già
  atomico sulla gran parte dei filesystem)
- Generazione chiavi ad alta entropia, verifica via hash a confronto
  costante

## Fase 2 — Pannello di amministrazione → `0.2.0` *(rivista, vedi "Fase X")*
- Login del proprietario
- Creazione di un nuovo Pi: genera il token, mostrato una sola volta
- Creazione/revoca sotto-chiavi per console, con nome e scope (`follow` /
  `follow+control`)
- Log/attività per tenant
- Creazione del proprietario: oltre a `bin/create-owner.php` (SSH), un
  wizard al primo accesso se non esiste ancora un proprietario — decisione
  e meccanismo in "Fase X" più sotto, implementazione non ancora fatta

## Fase 3 — API riservata per il Pi → `0.3.0`
- Endpoint che riceve lo stato dal Pi (autenticato col token del Pi)
- Endpoint che restituisce la coda comandi in attesa
- Endpoint che conferma/rimuove un comando dopo l'esecuzione

*(Le fasi 2 e 3 dipendono solo dalla 1, non l'una dall'altra — ordine
scambiabile.)*

## Fase 4 — API pubblica per le console esterne → `0.4.0`
- Lettura dello stato (sotto-chiave, scope `follow`)
- Deposito di un comando (sotto-chiave, scope `follow+control`) — whitelist
  stretta: solo marker/cue, niente avvio/stop (PENDING/FUTURO)
- Versione nell'indirizzo (`/api/v1/...`), formato di risposta ed errori
  coerente

## Fase 5 — Documentazione pubblica → `0.5.0`
- File OpenAPI per l'API della fase 4
- Pagina statica di documentazione (Swagger UI, solo file statici, nessun
  servizio in più)
- **Estensione multi-registrazione**, aggiunta prima di chiudere la fase
  perché scoperta preparando la Fase 6: `GET /follow/status.php` espone
  `registrations` (elenco delle registrazioni attive, Fluxus può
  registrarne più di una alla volta), `POST /control/commands.php`
  accetta `target_id` per indirizzare un comando a una registrazione
  precisa (whitelist stretta contro quelle correntemente attive,
  obbligatorio se ce n'è più di una — mai una scelta euristica di Connect
  o del Pi), e l'oggetto in coda porta ora anche `subkey_name` (non solo
  il log) così il Pi può mostrare da quale console è arrivato un comando.
  Cambio additivo, non ha richiesto `/api/v2/`. Vedi
  [NOTE-TECNICHE.md](NOTE-TECNICHE.md), "Multi-registrazione", e
  [CHANGELOG.md](CHANGELOG.md).

## Fase 6 — Script sul Pi *(vive in fluxus-src, non qui)* ✅ FATTO
- Nuovo file di segreti sul Pi (indirizzo Connect + token, stesso
  trattamento di `.remote.conf`: separato, 0640)
- Script di sincronizzazione — stessa struttura di `remote_sync.php` ma
  verso Connect: ogni 2s invia lo stato, scarica la coda, esegue i comandi
  via `fmCreateMarker()` (riusata, non reinventata) e conferma
- Timer systemd dedicato, sul modello di `fm-remote-sync.timer`
- Va lanciato da una conversazione radicata in `fluxus-src`, non qui: usa
  come contratto l'API pubblicata dalla Fase 4/5 di questo repository.

Costruito e rilasciato in `fluxus-src` come `scripts/connect_sync.php` +
timer `fm-connect-sync.timer`, fuori dalla numerazione di fase di quel
repository (versione `0.4.6` lì — la roadmap di Fluxus ha una propria
"Fase 6" non correlata, "Immagine SD pronta"). Configurazione da
pannello (card "Fluxus Connect" in Impostazioni, `0.4.8` lì, con pulsante
"Testa connessione" verso `whoami.php`) invece del solo file manuale.
Nessun cambio di versione qui: nessun codice scritto in questo
repository.

## Fase 7 — Collaudo end-to-end ✅ FATTO
- Prima con richieste dirette (`curl`) al posto di una console vera, per
  isolare i problemi
- Casi limite da provare esplicitamente: Connect irraggiungibile per un po',
  token revocato a metà sessione, comando rimasto in coda troppo a lungo
- Richiede sia Connect (fasi 1-5) sia lo script sul Pi (fase 6) già pronti

Tutti e tre i casi limite provati esplicitamente, test superati. Dal
collaudo sono emersi e corretti alcuni bug minori nel codice di Connect,
impatto trascurabile, nessun cambio al contratto pubblico — vedi `0.5.1`
in [CHANGELOG.md](CHANGELOG.md).

## Fase X — Autenticazione del proprietario senza terminale ✅ FATTO

Non numerata di proposito: non è la prosecuzione lineare della roadmap, è
una parentesi su una decisione di Fase 2 che si era rivelata incompleta.
Implementazione scritta e collaudata: Fase 8 può ora procedere.

- Problema: `bin/create-owner.php` (Fase 2) richiede accesso a riga di
  comando (SSH), non garantito sull'hosting più economico — il vincolo
  dichiarato nel README.
- Decisione: creazione del proprietario al primo accesso, senza prova
  preventiva di controllo del file system — stesso schema di WordPress e
  simili. Se non esiste ancora un proprietario, il pannello mostra un form
  di creazione invece del login; il primo invio valido lo crea e chiude la
  porta per sempre. Rischio di corsa residuo accettato deliberatamente
  (rilevamento quasi immediato, raggio di danno quasi nullo al primissimo
  avvio, recupero — cancellare `data/owner.json` via FTP — che non
  richiede capacità in più di quelle già necessarie per caricare il
  codice), con un rafforzamento a costo marginale (`created_by_ip` e
  `created_via` in `owner.json`). Scartate: file di configurazione
  compilato via FTP prima del primo accesso e wizard con prova FTP
  (stessa garanzia di sicurezza, più scomodi, senza vantaggio netto);
  generazione da Fluxus, in entrambe le varianti discusse (in tensione con
  "l'unico punto di contatto fra Fluxus e Connect è l'API pubblica
  documentata" delle istruzioni locali del repository, e comunque non
  risolve il caso generale multi-tenant). Motivazione completa, con le quattro alternative a
  confronto, in [NOTE-TECNICHE.md](NOTE-TECNICHE.md), "Configurazione del
  proprietario senza terminale".
- Implementazione: `public/login.php` mostra il form di creazione al
  posto del login quando `!fcOwnerExists()` (stessa protezione CSRF e
  stessa validazione già in uso). `includes/owner.php` aggiunge
  `fcOwnerCreateIfAbsent()`: scrive sotto lo stesso lock già usato da
  `fcUpdateJsonFile()` e non sovrascrive mai un proprietario già esistente
  — due submit quasi simultanei non possono corrompere `owner.json`, la
  corsa "chi arriva prima" in sé resta il rischio accettato descritto
  sopra. La stessa richiesta che crea il proprietario esegue anche il
  login automatico (come al primo avvio di WordPress), scelta esplicita
  fra le due indicate nel prompt di Fase X. `bin/create-owner.php` resta
  invariato nel comportamento, solo il commento in testa menziona ora il
  wizard come alternativa via browser. Test in `tests/admin_test.php`
  (motore: creazione atomica, corsa persa, validazione) e nuovo
  `tests/login_wizard_test.php` (HTTP end-to-end: form mostrato, CSRF,
  redirect, nessuna riapertura dopo la creazione).

## Fase 8 — Prima integrazione reale e rilascio ✅ FATTO

- Collegare una prima console esterna vera (anche minima) per validare
  l'intero flusso
- Documentato anche lato fluxus-src: sezione "Fluxus Connect" in
  `docs/NOTE-TECNICHE.md` e voce sotto "Prima della 1.0" in
  `docs/ROADMAP.md` di quel repository — completato.

Validato con un consumatore reale (script PHP a polling, autenticato con
una sotto-chiave `follow+control`) contro l'ambiente `fluxus-dev` già
collegato dalla Fase 6: `GET /api/v1/follow/status.php` letto a intervalli
riflette lo stato reale pubblicato ogni 2s dallo script sul Pi;
`POST /api/v1/control/commands.php` ha depositato un marker realmente
ritirato dal timer systemd `fluxus-dev-connect-sync` entro il giro
successivo (visibile sia nel log di quello script sia nel log di attività
del tenant), scartato correttamente perché nessuna registrazione era
attiva in quel momento — stesso percorso di codice che gestirebbe un
deposito andato a buon fine. Lo script della console non è entrato in
questo repository: è un consumatore esterno usa-e-getta, non un prodotto
da mantenere qui (stesso principio per cui Connect non assorbe i propri
consumer — vedi "Decisioni già prese"). La sotto-chiave creata per la
prova è stata revocata a validazione conclusa.

## Fase Y — `whoami.php`, mancante dal contratto con fluxus-src ✅ FATTO

Non numerata, come la Fase X: emersa durante il primo collegamento reale
in produzione (Fase 8), non dalla roadmap lineare. Il pannello di Fluxus
(Impostazioni → Fluxus Connect, `fmConnectTest()` in `includes/helpers.php`
di fluxus-src) ha un pulsante "Testa connessione" che chiama
`GET /api/pi/whoami.php` — endpoint mai costruito qui: Fase 3 aveva
previsto solo `status.php`, `queue.php`, `ack.php`. Il contratto era già
scritto lato Fluxus (commento sopra `fmConnectTest()`), quindi si è
implementato esattamente quello: 200 JSON `{"subkeys": [...]}` coi nomi
delle sotto-chiavi attive del tenant (array vuoto se nessuna), stessa
autenticazione col token di primo livello degli altri endpoint riservati,
401 per token mancante/malformato/sconosciuto. Solo le sotto-chiavi non
revocate: una console che ha perso l'accesso non deve risultare ancora
collegata. Vedi `public/api/pi/whoami.php`, `tests/api_test.php`.

## Fase W — Avvio di Fluxus Remote 1.5 (decisione) ✅ FATTO

Non numerata, come le Fasi X e Y: non prosegue linearmente questa roadmap,
segna la decisione di avviare — in un repository separato, non ancora
creato da questa conversazione — la riscrittura di Fluxus Remote che
"Più avanti" (più sotto) aveva previsto fin dall'impalcatura iniziale.

La condizione posta allora — "da valutare solo dopo che Connect avrà
dimostrato affidabilità sul campo con traffico reale (dopo la Fase 8, con
un margine di tempo d'uso vero, non subito dopo)" — è stata esplicitamente
derogata dal proprietario del progetto in questa stessa conversazione: a
suo giudizio la Fase 8 (validata lo stesso giorno di questa decisione,
vedi la voce sopra) è sufficiente, senza attendere oltre. Non è
un'interpretazione presa in autonomia da questa conversazione — è la
scelta esplicita di chi ha l'autorità per derogare a una propria decisione
precedente, registrata qui per lo stesso motivo per cui lo sono le altre:
chi legge in futuro deve trovare il perché, non solo il cosa.

Questa fase produce solo documentazione e un prompt di avvio — nessun
codice applicativo scritto qui, coerente con le istruzioni locali del
repository ("ogni fase della roadmap ha un proprio prompt... non
anticipare fasi future scrivendo codice oltre quella in corso"). La costruzione vera è demandata a una
conversazione nuova, radicata in `/home/pi/fluxus-remote` (cartella già
presente ma vuota — repository ulteriore, non questa cartella né
`fluxus-src`): prompt in `PROMPTS.local.md`, sezione "Fluxus Remote 1.5 —
Fase 0".

Il contratto che Remote 1.5 deve rispettare (API pubblica di Connect,
autenticazione, scope, whitelist, multi-registrazione) è quello già
documentato in `NOTE-TECNICHE.md` e in `public/docs/openapi.yaml` — non
riscritto qui, solo referenziato. Vedi `NOTE-TECNICHE.md`, sezione
"Fluxus Remote 1.5 — cosa deve sapere la conversazione che lo costruisce",
per cosa sostituisce, cosa resta intoccato, e come non confondersi con la
vecchia versione di Remote (prodotto separato, già in produzione, che
resta invariato).

## Fase Z — Valutazione proposte API (pre-implementazione)

Non numerata, come le Fasi X, Y e W: apre un processo, non lo prosegue
linearmente. A differenza delle altre parentesi, questa non chiude nulla
— resta aperta finché non arrivano decisioni esplicite. Regola di questa
fase: prima si raccolgono e valutano le proposte, poi — solo dopo una
decisione esplicita e motivata, registrata qui come già fatto per le
Fasi X e W — si scrive codice. Nessuna implementazione nel frattempo.

Ambiti aperti in valutazione, avviati in una conversazione radicata qui
mentre si preparava l'avvio di Fluxus Remote 1.5 (Fase W):

- **Strumento per provare le API esistenti** — a mano o scriptato, contro
  un'istanza reale, prima che un consumer nuovo (Remote 1.5 o altro) ci
  faccia affidamento. Probabilmente vive fuori da questo repository (chi
  lo consuma), non dentro. **Deciso**: nessuno strumento nuovo. Esiste
  già `public/docs/index.html` (Fase 5), una Swagger UI standard con
  "Try it out" e "Authorize" attivi di default — incollando lì una
  sotto-chiave reale si eseguono già chiamate vere sia `follow` sia
  `control` contro un'istanza reale. Valutata e scartata l'idea di
  un'alternativa integrata nel pannello (scelta istanza per nome invece
  di incollare la sotto-chiave): si scontrava con un vincolo del
  modello — dalla Fase 2 le sotto-chiavi si vedono in chiaro una sola
  volta, il pannello dopo la creazione ha solo l'hash, quindi non può
  "sceglierne una esistente" e usarla per conto del proprietario senza
  un meccanismo di provisioning nuovo (sotto-chiave di prova usa-e-getta
  o simile) che non ha un beneficio netto rispetto a quanto già offre la
  Swagger UI pubblica. Punto chiuso, nessun codice scritto per questo.
- **Inventario di cosa esiste già e cosa si potrebbe aggiungere** — le
  estensioni additive e a basso rischio (nuovi campi in sola lettura,
  nuovi endpoint follow) non toccano il modello di sicurezza e non
  richiedono una deroga; vanno comunque proposte ed elencate qui prima di
  scriverle.
- **Sotto-chiave appena creata, confusione visiva col token dell'istanza**
  (bug di presentazione emerso in questa conversazione, non un ambito
  aperto della roadmap originaria di Fase Z, ma risolto qui perché
  scoperto discutendo lo strumento di prova sopra) — vedi
  `docs/CHANGELOG.md` per il dettaglio implementativo. In breve:
  `public/tenant.php` mostrava il segreto di una sotto-chiave appena
  creata con lo stesso banner, in cima alla pagina, usato per il token
  dell'istanza quando lo si rigenera — stessa vetrina per due segreti
  diversi, posizione fuorviante. Scartata l'idea di separare creazione
  della sotto-chiave (solo nome/scope) e generazione del segreto (un
  tasto "genera" a parte in elenco): romperebbe un'invariante attuale,
  il nome del file su disco è l'hash del segreto stesso
  (`fcSubkeyPath`), costringendo a un identificatore separato e a una
  verifica del token in arrivo per scansione invece che lettura diretta
  — cambio di modello dati, non solo di interfaccia. Fatta invece solo
  la correzione di presentazione: il segreto appena generato per una
  sotto-chiave appare ora dentro la sua riga nell'elenco, col nome della
  console, mai più nel banner generico in cima (riservato solo al token
  dell'istanza).
- **Riapertura della decisione fissa su avvio/stop registrazione remota**
  (decisione architetturale fissa #5 delle istruzioni locali del
  repository; vedi anche [NOTE-TECNICHE.md](NOTE-TECNICHE.md), "Fluxus
  Remote 1.5", che la
  ribadisce esplicitamente: "non implementarli anche se un utente li
  richiedesse"). **Non ancora decisa.** Riguarda l'API pubblica di
  Connect in generale, indipendentemente da chi la consuma — non è una
  richiesta specifica di Remote 1.5. Prima di qualunque implementazione
  serve un motivo nuovo esplicito da parte del proprietario del progetto
  (stesso standard di Fase X e Fase W), col raggio di danno di uno stop
  sbagliato — non recuperabile, a differenza di un marker — pesato
  esplicitamente nella decisione.

**Sospesa per ora (2026-08-06)**: il proprietario del progetto ha deciso
di non proseguire oltre in questa fase per il momento — non c'è
interesse a implementare altro adesso. Le due decisioni sopra (strumento
di prova, correzione della confusione sotto-chiave/token) restano valide
e chiuse. L'inventario delle estensioni additive e la riapertura della
decisione fissa #5 restano **aperti e non decisi**, non abbandonati:
prima di scrivere altro codice su nuove proposte di API serve riprendere
esplicitamente questa fase, stessa regola di apertura di sopra — nessuna
ripresa implicita.

---

## Più avanti

- **Fluxus Remote 1.5** — avviato, vedi "Fase W" sopra: nuovo repository,
  riscrittura di Fluxus Remote che userà l'API pubblica di Connect (come un
  cliente qualunque, con una propria sotto-chiave) invece della propria
  logica di coda indipendente. Tre opzioni erano state confrontate in fase
  di progettazione (cliente API separato / plugin dentro Connect / fusione
  completa): la riscrittura come cliente API è quella scelta, per lo stesso
  motivo per cui Connect stesso non ha un meccanismo di plugin.
- **Avvio/stop registrazione da remoto**: resta un salto di fiducia a parte
  rispetto a marker/cue — un comando in ritardo di polling non ha, a
  differenza di un marker, un modo di essere "corretto" a posteriori.
  PENDING/FUTURO.
- **Limiti di frequenza (rate limiting)** sull'API pubblica — da aggiungere
  se e quando serve davvero, non preventivamente.

Nota su fluxus-src: la Fase 6 sopra (script di sincronizzazione, file di
segreti, timer systemd) vive in quel repository, non in questo, e non ha
ancora un numero di fase nella sua `ROADMAP.md` — da decidere quando si
arriva a costruirla.
