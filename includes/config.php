<?php
// Fluxus Connect — config.php
// Percorsi di base. FC_DATA_DIR è sovrascrivibile via variabile d'ambiente
// solo per i test (vedi tests/storage_test.php), che non devono mai
// scrivere dentro data/ nella copia di lavoro reale.

define('FC_ROOT', dirname(__DIR__));

$fcDataDir = getenv('FC_DATA_DIR');
define('FC_DATA_DIR', ($fcDataDir !== false && $fcDataDir !== '') ? $fcDataDir : FC_ROOT . '/data');
