<?php
/**
 * config.php - Společná konfigurace pro index.php a evcc-ote.php
 */

date_default_timezone_set('Europe/Prague');

// Chyby se nezobrazují, ale logují do data/ (adresář je chráněn přes .htaccess)
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/data/php-errors.log');

// --- CENY ---
const DPH = 1.21;
const SAZBA_DISTRIBUCE_PEAK = 2.8079;    // Kč/kWh bez DPH
const SAZBA_DISTRIBUCE_OFFPEAK = 0.6719; // Kč/kWh bez DPH
const HODINA_START_PEAK = 6;
const HODINA_KONEC_PEAK = 22;
const DEFAULT_EUR_CZK_RATE = 25.0;       // Záložní kurz, pokud OTE kurz nevrátí

// --- OTE API ---
const OTE_WSDL = 'https://www.ote-cr.cz/services/PublicDataService?wsdl';
const OTE_TIMEOUT = 30; // sekundy

// --- CACHE ---
const CACHE_DIR = __DIR__ . '/data/cache';
const CACHE_MISSING_TTL = 900; // Jak dlouho si pamatovat "ceny ještě nejsou zveřejněné" (s)
const CACHE_KEEP_DAYS = 7;     // Starší soubory v cache se mažou
