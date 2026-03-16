<?php
// Pastikan zona waktu sesuai
date_default_timezone_set('Asia/Jakarta');

// Hitung bulan & tahun saat ini (WIB)
$now      = new DateTime('now', new DateTimeZone('Asia/Jakarta'));
$year     = (int) $now->format('Y');
$month    = (int) $now->format('n'); // 1-12
$todayStr = $now->format('Y-m-d');

$events       = [];
$errorLibur   = '';
$todayEvents  = [];
$tickerEvents = [];
$liburSource  = 'API Hari Libur';
$debugMode = isset($_GET['debug']) && $_GET['debug'] === '1';
$isTvMode = isset($_GET['display']) && strtolower((string) $_GET['display']) === 'tv';

// ==========================
// 1) API HARI LIBUR
// ==========================
$fetchJson = function (string $url, int $timeout = 8): ?string {
  $opts = [
    'http' => ['method' => 'GET', 'timeout' => $timeout],
    'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
  ];
  $ctx = stream_context_create($opts);
  $raw = @file_get_contents($url, false, $ctx);
  if ($raw !== false && trim($raw) !== '') return $raw;

  // Fallback untuk environment lokal yang belum punya CA bundle.
  $insecureCtx = stream_context_create([
    'http' => ['method' => 'GET', 'timeout' => $timeout],
    'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
  ]);
  $rawInsecure = @file_get_contents($url, false, $insecureCtx);
  if ($rawInsecure !== false && trim($rawInsecure) !== '') return $rawInsecure;

  if (function_exists('curl_init')) {
    $request = function (bool $insecure = false) use ($url, $timeout): ?string {
      $ch = curl_init($url);
      $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_USERAGENT => 'Kalender/1.0',
      ];
      if ($insecure) {
        $opts[CURLOPT_SSL_VERIFYPEER] = false;
        $opts[CURLOPT_SSL_VERIFYHOST] = 0;
      }
      curl_setopt_array($ch, $opts);
      $resp = curl_exec($ch);
      $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
      curl_close($ch);
      if ($resp !== false && $code >= 200 && $code < 300 && trim($resp) !== '') {
        return $resp;
      }
      return null;
    };

    $resp = $request(false);
    if ($resp !== null) return $resp;

    $respInsecure = $request(true);
    if ($respInsecure !== null) return $respInsecure;
  }

  return null;
};

$apiOk = false;

// Sumber 1: API Hari Libur (jika available)
$url = "https://hari-libur-api.vercel.app/api?month={$month}&year={$year}";
$json = $fetchJson($url);
if ($json !== null) {
  $decoded = json_decode($json, true);
  if (is_array($decoded) && count($decoded) > 0) {
    $events = $decoded;
    $apiOk  = true;
    $liburSource = 'API Hari Libur';
  }
}

// Sumber 2 (fallback API): Nager public holidays, lalu filter per bulan
if (!$apiOk) {
  $nagerUrl = "https://date.nager.at/api/v3/PublicHolidays/{$year}/ID";
  $nagerJson = $fetchJson($nagerUrl);
  if ($nagerJson !== null) {
    $nagerDecoded = json_decode($nagerJson, true);
    if (is_array($nagerDecoded) && !empty($nagerDecoded)) {
      $mapped = [];
      foreach ($nagerDecoded as $item) {
        if (!is_array($item)) continue;
        $date = $item['date'] ?? '';
        if (!is_string($date) || strlen($date) < 10) continue;
        if ((int)substr($date, 5, 2) !== $month) continue;

        $mapped[] = [
          'event_date' => $date,
          'event_name' => $item['localName'] ?? ($item['name'] ?? 'Hari libur'),
          'is_national_holiday' => true,
        ];
      }

      if (!empty($mapped)) {
        $events = $mapped;
        $apiOk = true;
        $liburSource = 'Nager Public Holidays API';
      }
    }
  }
}

if (!$apiOk) {
  $errorLibur = 'Gagal memuat data hari libur dari API.';
  $liburSource = 'Tidak tersedia';
}

// Pisahkan today / upcoming (kalau events berhasil didapat)
if (!$errorLibur && !empty($events)) {
  // sort by date (jaga-jaga kalau sumber API)
  usort($events, function($a, $b) {
    return strcmp($a['event_date'] ?? '', $b['event_date'] ?? '');
  });

  foreach ($events as $ev) {
    $date = $ev['event_date'] ?? null;
    if (!$date) continue;

    if ($date < $todayStr) {
      continue;
    } elseif ($date === $todayStr) {
      $todayEvents[] = $ev;
    } else {
      $tickerEvents[] = $ev;
    }
  }
}

// siapkan data untuk JS ticker
$tickerEventsJs = [];
foreach ($tickerEvents as $ev) {
  $tickerEventsJs[] = [
    'event_date'          => $ev['event_date'] ?? '',
    'event_name'          => $ev['event_name'] ?? 'Hari besar',
    'is_national_holiday' => !empty($ev['is_national_holiday']),
  ];
}
$hasUpcoming = !empty($todayEvents) || !empty($tickerEvents);

// ==========================
// 2) API BMKG PRAKIRAAN CUACA (adm4 = 35.10.16.1010)
// ==========================
$bmkgForecastFlat = [];
$bmkgError        = '';

$bmkgUrl  = "https://api.bmkg.go.id/publik/prakiraan-cuaca?adm4=35.10.16.1010";
$bmkgJson = @file_get_contents($bmkgUrl);

if ($bmkgJson === false) {
  $bmkgError = 'Gagal memuat data cuaca BMKG.';
} else {
  $bmkgDecoded = json_decode($bmkgJson, true);
  if (!is_array($bmkgDecoded)) {
    $bmkgError = 'Format data cuaca BMKG tidak valid.';
  } else {
    if (isset($bmkgDecoded['data'][0]['cuaca']) && is_array($bmkgDecoded['data'][0]['cuaca'])) {
      foreach ($bmkgDecoded['data'][0]['cuaca'] as $daily) {
        if (!is_array($daily)) continue;
        foreach ($daily as $item) {
          if (is_array($item)) {
            $bmkgForecastFlat[] = $item;
          }
        }
      }
      usort($bmkgForecastFlat, function ($a, $b) {
        return strcmp($a['local_datetime'] ?? '', $b['local_datetime'] ?? '');
      });
    } else {
      $bmkgError = 'Struktur data prakiraan BMKG tidak ditemukan.';
    }
  }
}

// ==========================
// BACKGROUND FILE (cache-busting)
// ==========================
$bgCandidates = [
  'rsbl.jpeg',
  'rsbl.jpg',
  'rsbl.png',
  'background.jpg',
  'background.jpeg',
  'background.png',
  'assets/rsbl.jpeg',
  'assets/rsbl.jpg',
  'assets/rsbl.png',
];
$bgRelativePath = '';

foreach ($bgCandidates as $candidate) {
  if (is_file(__DIR__ . '/' . $candidate)) {
    $bgRelativePath = $candidate;
    break;
  }
}

if ($bgRelativePath === '') {
  $globHits = glob(__DIR__ . '/*.{jpg,jpeg,png,webp,JPG,JPEG,PNG,WEBP}', GLOB_BRACE);
  if (is_array($globHits) && !empty($globHits)) {
    $bgRelativePath = basename($globHits[0]);
  }
}

$bgUrl = '';
if ($bgRelativePath !== '') {
  $bgPath = __DIR__ . '/' . $bgRelativePath;
  $bgVer  = file_exists($bgPath) ? filemtime($bgPath) : time();
  $bgUrl  = $bgRelativePath . '?v=' . (int)$bgVer;
}
$bgCssValue = $bgUrl !== '' ? "url('{$bgUrl}')" : 'none';

$mainCssPath = __DIR__ . '/assets/css/main.css';
$tvCssPath   = __DIR__ . '/assets/css/tv.css';
$appJsPath   = __DIR__ . '/assets/js/app.js';
$mainCssVer  = file_exists($mainCssPath) ? filemtime($mainCssPath) : time();
$tvCssVer    = file_exists($tvCssPath) ? filemtime($tvCssPath) : time();
$appJsVer    = file_exists($appJsPath) ? filemtime($appJsPath) : time();
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<title>Jam Digital</title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@600;700;800;900&family=Roboto+Mono:wght@400;500;700&display=swap" rel="stylesheet">

<link rel="stylesheet" href="assets/css/main.css?v=<?= (int)$mainCssVer ?>">
<?php if ($isTvMode): ?>
<link rel="stylesheet" href="assets/css/tv.css?v=<?= (int)$tvCssVer ?>">
<?php endif; ?>
</head>
<body class="<?= $isTvMode ? 'display-tv' : '' ?>" style="--bg-image: <?= htmlspecialchars($bgCssValue, ENT_QUOTES) ?>;">

<div class="wrapper">
  <div class="clock-card">
    <div class="dashboard-grid">
      <div class="panel left-panel">
        <!-- CUACA -->
        <div class="weather-badge" id="weatherBadge">
        <div class="weather-city">Cuaca Sekitar RSUD Blambangan</div>
        <div class="weather-main">
          <span class="weather-emoji" id="weatherEmoji">⛅</span>
          <span class="weather-temp" id="weatherTemp">--°C</span>
          <span class="weather-separator">•</span>
          <span class="weather-desc" id="weatherDesc">Memuat cuaca...</span>
        </div>

        <div class="weather-updated" id="weatherUpdated">Sumber: BMKG</div>

        <div class="weather-next-hour" id="weatherNextHour">
          <span class="weather-next-hour-label">PRAKIRAAN BERIKUTNYA</span>
          <span class="weather-next-hour-value" id="weatherNextHourValue">Memuat ramalan...</span>
        </div>
        </div>
      </div>

      <div class="panel center-panel">
        <div class="clock-main">
          <div class="time-display" id="timeDisplay">
            00<span class="time-separator">:</span>00:00
          </div>

          <div class="date-main" id="dateMain"></div>
          <div class="day-progress" id="dayProgress"></div>

          <div class="holiday-section">
            <div class="holiday-title">HARI LIBUR &amp; HARI BESAR BULAN INI</div>
            <div class="holiday-source">Sumber: <?= htmlspecialchars($liburSource) ?></div>

            <?php if ($errorLibur): ?>
              <div class="holiday-error"><?= htmlspecialchars($errorLibur) ?></div>
            <?php elseif (!$hasUpcoming): ?>
              <div class="holiday-empty">Tidak ada hari besar tersisa di bulan ini.</div>
            <?php else: ?>

              <?php if (!empty($todayEvents)): ?>
                <div class="today-event-section">
                  <?php foreach ($todayEvents as $item): ?>
                    <div class="today-event-item">
                      <?= htmlspecialchars($item['event_name'] ?? 'Hari besar') ?>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>

              <div class="ticker-section" id="tickerSection" <?php if (empty($tickerEvents)) echo 'style="display:none"'; ?>>
                <div class="ticker-wrapper">
                  <div class="ticker-item" id="tickerItem"></div>
                </div>
              </div>

            <?php endif; ?>
          </div>
        </div>
      </div>

      <div class="panel right-panel">
        <!-- JADWAL SHOLAT -->
        <div class="prayer-badge">
          <div class="prayer-title">JADWAL SHOLAT ~ KAB. BANYUWANGI</div>
          <div class="prayer-list" id="prayerList">
            <div class="prayer-empty">Memuat jadwal sholat...</div>
          </div>
          <div class="prayer-badge-small" id="prayerInfo">
            Sumber: API MyQuran (Kemenag RI)
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ===== Theme Tester Helper (auto-hide) ===== -->
<?php if ($debugMode): ?>
<div class="theme-tester" id="themeTester">
  <div class="tt-title">
    <span>TEST TEMA</span>
    <span class="tt-status" id="themeTesterStatus">AUTO</span>
  </div>
  <div class="tt-grid">
    <button type="button" data-day="1">Sen</button>
    <button type="button" data-day="2">Sel</button>
    <button type="button" data-day="3">Rab</button>
    <button type="button" data-day="4">Kam</button>
    <button type="button" data-day="5">Jum</button>
    <button type="button" data-day="6">Sab</button>
    <button type="button" data-day="0">Min</button>
    <button type="button" class="tt-auto" data-auto="1">AUTO (WIB)</button>
  </div>
</div>
<?php endif; ?>

<script>
window.APP_DATA = {
  bgUrl: <?= json_encode($bgUrl, JSON_UNESCAPED_UNICODE) ?>,
  tickerEvents: <?= json_encode($tickerEventsJs, JSON_UNESCAPED_UNICODE) ?>,
  bmkgForecast: <?= json_encode($bmkgForecastFlat, JSON_UNESCAPED_UNICODE) ?>,
  bmkgError: <?= json_encode($bmkgError, JSON_UNESCAPED_UNICODE) ?>
};
</script>
<script src="assets/js/app.js?v=<?= (int)$appJsVer ?>"></script>

</body>
</html>
