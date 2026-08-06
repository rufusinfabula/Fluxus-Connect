# Note tecniche — perché Fluxus Connect è fatto così

## Il problema

Fluxus (l'installazione sul Raspberry Pi) sta in rete privata per scelta
architetturale: nessuna porta esposta a Internet, il Pi non riceve mai
connessioni in ingresso. Questo protegge la macchina che registra davvero,
ma impedisce anche a un sistema legittimo esterno — un'altra console, un
software di scaletta — di sapere cosa sta facendo o di lasciargli
un'istruzione.

Fluxus Connect risolve questo senza toccare il principio: il Pi continua a
non ricevere mai nulla in ingresso. È sempre lui che esce, verso Connect, a
intervalli brevi (obiettivo: ogni 2s). Connect è l'unico dei tre pezzi
dell'ecosistema (Fluxus, Connect, Fluxus Remote) pensato per stare
pubblicamente raggiungibile — è il suo scopo.

## Due assi di design, non uno

- **Livello**: *follow* (sola lettura: stato/durata/sorgente) vs *controllo*
  (scrittura: marker/cue; avvio/stop restano PENDING/FUTURO).
- **Raggiungibilità**: il Pi è sempre "fuori LAN" rispetto a un consumer
  esterno, per definizione — è proprio il problema che Connect risolve.

Da tenere distinta anche una terza cosa, per non confondersi in futuro: la
sezione "API di Federazione" di `fluxus-src` (mai costruita) riguarda la
sincronizzazione di *configurazione* (sources/schedules) fra nodi Fluxus
paritari — un problema diverso da questo, che riguarda sistemi/console
*terzi* che osservano o agiscono sullo *stato*.

## Broker, non proxy né bridge

Un **proxy** inoltrerebbe in tempo reale verso il Pi — richiederebbe
raggiungerlo *ora*, il che romperebbe il principio "il Pi non riceve mai
connessioni in ingresso". Un **bridge** presuppone che le due parti siano
raggiungibili nello stesso momento. Connect è invece un **broker**: chi
scrive e chi legge non devono mai essere online insieme, perché tutto passa
da uno stato depositato nel mezzo. Più precisamente, fa due cose diverse:

1. **Coda di comandi** (controllo) — vera semantica da broker: richieste in
   fila, lette dal Pi in ordine, una alla volta, rimosse dopo l'esecuzione.
2. **Specchio di stato** (follow) — non una coda da consumare: il Pi
   pubblica lo stato attuale, chi legge trova sempre l'ultimo valore
   lasciato lì, senza "consumarlo".

## Perché file piatti e non un database

Il vincolo dichiarato è girare anche sull'hosting condiviso più economico.
Le alternative considerate:

- **SQLite** — richiede l'estensione `pdo_sqlite` (quasi sempre presente,
  non garantita) e soprattutto un filesystem con locking affidabile. Su
  hosting economici il disco è a volte di rete (NFS o simile), dove il
  locking di SQLite può comportarsi in modo imprevedibile — esattamente il
  tipo di insidia già incontrata con `fluxus-src` stesso (vincoli
  WAL/`busy_timeout` nelle sue note tecniche).
- **MySQL** — richiederebbe che l'hosting fornisca un database service a
  parte, non scontato sui piani più economici, e aggiunge una dipendenza in
  più (credenziali, connessione).
- **File piatti** — funzionano ovunque PHP possa scrivere nella propria
  cartella: è il requisito minimo che qualunque hosting PHP garantisce già
  (senza quello non funzionerebbero nemmeno le sessioni).

Il carico di lavoro reale si presta bene a questo: il Pi riscrive il proprio
stato ogni 2s (un file, sovrascritto atomicamente: scrivi su un temporaneo,
poi `rename()`), le console depositano comandi rari (pressioni di un
pulsante, non un flusso continuo). Nessuna delle due cose ha bisogno delle
garanzie di un motore SQL.

### Struttura su disco

```
data/
  tenants/
    <hash-del-token-pi>/
      meta.json          ← nome, token_hash, creato il...
      status.json        ← ultimo stato pubblicato dal Pi (follow)
      queue/
        <id>.json         ← un file per comando in coda (controllo)
      subkeys/
        <hash-sottochiave>.json   ← nome console, scope, ultimo uso
      log.jsonl           ← append-only, per audit
```

Un file per comando nella coda (invece di un'unica lista) evita il problema
classico del leggi-modifica-riscrivi sotto concorrenza: il Pi legge la
cartella, prende il file più vecchio, lo processa, lo cancella — operazione
atomica sul filesystem, nessuna race condition da gestire a mano. La
cartella `data/` (o il suo percorso reale, da fissare in Fase 1) va sempre
esclusa dal repository: contiene segreti e dati di tenant reali.

## Chi genera i token, e perché

Li genera **Connect**, non il Pi — sia il token di primo livello (per Pi)
sia le sotto-chiavi (per console). Motivi:

- Connect è multi-tenant: deve garantire unicità fra *tutti* i Pi che
  ospita, cosa che solo chi possiede l'intero spazio dei tenant può
  garantire senza collisioni. Un Pi che si presentasse con un proprio token
  auto-generato richiederebbe comunque una decisione di Connect
  sull'accettarlo o no — e un'accettazione automatica al primo contatto
  aprirebbe a squatting di identità da parte di chi indovina o intercetta
  un token prima del legittimo proprietario.
- Le sotto-chiavi hanno senso solo se annidate sotto il token del Pi — solo
  chi mantiene quella gerarchia (Connect) può crearle in modo coerente.
- È lo stesso modello già in produzione con Fluxus Remote: `FLUXUS_REMOTE_API_KEY`
  lo genera il relay, l'utente lo incolla nella configurazione del Pi. Non è
  un'invenzione nuova.

Quello che protegge davvero la sicurezza non è chi genera i byte casuali, ma:
entropia alta (256 bit), **hash** salvato invece del valore in chiaro
(confronto a tempo costante — non serve un hash lento come bcrypt, il token
è già ad alta entropia, non una password umana), primo contatto mediato da
un umano autenticato sul pannello di Connect (non uno scambio automatico non
autenticato), e il contenimento del danno se Connect viene compromesso: il
Pi tira sempre i dati, non li riceve mai in ingresso, quindi anche un token
rubato può solo iniettare comandi falsi in coda, mai aprire una via di
rientro diretta.

## Configurazione del proprietario senza terminale

Emerso preparando la Fase 8: `bin/create-owner.php` (Fase 2) è rimasto
l'unico modo per impostare le credenziali del proprietario del pannello, e
richiede accesso a riga di comando (SSH) — non garantito su una parte
dell'hosting condiviso più economico, il vincolo dichiarato nel README.
Quattro alternative valutate.

### Scartata: file di configurazione compilato via FTP prima del primo accesso

Un file (`data/owner.setup.php`, sul modello di `wp-config.php`) compilato
a mano via FTP prima di aprire il sito, letto e consumato alla prima
richiesta utile. Chiude del tutto la corsa "chi arriva prima" — nessuna
finestra di rischio, nemmeno minima — ma il paragone con `wp-config.php`
non regge fino in fondo: quel file esiste per credenziali di *database*
spesso assegnate dall'hosting stesso, non per risolvere una corsa sulla
registrazione dell'amministratore. Qui costringerebbe a editare a mano un
array PHP dentro un client FTP, un passaggio scomodo e facile da sbagliare
(virgole, apici, incoraggia copia-incolla di valori sbagliati) per
eliminare un rischio che, come emerge sotto, è già basso di suo.

### Scartata: wizard nel browser con prova di controllo file via FTP

Stessa garanzia di sicurezza della precedente, in due passaggi (browser →
FTP → browser) invece di uno, più uno stato intermedio (codice generato,
con una sua scadenza) e un endpoint dedicato. Stesso costo della
precedente, nessun vantaggio in più.

### Scartata: generazione della configurazione da Fluxus

Discussa in due varianti, entrambe scartate. La versione leggera (Fluxus
scrive solo un piccolo file di configurazione in un formato documentato)
resta in tensione con "l'unico punto di contatto fra Fluxus e Connect è
l'API pubblica documentata" (istruzioni locali del repository) — un
secondo canale non dichiarato fra i due prodotti. La versione più pesante, discussa per prima
(Fluxus scarica temporaneamente da GitHub l'intero codice di Connect,
inietta il file di credenziali, e lo ripacchetta per il download sul
computer dell'utente, da caricare poi via FTP) aggrava il problema invece
di risolverlo: Fluxus dovrebbe conoscere la struttura interna del repo di
Connect e il suo processo di pacchettizzazione — nessuno dei due garantiti
stabili verso l'esterno, a differenza dell'API versionata — e restare
sincronizzato a ogni cambio di Connect, con due repository a
versionamento indipendente (Fase 0). In più non risolve il caso generale:
Connect è multi-tenant fin da subito, quindi il proprietario del pannello
è una proprietà dell'installazione Connect, non di un singolo Pi — non è
chiaro quale, fra più Fluxus che puntano alla stessa Connect, sarebbe
"autorizzato" a generarlo, e l'idea non copre affatto un'installazione di
Connect usata senza che nessun Fluxus l'abbia mai preceduta.

### Scelta: creazione del proprietario al primo accesso, senza prova preventiva

Stesso schema di WordPress, phpBB, Nextcloud e la gran parte delle
applicazioni PHP self-hosted: se `fcOwnerExists()` è falso, il pannello
mostra un form "crea il proprietario" (stessa validazione già in
`fcOwnerSetPassword()`, stessa protezione CSRF del resto del pannello)
invece del login; il primo invio valido crea il proprietario e chiude la
porta per sempre — da quel momento la stessa rotta mostra login, non più
un form di creazione, nessuna riapertura possibile via richiesta HTTP.

Nessuna prova di controllo del file system, quindi in teoria una corsa
esiste: chi scopre l'indirizzo prima del proprietario legittimo (per
esempio dai log di Certificate Transparency, non serve altro) potrebbe
inviare il form per primo. Accettata deliberatamente, per motivi specifici
di come Connect viene messo in produzione — non un'accettazione generica
del rischio:

- **Rilevamento quasi immediato**: chi carica i file e visita il pannello
  lo fa nello stesso momento, aspettandosi il form di creazione. Trovare
  invece un login è un segnale immediato e inequivocabile, diverso da un
  sito con mesi di traffico dove un account intruso può restare invisibile.
- **Raggio di danno quasi nullo nella finestra**: è il primissimo avvio,
  nessun Pi si è ancora registrato, nessun dato reale esiste da
  raggiungere — il peggio che un occupante abusivo vede è una dashboard
  vuota.
- **Recupero senza capacità in più**: cancellare `data/owner.json` (via
  FTP o file manager) e ripresentarsi riapre il form di creazione da capo.
  Usa lo stesso accesso già necessario per caricare il codice — non
  richiede SSH nemmeno nel caso limite, il vincolo che ha aperto questa
  intera decisione resta rispettato anche nel percorso di recupero.

Rafforzamento a costo marginale, incluso nella scelta: `owner.json` registra
anche `created_by_ip` (e `created_via`: `'wizard'` o `'cli'`) al momento
della creazione — non un log a parte, solo due campi in più sulla stessa
struttura che già porta `created_at`. Non impedisce una corsa persa, ma dà
al proprietario legittimo una conferma forense immediata se il login
inatteso lo insospettisce, oltre al segnale già in sé sufficiente del punto
sopra.

`bin/create-owner.php` resta invariato: percorso alternativo per chi ha
SSH, sia per la creazione iniziale sia per il recupero (sostituisce un
proprietario esistente con conferma esplicita, comportamento già presente).
Il meccanismo del wizard è additivo, non un rimpiazzo.

## Livello 1 — Follow (sola lettura)

Endpoint concettuali sotto `/api/v1/follow/*` (namespace pubblico, distinto
dall'API riservata al Pi):

- lettura dello stato delle registrazioni attive: id, sorgente, tipo media,
  stato, orario di inizio/secondi trascorsi, numero di marker;
- identità del nodo (per un consumer che segue più Fluxus/console
  contemporaneamente).

**Mai esporre**: percorsi del filesystem del Pi, PID di processo, note
interne, e nulla della configurazione delle sorgenti (possono contenere
credenziali embedded). Il follow è uno specchio dello stato, non
un'estensione della configurazione interna del Pi.

## Livello 2 — Controllo (marker/cue; avvio/stop PENDING)

Va tenuto separato dal follow non per prudenza formale ma per raggio di
danno diverso: un marker sbagliato è recuperabile, uno stop su una
registrazione live no. Per il v1: whitelist stretta, solo marker/cue. Scope
dedicato (`follow+control`) sulla stessa tabella di sotto-chiavi, mai attivo
di default.

## Multi-registrazione (estensione a Fase 4/5, prima della Fase 6)

Fluxus può registrare da più sorgenti contemporaneamente. Il contratto
originale della Fase 4 assumeva una sola registrazione attiva per Pi — un
limite scoperto proprio preparando la Fase 6 (script sul Pi, in
`fluxus-src`), che deve poter dire a Connect "sto registrando N cose alla
volta", e le console esterne devono poter scegliere a quale delle N si
riferisce un marker/cue. Tre decisioni, discusse qui perché toccano il
contratto pubblico già versionato.

### Follow multi-registrazione: campo array, non sostituzione

`GET /follow/status.php` ora espone `registrations` (array, anche vuoto):
tutte le registrazioni attive, ciascuna con il proprio `id`, `state`,
`source`, `media_type`, `started_at`, `elapsed_seconds`, `marker_count`. I
campi scalari già esistenti in cima alla risposta restano, come mirror di
sola compatibilità della prima registrazione dell'elenco.

Le due strade valutate:

- **Sostituzione**: `status.json` (e la risposta di `follow/status.php`)
  passano a un solo campo `registrations`, i vecchi campi scalari
  spariscono. Più pulito da leggere, ma è un cambio di forma incompatibile
  su un endpoint già versionato `/api/v1/`.
- **Campo array accanto agli scalari** (scelta fatta): `registrations` si
  aggiunge, gli scalari restano e continuano a rispecchiare la prima
  registrazione dell'elenco.

La ragione per non aprire una `/api/v2/follow/status.php` non è "conviene
risparmiare lavoro", ma che il cambio è genuinamente additivo, non
distruttivo: prima di questa estensione **non esisteva alcun modo di
pubblicare più di una registrazione alla volta** (la Fase 6 che lo
richiede non è ancora stata costruita). Un client scritto contro il
contratto di prima ha per costruzione un solo Pi con una sola
registrazione in mente: continua a leggere gli stessi campi scalari e a
funzionare esattamente come prima. Non sta perdendo una capacità che aveva
— sta semplicemente non vedendo ancora una capacità nuova, che gli era
comunque preclusa fino a un attimo fa. È una situazione diversa da
un'incompatibilità vera (un campo che cambia tipo, un valore che cambia
significato sotto lo stesso nome): quella avrebbe richiesto `/api/v2/`,
seguendo la convenzione già scritta in `openapi.yaml` (sezione
`servers`). Con più di una registrazione attiva lo specchio in cima smette
di rappresentare la situazione per intero (mostra solo la prima) — ma
questo è onestamente documentato nello schema, non nascosto: chi vuole la
visione completa, o deve scegliere un bersaglio, legge `registrations`.

Conseguenza sul lato Pi: `POST /api/pi/status.php` (Fase 3, API riservata,
mai pubblica/versionata) accetta ora solo la forma `{"registrations":
[...]}`, senza equivalente scalare. Qui un cambio non additivo non pone lo
stesso problema: non è un'API pubblica, non è mai stata documentata con
OpenAPI, e — soprattutto — non ha ancora nessun client, dato che lo script
della Fase 6 non è stato scritto. È il momento più economico per
cambiarla, prima che esista qualcosa da rompere.

### Comando con bersaglio esplicito: whitelist, non euristica

`POST /control/commands.php` accetta ora `target_id`: l'`id` della
registrazione (fra quelle elencate da `registrations`) a cui si applica il
comando. Obbligatorio se più di una registrazione è attiva; con una sola
registrazione attiva Connect lo assegna da sé (non è un'euristica — è
l'unica scelta possibile, non c'è nulla fra cui scegliere); con nessuna
registrazione attiva il comando resta accettato senza bersaglio, come
prima di questa estensione.

Un `target_id` che non compare fra le registrazioni correntemente attive
viene rifiutato con 400 — stessa forma già in uso per `type` (whitelist
stretta, non un tentativo di "correggere" o interpretare l'input). La
whitelist è calcolata leggendo lo stesso `status.json` che
`follow/status.php` già espone alla stessa sotto-chiave (una sotto-chiave
`follow+control` può sempre anche leggere il follow, vedi la tabella degli
scope più sotto): non trapela quindi nessuna informazione che questa
sotto-chiave non potesse già vedere.

Il punto di fondo, esplicitamente richiesto: la scelta di quale
registrazione riceve un comando deve farla la console che chiama, mai
un'euristica lato Connect o lato Pi ("prendi la più recente", "prendi la
prima trovata"). Un'euristica qui sposterebbe silenziosamente un marker
sulla registrazione sbagliata — un errore difficile da notare finché non
si guarda la registrazione giusta e non la si trova. Rifiutare con 400 è
scomodo (la console deve gestire il caso), ma è un errore rumoroso invece
di un dato silenziosamente sbagliato — stessa logica già scelta per la
whitelist di `type`.

### Nome della sotto-chiave nell'oggetto in coda, non solo nel log

`command_enqueued` già registrava `subkey_name` nel log di attività (audit
per il pannello), ma l'oggetto persistito in coda — quello che
`GET /api/pi/queue.php` restituisce al Pi — non lo includeva: il Pi non
aveva modo di sapere da quale console fosse arrivato un marker. La Fase 6
vuole mostrarlo in interfaccia, quindi `subkey_name` ora finisce anche
nell'oggetto comando, non solo nel log. Nessuna whitelist necessaria in
lettura su `queue.php`: è API riservata al Pi, non pubblica, e già oggi
non filtra il contenuto dei comandi (la whitelist si applica in scrittura,
quando il comando viene depositato — vedi il commento in cima a quel
file).

## Comportamento nei casi limite (collaudato in Fase 7)

La Fase 7 (collaudo end-to-end, condotta da `fluxus-src` con `connect_sync.php`
reale contro un'istanza locale di Connect) ha verificato sul campo i tre casi
limite che qualunque client del genere — non solo lo script di `fluxus-src` —
deve saper attraversare senza bloccarsi né perdere dati. Non sono
comportamenti impliciti: chiunque scriva un client diverso (un altro Fluxus,
uno script di terzi) deve implementarli allo stesso modo, perché **Connect
stesso non fa nulla per aiutare** in nessuno dei tre casi — si limita a
rispondere in modo prevedibile a ogni singola richiesta, è compito del client
gestire la sequenza nel tempo.

### Connect irraggiungibile

Dal punto di vista del client: la richiesta in uscita fallisce (timeout o
connessione rifiutata) — Connect non ha modo di distinguere "il Pi è offline"
da "il Pi non ha ancora richiamato", perché **non è lui a iniziare la
comunicazione** (vedi "Broker, non proxy né bridge"). Nessuno stato lato
Connect cambia: l'ultimo `status.json` pubblicato resta quello, "congelato",
finché il client non torna a scrivere.

Comportamento verificato in `connect_sync.php` (`fluxus-src`,
`fccsRequest()`): l'eccezione di rete viene catturata e loggata
(`fm-connect-sync.log`) senza propagarsi — un'interruzione di alcuni cicli non
blocca né il polling successivo né, soprattutto, la registrazione in corso
sul Pi (i due sono disaccoppiati: lo stato locale in SQLite non dipende da
Connect). Al primo ciclo utile dopo il ritorno di Connect, la sincronizzazione
riprende da sola — nessuna riconnessione esplicita da gestire, perché ogni
ciclo è una richiesta HTTP indipendente, non una sessione persistente.

### Sotto-chiave o token revocati a metà sessione

Una revoca (dal pannello, per le sotto-chiavi — non ancora costruita
un'interfaccia per revocare il token di primo livello di un Pi, vedi
"Più avanti") ha effetto **immediato**: la richiesta in corso al momento
della revoca completa con l'esito con cui era partita, ma la primissima
richiesta successiva riceve `401` con corpo `{"error": "token non valido"}`
(stesso messaggio sia per token del Pi sia per sotto-chiavi — non si
distingue il motivo per non rivelare a un chiamante non autenticato se un
identificativo è mai esistito). Nessun periodo di grazia, nessuna cache
lato Connect.

Verificato azzerando l'hash del token in `meta.json` del tenant mentre
`connect_sync.php` girava: ogni ciclo lo ha registrato con un errore
leggibile (`errore: POST /api/pi/status.php -> HTTP 401: {"error":"token non
valido"}`), mai un fallimento silenzioso — e al ripristino dell'hash corretto
la sincronizzazione è ripartita da sola al ciclo successivo, esattamente come
nel caso di Connect irraggiungibile. Chi gestisce un'istanza deve leggere
quel log per accorgersi di una revoca: Connect non manda altro avviso, e non
può (il Pi non è mai raggiungibile in ingresso).

### Un comando ritirato tardi, verso una registrazione già finita

`GET /api/pi/queue.php` restituisce sempre l'intera coda, senza filtrare per
validità corrente (vedi sopra) — è compito del client controllare, al momento
di eseguire ciascun comando, se il suo `target_id` è ancora fra le
registrazioni attive. Se il Pi ha smesso di ritirare per un po' (Connect
irraggiungibile per minuti, o semplicemente un turno saltato) e nel frattempo
la registrazione bersaglio è terminata, il comando arriva comunque, ma ormai
"orfano".

Verificato depositando un comando con `target_id` verso una registrazione
attiva, fermando quella registrazione, e solo dopo lasciando che
`connect_sync.php` la ritirasse: il comando viene **scartato e confermato**
(`POST /api/pi/ack.php`) nello stesso ciclo, con una riga di log esplicita
("target_id … non è (più) fra le registrazioni attive") — non un tentativo
infinito, non un marker creato sulla registrazione sbagliata per errore. La
console che lo ha depositato non riceve una notifica del mancato recapito:
lo saprebbe solo interrogando di nuovo `follow/status.php` e non trovando il
marker atteso — un limite noto, accettabile perché il raggio di danno di un
marker mancante è basso (vedi la tabella del modello di sicurezza) e
comunque non peggiore di quanto succedeva prima che questo controllo
esistesse.

## Convenzioni per l'API pubblica

- **REST su HTTP con JSON** — non GraphQL (overkill per pochi endpoint
  semplici), non gRPC (binario, richiede generare client — contrario alla
  semplicità voluta), non SOAP/XML (superato).
- **OpenAPI** come formato di descrizione — un file di testo (YAML/JSON) che
  documenta ogni endpoint, generato a mano, che permette documentazione
  navigabile (Swagger UI, solo file statici) e generazione di client da
  parte di chi integra.
- **Versione nell'indirizzo** (`/api/v1/...`): a differenza delle API
  interne di Fluxus (dove client e server sono la stessa squadra e possono
  cambiare insieme), qui il pubblico è esterno — una modifica incompatibile
  deve aprire una `/v2/`, non rompere silenziosamente chi ha già integrato.
- **Formato di risposta/errore coerente**, con i codici HTTP giusti (200,
  401, 404, 429 se mai servirà un limite di frequenza).
- **Non documentare endpoint "per il futuro"**: è un'API pubblica, qualcuno
  potrebbe iniziare a integrarla — documentare solo ciò che esiste davvero.

## Perché niente app-store / plugin

Valutato esplicitamente e scartato. Un meccanismo che permetta ad altre
applicazioni (a partire da Fluxus Remote) di girare *dentro* il processo di
Connect richiederebbe: un contratto interno stabile fra "app" e broker (un
piccolo SDK da progettare e mantenere), un meccanismo di
installazione/attivazione, e soprattutto **indebolirebbe l'isolamento fra
tenant** — nello stesso processo PHP, un bug in un plugin può raggiungere
dati di tenant che non gli appartengono, mentre con un confine di API vero
(rete, non memoria condivisa) il peggio che può fare un cliente compromesso
resta contenuto a quanto quel cliente stesso è autorizzato a fare.

Il beneficio che un plugin darebbe (più prodotti che sfruttano la capacità
comunicativa di Connect) si ottiene già, senza questo costo, semplicemente
lasciando che ogni prodotto — Fluxus Remote incluso, se in futuro vorrà
usare Connect — sia un **cliente dell'API pubblica** con la propria
sotto-chiave, esattamente come qualunque console esterna. Vedi la sezione
*Più avanti* di [ROADMAP.md](ROADMAP.md) per "Remote 1.5".

## Fluxus Remote 1.5 — cosa deve sapere la conversazione che lo costruisce

Decisione presa (vedi `ROADMAP.md`, "Fase W") di avviare, in un repository
ulteriore non ancora creato da questa conversazione, la riscrittura di
Fluxus Remote annunciata in "Più avanti" fin dall'impalcatura iniziale.
Questa sezione fissa il contratto e i confini che quella conversazione deve
rispettare — non decide nulla della sua architettura interna, che resta una
scelta propria di quel repository, esattamente come questo repository ha
fatto le proprie.

### Non confondere con la vecchia versione

**Fluxus Remote** (senza numero) è il relay già in produzione, altrove
(`https://vm.spazioumano.org/fluxus-remote`), con una propria interfaccia
web e una propria coda in un database che gestisce da sé. Resta **invariato
e in funzione**: nessuna delle informazioni qui sotto autorizza a toccarne
il codice, la configurazione o i dati. Sul Pi, il suo lato client è
`scripts/remote_sync.php` (in `fluxus-src`), pilotato dal timer
`fm-remote-sync.timer` (ogni 5s) e dai segreti in
`/etc/fluxus/<istanza>.remote.conf` (`FLUXUS_REMOTE_URL`,
`FLUXUS_REMOTE_API_KEY`, `FLUXUS_NODE_NAME`) — anche questi restano
invariati. Un'istanza Fluxus può avere Remote (vecchio) e Connect attivi
insieme, come due integrazioni indipendenti: passare un'istanza dal vecchio
Remote al nuovo è una decisione operativa separata, fuori dallo scopo di
questa fase.

**Fluxus Remote 1.5** è un prodotto nuovo, in un repository nuovo, che non
tocca né sostituisce nulla di quanto sopra sul momento — è pensato per
poterlo affiancare, e solo eventualmente rimpiazzare in futuro, a
migrazione decisa altrove.

### Cosa faceva il vecchio Remote, per riferimento sulle funzionalità

Non da copiare, solo da tenere a mente per non perdere capacità già offerte
agli utenti. Il vecchio relay espone (lato Pi, consumato da
`remote_sync.php`): `POST /api/sync` (il Pi invia `node_name` e l'elenco
delle registrazioni attive), `GET /api/queue?status=pending` (coda di
marker/cue creati da un pulsante nell'interfaccia del relay, con
`id`, `recording_id`, `type`, `label`, `created_at`), `POST /api/queue/ack`
(conferma degli id elaborati). Lato umano, un'interfaccia web mostra le
registrazioni attive e una "pulsantiera" per depositare marker/cue — è
questa esperienza, non questo protocollo, che Remote 1.5 deve replicare.

### Cosa cambia con Remote 1.5: nessuna coda propria, cliente di Connect

Il Pi **non ha bisogno di alcun nuovo codice per Remote 1.5**: il suo lato
è già `scripts/connect_sync.php` (Fase 6, già scritto e collaudato in
Fase 7-8), che parla con Connect ogni 2s indipendentemente da qualunque
console lo consumi. Remote 1.5 si collega da fuori, come una console
qualunque:

- **Autenticazione**: una sotto-chiave emessa dal pannello di
  amministrazione di *questa* installazione di Connect, per il tenant
  (Pi) che Remote 1.5 deve seguire — scope `follow` se serve solo la
  vista di stato, `follow+control` se deve anche depositare marker/cue.
  Nessun token di primo livello del Pi: quello resta esclusivo dell'API
  riservata (`/api/pi/...`).
- **Lettura di stato**: `GET /api/v1/follow/status.php` — restituisce
  `registrations` (array, può contenere più di una registrazione attiva
  insieme: vedi "Multi-registrazione" sopra). Remote 1.5 non deve
  assumere una sola registrazione per Pi: se ce n'è più di una, chi preme
  il pulsante deve poter scegliere a quale si riferisce.
- **Deposito comandi**: `POST /api/v1/control/commands.php`, whitelist
  stretta `type` ∈ {`marker`, `cue`}, `target_id` obbligatorio se più di
  una registrazione è attiva (l'`id` va preso da `registrations`, mai
  indovinato o scelto euristicamente da Remote 1.5 stesso). Avvio/stop
  restano PENDING/FUTURO — non implementarli anche se un utente li
  richiedesse, stesso limite già fissato per l'API pubblica in generale.
- **Contratto autorevole**: `public/docs/openapi.yaml` di questo
  repository (navigabile anche come Swagger UI in `public/docs/`) — non
  indovinare la forma delle richieste/risposte, è già documentata e
  collaudata.

Conseguenza architetturale: Remote 1.5 non ha più bisogno di una propria
coda persistente né di conoscere lo stato delle registrazioni per conto
proprio — quella responsabilità è già di Connect. Cosa resta comunque una
decisione propria del nuovo repository, non fissata qui: come Remote 1.5
autentica gli *umani* che premono i suoi pulsanti (il proprio pannello di
accesso), se e come conserva una propria configurazione per sapere quale
sotto-chiave/tenant seguire, e ogni altra scelta di implementazione — sullo
stesso principio per cui questo repository non ha deciso l'architettura
interna di `fluxus-src` né viceversa.

## Tabella riassuntiva del modello di sicurezza

| Combinazione | Meccanismo | Fiducia richiesta | Raggio di danno se compromessa |
|---|---|---|---|
| **Follow** | `Authorization: Bearer <sotto-chiave>`, scope `follow` | Bassa/media — sola lettura, dati filtrati | Fuga di informazioni operative; nessun impatto su registrazioni in corso |
| **Controllo (marker/cue)** | Sotto-chiave scope `follow+control`, whitelist azioni | Alta — può alterare l'esito di una registrazione | Marker falsi/mancanti — mai una via di rientro diretta verso il Pi |
| **Controllo (avvio/stop)** | PENDING/FUTURO | Molto alta | Registrazioni perse o in conflitto con l'operatore locale — non ancora ritenuto un rischio accettabile |
