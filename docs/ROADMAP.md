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

## Fase 2 — Pannello di amministrazione → `0.2.0`
- Login del proprietario
- Creazione di un nuovo Pi: genera il token, mostrato una sola volta
- Creazione/revoca sotto-chiavi per console, con nome e scope (`follow` /
  `follow+control`)
- Log/attività per tenant

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

## Fase 6 — Collaudo end-to-end
- Prima con richieste dirette (`curl`) al posto di una console vera, per
  isolare i problemi
- Casi limite da provare esplicitamente: Connect irraggiungibile per un po',
  token revocato a metà sessione, comando rimasto in coda troppo a lungo

## Fase 7 — Prima integrazione reale e rilascio
- Collegare una prima console esterna vera (anche minima) per validare
  l'intero flusso
- Solo a questo punto: sezione dedicata in `docs/NOTE-TECNICHE.md` di
  fluxus-src, ed eventuale voce nella sua `docs/ROADMAP.md`

---

## Più avanti

- **Fluxus Remote 1.5**: nuovo repository, riscrittura di Fluxus Remote che
  usa l'API pubblica di Connect (come un cliente qualunque, con una propria
  sotto-chiave) invece della propria logica di coda indipendente. Da
  valutare solo dopo che Connect avrà dimostrato affidabilità sul campo con
  traffico reale — non prima, per non rischiare una funzionalità già in
  produzione. Tre opzioni erano state confrontate in fase di progettazione
  (cliente API separato / plugin dentro Connect / fusione completa): la
  riscrittura come cliente API resta quella consigliata quando se ne
  riparlerà, per lo stesso motivo per cui Connect stesso non ha un
  meccanismo di plugin.
- **Avvio/stop registrazione da remoto**: resta un salto di fiducia a parte
  rispetto a marker/cue — un comando in ritardo di polling non ha, a
  differenza di un marker, un modo di essere "corretto" a posteriori.
  PENDING/FUTURO.
- **Limiti di frequenza (rate limiting)** sull'API pubblica — da aggiungere
  se e quando serve davvero, non preventivamente.

Nota su fluxus-src: la comunicazione Pi→Connect (script di sincronizzazione,
file di segreti, timer systemd) vive in quel repository, non in questo, e
non ha ancora un numero di fase nella sua `ROADMAP.md` — da decidere quando
si arriva a costruirla.
