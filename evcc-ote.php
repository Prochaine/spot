<?php
/**
 * evcc-ote.php - Tarif pro evcc (JSON, časy v UTC)
 */

require_once __DIR__ . '/ote.php';

header('Content-Type: application/json');

try {
    $evccData = [];

    foreach (oteGetUpcoming() as $periods) {
        foreach ($periods as $period) {
            $evccData[] = [
                "start" => $period['start']->format('Y-m-d\TH:i:s\Z'),
                "end"   => $period['end']->format('Y-m-d\TH:i:s\Z'),
                "value" => round($period['totalPrice'], 4)
            ];
        }
    }

    if (empty($evccData)) {
        throw new RuntimeException('OTE nevrátilo žádné ceny.');
    }

    echo json_encode($evccData);

} catch (Throwable $e) {
    error_log('evcc-ote: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(["error" => $e->getMessage()]);
}
