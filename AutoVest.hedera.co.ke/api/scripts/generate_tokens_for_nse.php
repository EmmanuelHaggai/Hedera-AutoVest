<?php
/**
 * generate_tokens_for_nse.php
 *
 * For every NSE ticker in your DB, create a Hedera token via the local Node API:
 *   POST http://127.0.0.1:5050/tokens
 *
 * Idempotent: keeps a mapping table so tickers are not created twice.
 * Safe defaults: fungible, 2 decimals, initialSupply=0, INFINITE supply.
 *
 * Run:
 *   php generate_tokens_for_nse.php
 * 
 * AutoVest Created Stocks Token List
 * [ok] ABSA -> 0.0.7162682 [ok] AMAC -> 0.0.7162683 [ok] ARM -> 0.0.7162684 [ok] BAMB -> 0.0.7162685 [ok] BAT -> 0.0.7162687 [ok] BKG -> 0.0.7162688 [ok] BOC -> 0.0.7162689 [ok] BRIT -> 0.0.7162690 [ok] CABL -> 0.0.7162691 [ok] CARB -> 0.0.7162692 [ok] CGEN -> 0.0.7162694 [ok] CIC -> 0.0.7162695 [ok] COOP -> 0.0.7162696 [ok] CRWN -> 0.0.7162697 [ok] CTUM -> 0.0.7162698 [ok] DCON -> 0.0.7162699 [ok] DTK -> 0.0.7162700 [ok] EABL -> 0.0.7162701 [ok] EGAD -> 0.0.7162702 [ok] EQTY -> 0.0.7162703 [ok] EVRD -> 0.0.7162704 [ok] FTGH -> 0.0.7162705 [ok] GLD -> 0.0.7162706 [ok] HAFR -> 0.0.7162707 [ok] HBE -> 0.0.7162709 [ok] HFCK -> 0.0.7162710 [ok] IMH -> 0.0.7162711 [ok] JUB -> 0.0.7162712 [ok] KAPC -> 0.0.7162713 [ok] KCB -> 0.0.7162714 [ok] KEGN -> 0.0.7162715 [ok] KNRE -> 0.0.7162717 [ok] KPLC -> 0.0.7162718 [ok] KQ -> 0.0.7162719 [ok] KUKZ -> 0.0.7162720 [ok] KURV -> 0.0.7162721 [ok] LAPR -> 0.0.7162722 [ok] LBTY -> 0.0.7162723 [ok] LIMT -> 0.0.7162724 [ok] LKL -> 0.0.7162725 [ok] MSC -> 0.0.7162726 [ok] NBV -> 0.0.7162727 [ok] NCBA -> 0.0.7162728 [ok] NMG -> 0.0.7162729 [ok] NSE -> 0.0.7162730 [ok] OCH -> 0.0.7162731 [ok] PORT -> 0.0.7162732 [ok] SASN -> 0.0.7162734 [ok] SBIC -> 0.0.7162736 [ok] SCAN -> 0.0.7162737 [ok] SCBK -> 0.0.7162738 [ok] SCOM -> 0.0.7162739 [ok] SGL -> 0.0.7162740 [ok] SKL -> 0.0.7162741 [ok] SLAM -> 0.0.7162742 [ok] SMER -> 0.0.7162743 [ok] SMWF -> 0.0.7162744 [ok] TCL -> 0.0.7162745 [ok] TOTL -> 0.0.7162746 [ok] TPSE -> 0.0.7162747 [ok] UCHM -> 0.0.7162748 [ok] UMME -> 0.0.7162749 [ok] UNGA -> 0.0.7162750 [ok] WTK -> 0.0.7162751 [ok] XPRS -> 0.0.7162752 Done. Created: 65, Skipped: 0, Errors: 0
 * 
 * 
 * 
 */

declare(strict_types=1);

// Dont create the tokens again
die();

// ---------- Settings ----------
$API_BASE   = getenv('HEDERA_LOCAL_API') ?: 'http://127.0.0.1:5050';
$TOK_DEC    = 2;                // decimals for fungible tokens
$TOK_INIT   = 0;                // initial supply
$TOK_SUPPLY = 'INFINITE';       // or 'FINITE' with a maxSupply

// ---------- Load .env ----------
$envPath = '/var/www/AutoVest.hedera.co.ke/.env';
if (!file_exists($envPath)) {
    fwrite(STDERR, ".env not found at {$envPath}\n");
    exit(1);
}
$env = parse_ini_file($envPath, false, INI_SCANNER_TYPED);
if ($env === false) {
    fwrite(STDERR, "Failed to parse .env\n");
    exit(1);
}
$DEBUG   = isset($env['DEBUG']) && filter_var($env['DEBUG'], FILTER_VALIDATE_BOOLEAN);
$DB_HOST = $env['DB_HOST'] ?? '127.0.0.1';
$DB_NAME = $env['DB_NAME'] ?? 'hedera_ai';
$DB_USER = $env['DB_USER'] ?? '';
$DB_PASS = $env['DB_PASS'] ?? '';

// ---------- DB ----------
try {
    $pdo = new PDO(
        "mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4",
        $DB_USER,
        $DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (Throwable $e) {
    fwrite(STDERR, "DB connect failed: " . $e->getMessage() . "\n");
    exit(1);
}

// ---------- Helpers ----------
function table_exists(PDO $pdo, string $tbl): bool {
    try { $pdo->query("SELECT 1 FROM `$tbl` LIMIT 1"); return true; }
    catch (Throwable $e) { return false; }
}

/**
 * Basic cURL POST JSON helper.
 */
function http_post_json(string $url, array $payload, int $timeout = 30): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_TIMEOUT        => $timeout,
    ]);
    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($resp === false) {
        return ['ok' => false, 'error' => "curl error: {$err}", 'code' => $code];
    }
    $json = json_decode($resp, true);
    if (!is_array($json)) {
        return ['ok' => false, 'error' => "invalid JSON from API: {$resp}", 'code' => $code];
    }
    return $json + ['http_code' => $code];
}

/**
 * Sanitize a token name to a readable string, symbol to uppercase A–Z, 1–32 chars.
 */
function make_token_fields(string $ticker, ?string $companyName = null): array {
    $ticker = strtoupper(preg_replace('/[^A-Z0-9]/', '', $ticker));
    if ($ticker === '') $ticker = 'NSE';
    // Hedera allows 1–100 chars for name, 1–32 for symbol (ASCII).
    $symbol = substr($ticker, 0, 32);
    $name   = $companyName ? trim($companyName) : $ticker . ' Token';
    $name   = $name !== '' ? $name : ($ticker . ' Token');
    if (strlen($name) > 100) $name = substr($name, 0, 100);
    return [$name, $symbol];
}

// ---------- Ensure mapping table ----------
$pdo->exec("
  CREATE TABLE IF NOT EXISTS nse_token_map (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    ticker VARCHAR(16) NOT NULL UNIQUE,
    token_id VARCHAR(64) NOT NULL,
    symbol VARCHAR(64) NOT NULL,
    token_name VARCHAR(128) NOT NULL,
    decimals INT NOT NULL DEFAULT 2,
    supply_type ENUM('INFINITE','FINITE') NOT NULL DEFAULT 'INFINITE',
    max_supply BIGINT NULL,
    supply_key TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// ---------- Source of tickers ----------
// Prefer a dedicated table if present; otherwise derive from latest nse_ticks snapshot; otherwise use legacy nse_quotes.
$tickerRows = [];

if (table_exists($pdo, 'nse_tickers')) {
    // Expect either columns: ticker, company_name OR at least ticker
    $stmt = $pdo->query("SELECT ticker, company_name FROM nse_tickers WHERE ticker REGEXP '^[A-Z]{2,6}$' ORDER BY ticker ASC");
    $tickerRows = $stmt->fetchAll();
} elseif (table_exists($pdo, 'nse_ticks')) {
    $row  = $pdo->query("SELECT MAX(asof_date) AS d FROM nse_ticks")->fetch();
    $asof = $row['d'] ?? null;
    if ($asof) {
        $sql = "
          SELECT t.ticker, NULL AS company_name
          FROM (
            SELECT ticker
            FROM nse_ticks
            WHERE asof_date = :d
            GROUP BY ticker
          ) t
          WHERE t.ticker REGEXP '^[A-Z]{2,6}$'
          ORDER BY t.ticker ASC
        ";
        $st = $pdo->prepare($sql);
        $st->execute([':d' => $asof]);
        $tickerRows = $st->fetchAll();
    }
} elseif (table_exists($pdo, 'nse_quotes')) {
    $latest = $pdo->query("SELECT MAX(snapshot_dt) AS s FROM nse_quotes")->fetch()['s'] ?? null;
    if ($latest) {
        $st = $pdo->prepare("SELECT name AS ticker, NULL AS company_name FROM nse_quotes WHERE snapshot_dt = :s GROUP BY name HAVING name REGEXP '^[A-Z]{2,6}$' ORDER BY name ASC");
        $st->execute([':s' => $latest]);
        $tickerRows = $st->fetchAll();
    }
}

if (!$tickerRows) {
    fwrite(STDERR, "No tickers found.\n");
    exit(0);
}

// ---------- Create tokens ----------
$created = 0;
$skipped = 0;
$errors  = 0;

$checkStmt = $pdo->prepare("SELECT token_id FROM nse_token_map WHERE ticker = :t LIMIT 1");
$insertMap = $pdo->prepare("
  INSERT INTO nse_token_map (ticker, token_id, symbol, token_name, decimals, supply_type, max_supply, supply_key)
  VALUES (:ticker, :token_id, :symbol, :token_name, :decimals, :supply_type, :max_supply, :supply_key)
");

foreach ($tickerRows as $row) {
    $ticker = strtoupper(trim($row['ticker'] ?? ''));
    if ($ticker === '' || !preg_match('/^[A-Z]{2,6}$/', $ticker)) {
        if ($DEBUG) fwrite(STDERR, "Skip invalid ticker: " . json_encode($row) . "\n");
        continue;
    }

    // Idempotency: skip if already mapped
    $checkStmt->execute([':t' => $ticker]);
    $existing = $checkStmt->fetchColumn();
    if ($existing) {
        $skipped++;
        if ($DEBUG) echo "[skip] {$ticker} already has token {$existing}\n";
        continue;
    }

    [$tokenName, $symbol] = make_token_fields($ticker, $row['company_name'] ?? null);

    $payload = [
        'name'          => $tokenName,
        'symbol'        => $symbol,
        'decimals'      => $TOK_DEC,
        'initialSupply' => $TOK_INIT,
        'supplyType'    => $TOK_SUPPLY,   // 'INFINITE' by default
        // 'maxSupply'   => 1000000000,   // uncomment if using FINITE
        // 'treasuryAccountId' => '0.0.x' // optional; defaults to operator on the Node service
    ];

    $resp = http_post_json(rtrim($API_BASE, '/') . '/tokens', $payload, 45);

    if (!($resp['ok'] ?? false)) {
        $errors++;
        $msg = $resp['error'] ?? 'unknown error';
        $code = $resp['http_code'] ?? 0;
        fwrite(STDERR, "[error] {$ticker} failed to create token: {$msg} (HTTP {$code})\n");
        continue;
    }

    $tokenId   = $resp['tokenId'] ?? null;
    $supplyKey = $resp['supplyKey'] ?? null;

    if (!$tokenId) {
        $errors++;
        fwrite(STDERR, "[error] {$ticker} created but tokenId missing in response.\n");
        continue;
    }

    // Save mapping
    try {
        $insertMap->execute([
            ':ticker'      => $ticker,
            ':token_id'    => $tokenId,
            ':symbol'      => $symbol,
            ':token_name'  => $tokenName,
            ':decimals'    => $TOK_DEC,
            ':supply_type' => $TOK_SUPPLY,
            ':max_supply'  => null,
            ':supply_key'  => $supplyKey,
        ]);
        $created++;
        echo "[ok] {$ticker} -> {$tokenId}\n";
    } catch (Throwable $e) {
        $errors++;
        fwrite(STDERR, "[error] failed to insert map for {$ticker}: " . $e->getMessage() . "\n");
    }
}

echo "\nDone. Created: {$created}, Skipped: {$skipped}, Errors: {$errors}\n";
