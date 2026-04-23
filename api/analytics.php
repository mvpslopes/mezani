<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

function env_or_empty(string $key): string {
  $v = getenv($key);
  return $v === false ? '' : trim((string)$v);
}

function b64url(string $data): string {
  return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function ga_service_account(): array {
  $jsonInline = env_or_empty('MEZANI_GA4_SERVICE_ACCOUNT_JSON');
  $jsonFile = env_or_empty('MEZANI_GA4_SERVICE_ACCOUNT_FILE');
  $checkedFiles = [];
  $addChecked = function(string $path) use (&$checkedFiles): void {
    if ($path !== '' && !in_array($path, $checkedFiles, true)) {
      $checkedFiles[] = $path;
    }
  };
  if ($jsonFile !== '') {
    $addChecked($jsonFile);
  }
  if ($jsonFile === '') {
    $defaultPath = __DIR__ . '/../secrets/ga4-service-account.json';
    $addChecked($defaultPath);
    $defaultFile = realpath($defaultPath);
    if (is_string($defaultFile) && $defaultFile !== '') {
      $jsonFile = $defaultFile;
      $addChecked($jsonFile);
    } else {
      $candidates = glob(__DIR__ . '/../secrets/*.json');
      if (is_array($candidates) && isset($candidates[0]) && is_string($candidates[0])) {
        $addChecked($candidates[0]);
        $resolved = realpath($candidates[0]);
        if (is_string($resolved) && $resolved !== '') {
          $jsonFile = $resolved;
          $addChecked($jsonFile);
        }
      }
    }
  }
  $raw = $jsonInline;
  if ($raw === '' && $jsonFile !== '' && is_file($jsonFile)) {
    $raw = (string)file_get_contents($jsonFile);
  }
  $cfg = json_decode($raw, true);
  if (is_array($cfg)) {
    $cfg['_debug_checked_files'] = $checkedFiles;
    $cfg['_debug_json_source'] = $jsonInline !== '' ? 'env_inline' : ($jsonFile !== '' ? 'file' : 'none');
    return $cfg;
  }
  return [
    '_debug_checked_files' => $checkedFiles,
    '_debug_json_source' => $jsonInline !== '' ? 'env_inline_invalid_json' : 'none',
  ];
}

function ga_access_token(array $sa): string {
  $tokenUri = (string)($sa['token_uri'] ?? 'https://oauth2.googleapis.com/token');
  $header = b64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
  $now = time();
  $payload = b64url(json_encode([
    'iss' => (string)$sa['client_email'],
    'scope' => 'https://www.googleapis.com/auth/analytics.readonly',
    'aud' => $tokenUri,
    'exp' => $now + 3600,
    'iat' => $now,
  ]));
  $toSign = $header . '.' . $payload;
  $privateKey = openssl_pkey_get_private((string)($sa['private_key'] ?? ''));
  if (!$privateKey) return '';
  $signature = '';
  $ok = openssl_sign($toSign, $signature, $privateKey, OPENSSL_ALGO_SHA256);
  openssl_pkey_free($privateKey);
  if (!$ok) return '';
  $jwt = $toSign . '.' . b64url($signature);
  $body = http_build_query([
    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
    'assertion' => $jwt,
  ]);
  $ctx = stream_context_create([
    'http' => [
      'method' => 'POST',
      'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
      'content' => $body,
      'timeout' => 20,
    ]
  ]);
  $res = @file_get_contents($tokenUri, false, $ctx);
  if ($res === false) return '';
  $data = json_decode($res, true);
  return is_array($data) ? (string)($data['access_token'] ?? '') : '';
}

function ga_run_report(string $accessToken, string $propertyId, array $dimensions, array $metrics, int $days, int $limit = 100): array {
  $url = "https://analyticsdata.googleapis.com/v1beta/properties/{$propertyId}:runReport";
  $payload = json_encode([
    'dateRanges' => [['startDate' => $days . 'daysAgo', 'endDate' => 'today']],
    'dimensions' => array_map(fn($d) => ['name' => $d], $dimensions),
    'metrics' => array_map(fn($m) => ['name' => $m], $metrics),
    'limit' => $limit,
  ]);
  $ctx = stream_context_create([
    'http' => [
      'method' => 'POST',
      'header' => "Content-Type: application/json\r\nAuthorization: Bearer {$accessToken}\r\n",
      'content' => $payload,
      'timeout' => 25,
    ]
  ]);
  $res = @file_get_contents($url, false, $ctx);
  if ($res === false) return [];
  $data = json_decode($res, true);
  return is_array($data) ? $data : [];
}

function pick_metric(array $report, int $idx): float {
  $v = $report['totals'][0]['metricValues'][$idx]['value'] ?? '0';
  return (float)$v;
}

require_auth();
$propertyId = env_or_empty('MEZANI_GA4_PROPERTY_ID');
if ($propertyId === '') {
  $propertyId = '534167480';
}
$days = isset($_GET['days']) ? (int)$_GET['days'] : 1;
if (!in_array($days, [1, 7, 30, 90, 3650], true)) $days = 1;

$sa = ga_service_account();
if (!$sa || empty($sa['client_email']) || empty($sa['private_key'])) {
  respond([
    'ok' => false,
    'message' => 'Configure credenciais GA4 (service account).',
    'debug' => [
      'checkedFiles' => $sa['_debug_checked_files'] ?? [],
      'jsonSource' => $sa['_debug_json_source'] ?? 'none',
    ],
  ], 422);
}
$token = ga_access_token($sa);
if ($token === '') {
  respond(['ok' => false, 'message' => 'Nao foi possivel autenticar no Google Analytics.'], 502);
}

$summary = ga_run_report(
  $token,
  $propertyId,
  [],
  ['activeUsers', 'sessions', 'screenPageViews', 'eventCount', 'userEngagementDuration', 'bounceRate', 'averageSessionDuration', 'sessionsPerUser'],
  $days,
  1
);
$timeline = ga_run_report($token, $propertyId, ['date'], ['activeUsers'], $days, 120);
$topPages = ga_run_report($token, $propertyId, ['pagePath'], ['screenPageViews', 'averageSessionDuration'], $days, 20);
$devices = ga_run_report($token, $propertyId, ['deviceCategory'], ['activeUsers'], $days, 10);
$browsers = ga_run_report($token, $propertyId, ['browser'], ['activeUsers'], $days, 10);
$os = ga_run_report($token, $propertyId, ['operatingSystem'], ['activeUsers'], $days, 10);
$countries = ga_run_report($token, $propertyId, ['country'], ['activeUsers', 'screenPageViews'], $days, 10);
$cities = ga_run_report($token, $propertyId, ['city', 'country'], ['sessions', 'screenPageViews'], $days, 20);
$traffic = ga_run_report($token, $propertyId, ['sessionDefaultChannelGroup'], ['sessions'], $days, 10);
$hourly = ga_run_report($token, $propertyId, ['hour'], ['activeUsers'], $days, 24);
$weekday = ga_run_report($token, $propertyId, ['dayOfWeek'], ['activeUsers'], $days, 7);
$realtime = ga_run_report($token, $propertyId, [], ['activeUsers'], 1, 1);

respond([
  'ok' => true,
  'periodDays' => $days,
  'generatedAt' => date('c'),
  'summary' => [
    'activeUsers' => pick_metric($summary, 0),
    'sessions' => pick_metric($summary, 1),
    'pageViews' => pick_metric($summary, 2),
    'eventCount' => pick_metric($summary, 3),
    'engagementSeconds' => pick_metric($summary, 4),
    'bounceRate' => pick_metric($summary, 5),
    'avgSessionDuration' => pick_metric($summary, 6),
    'sessionsPerUser' => pick_metric($summary, 7),
    'onlineNow' => pick_metric($realtime, 0),
  ],
  'timeline' => $timeline['rows'] ?? [],
  'topPages' => $topPages['rows'] ?? [],
  'devices' => $devices['rows'] ?? [],
  'browsers' => $browsers['rows'] ?? [],
  'operatingSystems' => $os['rows'] ?? [],
  'countries' => $countries['rows'] ?? [],
  'cities' => $cities['rows'] ?? [],
  'trafficSource' => $traffic['rows'] ?? [],
  'hourly' => $hourly['rows'] ?? [],
  'weekday' => $weekday['rows'] ?? [],
]);

