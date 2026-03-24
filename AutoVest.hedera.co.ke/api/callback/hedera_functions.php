<?php

// // During testing
error_reporting(E_ALL); // Report all types of errors
ini_set('display_errors', 1); // Display errors in the browser
ini_set('display_startup_errors', 1); // Display startup errors


// AutoVest FUNCTIONS
//-------------------------------------------------------
function custom_AutoVest_text_whatsapp($whatsapp_number, $text) {

    $text = limitStringLength($text);

    $url = 'https://graph.facebook.com/v18.0/887649351092468/messages';

    $access_token = getenv('WHATSAPP_ACCESS_TOKEN');

    // Request headers
    $headers = [
        "Authorization: Bearer {$access_token}",
        "Content-Type: application/json"
    ];

    $data = [
        "messaging_product" => "whatsapp",
        "recipient_type" => "individual",
        "to" =>  $whatsapp_number,
        "type" => "text",
        "text" => [ 
            "preview_url" => false,
            "body" => $text
        ]
    ];

    // Initialize cURL session
    $ch = curl_init();

    // Set cURL options
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    // Execute cURL session and capture the response
    $response = curl_exec($ch);

    //get the status code
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    // curl_error($ch)


    // echo $response;
    // echo $status;
    echo curl_error($ch);

    if ($status === 200) {
        //incase of an error response from saf
        if (strpos($response, "error") !== false) {
            // there was an error
            $errors = "An error occured";
            return false;
        } else {
            $success = "Message sent successfully";
            // echo $response;
            return true;
        }

    } else {
        // error
        // $errors = "An error occured";

        return false;
    }
}



function build_prompt_with_history_AutoVest(string $wa_id, string $user_prompt): string {
    global $db;

    // Step 1: Get the last 6 messages (most recent first)
    $sql = "
        SELECT query, reply 
        FROM AutoVest_prompt_history 
        WHERE wa_id = ? 
        ORDER BY id DESC 
        LIMIT 6
    ";

    $stmt = $db->prepare($sql);
    $stmt->bind_param("s", $wa_id);
    $stmt->execute();
    $result = $stmt->get_result();

    // Step 2: Store them in an array so we can reverse the order later
    $historyRows = [];
    while ($row = $result->fetch_assoc()) {
        $historyRows[] = $row;
    }

    // Step 3: Reverse order so that the oldest appears first
    $historyRows = array_reverse($historyRows);

    // Step 4: Build the conversation context in chronological order
    $history = "";
    foreach ($historyRows as $row) {
        $query = trim($row['query']);
        $reply = trim($row['reply']);

        if ($query !== "") {
            $history .= "User: {$query}\n";
        }
        if ($reply !== "") {
            $history .= "Assistant: {$reply}\n";
        }
    }

    // Step 5: Add the current user message at the end
    $history .= "User: {$user_prompt}\n";

    return $history;
}



function build_prompt_with_history_0_AutoVest(string $wa_id, string $user_prompt): string {
    global $db;

    $stmt = $db->prepare("SELECT query, reply FROM AutoVest_prompt_history WHERE wa_id = ? ORDER BY id DESC LIMIT 6");
    $stmt->bind_param("s", $wa_id);
    $stmt->execute();

    $result = $stmt->get_result();
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    $rows = array_reverse($rows); // chronological order

    $history = "";

    foreach ($rows as $row) {
        $query = trim($row['query']);
        $reply = trim($row['reply']);

        if ($query !== "") {
            $history .= "User: {$query}\n";
        }
        if ($reply !== "") {
            $history .= "Assistant: {$reply}\n";
        }
    }

    $history .= "User: {$user_prompt}\n";
    return $history;
}


/**
 * getSystemStatsSummary_AutoVest_Hedera()
 * - Uses CoinGecko for HBAR/KES + HBAR/USD (cached 120s)
 * - Summarizes latest NSE tick snapshot (counts, traded vs no_trades)
 * - Adds market breadth, session totals, ranked lists (turnover/volume/gainers/losers)
 * - Emits a machine-readable JSON bundle + compact TSV for AI reasoning
 * - Shows the 3 most recent NSE announcements for today
 * - Nairobi local time
 *
 * Requires: .env with DB_HOST, DB_NAME, DB_USER, DB_PASS (read via getenv)
 */

function getSystemStatsSummary_AutoVest_Hedera($NSE_data=false): string {
    $cacheFile     = __DIR__ . '/AutoVest_rate_cache_hbar.txt';
    $cacheDuration = 120; // seconds
    $timezone      = new DateTimeZone('Africa/Nairobi');
    $now           = new DateTime('now', $timezone);
    $currentTime   = $now->format('Y-m-d H:i');

    // ---------- RATES ----------
    $hbarToKes = null;
    $hbarToUsd = null;
    $usdtToHbar = null;  // 1 USDT -> HBAR
    $usdtToKes  = null;  // 1 USDT -> KES
    $updatedTime = 'Unavailable';

    // Load from cache if fresh
    $cacheValid = false;
    if (is_file($cacheFile)) {
        $raw = @file_get_contents($cacheFile);
        $c   = json_decode($raw, true);
        if (is_array($c) && isset($c['timestamp'], $c['hbarToKes'], $c['hbarToUsd'])) {
            if ((time() - (int)$c['timestamp']) < $cacheDuration) {
                $cacheValid = true;
                $hbarToKes  = (float)$c['hbarToKes'];
                $hbarToUsd  = (float)$c['hbarToUsd'];
                $usdtToHbar = ($hbarToUsd > 0) ? 1 / $hbarToUsd : null;
                $usdtToKes  = ($usdtToHbar !== null) ? $hbarToKes * $usdtToHbar : null;

                $u = (new DateTime('@' . $c['timestamp']))->setTimezone($timezone);
                $updatedTime = $u->format('Y-m-d H:i');
            }
        }
    }

    if (!$cacheValid) {
        // One combined call for USD + KES
        $url  = "https://api.coingecko.com/api/v3/simple/price?ids=hedera-hashgraph&vs_currencies=usd,kes";
        $resp = @file_get_contents($url);
        $j    = json_decode($resp, true);
        $hbarToKes = $j['hedera-hashgraph']['kes'] ?? null;
        $hbarToUsd = $j['hedera-hashgraph']['usd'] ?? null;

        if (is_numeric($hbarToKes) && is_numeric($hbarToUsd) && $hbarToUsd > 0) {
            $usdtToHbar = 1 / $hbarToUsd;
            $usdtToKes  = $hbarToKes * $usdtToHbar;

            $ts = time();
            $updatedTime = (new DateTime('@' . $ts))->setTimezone($timezone)->format('Y-m-d H:i');
            @file_put_contents($cacheFile, json_encode([
                'timestamp' => $ts,
                'hbarToKes' => $hbarToKes,
                'hbarToUsd' => $hbarToUsd,
            ], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
        }
    }

    // Fallbacks (only used if CoinGecko is down)
    if (!is_numeric($hbarToKes)) $hbarToKes = 9.00;   // conservative placeholder
    if (!is_numeric($hbarToUsd) || $hbarToUsd <= 0) $hbarToUsd = 0.07;
    $usdtToHbar = 1 / $hbarToUsd;
    $usdtToKes  = $hbarToKes * $usdtToHbar;

    // ---------- DB: NSE ----------
    $hasDB   = false;
    $pdo     = null;

    $tickSummary = [
        'snapshot_dt'   => null,
        'asof_date'     => null,
        'tickers'       => 0,
        'traded'        => 0,
        'no_trades'     => 0,
        'market_status' => 'unknown',
        'market_note'   => ''
    ];
    $annLines = [];   // latest announcements
    $stockLines = []; // concise prices section (top by turnover)
    $TOP_N = 15;      // top lists in human section; JSON will carry all rows

    // Database config from env only
    $dbHost = $db_host = getenv('DB_HOST') ?: 'localhost';
    $dbUser = $db_user = getenv('DB_USER') ?: 'root';
    $dbPass = $db_pass = getenv('DB_PASS') ?: '';
    $dbName = $db_name = getenv('DB_NAME') ?: 'hedera_ai';

    if (!empty($dbHost) && !empty($dbName) && !empty($dbUser) && !empty($dbPass)) {
        try {
            $dsn = "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4";
            $pdo = new PDO($dsn, $dbUser, $dbPass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $hasDB = true;
        } catch (Throwable $e) {
            $hasDB = false;
        }
    }

    $latestTicks = [];
    $aiBundleJson = '{}';
    $tsvPreview = '';
    $sessionTotalsTurnover = 0.0;
    $sessionTotalsVolume = 0.0;
    $breadthAdv = 0; $breadthDec = 0; $breadthUnc = 0;

    if ($hasDB && $NSE_data) {
        $hasTicks = tableExists($pdo, 'nse_ticks');
        $hasAnns  = tableExists($pdo, 'nse_announcements');

        if ($hasTicks) {
            // Get latest trading date and snapshot
            $asof = $pdo->query("SELECT MAX(asof_date) AS d FROM nse_ticks")->fetch()['d'] ?? null;
            if ($asof) {
                $tickSummary['asof_date'] = $asof;
                $st = $pdo->prepare("SELECT MAX(snapshot_dt) AS s FROM nse_ticks WHERE asof_date = :d");
                $st->execute([':d' => $asof]);
                $snap = $st->fetch()['s'] ?? null;
                $tickSummary['snapshot_dt'] = $snap;

                // Pick latest row per ticker for that date
                if (supportsWindows($pdo)) {
                    $sql = "
                        WITH ranked AS (
                          SELECT
                            id, ticker, asof_date, time_str, prev_close, latest, change_abs, change_pct,
                            high, low, volume, vwap, deals, turnover, foreign_pct, status, snapshot_dt,
                            ROW_NUMBER() OVER (
                              PARTITION BY ticker 
                              ORDER BY (time_str='00:00:00'), time_str DESC, snapshot_dt DESC
                            ) AS rn
                          FROM nse_ticks
                          WHERE asof_date = :d
                        )
                        SELECT *
                        FROM ranked
                        WHERE rn = 1
                    ";
                    $st2 = $pdo->prepare($sql);
                    $st2->execute([':d' => $asof]);
                    $latestTicks = $st2->fetchAll();
                } else {
                    // MySQL 5.7 fallback
                    $sql = "
                        SELECT t.*
                        FROM nse_ticks t
                        INNER JOIN (
                          SELECT ticker, 
                                 MAX(CONCAT(
                                     CASE WHEN time_str='00:00:00' THEN 0 ELSE 1 END, '_',
                                     time_str, '_', snapshot_dt
                                 )) AS rk
                          FROM nse_ticks
                          WHERE asof_date = :d
                          GROUP BY ticker
                        ) r
                        ON t.ticker = r.ticker
                       AND CONCAT(
                            CASE WHEN t.time_str='00:00:00' THEN 0 ELSE 1 END, '_',
                            t.time_str, '_', t.snapshot_dt
                       ) = r.rk
                        WHERE t.asof_date = :d
                    ";
                    $st2 = $pdo->prepare($sql);
                    $st2->execute([':d' => $asof]);
                    $latestTicks = $st2->fetchAll();
                }

                // Keep valid NSE tickers only
                $latestTicks = array_values(array_filter($latestTicks, fn($r) =>
                    isset($r['ticker']) && preg_match('/^[A-Z]{2,6}$/', $r['ticker'])
                ));

                // Counts and market status
                $tickSummary['tickers'] = count($latestTicks);
                if ($latestTicks) {
                    $traded = 0; $noTrades = 0;
                    foreach ($latestTicks as $r) {
                        $hasPrice = ($r['latest'] ?? null) !== null
                                    || ($r['high'] ?? null) !== null
                                    || ($r['low'] ?? null) !== null
                                    || ($r['vwap'] ?? null) !== null;
                        if ($hasPrice && (($r['status'] ?? 'traded') === 'traded')) $traded++; else $noTrades++;
                    }
                    $tickSummary['traded']    = $traded;
                    $tickSummary['no_trades'] = $noTrades;

                    $times  = array_values(array_filter(array_map(fn($r) => $r['time_str'] ?? null, $latestTicks)));
                    $unique = count(array_unique($times));
                    if ($unique <= 2) {
                        $tickSummary['market_status'] = 'closed';
                        $tickSummary['market_note']   = 'static times';
                    } else {
                        $tickSummary['market_status'] = 'open';
                        $tickSummary['market_note']   = 'varying times';
                    }
                } else {
                    $tickSummary['market_status'] = 'closed';
                    $tickSummary['market_note']   = 'no ticks';
                }

                // ---------- Normalize rows and compute breadth + totals ----------
                $norm = [];
                foreach ($latestTicks as $r) {
                    $row = [
                        'ticker'      => (string)($r['ticker'] ?? ''),
                        'time_str'    => (string)($r['time_str'] ?? ''),
                        'prev_close'  => is_numeric($r['prev_close'] ?? null) ? (float)$r['prev_close'] : null,
                        'latest'      => is_numeric($r['latest'] ?? null) ? (float)$r['latest'] : null,
                        'change_abs'  => is_numeric($r['change_abs'] ?? null) ? (float)$r['change_abs'] : null,
                        'change_pct'  => is_numeric($r['change_pct'] ?? null) ? (float)$r['change_pct'] : null,
                        'high'        => is_numeric($r['high'] ?? null) ? (float)$r['high'] : null,
                        'low'         => is_numeric($r['low'] ?? null) ? (float)$r['low'] : null,
                        'volume'      => is_numeric($r['volume'] ?? null) ? (float)$r['volume'] : null,
                        'vwap'        => is_numeric($r['vwap'] ?? null) ? (float)$r['vwap'] : null,
                        'deals'       => is_numeric($r['deals'] ?? null) ? (int)$r['deals'] : null,
                        'turnover'    => is_numeric($r['turnover'] ?? null) ? (float)$r['turnover'] : null,
                        'foreign_pct' => is_numeric($r['foreign_pct'] ?? null) ? (float)$r['foreign_pct'] : null,
                        'status'      => (string)($r['status'] ?? 'traded'),
                        'snapshot_dt' => (string)($r['snapshot_dt'] ?? ''),
                        'asof_date'   => (string)($r['asof_date'] ?? ''),
                    ];

                    if ($row['change_abs'] === null && $row['latest'] !== null && $row['prev_close'] !== null) {
                        $row['change_abs'] = $row['latest'] - $row['prev_close'];
                    }
                    if ($row['change_pct'] === null && $row['latest'] !== null && $row['prev_close'] && $row['prev_close'] > 0) {
                        $row['change_pct'] = ($row['latest'] - $row['prev_close']) / $row['prev_close'] * 100.0;
                    }

                    if ($row['turnover'] !== null) $sessionTotalsTurnover += $row['turnover'];
                    if ($row['volume']   !== null) $sessionTotalsVolume   += $row['volume'];

                    if ($row['change_abs'] !== null) {
                        if ($row['change_abs'] > 0) $breadthAdv++;
                        elseif ($row['change_abs'] < 0) $breadthDec++;
                        else $breadthUnc++;
                    }

                    $norm[] = $row;
                }

                // ---------- Rankings ----------
                $byTurnover = $norm;
                usort($byTurnover, fn($a,$b) => (float)($b['turnover'] ?? 0) <=> (float)($a['turnover'] ?? 0));

                $byVolume = $norm;
                usort($byVolume, fn($a,$b) => (float)($b['volume'] ?? 0)   <=> (float)($a['volume'] ?? 0));

                $gainers = array_values(array_filter($norm, fn($x) => is_numeric($x['change_pct'] ?? null)));
                usort($gainers, fn($a,$b) => (float)($b['change_pct']) <=> (float)($a['change_pct']));

                $losers = $gainers;
                usort($losers, fn($a,$b) => (float)($a['change_pct']) <=> (float)($b['change_pct']));

                // ---------- Human concise price lines: top by turnover ----------
                $stockLines = [];
                foreach (array_slice($byTurnover, 0, $TOP_N) as $r) {
                    $tkr   = $r['ticker'];
                    $lt    = $r['latest'];
                    $prev  = $r['prev_close'];
                    $chgA  = $r['change_abs'];
                    $chgP  = $r['change_pct'];
                    $tt    = $r['time_str'] ?? '';
                    $stat  = $r['status'] ?? 'traded';

                    $price = $lt !== null  ? number_format($lt, 2)   : 'n/a';
                    $prevS = $prev !== null? number_format($prev, 2) : 'n/a';
                    $chgAs = $chgA !== null? sprintf('%+.2f', $chgA) : 'n/a';
                    $chgPs = $chgP !== null? sprintf('%+.2f%%', $chgP): 'n/a';

                    $stockLines[] = "{$tkr} {$price} (prev {$prevS}) {$chgAs} {$chgPs} • {$stat}" . ($tt ? " • {$tt}" : "");
                }

                // ---------- Compact TSV preview (all rows) ----------
                $headers = ['ticker','latest','prev_close','change_abs','change_pct','volume','turnover','vwap','deals','time_str','status'];
                $tsvLines = [];
                $tsvLines[] = implode("\t", $headers);
                foreach ($norm as $row) {
                    $tsvLines[] = implode("\t", [
                        $row['ticker'],
                        $row['latest']      !== null ? $row['latest']      : '',
                        $row['prev_close']  !== null ? $row['prev_close']  : '',
                        $row['change_abs']  !== null ? $row['change_abs']  : '',
                        $row['change_pct']  !== null ? $row['change_pct']  : '',
                        $row['volume']      !== null ? $row['volume']      : '',
                        $row['turnover']    !== null ? $row['turnover']    : '',
                        $row['vwap']        !== null ? $row['vwap']        : '',
                        $row['deals']       !== null ? $row['deals']       : '',
                        $row['time_str'],
                        $row['status'],
                    ]);
                }
                $tsvPreview = implode("\n", $tsvLines);

                // ---------- Machine-readable JSON bundle ----------
                $pick = function(array $arr, int $n) {
                    return array_map(function($r) {
                        return [
                            'ticker'      => $r['ticker'],
                            'latest'      => $r['latest'],
                            'prev_close'  => $r['prev_close'],
                            'change_abs'  => $r['change_abs'],
                            'change_pct'  => $r['change_pct'],
                            'volume'      => $r['volume'],
                            'turnover'    => $r['turnover'],
                            'vwap'        => $r['vwap'],
                            'deals'       => $r['deals'],
                            'time_str'    => $r['time_str'],
                            'status'      => $r['status'],
                        ];
                    }, array_slice($arr, 0, $n));
                };

                $aiBundle = [
                    'nse_snapshot' => [
                        'asof_date'     => $tickSummary['asof_date'] ?? null,
                        'snapshot_dt'   => $tickSummary['snapshot_dt'] ?? null,
                        'market_status' => $tickSummary['market_status'] ?? 'unknown',
                        'market_note'   => $tickSummary['market_note']   ?? '',
                        'counts'        => [
                            'tickers'   => $tickSummary['tickers']   ?? count($norm),
                            'traded'    => $tickSummary['traded']    ?? null,
                            'no_trades' => $tickSummary['no_trades'] ?? null,
                            'advancers' => $breadthAdv,
                            'decliners' => $breadthDec,
                            'unchanged' => $breadthUnc,
                        ],
                        'totals'        => [
                            'turnover' => $sessionTotalsTurnover,
                            'volume'   => $sessionTotalsVolume,
                        ],
                        'rankings'      => [
                            'top_by_turnover' => $pick($byTurnover, 50),
                            'top_by_volume'   => $pick($byVolume,   50),
                            'top_gainers'     => $pick($gainers,    50),
                            'top_losers'      => $pick($losers,     50),
                        ],
                        'all_rows'      => $norm,   // include full set for deep analysis
                        'tsv'           => $tsvPreview,
                    ],
                    'rates' => [
                        'hbar_kes' => $hbarToKes,
                        'hbar_usd' => $hbarToUsd,
                        'usdt_hbar'=> $usdtToHbar,
                        'usdt_kes' => $usdtToKes,
                        'hksh_note'=> '1 HKSH = 1 KES',
                    ],
                    'time_info' => [
                        'now_nairobi'    => $currentTime,
                        'rates_updated'  => $updatedTime,
                        'timezone'       => 'Africa/Nairobi',
                        'cache_seconds'  => $cacheDuration,
                    ],
                ];
                $aiBundleJson = json_encode($aiBundle, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
        }

        if ($hasAnns && !empty($tickSummary['asof_date'])) {
            $sa = $pdo->prepare("SELECT time_str, type, message FROM nse_announcements WHERE asof_date = :d ORDER BY time_str DESC LIMIT 3");
            $sa->execute([':d' => $tickSummary['asof_date']]);
            $anns = $sa->fetchAll();
            foreach ($anns as $a) {
                $t = trim($a['time_str'] ?? '');
                $type = trim($a['type'] ?? '');
                $prefix = $type ? ucfirst($type) . " Announcement: " : "";
                $line = ($t ? $t . ' – ' : '') . $prefix . ($a['message'] ?? '');
                $annLines[] = $line;
            }
        }
    }

    // ---------- Compose message (human + machine blocks) ----------
    $lines = [];
    $lines[] = "Time Info";
    $lines[] = "Current Time: {$currentTime} (Africa/Nairobi)";
    $lines[] = "Rates Last Updated: {$updatedTime} (cached, auto-refresh 2 min)";
    $lines[] = "";
    $lines[] = "HBAR & System Rates";
    $lines[] = "1 USDT ≈ " . number_format($usdtToKes, 2) . " KES";
    $lines[] = "1 HBAR ≈ " . number_format($hbarToKes, 4) . " KES";
    $lines[] = "1 USDT ≈ " . number_format($usdtToHbar, 6) . " HBAR";
    $lines[] = "HKSH Conversion:";
    $lines[] = "1 HBAR ≈ " . number_format($hbarToKes, 4) . " HKSH";
    $lines[] = "1 HKSH = 1 KES";
    $lines[] = "Minimum M-Pesa Top-Up: KES 1";
    $lines[] = "";

    if ($tickSummary['asof_date'] && $NSE_data) {
        $lines[] = "NSE Snapshot";
        $lines[] = "Date: {$tickSummary['asof_date']}";
        $lines[] = "Snapshot: " . ($tickSummary['snapshot_dt'] ?? 'n/a');
        $lines[] = "Market: {$tickSummary['market_status']}" . ($tickSummary['market_note'] ? " ({$tickSummary['market_note']})" : "");
        $lines[] = "Tickers: {$tickSummary['tickers']} • Traded: {$tickSummary['traded']} • No trades: {$tickSummary['no_trades']}";
        $lines[] = "Market Breadth: Adv " . number_format($breadthAdv) . " / Dec " . number_format($breadthDec) . " / Unch " . number_format($breadthUnc);
        $lines[] = "Session Totals: Turnover " . number_format($sessionTotalsTurnover, 2) . " • Volume " . number_format($sessionTotalsVolume, 0);
        $lines[] = "";

        if (!empty($stockLines)) {
            $lines[] = "Key NSE Prices (top by turnover)";
            foreach ($stockLines as $sl) $lines[] = $sl;
            $lines[] = "";
        }

        if ($annLines) {
            $lines[] = "Latest Announcements:";
            foreach ($annLines as $l) $lines[] = "• " . $l;
            $lines[] = "";
        } else {
            $lines[] = "Latest Announcements: None";
            $lines[] = "";
        }

        // Machine-readable data blocks for AI
        $lines[] = "Machine Data (JSON):";
        $lines[] = "```json";
        $lines[] = $aiBundleJson ?: "{}";
        $lines[] = "```";
        $lines[] = "";

        // Optional: include TSV (useful for LLM quick scanning)
        $lines[] = "Machine Data (TSV):";
        $lines[] = "```text";
        $lines[] = $tsvPreview ?: "ticker\tlatest\tprev_close\tchange_abs\tchange_pct\tvolume\tturnover\tvwap\tdeals\ttime_str\tstatus";
        $lines[] = "```";
        $lines[] = "";
    } else {
        $lines[] = "NSE Snapshot";
        $lines[] = "No NSE data available.";
        $lines[] = "";
    }

    return implode("\n", $lines);
}


/* ---------- helpers ---------- */
function tableExists(PDO $pdo, string $name): bool {
    try { $pdo->query("SELECT 1 FROM `$name` LIMIT 1"); return true; }
    catch (Throwable $e) { return false; }
}
function supportsWindows(PDO $pdo): bool {
    try { $pdo->query("SELECT 1, ROW_NUMBER() OVER() AS rn"); return true; }
    catch (Throwable $e) { return false; }
}


function MPESA_TOPUP_init_AutoVest_whatsapp($whatsapp_number) {
  
  $url = 'https://graph.facebook.com/v18.0/887649351092468/messages';

    // Headers for the request
    $access_token = getenv('WHATSAPP_ACCESS_TOKEN');

    // Request headers
    $headers = [
        "Authorization: Bearer {$access_token}",
        "Content-Type: application/json"
    ];

  // Prepare data to send via POST
  $data = [
    'messaging_product' => 'whatsapp',
    'recipient_type' => 'individual',
    'to' => $whatsapp_number,
    'type' => 'template',
    'template' => [
        // 'name' => "jksh_mpesa_topup", 
        'name' => "mpesa_topup_hbar_hksh", 
        'language' => [
            'code' => "en"
        ],
        'components' => [
            [
                'type' => 'button',
                'sub_type' => 'flow',
                'index' => '0'
            ]
        ]
    ]
  ];


  // Initialize cURL session
  $ch = curl_init();

  // Set cURL options
  curl_setopt($ch, CURLOPT_URL, $url);
  curl_setopt($ch, CURLOPT_POST, 1);
  curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
  curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

  // Execute cURL session and capture the response
  $response = curl_exec($ch);

  //get the status code
  $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  // curl_error($ch)


//   echo $response;
//   echo $status;
  curl_error($ch);

  if ($status === 200) {
      //incase of an error response from saf
      if (strpos($response, "error") !== false) {
          //there was an error
          // $errors = "An error occured";
          return false;
      } else {
          // $success = "Message sent successfully";
          // echo $response;
          return true;
      }

  } else {
      // error
      // $errors = "An error occured";

      return false;
  }
}


function CARD_TOPUP_init_AutoVest_whatsapp($whatsapp_number) {
  
  $url = 'https://graph.facebook.com/v18.0/887649351092468/messages';

  $access_token = getenv('WHATSAPP_ACCESS_TOKEN');

    // Request headers
    $headers = [
        "Authorization: Bearer {$access_token}",
        "Content-Type: application/json"
    ];

  // Prepare data to send via POST
  $data = [
    'messaging_product' => 'whatsapp',
    'recipient_type' => 'individual',
    'to' => $whatsapp_number,
    'type' => 'template',
    'template' => [
        // 'name' => "jksh_mpesa_topup", 
        'name' => "card_topup_hksh_hbar_autovest", 
        'language' => [
            'code' => "en"
        ],
        'components' => [
            [
                'type' => 'button',
                'sub_type' => 'flow',
                'index' => '0'
            ]
        ]
    ]
  ];


  // Initialize cURL session
  $ch = curl_init();

  // Set cURL options
  curl_setopt($ch, CURLOPT_URL, $url);
  curl_setopt($ch, CURLOPT_POST, 1);
  curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
  curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

  // Execute cURL session and capture the response
  $response = curl_exec($ch);

  //get the status code
  $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  // curl_error($ch)


//   echo $response;
//   echo $status;
  curl_error($ch);

  if ($status === 200) {
      //incase of an error response from saf
      if (strpos($response, "error") !== false) {
          //there was an error
          // $errors = "An error occured";
          return false;
      } else {
          // $success = "Message sent successfully";
          // echo $response;
          return true;
      }

  } else {
      // error
      // $errors = "An error occured";

      return false;
  }
}



function kyc_init_AutoVest_whatsapp($whatsapp_number) {
  
  $url = 'https://graph.facebook.com/v18.0/887649351092468/messages';

  $access_token = getenv('WHATSAPP_ACCESS_TOKEN');

    // Request headers
    $headers = [
        "Authorization: Bearer {$access_token}",
        "Content-Type: application/json"
    ];

    function normalizePhoneKYC(string $phone): string {
        return preg_replace('/\D+/', '', $phone) ?? '';
    }

    function kyc_insert_sent(mysqli $db, string $flowToken, string $phoneDigits, string $templateName): void {
        $sql = "INSERT INTO kyc_submissions (flow_token, phone_digits, status, current_screen, last_action)
                VALUES (?, ?, 'sent', 'TEMPLATE_SENT', 'SEND')
                ON DUPLICATE KEY UPDATE
                phone_digits=VALUES(phone_digits),
                status='sent',
                current_screen='TEMPLATE_SENT',
                last_action='SEND',
                updated_at=NOW()";

        $stmt = $db->prepare($sql);
        if (!$stmt) throw new RuntimeException('DB prepare failed: '.$db->error);
        $stmt->bind_param("ss", $flowToken, $phoneDigits);
        $stmt->execute();
        $stmt->close();
    }

    $normalizedPhone = normalizePhoneKYC($whatsapp_number);

    // Build a token that includes phone as prefix + strong entropy (collision-proof in practice)
    // Format: <digits>_<sha256>
    $entropy = bin2hex(random_bytes(16));
    $time    = microtime(true);

    $hashPart = hash('sha256', $normalizedPhone . '|' . $time . '|' . $entropy);
    $submission_id = $normalizedPhone . '_' . $hashPart; 

    try {
        global $db;
        kyc_insert_sent($db, $submission_id, $normalizedPhone, 'autovest_kyc_flow_v1');
        $db->close();
    } catch (Throwable $e) {
        // If DB insert fails, you can choose to stop sending or still send.
        // For KYC, I recommend failing hard so you never lose the mapping.
        return false;
    }

    $data = [
    'messaging_product' => 'whatsapp',
    'recipient_type'    => 'individual',
    'to'                => $whatsapp_number,
    'type'              => 'template',
    'template'          => [
        'name'     => 'autovest_kyc_flow_v1',
        'language' => ['code' => 'en'],
        'components' => [
        [
            'type'     => 'button',
            'sub_type' => 'flow',
            'index'    => '0',
            'parameters' => [
            [
                'type'   => 'action',
                'action' => [
                // ✅ your unique identifier for THIS submission
                'flow_token' => $submission_id,
                ]
            ]
            ]
        ]
        ]
    ]
    ];



  // Initialize cURL session
  $ch = curl_init();

  // Set cURL options
  curl_setopt($ch, CURLOPT_URL, $url);
  curl_setopt($ch, CURLOPT_POST, 1);
  curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
  curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

  // Execute cURL session and capture the response
  $response = curl_exec($ch);

  //get the status code
  $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  // curl_error($ch)


//   echo $response;
//   echo $status;
  curl_error($ch);

  if ($status === 200) {
      //incase of an error response from saf
      if (strpos($response, "error") !== false) {
          //there was an error
          // $errors = "An error occured";
          return false;
      } else {
          // $success = "Message sent successfully";
          // echo $response;
          return true;
      }

  } else {
      // error
      // $errors = "An error occured";

      return false;
  }
}


/**
 * Hedera (HBAR) <-> KES with profit spread and security buffer.
 * - Live mid-rate from CoinGecko (KES per HBAR), cached.
 * - Profit margin and security buffer applied in basis points (1 bps = 0.01%).
 * - Side-aware quotes: "buy" (user buys HBAR from you) or "sell" (user sells HBAR to you).
 */

function cg_get_json(string $url, int $timeout = 8): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ]);
    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($resp === false) {
        throw new Exception("HTTP error: $err");
    }
    $json = json_decode($resp, true);
    if (!is_array($json) || $code >= 400) {
        throw new Exception("Bad API response ($code): $resp");
    }
    return $json;
}

/**
 * Returns KES per 1 HBAR (float), cached with a safe fallback.
 */
function getHbarToKesRateCached(string $cacheFile = 'hbar_rate_cache.json',
                                int $cacheDuration = 120,
                                ?float $fallbackKesPerHbar = null): float {
    $now = time();

    // Use fresh cache if available
    if (is_file($cacheFile)) {
        $cache = json_decode(@file_get_contents($cacheFile), true);
        if (is_array($cache) && isset($cache['rate'], $cache['timestamp'])) {
            if (($now - (int)$cache['timestamp']) < $cacheDuration) {
                return (float)$cache['rate'];
            }
        }
    }

    // Try live fetch
    try {
        $url = "https://api.coingecko.com/api/v3/simple/price?ids=hedera-hashgraph&vs_currencies=kes";
        $data = cg_get_json($url);
        if (!isset($data['hedera-hashgraph']['kes'])) {
            throw new Exception("Unexpected API structure");
        }
        $rate = (float)$data['hedera-hashgraph']['kes'];
        if ($rate <= 0) throw new Exception("Non-positive rate");
        @file_put_contents($cacheFile, json_encode(['rate'=>$rate,'timestamp'=>$now]));
        return $rate;
    } catch (Throwable $e) {
        // If API failed, try stale cache
        if (is_file($cacheFile)) {
            $cache = json_decode(@file_get_contents($cacheFile), true);
            if (is_array($cache) && isset($cache['rate'])) {
                return (float)$cache['rate'];
            }
        }
        // Last resort fallback if provided
        if ($fallbackKesPerHbar !== null && $fallbackKesPerHbar > 0) {
            return $fallbackKesPerHbar;
        }
        throw $e;
    }
}

/**
 * Compute a quoted rate with spread.
 * - $side: "buy" means user buys HBAR from you (you charge more KES per HBAR).
 *          "sell" means user sells HBAR to you (you pay fewer KES per HBAR).
 * - $profit_bps: your margin in basis points.
 * - $buffer_bps: extra cushion for volatility, outages, slippage.
 * - $cap_bps: optional guardrail to limit total spread.
 *
 * Returns KES per HBAR (float).
 */
function getQuotedRateKesPerHbar(string $side,
                                 int $profit_bps = 150,
                                 int $buffer_bps = 50,
                                 ?int $cap_bps = 500): float {
    $mid = getHbarToKesRateCached(); // KES per HBAR
    $bps = max(0, $profit_bps) + max(0, $buffer_bps);
    if ($cap_bps !== null) $bps = min($bps, max(0, $cap_bps));
    $mult = $bps / 10000.0; // convert bps to fraction

    // For "buy", increase price; for "sell", decrease price
    if (strtolower($side) === 'buy') {
        return $mid * (1.0 + $mult);
    } else {
        return $mid * (1.0 - $mult);
    }
}

/**
 * Convert KES -> HBAR using a side-aware quote.
 * If user gives you KES to buy HBAR, pass side="buy" so you apply the buy quote.
 */
function convertKesToHbar(float $kesAmount,
                          string $side = 'buy',
                          int $profit_bps = 150,
                          int $buffer_bps = 50,
                          ?int $cap_bps = 500): float {
    $rate = getQuotedRateKesPerHbar($side, $profit_bps, $buffer_bps, $cap_bps);
    if ($rate <= 0) throw new Exception("Invalid quoted rate");
    return round($kesAmount / $rate, 6);
}

/**
 * Convert HBAR -> KES using a side-aware quote.
 * If user sells HBAR to you for KES, pass side="sell".
 */
function convertHbarToKes(
    float $hbarAmount,
    string $side = 'sell',
    int $profit_bps = 150,
    int $buffer_bps = 50,
    ?int $cap_bps = 500
): float {
    $rate = (float) getQuotedRateKesPerHbar($side, $profit_bps, $buffer_bps, $cap_bps);
    if ($rate <= 0 || $hbarAmount <= 0) {
        return 0.0;
    }

    // Keep precision, round only to 6 decimals for stability
    return round($hbarAmount * $rate, 6);
}

/**
 * Convert HBAR -> HKSH via KES, with peg or live HKSH/KES if you have it.
 * By default assumes 1 HKSH = 1 KES.
 */
function convertHbarToHKSH(
    float $hbarAmount,
    float $hkshPerKes = 1.0,
    string $side = 'sell',
    int $profit_bps = 150,
    int $buffer_bps = 50,
    ?int $cap_bps = 500
): float {
    if ($hbarAmount <= 0) {
        return 0.0;
    }

    $kes = (float) convertHbarToKes($hbarAmount, $side, $profit_bps, $buffer_bps, $cap_bps);
    $hksh = $kes * $hkshPerKes;

    // Round to 6 decimals (HKSH precision)
    return round($hksh, 6);
}


//send algo-------------------------------------------------------------------------


function check_AutoVest_intent($user_request, $AI_responce) {
    $url = "https://api.openai.com/v1/chat/completions";
    $apiKey = getenv("OPENAI_API_KEY") ?: "";

    // Build the system prompt for Hedera AutoVest (HBAR + HKSH)
$system_prompt = 'You are an intent classifier for Hedera AutoVest on WhatsApp.
Based on the user’s message and the AI’s reply, output a single digit that represents the clear action the user wants.

CRITICAL RULES
- Ignore anything related to airtime (e.g., "Buy airtime 100 for 0712...", "Airtime done"). If it is airtime, output "0".
- Only classify real actions. If the user is just asking for rates, asking questions, or being vague without confirming an action, output "0".
- If the user says short confirmations that clearly refer to a recent swap prompt (e.g., "Convert", "Yes convert", "Swap it", "Convert the 5"), then treat it as a swap (4).

TOKENS AND RAILS (Hedera)
- Native token: HBAR
- Utility token: HKSH

RETURNS (single character only):
- "1" → Send HBAR from wallet to a wallet address or phone number. 
  Examples: "Send HBAR", "Transfer 5 HBAR", "Send to this number", "Send to 0.0.12345".

- "2" → Top up with M-Pesa or convert NEW KES into HBAR/HKSH. 
  Examples: "Top up now", "Add funds", "Fund my account", "Convert KES to HKSH", "Buy HBAR with KES".
  Do NOT classify as top-up if converting only internal balances (no fresh KES). 
  Example: HKSH→HBAR is NOT a top-up.

- "3" → Withdraw / cash out. 
  Examples: "Withdraw", "Cash out", "Send to M-Pesa", "Withdraw to my number".

- "4" → Swap HBAR ↔ HKSH when user initiates or clearly confirms. 
  Examples: "Swap HBAR to HKSH", "Convert 5 HBAR to HKSH", "Yes convert", "Convert that", "Swap it".
  Do NOT classify as swap if they only ask for prices or do math without asking to proceed.

- "5" → Buy or invest in stocks, shares, or securities listed on the NSE. 
  Trigger when the user mentions buying, investing, or purchasing company names, stocks, or NSE tickers — even with slight spelling errors (e.g., "shared" instead of "shares").
  Examples: 
  "Buy Safaricom shares", 
  "Buy 500 KCB stocks", 
  "Invest in NSE", 
  "Purchase Equity", 
  "Buy BAT Kenya", 
  "Invest 2000 in stocks", 
  "Buy 10 shared from KCB".

- "6" → AutoVest AI or subscription-related investment requests.
  Trigger when the user refers to automatic, AI-managed, or recurring investments (AutoVest).
  Examples:
  "Subscribe to AutoVest",
  "Start automatic investing",
  "Enable recurring investment",
  "Set up weekly investment",
  "AI invest for me",
  "Auto invest 500 every month".

- "7" → Feedback, issues, complaints, or suggestions.
  Trigger when the user expresses feedback or intent to report something.
  Examples:
  "I have feedback",
  "I want to report a bug",
  "Something is not working",
  "I want to suggest something",
  "I have a complaint",
  "I found an issue",
  "I want to give feedback".

- "8" → Card-based top-up or deposit using Visa, Mastercard, or other cards.
  Trigger when the user explicitly wants to fund their account using a debit or credit card.
  Examples:
  "Top up with card",
  "Use my Visa",
  "Fund using Mastercard",
  "Pay with card",
  "I want to deposit by card",
  "Card top-up",
  "Let me use my debit card".

- "9" → Identity verification (KYC).  
  Trigger when the user wants to verify identity, complete KYC, or unlock full account features.  
  Examples:  
  "Verify my account",  
  "Start KYC",  
  "I want to verify",  
  "Complete identity verification",  
  "Unlock withdrawals",  
  "Do KYC",  
  "Verify identity".

- "0" → Anything else, including airtime, questions, greetings, rates-only, or unclear intent.

Keep it strict. Output only one of: "1", "2", "3", "4", "5", "6", "7", "8", "9" or "0".';




    // Compose the user prompt with both signals
    $user_prompt = "User message: " . $user_request . "\nAI response: " . $AI_responce;

    // Prepare request
    $data = [
        "model" => "gpt-5-mini",
        "messages" => [
            [ "role" => "system", "content" => $system_prompt ],
            [ "role" => "user",   "content" => $user_prompt ]
        ]
    ];

    // Call OpenAI
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Content-Type: application/json",
        "Authorization: Bearer $apiKey"
    ]);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

    $response = curl_exec($ch);
    $curl_err = curl_error($ch);
    curl_close($ch);

    // Fail-safe: return "0" on transport error
    if ($curl_err || !$response) {
        return "0";
    }

    $responseArray = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return "0";
    }

    // Defensive parsing
    $content = "";
    if (isset($responseArray["choices"][0]["message"]["content"])) {
        $content = $responseArray["choices"][0]["message"]["content"];
    } elseif (isset($responseArray["choices"][0]["text"])) {
        $content = $responseArray["choices"][0]["text"];
    } else {
        return "0";
    }

    // Normalize and strictly validate digit
    $content = strtolower(trim($content));
    // Keep only first char in case model adds whitespace or notes
    $digit = substr($content, 0, 1);

    // Enforce allowed set
    $allowed = ["0","1","2","3","4","5","6","7","8","9"];
    if (!in_array($digit, $allowed, true)) {
        return "0";
    }

    return $digit;
}



function Hbar_SEND_init_HKSH_whatsapp($whatsapp_number) {
  
  $url = 'https://graph.facebook.com/v18.0/887649351092468/messages';

  $access_token = getenv('WHATSAPP_ACCESS_TOKEN');

    // Request headers
    $headers = [
        "Authorization: Bearer {$access_token}",
        "Content-Type: application/json"
    ];

  // Prepare data to send via POST
  $data = [
    'messaging_product' => 'whatsapp',
    'recipient_type' => 'individual',
    'to' => $whatsapp_number,
    'type' => 'template',
    'template' => [
        'name' => "hksh_hbar_send",
        'language' => [
            'code' => "en"
        ],
        'components' => [
            [
                'type' => 'button',
                'sub_type' => 'flow',
                'index' => '0'
            ]
        ]
    ]
  ];


  // Initialize cURL session
  $ch = curl_init();

  // Set cURL options
  curl_setopt($ch, CURLOPT_URL, $url);
  curl_setopt($ch, CURLOPT_POST, 1);
  curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
  curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

  // Execute cURL session and capture the response
  $response = curl_exec($ch);

  //get the status code
  $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  // curl_error($ch)


//   echo $response;
//   echo $status;
  curl_error($ch);

  if ($status === 200) {
      //incase of an error response from saf
      if (strpos($response, "error") !== false) {
          //there was an error
          // $errors = "An error occured";
          return false;
      } else {
          // $success = "Message sent successfully";
          // echo $response;
          return true;
      }

  } else {
      // error
      // $errors = "An error occured";

      return false;
  }
}



function HKSH_WITHDRAW_MPESA_init_AutoVest_whatsapp($whatsapp_number) {
  
  $url = 'https://graph.facebook.com/v18.0/887649351092468/messages';

  $access_token = getenv('WHATSAPP_ACCESS_TOKEN');

    // Request headers
    $headers = [
        "Authorization: Bearer {$access_token}",
        "Content-Type: application/json"
    ];

  // Prepare data to send via POST
  $data = [
    'messaging_product' => 'whatsapp',
    'recipient_type' => 'individual',
    'to' => $whatsapp_number,
    'type' => 'template',
    'template' => [
        'name' => "hksh_withdraw_to_mpesa",
        'language' => [
            'code' => "en"
        ],
        'components' => [
            [
                'type' => 'button',
                'sub_type' => 'flow',
                'index' => '0'
            ]
        ]
    ]
  ];


  // Initialize cURL session
  $ch = curl_init();

  // Set cURL options
  curl_setopt($ch, CURLOPT_URL, $url);
  curl_setopt($ch, CURLOPT_POST, 1);
  curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
  curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

  // Execute cURL session and capture the response
  $response = curl_exec($ch);

  //get the status code
  $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  // curl_error($ch)


//   echo $response;
//   echo $status;
  curl_error($ch);

  if ($status === 200) {
      //incase of an error response from saf
      if (strpos($response, "error") !== false) {
          //there was an error
          // $errors = "An error occured";
          return false;
      } else {
          // $success = "Message sent successfully";
          // echo $response;
          return true;
      }

  } else {
      // error
      // $errors = "An error occured";

      return false;
  }
}

// JKSH WITHDRAW ADITIONAL FUNCTIONS----------------------------------------

// withdraw phone number, can be an international phone number
function validateRecipientInput_Hedera($input) {
    $input = trim($input);

    // Remove spaces, dashes, and parentheses
    $cleaned = preg_replace('/[\s\-\(\)]/', '', $input);

    // Check if it's a valid Algorand address
    if (preg_match('/^[A-Z2-7]{58}$/', $cleaned)) {
        return ['type' => 'wallet', 'value' => $cleaned];
    }

    // Ensure phone number starts with +
    if (strpos($cleaned, '+') !== 0 && preg_match('/^\d{9,15}$/', $cleaned)) {
        $cleaned = '+' . $cleaned;
    }

    // Check if it's a valid international phone number (E.164 format)
    if (preg_match('/^\+\d{9,15}$/', $cleaned)) {
        return ['type' => 'phone', 'value' => $cleaned];
    }

    return ['error' => 'Invalid input. Enter a valid 58-character Algorand wallet address or an international phone number in the format +1234567890.'];
}



function HKSH_HBAR_CONVERSION_init_whatsapp($whatsapp_number) {
  
  $url = 'https://graph.facebook.com/v18.0/887649351092468/messages';

  $access_token = getenv('WHATSAPP_ACCESS_TOKEN');

    // Request headers
    $headers = [
        "Authorization: Bearer {$access_token}",
        "Content-Type: application/json"
    ];

  // Prepare data to send via POST
  $data = [
    'messaging_product' => 'whatsapp',
    'recipient_type' => 'individual',
    'to' => $whatsapp_number,
    'type' => 'template',
    'template' => [
        'name' => "message_templates_hksh_hbar_convert_utility_da867_clone", 
        'language' => [
            'code' => "en"
        ],
        'components' => [
            [
                'type' => 'button',
                'sub_type' => 'flow',
                'index' => '0'
            ]
        ]
    ]
  ];


  // Initialize cURL session
  $ch = curl_init();

  // Set cURL options
  curl_setopt($ch, CURLOPT_URL, $url);
  curl_setopt($ch, CURLOPT_POST, 1);
  curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
  curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

  // Execute cURL session and capture the response
  $response = curl_exec($ch);

  //get the status code
  $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  // curl_error($ch)


  echo $response;
  echo $status;
  curl_error($ch);

  if ($status === 200) {
      //incase of an error response from saf
      if (strpos($response, "error") !== false) {
          //there was an error
          // $errors = "An error occured";
          return false;
      } else {
          // $success = "Message sent successfully";
          // echo $response;
          return true;
      }

  } else {
      // error
      // $errors = "An error occured";

      return false;
  }
}


function HKSH_HBAR_NSE_BUY_whatsapp($whatsapp_number) {
  
  $url = 'https://graph.facebook.com/v18.0/887649351092468/messages';

  $access_token = getenv('WHATSAPP_ACCESS_TOKEN');

    // Request headers
    $headers = [
        "Authorization: Bearer {$access_token}",
        "Content-Type: application/json"
    ];

  // Prepare data to send via POST
  $data = [
    'messaging_product' => 'whatsapp',
    'recipient_type' => 'individual',
    'to' => $whatsapp_number,
    'type' => 'template',
    'template' => [
        'name' => "stock_purchase_request", 
        'language' => [
            'code' => "en"
        ],
        'components' => [
            [
                'type' => 'button',
                'sub_type' => 'flow',
                'index' => '0'
            ]
        ]
    ]
  ];


  // Initialize cURL session
  $ch = curl_init();

  // Set cURL options
  curl_setopt($ch, CURLOPT_URL, $url);
  curl_setopt($ch, CURLOPT_POST, 1);
  curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
  curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

  // Execute cURL session and capture the response
  $response = curl_exec($ch);

  //get the status code
  $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  // curl_error($ch)


  echo $response;
  echo $status;
  curl_error($ch);

  if ($status === 200) {
      //incase of an error response from saf
      if (strpos($response, "error") !== false) {
          //there was an error
          // $errors = "An error occured";
          return false;
      } else {
          // $success = "Message sent successfully";
          // echo $response;
          return true;
      }

  } else {
      // error
      // $errors = "An error occured";

      return false;
  }
}


function HKSH_HBAR_NSE_AutoVest_AI_Subscribe($whatsapp_number) {
  
  $url = 'https://graph.facebook.com/v18.0/887649351092468/messages';

  $access_token = getenv('WHATSAPP_ACCESS_TOKEN');

    // Request headers
    $headers = [
        "Authorization: Bearer {$access_token}",
        "Content-Type: application/json"
    ];

  // Prepare data to send via POST
  $data = [
    'messaging_product' => 'whatsapp',
    'recipient_type' => 'individual',
    'to' => $whatsapp_number,
    'type' => 'template',
    'template' => [
        'name' => "autovest_ai_subscibe", 
        'language' => [
            'code' => "en"
        ],
        'components' => [
            [
                'type' => 'button',
                'sub_type' => 'flow',
                'index' => '0'
            ]
        ]
    ]
  ];


  // Initialize cURL session
  $ch = curl_init();

  // Set cURL options
  curl_setopt($ch, CURLOPT_URL, $url);
  curl_setopt($ch, CURLOPT_POST, 1);
  curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
  curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

  // Execute cURL session and capture the response
  $response = curl_exec($ch);

  //get the status code
  $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  // curl_error($ch)


  echo $response;
  echo $status;
  curl_error($ch);

  if ($status === 200) {
      //incase of an error response from saf
      if (strpos($response, "error") !== false) {
          //there was an error
          // $errors = "An error occured";
          return false;
      } else {
          // $success = "Message sent successfully";
          // echo $response;
          return true;
      }

  } else {
      // error
      // $errors = "An error occured";

      return false;
  }
}


function AutoVest_FeedbackFlow_Init($whatsapp_number) {
  
  $url = 'https://graph.facebook.com/v18.0/887649351092468/messages';

  $access_token = getenv('WHATSAPP_ACCESS_TOKEN');

    // Request headers
    $headers = [
        "Authorization: Bearer {$access_token}",
        "Content-Type: application/json"
    ];

  // Prepare data to send via POST
  $data = [
    'messaging_product' => 'whatsapp',
    'recipient_type' => 'individual',
    'to' => $whatsapp_number,
    'type' => 'template',
    'template' => [
        'name' => "autovest_feedback", 
        'language' => [
            'code' => "en"
        ],
        'components' => [
            [
                'type' => 'button',
                'sub_type' => 'flow',
                'index' => '0'
            ]
        ]
    ]
  ];


  // Initialize cURL session
  $ch = curl_init();

  // Set cURL options
  curl_setopt($ch, CURLOPT_URL, $url);
  curl_setopt($ch, CURLOPT_POST, 1);
  curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
  curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

  // Execute cURL session and capture the response
  $response = curl_exec($ch);

  //get the status code
  $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  // curl_error($ch)


  echo $response;
  echo $status;
  curl_error($ch);

  if ($status === 200) {
      //incase of an error response from saf
      if (strpos($response, "error") !== false) {
          //there was an error
          // $errors = "An error occured";
          return false;
      } else {
          // $success = "Message sent successfully";
          // echo $response;
          return true;
      }

  } else {
      // error
      // $errors = "An error occured";

      return false;
  }
}

//---------------------------------------------------------------------

// Tool definitions for OpenAI
$tools = [
    [
        'type' => 'function',
        'function' => [
            'name' => 'buy_airtime',
            'description' => 'Purchase airtime for a phone number. Extract phone number and amount from user messages like "buy 50 shillings airtime for 0712345678", "purchase 100 KES airtime for +254701234567", "I need 20 bob airtime for 254712345678", etc. Parse amounts from various formats including "shillings", "KES", "bob", or just numbers.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'phone_number' => [
                        'type' => 'string',
                        'description' => 'The phone number to buy airtime for (Kenyan format) - extract from user message'
                    ],
                    'amount' => [
                        'type' => 'number',
                        'description' => 'The amount of airtime to purchase in KES - extract and convert from user message (e.g., "50 shillings" = 50, "100 bob" = 100)'
                    ]
                ],
                'required' => ['phone_number', 'amount']
            ]
        ]
    ]
];

// Database connection function
function getDbConnection2() {
    global $db_host, $db_name, $db_user, $db_pass;
    
    try {
        $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    } catch (PDOException $e) {
        error_log("Database connection failed: " . $e->getMessage());
        return null;
    }
}

// Function to save conversation history
function saveConversationHistory_AutoVest($wa_id, $query, $reply) {
    $pdo = getDbConnection2();
    if (!$pdo) return false;
    
    try {
        $stmt = $pdo->prepare("INSERT INTO AutoVest_prompt_history (wa_id, query, reply) VALUES (?, ?, ?)");
        $stmt->execute([$wa_id, $query, $reply]);
        return true;
    } catch (PDOException $e) {
        error_log("Failed to save conversation history: " . $e->getMessage());
        return false;
    }
}

// Function to build prompt with conversation history
function build_prompt_with_history_3_AutoVest($wa_id, $current_prompt, $limit = 5) {
    $pdo = getDbConnection2();
    if (!$pdo) {
        return $current_prompt;
    }

    $limit = (int) $limit;

    try {
        $stmt = $pdo->prepare("SELECT query, reply FROM AutoVest_prompt_history WHERE wa_id = ? ORDER BY id DESC LIMIT $limit");
        $stmt->execute([$wa_id]);
        $history = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($history)) {
            return $current_prompt;
        }

        $history = array_reverse($history); // oldest first

        $context = "[Start of history: previous conversation]\n";
        foreach ($history as $entry) {
            $query = trim($entry['query']);
            $reply = trim($entry['reply']);

            if ($query !== "") {
                $context .= "User: {$query}\n";
            }
            if ($reply !== "") {
                $context .= "Assistant: {$reply}\n";
            }
        }
        $context .= "[End of history]\n\n";
        $context .= "User: {$current_prompt}\n";

        return $context;

    } catch (PDOException $e) {
        error_log("Failed to retrieve conversation history: " . $e->getMessage());
        return $current_prompt;
    }
}


// Function implementations
function buyAirtimeAutoVest($phone_number, $amount, $wa_id="") {
    // Sanitize inputs
    $phone_number = trim($phone_number);
    $amount = floatval($amount);
    
    // Validate phone number (Kenyan format)
    if (empty($phone_number)) {
        return ['success' => false, 'message' => 'Phone number cannot be empty'];
    }
    
    // Basic Kenyan phone number validation
    // Accepts: 0712345678, +254712345678, 254712345678, 0701234567, etc.
    $phone_pattern = '/^(\+?254|0)?[17]\d{8}$/';
    if (!preg_match($phone_pattern, $phone_number)) {
        return ['success' => false, 'message' => 'Invalid Kenyan phone number format. Use format like 0712345678'];
    }
    
    // Validate amount
    if ($amount <= 0) {
        return ['success' => false, 'message' => 'Amount must be greater than 0'];
    }
    
    if ($amount < 5) {
        return ['success' => false, 'message' => 'Minimum airtime amount is KES 5'];
    }
    
    if ($amount > 10000) {
        return ['success' => false, 'message' => 'Maximum airtime amount is KES 10,000'];
    }
    
    // Normalize phone number to 254 format
    $normalized_phone = preg_replace('/^0/', '254', $phone_number);
    $normalized_phone = preg_replace('/^\+?254/', '254', $normalized_phone);

    $result = "Airtime purchase of KES {$amount} for {$normalized_phone} has been initiated. Please check your WhatsApp shortly to confirm the transaction.";

    // Log the transaction with detailed info
    $log_entry = [
        'function' => 'buyAirtimeAutoVest',
        'phone_number' => $normalized_phone,
        'amount' => $amount,
        'timestamp' => date('Y-m-d H:i:s'),
        'result' => $result
    ];

    file_put_contents("log.txt", "AIRTIME PURCHASE: " . json_encode($log_entry, JSON_PRETTY_PRINT) . PHP_EOL, FILE_APPEND);
    
    return ['success' => true, 'message' => $result, 'log_entry' => $log_entry];
}

// Function to execute tool calls
function executeToolCall2($tool_call) {
    $function_name = $tool_call['function']['name'];
    $arguments = json_decode($tool_call['function']['arguments'], true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        return ['success' => false, 'message' => 'Invalid function arguments'];
    }
    
    switch ($function_name) {
        case 'buy_airtime':
            if (!isset($arguments['phone_number']) || empty($arguments['phone_number'])) {
                return ['success' => false, 'message' => 'Phone number parameter is required and cannot be empty'];
            }
            if (!isset($arguments['amount']) || empty($arguments['amount'])) {
                return ['success' => false, 'message' => 'Amount parameter is required and cannot be empty'];
            }
            return buyAirtimeAutoVest($arguments['phone_number'], $arguments['amount']);
            
        default:
            return ['success' => false, 'message' => 'Unknown function: ' . $function_name];
    }
}

function choose_AutoVest_option_AI($user_prompt, $wa_id = "") {
    $url = "https://api.openai.com/v1/chat/completions";

    if (!empty($wa_id)) {
        $final_prompt = build_prompt_with_history_AutoVest($wa_id, $user_prompt);
    } else {
        $final_prompt = $user_prompt;
    }

    $stats_context = "";
    $include_NSE_data = shouldIncludeNSEData($user_prompt);

    if ($include_NSE_data) {
        $stats_context = (string) getSystemStatsSummary_AutoVest_Hedera(true);
    } else {
        $stats_context = (string) getSystemStatsSummary_AutoVest_Hedera(false);
    }

    // Tools (kept: airtime)
    $tools = [
        [
        'type' => 'function',
        'function' => [
            'name' => 'buy_airtime',
            'description' => 'Purchase airtime for a phone number. Extract phone number and amount from user messages like "buy 50 shillings airtime for 0712345678", "purchase 100 KES airtime for +254701234567", "I need 20 bob airtime for 254712345678", etc. Parse amounts from various formats including "shillings", "KES", "bob", or just numbers.',
            'parameters' => [
            'type' => 'object',
            'properties' => [
                'phone_number' => [
                'type' => 'string',
                'description' => 'The phone number to buy airtime for (Kenyan format) - extract from user message'
                ],
                'amount' => [
                'type' => 'number',
                'description' => 'The amount of airtime to purchase in KES - extract and convert from user message (e.g., "50 shillings" = 50, "100 bob" = 100)'
                ]
            ],
            'required' => ['phone_number', 'amount']
            ]
        ]
        ]
    ];

  // API key
  $apiKey = getenv("OPENAI_API_KEY") ?: "";

$system_prompt = '
You are AutoVest AI, the assistant for the Hedera AutoVest platform on WhatsApp. You help users manage HBAR and HKSH, buy NSE stocks effortlessly, and set up Auto-Invest by industry. You can check balances, convert HBAR and HKSH, handle simple transfers, link external accounts, and give practical investment guidance. Answer clearly and avoid internal logs.

Important platform facts:
- Native network token: HBAR.
- Utility token: HKSH.
- AutoVest runs in WhatsApp. Wallets are created automatically on first interaction. Association to HKSH happens during first use if required.

Commands available:
- "/bal" shows HBAR and HKSH balances.
- "/addr" returns the receive address.
- "/airtime" starts the airtime purchase flow.
- "/topup" initiates an M-Pesa top-up into Hedera AutoVest.
- "/CardTopup" initiates a secure card-based top-up into Hedera AutoVest.
- "/send" sends HBAR or HKSH to a wallet address or registered phone number.
- "/convert" converts HBAR to HKSH and vice versa.
- "/buy" starts a manual NSE stock investment.
- "/auto" subscribes the user to the Hedera AutoVest AI-powered automated investor.
- "/verify" starts the identity verification (KYC) process to unlock trading and withdrawals.
- "/LinkBank" safely connects a user’s bank account to their Hedera AutoVest wallet.  
   If the user is on testnet, respond:
   “You can’t link your bank account right now because you’re using a testnet account. Please switch to a live account to enable real bank connections and transactions.”
- "/linkGoogle" links a user’s Google account to strengthen account security.  
   If the user is on testnet, respond:
   “You can’t connect your Google account right now because you’re using a testnet account. Please switch to a live account to enable real Google integrations and access.”
- "/portfolio" shows the user’s combined holdings: HBAR, HKSH, and tokenized NSE stocks.
- "/feedback" starts the feedback flow so the user can report issues or suggest improvements.

Intent recognition and exact responses:
1) Check Balance  
   Examples: "check my balance", "wallet total", "what do I have", "remaining balance"  
   Action: Reply with only "/bal" and nothing else.

1b) Check Portfolio  
    Examples: "portfolio", "show my portfolio", "what are my holdings", "my investments", 
    "my assets", "see everything I own", "check holdings", "portfolio balance"
    Action: Reply with only "/portfolio" and nothing else.

2) Get Wallet Address  
   Examples: "my wallet address", "where do I receive", "show address"  
   Action: Reply with only "/addr" and nothing else.

3) Buy HKSH  
   Examples: "buy HKSH", "purchase HKSH", "get more HKSH"  
   Response: "Your HKSH purchase request has been received. You will get a prompt shortly to complete it."

4) Convert HBAR ↔ HKSH  
   Examples:
     - "convert HKSH to HBAR" → "Conversion from HKSH to HBAR is being prepared. You will get a prompt shortly."
     - "convert HBAR to HKSH" → "HBAR received. You will get a prompt to convert it into HKSH shortly."

5) Buy NSE stocks  
   Examples: "buy 10 KPLC", "purchase Safaricom shares", "invest KES 2,000 in SCOM", "I want to buy banks ETF"  
   Response: "Stock purchase request received. You will get a guided prompt shortly to pick the stock, amount, and confirm."

6) Auto-Invest by industry  
   Examples: "auto invest in banks weekly", "set AI investment to tech monthly", "allocate 1,000 to agriculture each week"  
   Response: "Auto-Invest setup request received. You will get a guided prompt shortly to choose industry, frequency, and amount."

7) M-Pesa and wallet transfers  
   Examples:
     - "top up with M-Pesa" → "M-Pesa top-up request received. A prompt will be sent shortly to continue."
     - "send to wallet/phone" → "Transfer request received. A confirmation prompt will be sent shortly."

7b) Card Top-up  
    Examples: "top up with card", "use my Visa", "pay using Mastercard", "I want to top up by card", "card deposit"
    Response: "Card top-up request received. A secure payment prompt will be sent shortly to continue."

7c) Identity verification (KYC)  
    Examples: "verify", "verify my account", "start kyc", "complete verification", "unlock withdrawals"
    Response: "Identity verification request received. A secure KYC prompt will be sent shortly to continue."

8) Buy airtime  
   If the message contains both a valid Kenyan phone number and an amount, call the buy_airtime function directly.  
   Recognize phone formats 0712345678, +254712345678, 254712345678.  
   Recognize amounts like "50", "50 shillings", "100 KES", "20 bob".  
   If one piece is missing, ask only for the missing item.

9) Link Bank Account  
   Examples: "connect my bank", "link bank account", "add bank"
   Response: "You can’t link your bank account right now because you’re using a testnet account. Please switch to a live account to enable real bank connections and transactions."

10) Link Google Account  
   Examples: "link my Google", "connect Google account", "secure with Google"
   Response: "You can’t connect your Google account right now because you’re using a testnet account. Please switch to a live account to enable real Google integrations and access."

11) Financial tips and general help  
   Give good, responsible guidance. Keep it specific to the user request.

12) Feedback and bug reports  
   Examples: "I have feedback", "I want to report a bug", "I found an issue", "I want to suggest something", "I have a complaint", "I want to share feedback"  
   Response: "Feedback request received. I will open the feedback flow so you can describe the issue or suggestion in more detail."

Rules:
- Use only "/bal", "/portfolio", or "/addr" when those intents are detected, with no extra words.
- Do not expose internal steps or actions.
- Be concise and friendly. If asked to clarify a previous reply, explain simply.

Market data reasoning:
You have access to the latest market snapshot text below. Treat it as current NSE market data.
When users ask about:
- “top stocks”, “most traded”, “active stocks”, “market summary”, “today’s gainers”, or “market movers”
  → Parse the NSE Snapshot portion of the text and identify stocks with the highest turnover, biggest gains (positive change_pct), or notable movement.
  → Give a short ranked list (3–5 items) showing the ticker, current price, and change percentage.
  → If market status is "closed", say “based on the last trading session” or similar.
  → Always use the prices and data from the snapshot, not guesses.
- When asked “HBAR rate”, “KES value”, or “conversion”, use the rates listed in the snapshot.
- When asked “what is HKSH”, “how is it valued”, explain that 1 HKSH = 1 KES and conversion follows the shown rates.

Latest platform stats and rates:
' . $stats_context . '

Use this data when answering. Assume it is current unless stated otherwise.
';





if($include_NSE_data) {
    $data = [
        "model" => "gpt-4o", //gpt-4o-mini // gpt-4o // gpt-5-mini
        "messages" => [
        [ "role" => "system", "content" => $system_prompt ],
        [ "role" => "user",   "content" => $final_prompt ]
        ]
    ];
} else {
    $data = [
        "model" => "gpt-5-mini", //gpt-4o-mini // gpt-4o // gpt-5-mini
        "messages" => [
        [ "role" => "system", "content" => $system_prompt ],
        [ "role" => "user",   "content" => $final_prompt ]
        ]
    ];
}

  

  file_put_contents("log.txt", "Tools being sent: " . json_encode($tools, JSON_PRETTY_PRINT) . PHP_EOL, FILE_APPEND);

  if (!empty($tools)) {
    $data["tools"] = $tools;
    $data["tool_choice"] = "auto";
    file_put_contents("log.txt", "Tools added to request data" . PHP_EOL, FILE_APPEND);
  } else {
    file_put_contents("log.txt", "WARNING: Tools array is empty!" . PHP_EOL, FILE_APPEND);
  }

  file_put_contents("log.txt", "Request data: " . json_encode($data, JSON_PRETTY_PRINT) . PHP_EOL, FILE_APPEND);

  $ch = curl_init($url);
  curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Content-Type: application/json",
    "Authorization: Bearer $apiKey"
  ]);
  curl_setopt($ch, CURLOPT_POST, true);
  curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_TIMEOUT, 30);
  curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

  $response  = curl_exec($ch);
  $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $error     = curl_error($ch);
  curl_close($ch);

  if ($error) {
    return "Error: cURL Error - " . $error;
  }
  if ($http_code !== 200) {
    return "Error: HTTP Error " . $http_code . " - " . $response;
  }

  $responseArray = json_decode($response, true);
  if (json_last_error() !== JSON_ERROR_NONE) {
    return "Error: JSON decode error - " . json_last_error_msg();
  }
  if (!isset($responseArray["choices"][0]["message"])) {
    return "Error: Invalid response format from OpenAI";
  }

  $assistant_message = $responseArray["choices"][0]["message"];
  file_put_contents("log.txt", "OpenAI Full Response: " . json_encode($responseArray, JSON_PRETTY_PRINT) . PHP_EOL, FILE_APPEND);

  if (isset($assistant_message["tool_calls"]) && !empty($assistant_message["tool_calls"])) {
    file_put_contents("log.txt", "Tool calls found: " . json_encode($assistant_message["tool_calls"], JSON_PRETTY_PRINT) . PHP_EOL, FILE_APPEND);

    $tool_result = executeToolCall($assistant_message["tool_calls"][0]);
    file_put_contents("log.txt", "Tool result: " . json_encode($tool_result, JSON_PRETTY_PRINT) . PHP_EOL, FILE_APPEND);

    if ($tool_result["success"]) {
      // Initialize airtime request in server, send interactive message, save history
      global $db;
      $amount        = (string)$tool_result["log_entry"]["amount"];
      $airtime_phone = (string)$tool_result["log_entry"]["phone_number"];

      $stmt = $db->prepare("INSERT INTO `airtime_request` (`wa_id`, `amount`, `airtime_phone`) VALUES (?, ?, ?)");
      if (!$stmt) {
        file_put_contents("log.txt", "SQL PREPARE ERROR: " . $db->error . PHP_EOL, FILE_APPEND);
        exit;
      }

      $stmt->bind_param("sss", $wa_id, $amount, $airtime_phone);
      if (!$stmt->execute()) {
        file_put_contents("log.txt", "SQL EXECUTE ERROR: " . $stmt->error . PHP_EOL, FILE_APPEND);
        $response_message = "Error: An error occurred, please try again (A2)";
      } else {
        $last_id = $db->insert_id;
        file_put_contents("log.txt", "SQL SUCCESS: Inserted {$amount} for +{$airtime_phone} with ID {$last_id}" . PHP_EOL, FILE_APPEND);
        $response_message = $tool_result["message"];

        $intention_text = "You are about to buy airtime worth KES {$amount} for +{$airtime_phone}.\n\nChoose your payment method:";
        initeractive_whatsapp_hksh_airtime_v1($wa_id, $intention_text, $last_id);

        $db_query = $user_prompt;
        $db_reply = "[Action: Triggered Airtime Function] - " . $intention_text;
        $save_prompt_query = "INSERT INTO `AutoVest_prompt_history`(`wa_id`, `query`, `reply`) VALUES ('$wa_id','$db_query','$db_reply')";
        mysqli_query($db, $save_prompt_query);

        die(); // further handling happens on follow-up
      }
    } else {
      $response_message = "Error: " . $tool_result["message"];
    }
  } else {
    file_put_contents("log.txt", "No tool calls found. Assistant message: " . $assistant_message["content"] . PHP_EOL, FILE_APPEND);
    $response_message = $assistant_message["content"];
  }

  if (!empty($wa_id)) {
    saveConversationHistory_AutoVest($wa_id, $user_prompt, $response_message);
  }

  return $response_message;
}




function initeractive_whatsapp_hksh_airtime_v1($whatsapp_number, $intention_text, $id = '') {

  $url = 'https://graph.facebook.com/v18.0/887649351092468/messages';

  $apiKey = getenv("WHATSAPP_ACCESS_TOKEN") ?: "";

  // Headers for the request
  $headers = array(
      'Authorization: Bearer '. $apiKey,
      'Content-Type: application/json'
  );

  $data = [
    "messaging_product" => "whatsapp",
    "recipient_type" => "individual",
    "to" => $whatsapp_number,
    "type" => "interactive",
    "interactive" => [
        "type" => "button",
        "header" => [
            "type" => "text",
            "text" => "Airtime Purchase"
        ],
        "body" => [
            "text" => $intention_text
        ],
        "footer" => [
            "text" => "Tap a button to proceed."
        ],
        "action" => [
            "buttons" => [
                [
                    "type" => "reply",
                    "reply" => [
                        "id" => "pay_hbar_".$id,
                        "title" => "💰 Pay with HBAR"
                    ]
                ],
                [
                    "type" => "reply",
                    "reply" => [
                        "id" => "pay_hksh_".$id,
                        "title" => "💰 Pay with HKSH"
                    ]
                ],
                [
                    "type" => "reply",
                    "reply" => [
                        "id" => "cancel_airtime2_".$id,
                        "title" => "❌ Cancel"
                    ]
                ]
            ]
        ]
    ]
];



  // Initialize cURL session
  $ch = curl_init();

  // Set cURL options
  curl_setopt($ch, CURLOPT_URL, $url);
  curl_setopt($ch, CURLOPT_POST, 1);
  curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
  curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

  // Execute cURL session and capture the response
  $response = curl_exec($ch);

  //get the status code
  $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  // curl_error($ch)


  echo $response;
  echo $status;
  curl_error($ch);

  if ($status === 200) {
      //incase of an error response from saf
      if (strpos($response, "error") !== false) {
          //there was an error
          // $errors = "An error occured";
          return false;
      } else {
          // $success = "Message sent successfully";
          // echo $response;
          return true;
      }

  } else {
      // error
      // $errors = "An error occured";

      return false;
  }
}




function build_welcome_reply(string $wa_id, string $user_prompt, string $feedback): string {
    $url    = "https://api.openai.com/v1/chat/completions";
    $apiKey = getenv("OPENAI_API_KEY") ?: "";

    $include_NSE_data = shouldIncludeNSEData($user_prompt);
    $stats_context = (string) getSystemStatsSummary_AutoVest_Hedera($include_NSE_data);

    // Deterministic fallback if API key missing
    if ($apiKey === "") {
        return "🎉 Welcome to AutoVest AI!\n\n"
            . "Your wallet setup is automatic and it has already been completed.\n\n"
            . $feedback;
    }

    /**
     * NOTE:
     * We intentionally ALLOW headings and short bullets because WhatsApp readability depends on it.
     * We also strictly forbid inventing or changing any IDs/amounts/URLs.
     */
    $system_instructions =
        "You are the Hedera AutoVest WhatsApp assistant replying to a user's FIRST message.\n\n"
        . "Your job:\n"
        . "Write ONE WhatsApp-friendly message that is easy to scan and not a wall of text.\n"
        . "You MUST keep all factual strings from FEEDBACK EXACT (account IDs, token IDs, tickers, amounts, and ALL URLs).\n\n"
        . "Non-negotiable structure (in this order, exactly once each):\n"
        . "1) A short warm welcome line.\n"
        . "2) One short confirmation line that wallet creation is automatic and already done.\n"
        . "3) A compact, well-formatted wallet summary derived ONLY from FEEDBACK.\n"
        . "4) A short 'What next?' line with 2–3 options.\n\n"
        . "Formatting rules for WhatsApp:\n"
        . "- Use short sections with simple headings like '🏦 Your Wallet', '🪙 Enabled Assets'.\n"
        . "- Use 1 idea per line, with blank lines between sections.\n"
        . "- Links must be on their own line.\n"
        . "- Use at most 6 bullet lines total. Bullets must be '• '.\n"
        . "- Wrap IDs (account/token IDs) in backticks like `0.0.x`.\n"
        . "- Keep the entire message under 850 characters.\n\n"
        . "Strict safety rules:\n"
        . "- Do NOT change, shorten, re-encode, or reformat URLs (no removing query params, no adding punctuation to links).\n"
        . "- Do NOT invent any values or additional links.\n"
        . "- If FEEDBACK indicates an error, clearly state it and give ONE next step.\n\n"
        . "Tone:\n"
        . "Professional, friendly, and crisp. Avoid long paragraphs.";

    $user_content =
        "USER_FIRST_MESSAGE:\n"
        . $user_prompt . "\n\n"
        . "CONTEXT_STATS (optional, only if it helps and stays brief):\n"
        . ($stats_context !== "" ? $stats_context : "[no-stats-available]") . "\n\n"
        . "FEEDBACK (RESTYLE this; keep all facts/IDs/amounts/URLs EXACT; do not invent anything):\n"
        . $feedback;

    $data = [
        "model" => "gpt-4o-mini",
        "messages" => [
            ["role" => "system", "content" => $system_instructions],
            ["role" => "user",   "content" => $user_content],
        ],
        "temperature" => 0.35,
        "max_tokens" => 450,
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => [
            "Content-Type: application/json",
            "Authorization: Bearer $apiKey",
        ],
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 25,
    ]);

    $response = curl_exec($ch);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    // Network/API fallback: still return readable WhatsApp text
    if ($response === false || $curlErr) {
        $return_data = "🎉 Welcome to AutoVest AI!\n\n"
            . "Your wallet setup is automatic and it has already been completed.\n\n"
            . $feedback;

        if (!empty($wa_id)) {
            saveConversationHistory_AutoVest($wa_id, $user_prompt, $return_data);
        }
        return $return_data;
    }

    $responseArray = json_decode($response, true);
    $aiText = $responseArray['choices'][0]['message']['content'] ?? "";

    $return_data = trim($aiText) !== ""
        ? $aiText
        : ("🎉 Welcome to AutoVest AI!\n\n"
            . "Your wallet setup is automatic and it has already been completed.\n\n"
            . $feedback);

    if (!empty($wa_id)) {
        saveConversationHistory_AutoVest($wa_id, $user_prompt, $return_data);
    }

    return $return_data;
}




/**
 * getHederaBalances()
 * 
 * Fetches HBAR and HKSH token balances for a Hedera account.
 * Uses the Mirror Node API and returns a formatted array with WhatsApp-style feedback text.
 */

function getHederaBalances(string $account_id, string $network = 'testnet', string $hksh_token_id = '0.0.XXXXXXX'): array {
    // Mirror node base URLs
    $mirrorNodes = [
        'testnet'   => 'https://testnet.mirrornode.hedera.com',
        'mainnet'   => 'https://mainnet.mirrornode.hedera.com',
        'previewnet'=> 'https://previewnet.mirrornode.hedera.com',
    ];
    $mirror = $mirrorNodes[$network] ?? $mirrorNodes['testnet'];

    // --- Helper: GET JSON
    $ch = curl_init(rtrim($mirror, '/') . '/api/v1/accounts/' . rawurlencode($account_id) . '?transactions=false');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($resp === false) {
        return ['ok' => false, 'error' => "cURL error: $err", 'feedback' => "⚠️ Unable to reach Hedera network right now. Please try again later."];
    }

    $json = json_decode($resp, true);
    if (!is_array($json) || $code >= 400) {
        return ['ok' => false, 'error' => "Invalid response: $resp", 'feedback' => "⚠️ Could not fetch balance for account {$account_id}. Please try again."];
    }

    // --- Convert HBAR (tinybars → HBAR)
    $tinybars = (int)($json['balance']['balance'] ?? 0);
    $hbar_balance = $tinybars / 100000000; // numeric

    // --- Find HKSH token balance
    $hksh_balance = 0.0;
    $tokens = $json['balance']['tokens'] ?? [];
    if ($hksh_token_id !== '0.0.XXXXXXX' && !empty($tokens)) {
        foreach ($tokens as $t) {
            if (($t['token_id'] ?? '') === $hksh_token_id) {
                $raw = (string)($t['balance'] ?? "0");
                $dec = (int)($t['decimals'] ?? 2);
                $denom = $dec > 0 ? pow(10, $dec) : 1;
                $hksh_balance = (float)$raw / (float)$denom;
                break;
            }
        }
    }

    // --- Fetch live exchange rates (cached 2 min)
    $cacheFile = 'hbar_rates_cache.json';
    $now = time();
    $rates = null;

    if (file_exists($cacheFile)) {
        $rates = json_decode(file_get_contents($cacheFile), true);
        if (!is_array($rates) || ($now - ($rates['timestamp'] ?? 0)) > 120) {
            $rates = null;
        }
    }

    if (!$rates) {
        $url = "https://api.coingecko.com/api/v3/simple/price?ids=hedera-hashgraph&vs_currencies=usd,kes";
        $data = @json_decode(@file_get_contents($url), true);
        $rates = [
            'usd' => $data['hedera-hashgraph']['usd'] ?? 0.05,
            'kes' => $data['hedera-hashgraph']['kes'] ?? 7.0,
            'timestamp' => $now
        ];
        file_put_contents($cacheFile, json_encode($rates));
    }

    $rate_usd = (float)$rates['usd'];
    $rate_kes = (float)$rates['kes'];

    // --- Approx value (assuming 1 HKSH = 1 KES)
    $hksh_to_kes = 1.0;
    $total_value_kes = ($hbar_balance * $rate_kes) + ($hksh_balance * $hksh_to_kes);
    $total_value_usd = ($hbar_balance * $rate_usd) + ($hksh_balance * ($hksh_to_kes / $rate_kes * $rate_usd));

    // --- Formatting
    $hbar_fmt = number_format($hbar_balance, 3);
    $hksh_fmt = number_format($hksh_balance, 2);
    $total_kes_fmt = number_format($total_value_kes, 2);
    $total_usd_fmt = number_format($total_value_usd, 2);

    $explorer = "https://hashscan.io/{$network}/account/" . rawurlencode($account_id);

    // $feedback = "💼 *Wallet Summary*\n"
    //     . "• *{$hbar_fmt} HBAR*  _(network token)_\n"
    //     . "• *{$hksh_fmt} HKSH*  _(investment token)_\n\n"
    //     . "💰 *Approx Total Value:*\n"
    //     . "• ~ KES *{$total_kes_fmt}*\n"
    //     . "• ~ USD *{$total_usd_fmt}*\n\n"
    //     . "🔎 View on HashScan:\n{$explorer}";

    $feedback = "💼 *Your Hedera AutoVest Wallet Balance*\n\n"
    . "🏦 *Account Summary:*\n"
    . "• *{$hbar_fmt} HBAR*  _(network token for transactions)_\n"
    . "• *{$hksh_fmt} HKSH*  _(investment token powering AutoVest AI)_\n\n"
    . "💰 *Estimated Total Value:*\n"
    . "• ≈ *KES {$total_kes_fmt}*\n"
    . "• ≈ *USD {$total_usd_fmt}*\n\n"
    . "🔗 *View on HashScan:*\n{$explorer}\n\n"
    . "✨ You can use your HKSH to invest, trade, or access AutoVest AI insights anytime!";


    return [
        'ok' => true,
        'hbar' => $hbar_balance,
        'hksh' => $hksh_balance,
        'rate_usd' => $rate_usd,
        'rate_kes' => $rate_kes,
        'total_kes' => $total_value_kes,
        'total_usd' => $total_value_usd,
        'feedback' => $feedback
    ];
}



/**
 * detect_nse_relevance.php
 *
 * Determines if a user's prompt should include live NSE stock data.
 * Returns: true | false
 */

function shouldIncludeNSEData($user_prompt) {
    $apiKey = getenv("OPENAI_API_KEY") ?: "";
    $url = "https://api.openai.com/v1/chat/completions";

    $system_prompt = <<<SYS
You are a financial intent classifier.
Determine if the user's message requires live data about the NSE (Nairobi Securities Exchange) stocks.

If the message clearly mentions or implies:
- stock prices, shares, or tickers (e.g., "Safaricom", "EQTY", "KCB", "BAT"),
- market summary or NSE data,
- buying, selling, or analyzing NSE-listed companies,

respond with only the word "true".

Otherwise, respond with only the word "false".
Do not include any explanations or extra text.
SYS;

    $data = [
        "model" => "gpt-4o-mini",
        "temperature" => 0,
        "messages" => [
            [ "role" => "system", "content" => $system_prompt ],
            [ "role" => "user", "content" => $user_prompt ]
        ]
    ];

    $options = [
        "http" => [
            "header" => "Content-Type: application/json\r\n" .
                        "Authorization: Bearer $apiKey\r\n",
            "method" => "POST",
            "content" => json_encode($data),
        ],
    ];

    $context = stream_context_create($options);
    $response = @file_get_contents($url, false, $context);

    if ($response === false) return false;

    $result = json_decode($response, true);
    $answer = strtolower(trim($result['choices'][0]['message']['content'] ?? ''));

    return $answer === "true";
}



function AIRTIME_TEMPLATE_init_AutoVest_whatsapp($whatsapp_number) {

    // Replace this with your own Phone Number ID, Access Token, and Template Name
    $phone_number_id = "887649351092468";  // e.g. 123456789012345
    $access_token = getenv('WHATSAPP_ACCESS_TOKEN');

    // WhatsApp Cloud API endpoint
    $url = "https://graph.facebook.com/v21.0/{$phone_number_id}/messages";

    // Request headers
    $headers = [
        "Authorization: Bearer {$access_token}",
        "Content-Type: application/json"
    ];

     // Prepare data to send via POST
    $data = [
        'messaging_product' => 'whatsapp',
        'recipient_type' => 'individual',
        'to' => $whatsapp_number,
        'type' => 'template',
        'template' => [
            'name' => "airtime_purchase_request", 
            'language' => [
                'code' => "en"
            ],
            'components' => [
                [
                    'type' => 'button',
                    'sub_type' => 'flow',
                    'index' => '0'
                ]
            ]
        ]
    ];

    // Send request
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => json_encode($data, JSON_UNESCAPED_SLASHES),
        CURLOPT_RETURNTRANSFER => true
    ]);

    $response = curl_exec($ch);
    $status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error    = curl_error($ch);
    curl_close($ch);

    if ($error) {
        error_log("cURL Error: " . $error);
        return false;
    }

    if ($status >= 200 && $status < 300 && strpos($response, "error") === false) {
        return true;
    }

    error_log("WhatsApp API error ({$status}): " . $response);
    return false;
}


function hashscan_base_from_network(?string $n): string {
    $n = strtolower(trim((string)$n));
    if ($n === 'mainnet' || $n === 'main')   return 'https://hashscan.io/mainnet';
    if ($n === 'previewnet' || $n === 'preview') return 'https://hashscan.io/previewnet';
    return 'https://hashscan.io/testnet';
}

function normalize_msisdn_ke(string $raw): string {
    $p = preg_replace('/\D+/', '', $raw);
    if (strpos($p, '254') === 0) return $p;
    if (strpos($p, '0') === 0)   return '254' . substr($p, 1);
    return $p;
}


/**
 * Minimal JSON POST helper to your Node API.
 * Env:
 *   AUTOVEST_NODE_BASE_URL = http://127.0.0.1:5050  (default)
 *   AUTOVEST_NODE_API_KEY  = optional, will be sent as X-API-Key
 */
function autovest_json_post(string $path, array $payload, int $timeout = 25): array {
    $base = rtrim(getenv('AUTOVEST_NODE_BASE_URL') ?: 'http://127.0.0.1:5050', '/');
    $url  = $base . $path;

    $headers = ['Content-Type: application/json'];
    if ($k = getenv('AUTOVEST_NODE_API_KEY')) {
        $headers[] = 'X-API-Key: ' . $k;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_SLASHES),
    ]);
    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($resp === false) {
        return ['ok' => false, 'error' => "cURL error: $err", 'http' => $code];
    }
    $json = json_decode($resp, true);
    if (!is_array($json)) {
        return ['ok' => false, 'error' => "Invalid JSON: $resp", 'http' => $code];
    }
    $json['_http'] = $code;
    return $json;
}

/**
 * Send HBAR from signer → recipient.
 * Current caller sends tinybars, so we convert back to HBAR float for the Node API.
 * Works for both user-signed and operator-signed transfers.
 *
 * @param string $signerPrivKey  Private key of the sender (user or operator)
 * @param string $fromAccountId  AccountId (0.0.x) of the sender
 * @param string $toAccountId    Recipient AccountId
 * @param int    $tinybars       Amount in tinybars (1e-8 HBAR)
 * @param string $memo           Optional memo
 * @return array {confirmed:bool, txId?:string, status?:string, ...}
 */
function send_HBAR_Transaction(string $signerPrivKey,
                               string $fromAccountId,
                               string $toAccountId,
                               int $tinybars,
                               string $memo = ''): array {
    // Convert tinybars -> HBAR expected by Node endpoint
    $hbar = $tinybars / 100_000_000;

    $payload = [
        'fromPrivKey' => $signerPrivKey,
        'fromAccountId' => $fromAccountId,
        'toAccountId' => $toAccountId,
        'hbar' => $hbar,
        'memo' => $memo,
    ];

    $res = autovest_json_post('/transfer', $payload);
    $ok  = !empty($res['ok']) && ($res['_http'] ?? 500) < 400;

    return [
        'confirmed' => $ok,
        'txId'      => $res['txId'] ?? null,
        'status'    => $res['status'] ?? null,
        'error'     => $ok ? null : ($res['error'] ?? 'unknown error'),
        'raw'       => $res,
    ];
}

/**
 * Send HKSH tokens. Accepts human units (e.g., 12.345678 if decimals=6) and scales to smallest units.
 * If signer is operator, uses /tokens/transfer (treasury → toAccount).
 * If signer is a user, uses /tokens/transfer-user (from user → toAccount).
 *
 * @param string $signerPrivKey  Private key of the sender (user or operator)
 * @param string $tokenId        HTS token id (e.g., 0.0.12345)
 * @param string $toAccountId    Recipient AccountId
 * @param float  $humanAmount    Human units (will be scaled using HKSH_DECIMALS)
 * @param string $memo           Optional memo
 * @return array {confirmed:bool, txId?:string, status?:string, ...}
 */
function send_HKSH_Token_Transaction(string $signerPrivKey,
                                     string $tokenId,
                                     string $toAccountId,
                                     float $humanAmount,
                                     string $memo = ''): array {
    $decimals      = (int) (getenv('HKSH_DECIMALS') ?: 6);
    $scale         = $decimals > 0 ? (10 ** $decimals) : 1;
    $scaledAmount  = (int) round($humanAmount * $scale);

    $operatorId  = getenv('HEDERA_OPERATOR_ID') ?: '';
    $operatorKey = getenv('HEDERA_OPERATOR_KEY') ?: '';

    // Determine whether this is operator → user, or user → someone (e.g., main)
    if ($operatorKey && hash_equals($operatorKey, $signerPrivKey)) {
        // Operator path: /tokens/transfer
        $payload = [
            'tokenId'     => $tokenId,
            'toAccountId' => $toAccountId,
            'amount'      => $scaledAmount,
            'memo'        => $memo,
        ];
        $res = autovest_json_post('/tokens/transfer', $payload);
    } else {
        // User-signed path: need fromAccountId. We take it from the active context.
        // Your current call site does not pass fromAccountId, so read it from the outer scope.
        $fromAccountId = $GLOBALS['user_account_id'] ?? '';
        if (!$fromAccountId) {
            return ['confirmed' => false, 'error' => 'fromAccountId unavailable for user-signed token transfer'];
        }
        $payload = [
            'tokenId'       => $tokenId,
            'fromAccountId' => $fromAccountId,
            'fromPrivKey'   => $signerPrivKey,
            'toAccountId'   => $toAccountId,
            'amount'        => $scaledAmount,
            'memo'          => $memo,
        ];
        $res = autovest_json_post('/tokens/transfer-user', $payload);
    }

    $ok = !empty($res['ok']) && ($res['_http'] ?? 500) < 400;
    return [
        'confirmed' => $ok,
        'txId'      => $res['txId'] ?? null,
        'status'    => $res['status'] ?? null,
        'error'     => $ok ? null : ($res['error'] ?? 'unknown error'),
        'raw'       => $res,
    ];
}


function initeractive_whatsapp_hksh_stocks($whatsapp_number, $intention_text, $id = '') {

    $phone_number_id = "887649351092468";  // AutoVest

    // WhatsApp Cloud API endpoint
    $url = "https://graph.facebook.com/v21.0/{$phone_number_id}/messages";
    
    $access_token = getenv('WHATSAPP_ACCESS_TOKEN');

    // Request headers
    $headers = [
        "Authorization: Bearer {$access_token}",
        "Content-Type: application/json"
    ];

  $data = [
    "messaging_product" => "whatsapp",
    "recipient_type" => "individual",
    "to" => $whatsapp_number,
    "type" => "interactive",
    "interactive" => [
        "type" => "button",
        "header" => [
            "type" => "text",
            "text" => "Buy Stocks"
        ],
        "body" => [
            "text" => $intention_text
        ],
        "footer" => [
            "text" => "Tap a button to proceed."
        ],
        "action" => [
            "buttons" => [
                [
                    "type" => "reply",
                    "reply" => [
                        "id" => "pay_hbar_".$id,
                        "title" => "💰 Confirm"
                    ]
                ],
                [
                    "type" => "reply",
                    "reply" => [
                        "id" => "cancel_stocks_".$id,
                        "title" => "❌ Cancel"
                    ]
                ]
            ]
        ]
    ]
];



  // Initialize cURL session
  $ch = curl_init();

  // Set cURL options
  curl_setopt($ch, CURLOPT_URL, $url);
  curl_setopt($ch, CURLOPT_POST, 1);
  curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
  curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

  // Execute cURL session and capture the response
  $response = curl_exec($ch);

  //get the status code
  $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  // curl_error($ch)


//   echo $response;
//   echo $status;
  curl_error($ch);

  if ($status === 200) {
      //incase of an error response from saf
      if (strpos($response, "error") !== false) {
          //there was an error
          // $errors = "An error occured";
          return false;
      } else {
          // $success = "Message sent successfully";
          // echo $response;
          return true;
      }

  } else {
      // error
      // $errors = "An error occured";

      return false;
  }
}


//portfolio functions --------------------------------------------


/**
* Core Portfolio Logic
*/
function getHederaPortfolioWithDB(
    string $account_id,
    string $network = 'testnet',
    string $hksh_token_id = '0.0.XXXXXXX',
    ?mysqli $db = null
): array {

    // Mirror base URL
    $mirror = [
        'testnet'    => 'https://testnet.mirrornode.hedera.com',
        'mainnet'    => 'https://mainnet.mirrornode.hedera.com',
        'previewnet' => 'https://previewnet.mirrornode.hedera.com',
    ][$network] ?? 'https://testnet.mirrornode.hedera.com';

    // 1) Account balances
    $acctUrl = rtrim($mirror, '/') . '/api/v1/accounts/' . rawurlencode($account_id) . '?transactions=false';
    [$code, $acct, $err] = http_get_json($acctUrl, ['Accept: application/json'], 20);
    if (!is_array($acct)) return ['ok'=>false,'error'=>"Mirror error: ".($err ?: "HTTP {$code}")];

    $tinybars      = (int)($acct['balance']['balance'] ?? 0);
    $hbar_balance  = $tinybars / 100000000;
    $tokenBalances = $acct['balance']['tokens'] ?? [];

    // 2) Collect subscribed token IDs
    $subscribedTokenIds = [];
    foreach ($tokenBalances as $t) {
        $tid = (string)($t['token_id'] ?? '');
        if ($tid !== '') $subscribedTokenIds[$tid] = true;
    }
    $subscribedTokenIds = array_keys($subscribedTokenIds);

    // 3) Enrich from DB if possible
    $metaById = [];
    if ($db && $subscribedTokenIds) {
        $placeholders = implode(',', array_fill(0, count($subscribedTokenIds), '?'));
        $sql = "SELECT token_id, symbol, decimals, type, name FROM tokens WHERE token_id IN ($placeholders)";
        if ($stmt = mysqli_prepare($db, $sql)) {
            $types = str_repeat('s', count($subscribedTokenIds));
            mysqli_stmt_bind_param($stmt, $types, ...$subscribedTokenIds);
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);
            while ($row = mysqli_fetch_assoc($res)) {
                $metaById[(string)$row['token_id']] = [
                    'symbol'   => $row['symbol'] ?? null,
                    'decimals' => (int)($row['decimals'] ?? 0),
                    'type'     => $row['type'] ?? null,
                    'name'     => $row['name'] ?? null,
                ];
            }
            mysqli_stmt_close($stmt);
        }
    }

    // 4) Fallback to mirror for token info
    foreach ($subscribedTokenIds as $tid) {
        if (isset($metaById[$tid])) continue;
        $info = get_token_info_cached($tid, $network);
        if (is_array($info) && empty($info['error'])) {
            $metaById[$tid] = [
                'symbol'   => $info['symbol'] ?? null,
                'decimals' => (int)($info['decimals'] ?? 0),
                'type'     => $info['type'] ?? null,
                'name'     => $info['name'] ?? null,
            ];
        }
    }

    // 5) Fetch NSE snapshot directly from DB
    $snapshot = fetch_nse_snapshot_from_db($db);
    $price_map_kes = $snapshot['price_map_kes'];
    $tickerSet     = $snapshot['ticker_set'];
    $snapshot_dt   = $snapshot['snapshot_dt'];
    $snapshot_err  = $snapshot['error'];

    // 6) HBAR rates
    $rates    = get_cached_hbar_rates();
    $rate_usd = (float)$rates['usd'];
    $rate_kes = (float)$rates['kes'];

    // 7) Classify balances
    $hksh_balance = 0.0;
    $stocks       = [];
    $others       = [];

    foreach ($tokenBalances as $t) {
        $tid   = (string)($t['token_id'] ?? '');
        $raw   = (string)($t['balance'] ?? '0');
        $dec   = (int)($t['decimals'] ?? 0);               // decimals reported by mirror for this balance
        $denom = $dec > 0 ? pow(10, $dec) : 1.0;
        $amt_units = (float)$raw / (float)$denom;          // token units

        if ($tid === $hksh_token_id) {
            $hksh_balance = round($amt_units/100, 2);
            continue;
        }

        $meta     = $metaById[$tid] ?? [];
        $symbol   = strtoupper(trim((string)($meta['symbol'] ?? '')));
        $symbol   = preg_replace('/\s+/', '', $symbol);
        $type     = strtolower((string)($meta['type'] ?? ''));
        $tokDec   = (int)($meta['decimals'] ?? $dec);      // canonical decimals from DB if available

        $isNSE = false;
        if (in_array($type, ['nse_equity','nse_stock','security','nse'])) $isNSE = true;
        if (!$isNSE && $symbol && isset($tickerSet[$symbol])) $isNSE = true;

        if ($isNSE) {
            // convert token units to real shares for NSE tokens: 2 decimals => 100 units = 1 share
            $shares = normalize_nse_shares($amt_units, $tokDec);

            $stocks[] = [
                'token_id' => $tid,
                'ticker'   => $symbol ?: $tid,
                'units'    => round($amt_units, max(0, $tokDec)), // raw token units for reference
                'shares'   => round($shares, 2),                  // use for pricing
                'decimals' => $tokDec,
                'name'     => $meta['name'] ?? null
            ];
        } else {
            $others[] = [
                'token_id' => $tid,
                'symbol'   => $symbol,
                'amount'   => round($amt_units, max(0, $tokDec)),
                'type'     => $type ?: 'other'
            ];
        }
    }

    // 8) Calculate values
    $hksh_to_kes     = 1.0;
    $hbar_value_kes  = $hbar_balance * $rate_kes;
    $hksh_value_kes  = $hksh_balance * $hksh_to_kes;

    $stocks_value_kes = 0.0;
    $stock_lines = [];

    foreach ($stocks as $s) {
        $sym    = strtoupper($s['ticker']);
        $shares = (float)$s['shares'];                       // use real shares here
        $px     = $price_map_kes[$sym] ?? null;

        if ($px !== null) {
            $val = $shares * (float)$px;
            $stocks_value_kes += $val;
            $stock_lines[] = "{$sym}: " . number_format($shares, 2) . " @ KES " . number_format($px, 2) . " = KES " . number_format($val, 2);
        } else {
            $stock_lines[] = "{$sym}: " . number_format($shares, 2) . " (price unavailable)";
        }
    }

    $total_kes = $hbar_value_kes + $hksh_value_kes + $stocks_value_kes;
    $total_usd = ($total_kes / max($rate_kes, 0.0000001)) * $rate_usd;

    // 9) Build WhatsApp feedback
    $feedback =
        "💼 Your Hedera AutoVest Portfolio\n\n"
    . "🏦 Account: {$account_id}\n"
    . "🔗 HashScan: https://hashscan.io/{$network}/account/{$account_id}\n\n"
    . "HBAR: " . number_format($hbar_balance,3) . " ≈ KES " . number_format($hbar_value_kes,2) . "\n"
    . "HKSH: " . number_format($hksh_balance,2) . " ≈ KES " . number_format($hksh_value_kes,2) . "\n\n"
    . "📊 NSE Tokens\n" . ($stock_lines ? implode("\n",array_map(fn($l)=>"• ".$l,$stock_lines)) : "• None") . "\n\n"
    . "💰 Estimated Total\nKES " . number_format($total_kes,2) . "  |  USD " . number_format($total_usd,2); // . "\n"
    //   . "📅 Snapshot: " . ($snapshot_dt ?: "unavailable" . ($snapshot_err ? " ({$snapshot_err})" : ""));

    return [
        'ok'               => true,
        'hbar'             => $hbar_balance,
        'hksh'             => $hksh_balance,
        'stocks'           => $stocks,
        'others'           => $others,
        'stocks_value_kes' => $stocks_value_kes,
        'total_kes'        => $total_kes,
        'total_usd'        => $total_usd,
        'snapshot_dt'      => $snapshot_dt,
        'feedback'         => $feedback
    ];
}

/**
* Convert NSE token units to real shares.
* Rule: 2 decimals => 100 token units = 1 share.
*/
function normalize_nse_shares(float $amt_units, int $tokDec): float {
    return ($tokDec === 2) ? ($amt_units / 100.0) : $amt_units;
}

/**
* Pull the latest NSE snapshot directly from DB
*/
function fetch_nse_snapshot_from_db(?mysqli $db): array {
    $price_map_kes = [];
    $tickerSet = [];
    $snapshot_dt = null;
    $error = null;

    if (!$db instanceof mysqli) {
        return ['price_map_kes'=>[], 'ticker_set'=>[], 'snapshot_dt'=>null, 'error'=>'no db connection'];
    }

    try {
        // check if nse_ticks exists
        $exists = $db->query("SHOW TABLES LIKE 'nse_ticks'");
        if ($exists && $exists->num_rows > 0) {
            $res = $db->query("SELECT MAX(asof_date) AS d FROM nse_ticks");
            $asof = $res ? ($res->fetch_assoc()['d'] ?? null) : null;
            if ($asof) {
                $res2 = $db->query("SELECT MAX(snapshot_dt) AS s FROM nse_ticks WHERE asof_date = '".$db->real_escape_string($asof)."'");
                $snapshot_dt = $res2 ? ($res2->fetch_assoc()['s'] ?? null) : null;
                $sql = "
                    SELECT t.ticker, t.latest AS closing
                    FROM nse_ticks t
                    INNER JOIN (
                        SELECT ticker, MAX(snapshot_dt) AS snap
                        FROM nse_ticks WHERE asof_date = '".$db->real_escape_string($asof)."'
                        GROUP BY ticker
                    ) r ON t.ticker = r.ticker AND t.snapshot_dt = r.snap
                ";
                $snapRes = $db->query($sql);
                if ($snapRes) {
                    while ($r = $snapRes->fetch_assoc()) {
                        $sym = strtoupper(trim($r['ticker'] ?? ''));
                        $px  = (float)($r['closing'] ?? 0);
                        if ($sym !== '' && $px > 0) {
                            $price_map_kes[$sym] = $px;
                            $tickerSet[$sym] = true;
                        }
                    }
                }
            }
        } else {
            // fallback to legacy nse_quotes
            $res = $db->query("SELECT MAX(snapshot_dt) AS latest FROM nse_quotes");
            $snapshot_dt = $res ? ($res->fetch_assoc()['latest'] ?? null) : null;
            if ($snapshot_dt) {
                $res2 = $db->query("SELECT name AS ticker, closing FROM nse_quotes WHERE snapshot_dt = '".$db->real_escape_string($snapshot_dt)."'");
                if ($res2) {
                    while ($r = $res2->fetch_assoc()) {
                        $sym = strtoupper(trim($r['ticker'] ?? ''));
                        $px  = (float)($r['closing'] ?? 0);
                        if ($sym !== '' && $px > 0) {
                            $price_map_kes[$sym] = $px;
                            $tickerSet[$sym] = true;
                        }
                    }
                }
            }
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }

    return [
        'price_map_kes' => $price_map_kes,
        'ticker_set'    => $tickerSet,
        'snapshot_dt'   => $snapshot_dt,
        'error'         => $error
    ];
}

/**
* Cached token info from Mirror Node
*/
function get_token_info_cached(string $tokenId, string $network): ?array {
    $cacheDir = __DIR__ . '/token_cache';
    if (!is_dir($cacheDir)) @mkdir($cacheDir, 0775, true);
    $key  = "{$network}__" . str_replace(['/', '\\'], '_', $tokenId) . '.json';
    $path = $cacheDir . '/' . $key;
    $ttl = 86400;

    if (file_exists($path) && (time() - filemtime($path)) < $ttl) {
        $cached = json_decode(file_get_contents($path), true);
        if (is_array($cached)) return $cached;
    }

    $mirror = [
        'testnet' => 'https://testnet.mirrornode.hedera.com',
        'mainnet' => 'https://mainnet.mirrornode.hedera.com'
    ][$network] ?? 'https://testnet.mirrornode.hedera.com';

    $url = rtrim($mirror, '/') . '/api/v1/tokens/' . rawurlencode($tokenId);
    [$code, $data] = http_get_json($url, ['Accept: application/json'], 15);
    if (!is_array($data) || $code >= 400) {
        $out = ['error'=>"HTTP {$code}"];
        file_put_contents($path, json_encode($out));
        return $out;
    }

    $out = [
        'symbol'   => $data['symbol']   ?? null,
        'name'     => $data['name']     ?? null,
        'decimals' => $data['decimals'] ?? null,
        'type'     => $data['type']     ?? null,
    ];
    file_put_contents($path, json_encode($out));
    return $out;
}

/**
* Cached HBAR/USD/KES rates (2min)
*/
function get_cached_hbar_rates(): array {
    $cacheFile = __DIR__ . '/hbar_rates_cache.json';
    $now = time();
    if (file_exists($cacheFile)) {
        $decoded = json_decode(file_get_contents($cacheFile), true);
        if (is_array($decoded) && ($now - ($decoded['timestamp'] ?? 0)) <= 120) return $decoded;
    }
    $url = "https://api.coingecko.com/api/v3/simple/price?ids=hedera-hashgraph&vs_currencies=usd,kes";
    $json = @json_decode(@file_get_contents($url), true);
    $rates = [
        'usd' => $json['hedera-hashgraph']['usd'] ?? 0.05,
        'kes' => $json['hedera-hashgraph']['kes'] ?? 7.0,
        'timestamp' => $now
    ];
    file_put_contents($cacheFile, json_encode($rates));
    return $rates;
}

/**
* Simple HTTP GET helper
*/
function http_get_json(string $url, array $headers = [], int $timeout = 20): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    $raw  = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = json_decode($raw, true);
    if (!is_array($data)) return [$code, null, $err ?: 'Invalid JSON'];
    return [$code, $data, null];
}

//end portfolion functions ------------------------------------------




function getLatestKycSubmission(mysqli $db, string $phoneDigits): ?array {
    $sql = "SELECT flow_token, status, updated_at, created_at
            FROM kyc_submissions
            WHERE phone_digits = ?
            ORDER BY created_at DESC
            LIMIT 1";

    $stmt = $db->prepare($sql);
    if (!$stmt) return null;

    $stmt->bind_param("s", $phoneDigits);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    return $row ?: null;
}

function expireKycSubmission(mysqli $db, string $flowToken): void {
    $sql = "UPDATE kyc_submissions
            SET status='error', updated_at=NOW()
            WHERE flow_token=? AND status IN ('sent','started') LIMIT 1";

    $stmt = $db->prepare($sql);
    if (!$stmt) return;
    $stmt->bind_param("s", $flowToken);
    $stmt->execute();
    $stmt->close();
}