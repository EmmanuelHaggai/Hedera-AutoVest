<?php
/**
 * get_nse_search.php
 *
 * Powerful read/search endpoint for NSE quotes stored in nse_quotes.
 * Reads config from .env (same as your other endpoints):
 *
 * Query parameters (all optional unless noted):
 *   latest_only=true|false        default true. When true, restrict to the most recent snapshot_dt.
 *   snapshot_dt=YYYY-MM-DD HH:MM:SS   exact snapshot to fetch when latest_only=false.
 *   since=YYYY-MM-DD HH:MM:SS     lower bound for snapshot_dt when latest_only=false.
 *   until=YYYY-MM-DD HH:MM:SS     upper bound for snapshot_dt when latest_only=false.
 *   name=ABSA,EQTY,…              exact ticker(s), comma-separated.
 *   q=pattern                     case-insensitive LIKE match on name.
 *   status=traded|no_trades       filter by status.
 *   time_from=HH:MM:SS            filter t_time >= this.
 *   time_to=HH:MM:SS              filter t_time <= this.
 *   min_close=, max_close=        numeric range on closing.
 *   min_volume=, max_volume=      numeric range on volume.
 *   min_deals=,  max_deals=       numeric range on deals.
 *   sort=name|closing|volume|deals|time|snapshot_dt   default name.
 *   order=asc|desc                default asc.
 *   limit=1..500                  default 100.
 *   page=1..N                     default 1 (offset = (page-1)*limit).
 *   fields=name,closing,time,…    subset of columns to return.
 *   distinct=names                returns a unique sorted list of tickers.
 *   format=json|csv               default json. csv ignores pagination meta.
 *   key=READ_API_KEY              only required if you set READ_API_KEY in .env.
 */

declare(strict_types=1);

// ---- Environment and debug ----
header('Content-Type: application/json; charset=utf-8');
$envPath = __DIR__ . '/.env';
if (!file_exists($envPath)) { http_response_code(500); echo json_encode(['ok'=>false,'error'=>'.env not found']); exit; }
$env = parse_ini_file($envPath, false, INI_SCANNER_TYPED);
if ($env === false) { http_response_code(500); echo json_encode(['ok'=>false,'error'=>'failed to parse .env']); exit; }
$DEBUG = isset($env['DEBUG']) && filter_var($env['DEBUG'], FILTER_VALIDATE_BOOLEAN);
if ($DEBUG) { ini_set('display_errors','1'); error_reporting(E_ALL); }

// ---- Config ----
$DB_HOST = $env['DB_HOST'] ?? '127.0.0.1';
$DB_NAME = $env['DB_NAME'] ?? 'hedera_ai';
$DB_USER = $env['DB_USER'] ?? '';
$DB_PASS = $env['DB_PASS'] ?? '';
$READ_API_KEY = $env['READ_API_KEY'] ?? null;
$ALLOWED_ORIGINS = trim((string)($env['ALLOWED_ORIGINS'] ?? ''));

// ---- CORS ----
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowed = $ALLOWED_ORIGINS === '' ? [] : array_map('trim', explode(',', $ALLOWED_ORIGINS));
$allowOrigin = '';
if (in_array('*', $allowed, true)) $allowOrigin = '*';
elseif ($origin && in_array($origin, $allowed, true)) $allowOrigin = $origin;
if ($allowOrigin !== '') {
  header("Access-Control-Allow-Origin: {$allowOrigin}");
  header('Vary: Origin');
  header('Access-Control-Allow-Methods: GET, OPTIONS');
  header('Access-Control-Allow-Headers: Content-Type, X-API-Key');
  header('Access-Control-Max-Age: 600');
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(204); exit; }

// ---- Optional read key ----
$providedKey = $_GET['key'] ?? ($_SERVER['HTTP_X_API_KEY'] ?? null);
if ($READ_API_KEY && (!$providedKey || !hash_equals((string)$READ_API_KEY, (string)$providedKey))) {
  http_response_code(401); echo json_encode(['ok'=>false,'error'=>'unauthorized']); exit;
}

// ---- Input parsing ----
function get_bool($k, $def) {
  if (!isset($_GET[$k])) return $def;
  $v = strtolower((string)$_GET[$k]); return in_array($v, ['1','true','yes','on'], true);
}
$latestOnly = get_bool('latest_only', true);
$snapshotDt = isset($_GET['snapshot_dt']) ? trim((string)$_GET['snapshot_dt']) : null;
$since      = isset($_GET['since']) ? trim((string)$_GET['since']) : null;
$until      = isset($_GET['until']) ? trim((string)$_GET['until']) : null;

$nameParam  = isset($_GET['name']) ? array_filter(array_map('trim', explode(',', (string)$_GET['name']))) : [];
$q          = isset($_GET['q']) ? trim((string)$_GET['q']) : null;
$status     = isset($_GET['status']) ? trim((string)$_GET['status']) : null;

$timeFrom   = isset($_GET['time_from']) ? trim((string)$_GET['time_from']) : null;
$timeTo     = isset($_GET['time_to']) ? trim((string)$_GET['time_to']) : null;

$minClose   = isset($_GET['min_close']) ? (float)$_GET['min_close'] : null;
$maxClose   = isset($_GET['max_close']) ? (float)$_GET['max_close'] : null;
$minVol     = isset($_GET['min_volume']) ? (int)$_GET['min_volume'] : null;
$maxVol     = isset($_GET['max_volume']) ? (int)$_GET['max_volume'] : null;
$minDeals   = isset($_GET['min_deals']) ? (int)$_GET['min_deals'] : null;
$maxDeals   = isset($_GET['max_deals']) ? (int)$_GET['max_deals'] : null;

$sort       = strtolower((string)($_GET['sort'] ?? 'name'));
$order      = strtolower((string)($_GET['order'] ?? 'asc'));
$limit      = isset($_GET['limit']) ? max(1, min(500, (int)$_GET['limit'])) : 100;
$page       = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset     = ($page - 1) * $limit;

$fields     = isset($_GET['fields']) ? array_filter(array_map('trim', explode(',', (string)$_GET['fields']))) : [];
$distinct   = isset($_GET['distinct']) ? trim((string)$_GET['distinct']) : null;
$format     = strtolower((string)($_GET['format'] ?? 'json'));

// ---- DB connect ----
try {
  $dsn = "mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4";
  $pdo = new PDO($dsn, $DB_USER, $DB_PASS, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
  ]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'db connect failed','detail'=>$DEBUG?$e->getMessage():null]); exit;
}

// ---- Distinct tickers mode ----
if ($distinct === 'names') {
  $sql = "SELECT DISTINCT name FROM nse_quotes ORDER BY name ASC";
  $names = $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN);
  if ($format === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    echo "name\n"; foreach ($names as $n) echo $n . "\n"; exit;
  }
  echo json_encode(['ok'=>true,'count'=>count($names),'names'=>$names]); exit;
}

// ---- Resolve base snapshot scope ----
$w = []; $p = [];
if ($latestOnly) {
  $latest = $pdo->query("SELECT MAX(snapshot_dt) AS latest FROM nse_quotes")->fetchColumn();
  if (!$latest) { echo json_encode(['ok'=>true,'snapshot_dt'=>null,'count'=>0,'rows'=>[]]); exit; }
  $w[] = "snapshot_dt = :latest"; $p[':latest'] = $latest;
  $snapshotContext = $latest;
} else {
  if ($snapshotDt) { $w[] = "snapshot_dt = :snap"; $p[':snap'] = $snapshotDt; $snapshotContext = $snapshotDt; }
  if ($since)      { $w[] = "snapshot_dt >= :since"; $p[':since'] = $since; }
  if ($until)      { $w[] = "snapshot_dt <= :until"; $p[':until'] = $until; }
  $snapshotContext = $snapshotDt ?: ($since ?: null);
}

// ---- Filters ----
if ($nameParam) {
  // Build IN list safely
  $in = [];
  foreach ($nameParam as $i => $n) {
    $key = ":n{$i}";
    $in[] = $key;
    $p[$key] = $n;
  }
  $w[] = "name IN (" . implode(',', $in) . ")";
}
if ($q) {
  $w[] = "name LIKE :q";
  $p[':q'] = '%' . $q . '%';
}
if ($status === 'traded' || $status === 'no_trades') {
  $w[] = "status = :status"; $p[':status'] = $status;
}
if ($timeFrom) { $w[] = "t_time >= :tf"; $p[':tf'] = $timeFrom; }
if ($timeTo)   { $w[] = "t_time <= :tt"; $p[':tt'] = $timeTo; }

if ($minClose !== null) { $w[] = "closing IS NOT NULL AND closing >= :minc"; $p[':minc'] = $minClose; }
if ($maxClose !== null) { $w[] = "closing IS NOT NULL AND closing <= :maxc"; $p[':maxc'] = $maxClose; }
if ($minVol   !== null) { $w[] = "volume  IS NOT NULL AND volume  >= :minv"; $p[':minv'] = $minVol; }
if ($maxVol   !== null) { $w[] = "volume  IS NOT NULL AND volume  <= :maxv"; $p[':maxv'] = $maxVol; }
if ($minDeals !== null) { $w[] = "deals   IS NOT NULL AND deals   >= :mind"; $p[':mind'] = $minDeals; }
if ($maxDeals !== null) { $w[] = "deals   IS NOT NULL AND deals   <= :maxd"; $p[':maxd'] = $maxDeals; }

// ---- Field selection ----
$allCols = [
  'name' => 'name',
  'time' => 't_time',
  'prevClosing' => 'prev_closing',
  'closing' => 'closing',
  'change' => 'change_px',
  'high' => 'high',
  'low' => 'low',
  'volume' => 'volume',
  'vwap' => 'vwap',
  'deals' => 'deals',
  'turnover' => 'turnover_raw',
  'foreign' => 'foreign_raw',
  'status' => 'status',
  'snapshot_dt' => 'snapshot_dt'
];
$selectMap = $fields ? array_intersect_key($allCols, array_flip($fields)) : $allCols;
if (empty($selectMap)) $selectMap = $allCols;

// ---- Sorting ----
$sortMap = [
  'name' => 'name',
  'closing' => 'closing',
  'volume' => 'volume',
  'deals' => 'deals',
  'time' => 't_time',
  'snapshot_dt' => 'snapshot_dt'
];
$sortCol = $sortMap[$sort] ?? 'name';
$orderSql = $order === 'desc' ? 'DESC' : 'ASC';

// ---- Build SQL ----
$whereSql = $w ? ('WHERE ' . implode(' AND ', $w)) : '';
$colsSql = implode(', ', array_map(function($alias, $col){ return "$col AS `$alias`"; }, array_keys($selectMap), $selectMap));

$sql = "SELECT {$colsSql} FROM nse_quotes {$whereSql} ORDER BY {$sortCol} {$orderSql} LIMIT :limit OFFSET :offset";
$countSql = "SELECT COUNT(1) FROM nse_quotes {$whereSql}";

// ---- Execute ----
try {
  $countStmt = $pdo->prepare($countSql);
  foreach ($p as $k=>$v) $countStmt->bindValue($k, $v);
  $countStmt->execute();
  $total = (int)$countStmt->fetchColumn();

  $stmt = $pdo->prepare($sql);
  foreach ($p as $k=>$v) $stmt->bindValue($k, $v);
  $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
  $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
  $stmt->execute();
  $rows = $stmt->fetchAll();
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'query failed','detail'=>$DEBUG?$e->getMessage():null]); exit;
}

// ---- Output CSV if requested ----
if ($format === 'csv') {
  header('Content-Type: text/csv; charset=utf-8');
  // If no rows, still emit header
  $headers = array_keys($rows[0] ?? $selectMap);
  $out = fopen('php://output', 'w');
  fputcsv($out, $headers);
  foreach ($rows as $r) fputcsv($out, array_map(fn($k)=>$r[$k] ?? null, $headers));
  fclose($out);
  exit;
}

// ---- Output JSON ----
echo json_encode([
  'ok' => true,
  'latest_only' => $latestOnly,
  'snapshot_context' => $snapshotContext ?? null,
  'total' => $total,
  'page' => $page,
  'limit' => $limit,
  'count' => count($rows),
  'rows' => $rows
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
