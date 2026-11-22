<?php
/**
 * Автосервис «Автоимперия» (г. Сергиев Посад)
 * Класс парсинга и расчета реальных цен на запчасти, расходники и нормо-часы для конкретного авто
 * Поддерживает Ford Mustang 2016, а также любые марки и модели.
 */

class CarPriceParser {
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    /**
     * Поиск спецификации и рыночных цен на автомобиль
     */
    public function getCarMarketSpecs(string $brand, string $model, ?int $year = null): array {
        $brandClean = trim($brand);
        $modelClean = trim($model);

        $stmt = $this->pdo->prepare("
            SELECT * FROM vehicle_market_data
            WHERE LOWER(brand) = LOWER(:brand) AND LOWER(model) = LOWER(:model)
              AND (:year IS NULL OR :year BETWEEN year_from AND year_to)
            ORDER BY spec_id ASC
            LIMIT 1
        ");
        $stmt->execute([':brand' => $brandClean, ':model' => $modelClean, ':year' => $year]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            $row = $this->generateMarketDataForUnknownCar($brandClean, $modelClean, $year);
            $row['is_estimate'] = true;
        }

        return $this->formatSpecData($row, $brandClean, $modelClean, $year);
    }

    /**
     * Преобразование сырых данных в удобную структуру для фронтенда и расчета
     */
    private function formatSpecData(array $row, string $brand, string $model, ?int $year): array {
        $multiplier = (float)($row['labor_multiplier'] ?? 1.0);
        $oilLiters  = (float)($row['oil_liters'] ?? 4.0);
        $oilPriceL  = (float)($row['oil_price_per_l'] ?? 1200);
        $oilFilterP = (float)($row['oil_filter_price'] ?? 850);
        $airFilterP = (float)($row['air_filter_price'] ?? 950);
        $cabinFP    = (float)($row['cabin_filter_price'] ?? 900);
        $brakePadsP = (float)($row['brake_pads_front_price'] ?? 3900);
        $brakeAnalog= (float)($row['brake_pads_analog_price'] ?? 1900);
        $brakeDiscsP= (float)($row['brake_discs_front_price'] ?? 7500);

        // Стоимость комплекта масла + масляного фильтра (OEM)
        $oilServicePartsTotal = round(($oilLiters * $oilPriceL) + $oilFilterP);
        // Комплексное ТО (масло + масляный + воздушный + салонный фильтры)
        $fullToPartsTotal = round($oilServicePartsTotal + $airFilterP + $cabinFP);

        return [
            'brand'                 => $brand,
            'model'                 => $model,
            'year'                  => $year,
            'engine_desc'           => $row['engine_desc'] ?? 'Бензин / Турбо',
            'oil_type'              => $row['oil_type'] ?? 'Синтетика 5W-30',
            'oil_liters'            => $oilLiters,
            'oil_price_per_l'       => $oilPriceL,
            'oil_filter_oem'        => $row['oil_filter_oem'] ?? 'OEM Filter',
            'oil_filter_price'      => $oilFilterP,
            'oil_service_parts'     => $oilServicePartsTotal,
            'full_to_parts'         => $fullToPartsTotal,
            'air_filter_price'      => $airFilterP,
            'cabin_filter_price'    => $cabinFP,
            'brake_pads_oem'        => $row['brake_pads_front_oem'] ?? 'OEM Pads',
            'brake_pads_price'      => $brakePadsP,
            'brake_pads_analog'     => $brakeAnalog,
            'brake_discs_price'     => $brakeDiscsP,
            'spark_plug_price_set'  => (float)($row['spark_plug_price_set'] ?? 3000),
            'coolant_price'         => (float)($row['coolant_price'] ?? 2500),
            'labor_multiplier'      => $multiplier,
            'market_source'         => !empty($row['is_estimate']) ? 'Учебная оценка' : 'Учебный справочник',
            'is_estimate'           => !empty($row['is_estimate']),
            'is_premium'            => ($multiplier >= 1.35),
            'parsed_timestamp'      => date('d.m.Y H:i')
        ];
    }

    /**
     * Учебная оценка стоимости для автомобиля вне справочника
     */
    private function generateMarketDataForUnknownCar(string $brand, string $model, ?int $year): array {
        $brandLower = mb_strtolower($brand);
        $modelLower = mb_strtolower($model);

        // Определяем класс авто
        $isSportOrMuscle = (
            strpos($modelLower, 'mustang') !== false ||
            strpos($modelLower, 'camaro') !== false ||
            strpos($modelLower, 'challenger') !== false ||
            strpos($modelLower, 'porsche') !== false ||
            strpos($modelLower, 'amg') !== false ||
            strpos($modelLower, 'corvette') !== false
        );

        $isPremium = (
            in_array($brandLower, ['bmw', 'mercedes-benz', 'mercedes', 'audi', 'porsche', 'lexus', 'land rover', 'jaguar', 'infiniti', 'volvo']) ||
            $isSportOrMuscle
        );

        $isHeavySuv = (
            strpos($modelLower, 'land cruiser') !== false ||
            strpos($modelLower, 'patrol') !== false ||
            strpos($modelLower, 'tahoe') !== false ||
            strpos($modelLower, 'prado') !== false ||
            strpos($modelLower, 'tuareg') !== false
        );

        if ($isSportOrMuscle) {
            return [
                'engine_desc'             => '2.3T EcoBoost / 5.0L V8 High Performance',
                'oil_type'                => 'Ford Motorcraft Full Synthetic 5W-20 / 5W-30',
                'oil_liters'              => 5.70,
                'oil_price_per_l'         => 1450.00,
                'oil_filter_oem'          => 'Motorcraft FL-910S Sport',
                'oil_filter_price'        => 1850.00,
                'air_filter_price'        => 2400.00,
                'cabin_filter_price'      => 1950.00,
                'brake_pads_front_oem'    => 'Brembo Sport 6-piston OEM',
                'brake_pads_front_price'  => 8900.00,
                'brake_pads_analog_price' => 4800.00,
                'brake_discs_front_price' => 18500.00,
                'spark_plug_price_set'    => 6800.00,
                'coolant_liters'          => 11.50,
                'coolant_price'           => 4200.00,
                'labor_multiplier'        => 1.55
            ];
        }

        if ($isPremium) {
            return [
                'engine_desc'             => '2.0T - 3.0T TwinPower Turbo / Direct Injection',
                'oil_type'                => 'Оригинал LongLife-04 / 229.5 5W-30 Synthetic',
                'oil_liters'              => 5.50,
                'oil_price_per_l'         => 1800.00,
                'oil_filter_oem'          => 'OEM Premium Filter',
                'oil_filter_price'        => 1600.00,
                'air_filter_price'        => 2200.00,
                'cabin_filter_price'      => 2400.00,
                'brake_pads_front_oem'    => 'OEM Performance Pads',
                'brake_pads_front_price'  => 8200.00,
                'brake_pads_analog_price' => 4200.00,
                'brake_discs_front_price' => 17000.00,
                'spark_plug_price_set'    => 5600.00,
                'coolant_liters'          => 8.50,
                'coolant_price'           => 3800.00,
                'labor_multiplier'        => 1.45
            ];
        }

        if ($isHeavySuv) {
            return [
                'engine_desc'             => 'V6 / V8 3.5 - 4.6L Heavy Duty',
                'oil_type'                => 'Toyota / Mobil 1 5W-30 Advanced Full Synthetic',
                'oil_liters'              => 7.20,
                'oil_price_per_l'         => 1350.00,
                'oil_filter_oem'          => 'OEM Heavy Duty Cartridge',
                'oil_filter_price'        => 1100.00,
                'air_filter_price'        => 1600.00,
                'cabin_filter_price'      => 1400.00,
                'brake_pads_front_oem'    => 'OEM Reinforced SUV Pads',
                'brake_pads_front_price'  => 5800.00,
                'brake_pads_analog_price' => 2900.00,
                'brake_discs_front_price' => 14000.00,
                'spark_plug_price_set'    => 5200.00,
                'coolant_liters'          => 12.00,
                'coolant_price'           => 3900.00,
                'labor_multiplier'        => 1.30
            ];
        }

        // Стандартный легковой автомобиль (Ford Focus, Kia Rio, VW Polo и т.п.)
        return [
            'engine_desc'             => '1.6L - 2.0L 16V DOHC',
            'oil_type'                => 'Synthetic 5W-30 / 5W-40 (ACEA A3/B4, A5/B5)',
            'oil_liters'              => 4.20,
            'oil_price_per_l'         => 1050.00,
            'oil_filter_oem'          => 'OEM Filter Element',
            'oil_filter_price'        => 750.00,
            'air_filter_price'        => 850.00,
            'cabin_filter_price'      => 750.00,
            'brake_pads_front_oem'    => 'OEM Disc Brake Pads',
            'brake_pads_front_price'  => 3600.00,
            'brake_pads_analog_price' => 1800.00,
            'brake_discs_front_price' => 6900.00,
            'spark_plug_price_set'    => 2600.00,
            'coolant_liters'          => 6.50,
            'coolant_price'           => 2100.00,
            'labor_multiplier'        => 1.05
        ];
    }

    /**
     * Расчет стоимости услуг с учетом специфики конкретного автомобиля
     * @param array $serviceIds Массив ID услуг
     * @param array $carSpec Данные из getCarMarketSpecs
     * @param string $partsGrade 'none' (только работа), 'analog' (аналоги), 'oem' (оригинал), 'premium'
     */
    public function calculateServiceQuotes(array $serviceIds, array $carSpec, string $partsGrade = 'oem'): array {
        if (empty($serviceIds)) {
            return [
                'services'        => [],
                'total_labor'     => 0,
                'total_parts'     => 0,
                'total_price'     => 0,
                'total_duration'  => 0
            ];
        }

        $in = implode(',', array_fill(0, count($serviceIds), '?'));
        $stmt = $this->pdo->prepare("SELECT * FROM services WHERE service_id IN ($in) AND is_active = 1 ORDER BY category_id, service_id");
        $stmt->execute(array_values($serviceIds));
        $services = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $multiplier = (float)($carSpec['labor_multiplier'] ?? 1.0);
        $totalLabor = 0;
        $totalParts = 0;
        $totalDuration = 0;
        $calculatedServices = [];

        foreach ($services as $s) {
            $baseLabor = (float)$s['price'];
            $adjustedLabor = round($baseLabor * $multiplier);
            $duration = (int)$s['duration_min'];
            $partsCost = 0;
            $partsDesc = '';

            // Если клиент выбрал включение запчастей
            if ($partsGrade !== 'none') {
                $serviceId = (int)$s['service_id'];

                // Замена масла
                if ($serviceId === 1) {
                    $partsCost = ($partsGrade === 'analog') 
                        ? round(($carSpec['oil_liters'] * 850) + 500) 
                        : $carSpec['oil_service_parts'];
                    $partsDesc = "Масло {$carSpec['oil_liters']}л ({$carSpec['oil_type']}) + фильтр {$carSpec['oil_filter_oem']}";
                }
                // Замена фильтров (воздушный + салонный)
                elseif ($serviceId === 2) {
                    $partsCost = ($partsGrade === 'analog')
                        ? round(($carSpec['air_filter_price'] + $carSpec['cabin_filter_price']) * 0.6)
                        : round($carSpec['air_filter_price'] + $carSpec['cabin_filter_price']);
                    $partsDesc = "Воздушный + угольный салонный фильтр";
                }
                // Замена антифриза
                elseif ($serviceId === 3) {
                    $partsCost = $carSpec['coolant_price'];
                    $partsDesc = "Антифриз премиум класс G12+/G13";
                }
                // Тормозная жидкость
                elseif ($serviceId === 4) {
                    $partsCost = 900;
                    $partsDesc = "Тормозная жидкость DOT 4 Class 6 (1л)";
                }
                // Комплексное ТО
                elseif ($serviceId === 5) {
                    $partsCost = ($partsGrade === 'analog')
                        ? round($carSpec['full_to_parts'] * 0.65)
                        : $carSpec['full_to_parts'];
                    $partsDesc = "Масло {$carSpec['oil_liters']}л + 3 фильтра (масляный, воздушный, салонный)";
                }
                // Свечи зажигания
                elseif ($serviceId === 6) {
                    $partsCost = ($partsGrade === 'analog')
                        ? round($carSpec['spark_plug_price_set'] * 0.6)
                        : $carSpec['spark_plug_price_set'];
                    $partsDesc = "Комплект иридиевых свечей зажигания OEM";
                }
                // Замена передних колодок
                elseif ($serviceId === 12) {
                    $partsCost = ($partsGrade === 'analog') 
                        ? $carSpec['brake_pads_analog'] 
                        : $carSpec['brake_pads_price'];
                    $partsDesc = "Колодки передние " . ($partsGrade === 'analog' ? "Hi-Q / TRW" : $carSpec['brake_pads_oem']);
                }
                // Замена передних тормозных дисков и колодок
                elseif ($serviceId === 14) {
                    $partsCost = ($partsGrade === 'analog')
                        ? round($carSpec['brake_discs_price'] * 0.65 + $carSpec['brake_pads_analog'])
                        : round($carSpec['brake_discs_price'] + $carSpec['brake_pads_price']);
                    $partsDesc = "Тормозные диски (пара) + колодки OEM";
                }
            }

            $serviceTotal = $adjustedLabor + $partsCost;

            $totalLabor += $adjustedLabor;
            $totalParts += $partsCost;
            $totalDuration += $duration;

            $calculatedServices[] = [
                'service_id'    => $s['service_id'],
                'category_id'   => $s['category_id'],
                'title'         => $s['title'],
                'duration_min'  => $duration,
                'base_labor'    => $baseLabor,
                'labor_price'   => $adjustedLabor,
                'parts_cost'    => $partsCost,
                'parts_desc'    => $partsDesc,
                'total_price'   => $serviceTotal,
                'required_bay'  => $s['required_bay'],
                'upsell'        => $s['upsell_recommendation']
            ];
        }

        return [
            'services'       => $calculatedServices,
            'total_labor'    => $totalLabor,
            'total_parts'    => $totalParts,
            'total_price'    => $totalLabor + $totalParts,
            'total_duration' => $totalDuration,
            'car_spec'       => $carSpec,
            'parts_grade'    => $partsGrade
        ];
    }
}

