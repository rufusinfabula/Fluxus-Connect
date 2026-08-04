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

## Tabella riassuntiva del modello di sicurezza

| Combinazione | Meccanismo | Fiducia richiesta | Raggio di danno se compromessa |
|---|---|---|---|
| **Follow** | `Authorization: Bearer <sotto-chiave>`, scope `follow` | Bassa/media — sola lettura, dati filtrati | Fuga di informazioni operative; nessun impatto su registrazioni in corso |
| **Controllo (marker/cue)** | Sotto-chiave scope `follow+control`, whitelist azioni | Alta — può alterare l'esito di una registrazione | Marker falsi/mancanti — mai una via di rientro diretta verso il Pi |
| **Controllo (avvio/stop)** | PENDING/FUTURO | Molto alta | Registrazioni perse o in conflitto con l'operatore locale — non ancora ritenuto un rischio accettabile |
