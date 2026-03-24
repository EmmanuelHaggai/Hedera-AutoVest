<?php
/**
 * get_nse_snapshot.php
 * Unified reader for NEW schema (nse_ticks + nse_announcements) and legacy (nse_quotes).
 */

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

// ------------ .env ------------
$envPath = __DIR__ . '/.env';
try {
    if (!file_exists($envPath)) throw new RuntimeException('.env not found at ' . $envPath);
    $env   = parse_ini_file($envPath, false, INI_SCANNER_TYPED);
    if ($env === false) throw new RuntimeException('failed to parse .env');
    $DEBUG = isset($env['DEBUG']) && filter_var($env['DEBUG'], FILTER_VALIDATE_BOOLEAN);
    if ($DEBUG) { ini_set('display_errors','1'); ini_set('display_startup_errors','1'); error_reporting(E_ALL); }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
    exit;
}

// ------------ Config ------------
$DB_HOST = $env['DB_HOST'] ?? '127.0.0.1';
$DB_NAME = $env['DB_NAME'] ?? 'hedera_ai';
$DB_USER = $env['DB_USER'] ?? '';
$DB_PASS = $env['DB_PASS'] ?? '';
$READ_API_KEY = $env['READ_API_KEY'] ?? null;

// CORS
$ALLOWED_ORIGINS = trim((string)($env['ALLOWED_ORIGINS'] ?? ''));
$allowedOrigins = $ALLOWED_ORIGINS === '' ? [] : array_map('trim', explode(',', $ALLOWED_ORIGINS));
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowOrigin = in_array('*', $allowedOrigins, true) ? '*' : (($origin && in_array($origin, $allowedOrigins, true)) ? $origin : '');
if ($allowOrigin !== '') {
    header("Access-Control-Allow-Origin: {$allowOrigin}");
    header('Vary: Origin');
    header('Access-Control-Allow-Methods: GET, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-API-Key');
    header('Access-Control-Max-Age: 600');
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(204); exit; }

// Optional read key
$providedKey = $_GET['key'] ?? ($_SERVER['HTTP_X_API_KEY'] ?? null);
if ($READ_API_KEY) {
    if (!$providedKey || !hash_equals((string)$READ_API_KEY, (string)$providedKey)) {
        http_response_code(401);
        echo json_encode(['ok'=>false,'error'=>'unauthorized']);
        exit;
    }
}

// Input
$limit = isset($_GET['limit']) ? max(0, (int)$_GET['limit']) : null;

// ------------ DB connect ------------
try {
    $pdo = new PDO("mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4",
        $DB_USER, $DB_PASS,
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'db connect failed','detail'=>$DEBUG?$e->getMessage():null]);
    exit;
}

// Helpers
function table_exists(PDO $pdo, string $tbl): bool {
    try { $pdo->query("SELECT 1 FROM `$tbl` LIMIT 1"); return true; }
    catch (Throwable $e) { return false; }
}
function supports_windows(PDO $pdo): bool {
    // crude check: try a tiny window query once
    try { $pdo->query("SELECT 1, ROW_NUMBER() OVER() AS rn"); return true; }
    catch (Throwable $e) { return false; }
}
function filter_valid_tickers(array $rows): array {
    return array_values(array_filter($rows, function($r){
        return isset($r['ticker']) && preg_match('/^[A-Z]{2,6}$/', $r['ticker']);
    }));
}

// ------------ NEW PATH ------------
if (table_exists($pdo, 'nse_ticks')) {
    try {
        // Latest asof_date present
        $row = $pdo->query("SELECT MAX(asof_date) AS d FROM nse_ticks")->fetch();
        $asof = $row['d'] ?? null;
        if (!$asof) {
            echo json_encode(['ok'=>true,'snapshot_dt'=>null,'market_status'=>'unknown','market_note'=>'no data','ticks'=>[],'announcements'=>[],'rows'=>[],'counts'=>['tickers'=>0,'announcements'=>0]]);
            exit;
        }

        // Latest snapshot_dt on that date
        $st = $pdo->prepare("SELECT MAX(snapshot_dt) AS s FROM nse_ticks WHERE asof_date = :d");
        $st->execute([':d'=>$asof]);
        $snap = $st->fetch()['s'] ?? null;

        // Per-ticker latest row for that date
        $ticks = [];
        if (supports_windows($pdo)) {
            $sql = "
                WITH ranked AS (
                  SELECT
                    ticker, asof_date, time_str, prev_close, latest, change_abs, change_pct,
                    high, low, volume, vwap, deals, turnover, foreign_pct, status, snapshot_dt,
                    ROW_NUMBER() OVER (PARTITION BY ticker ORDER BY (time_str='00:00:00'), time_str DESC, snapshot_dt DESC) AS rn
                  FROM nse_ticks
                  WHERE asof_date = :d
                )
                SELECT * FROM ranked WHERE rn = 1
                ORDER BY ticker ASC
            ";
            if ($limit) $sql .= " LIMIT ".(int)$limit; // constant limit with windows
            $st2 = $pdo->prepare($sql);
            $st2->execute([':d'=>$asof]);
            $ticks = $st2->fetchAll();
        } else {
            // MySQL 5.7 fallback: pick latest per ticker via subquery
            $sql = "
                SELECT t.*
                FROM nse_ticks t
                INNER JOIN (
                  SELECT ticker, 
                         MAX(CONCAT(CASE WHEN time_str='00:00:00' THEN 0 ELSE 1 END, '_', time_str, '_', snapshot_dt)) AS rankkey
                  FROM nse_ticks
                  WHERE asof_date = :d
                  GROUP BY ticker
                ) r
                ON t.ticker = r.ticker
               AND CONCAT(CASE WHEN t.time_str='00:00:00' THEN 0 ELSE 1 END, '_', t.time_str, '_', t.snapshot_dt) = r.rankkey
               WHERE t.asof_date = :d
               ORDER BY t.ticker ASC
            ";
            if ($limit) $sql .= " LIMIT :lim";
            $st2 = $pdo->prepare($sql);
            $st2->bindValue(':d', $asof);
            if ($limit) $st2->bindValue(':lim', $limit, PDO::PARAM_INT);
            $st2->execute();
            $ticks = $st2->fetchAll();
        }

        // Filter invalid tickers (safety)
        $ticks = filter_valid_tickers($ticks);

        // Announcements (if table exists)
        $anns = [];
        if (table_exists($pdo, 'nse_announcements')) {
            $sqlAnn = "SELECT asof_date, time_str, type, message FROM nse_announcements WHERE asof_date = :d ORDER BY time_str ASC";
            if ($limit) $sqlAnn .= " LIMIT :lim";
            $stAnn = $pdo->prepare($sqlAnn);
            $stAnn->bindValue(':d', $asof);
            if ($limit) $stAnn->bindValue(':lim', $limit, PDO::PARAM_INT);
            $stAnn->execute();
            $anns = $stAnn->fetchAll();
        }

        // Market status heuristic
        $marketStatus = 'unknown';
        $marketNote = '';
        if ($ticks) {
            $times = array_values(array_filter(array_map(fn($t) => $t['time_str'] ?? null, $ticks)));
            $unique = count(array_unique($times));
            if ($unique <= 2) { $marketStatus = 'closed'; $marketNote = 'static times'; }
            else { $marketStatus = 'open'; $marketNote = 'varying times'; }
        } else {
            $marketStatus = 'closed';
            $marketNote = 'no ticks for day';
        }

        // Legacy rows mapping
        $rows = array_map(function($r){
            return [
                'name'        => $r['ticker'],
                'time'        => $r['time_str'],
                'prevClosing' => $r['prev_close'],
                'closing'     => $r['latest'],
                'change'      => $r['change_abs'],
                'high'        => $r['high'],
                'low'         => $r['low'],
                'volume'      => $r['volume'],
                'vwap'        => $r['vwap'],
                'deals'       => $r['deals'],
                'turnover'    => $r['turnover'],
                'foreign'     => ($r['foreign_pct'] !== null) ? round((float)$r['foreign_pct']*100, 2).'%' : null,
                'status'      => $r['status'],
            ];
        }, $ticks);

        $out = [
            'ok'            => true,
            'snapshot_dt'   => $snap,
            'market_status' => $marketStatus,
            'market_note'   => $marketNote,
            'ticks'         => $ticks,
            'announcements' => array_map(fn($a) => [
                'time'    => $a['time_str'],
                'type'    => $a['type'],
                'message' => $a['message'],
            ], $anns),
            'rows'          => $rows,   // legacy
            'counts'        => [
                'tickers'       => count($ticks),
                'announcements' => count($anns)
            ]
        ];
        echo json_encode($out, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION);
        exit;

    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok'=>false,'error'=>'query failed (new schema)','detail'=>$DEBUG?$e->getMessage():null]);
        exit;
    }
}

// ------------ LEGACY PATH (nse_quotes) ------------
try {
    $pdo->query("SELECT 1 FROM nse_quotes LIMIT 1");
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'neither nse_ticks nor nse_quotes found','detail'=>$DEBUG?$e->getMessage():null]);
    exit;
}

try {
    $latest = $pdo->query("SELECT MAX(snapshot_dt) AS latest FROM nse_quotes")->fetch()['latest'] ?? null;
    if (!$latest) {
        echo json_encode(['ok'=>true,'snapshot_dt'=>null,'ticks'=>[],'announcements'=>[],'rows'=>[],'counts'=>['tickers'=>0,'announcements'=>0]]);
        exit;
    }

    $sql = "SELECT name, t_time, prev_closing, closing, change_px AS `change`, high, low, volume, vwap, deals, turnover_raw AS turnover, foreign_raw AS `foreign`, status
            FROM nse_quotes
            WHERE snapshot_dt = :latest
            ORDER BY name ASC";
    if ($limit) $sql .= " LIMIT :lim";
    $st = $pdo->prepare($sql);
    $st->bindValue(':latest', $latest);
    if ($limit) $st->bindValue(':lim', $limit, PDO::PARAM_INT);
    $st->execute();
    $rows = $st->fetchAll();

    echo json_encode([
        'ok' => true,
        'snapshot_dt' => $latest,
        'ticks' => [],
        'announcements' => [],
        'rows' => array_map(function($r){
            return [
                'name'         => $r['name'],
                'time'         => $r['t_time'],
                'prevClosing'  => $r['prev_closing'],
                'closing'      => $r['closing'],
                'change'       => $r['change'],
                'high'         => $r['high'],
                'low'          => $r['low'],
                'volume'       => $r['volume'],
                'vwap'         => $r['vwap'],
                'deals'        => $r['deals'],
                'turnover'     => $r['turnover'],
                'foreign'      => $r['foreign'],
                'status'       => $r['status'],
            ];
        }, $rows),
        'counts' => ['tickers'=>count($rows), 'announcements'=>0]
    ], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION);
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'query failed (legacy schema)','detail'=>$DEBUG?$e->getMessage():null]);
    exit;
}
