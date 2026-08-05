<?php
// Fluxus Connect — API riservata al Pi: coda comandi in attesa (controllo).
// GET, autenticato col token di primo livello del Pi. Restituisce sempre
// l'intera coda in ordine FIFO (dal più vecchio) senza consumarla — il Pi
// conferma l'esecuzione di ciascun comando separatamente via ack.php. Vedi
// includes/storage.php, fcQueueList: sola lettura per costruzione.
//
// Nessuna validazione sul contenuto dei comandi qui: la whitelist delle
// azioni ammesse (solo marker/cue) va applicata da chi deposita un comando
// (Fase 4, non ancora costruita), non da chi lo ritira.

require_once __DIR__ . '/../../../includes/api.php';

fcApiRequireMethod('GET');
$tenantHash = fcApiRequireTenant();

fcApiJsonResponse(200, ['commands' => fcQueueList($tenantHash)]);
