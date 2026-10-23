<?php
/**
 * prices.php - fetches Euronext (MATIF) milling wheat futures prices on the server
 * and returns them as JSON for index.html.
 *
 * - Needs PHP 7.4+ with the DOM extension (standard on almost all hosts).
 * - Uses cURL if available, otherwise file_get_contents (allow_url_fopen).
 * - Caches the result for 15 minutes in ./cache so Euronext is not hit on every visit.
 * - If Euronext can't be reached, it serves the last saved prices (marked "stale").
 *
 * Troubleshooting: open  prices.php?debug=1  to see what Euronext actually returned.
 *
 * NOTE: the Euronext address below is the one their own website appears to use. It is not a
 * documented public API and can change. Euronext data is licensed - check their terms before
 * showing it publicly.
 */

declare(strict_types=1);

const CACHE_TTL = 900; // seconds
const ENDPOINT  = 'https://live.euronext.com/en/ajax/getPricesFuturesBlock/commodities-futures/%s';
const INSTRUMENTS = [
    'wheat'    => ['label' => 'Wheat',    'code' => 'EBM-DPAR'],  // Milling Wheat No.2
    'rapeseed' => ['label' => 'Rapeseed', 'code' => 'ECO-DPAR'],
];
const MONTHS = ['jan' => 'Jan', 'feb' => 'Feb', 'mar' => 'Mar', 'apr' => 'Apr', 'may' => 'May', 'jun' => 'Jun',
                'jul' => 'Jul', 'aug' => 'Aug', 'sep' => 'Sep', 'oct' => 'Oct', 'nov' => 'Nov', 'dec' => 'Dec'];

function http_get(string $url): array
{
    $headers = [
        'User-Agent: Mozilla/5.0 (compatible; price-fetch/1.0)',
        'X-Requested-With: XMLHttpRequest',
        'Accept: text/html,application/json;q=0.9,*/*;q=0.8',
    ];
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_HTTPHEADER     => $headers,
        ]);
        $body = curl_exec($ch);
        $err  = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($body === false) {
            return ['', 'cURL error: ' . $err];
        }
        if ($code >= 400) {
            return [(string) $body, 'Euronext returned HTTP ' . $code];
        }
        return [(string) $body, ''];
    }
    $ctx  = stream_context_create(['http' => ['timeout' => 20, 'header' => implode("\r\n", $headers)]]);
    $body = @file_get_contents($url, false, $ctx);
    return $body === false ? ['', 'file_get_contents failed (cURL missing, allow_url_fopen off?)'] : [$body, ''];
}

function parse_delivery(string $text): ?string
{
    if (!preg_match('/\b([A-Za-z]{3})[a-z]*[\s\-\/\']*((?:20)?\d{2})\b/', $text, $m)) {
        return null;
    }
    $mon = strtolower($m[1]);
    if (!isset(MONTHS[$mon])) {
        return null;
    }
    $year = strlen($m[2]) === 2 ? '20' . $m[2] : $m[2];
    return MONTHS[$mon] . ' ' . $year;
}

function parse_price(string $text): ?string
{
    $t = str_replace(["\xC2\xA0", ' '], '', $text);
    if (!preg_match('/\d+(?:[.,]\d+)?/', $t, $m)) {
        return null;
    }
    return str_replace(',', '.', $m[0]);
}

/** Reads the first table of delivery months / prices out of Euronext's HTML. */
function parse_quotes(string $html): array
{
    if (trim($html) === '') {
        return [];
    }
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
    libxml_clear_errors();

    $rows = [];
    foreach ($dom->getElementsByTagName('tr') as $tr) {
        $cells = [];
        foreach ($tr->childNodes as $node) {
            if ($node instanceof DOMElement && in_array(strtolower($node->tagName), ['td', 'th'], true)) {
                $cells[] = trim(preg_replace('/\s+/u', ' ', $node->textContent));
            }
        }
        if ($cells) {
            $rows[] = $cells;
        }
    }
    if (!$rows) {
        return [];
    }

    $header   = array_map('strtolower', $rows[0]);
    $monthCol = 0;
    foreach ($header as $i => $c) {
        if (strpos($c, 'delivery') !== false || strpos($c, 'month') !== false) {
            $monthCol = $i;
            break;
        }
    }
    $priceCol = null;
    foreach (['last', 'settl'] as $want) {
        foreach ($header as $i => $c) {
            if (strpos($c, $want) !== false) {
                $priceCol = $i;
                break 2;
            }
        }
    }
    if ($priceCol === null) {
        $priceCol = 1;
    }

    $quotes = [];
    foreach (array_slice($rows, 1) as $row) {
        if (max($monthCol, $priceCol) >= count($row)) {
            continue;
        }
        $delivery = parse_delivery($row[$monthCol]);
        $price    = parse_price($row[$priceCol]);
        if ($delivery !== null && $price !== null) {
            $quotes[] = ['delivery' => $delivery, 'price' => number_format((float) $price, 2, '.', '')];
        }
    }
    return $quotes;
}

function cache_dir(): string
{
    $dir = __DIR__ . '/cache';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    if (is_dir($dir) && is_writable($dir)) {
        return $dir;
    }
    $tmp = sys_get_temp_dir() . '/euronext_prices_cache';
    if (!is_dir($tmp)) {
        @mkdir($tmp, 0755, true);
    }
    return $tmp;
}

function respond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function main(): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    date_default_timezone_set('Europe/Vilnius');

    $key = isset($_GET['instrument']) ? (string) $_GET['instrument'] : 'wheat';
    if (!isset(INSTRUMENTS[$key])) {
        respond(['error' => 'unknown instrument'], 400);
    }
    $info      = INSTRUMENTS[$key];
    $cacheFile = cache_dir() . '/' . $key . '.json';
    $debug     = isset($_GET['debug']);

    if (!$debug && is_file($cacheFile) && (time() - filemtime($cacheFile)) < CACHE_TTL) {
        readfile($cacheFile);
        exit;
    }

    [$html, $err] = http_get(sprintf(ENDPOINT, $info['code']));

    if ($debug) {
        respond([
            'error'   => $err ?: null,
            'bytes'   => strlen($html),
            'quotes'  => parse_quotes($html),
            'snippet' => substr($html, 0, 3000),
        ]);
    }

    $quotes = parse_quotes($html);
    if ($quotes) {
        $payload = [
            'marketPrices' => [[
                'instrument' => $key,
                'label'      => $info['label'],
                'currency'   => 'EUR',
                'priceDate'  => date('Y-m-d'),
                'quotes'     => array_slice($quotes, 0, 5),
            ]],
            'marketPricesUpdatedAt' => date('Y-m-d H:i'),
        ];
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $tmp  = $cacheFile . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, $json) !== false) {
            @rename($tmp, $cacheFile);
        }
        echo $json;
        exit;
    }

    if (is_file($cacheFile)) { // Euronext failed: serve the last good copy
        $old = json_decode((string) file_get_contents($cacheFile), true);
        if (is_array($old)) {
            $old['stale'] = true;
            respond($old);
        }
    }
    respond(['error' => $err ?: 'no prices found in Euronext response (try prices.php?debug=1)'], 502);
}

if (!defined('PRICES_LIB')) {
    main();
}
