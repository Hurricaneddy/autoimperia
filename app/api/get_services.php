<?php
/**
 * API: api/get_services.php
 * Отдача каталога категорий и услуг СТО «Автоимперия» с поддержкой персонализированных цен под автомобиль
 */

define('IS_API_CALL', true);
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/CarPriceParser.php';
require_once __DIR__ . '/../includes/ui_icons.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $brand = isset($_GET['brand']) ? trim($_GET['brand']) : null;
    $model = isset($_GET['model']) ? trim($_GET['model']) : null;
    $year  = isset($_GET['year']) && is_numeric($_GET['year']) ? (int)$_GET['year'] : null;
    $partsGrade = isset($_GET['parts_grade']) ? trim($_GET['parts_grade']) : 'oem'; // 'oem', 'analog', 'none'

    $parser = new CarPriceParser($pdo);
    $carSpec = null;
    if (!empty($brand) && !empty($model)) {
        $carSpec = $parser->getCarMarketSpecs($brand, $model, $year);
    }

    // Загрузка категорий
    $catStmt = $pdo->query("SELECT * FROM service_categories ORDER BY sort_order ASC, category_id ASC");
    $categories = $catStmt->fetchAll(PDO::FETCH_ASSOC);

    // Загрузка услуг
    $servStmt = $pdo->query("SELECT * FROM services WHERE is_active = 1 ORDER BY category_id ASC, service_id ASC");
    $allServices = $servStmt->fetchAll(PDO::FETCH_ASSOC);

    // Группировка услуг по категориям
    $grouped = [];
    foreach ($categories as $cat) {
        $catId = (int)$cat['category_id'];
        $catServices = [];

        foreach ($allServices as $s) {
            if ((int)$s['category_id'] === $catId) {
                $serviceItem = [
                    'id'               => (int)$s['service_id'],
                    'title'            => $s['title'],
                    'duration'         => (int)$s['duration_min'],
                    'required_bay'     => $s['required_bay'],
                    'is_popular'       => (bool)$s['is_popular'],
                    'description'      => $s['description'],
                    'upsell'           => $s['upsell_recommendation'],
                    'base_price'       => (float)$s['price'],
                    'calculated_price' => (float)$s['price'],
                    'parts_cost'       => 0,
                    'parts_desc'       => ''
                ];

                // Если выбран конкретный авто, рассчитываем реальную цену (с деталями и коэффициентом)
                if ($carSpec) {
                    $calc = $parser->calculateServiceQuotes([(int)$s['service_id']], $carSpec, $partsGrade);
                    if (!empty($calc['services'])) {
                        $calcItem = $calc['services'][0];
                        $serviceItem['calculated_price'] = $calcItem['total_price'];
                        $serviceItem['labor_price']      = $calcItem['labor_price'];
                        $serviceItem['parts_cost']        = $calcItem['parts_cost'];
                        $serviceItem['parts_desc']        = $calcItem['parts_desc'];
                    }
                }

                $catServices[] = $serviceItem;
            }
        }

        $grouped[] = [
            'id'          => $cat['category_id'],
            'category'    => $cat['category_name'],
            'icon'        => serviceCategoryIcon($catId),
            'description' => $cat['description'],
            'items'       => $catServices
        ];
    }

    sendJsonResponse([
        'success'    => true,
        'car_spec'   => $carSpec,
        'categories' => $grouped
    ]);
} catch (Exception $e) {
    sendJsonResponse([
        'success' => false,
        'error'   => 'Ошибка при получении каталога услуг'
    ], 500);
}

