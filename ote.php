<?php
/**
 * ote.php - Stažení cen z OTE, cache a přepočet na Kč/kWh (sdíleno pro index.php a evcc-ote.php)
 */

require_once __DIR__ . '/config.php';

/**
 * Vrátí SOAP klienta OTE (jedna instance na požadavek)
 */
function oteClient(): SoapClient {
    static $client = null;

    if ($client === null) {
        if (!extension_loaded('soap')) {
            throw new RuntimeException("Rozšíření PHP 'soap' není dostupné.");
        }
        ini_set('default_socket_timeout', (string)OTE_TIMEOUT);
        // Výchozí /tmp na Windows nemusí existovat, WSDL cachujeme k ostatním datům
        if (oteEnsureCacheDir()) {
            ini_set('soap.wsdl_cache_dir', CACHE_DIR);
        }
        $client = new SoapClient(OTE_WSDL, [
            'connection_timeout' => OTE_TIMEOUT,
            'cache_wsdl' => WSDL_CACHE_BOTH,
            'exceptions' => true,
        ]);
    }

    return $client;
}

/**
 * Počet period (hodin) v daném dni - 23 / 24 / 25 podle přechodu letní/zimní čas
 */
function oteHoursInDay(string $date): int {
    $tz = new DateTimeZone('Europe/Prague');
    $start = new DateTimeImmutable($date . ' 00:00:00', $tz);
    $end = $start->modify('+1 day');
    return intdiv($end->getTimestamp() - $start->getTimestamp(), 3600);
}

/**
 * Stáhne surová data jednoho dne z OTE.
 * Vrací null, pokud ceny ještě nejsou zveřejněné (zítřek cca před 13:00).
 */
function oteFetchDay(string $date): ?array {
    $client = oteClient();
    $params = ['StartDate' => $date, 'EndDate' => $date];

    // Hodinové ceny (EUR/MWh)
    $response = $client->__soapCall('GetDamPricePeriodE', [$params + ['PeriodResolution' => 'PT60M']]);
    $items = $response->Result->Item ?? null;
    if (empty($items)) {
        return null;
    }
    if (!is_array($items)) $items = [$items];

    $prices = [];
    foreach ($items as $item) {
        if (($item->Date ?? null) !== $date || !isset($item->PeriodIndex)) continue;
        $price = is_numeric($item->HourlyPrice ?? null) ? $item->HourlyPrice : ($item->Price ?? null);
        if (!is_numeric($price)) continue;
        $prices[(int)$item->PeriodIndex] = (float)$price;
    }

    // Neúplná data považujeme za nezveřejněná
    if (count($prices) !== oteHoursInDay($date)) {
        return null;
    }
    ksort($prices);

    // Kurz EUR/CZK - při chybě se použije záložní kurz, ceny už máme
    $eurRate = null;
    try {
        $indexResponse = $client->__soapCall('GetDamIndexE', [$params]);
        $indexItems = $indexResponse->Result->DamIndex ?? [];
        if (!is_array($indexItems)) $indexItems = [$indexItems];

        foreach ($indexItems as $indexItem) {
            if (($indexItem->Date ?? null) === $date && is_numeric($indexItem->EurRate ?? null)) {
                $eurRate = (float)$indexItem->EurRate;
            }
        }
    } catch (Throwable $e) {
        error_log("OTE: chyba při získání kurzu EUR/CZK pro $date: " . $e->getMessage());
    }

    return [
        'date' => $date,
        'resolution' => 60, // minut
        'eurRate' => $eurRate,
        'prices' => $prices,
    ];
}

/**
 * Zajistí existenci adresáře cache
 */
function oteEnsureCacheDir(): bool {
    if (is_dir(CACHE_DIR) || @mkdir(CACHE_DIR, 0775, true)) {
        return true;
    }
    error_log('OTE cache: nelze vytvořit adresář ' . CACHE_DIR);
    return false;
}

/**
 * Atomický zápis do cache (chyba zápisu se jen zaloguje, cache je volitelná)
 */
function oteCacheWrite(string $file, array $data): void {
    $json = json_encode($data);
    if ($json === false || !oteEnsureCacheDir()) {
        return;
    }

    // Náhodné jméno - PID je u vláknového PHP (mod_php na Windows) stejné pro souběžné požadavky
    $tmp = $file . '.' . bin2hex(random_bytes(6)) . '.tmp';
    if (@file_put_contents($tmp, $json, LOCK_EX) === false || !@rename($tmp, $file)) {
        @unlink($tmp);
        error_log('OTE cache: nelze zapsat ' . $file);
        return;
    }

    // Úklid starých souborů (včetně případných pozůstalých .tmp)
    foreach (glob(CACHE_DIR . '/ote-*') ?: [] as $old) {
        if (filemtime($old) < time() - CACHE_KEEP_DAYS * 86400) {
            @unlink($old);
        }
    }
}

/**
 * Surová data dne - z cache, nebo z OTE.
 * $cacheMissing: zda si pamatovat "ještě nezveřejněno" (pro dnešek ne, ceny tam mají být vždy)
 */
function oteGetDay(string $date, bool $cacheMissing = true): ?array {
    $file = CACHE_DIR . "/ote-$date.json";
    $cached = null;

    if (is_file($file)) {
        $cached = json_decode((string)@file_get_contents($file), true);
        if (is_array($cached)) {
            $fresh = time() - filemtime($file) < CACHE_MISSING_TTL;
            $hasPrices = !empty($cached['prices']);

            // Kompletní data, nebo data bez kurzu, která se po TTL zkusí doplnit
            if ($hasPrices && (($cached['eurRate'] ?? null) !== null || $fresh)) {
                return $cached;
            }
            // Záznam "ještě nezveřejněno" - OTE se znovu ptáme až po vypršení TTL
            if (!$hasPrices && $fresh && $cacheMissing) {
                return null;
            }
        } else {
            $cached = null;
        }
    }

    try {
        $day = oteFetchDay($date);
    } catch (Throwable $e) {
        // OTE nedostupné - raději starší data (bez kurzu) než nic
        if (!empty($cached['prices'])) {
            error_log("OTE: chyba při stahování $date, použita data z cache: " . $e->getMessage());
            return $cached;
        }
        throw $e;
    }

    if ($day === null) {
        if ($cacheMissing) {
            oteCacheWrite($file, ['prices' => []]);
        }
        return null;
    }

    if ($day['eurRate'] === null) {
        error_log("OTE: chybí kurz EUR/CZK pro $date, použit záložní kurz " . DEFAULT_EUR_CZK_RATE);
    }
    oteCacheWrite($file, $day);

    return $day;
}

/**
 * Převede surová data dne na periody s časy a cenami v Kč/kWh.
 * Čas periody se počítá od půlnoci v UTC, takže sedí i ve dnech se změnou času (23/25 hodin).
 */
function otePeriods(array $day): array {
    $prague = new DateTimeZone('Europe/Prague');
    $utc = new DateTimeZone('UTC');

    $midnightUtc = (new DateTimeImmutable($day['date'] . ' 00:00:00', $prague))->setTimezone($utc);
    $eurCzkRate = $day['eurRate'] ?? DEFAULT_EUR_CZK_RATE;
    $minutes = (int)$day['resolution'];

    $periods = [];
    $seenLabels = [];
    foreach ($day['prices'] as $periodIndex => $priceEurMwh) {
        $start = $midnightUtc->modify('+' . (((int)$periodIndex - 1) * $minutes) . ' minutes');
        $end = $start->modify("+$minutes minutes");
        $localStart = $start->setTimezone($prague);
        $localHour = (int)$localStart->format('G');

        $spotPrice = ($priceEurMwh / 1000) * $eurCzkRate; // Kč/kWh

        // Distribuce se řídí místním (českým) časem
        $isPeak = ($localHour >= HODINA_START_PEAK && $localHour < HODINA_KONEC_PEAK);
        $baseDistribuce = $isPeak ? SAZBA_DISTRIBUCE_PEAK : SAZBA_DISTRIBUCE_OFFPEAK;
        $totalPrice = ($spotPrice + $baseDistribuce) * DPH;

        // Při přechodu na zimní čas je hodina 02 dvakrát
        $label = sprintf('%02dh', $localHour);
        if (isset($seenLabels[$label])) $label .= ' (2.)';
        $seenLabels[$label] = true;

        $periods[] = [
            'start' => $start,           // UTC
            'end' => $end,               // UTC
            'localStart' => $localStart,
            'localEnd' => $end->setTimezone($prague),
            'localHour' => $localHour,
            'label' => $label,
            'spotPrice' => $spotPrice,   // Kč/kWh bez DPH a distribuce
            'totalPrice' => $totalPrice, // Kč/kWh vč. distribuce a DPH
        ];
    }

    return $periods;
}

/**
 * Periody pro dnešek a zítřek: [datum => periody].
 * Den, který se nepodaří získat, se vynechá; výjimka se vyhodí, jen když není nic.
 */
function oteGetUpcoming(): array {
    $today = date('Y-m-d');
    $tomorrow = (new DateTimeImmutable('tomorrow'))->format('Y-m-d');

    $result = [];
    $lastError = null;

    foreach ([$today => false, $tomorrow => true] as $date => $cacheMissing) {
        try {
            $day = oteGetDay($date, $cacheMissing);
            if ($day !== null) {
                $result[$date] = otePeriods($day);
            }
        } catch (Throwable $e) {
            error_log("OTE: nepodařilo se získat ceny pro $date: " . $e->getMessage());
            $lastError = $e;
        }
    }

    if (empty($result) && $lastError !== null) {
        throw $lastError;
    }

    return $result;
}
