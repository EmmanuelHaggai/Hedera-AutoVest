<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;
use Slim\Exception\HttpMethodNotAllowedException;
use Slim\Exception\HttpNotFoundException;
use phpseclib3\Crypt\RSA;
use phpseclib3\Crypt\AES;
use phpseclib3\Crypt\PublicKeyLoader;

require __DIR__ . '/vendor/autoload.php';

// Core helpers 
require_once '/var/www/aws1/v2-functions.php';
require_once '/var/www/AutoVest.hedera.co.ke/api/callback/hedera_functions.php';

/**
 * Load .env from /var/www/AutoVest.hedera.co.ke/.env into $env
 */
$env = [];
// Load environment variables from AWS KMS
require_once '/var/www/AutoVest.hedera.co.ke/bootstrap_secrets.php';

try {
    $DEBUG = filter_var(env('DEBUG') ?: 'false', FILTER_VALIDATE_BOOLEAN);

    if ($DEBUG) {
        ini_set('display_errors', '1');
        ini_set('display_startup_errors', '1');
        error_reporting(E_ALL);
    }

    $AWS_REGION = getenv('AWS_REGION') ?: 'eu-west-1';
    $AWS_SECRET_ID = getenv('AWS_SECRET_ID') ?: 'prod/autovest/app';

    $env = loadAwsSecrets($AWS_SECRET_ID, $AWS_REGION);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'bootstrap_failed']);
    error_log($e->getMessage());
    exit;
}

// DB config (env or process env)
$dbHost = env('DB_HOST') ?: ($env['DB_HOST'] ?? 'localhost');
$dbUser = env('DB_USER') ?: ($env['DB_USER'] ?? 'root');
$dbPass = env('DB_PASS') ?: ($env['DB_PASS'] ?? '');
$dbName = env('DB_NAME') ?: ($env['DB_NAME'] ?? 'hedera_ai');

// Flows private key path (env override)
$privateKeyPath = env('FLOWS_PRIVATE_KEY') ?: ($env['FLOWS_PRIVATE_KEY'] ?? '/etc/whatsapp/flows_private.pem');

// Flow screen IDs (match your uploaded Flow)
const SCREEN_KYC_ID      = 'KYC_ID';
const SCREEN_KYC_SELFIE  = 'KYC_SELFIE';
const SCREEN_KYC_ADDRESS = 'KYC_ADDRESS';
const SCREEN_KYC_FUNDS   = 'KYC_FUNDS';
const SCREEN_ERROR       = 'ERROR';

$app = AppFactory::create();

// base path: auto-detect when app is in /flows
$basePath = rtrim(str_ireplace('index.php', '', $_SERVER['SCRIPT_NAME'] ?? ''), '/');
if ($basePath) {
    $app->setBasePath($basePath);
}

// JSON body parsing
$app->addBodyParsingMiddleware();

/* ============================================================
 * LOGGING 
 * Logs to: /var/log/whatsapp-flows/requests.log
 * One JSON per line.
 * Safe: no AES keys, no IVs, no decrypted PII bytes, no media bytes.
 * ============================================================ */

function logRequest(array $payload): void
{
    $logDir  = '/var/www/AutoVest.hedera.co.ke/flows/logs/';
    $logFile = $logDir . 'flow-requests.log';

    if (!is_dir($logDir)) {
        @mkdir($logDir, 0750, true);
    }

    $entry = [
        'time'         => date('c'),
        'ip'           => $_SERVER['REMOTE_ADDR'] ?? null,
        'method'       => $_SERVER['REQUEST_METHOD'] ?? null,
        'path'         => $_SERVER['REQUEST_URI'] ?? null,
        'user_agent'   => $_SERVER['HTTP_USER_AGENT'] ?? null,
        'content_type' => $_SERVER['CONTENT_TYPE'] ?? null,
        'payload'      => $payload,
    ];

    @file_put_contents(
        $logFile,
        json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );
}

// Global middleware: logs every request (GET/HEAD/POST)
$app->add(function (Request $request, $handler) {
    logRequest([
        'event'   => 'http_request',
        'method'  => $request->getMethod(),
        'route'   => $request->getUri()->getPath(),
        'headers' => [
            'content_length' => $request->getHeaderLine('Content-Length'),
            'content_type'   => $request->getHeaderLine('Content-Type'),
        ],
    ]);

    return $handler->handle($request);
});



// ---------- helpers ----------
function jsend(Response $response, int $status, array $data): Response {
    $response->getBody()->write(json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
}

function b64auto_decode(string $s): string {
    $s = preg_replace('/\s+/', '', $s) ?? '';
    $s = strtr($s, '-_', '+/');
    $pad = strlen($s) % 4;
    if ($pad) $s .= str_repeat('=', 4 - $pad);
    $out = base64_decode($s, true);
    if ($out === false) throw new RuntimeException('Base64 decode failed');
    return $out;
}

function decryptRequest(array $body, string $privatePem): array {
    foreach (['encrypted_aes_key','encrypted_flow_data','initial_vector'] as $k) {
        if (!isset($body[$k])) throw new RuntimeException("Missing field: {$k}");
    }

    $encKey  = b64auto_decode($body['encrypted_aes_key']);
    $encData = b64auto_decode($body['encrypted_flow_data']);
    $iv      = b64auto_decode($body['initial_vector']);

    $rsa = PublicKeyLoader::load($privatePem)
        ->withPadding(RSA::ENCRYPTION_OAEP)
        ->withHash('sha256')
        ->withMGFHash('sha256');

    $aesKey = $rsa->decrypt($encKey);
    if (!is_string($aesKey) || $aesKey === '') throw new RuntimeException('AES key decrypt failed');

    if (strlen($encData) <= 16) throw new RuntimeException('encrypted_flow_data too short');
    $tagLen = 16;
    $ct  = substr($encData, 0, -$tagLen);
    $tag = substr($encData, -$tagLen);

    $aes = new AES('gcm');
    $aes->setKey($aesKey);
    $aes->setNonce($iv);
    $aes->setTag($tag);

    $plain = $aes->decrypt($ct);
    if ($plain === false || $plain === '') throw new RuntimeException('AES-GCM decrypt failed');

    $decoded = json_decode($plain, true);
    if (!is_array($decoded)) throw new RuntimeException('Decrypted payload is not JSON');

    return ['decryptedBody'=>$decoded, 'aesKeyBuffer'=>$aesKey, 'initialVectorBuffer'=>$iv];
}

function encryptResponseGCM($responseObj, string $aesKey, string $requestIv): string {
    $json = json_encode($responseObj, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    // Flip IV byte-by-byte (reply IV)
    $mask    = str_repeat("\xFF", strlen($requestIv));
    $replyIv = $requestIv ^ $mask;

    $aes = new AES('gcm');
    $aes->setKey($aesKey);
    $aes->setNonce($replyIv);
    $ciphertext = $aes->encrypt($json);
    $tag        = $aes->getTag();

    if ($ciphertext === false || $tag === null) {
        throw new RuntimeException('AES-GCM encrypt failed');
    }
    return base64_encode($ciphertext . $tag);
}

// -------- flow utilities --------
$eqi = static function (?string $a, ?string $b): bool {
    return strcasecmp((string)$a, (string)$b) === 0;
};

$filterData = static function (array $input, array $allowedKeys): array {
    $out = [];
    foreach ($allowedKeys as $k) {
        if (array_key_exists($k, $input)) $out[$k] = $input[$k];
    }
    return $out;
};

$normalizeMediaList = static function ($v): array {
    // Treat missing / empty as no uploads
    if ($v === null || $v === '' || $v === false) return [];

    // If WhatsApp sends a single media object as an object
    if (is_object($v)) {
        $arr = (array)$v;
        return empty($arr) ? [] : [$arr];
    }

    // If it's an array, it can be either:
    // - list of media objects: [ {...}, {...} ]
    // - single media object as associative array: { ... }
    if (is_array($v)) {
        $isAssoc = array_keys($v) !== range(0, count($v) - 1);
        if ($isAssoc) return empty($v) ? [] : [$v];

        // list-array: keep only valid media objects (arrays)
        $out = [];
        foreach ($v as $item) {
            if (is_array($item) && !empty($item)) $out[] = $item;
            if (is_object($item)) {
                $a = (array)$item;
                if (!empty($a)) $out[] = $a;
            }
        }
        return $out;
    }

    // Anything scalar (string/int/etc) should NOT become a list
    return [];
};



$errorReply = static function (string $message, string $screenId = SCREEN_ERROR): array {
    return [
        'screen' => $screenId,
        'data'   => ['error' => $message],
    ];
};

// Allowed keys per screen (what each screen declares in its "data" model)
$ALLOWED_FIELDS = [
    SCREEN_KYC_ID       => [],
    SCREEN_KYC_SELFIE   => ['id_images'],                 // selfie screen receives ID images
    SCREEN_KYC_ADDRESS  => ['id_images', 'selfie_photo'], // address receives ID + selfie
    SCREEN_KYC_FUNDS    => ['id_images', 'selfie_photo', 'address_document'], // funds receives all
];

// ---------- DB helpers (using your env style) ----------
function db(string $dbHost, string $dbUser, string $dbPass, string $dbName): mysqli {
    $db = mysqli_connect($dbHost, $dbUser, $dbPass, $dbName);
    if (!$db) {
        throw new RuntimeException('DB connection failed: ' . mysqli_connect_error());
    }
    mysqli_set_charset($db, 'utf8mb4');
    return $db;
}

function findClientByPhone(mysqli $db, string $phone): ?array {
    $sql = "SELECT id, whatsapp_phone, kyc, kyc_status FROM hksh_AutoVest_clients WHERE whatsapp_phone = ? LIMIT 1";
    $stmt = $db->prepare($sql);
    if (!$stmt) throw new RuntimeException('DB prepare failed');
    $stmt->bind_param("s", $phone);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    return $row ?: null;
}

function updateClientKycStatus(mysqli $db, int $clientId, string $status, ?int $kycId = null): void {
    if ($kycId !== null) {
        $sql = "UPDATE hksh_AutoVest_clients
                SET kyc = ?, kyc_status = ?, kyc_updated_at = NOW()
                WHERE id = ? LIMIT 1";
        $stmt = $db->prepare($sql);
        if (!$stmt) throw new RuntimeException('DB prepare failed');
        $stmt->bind_param("isi", $kycId, $status, $clientId);
    } else {
        $sql = "UPDATE hksh_AutoVest_clients
                SET kyc_status = ?, kyc_updated_at = NOW()
                WHERE id = ? LIMIT 1";
        $stmt = $db->prepare($sql);
        if (!$stmt) throw new RuntimeException('DB prepare failed');
        $stmt->bind_param("si", $status, $clientId);
    }

    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        throw new RuntimeException("DB update failed: {$err}");
    }
    $stmt->close();
}

// ---------- Error middleware + JSON handlers ----------
$errorMiddleware = $app->addErrorMiddleware(true, true, true);

$errorMiddleware->setErrorHandler(
    HttpMethodNotAllowedException::class,
    function (Request $request, Throwable $exception) use ($app) {
        $allowed = method_exists($exception, 'getAllowedMethods') ? $exception->getAllowedMethods() : [];

        logRequest([
            'event'   => 'method_not_allowed',
            'path'    => $request->getUri()->getPath(),
            'allowed' => $allowed,
        ]);

        $res = $app->getResponseFactory()->createResponse(405);
        return jsend($res, 405, [
            'ok'      => false,
            'error'   => 'Method not allowed',
            'path'    => $request->getUri()->getPath(),
            'allowed' => $allowed,
            'hint'    => 'Use POST /data for WhatsApp Flows encrypted exchange. Use GET / for health.',
        ]);
    }
);

$errorMiddleware->setErrorHandler(
    HttpNotFoundException::class,
    function (Request $request) use ($app) {
        logRequest([
            'event' => 'not_found',
            'path'  => $request->getUri()->getPath(),
        ]);

        $res = $app->getResponseFactory()->createResponse(404);
        return jsend($res, 404, [
            'ok'    => false,
            'error' => 'Not found',
            'path'  => $request->getUri()->getPath(),
            'hint'  => 'Valid endpoints: GET /, POST /data, GET /data',
        ]);
    }
);

// ---------- handlers ----------
$healthHandler = function (Request $request, Response $response) {
    logRequest([
        'event' => 'health_check',
        'path'  => (string)$request->getUri()->getPath(),
    ]);

    return jsend($response, 200, [
        'ok'   => true,
        'msg'  => 'WA Flows endpoint alive',
        'time' => date('c'),
        'hint' => 'POST /data for encrypted exchange'
    ]);
};

/* ============================================================
 * ✅ DB capture helpers (two-table approach)
 * Requires tables: kyc_submissions + kyc_media (with UNIQUE(flow_token, media_role))
 * ============================================================ */

function phoneDigitsFromFlowToken(string $flowToken): string {
    if ($flowToken === '') return '';
    $parts = explode('_', $flowToken, 2);
    return preg_replace('/\D+/', '', $parts[0] ?? '') ?? '';
}

function extractMediaId(array $obj): ?string {
    foreach (['id','media_id','file_id','handle','h','mediaId'] as $k) {
        if (isset($obj[$k]) && is_string($obj[$k]) && $obj[$k] !== '') return $obj[$k];
    }
    return null;
}

function markSubmissionStarted(mysqli $db, string $flowToken, string $screen, string $action): void {
    $phoneDigits = phoneDigitsFromFlowToken($flowToken);

    $sql = "UPDATE kyc_submissions
            SET status='started',
                phone_digits=IF(phone_digits='', ?, phone_digits),
                current_screen=?,
                last_action=?,
                updated_at=NOW()
            WHERE flow_token=? LIMIT 1";

    $stmt = $db->prepare($sql);
    if (!$stmt) throw new RuntimeException('DB prepare failed: '.$db->error);
    $stmt->bind_param("ssss", $phoneDigits, $screen, $action, $flowToken);
    $stmt->execute();
    $stmt->close();
}

function markSubmissionAwaitingApproval(mysqli $db, string $flowToken, string $sourceOfFunds, string $screen, string $action): void {
    $phoneDigits = phoneDigitsFromFlowToken($flowToken);

    $sql = "UPDATE kyc_submissions
            SET status='awaiting_approval',
                source_of_funds=?,
                phone_digits=IF(phone_digits='', ?, phone_digits),
                current_screen=?,
                last_action=?,
                updated_at=NOW()
            WHERE flow_token=? LIMIT 1";

    $stmt = $db->prepare($sql);
    if (!$stmt) throw new RuntimeException('DB prepare failed: '.$db->error);
    $stmt->bind_param("sssss", $sourceOfFunds, $phoneDigits, $screen, $action, $flowToken);
    $stmt->execute();
    $stmt->close();
}

function upsertKycMedia(mysqli $db, string $flowToken, string $role, array $refObj): void {
    $waMediaId = extractMediaId($refObj);
    $refJson   = json_encode($refObj, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $sql = "INSERT INTO kyc_media (flow_token, media_role, wa_media_id, wa_media_ref, download_status)
            VALUES (?, ?, ?, CAST(? AS JSON), 'pending')
            ON DUPLICATE KEY UPDATE
              wa_media_id=COALESCE(VALUES(wa_media_id), wa_media_id),
              wa_media_ref=CAST(VALUES(wa_media_ref) AS JSON),
              updated_at=NOW()";

    $stmt = $db->prepare($sql);
    if (!$stmt) throw new RuntimeException('DB prepare failed: '.$db->error);
    $stmt->bind_param("ssss", $flowToken, $role, $waMediaId, $refJson);
    $stmt->execute();
    $stmt->close();
}

/* ============================================================
 * UPDATED exchange handler (captures media refs + source_of_funds)
 * ============================================================ */

$exchangeHandler = function (Request $request, Response $response) use (
    $eqi, $filterData, $normalizeMediaList, $errorReply, $ALLOWED_FIELDS,
    $dbHost, $dbUser, $dbPass, $dbName, $privateKeyPath
) {
    $db = null;

    try {
        if (in_array($request->getMethod(), ['GET','HEAD'], true)) {
            logRequest([
                'event' => 'data_health_check',
                'path'  => (string)$request->getUri()->getPath(),
            ]);

            return jsend($response, 200, [
                'ok'   => true,
                'msg'  => 'WA Flows /data endpoint alive',
                'time' => date('c'),
                'hint' => 'POST here for encrypted exchange'
            ]);
        }

        $body = $request->getParsedBody();
        if (!is_array($body)) {
            $raw = (string)$request->getBody();
            $body = json_decode($raw, true);
        }
        if (!is_array($body)) {
            logRequest(['event' => 'invalid_json_body']);
            return jsend($response, 400, ['ok'=>false,'error'=>'Invalid JSON body']);
        }

        logRequest([
            'event' => 'encrypted_payload_received',
            'keys'  => array_keys($body),
        ]);

        $pem = @file_get_contents($privateKeyPath);
        if ($pem === false || trim($pem) === '') {
            logRequest(['event' => 'private_key_not_readable', 'path' => $privateKeyPath]);
            return jsend($response, 500, ['ok'=>false,'error'=>'Private key not readable','path'=>$privateKeyPath]);
        }

        $dec = decryptRequest($body, $pem);
        $req = $dec['decryptedBody'];

        $version   = (string)($req['version'] ?? '');
        $action    = (string)($req['action']  ?? '');
        $screenId  = (string)($req['screen']  ?? '');
        $payload   = is_array($req['data'] ?? null) ? $req['data'] : [];
        $flowToken = (string)($req['flow_token'] ?? '');

        $waPhone = (string)($req['whatsapp_phone'] ?? $payload['whatsapp_phone'] ?? '');

        logRequest([
            'event'      => 'flow_token_seen',
            'flow_token' => ($flowToken !== '' ? $flowToken : null),
            'action'     => $action,
            'screen'     => $screenId,
            'has_phone'  => ($waPhone !== ''),
        ]);

        logRequest([
            'event'      => 'flow_request',
            'version'    => $version,
            'action'     => $action,
            'screen'     => $screenId,
            'has_data'   => isset($req['data']),
            'has_phone'  => ($waPhone !== ''),
            'has_token'  => ($flowToken !== ''),
        ]);

        // We open DB by flowToken (not phone), because has_phone is usually false.
        if ($flowToken !== '') {
            $db = db($dbHost, $dbUser, $dbPass, $dbName);
        }

        if ($version !== '3.0') {
            $reply = $errorReply('Unsupported version', SCREEN_ERROR);

        } elseif ($eqi($action, 'PING')) {
            $reply = ['data' => ['status' => 'active']];

        } elseif ($eqi($action, 'INIT')) {
            // Optional: mark screen/action, keep status as 'sent' until docs are uploaded
            if ($db && $flowToken !== '') {
                // keep it simple: don't change status, but track where they are
                $phoneDigits = phoneDigitsFromFlowToken($flowToken);
                $sql = "UPDATE kyc_submissions
                        SET phone_digits=IF(phone_digits='', ?, phone_digits),
                            current_screen=?,
                            last_action=?,
                            updated_at=NOW()
                        WHERE flow_token=? LIMIT 1";
                $stmt = $db->prepare($sql);
                if ($stmt) {
                    $stmt->bind_param("ssss", $phoneDigits, $screenId, $action, $flowToken);
                    $stmt->execute();
                    $stmt->close();
                }
            }

            $reply = [
                'screen' => SCREEN_KYC_ID,
                'data'   => new stdClass(),
            ];

        } elseif ($eqi($action, 'DATA_EXCHANGE')) {

            if ($screenId === SCREEN_KYC_ID) {
                $idImages = $normalizeMediaList($payload['id_images'] ?? []);

                logRequest([
                    'event'        => 'kyc_id_upload',
                    'screen'       => SCREEN_KYC_ID,
                    'id_image_cnt' => count($idImages),
                    'flow_token'   => ($flowToken !== '' ? $flowToken : null),
                ]);

                if (count($idImages) !== 2) {
                    $reply = $errorReply('Please upload exactly 2 ID photos (front and back).', SCREEN_KYC_ID);
                } else {

                    // SAVE SUBMITTED ID IMAGE REFS (front/back)
                    if ($db && $flowToken !== '') {
                        markSubmissionStarted($db, $flowToken, $screenId, $action);

                        if (!empty($idImages[0]) && is_array($idImages[0])) upsertKycMedia($db, $flowToken, 'ID_FRONT', $idImages[0]);
                        if (!empty($idImages[1]) && is_array($idImages[1])) upsertKycMedia($db, $flowToken, 'ID_BACK',  $idImages[1]);
                    }

                    $data = $filterData(['id_images' => $idImages], $ALLOWED_FIELDS[SCREEN_KYC_SELFIE]);

                    $reply = [
                        'screen' => SCREEN_KYC_SELFIE,
                        'data'   => $data,
                    ];
                }

            } elseif ($screenId === SCREEN_KYC_SELFIE) {
                $idImages    = $normalizeMediaList($payload['id_images'] ?? []);
                $selfiePhoto = $normalizeMediaList($payload['selfie_photo'] ?? []);

                logRequest([
                    'event'          => 'kyc_selfie_upload',
                    'screen'         => SCREEN_KYC_SELFIE,
                    'id_image_cnt'   => count($idImages),
                    'selfie_cnt'     => count($selfiePhoto),
                    'flow_token'     => ($flowToken !== '' ? $flowToken : null),
                ]);

                if (count($selfiePhoto) !== 1) {
                    $reply = $errorReply('Please upload exactly 1 selfie photo.', SCREEN_KYC_SELFIE);
                } else {

                    // SAVE SELFIE REF
                    if ($db && $flowToken !== '') {
                        markSubmissionStarted($db, $flowToken, $screenId, $action);

                        if (!empty($selfiePhoto[0]) && is_array($selfiePhoto[0])) {
                            upsertKycMedia($db, $flowToken, 'SELFIE', $selfiePhoto[0]);
                        }
                    }

                    // Pass forward id_images + selfie_photo to next screen
                    $data = $filterData(
                        ['id_images' => $idImages, 'selfie_photo' => $selfiePhoto],
                        $ALLOWED_FIELDS[SCREEN_KYC_ADDRESS]
                    );

                    $reply = [
                        'screen' => SCREEN_KYC_ADDRESS,
                        'data'   => $data,
                    ];
                }   
                        
            } elseif ($screenId === SCREEN_KYC_ADDRESS) {
                $idImages    = $normalizeMediaList($payload['id_images'] ?? []);
                $selfiePhoto = $normalizeMediaList($payload['selfie_photo'] ?? []);
                $addressDoc  = $normalizeMediaList($payload['address_document'] ?? []);

                logRequest([
                    'event'           => 'kyc_address_upload',
                    'screen'          => SCREEN_KYC_ADDRESS,
                    'id_image_cnt'    => count($idImages),
                    'selfie_cnt'      => count($selfiePhoto),
                    'address_doc_cnt' => count($addressDoc),
                    'flow_token'      => ($flowToken !== '' ? $flowToken : null),
                ]);

                // SAVE OPTIONAL ADDRESS DOC REF (if provided)
                if ($db && $flowToken !== '') {
                    markSubmissionStarted($db, $flowToken, $screenId, $action);

                    if (!empty($addressDoc[0]) && is_array($addressDoc[0])) {
                        upsertKycMedia($db, $flowToken, 'ADDRESS', $addressDoc[0]);
                    }
                }

                $data = $filterData(
                    [
                        'id_images' => $idImages,
                        'selfie_photo' => $selfiePhoto,
                        'address_document' => $addressDoc
                    ],
                    $ALLOWED_FIELDS[SCREEN_KYC_FUNDS]
                );

                $reply = [
                    'screen' => SCREEN_KYC_FUNDS,
                    'data'   => $data,
                ];

            } elseif ($screenId === SCREEN_KYC_FUNDS) {
                $idImages    = $normalizeMediaList($payload['id_images'] ?? []);
                $selfiePhoto = $normalizeMediaList($payload['selfie_photo'] ?? []);
                $addressDoc  = $normalizeMediaList($payload['address_document'] ?? []);
                $source      = $payload['source_of_funds'] ?? null;

                $sourceArr = is_array($source) ? $source : ($source ? [$source] : []);
                $selected  = (string)($sourceArr[0] ?? '');

                logRequest([
                    'event'           => 'kyc_funds_submit_attempt',
                    'screen'          => SCREEN_KYC_FUNDS,
                    'id_image_cnt'    => count($idImages),
                    'selfie_cnt'      => count($selfiePhoto),
                    'address_doc_cnt' => count($addressDoc),
                    'selected'        => $selected,
                    'flow_token'      => ($flowToken !== '' ? $flowToken : null),
                ]);

                // enforce selfie exists (required)
                if (count($selfiePhoto) !== 1) {
                    $reply = $errorReply('Please upload a selfie to continue.', SCREEN_KYC_FUNDS);

                } else {
                    $allowed = ['SALARY','BUSINESS','SAVINGS','FAMILY','OTHER'];
                    if ($selected === '' || !in_array($selected, $allowed, true)) {
                        $reply = $errorReply('Please select one source of funds.', SCREEN_KYC_FUNDS);

                    } else {
                        // SAVE SOURCE OF FUNDS + MOVE TO AWAITING APPROVAL
                        if ($db && $flowToken !== '') {
                            markSubmissionAwaitingApproval($db, $flowToken, $selected, $screenId, $action);
                        }

                        // MOVE TO FINAL SCREEN
                        $reply = [
                            'screen' => 'KYC_DONE',
                            'data'   => new stdClass(),
                        ];
                    }
                }
            } else {
                $reply = $errorReply('Unknown screen in exchange.', SCREEN_ERROR);
            }

        } else {
            $reply = $errorReply('Unknown action.', SCREEN_ERROR);
        }

        logRequest([
            'event'      => 'flow_response',
            'screen'     => $reply['screen'] ?? 'none',
            'keys'       => isset($reply['data']) ? array_keys((array)$reply['data']) : [],
            'flow_token' => ($flowToken !== '' ? $flowToken : null),
        ]);

        if ($db instanceof mysqli) $db->close();

        $b64 = encryptResponseGCM($reply, $dec['aesKeyBuffer'], $dec['initialVectorBuffer']);
        $response->getBody()->write($b64);

        return $response
            ->withHeader('Content-Type', 'text/plain')
            ->withHeader('Access-Control-Allow-Origin', '*');

    } catch (Throwable $e) {
        if ($db instanceof mysqli) $db->close();

        logRequest([
            'event'   => 'exception',
            'message' => $e->getMessage(),
            'file'    => $e->getFile(),
            'line'    => $e->getLine(),
        ]);

        error_log('[flows] '.$e->getMessage());
        return jsend($response, 500, ['ok'=>false,'error'=>'Unhandled exception','detail'=>$e->getMessage()]);
    }
};


// ---------- routes ----------
$app->map(['GET','HEAD'], '/', $healthHandler);

$app->post('/', function (Request $request, Response $response) {
    logRequest([
        'event' => 'root_post',
        'path'  => (string)$request->getUri()->getPath(),
    ]);

    return jsend($response, 200, [
        'ok'   => true,
        'msg'  => 'Root is health-only. For WhatsApp Flows encrypted exchange, POST to /data.',
        'time' => date('c'),
    ]);
});

$app->map(['GET','HEAD','POST'], '/data', $exchangeHandler);

$app->run();
