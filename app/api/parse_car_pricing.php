<?php
/**
 * API: api/parse_car_pricing.php
 * Парсинг актуальных рыночных расценок для конкретного автомобиля (Ford Mustang 2016 и др.)
 * Включает расчет запчастей, нормо-часов и параметров онлайн-оплаты
 */

define('IS_API_CALL', true);
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/CarPriceParser.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $brand = isset($_GET['brand']) ? trim($_GET['brand']) : '';
    $model = isset($_GET['model']) ? trim($_GET['model']) : '';
    $year  = isset($_GET['year']) && is_numeric($_GET['year']) ? (int)$_GET['year'] : null;
    $serviceIdsRaw = isset($_GET['services']) ? trim($_GET['services']) : '';
    $partsGrade = isset($_GET['parts_grade']) ? trim($_GET['parts_grade']) : 'oem';
    $promoCode = isset($_GET['promo']) ? trim($_GET['promo']) : '';

    if (empty($brand) || empty($model)) {
        sendJsonResponse([
            'success' => false,
            'error'   => 'Укажите марку и модель автомобиля'
        ], 400);
    }

    $parser = new CarPriceParser($pdo);
    $carSpec = $parser->getCarMarketSpecs($brand, $model, $year);

    $serviceIds = [];
    if (!empty($serviceIdsRaw)) {
        $parts = explode(',', $serviceIdsRaw);
        foreach ($parts as $p) {
            $val = (int)trim($p);
            if ($val > 0) {
                $serviceIds[] = $val;
            }
        }
    }

    $quote = $parser->calculateServiceQuotes($serviceIds, $carSpec, $partsGrade);

    // Расчет промокода
    $discountAmount = 0.00;
    $promoApplied = null;
    if (!empty($promoCode)) {
        $promoStmt = $pdo->prepare("SELECT * FROM promotions WHERE code = :code AND is_active = 1");
        $promoStmt->execute([':code' => strtoupper($promoCode)]);
        $promo = $promoStmt->fetch(PDO::FETCH_ASSOC);

        if ($promo && $quote['total_price'] >= (float)$promo['min_order_sum']) {
            if ($promo['discount_type'] === 'fixed') {
                $discountAmount = min((float)$promo['discount_val'], $quote['total_price']);
            } else {
                $discountAmount = round(($quote['total_price'] * (float)$promo['discount_val']) / 100);
            }
            $promoApplied = [
                'code'     => $promo['code'],
                'discount' => $discountAmount
            ];
        }
    }

    $finalTotal = max(0, $quote['total_price'] - $discountAmount);

    // Расчет вариантов оплаты
    $depositAmount = min(PREPAY_DEPOSIT_RUB, $finalTotal);
    $onlineDiscount = round($finalTotal * (ONLINE_PAY_DISCOUNT_PERCENT / 100));
    $fullOnlineAmount = max(0, $finalTotal - $onlineDiscount);

    // Формирование демонстрационного безопасного QR СБП для оплаты

    sendJsonResponse([
        'success'          => true,
        'car_spec'         => $carSpec,
        'quote'            => $quote,
        'promo_applied'    => $promoApplied,
        'discount_amount'  => $discountAmount,
        'final_total'      => $finalTotal,
        'payment_options'  => [
            'deposit' => [
                'title'       => 'Предоплата слота брони (рекомендуется)',
                'amount'      => $depositAmount,
                'description' => 'Гарантирует бронирование подъемника за вами. Остаток оплачивается при визите в сервис.'
            ],
            'full_online' => [
                'title'       => 'Оплата онлайн со скидкой 5%',
                'amount'      => $fullOnlineAmount,
                'discount'    => $onlineDiscount,
                'description' => 'Максимальная выгода: имитация полной оплаты'
            ],
            'onsite' => [
                'title'       => 'Оплата в сервисе',
                'amount'      => $finalTotal,
                'description' => 'Наличными или банковской картой мастеру-приёмщику при получении авто'
            ]
        ]
    ]);
} catch (Exception $e) {
    sendJsonResponse([
        'success' => false,
        'error'   => 'Ошибка расчета рыночных расценок'
    ], 500);
}

