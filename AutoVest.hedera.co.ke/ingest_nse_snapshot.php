<?php
declare(strict_types=1);

/**
 *  DB schema:
 * - nse_ticks 
 * - nse_announcements 
 * - securities
 */

////////////////////////////////////////////////////////////
// CORS 
////////////////////////////////////////////////////////////
$allowedOrigin = 'https://live.mystocks.co.ke';
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin === $allowedOrigin) {
    header("Access-Control-Allow-Origin: $allowedOrigin");
    header("Vary: Origin");
    header("Access-Control-Allow-Methods: POST, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, X-API-Key");
    header("Access-Control-Max-Age: 600");
}
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}
header('Content-Type: application/json');

////////////////////////////////////////////////////////////
// Load env
////////////////////////////////////////////////////////////
$envPath = __DIR__ . '/.env';
if (!file_exists($envPath)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => '.env not found']);
    exit;
}
$env = parse_ini_file($envPath, false, INI_SCANNER_TYPED);
if (!$env) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'failed to parse .env']);
    exit;
}

$DB_HOST = $env['DB_HOST'] ?? '127.0.0.1';
$DB_NAME = $env['DB_NAME'] ?? 'hedera_ai';
$DB_USER = $env['DB_USER'] ?? '';
$DB_PASS = $env['DB_PASS'] ?? '';
$API_KEY = $env['API_KEY'] ?? '';

if (!$DB_USER || !$DB_PASS || !$API_KEY) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'missing .env configuration']);
    exit;
}

////////////////////////////////////////////////////////////
// Authenticate
////////////////////////////////////////////////////////////
$hdrKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
if (!hash_equals($API_KEY, $hdrKey)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthorized']);
    exit;
}

////////////////////////////////////////////////////////////
// Read body
////////////////////////////////////////////////////////////
$raw = file_get_contents('php://input');
if ($raw === false || trim($raw) === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'empty body']);
    exit;
}
$data = json_decode($raw, true);
if ($data === null) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid json']);
    exit;
}

////////////////////////////////////////////////////////////
// Helpers
////////////////////////////////////////////////////////////
function kmToFloat(?string $s): ?float {
    if ($s === null) return null;
    $t = trim(str_replace(',', '', $s));
    if ($t === '' || $t === '-' || $t === '—') return null;
    if (preg_match('/^(-?\d+(?:\.\d+)?)([KkMm])?$/', $t, $m)) {
        $v = (float)$m[1];
        $suf = strtolower($m[2] ?? '');
        if ($suf === 'k') return $v * 1e3;
        if ($suf === 'm') return $v * 1e6;
        return $v;
    }
    return is_numeric($t) ? (float)$t : null;
}
function pctToFloat(?string $s): ?float {
    if ($s === null) return null;
    $t = preg_replace('/\s+/', '', $s);
    if ($t === '' || $t === '-' || $t === '—') return null;
    if (preg_match('/(-?\d+(?:\.\d+)?)%/', $t, $m)) {
        return ((float)$m[1]) / 100.0;
    }
    return null;
}
function clockOrNull(?string $hhmmss): ?string {
    $t = trim((string)$hhmmss);
    if ($t === '') return null;
    if (!preg_match('/^(\d{1,2}):(\d{1,2}):(\d{1,2})$/', $t, $m)) return null;
    // pad to hh:mm:ss
    return sprintf('%02d:%02d:%02d', (int)$m[1], (int)$m[2], (int)$m[3]);
}
function statusValue(?string $s): string {
    return $s === 'no_trades' ? 'no_trades' : 'traded';
}
function tickerKey(string $name): string {
    // normalize e.g. "EQTY" to uppercase; trim spaces
    return strtoupper(trim($name));
}

$nowNairobi = (new DateTimeImmutable('now', new DateTimeZone('Africa/Nairobi')));
$nowServer  = (new DateTimeImmutable('now')); // server time
$asofDate   = $nowNairobi->format('Y-m-d');   // store date as Nairobi-local

////////////////////////////////////////////////////////////
// DB connect
////////////////////////////////////////////////////////////
try {
    $dsn = "mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4";
    $pdo = new PDO($dsn, $DB_USER, $DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'db connect failed']);
    exit;
}

////////////////////////////////////////////////////////////
// Detect payload shape
////////////////////////////////////////////////////////////
$isWrapped = is_array($data) && array_key_exists('stocks', $data) && array_key_exists('announcements', $data);
$stocks = [];
$announcements = [];
$market_status = null;
$market_note = null;
$ts_client = null;

if ($isWrapped) {
    $stocks = is_array($data['stocks']) ? $data['stocks'] : [];
    $announcements = is_array($data['announcements']) ? $data['announcements'] : [];
    $market_status = $data['market_status'] ?? null;
    $market_note   = $data['market_note']   ?? null;
    $ts_client     = $data['ts_client']     ?? null;
} elseif (is_array($data) && isset($data[0]) && is_array($data[0])) {
    // Back-compat: original flat array of stock rows
    $stocks = $data;
} else {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'unexpected payload shape']);
    exit;
}

////////////////////////////////////////////////////////////
// Prepare statements
////////////////////////////////////////////////////////////
// nse_ticks upsert
$sqlTick = <<<SQL
INSERT INTO nse_ticks
(ticker, asof_date, time_str, prev_close, latest, change_abs, change_pct, high, low, volume, vwap, deals, turnover, foreign_pct, status, snapshot_dt, raw_json)
VALUES
(:ticker, :asof_date, :time_str, :prev_close, :latest, :change_abs, :change_pct, :high, :low, :volume, :vwap, :deals, :turnover, :foreign_pct, :status, :snapshot_dt, :raw_json)
ON DUPLICATE KEY UPDATE
  prev_close = VALUES(prev_close),
  latest     = VALUES(latest),
  change_abs = VALUES(change_abs),
  change_pct = VALUES(change_pct),
  high       = VALUES(high),
  low        = VALUES(low),
  volume     = VALUES(volume),
  vwap       = VALUES(vwap),
  deals      = VALUES(deals),
  turnover   = VALUES(turnover),
  foreign_pct= VALUES(foreign_pct),
  status     = VALUES(status),
  snapshot_dt= VALUES(snapshot_dt),
  raw_json   = VALUES(raw_json)
SQL;
$insTick = $pdo->prepare($sqlTick);

// nse_announcements upsert
$sqlAnn = <<<SQL
INSERT INTO nse_announcements
(asof_date, time_str, type, message, snapshot_dt, raw_json)
VALUES
(:asof_date, :time_str, :type, :message, :snapshot_dt, :raw_json)
ON DUPLICATE KEY UPDATE
  type        = VALUES(type),
  message     = VALUES(message),
  snapshot_dt = VALUES(snapshot_dt),
  raw_json    = VALUES(raw_json)
SQL;
$insAnn = $pdo->prepare($sqlAnn);

////////////////////////////////////////////////////////////
// Ingest
////////////////////////////////////////////////////////////
$pdo->beginTransaction();
$savedStocks = 0;
$savedAnns   = 0;

try {
    // Stocks
    foreach ($stocks as $r) {
        // The new JS sends numeric fields; also support legacy raw strings
        $ticker = tickerKey((string)($r['name'] ?? $r['ticker'] ?? ''));
        if ($ticker === '') continue;

        $prev_close = $r['prev'] ?? $r['prevClosing'] ?? null;
        $latest     = $r['latest'] ?? $r['closing'] ?? null;
        $changeAbs  = $r['ch_raw'] ?? $r['change'] ?? null;
        $changePct  = $r['ch_pct'] ?? $r['change_pct'] ?? null;
        $high       = $r['high'] ?? null;
        $low        = $r['low'] ?? null;
        $volume     = $r['volume'] ?? null;
        $vwap       = $r['vwap'] ?? null;
        $deals      = $r['deals'] ?? null;
        $turnover   = $r['turnover_num'] ?? $r['turnover'] ?? null; // JS already numeric; fallback parse
        $foreignPct = $r['foreign_pct'] ?? $r['foreign'] ?? null;
        $timeStr    = clockOrNull($r['time'] ?? null);
        $status     = statusValue($r['status'] ?? null);

        // Parse if legacy strings slipped through
        $prev_close = is_numeric($prev_close) ? (float)$prev_close : kmToFloat($prev_close);
        $latest     = is_numeric($latest)     ? (float)$latest     : kmToFloat($latest);
        $changeAbs  = is_numeric($changeAbs)  ? (float)$changeAbs  : kmToFloat($changeAbs);
        $high       = is_numeric($high)       ? (float)$high       : kmToFloat($high);
        $low        = is_numeric($low)        ? (float)$low        : kmToFloat($low);
        $volume     = is_numeric($volume)     ? (float)$volume     : kmToFloat((string)$volume);
        $vwap       = is_numeric($vwap)       ? (float)$vwap       : kmToFloat($vwap);
        $deals      = is_numeric($deals)      ? (int)$deals        : (int)kmToFloat((string)$deals);
        $turnover   = is_numeric($turnover)   ? (float)$turnover   : kmToFloat((string)$turnover);
        $changePct  = is_numeric($changePct)  ? (float)$changePct  : pctToFloat((string)$changePct);
        $foreignPct = is_numeric($foreignPct) ? (float)$foreignPct : pctToFloat((string)$foreignPct);

        $insTick->execute([
            ':ticker'     => $ticker,
            ':asof_date'  => $asofDate,
            ':time_str'   => $timeStr ?? '00:00:00',
            ':prev_close' => $prev_close,
            ':latest'     => $latest,
            ':change_abs' => $changeAbs,
            ':change_pct' => $changePct,
            ':high'       => $high,
            ':low'        => $low,
            ':volume'     => $volume,
            ':vwap'       => $vwap,
            ':deals'      => $deals,
            ':turnover'   => $turnover,
            ':foreign_pct'=> $foreignPct,
            ':status'     => $status,
            ':snapshot_dt'=> $nowServer->format('Y-m-d H:i:s'),
            ':raw_json'   => json_encode($r, JSON_UNESCAPED_UNICODE),
        ]);
        $savedStocks++;
    }

    // Announcements
    foreach ($announcements as $a) {
        $msg  = trim((string)($a['message'] ?? ''));
        if ($msg === '') continue;
        $tt   = clockOrNull($a['time'] ?? null) ?? '00:00:00';
        $type = (string)($a['type'] ?? 'exchange');

        $insAnn->execute([
            ':asof_date'  => $asofDate,
            ':time_str'   => $tt,
            ':type'       => $type,
            ':message'    => $msg,
            ':snapshot_dt'=> $nowServer->format('Y-m-d H:i:s'),
            ':raw_json'   => json_encode($a, JSON_UNESCAPED_UNICODE),
        ]);
        $savedAnns++;
    }

    $pdo->commit();

    echo json_encode([
        'ok' => true,
        'saved_stocks' => $savedStocks,
        'saved_announcements' => $savedAnns,
        'market_status' => $market_status,
        'market_note' => $market_note,
        'ts_client' => $ts_client,
    ]);
} catch (Throwable $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
