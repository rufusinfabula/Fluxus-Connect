# Fluxus Connect

**Broker di comunicazione per Fluxus, pensato per hosting economici.**
Fa da intermediario fra un'installazione di Fluxus (su Raspberry Pi, in rete
privata) e sistemi esterni — console di regia, software di scaletta, o
qualunque altro programma che voglia seguire o inviare istruzioni a una
registrazione — senza che il Pi debba mai ricevere connessioni in ingresso.

> **Versione `1.0.0` — rilasciato.** Motore di storage, pannello di
> amministrazione, API riservata al Pi e API pubblica per le console
> esterne (con supporto multi-registrazione) sono scritti, collaudati
> end-to-end e in uso reale: lo script di sincronizzazione sul Pi e una
> console esterna vera sono collegati. Vedi
> [docs/CHANGELOG.md](docs/CHANGELOG.md).

---

## Il problema che risolve

Fluxus gira in rete privata per scelta: nessuna porta esposta a Internet.
Questo protegge la macchina, ma impedisce anche a un sistema esterno
legittimo di sapere "sta registrando?" o di lasciargli un'istruzione. Fluxus
Connect fa da bacheca condivisa: il Pi esce periodicamente a leggerla e a
scriverla (mai il contrario); i sistemi esterni la leggono e ci scrivono
tramite un'API pubblica.

Non è un proxy né un ponte verso il Pi: nessuno, nemmeno Connect stesso,
raggiunge mai il Pi direttamente. È sempre il Pi a uscire.

## Come funziona, in breve

Ogni installazione di Fluxus registrata su Connect ha una propria "cassetta"
isolata dalle altre, in due scomparti:

- **stato** — il Pi vi pubblica ogni pochi secondi cosa sta facendo (sta
  registrando? cosa? da quanto?); chi segue da fuori legge l'ultimo
  bigliettino lasciato lì.
- **comandi in coda** — sistemi esterni autorizzati vi depositano richieste
  (es. "segna questo momento"); il Pi le ritira una alla volta al giro di
  polling successivo e le esegue.

Ogni Pi ha una propria chiave; ogni sistema esterno autorizzato a scrivere
nella sua cassetta riceve una propria sotto-chiave, revocabile
singolarmente.

## Requisiti

- Solo PHP — niente database, niente estensioni oltre quelle di base.
  Pensato per girare anche sull'hosting condiviso più economico.
- Dati in file semplici, non in un database: una cartella per tenant,
  scritture atomiche.

## Documentazione

| | |
|---|---|
| [Changelog](docs/CHANGELOG.md) | cosa è cambiato in ogni versione |
| [OpenAPI](public/docs/openapi.yaml) | contratto dell'API pubblica (anche navigabile via Swagger UI in `public/docs/`) |

## Relazione con gli altri prodotti Fluxus

- **Fluxus** (repository `Fluxus`) — l'installazione che registra davvero,
  sul Raspberry Pi. Consuma l'API di Connect come cliente in uscita, mai il
  contrario.
- **Fluxus Remote** — relay indipendente già esistente per marker/cue da
  fuori LAN, con propria interfaccia. Resta un prodotto a sé: non gira
  dentro Connect né viene assorbito da esso.

## Licenza

Fluxus Connect — broker di comunicazione per Fluxus.
Copyright © 2026 Fabio Ranfi.

Distribuito secondo i termini della **GNU Affero General Public License
v3.0** (o, a scelta di chi lo usa, una versione successiva) — testo completo
in [LICENSE](LICENSE). In breve: si può usare, modificare e ridistribuire
liberamente; chi fa girare una versione modificata come servizio
raggiungibile in rete deve mettere a disposizione di chi lo usa il codice
sorgente di quella versione, comprese le modifiche — la clausola che conta
di più proprio per un broker come questo, pensato per girare come servizio.
