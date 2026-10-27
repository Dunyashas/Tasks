<?php
/**
 * Сверка заказов маркетплейса с выгрузкой номенклатуры 1С.
 * Запуск из корня проекта: php run.php
 */

declare(strict_types=1);

final class ReconciliationRunner
{
    private string $root;
    /** @var resource|null */
    private $logHandle = null;
    /** @var array<string, list<array<string, mixed>>> */
    private array $catalog = [];
    /** @var array<string, float> */
    private array $remainingStock = [];
    /** @var list<array<string, string>> */
    private array $detailRows = [];
    /** @var list<array<string, string>> */
    private array $summaryRows = [];

    public function __construct(string $root)
    {
        $this->root = rtrim($root, DIRECTORY_SEPARATOR);
    }

    public function run(): int
    {
        foreach (['reports', 'logs'] as $dir) {
            $path = $this->root . DIRECTORY_SEPARATOR . $dir;
            if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
                fwrite(STDERR, "Не удалось создать каталог: {$path}\n");
                return 1;
            }
        }

        $this->logHandle = fopen($this->root . '/logs/processing.log', 'wb');
        if ($this->logHandle === false) {
            fwrite(STDERR, "Не удалось открыть лог для записи.\n");
            return 1;
        }
        $this->log('INFO', 'Старт сверки заказов.');

        try {
            $orders = $this->loadOrders($this->root . '/data/orders.json');
            $this->loadCatalog($this->root . '/data/nomenclature_1c.csv');
            $this->processOrders($orders);
            $this->writeCsv(
                $this->root . '/reports/reconciliation_report.csv',
                ['order_id', 'created_at', 'order_status', 'buyer', 'sku', 'item_title', 'quantity',
                 'marketplace_price', 'one_c_price', 'one_c_stock_initial', 'available_before_line',
                 'shortage', 'currency', 'item_issues', 'order_issues', 'recommendation'],
                $this->detailRows
            );
            $this->writeCsv(
                $this->root . '/reports/orders_summary.csv',
                ['order_id', 'created_at', 'order_status', 'buyer', 'items_count', 'currency',
                 'marketplace_total', 'calculated_total', 'issues', 'recommendation', 'duplicate_of'],
                $this->summaryRows
            );

            $issueOrders = 0;
            foreach ($this->summaryRows as $row) {
                if (str_starts_with($row['recommendation'], 'Проверить')) {
                    $issueOrders++;
                }
            }
            $this->log('INFO', sprintf(
                'Завершено. Заказов/записей в сводке: %d; требуют проверки: %d; строк детализации: %d.',
                count($this->summaryRows), $issueOrders, count($this->detailRows)
            ));
            printf("Сверка завершена.\nСводка: reports/orders_summary.csv\nДетали: reports/reconciliation_report.csv\nЛог: logs/processing.log\nЗаписей в сводке: %d; требуют проверки: %d; строк деталей: %d\n",
                count($this->summaryRows), $issueOrders, count($this->detailRows));
            return 0;
        } catch (Throwable $e) {
            $this->log('ERROR', $e->getMessage());
            fwrite(STDERR, "Не удалось завершить сверку: {$e->getMessage()}\nСм. logs/processing.log\n");
            return 1;
        } finally {
            if (is_resource($this->logHandle)) {
                fclose($this->logHandle);
            }
        }
    }

    /** @return list<mixed> */
    private function loadOrders(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException("Файл заказов не найден или недоступен: {$path}");
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException("Не удалось прочитать файл заказов: {$path}");
        }
        try {
            $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('Некорректный JSON заказов: ' . $e->getMessage(), 0, $e);
        }
        $orders = $payload['data']['orders'] ?? null;
        if (!is_array($orders) || !array_is_list($orders)) {
            throw new RuntimeException('В JSON отсутствует массив data.orders.');
        }
        $this->log('INFO', 'Прочитано записей заказов: ' . count($orders) . '.');
        return $orders;
    }

    private function loadCatalog(string $path): void
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException("CSV номенклатуры не найден или недоступен: {$path}");
        }
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException("Не удалось открыть CSV номенклатуры: {$path}");
        }

        try {
            $headers = fgetcsv($handle, 0, ';', '"', '');
            if (!is_array($headers)) {
                throw new RuntimeException('CSV номенклатуры пустой.');
            }
            $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $headers[0]) ?? (string) $headers[0];
            $index = array_flip(array_map(static fn($h): string => trim((string) $h), $headers));
            foreach (['Артикул', 'Наименование', 'Цена', 'Остаток'] as $required) {
                if (!array_key_exists($required, $index)) {
                    throw new RuntimeException("В CSV нет обязательного столбца «{$required}». ");
                }
            }

            $line = 1;
            while (($values = fgetcsv($handle, 0, ';', '"', '')) !== false) {
                $line++;
                if ($values === [null] || $values === []) {
                    continue;
                }
                if (count($values) !== count($headers)) {
                    $this->log('WARNING', "Строка CSV {$line}: ожидалось " . count($headers) . ' полей, получено ' . count($values) . '; строка пропущена.');
                    continue;
                }
                $row = [];
                foreach ($headers as $i => $header) {
                    $row[trim((string) $header)] = trim((string) ($values[$i] ?? ''));
                }
                $sku = $this->normaliseSku($row['Артикул'] ?? '');
                $price = $this->parseLocalNumber((string) ($row['Цена'] ?? ''));
                $stock = $this->parseLocalNumber((string) ($row['Остаток'] ?? ''));
                if ($sku === '' || $price === null || $stock === null || $price < 0 || $stock < 0) {
                    $this->log('WARNING', "Строка CSV {$line}: некорректный артикул, цена или остаток; строка пропущена.");
                    continue;
                }
                $row['_sku'] = $sku;
                $row['_price'] = $price;
                $row['_stock'] = $stock;
                $this->catalog[$sku][] = $row;
            }
        } finally {
            fclose($handle);
        }

        foreach ($this->catalog as $sku => $products) {
            if (count($products) > 1) {
                $this->log('WARNING', "Артикул {$sku} встречается в 1С " . count($products) . ' раза; автоматическое сопоставление запрещено.');
            } elseif (count($products) === 1) {
                $this->remainingStock[$sku] = (float) $products[0]['_stock'];
            }
        }
        $this->log('INFO', 'Загружено карточек номенклатуры: ' . array_sum(array_map('count', $this->catalog)) . '; уникальных артикулов: ' . count($this->catalog) . '.');
    }

    /** @param list<mixed> $rawOrders */
    private function processOrders(array $rawOrders): void
    {
        $uniqueOrders = [];
        $firstById = [];
        $duplicateRecords = [];
        $badRecords = [];

        foreach ($rawOrders as $index => $order) {
            if (!is_array($order)) {
                $badRecords[] = ['id' => 'ROW-' . ($index + 1), 'reason' => 'Запись заказа не является объектом JSON.'];
                $this->log('WARNING', 'Запись заказа #' . ($index + 1) . ' пропущена: это не объект.');
                continue;
            }
            $id = trim((string) ($order['id'] ?? ''));
            if ($id === '') {
                $id = 'ROW-' . ($index + 1);
                $order['id'] = $id;
                $order['_invalid_id'] = true;
            }
            if (isset($firstById[$id])) {
                $exact = $this->stableJson($firstById[$id]) === $this->stableJson($order);
                $duplicateRecords[] = [
                    'order' => $order,
                    'id' => $id,
                    'reason' => $exact
                        ? 'Повторная запись заказа с тем же ID и содержимым.'
                        : 'Повторный ID с отличающимся содержимым; требуется ручная проверка.',
                    'exact' => $exact,
                ];
                $this->log('WARNING', "Дубликат ID {$id}: повторная запись пропущена; совпадение содержимого: " . ($exact ? 'да' : 'нет') . '.');
                continue;
            }
            $firstById[$id] = $order;
            $uniqueOrders[] = $order;
        }

        usort($uniqueOrders, static function (array $a, array $b): int {
            return strcmp((string) ($a['created_at'] ?? ''), (string) ($b['created_at'] ?? ''));
        });

        foreach ($uniqueOrders as $order) {
            $this->processOneOrder($order);
        }
        foreach ($duplicateRecords as $duplicate) {
            $o = $duplicate['order'];
            $this->appendDuplicateRecord($o, $duplicate['reason'], $duplicate['id']);
        }
        foreach ($badRecords as $bad) {
            $this->summaryRows[] = [
                'order_id' => $bad['id'], 'created_at' => '', 'order_status' => 'invalid', 'buyer' => '',
                'items_count' => '0', 'currency' => '', 'marketplace_total' => '', 'calculated_total' => '',
                'issues' => $bad['reason'], 'recommendation' => 'Проверить исходные данные', 'duplicate_of' => '',
            ];
            $this->detailRows[] = $this->emptyDetail($bad['id'], 'invalid', $bad['reason'], 'Проверить исходные данные');
        }
    }

    /** @param array<string, mixed> $order */
    private function processOneOrder(array $order): void
    {
        $id = trim((string) ($order['id'] ?? 'UNKNOWN'));
        $createdAt = (string) ($order['created_at'] ?? '');
        $status = strtolower(trim((string) ($order['status'] ?? 'unknown')));
        $buyer = trim((string) ($order['buyer']['name'] ?? ''));
        $currency = strtoupper(trim((string) ($order['currency'] ?? '')));
        $orderIssues = [];
        $lineIssues = [];

        if (isset($order['_invalid_id'])) {
            $orderIssues[] = 'Отсутствует ID заказа';
        }
        if ($createdAt === '' || strtotime($createdAt) === false) {
            $orderIssues[] = 'Отсутствует или некорректна дата создания';
            $this->log('WARNING', "Заказ {$id}: отсутствует/неверна дата создания.");
        }
        if (!in_array($status, ['new', 'confirmed', 'cancelled'], true)) {
            $orderIssues[] = 'Неизвестный статус заказа: ' . $status;
        }
        if ($currency !== 'BYN') {
            $orderIssues[] = 'Неожиданная или отсутствующая валюта: ' . ($currency ?: '(пусто)');
        }

        $items = $order['items'] ?? null;
        if (!is_array($items) || !array_is_list($items)) {
            $orderIssues[] = 'Поле items отсутствует или не является списком';
            $this->log('WARNING', "Заказ {$id}: поле items отсутствует или имеет неверный формат.");
            $items = [];
        }
        if (count($items) === 0) {
            $orderIssues[] = 'Заказ не содержит товаров';
        }

        $total = $this->parseJsonNumber($order['total'] ?? null);
        if ($total === null) {
            $orderIssues[] = 'Не указана или некорректна сумма заказа';
        }

        $itemTotal = 0.0;
        $canCheckTotal = count($items) > 0;
        $normalisedCounts = [];
        foreach ($items as $item) {
            if (is_array($item)) {
                $sku = $this->normaliseSku($item['sku'] ?? '');
                if ($sku !== '') {
                    $normalisedCounts[$sku] = ($normalisedCounts[$sku] ?? 0) + 1;
                }
            }
        }

        $prepared = [];
        foreach ($items as $position => $item) {
            if (!is_array($item)) {
                $canCheckTotal = false;
                $lineIssues[] = ['позиция ' . ($position + 1) => ['Элемент заказа имеет неверный формат']];
                $prepared[] = ['invalid' => true, 'position' => $position + 1];
                $this->log('WARNING', "Заказ {$id}, позиция " . ($position + 1) . ': элемент не является объектом.');
                continue;
            }
            $qtyRaw = $item['quantity'] ?? null;
            $qtyNumber = $this->parseJsonNumber($qtyRaw);
            $qtyValid = $qtyNumber !== null && $qtyNumber > 0 && floor($qtyNumber) === $qtyNumber;
            $qty = $qtyValid ? (int) $qtyNumber : 0;
            $price = $this->parseJsonNumber($item['price'] ?? null);
            $priceValid = $price !== null && $price >= 0;
            if (!$qtyValid || !$priceValid) {
                $canCheckTotal = false;
            } elseif ($qtyValid && $priceValid) {
                $itemTotal += $qty * $price;
            }
            $skuRaw = $item['sku'] ?? '';
            $sku = $this->normaliseSku($skuRaw);
            $title = trim((string) ($item['title'] ?? ''));
            $issues = [];
            $catalogProduct = null;
            $stockInitial = '';
            $availableBefore = '';
            $shortage = 0.0;

            if ($sku === '') {
                $issues[] = 'Отсутствует артикул';
            }
            if (!$qtyValid) {
                $issues[] = 'Количество должно быть положительным целым числом';
            }
            if (!$priceValid) {
                $issues[] = 'Отсутствует или некорректна цена маркетплейса';
            }
            if ($sku !== '' && isset($normalisedCounts[$sku]) && $normalisedCounts[$sku] > 1) {
                $issues[] = 'Артикул повторяется в строках одного заказа';
            }

            $products = $sku !== '' ? ($this->catalog[$sku] ?? []) : [];
            if ($sku !== '' && count($products) === 0) {
                $issues[] = 'Артикул отсутствует в номенклатуре 1С';
            } elseif (count($products) > 1) {
                $issues[] = 'Неоднозначный артикул: несколько карточек в 1С';
            } elseif (count($products) === 1) {
                $catalogProduct = $products[0];
                $stockInitial = $this->moneyOrQuantity((float) $catalogProduct['_stock']);
                if ($priceValid && abs($price - (float) $catalogProduct['_price']) >= 0.005) {
                    $issues[] = sprintf('Цена отличается: маркетплейс %.2f BYN, 1С %.2f BYN', $price, (float) $catalogProduct['_price']);
                }
                if (in_array($status, ['new', 'confirmed'], true) && $qtyValid) {
                    $available = $this->remainingStock[$sku] ?? (float) $catalogProduct['_stock'];
                    $availableBefore = $this->moneyOrQuantity($available);
                    if ($qty > $available) {
                        $shortage = $qty - $available;
                        $issues[] = sprintf('Недостаточно остатка: доступно %.0f, требуется %d, нехватка %.0f', $available, $qty, $shortage);
                        $this->log('WARNING', "Заказ {$id}, {$sku}: нехватка " . $shortage . ' ед.; до строки было доступно ' . $available . '.');
                    }
                    // В проекции по времени заказы получают доступный остаток по очереди.
                    // Нельзя уйти ниже нуля; неоднозначные артикулы не резервируются.
                    $allocated = min((float) $qty, $available);
                    $this->remainingStock[$sku] = max(0.0, $available - $allocated);
                } else {
                    $availableBefore = $stockInitial;
                }
            }

            foreach ($issues as $message) {
                if ($sku !== '') {
                    $this->log('WARNING', "Заказ {$id}, {$sku}: {$message}.");
                } else {
                    $this->log('WARNING', "Заказ {$id}: {$message}.");
                }
            }
            $lineIssues[] = [$sku !== '' ? $sku : ('позиция ' . ($position + 1)) => $issues];
            $prepared[] = [
                'sku' => $sku, 'title' => $title, 'qty' => $qtyValid ? (string) $qty : (string) ($qtyRaw ?? ''),
                'price' => $priceValid ? number_format($price, 2, '.', '') : '',
                'one_c_price' => $catalogProduct !== null ? number_format((float) $catalogProduct['_price'], 2, '.', '') : '',
                'stock_initial' => $stockInitial, 'available_before' => $availableBefore,
                'shortage' => $shortage > 0 ? $this->moneyOrQuantity($shortage) : '0',
                'currency' => $currency, 'issues' => $issues, 'invalid' => false,
            ];
        }

        $calculatedTotal = $canCheckTotal ? round($itemTotal, 2) : null;
        if ($total !== null && $calculatedTotal !== null && abs($total - $calculatedTotal) >= 0.005) {
            $orderIssues[] = sprintf('Сумма заказа не совпадает: указано %.2f BYN, сумма строк %.2f BYN', $total, $calculatedTotal);
        }

        foreach ($orderIssues as $orderIssue) {
            $this->log('WARNING', "Заказ {$id}: {$orderIssue}.");
        }

        $allIssues = $orderIssues;
        foreach ($lineIssues as $entry) {
            foreach ($entry as $sku => $messages) {
                foreach ($messages as $message) {
                    $allIssues[] = $sku . ': ' . $message;
                }
            }
        }
        $allIssues = array_values(array_unique($allIssues));

        if ($status === 'cancelled') {
            $recommendation = 'Не передавать: заказ отменён';
        } elseif ($allIssues === []) {
            $recommendation = 'Готов к передаче в 1С';
        } else {
            $recommendation = 'Проверить вручную; не передавать автоматически';
        }

        if ($prepared === []) {
            $this->detailRows[] = $this->emptyDetail($id, $status, implode(' | ', $orderIssues), $recommendation, $createdAt, $buyer, $currency);
        } else {
            foreach ($prepared as $i => $line) {
                if (($line['invalid'] ?? false) === true) {
                    $this->detailRows[] = $this->emptyDetail($id, $status, 'Элемент заказа имеет неверный формат', $recommendation, $createdAt, $buyer, $currency);
                    continue;
                }
                $itemIssueText = implode(' | ', $line['issues']);
                $this->detailRows[] = [
                    'order_id' => $id,
                    'created_at' => $createdAt,
                    'order_status' => $status,
                    'buyer' => $buyer,
                    'sku' => (string) $line['sku'],
                    'item_title' => $line['title'],
                    'quantity' => $line['qty'],
                    'marketplace_price' => $line['price'],
                    'one_c_price' => $line['one_c_price'],
                    'one_c_stock_initial' => $line['stock_initial'],
                    'available_before_line' => $line['available_before'],
                    'shortage' => $line['shortage'],
                    'currency' => $line['currency'],
                    'item_issues' => $itemIssueText,
                    'order_issues' => implode(' | ', $orderIssues),
                    'recommendation' => $recommendation,
                ];
            }
        }

        $this->summaryRows[] = [
            'order_id' => $id,
            'created_at' => $createdAt,
            'order_status' => $status,
            'buyer' => $buyer,
            'items_count' => (string) count($items),
            'currency' => $currency,
            'marketplace_total' => $total === null ? '' : number_format($total, 2, '.', ''),
            'calculated_total' => $calculatedTotal === null ? '' : number_format($calculatedTotal, 2, '.', ''),
            'issues' => implode(' | ', $allIssues),
            'recommendation' => $recommendation,
            'duplicate_of' => '',
        ];
    }

    /** @param array<string, mixed> $order */
    private function appendDuplicateRecord(array $order, string $reason, string $id): void
    {
        $createdAt = (string) ($order['created_at'] ?? '');
        $status = strtolower(trim((string) ($order['status'] ?? 'unknown')));
        $buyer = trim((string) ($order['buyer']['name'] ?? ''));
        $currency = strtoupper(trim((string) ($order['currency'] ?? '')));
        $items = $order['items'] ?? [];
        $count = is_array($items) ? count($items) : 0;
        $recommendation = 'Пропустить дубликат; проверить оригинальную запись';
        $this->summaryRows[] = [
            'order_id' => $id, 'created_at' => $createdAt, 'order_status' => $status, 'buyer' => $buyer,
            'items_count' => (string) $count, 'currency' => $currency,
            'marketplace_total' => $this->formatOptionalMoney($order['total'] ?? null), 'calculated_total' => '',
            'issues' => $reason, 'recommendation' => $recommendation, 'duplicate_of' => $id,
        ];
        $this->detailRows[] = $this->emptyDetail($id, $status, $reason, $recommendation, $createdAt, $buyer, $currency);
    }

    /** @param array<string, string> $row */
    private function emptyDetail(string $id, string $status, string $issues, string $recommendation,
        string $createdAt = '', string $buyer = '', string $currency = ''): array
    {
        return [
            'order_id' => $id, 'created_at' => $createdAt, 'order_status' => $status, 'buyer' => $buyer,
            'sku' => '', 'item_title' => '', 'quantity' => '', 'marketplace_price' => '', 'one_c_price' => '',
            'one_c_stock_initial' => '', 'available_before_line' => '', 'shortage' => '', 'currency' => $currency,
            'item_issues' => '', 'order_issues' => $issues, 'recommendation' => $recommendation,
        ];
    }

    /** @param list<string> $headers @param list<array<string, string>> $rows */
    private function writeCsv(string $path, array $headers, array $rows): void
    {
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new RuntimeException("Не удалось создать отчёт: {$path}");
        }
        try {
            // BOM помогает Excel корректно распознать UTF-8 и кириллицу.
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, $headers, ';', '"', '');
            foreach ($rows as $row) {
                $values = [];
                foreach ($headers as $header) {
                    $values[] = $row[$header] ?? '';
                }
                fputcsv($handle, $values, ';', '"', '');
            }
        } finally {
            fclose($handle);
        }
    }

    private function parseLocalNumber(string $value): ?float
    {
        $value = str_replace(["\u{00A0}", "\u{202F}", ' ', "\t"], '', trim($value));
        if ($value === '' || !preg_match('/^-?\d+(?:,\d+)?$/u', $value)) {
            return null;
        }
        $number = (float) str_replace(',', '.', $value);
        return is_finite($number) ? $number : null;
    }

    private function parseJsonNumber(mixed $value): ?float
    {
        if (!is_int($value) && !is_float($value) && !(is_string($value) && is_numeric(trim($value)))) {
            return null;
        }
        $number = (float) $value;
        return is_finite($number) ? $number : null;
    }

    private function normaliseSku(mixed $sku): string
    {
        if (!is_string($sku) && !is_numeric($sku)) {
            return '';
        }
        return strtoupper(trim((string) $sku));
    }

    private function moneyOrQuantity(float $number): string
    {
        return abs($number - round($number)) < 0.000001
            ? number_format($number, 0, '.', '')
            : number_format($number, 3, '.', '');
    }

    private function formatOptionalMoney(mixed $value): string
    {
        $number = $this->parseJsonNumber($value);
        return $number === null ? '' : number_format($number, 2, '.', '');
    }

    /** @param array<string, mixed> $value */
    private function stableJson(array $value): string
    {
        unset($value['_invalid_id']);
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION) ?: '';
    }

    private function log(string $level, string $message): void
    {
        if (is_resource($this->logHandle)) {
            fwrite($this->logHandle, sprintf("[%s] %-7s %s\n", date('Y-m-d H:i:s'), $level, $message));
        }
    }
}

$runner = new ReconciliationRunner(__DIR__);
exit($runner->run());
