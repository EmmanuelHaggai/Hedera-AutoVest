<?php
/**
 * api/signal/index.php
 *
 * Ingest or fetch AutoVest trading signals.
 *
 * Methods:
 *   POST  (default) : create a new signal
 *   GET             : list recent signals (debug/read use)
 *
 * Auth:
 *   - WRITE_API_KEY protects POST (via ?key=... or X-API-Key)
 *   - READ_API_KEY  protects GET  (optional; if present, required)
 *
 * CORS:
 *   - Controlled by ALLOWED_ORIGINS in .env (comma list or '*')
 *
 * Payload (POST JSON):
 * {
 *   "ts": "2025-10-30T12:34:56.789Z",
 *   "ticker": "SCOM",
 *   "position": 0.27,          // -1..1, we clamp to [-1,1]
 *   "confidence": 0.81,        // 0..1, we clamp to [0,1]
 *   "price": 20.35,            // >0
 *   "strategy": "TCN_NSE_v1"   // string
 * }
 *
 * Response:
 *   { "ok": true, "id": 123, "stored": { ...normalized fields... } }
 *
 * Table schema (Its at the bottom comment).
 */

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

// ---- Load .env ----
$envPath = __DIR__ . '/../../.env'; // adjust if your .env sits elsewhere
$debug = false;
try {
    if (!file_exists($envPath)) {
        throw new RuntimeException('.env not found at ' . $envPath);
    }
    $env = parse_ini_file($envPath, false, INI_SCANNER_TYPED);
    if ($env === false) {
        throw new RuntimeException('failed to parse .env');
    }
    $debug = isset($env['DEBUG']) && filter_var($env['DEBUG'], FILTER_VALIDATE_BOOLEAN);
    if ($debug) {
        ini_set('display_errors', '1');
        ini_set('display_startup_errors', '1');
        error_reporting(E_ALL);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    exit;
}

// ---- Config ----
$DB_HOST = $env['DB_HOST'] ?? '127.0.0.1';
$DB_NAME = $env['DB_NAME'] ?? 'hedera_ai';
$DB_USER = $env['DB_USER'] ?? '';
$DB_PASS = $env['DB_PASS'] ?? '';

$WRITE_API_KEY   = $env['WRITE_API_KEY'] ?? null; // required for POST if set
$READ_API_KEY    = $env['READ_API_KEY']  ?? null; // required for GET  if set
$ALLOWED_ORIGINS = trim((string)($env['ALLOWED_ORIGINS'] ?? ''));
$allowedOrigins  = $ALLOWED_ORIGINS === '' ? [] : array_map('trim', explode(',', $ALLOWED_ORIGINS));

// Optional idempotency and dedupe settings
$DEDUPE_WINDOW_SEC = (int)($env['DEDUPE_WINDOW_SEC'] ?? 60); // same ticker+strategy within N sec and same position considered duplicate

// ---- CORS ----
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowOrigin = '';
if (in_array('*', $allowedOrigins, true)) {
    $allowOrigin = '*';
} elseif ($origin && in_array($origin, $allowedOrigins, true)) {
    $allowOrigin = $origin;
}
if ($allowOrigin !== '') {
    header("Access-Control-Allow-Origin: {$allowOrigin}");
    header('Vary: Origin');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-API-Key, Idempotency-Key');
    header('Access-Control-Max-Age: 600');
}
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ---- Auth helpers ----
function provided_api_key(): ?string {
    return $_GET['key'] ?? ($_SERVER['HTTP_X_API_KEY'] ?? null);
}

function require_key(?string $expectedKey, string $realm): void {
    if (!$expectedKey) return; // not enforced
    $got = provided_api_key();
    if (!$got || !hash_equals((string)$expectedKey, (string)$got)) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => "unauthorized ($realm)"]);
        exit;
    }
}

// ---- DB connect ----
try {
    $dsn = "mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4";
    $pdo = new PDO($dsn, $DB_USER, $DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'db connect failed', 'detail' => $debug ? $e->getMessage() : null]);
    exit;
}

// ---- Ensure table exists ----
try {
    $pdo->query("SELECT 1 FROM autovest_signals LIMIT 1");
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'table autovest_signals not found',
        'detail' => $debug ? $e->getMessage() : null,
        'hint' => 'Run the CREATE TABLE for autovest_signals (see file bottom).'
    ]);
    exit;
}

// ---- Handlers ----
if ($method === 'GET') {
    // Optional read key
    require_key($READ_API_KEY, 'read');

    // Query params: limit, ticker, strategy
    $limit    = isset($_GET['limit']) ? max(1, min(1000, (int)$_GET['limit'])) : 100;
    $ticker   = isset($_GET['ticker']) ? trim((string)$_GET['ticker']) : null;
    $strategy = isset($_GET['strategy']) ? trim((string)$_GET['strategy']) : null;

    try {
        $sql = "SELECT id, ts_utc, ticker, position, confidence, price, strategy, source, idempotency_key, created_at
                FROM autovest_signals
                WHERE 1=1";
        $params = [];
        if ($ticker)   { $sql .= " AND ticker = :ticker";   $params[':ticker'] = $ticker; }
        if ($strategy) { $sql .= " AND strategy = :strategy"; $params[':strategy'] = $strategy; }
        $sql .= " ORDER BY id DESC LIMIT :lim";
        $st = $pdo->prepare($sql);
        foreach ($params as $k => $v) $st->bindValue($k, $v);
        $st->bindValue(':lim', $limit, PDO::PARAM_INT);
        $st->execute();
        $rows = $st->fetchAll();
        echo json_encode(['ok' => true, 'count' => count($rows), 'rows' => $rows], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        exit;
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'fetch failed', 'detail' => $debug ? $e->getMessage() : null]);
        exit;
    }
}

// POST
require_key($WRITE_API_KEY, 'write');

// ---- Read and validate JSON body ----
$raw = file_get_contents('php://input') ?: '';
$ctype = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
if (stripos($ctype, 'application/json') === false) {
    // Accept JSON anyway, but warn
}

$data = json_decode($raw, true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_json']);
    exit;
}

// Fields
$ts        = isset($data['ts'])        ? trim((string)$data['ts']) : null;
$ticker    = isset($data['ticker'])    ? strtoupper(trim((string)$data['ticker'])) : null;
$position  = isset($data['position'])  ? (float)$data['position'] : null;
$confidence= isset($data['confidence'])? (float)$data['confidence'] : null;
$price     = isset($data['price'])     ? (float)$data['price'] : null;
$strategy  = isset($data['strategy'])  ? trim((string)$data['strategy']) : null;

// Optional headers
$idempotencyKey = $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? null;
$source = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';

// Basic checks
$errors = [];
if (!$ts)        $errors[] = 'ts required';
if (!$ticker)    $errors[] = 'ticker required';
if ($position === null)   $errors[] = 'position required';
if ($confidence === null) $errors[] = 'confidence required';
if ($price === null)      $errors[] = 'price required';
if (!$strategy)  $errors[] = 'strategy required';

if ($errors) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'validation', 'fields' => $errors]);
    exit;
}

// Normalize and clamp
try {
    // Parse ts to UTC DATETIME(6)
    $dt = new DateTime($ts);
    $dt->setTimezone(new DateTimeZone('UTC'));
    $tsUtc = $dt->format('Y-m-d H:i:s.u'); // microseconds
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid ts format, expected ISO8601', 'detail' => $debug ? $e->getMessage() : null]);
    exit;
}

// Sanity bounds
$position   = max(-1.0, min(1.0, (float)$position));
$confidence = max(0.0,  min(1.0, (float)$confidence));
if (!is_finite($price) || $price <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'price must be > 0']);
    exit;
}
if (!preg_match('/^[A-Z0-9\.\-\_]{1,32}$/', $ticker)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid ticker format']);
    exit;
}
if (!preg_match('/^[A-Za-z0-9\.\-\_]{1,64}$/', $strategy)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid strategy format']);
    exit;
}

// Dedupe: same ticker+strategy within window and same rounded position
try {
    $dupeId = null;
    if ($DEDUPE_WINDOW_SEC > 0) {
        $st = $pdo->prepare(
            "SELECT id FROM autovest_signals
             WHERE ticker = :t
               AND strategy = :s
               AND ROUND(position, 4) = ROUND(:p, 4)
               AND ts_utc >= (UTC_TIMESTAMP() - INTERVAL :win SECOND)
             ORDER BY id DESC LIMIT 1"
        );
        $st->bindValue(':t', $ticker);
        $st->bindValue(':s', $strategy);
        $st->bindValue(':p', $position);
        $st->bindValue(':win', $DEDUPE_WINDOW_SEC, PDO::PARAM_INT);
        $st->execute();
        $row = $st->fetch();
        if ($row && isset($row['id'])) {
            $dupeId = (int)$row['id'];
        }
    }

    if ($dupeId !== null) {
        echo json_encode([
            'ok' => true,
            'id' => $dupeId,
            'duplicate' => true,
            'stored' => [
                'ts_utc'     => $tsUtc,
                'ticker'     => $ticker,
                'position'   => $position,
                'confidence' => $confidence,
                'price'      => $price,
                'strategy'   => $strategy
            ]
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        exit;
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'dedupe check failed', 'detail' => $debug ? $e->getMessage() : null]);
    exit;
}

// Insert
try {
    $sql = "INSERT INTO autovest_signals
              (ts_utc, ticker, position, confidence, price, strategy, source, idempotency_key)
            VALUES
              (:ts_utc, :ticker, :position, :confidence, :price, :strategy, :source, :idem)";
    $st = $pdo->prepare($sql);
    $st->bindValue(':ts_utc', $tsUtc);
    $st->bindValue(':ticker', $ticker);
    $st->bindValue(':position', $position);
    $st->bindValue(':confidence', $confidence);
    $st->bindValue(':price', $price);
    $st->bindValue(':strategy', $strategy);
    $st->bindValue(':source', substr((string)$source, 0, 255));
    $st->bindValue(':idem', $idempotencyKey ? substr((string)$idempotencyKey, 0, 64) : null);
    $st->execute();
    $id = (int)$pdo->lastInsertId();

    echo json_encode([
        'ok' => true,
        'id' => $id,
        'stored' => [
            'ts_utc'     => $tsUtc,
            'ticker'     => $ticker,
            'position'   => $position,
            'confidence' => $confidence,
            'price'      => $price,
            'strategy'   => $strategy
        ]
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
} catch (Throwable $e) {
    // If you want idempotency on duplicate idempotency_key, add a UNIQUE KEY and catch here
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'insert failed', 'detail' => $debug ? $e->getMessage() : null]);
    exit;
}

/*
-- Example DDL:

CREATE TABLE `autovest_signals` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ts_utc`           DATETIME(6)     NOT NULL,           -- UTC timestamp from client
  `ticker`           VARCHAR(32)     NOT NULL,
  `position`         DECIMAL(9,6)    NOT NULL,           -- -1..1
  `confidence`       DECIMAL(9,6)    NOT NULL,           -- 0..1
  `price`            DECIMAL(18,6)   NOT NULL,           -- last price
  `strategy`         VARCHAR(64)     NOT NULL,
  `source`           VARCHAR(255)    DEFAULT NULL,       -- user agent
  `idempotency_key`  VARCHAR(64)     DEFAULT NULL,
  `created_at`       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `k_time` (`ts_utc`),
  KEY `k_ticker_time` (`ticker`,`ts_utc`),
  KEY `k_strategy_time` (`strategy`,`ts_utc`),
  KEY `k_ticker_strategy` (`ticker`,`strategy`),
  UNIQUE KEY `uq_idem` (`idempotency_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Optional housekeeping: delete very old rows if needed.
-- You can also add a trigger to cap precision or enforce bounds.
*/

