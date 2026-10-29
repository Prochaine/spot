<?php

// ============================================================================
// KONFIGURACE
// ============================================================================

// Společná konfigurace (sazby, DPH, logování, časová zóna) a práce s OTE
require_once __DIR__ . '/ote.php';

$config = [
    'target_hours_d' => [22, 23],
    'target_hours_dplus1' => [0, 1, 2, 3, 4, 5],
    'block_lengths' => [1, 2, 3, 4, 5, 6, 7],
    'enable_debug' => false, // Nastavte na true pro debug režim
];

// České názvy dnů v týdnu (pondělí je index 1)
$czechDays = [
    1 => 'pondělí', 2 => 'úterý', 3 => 'středu', 4 => 'čtvrtek',
    5 => 'pátek', 6 => 'sobotu', 7 => 'neděli',
];

// ============================================================================
// POMOCNÉ FUNKCE PRO VÝSTUP
// ============================================================================

function showError($title, $message = '', $details = '') {
    echo '<div class="error">';
    echo '<h3>❌ ' . htmlspecialchars($title) . '</h3>';
    if ($message) echo '<p>' . htmlspecialchars($message) . '</p>';
    if ($details && $GLOBALS['config']['enable_debug']) {
        echo '<details><summary>Technické detaily</summary><pre>' . htmlspecialchars($details) . '</pre></details>';
    }
    echo '</div>';
}

function showWarning($message) {
    echo '<div class="warning"><p>⚠️ ' . htmlspecialchars($message) . '</p></div>';
}

function showInfo($message) {
    echo '<div class="info"><p>ℹ️ ' . htmlspecialchars($message) . '</p></div>';
}

function formatPrice(float $price): string {
    return number_format($price, 2, ',', ' ');
}

// ============================================================================
// ANALYTICKÉ FUNKCE
// ============================================================================

/**
 * Najde nejlevnější souvislý blok dané délky v nočním tarifu.
 * $nightBlock je časově seřazený seznam period z otePeriods().
 */
function findCheapestBlock(array $nightBlock, int $length) {
    $count = count($nightBlock);
    if ($length <= 0 || $length > $count) {
        return null;
    }

    $cheapestBlock = null;

    for ($i = 0; $i <= $count - $length; $i++) {
        $slice = array_slice($nightBlock, $i, $length);
        $currentCost = array_sum(array_column($slice, 'spotPrice'));

        if ($cheapestBlock === null || $currentCost < $cheapestBlock['total_cost']) {
            $cheapestBlock = [
                'start_time' => $slice[0]['localStart']->format('H:i'),
                'end_time' => $slice[$length - 1]['localEnd']->format('H:i'),
                'total_cost' => $currentCost,
                'avg_cost_kwh' => $currentCost / $length,
                'length' => $length
            ];
        }
    }

    return $cheapestBlock;
}

// ============================================================================
// ZÍSKÁNÍ A ZPRACOVÁNÍ DAT (před jakýmkoli výstupem)
// ============================================================================

$errors = [];
$dailyDataForCharts = [];
$nightBlock = [];
$blockResults = [];
$finalPricesData = [];

$today = date('Y-m-d');
$tomorrow = (new DateTimeImmutable('tomorrow'))->format('Y-m-d');

try {
    foreach (oteGetUpcoming() as $date => $periods) {
        // Data pro grafy
        $dayOfWeekNumber = (new DateTime($date))->format('N');
        $dailyDataForCharts[$date] = [
            'dayOfWeek' => $czechDays[$dayOfWeekNumber],
            'labels' => array_column($periods, 'label'),
            'totalPrices' => array_map(fn($p) => round($p['totalPrice'], 2), $periods),
            'spotPrices' => array_map(fn($p) => round($p['spotPrice'], 2), $periods),
        ];

        // Periody pro noční analýzu (seznam, ne podle hodiny - při změně času je hodina 02 dvakrát)
        foreach ($periods as $period) {
            $isTargetNightHour =
                ($date === $today && in_array($period['localHour'], $config['target_hours_d'])) ||
                ($date === $tomorrow && in_array($period['localHour'], $config['target_hours_dplus1']));

            if ($isTargetNightHour) {
                $nightBlock[] = $period;
            }
        }
    }
} catch (Throwable $e) {
    error_log('index: ' . $e->getMessage());
    $errors[] = [
        'title' => 'Chyba při komunikaci s OTE API',
        'message' => 'Nepodařilo se získat data ze serveru OTE.',
        'details' => $e->getMessage(),
    ];
}

if (empty($errors) && empty($dailyDataForCharts)) {
    $errors[] = [
        'title' => 'Nebyla získána žádná data',
        'message' => 'Odpověď z OTE API neobsahovala žádné platné cenové údaje.',
        'details' => '',
    ];
}

// Výsledky analýzy nejlevnějších bloků
foreach ($config['block_lengths'] as $length) {
    $block = findCheapestBlock($nightBlock, $length);
    if ($block) {
        // Konečná cena = (Spot + distribuce offpeak) * DPH
        $block['final_avg_price'] = ($block['avg_cost_kwh'] + SAZBA_DISTRIBUCE_OFFPEAK) * DPH;
        $finalPricesData[$length] = $block['final_avg_price'];
    }
    $blockResults[$length] = $block;
}

?>
<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>🔌 Analýza spotové ceny elektřiny</title>
    <link rel="icon" type="image/svg+xml" href="favicon.svg">
    <link rel="icon" type="image/x-icon" href="favicon.ico" sizes="32x32">
    <link rel="apple-touch-icon" href="apple-touch-icon.png">
    <script src="https://cdn.jsdelivr.net/npm/apexcharts@3.54.1/dist/apexcharts.min.js"></script>
    <style>
        body { font-family: Arial, sans-serif; max-width: 1200px; margin: 0 auto; padding: 20px; }
        .chart-container { margin-bottom: 20px; }
        table { border-collapse: collapse; width: 100%; margin-top: 20px; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f2f2f2; }
        .error { background-color: #ffebee; border: 1px solid #f44336; color: #c62828; padding: 15px; margin: 20px 0; border-radius: 4px; }
        .warning { background-color: #fff3e0; border: 1px solid #ff9800; color: #e65100; padding: 15px; margin: 20px 0; border-radius: 4px; }
        .info { background-color: #e3f2fd; border: 1px solid #2196f3; color: #0d47a1; padding: 15px; margin: 20px 0; border-radius: 4px; }
        .section-divider { margin: 40px 0; }
        .table-narrow { max-width: 40em; }
        .table-wide { max-width: 50em; }
        #resultsTable tr { transition: background-color 0.3s ease; }
        .results-heading, .calc-heading { margin-top: 30px; }
        .note { font-size: 0.8em; color: #666; }
        .calculator { max-width: 50em; padding: 20px; background-color: #f9f9f9; border-radius: 8px; border: 1px solid #ddd; }
        .calc-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 20px; max-width: 450px; }
        .calc-grid label { font-weight: bold; display: block; margin-bottom: 5px; }
        .calc-grid input { width: 90%; padding: 8px; }
        .soc-row { margin-bottom: 10px; }
        .soc-row label { font-weight: bold; display: block; margin-bottom: 10px; }
        .soc-control { display: flex; align-items: center; gap: 15px; }
        #socSlider { flex: 1; height: 8px; }
        #socValue { font-size: 24px; font-weight: bold; color: #2196f3; min-width: 60px; text-align: right; }
        .calc-results { margin-top: 25px; padding: 15px; background-color: white; border-radius: 5px; border: 1px solid #ddd; }
        .calc-results div { font-size: 1.1em; font-weight: bold; }
        #result80 { color: #4caf50; margin-bottom: 10px; }
        #result100 { color: #ff9800; }
        .footer-divider { margin-top: 30px; }
        .footer { font-size: 0.9em; color: #666; }
    </style>
</head>
<body>

<h1>🔌 Analýza spotové ceny elektřiny</h1>

<?php if (!empty($errors)): ?>
    <?php foreach ($errors as $error) showError($error['title'], $error['message'], $error['details']); ?>
<?php else: ?>

<!-- Sekce s grafy -->
<?php foreach ($dailyDataForCharts as $date => $data): ?>
    <div id="chart-<?= str_replace('-', '', $date) ?>" class="chart-container"></div>
<?php endforeach; ?>

<hr class="section-divider">

<!-- Sekce analýzy nočního bloku -->
<h2>⚡ Analýza nejlevnějšího nočního bloku (22:00 - 06:00)</h2>

<?php if (empty($nightBlock)): ?>
    <?php showWarning("Pro noční blok (22:00 - 06:00) nejsou k dispozici žádná data k analýze."); ?>
<?php else: ?>

    <!-- Tabulka vstupních cen -->
    <h4>📊 Vstupní hodinové ceny pro noční blok (Kč/kWh, bez DPH a distribuce):</h4>
    <table class="table-narrow">
        <tr><th>Čas dodávky</th><th>Spotová cena (Kč/kWh)</th><th>Celková cena (Kč/kWh)</th></tr>
        <?php foreach ($nightBlock as $period): ?>
        <tr>
            <td><?= $period['localStart']->format('H:i') ?> – <?= $period['localEnd']->format('H:i') ?></td>
            <td><?= formatPrice($period['spotPrice']) ?></td>
            <td><?= formatPrice($period['totalPrice']) ?></td>
        </tr>
        <?php endforeach; ?>
    </table>

    <!-- Tabulka výsledků analýzy -->
    <h3 class="results-heading">✅ Výsledky – Nejlevnější souvislé bloky</h3>
    <p>Analýza pro různé délky bloků v rámci nočního tarifu.</p>
    <table id="resultsTable" class="table-wide">
        <tr>
            <th>Délka bloku</th>
            <th>Nejlevnější úsek</th>
            <th>Průměrná spot. cena<br>(Kč/kWh)</th>
            <th>Konečná prům. cena*<br>(Kč/kWh)</th>
        </tr>
        <?php foreach ($blockResults as $length => $block): ?>
        <tr id="row-<?= $length ?>" data-length="<?= $length ?>">
            <td><b><?= $length ?> h</b></td>
            <?php if ($block): ?>
            <td><?= htmlspecialchars($block['start_time'] . ' – ' . $block['end_time']) ?></td>
            <td><b><?= formatPrice($block['avg_cost_kwh']) ?></b></td>
            <td><b><?= formatPrice($block['final_avg_price']) ?></b></td>
            <?php else: ?>
            <td colspan="3">Nelze analyzovat (nedostatek dat)</td>
            <?php endif; ?>
        </tr>
        <?php endforeach; ?>
    </table>
    <p class="note">
        *Konečná cena zahrnuje sazbu za distribuci v nízkém tarifu (<?= SAZBA_DISTRIBUCE_OFFPEAK ?> Kč/kWh) a DPH (<?= (DPH - 1) * 100 ?> %).</p>

    <!-- Kalkulačka nabíjení -->
    <h4 class="calc-heading">🔋 Kalkulačka nabíjení baterie</h4>
    <div class="calculator">
        <div class="calc-grid">
            <div>
                <label for="batteryCapacity">Kapacita baterie (kWh):</label>
                <input type="number" id="batteryCapacity" value="75" min="1" max="200">
            </div>
            <div>
                <label for="chargingPower">Nabíjecí výkon (kW):</label>
                <input type="number" id="chargingPower" value="11" min="1" max="50" step="0.1">
            </div>
        </div>

        <div class="soc-row">
            <label for="socSlider">Aktuální stav baterie (SOC):</label>
            <div class="soc-control">
                <input type="range" id="socSlider" min="0" max="100" value="50" step="1">
                <span id="socValue">50%</span>
            </div>
        </div>

        <div class="calc-results">
            <div id="result80">-</div>
            <div id="result100">-</div>
        </div>
    </div>

<?php endif; ?>
<?php endif; ?>

<!-- Footer -->
<hr class="footer-divider">
<p class="footer">
    Poslední aktualizace: <?= date('d.m.Y H:i:s') ?> |
    Zdroj dat: <a href="https://www.ote-cr.cz" target="_blank" rel="noopener">OTE, a.s.</a>
</p>

<script>
// Skript pro vykreslení grafů
<?php foreach ($dailyDataForCharts as $date => $data):
    $dateId = str_replace('-', '', $date);
?>
    (function() {
        const labels = <?= json_encode($data['labels']) ?>;
        const totalPrices = <?= json_encode($data['totalPrices']) ?>;
        const spotPrices = <?= json_encode($data['spotPrices']) ?>;
        const dayOfWeek = '<?= $data['dayOfWeek'] ?>';
        const isToday = '<?= $date ?>' === '<?= $today ?>';

        var now = new Date(),
            hourStr = String(now.getHours()).padStart(2, '0') + 'h';

        var options = {
            chart: { type: 'bar', height: 350, animations: { enabled: true } },
            series: [{ name: 'Spotová cena', data: totalPrices }],
            xaxis: { categories: labels },
            title: { text: `Cena elektřiny na ${dayOfWeek} (${new Date('<?= $date ?>').toLocaleDateString('cs-CZ')}) vč. DPH a distribuce` },
            yaxis: {
                title: { text: 'Cena (Kč/kWh)' },
                labels: { formatter: val => val.toFixed(2) }
            },
            legend: { show: false },
            tooltip: {
                y: { formatter: (val, { dataPointIndex }) => {
                    const currentSpotPrice = spotPrices[dataPointIndex];
                    return currentSpotPrice.toFixed(2) + ' Kč/kWh';
                }}
            },
            plotOptions: {
                bar: {
                    distributed: true,
                    dataLabels: { position: 'top' },
                    colors: {
                        // Pevná škála podle konečné ceny (Kč/kWh)
                        ranges: [
                            { from: -99.0, to: 1.0, color: '#00B894' },
                            { from: 1.0, to: 2.0, color: '#00E396' },
                            { from: 2.0, to: 3.0, color: '#77DD77' },
                            { from: 3.0, to: 4.0, color: '#C5E86C' },
                            { from: 4.0, to: 5.0, color: '#FFD700' },
                            { from: 5.0, to: 6.0, color: '#FFB300' },
                            { from: 6.0, to: 7.0, color: '#FF8C00' },
                            { from: 7.0, to: 8.0, color: '#FF6B3D' },
                            { from: 8.0, to: 9.0, color: '#FF4560' },
                            { from: 9.0, to: 10.0, color: '#D7263D' },
                            { from: 10.0, to: 99.0, color: '#8E1B3A' }
                        ]
                    }
                }
            },
            dataLabels: {
                enabled: true,
                formatter: val => val.toFixed(2),
                offsetY: -20,
                style: { fontSize: '12px', colors: ["#304758"] }
            },
            annotations: {}
        };

        if (isToday) {
            options.annotations.xaxis = [{
                x: hourStr,
                borderColor: '#00ae23',
                label: {
                    text: 'Nyní',
                    orientation: 'vertical',
                    style: { color: '#ffffff', background: '#00ae23' }
                }
            }];
        }

        var chart = new ApexCharts(document.querySelector("#chart-<?= $dateId ?>"), options);
        chart.render();
    })();
<?php endforeach; ?>

// Skript pro kalkulačku nabíjení
(function() {
    const finalPrices = <?= json_encode($finalPricesData) ?>;
    const capacityInput = document.getElementById('batteryCapacity');
    const powerInput = document.getElementById('chargingPower');
    const socSlider = document.getElementById('socSlider');
    const socValueSpan = document.getElementById('socValue');
    const result80Div = document.getElementById('result80');
    const result100Div = document.getElementById('result100');

    if (!capacityInput) return; // Pokud kalkulačka není na stránce, nic nedělat

    function highlightTableRows(hours80, hours100) {
        document.querySelectorAll('#resultsTable tr[data-length]').forEach(row => {
            row.style.backgroundColor = '';
            row.style.boxShadow = '';
        });

        if (hours80 > 0 && hours80 <= 7) {
            const row80 = document.getElementById('row-' + hours80);
            if (row80) row80.style.backgroundColor = '#c8e6c9';
        }

        if (hours100 > 0 && hours100 <= 7) {
            const row100 = document.getElementById('row-' + hours100);
            if (row100) {
                row100.style.backgroundColor = (hours80 === hours100) ? '#fff9c4' : '#ffe0b2';
            }
        }
    }

    function calculateChargingTime() {
        const soc = parseInt(socSlider.value);
        const capacity = parseFloat(capacityInput.value);
        const power = parseFloat(powerInput.value);

        if (isNaN(capacity) || isNaN(power) || power <= 0) {
            result80Div.textContent = '-';
            result100Div.textContent = '-';
            highlightTableRows(0, 0);
            return;
        }

        socValueSpan.textContent = soc + '%';

        const currentEnergy = (soc / 100) * capacity;

        // Cíl 80%
        if (soc >= 80) {
            result80Div.textContent = '✅ Dosažení 80%: Již nabito';
            result80Div.style.color = '#4caf50';
        } else {
            const energyTo80 = (0.80 * capacity) - currentEnergy;
            const timeTo80 = energyTo80 / power;
            const hours80 = Math.ceil(timeTo80);
            const displayHours80 = Math.floor(timeTo80);
            const minutes80 = Math.round((timeTo80 - displayHours80) * 60);
            let resultText = `⚡ Dosažení 80%: ${displayHours80}h ${minutes80}min`;
            if (finalPrices[hours80]) {
                const cost = Math.round(energyTo80 * finalPrices[hours80]);
                resultText += ` (cca ${cost} Kč)`;
                result80Div.style.color = '#4caf50';
            } else {
                resultText += ` (cenu nelze spočítat)`;
                result80Div.style.color = '#e65100';
            }
            result80Div.textContent = resultText;
        }

        // Cíl 100%
        if (soc >= 100) {
            result100Div.textContent = '✅ Dosažení 100%: Plně nabito';
            result100Div.style.color = '#ff9800';
        } else {
            const energyTo100 = (1.00 * capacity) - currentEnergy;
            const timeTo100 = energyTo100 / power;
            const hours100 = Math.ceil(timeTo100);
            const displayHours100 = Math.floor(timeTo100);
            const minutes100 = Math.round((timeTo100 - displayHours100) * 60);
            let resultText = `⚡ Dosažení 100%: ${displayHours100}h ${minutes100}min`;
            if (finalPrices[hours100]) {
                const cost = Math.round(energyTo100 * finalPrices[hours100]);
                resultText += ` (cca ${cost} Kč)`;
                result100Div.style.color = '#ff9800';
            } else {
                resultText += ` (cenu nelze spočítat)`;
                result100Div.style.color = '#e65100';
            }
            result100Div.textContent = resultText;
        }

        const hoursTo80 = (soc < 80) ? Math.ceil(((0.80 * capacity) - currentEnergy) / power) : 0;
        const hoursTo100 = (soc < 100) ? Math.ceil(((1.00 * capacity) - currentEnergy) / power) : 0;
        highlightTableRows(hoursTo80, hoursTo100);
    }

    capacityInput.addEventListener('input', calculateChargingTime);
    powerInput.addEventListener('input', calculateChargingTime);
    socSlider.addEventListener('input', calculateChargingTime);

    calculateChargingTime();
})();
</script>

</body>
</html>
