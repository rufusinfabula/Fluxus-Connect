<?php
// Fluxus Connect — bootstrap.php
// Punto d'ingresso unico per le fasi successive (pannello, API): carica
// tutto il motore di storage senza che chi lo usa debba conoscere come è
// suddiviso internamente.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/tokens.php';
require_once __DIR__ . '/storage.php';
require_once __DIR__ . '/activity_log.php';
require_once __DIR__ . '/tenants.php';
require_once __DIR__ . '/subkeys.php';
require_once __DIR__ . '/owner.php';
