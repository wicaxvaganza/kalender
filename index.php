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
// HELPER: LOAD DARI FILE JSON
// ==========================
function loadLiburFromFile(string $filePath, int $year, int $month, string &$errMsg): array {
  if (!is_readable($filePath)) {
    $errMsg = 'File hari libur tidak ditemukan/ tidak bisa dibaca: ' . basename($filePath);
    return [];
  }

  $raw = @file_get_contents($filePath);
  if ($raw === false || trim($raw) === '') {
    $errMsg = 'Gagal membaca file hari libur: ' . basename($filePath);
    return [];
  }

  $decoded = json_decode($raw, true);
  if (!is_array($decoded)) {
    $errMsg = 'Format JSON hari libur (file) tidak valid.';
    return [];
  }

  $ym = sprintf('%04d-%02d', $year, $month);

  // Filter: hanya event yang sesuai bulan & tahun sekarang
  $filtered = array_values(array_filter($decoded, function($ev) use ($ym) {
    $date = $ev['event_date'] ?? '';
    return is_string($date) && substr($date, 0, 7) === $ym;
  }));

  // Sort by tanggal
  usort($filtered, function($a, $b) {
    return strcmp($a['event_date'] ?? '', $b['event_date'] ?? '');
  });

  $errMsg = ''; // sukses
  return $filtered;
}

// ==========================
// 1) API HARI LIBUR (dengan fallback ke 2026.json)
// ==========================
$url = "https://hari-libur-api.vercel.app/api?month={$month}&year={$year}";
$options = [
  'http' => [
    'method'  => 'GET',
    'timeout' => 5,
  ]
];
$context = stream_context_create($options);
$json    = @file_get_contents($url, false, $context);

$apiOk = false;
if ($json !== false && trim($json) !== '') {
  $decoded = json_decode($json, true);
  if (is_array($decoded) && count($decoded) > 0) {
    $events = $decoded;
    $apiOk  = true;
    $liburSource = 'API Hari Libur';
  }
}

if (!$apiOk) {
  // Fallback ke file lokal (disarankan untuk 2026.json)
  $localLiburFile = __DIR__ . '/2026.json';
  $fileErr = '';
  $eventsFromFile = loadLiburFromFile($localLiburFile, $year, $month, $fileErr);

  if (!empty($eventsFromFile)) {
    $events = $eventsFromFile;
    $errorLibur = ''; // sukses via file
    $liburSource = 'File 2026';
  } else {
    // kalau file juga gagal
    $errorLibur = $fileErr ?: 'Gagal memuat data hari libur (API & file).';
    $liburSource = 'Tidak tersedia';
  }
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
$bgFile = 'rsbl.jpeg'; // pastikan file ada 1 folder dengan index.php
$bgPath = __DIR__ . '/' . $bgFile;
$bgVer  = file_exists($bgPath) ? filemtime($bgPath) : time();
$bgUrl  = $bgFile . '?v=' . (int)$bgVer;

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
<body class="<?= $isTvMode ? 'display-tv' : '' ?>" style="--bg-image: url('<?= htmlspecialchars($bgUrl, ENT_QUOTES) ?>');">

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
