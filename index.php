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
$liburSource  = 'File 2026.json';
$debugMode = isset($_GET['debug']) && $_GET['debug'] === '1';
$isTvMode = isset($_GET['display']) && strtolower((string) $_GET['display']) === 'tv';

// ==========================
// 1) HARI LIBUR DARI FILE 2026.JSON
// ==========================
$liburFile = __DIR__ . '/2026.json';

if (!is_readable($liburFile)) {
  $errorLibur = 'File hari libur tidak ditemukan/ tidak bisa dibaca: 2026.json';
  $liburSource = 'Tidak tersedia';
} else {
  $rawLibur = @file_get_contents($liburFile);
  $decodedLibur = json_decode((string)$rawLibur, true);

  if (!is_array($decodedLibur)) {
    $errorLibur = 'Format 2026.json tidak valid.';
    $liburSource = 'Tidak tersedia';
  } else {
    $ym = sprintf('%04d-%02d', $year, $month);
    $events = array_values(array_filter($decodedLibur, function($ev) use ($ym) {
      $date = $ev['event_date'] ?? '';
      return is_string($date) && substr($date, 0, 7) === $ym;
    }));

    if (empty($events)) {
      $errorLibur = 'Data hari libur untuk bulan ini tidak ditemukan di 2026.json.';
    }
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
$bgFile = file_exists(__DIR__ . '/assets/rsbl.jpeg') ? 'assets/rsbl.jpeg' : 'rsbl.jpeg';
$bgPath = __DIR__ . '/' . $bgFile;
$bgVer  = file_exists($bgPath) ? filemtime($bgPath) : time();
$bgUrl  = $bgFile . '?v=' . (int)$bgVer;
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<title>Jam Digital</title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@600;700;800;900&family=Roboto+Mono:wght@400;500;700&display=swap" rel="stylesheet">

<style>
  :root{
    /* Default (Jumat = biru). Akan dioverride JS sesuai hari. */
    --accent-rgb: 59,130,246;
    --accent2-rgb: 30,64,175;
    --overlay-mid: 0.08;
    --overlay-end: 0.14;

    /* Keterbacaan */
    --text-main: #ffffff;
    --text-muted: rgba(255,255,255,.82);
    --text-soft: rgba(255,255,255,.68);

    /* GLASS (lebih tembus background) */
    --panel-bg: rgba(255,255,255, 0.03);
    --panel-border: rgba(255,255,255, 0.18);

    --badge-bg: rgba(255,255,255, 0.04);
    --badge-border: rgba(255,255,255, 0.16);

    /* ===== OUTLINE TEXT (BARU) ===== */
    --outline-color: rgba(0,0,0,.92);
  }

  * { box-sizing: border-box; margin: 0; padding: 0; }
  html, body { height: 100%; }

  /* =========================
     BACKGROUND FOTO (lebih terang)
     ========================= */
  body {
    font-family: 'Roboto Mono', system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
    color: var(--text-main);
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
    position: relative;
    background: transparent;
    isolation: isolate;
  }

  /* Layer gambar */
  body::before{
    content: "";
    position: fixed;
    inset: 0;

    background-color: #000;
    background-image: url("<?= htmlspecialchars($bgUrl) ?>");
    background-size: cover;
    background-position: center;
    background-repeat: no-repeat;
    background-attachment: fixed;

    /* LEBIH TERANG + blur kecil supaya detail foto tetap keliatan */
    filter: blur(0.4px) brightness(1.12) contrast(1.03) saturate(1.08);
    transform: scale(1.02);
    z-index: -2;
  }

  /* Overlay tipis saja (biar foto tetap muncul) */
  body::after{
    content:"";
    position: fixed;
    inset: 0;
    background:
      radial-gradient(circle at top,
        rgba(var(--accent-rgb), 0.06) 0%,
        rgba(0, 0, 0, var(--overlay-mid)) 55%,
        rgba(0, 0, 0, var(--overlay-end)) 100%
      );
    z-index: -1;
  }

  .wrapper {
    width: 100%;
    height: 100%;
    padding: clamp(10px, 2vh, 28px) clamp(16px, 2.2vw, 28px);
    display: flex;
    align-items: center;
    justify-content: center;
    position: relative;
    z-index: 0;
  }

  /* =========================
     CARD UTAMA (SUPER TEMBUS)
     ========================= */
  .clock-card {
    position: relative;
    border-radius: 32px;
    padding: clamp(18px, 2.6vh, 32px) clamp(20px, 2.8vw, 40px) clamp(22px, 3vh, 40px);

    background: var(--panel-bg);
    border: 1px solid var(--panel-border);

    /* blur kecil biar background kelihatan */
    backdrop-filter: blur(6px) saturate(1.25);

    box-shadow:
      0 18px 70px rgba(0,0,0,0.25),
      0 0 26px rgba(var(--accent-rgb),0.10);

    width: 100%;
    max-width: 1400px;

    text-shadow: 0 2px 6px rgba(0,0,0,0.28);
  }

  /* fog tipis supaya teks tetap kebaca tanpa menggelapkan */
  .clock-card::before{
    content:"";
    position:absolute;
    inset:0;
    border-radius: 32px;
    pointer-events:none;
    background: linear-gradient(
      180deg,
      rgba(0,0,0,0.08),
      rgba(0,0,0,0.10)
    );
  }
  .clock-card > * { position: relative; z-index: 1; }

  .dashboard-grid {
    display: grid;
    grid-template-columns: minmax(280px, 1fr) minmax(280px, 1fr);
    grid-template-areas:
      "left right"
      "center center";
    gap: 24px;
    align-items: stretch;
  }
  .panel { min-width: 0; }
  .center-panel { display: flex; align-items: center; justify-content: center; }
  .left-panel { grid-area: left; justify-self: start; width: 100%; max-width: 420px; }
  .right-panel { grid-area: right; justify-self: end; width: 100%; max-width: 420px; }
  .center-panel { grid-area: center; }

  /* =========================
     BADGES (lebih tembus)
     ========================= */
  .weather-badge, .prayer-badge{
    padding: clamp(10px, 1.6vh, 12px) clamp(14px, 1.8vw, 18px);
    border-radius: 20px;
    background: var(--badge-bg);
    border: 1px solid var(--badge-border);
    backdrop-filter: blur(6px) saturate(1.2);
    box-shadow: 0 10px 35px rgba(0,0,0,0.18);
  }

  .weather-badge {
    display: flex;
    flex-direction: column;
    gap: 6px;
    min-width: 0;
    max-width: 100%;
  }
  .weather-city {
    font-size: 14px;
    letter-spacing: 0.18em;
    text-transform: uppercase;
    color: var(--text-muted);
    opacity: 0.95;
  }
  .weather-main {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-top: 6px;
  }
  .weather-emoji { font-size: 32px; line-height: 1; }
  .weather-temp {
    font-size: clamp(24px, 2.4vw, 36px);
    font-weight: 800;
    color: #fff;
    text-shadow: 0 8px 28px rgba(0,0,0,0.35);
  }
  .weather-separator { opacity: 0.8; font-size: 18px; color: var(--text-soft); }
  .weather-desc { font-size: clamp(14px, 1.4vw, 20px); color: var(--text-main); font-weight: 600; }
  .weather-updated { margin-top: 4px; font-size: 13px; color: var(--text-soft); }

  .weather-next-hour {
    margin-top: 8px;
    padding-top: 8px;
    border-top: 1px dashed rgba(255,255,255,0.22);
    font-size: 13px;
    color: var(--text-muted);
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    align-items: baseline;
  }
  .weather-next-hour-label {
    text-transform: uppercase;
    letter-spacing: 0.16em;
    font-size: 11px;
    color: var(--text-soft);
  }
  .weather-next-hour-value { font-size: 13px; font-weight: 700; color: var(--text-main); }

  .prayer-badge {
    min-width: 0;
    max-width: 100%;
    display: flex;
    flex-direction: column;
    gap: 8px;
  }
  .prayer-title {
    font-size: 14px;
    letter-spacing: 0.18em;
    text-transform: uppercase;
    color: var(--text-muted);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }
  .prayer-list { display: flex; flex-direction: column; gap: 4px; margin-top: 4px; }
  .prayer-row { 
  display: flex; 
  align-items: baseline; 
  justify-content: space-between; 
  gap: 8px; 
  font-size: clamp(16px, 2vh, 19px);
}

.prayer-name { 
  font-weight: 800; 
  color: var(--text-main); 
}

.prayer-time { 
  font-variant-numeric: tabular-nums; 
  color: var(--text-main); 
  font-weight: 800; 
}

  .prayer-badge-small { font-size: 11px; color: var(--text-soft); margin-top: 4px; }
  .prayer-current { color: #bbf7d0; text-shadow: 0 0 18px rgba(34,197,94,.22); }
  .prayer-empty { font-size: 12px; color: var(--text-main); }

  .clock-main {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: clamp(8px, 1.6vh, 16px);
    text-align: center;
    padding: 0;
  }

  /* =========================
     JAM & TANGGAL: OUTLINE HITAM (BARU)
     ========================= */

  .time-display {
    font-size: clamp(68px, 12.8vh, 132px);
    font-weight: 900;
    letter-spacing: 0.08em;
    font-family: 'Orbitron', 'Roboto Mono', system-ui, sans-serif;
    color: #fff;
    white-space: nowrap;

    /* fallback outline pakai text-shadow (untuk browser non-stroke) */
    --ol: 0.035em;
    text-shadow:
      calc(var(--ol) * -1) 0 0 var(--outline-color),
      var(--ol) 0 0 var(--outline-color),
      0 calc(var(--ol) * -1) 0 var(--outline-color),
      0 var(--ol) 0 var(--outline-color),
      calc(var(--ol) * -1) calc(var(--ol) * -1) 0 var(--outline-color),
      var(--ol) calc(var(--ol) * -1) 0 var(--outline-color),
      calc(var(--ol) * -1) var(--ol) 0 var(--outline-color),
      var(--ol) var(--ol) 0 var(--outline-color),

      /* shadow asli kamu (tetap) */
      0 12px 50px rgba(0,0,0,.45),
      0 0 30px rgba(var(--accent-rgb),.25);

    -webkit-font-smoothing: antialiased;
    text-rendering: geometricPrecision;
  }

  .time-separator { opacity: 1; }
  .time-display.tick { animation: secondPulse 0.28s ease-out; }
  @keyframes secondPulse {
    0% { transform: translateY(0); filter: brightness(1); }
    50% { transform: translateY(-2px); filter: brightness(1.12); }
    100% { transform: translateY(0); filter: brightness(1); }
  }

  .date-main {
    font-size: clamp(32px, 5.2vh, 58px);
    font-weight: 900;
    color: #fff;
    letter-spacing: 0.08em;

    /* fallback outline pakai text-shadow */
    --ol: 0.06em;
    text-shadow:
      calc(var(--ol) * -1) 0 0 var(--outline-color),
      var(--ol) 0 0 var(--outline-color),
      0 calc(var(--ol) * -1) 0 var(--outline-color),
      0 var(--ol) 0 var(--outline-color),
      calc(var(--ol) * -1) calc(var(--ol) * -1) 0 var(--outline-color),
      var(--ol) calc(var(--ol) * -1) 0 var(--outline-color),
      calc(var(--ol) * -1) var(--ol) 0 var(--outline-color),
      var(--ol) var(--ol) 0 var(--outline-color),

      /* shadow asli kamu (tetap) */
      0 16px 44px rgba(0,0,0,.48),
      0 0 22px rgba(0,0,0,.38);

    -webkit-font-smoothing: antialiased;
    text-rendering: geometricPrecision;
  }

  .day-progress {
    margin-top: 4px;
    font-size: clamp(16px, 2.6vh, 28px);
    font-weight: 900;
    color: #f8fafc;
    letter-spacing: 0.04em;
    line-height: 1.15;
    padding: 6px 12px;
    border-radius: 14px;
    border: 1px solid rgba(255,255,255,0.35);
    background: rgba(0,0,0,0.40);
    text-shadow: 0 8px 24px rgba(0,0,0,0.55);
  }

  /* Kalau browser support stroke, pakai outline beneran mengikuti bentuk font */
  @supports (-webkit-text-stroke: 1px black) {
    .time-display{
      -webkit-text-stroke: 0.028em var(--outline-color);
      /* hilangkan outline shadow (biar tidak dobel tebal), sisakan glow/drop */
      text-shadow:
        0 12px 50px rgba(0,0,0,.45),
        0 0 30px rgba(var(--accent-rgb),.25);
    }
    .date-main{
      -webkit-text-stroke: 0.060em var(--outline-color);
      text-shadow:
        0 16px 44px rgba(0,0,0,.48),
        0 0 22px rgba(0,0,0,.38);
    }
  }

  .holiday-section { margin-top: clamp(12px, 1.8vh, 22px); width: 100%; max-width: 760px; }
  .holiday-title {
    font-size: clamp(20px, 2vw, 30px);
    text-transform: uppercase;
    letter-spacing: 0.12em;
    color: #f8fafc;
    margin-bottom: 4px;
    font-weight: 800;
    text-shadow: 0 8px 22px rgba(0,0,0,0.55);
  }
  .holiday-source{
    margin-top: 6px;
    font-size: clamp(14px, 1.4vw, 20px);
    color: rgba(255,255,255,0.94);
    letter-spacing: .08em;
    text-transform: uppercase;
    opacity: 1;
    text-shadow: 0 6px 18px rgba(0,0,0,0.5);
  }
  .today-event-section { margin-top: 6px; margin-bottom: 12px; }
  .today-event-item {
    font-size: clamp(20px, 3vh, 30px);
    font-weight: 900;
    color: #fde68a;
    text-shadow: 0 12px 32px rgba(0,0,0,.35);
  }
  .holiday-empty, .holiday-error { font-size: 14px; color: var(--text-muted); }
  .holiday-error { color: #fecaca; }

  .ticker-section { margin-top: 10px; display: none; }
  .ticker-wrapper {
    position: relative;
    height: clamp(56px, 8vh, 70px);
    overflow: hidden;
    display: flex;
    align-items: flex-end;
    justify-content: center;
  }
  .ticker-item {
    display: flex;
    flex-direction: column;
    gap: 2px;
    opacity: 0;
    transform: translateY(22px);
  }
  .ticker-row { display: flex; align-items: center; gap: 8px; font-size: 18px; white-space: nowrap; }
  .ticker-date { font-weight: 900; color: #fff; }
  .ticker-name { color: #fff; font-weight: 900; }
  .ticker-separator { opacity: 0.7; color: var(--text-soft); }
  .ticker-badge {
    font-size: 11px;
    padding: 2px 8px;
    border-radius: 999px;
    border: 1px solid rgba(52, 211, 153, 0.55);
    color: #bbf7d0;
    background: rgba(0,0,0,.18);
    font-weight: 800;
  }
  .ticker-item.slide-in { animation: slideUpGroup 0.55s cubic-bezier(.22,.61,.36,1) forwards; }
  @keyframes slideUpGroup {
    from { opacity: 0; transform: translateY(20px) scale(0.99); filter: blur(2px); }
    to   { opacity: 1; transform: translateY(0) scale(1); filter: blur(0); }
  }

  .theme-tester{
    position: fixed;
    right: 16px;
    bottom: 16px;
    z-index: 9999;
    padding: 12px 12px 10px;
    border-radius: 16px;
    border: 1px solid rgba(255,255,255,0.18);
    background: rgba(0,0,0,0.22);
    backdrop-filter: blur(16px);
    box-shadow: 0 18px 60px rgba(0,0,0,0.35);
    font-family: inherit;
    color: #fff;
    max-width: 280px;
    transition: opacity .25s ease, transform .25s ease;
  }
  .theme-tester.is-hidden{
    opacity: 0;
    transform: translateY(10px);
    pointer-events: none;
  }
  .theme-tester .tt-title{
    font-size: 11px;
    letter-spacing: .16em;
    text-transform: uppercase;
    color: rgba(255,255,255,.78);
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 8px;
    margin-bottom: 10px;
    font-weight: 800;
  }
  .theme-tester .tt-status{
    font-size: 11px;
    letter-spacing: .06em;
    color: #fff;
    opacity: .9;
  }
  .theme-tester .tt-grid{
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 6px;
  }
  .theme-tester button{
    appearance: none;
    border: 1px solid rgba(255,255,255,0.18);
    background: rgba(0,0,0,0.22);
    color: #fff;
    padding: 8px 6px;
    border-radius: 12px;
    font-size: 12px;
    cursor: pointer;
    transition: transform .08s ease, border-color .2s ease, background .2s ease;
    font-weight: 800;
  }
  .theme-tester button:hover{
    border-color: rgba(var(--accent-rgb), 0.55);
    background: rgba(0,0,0,0.28);
    transform: translateY(-1px);
  }
  .theme-tester button.tt-auto{
    grid-column: span 4;
    font-weight: 800;
    border-color: rgba(var(--accent-rgb), 0.35);
  }

  /* =========================
     TV MODE (32")
     aktif via ?display=tv
     ========================= */
  body.display-tv .wrapper{
    padding: 2.2vh 2.2vw;
  }
  body.display-tv .clock-card{
    max-width: none;
    height: 100%;
    border-radius: 28px;
    padding: clamp(20px, 2.8vh, 36px) clamp(24px, 2.8vw, 42px) clamp(24px, 3vh, 40px);
  }
  body.display-tv .clock-card::before{
    border-radius: 28px;
  }
  body.display-tv .dashboard-grid{
    grid-template-columns: 1fr 2.8fr 1fr;
    grid-template-areas: "left center right";
    gap: clamp(16px, 1.8vw, 30px);
    height: 100%;
  }
  body.display-tv .left-panel,
  body.display-tv .right-panel{
    max-width: none;
  }
  body.display-tv .center-panel{
    align-self: stretch;
  }
  body.display-tv .time-display{
    font-size: clamp(76px, 8.8vw, 160px);
    line-height: .92;
  }
  body.display-tv .date-main{
    font-size: clamp(34px, 2.4vw, 52px);
  }
  body.display-tv .day-progress{
    font-size: clamp(20px, 1.3vw, 30px);
  }
  body.display-tv .holiday-title{
    font-size: clamp(24px, 1.7vw, 38px);
  }
  body.display-tv .holiday-source,
  body.display-tv .today-event-item,
  body.display-tv .ticker-row,
  body.display-tv .weather-temp,
  body.display-tv .weather-desc,
  body.display-tv .prayer-row{
    font-size: clamp(18px, 1.1vw, 26px);
  }
  body.display-tv .weather-city,
  body.display-tv .prayer-title{
    font-size: clamp(15px, .9vw, 20px);
  }
  body.display-tv .ticker-wrapper{
    height: clamp(80px, 11vh, 120px);
  }

  @media (max-width: 1100px) {
    .dashboard-grid { grid-template-columns: 1fr; gap: 14px; }
    .dashboard-grid { grid-template-areas: "center" "left" "right"; }
    .center-panel { order: 1; }
    .left-panel { order: 2; }
    .right-panel { order: 3; }
    .left-panel, .right-panel { justify-self: stretch; max-width: none; }
    .holiday-section { max-width: 100%; }
  }

  @media (max-width: 768px) {
    .clock-card { padding: 24px 20px 28px; border-radius: 24px; }
    .clock-card::before{ border-radius: 24px; }
    .time-display { letter-spacing: 0.09em; }
    .weather-badge { width: 100%; min-width: 0; }
    .prayer-badge  { width: 100%; max-width: 100%; }
  }

  @media (max-height: 860px) {
    .dashboard-grid { gap: 12px; }
    .holiday-title { font-size: clamp(16px, 2.4vh, 24px); }
    .holiday-source { font-size: clamp(12px, 1.8vh, 16px); }
  }
</style>
</head>
<body class="<?= $isTvMode ? 'display-tv' : '' ?>">

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
const HARI  = ["Minggu","Senin","Selasa","Rabu","Kamis","Jumat","Sabtu"];
const BULAN = ["Januari","Februari","Maret","April","Mei","Juni","Juli","Agustus","September","Oktober","November","Desember"];

const timeDisplay   = document.getElementById('timeDisplay');
const dateMain      = document.getElementById('dateMain');
const dayProgress   = document.getElementById('dayProgress');
const tickerItem    = document.getElementById('tickerItem');
const tickerSection = document.getElementById('tickerSection');

// Elemen cuaca
const weatherTempEl        = document.getElementById('weatherTemp');
const weatherDescEl        = document.getElementById('weatherDesc');
const weatherUpdatedEl     = document.getElementById('weatherUpdated');
const weatherEmojiEl       = document.getElementById('weatherEmoji');
const weatherNextHourValue = document.getElementById('weatherNextHourValue');

// Elemen jadwal sholat
const prayerListEl = document.getElementById('prayerList');
const prayerInfoEl = document.getElementById('prayerInfo');

let jadwalSholatToday = null;
let lastRenderedSecond = null;

function pad(num) { return String(num).padStart(2, '0'); }

function setOverlayStrength(mid, end) {
  document.documentElement.style.setProperty('--overlay-mid', mid.toFixed(3));
  document.documentElement.style.setProperty('--overlay-end', end.toFixed(3));
}

function adjustOverlayByBackground() {
  const img = new Image();
  img.onload = () => {
    const canvas = document.createElement('canvas');
    const w = 48;
    const h = 48;
    canvas.width = w;
    canvas.height = h;

    const ctx = canvas.getContext('2d');
    if (!ctx) return;

    ctx.drawImage(img, 0, 0, w, h);
    const px = ctx.getImageData(0, 0, w, h).data;

    let total = 0;
    const count = px.length / 4;
    for (let i = 0; i < px.length; i += 4) {
      const r = px[i];
      const g = px[i + 1];
      const b = px[i + 2];
      total += (0.2126 * r + 0.7152 * g + 0.0722 * b);
    }

    const avgLum = total / count;
    const normalized = Math.max(0, Math.min(1, avgLum / 255));
    const overlayEnd = 0.11 + (normalized * 0.16);
    const overlayMid = overlayEnd * 0.58;
    setOverlayStrength(overlayMid, overlayEnd);
  };

  img.onerror = () => setOverlayStrength(0.08, 0.14);
  img.src = <?php echo json_encode($bgUrl, JSON_UNESCAPED_UNICODE); ?>;
}

// ====== TEMA WARNA PER HARI (WIB) ======
const WEEKDAY_SHORT_TO_IDX = { Sun:0, Mon:1, Tue:2, Wed:3, Thu:4, Fri:5, Sat:6 };
const DAY_THEME = {
  0: { accent: "148,163,184", accent2: "100,116,139" }, // Minggu
  1: { accent: "226,232,240", accent2: "148,163,184" }, // Senin
  2: { accent: "239,68,68",   accent2: "153,27,27" },   // Selasa
  3: { accent: "34,197,94",   accent2: "21,128,61" },   // Rabu
  4: { accent: "234,179,8",   accent2: "161,98,7" },    // Kamis
  5: { accent: "59,130,246",  accent2: "30,64,175" },   // Jumat
  6: { accent: "168,85,247",  accent2: "107,33,168" },  // Sabtu
};

let lastDayIdx = null;
let themeOverrideDayIdx = null; // null = AUTO WIB

function applyDayTheme(dayIdx){
  const t = DAY_THEME[dayIdx] || DAY_THEME[5];
  document.documentElement.style.setProperty('--accent-rgb', t.accent);
  document.documentElement.style.setProperty('--accent2-rgb', t.accent2);
}

// Ambil komponen waktu berdasarkan WIB (Asia/Jakarta)
function getWIBParts(){
  const now = new Date();
  const parts = new Intl.DateTimeFormat('en-US', {
    timeZone: 'Asia/Jakarta',
    weekday: 'short',
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
    hour12: false
  }).formatToParts(now);

  const obj = {};
  for (const p of parts) if (p.type !== 'literal') obj[p.type] = p.value;

  return {
    year: +obj.year,
    month: +obj.month,
    day: +obj.day,
    hour: +obj.hour,
    minute: +obj.minute,
    second: +obj.second,
    dayIdx: WEEKDAY_SHORT_TO_IDX[obj.weekday] ?? new Date().getDay(),
  };
}

function updateClock() {
  const p = getWIBParts();
  const { hour:h, minute:m, second:s, day:d, dayIdx, month, year } = p;

  const themeIdx = (themeOverrideDayIdx !== null) ? themeOverrideDayIdx : dayIdx;
  if (lastDayIdx !== themeIdx) {
    applyDayTheme(themeIdx);
    lastDayIdx = themeIdx;
  }

  timeDisplay.innerHTML =
    pad(h) + '<span class="time-separator">:</span>' + pad(m) + ':' + pad(s);
  if (lastRenderedSecond !== s) {
    timeDisplay.classList.remove('tick');
    void timeDisplay.offsetWidth;
    timeDisplay.classList.add('tick');
    lastRenderedSecond = s;
  }

  const hariStr  = HARI[dayIdx];
  const bulanStr = BULAN[(month - 1)];
  dateMain.textContent = `${hariStr}, ${pad(d)} ${bulanStr} ${year}`;

  const startOfYear = new Date(Date.UTC(year, 0, 1));
  const todayUtc = new Date(Date.UTC(year, month - 1, d));
  const dayOfYear = Math.floor((todayUtc - startOfYear) / 86400000) + 1;
  const isLeap = ((year % 4 === 0 && year % 100 !== 0) || (year % 400 === 0));
  const totalDays = isLeap ? 366 : 365;
  const daysLeft = totalDays - dayOfYear;
  if (dayProgress) {
    dayProgress.textContent = `Hari ke-${dayOfYear} di ${year}, tersisa ${daysLeft} hari menuju ${year + 1}`;
  }
}

function formatTanggalIndoFromISO(isoStr) {
  const [y, m, d] = isoStr.split('-').map(Number);
  const bulanStr = BULAN[m - 1] || '';
  return `${pad(d)} ${bulanStr} ${y}`;
}

// ===== Theme Tester (klik tombol) =====
const themeTesterEl = document.getElementById('themeTester');
const themeTesterStatusEl = document.getElementById('themeTesterStatus');

function setThemeOverride(dayIdx){
  themeOverrideDayIdx = dayIdx;
  localStorage.setItem('themeOverrideDayIdx', String(dayIdx));
  applyDayTheme(dayIdx);
  lastDayIdx = dayIdx;
  if (themeTesterStatusEl) themeTesterStatusEl.textContent = `TEST: ${HARI[dayIdx]}`;
}

function clearThemeOverride(){
  themeOverrideDayIdx = null;
  localStorage.removeItem('themeOverrideDayIdx');
  if (themeTesterStatusEl) themeTesterStatusEl.textContent = 'AUTO';
  const p = getWIBParts();
  applyDayTheme(p.dayIdx);
  lastDayIdx = p.dayIdx;
}

(function initThemeTester(){
  const saved = localStorage.getItem('themeOverrideDayIdx');
  if (saved !== null && saved !== '') {
    const idx = parseInt(saved, 10);
    if (!Number.isNaN(idx) && idx >= 0 && idx <= 6) {
      themeOverrideDayIdx = idx;
      if (themeTesterStatusEl) themeTesterStatusEl.textContent = `TEST: ${HARI[idx]}`;
    }
  }

  const p = getWIBParts();
  const initialIdx = (themeOverrideDayIdx !== null) ? themeOverrideDayIdx : p.dayIdx;
  applyDayTheme(initialIdx);
  lastDayIdx = initialIdx;

  if (themeTesterEl) {
    themeTesterEl.addEventListener('click', (e) => {
      const btn = e.target.closest('button');
      if (!btn) return;

      if (btn.dataset.auto) {
        clearThemeOverride();
        return;
      }
      if (btn.dataset.day != null) {
        const idx = parseInt(btn.dataset.day, 10);
        if (!Number.isNaN(idx)) setThemeOverride(idx);
      }
    });
  }
})();

// ===== Auto-hide panel kalau tidak ada aktivitas =====
const THEME_TESTER_HIDE_MS = 20000; // 20 detik idle
let hideTimer = null;

function showThemeTester(){
  if (!themeTesterEl) return;
  themeTesterEl.classList.remove('is-hidden');
}
function hideThemeTester(){
  if (!themeTesterEl) return;
  themeTesterEl.classList.add('is-hidden');
}
function resetAutoHideTimer(){
  showThemeTester();
  clearTimeout(hideTimer);
  hideTimer = setTimeout(hideThemeTester, THEME_TESTER_HIDE_MS);
}
(function initAutoHide(){
  if (!themeTesterEl) return;
  const activityEvents = ['mousemove','mousedown','keydown','touchstart','scroll'];
  activityEvents.forEach(ev => window.addEventListener(ev, resetAutoHideTimer, { passive: true }));
  resetAutoHideTimer();
})();

// ==========================
// TICKER HARI LIBUR (dari PHP)
// ==========================
const tickerEvents = <?php echo json_encode($tickerEventsJs, JSON_UNESCAPED_UNICODE); ?> || [];
let tickerBaseIndex = 0;

function updateTicker() {
  if (!tickerEvents.length || !tickerItem) return;

  const len = tickerEvents.length;
  let rowsHtml = '';

  const count = Math.min(3, len);
  for (let i = 0; i < count; i++) {
    const idx  = (tickerBaseIndex + i) % len;
    const item = tickerEvents[idx];
    const tgl  = formatTanggalIndoFromISO(item.event_date);
    const nama = item.event_name || 'Hari besar';
    const badge = item.is_national_holiday
      ? '<span class="ticker-badge">Libur Nasional</span>'
      : '';

    rowsHtml += `
      <div class="ticker-row">
        <span class="ticker-date">${tgl}</span>
        <span class="ticker-separator">•</span>
        <span class="ticker-name">${nama}</span>
        ${badge}
      </div>
    `;
  }

  tickerItem.innerHTML = rowsHtml;
  tickerItem.classList.remove('slide-in');
  void tickerItem.offsetWidth;
  tickerItem.classList.add('slide-in');

  tickerBaseIndex = (tickerBaseIndex + 1) % len;
}

// ==========================
// BMKG DATA DARI PHP
// ==========================
const bmkgForecast = <?php echo json_encode($bmkgForecastFlat, JSON_UNESCAPED_UNICODE); ?> || [];
const bmkgError    = <?php echo json_encode($bmkgError, JSON_UNESCAPED_UNICODE); ?> || "";

// parsing "YYYY-MM-DD HH:mm:ss" -> Date (anggap WIB, tambahkan +07:00)
function parseLocalDatetime(str) {
  if (!str) return null;
  return new Date(str.replace(' ', 'T') + '+07:00');
}

function descToEmoji(desc) {
  if (!desc) return "🌡️";
  const d = desc.toLowerCase();
  if (d.includes("petir")) return "⛈️";
  if (d.includes("hujan lebat")) return "🌧️";
  if (d.includes("hujan")) return "🌧️";
  if (d.includes("gerimis")) return "🌦️";
  if (d.includes("kabut") || d.includes("asap")) return "🌫️";
  if (d.includes("cerah berawan")) return "🌤️";
  if (d.includes("berawan")) return "⛅";
  if (d.includes("cerah")) return "☀️";
  return "🌡️";
}

function pickCurrentAndNextFromBMKG() {
  if (!bmkgForecast.length) return { current: null, next: null };

  const now = new Date();
  const withDt = bmkgForecast
    .map((item) => {
      const dt = parseLocalDatetime(item.local_datetime);
      return dt ? { ...item, _dt: dt } : null;
    })
    .filter(Boolean)
    .sort((a, b) => a._dt - b._dt);

  if (!withDt.length) return { current: null, next: null };

  let current = null;
  let next = null;

  for (let i = 0; i < withDt.length; i++) {
    const item = withDt[i];
    if (item._dt <= now) {
      current = item;
      next = withDt[i + 1] || null;
    } else {
      if (!current) {
        current = item;
        next = withDt[i + 1] || null;
      }
      break;
    }
  }

  if (!current) {
    current = withDt[withDt.length - 1];
    next = null;
  }

  return { current, next };
}

function renderBMKGWeather() {
  if (bmkgError) {
    weatherDescEl.textContent        = bmkgError;
    weatherTempEl.textContent        = "--°C";
    weatherEmojiEl.textContent       = "⚠️";
    weatherUpdatedEl.textContent     = "Sumber: BMKG";
    weatherNextHourValue.textContent = "Tidak dapat memuat prakiraan berikutnya.";
    return;
  }

  if (!bmkgForecast.length) {
    weatherDescEl.textContent        = "Data cuaca BMKG kosong.";
    weatherTempEl.textContent        = "--°C";
    weatherEmojiEl.textContent       = "⚠️";
    weatherUpdatedEl.textContent     = "Sumber: BMKG";
    weatherNextHourValue.textContent = "Tidak ada data prakiraan berikutnya.";
    return;
  }

  const { current, next } = pickCurrentAndNextFromBMKG();

  if (!current) {
    weatherDescEl.textContent        = "Data cuaca tidak tersedia.";
    weatherTempEl.textContent        = "--°C";
    weatherEmojiEl.textContent       = "⚠️";
    weatherUpdatedEl.textContent     = "Sumber: BMKG";
    weatherNextHourValue.textContent = "Tidak ada data prakiraan berikutnya.";
    return;
  }

  const temp = current.t != null ? Math.round(current.t) : null;
  const desc = current.weather_desc || "Cuaca tidak diketahui";

  let iconUrl = "";
  if (current.image) iconUrl = String(current.image).replace(/ /g, "%20");

  if (iconUrl) {
    weatherEmojiEl.innerHTML =
      `<img src="${iconUrl}" alt="Ikon cuaca" style="width:36px;height:36px;vertical-align:middle;">`;
  } else {
    weatherEmojiEl.textContent = descToEmoji(desc);
  }

  weatherTempEl.textContent  = (temp !== null ? temp : "--") + "°C";
  weatherDescEl.textContent  = desc;
  weatherUpdatedEl.textContent = "Sumber: BMKG";

  if (next && next._dt) {
    const dt  = next._dt;
    const jam = pad(dt.getHours()) + ":" + pad(dt.getMinutes());
    const t2  = next.t != null ? Math.round(next.t) : null;
    const d2  = next.weather_desc || "Cuaca tidak diketahui";

    weatherNextHourValue.textContent =
      `${jam} WIB • ${(t2 !== null ? t2 : "--")}°C • ${d2}`;
  } else {
    weatherNextHourValue.textContent = "Data ramalan berikutnya tidak tersedia.";
  }
}

// ==== JADWAL SHOLAT (MyQuran) ====
const KOTA_ID_BANYUWANGI = 1602;

async function fetchJadwalSholat() {
  try {
    const p = getWIBParts();
    const year  = p.year;
    const month = p.month;
    const day   = p.day;

    const url = `https://api.myquran.com/v2/sholat/jadwal/${KOTA_ID_BANYUWANGI}/${year}/${month}/${day}`;

    const res = await fetch(url);
    if (!res.ok) throw new Error("HTTP " + res.status);
    const data = await res.json();

    if (!data.status || !data.data || !data.data.jadwal) {
      throw new Error("Format jadwal tidak valid");
    }

    jadwalSholatToday = data.data.jadwal;
    updatePrayerList();
  } catch (err) {
    console.error("Gagal memuat jadwal sholat:", err);
    prayerListEl.innerHTML = `<div class="prayer-empty">Gagal memuat jadwal sholat.</div>`;
    prayerInfoEl.textContent = "Coba periksa koneksi atau API MyQuran.";
  }
}

function updatePrayerList() {
  if (!jadwalSholatToday) return;

  const p = getWIBParts();
  const currentMinutes = p.hour * 60 + p.minute;

  const prayers = [
    { key: "subuh",   label: "Subuh" },
    { key: "dzuhur",  label: "Dzuhur" },
    { key: "ashar",   label: "Ashar" },
    { key: "maghrib", label: "Maghrib" },
    { key: "isya",    label: "Isya" }
  ];

  const upcoming = [];

  prayers.forEach(pr => {
    const timeStr = jadwalSholatToday[pr.key];
    if (!timeStr) return;

    const [hStr, mStr] = timeStr.split(':');
    const h = parseInt(hStr, 10);
    const m = parseInt(mStr, 10);
    if (Number.isNaN(h) || Number.isNaN(m)) return;

    const minutes = h * 60 + m;
    if (minutes > currentMinutes) {
      upcoming.push({ name: pr.label, time: pad(h) + ":" + pad(m), minutes });
    }
  });

  if (upcoming.length === 0) {
    prayerListEl.innerHTML = `
      <div class="prayer-empty">
        Semua jadwal sholat hari ini sudah lewat.<br>
        Menunggu jadwal esok hari.
      </div>
    `;
    return;
  }

  upcoming.sort((a, b) => a.minutes - b.minutes);
  const nextMinutes = upcoming[0].minutes;

  let html = "";
  upcoming.forEach(item => {
    const isNext = item.minutes === nextMinutes;
    html += `
      <div class="prayer-row">
        <span class="prayer-name ${isNext ? 'prayer-current' : ''}">${item.name}</span>
        <span class="prayer-time ${isNext ? 'prayer-current' : ''}">${item.time}</span>
      </div>
    `;
  });

  prayerListEl.innerHTML = html;
}

// Reload setelah ganti hari (WIB) supaya data PHP ikut update
function scheduleReloadAfterMidnight() {
  const p = getWIBParts();
  const targetMs = Date.UTC(p.year, p.month - 1, p.day + 1, -7, 1, 0);
  const timeout = targetMs - Date.now();
  if (timeout > 0) setTimeout(() => location.reload(), timeout);
}

// ===== INIT =====
adjustOverlayByBackground();
updateClock();
setInterval(updateClock, 200);

if (tickerEvents.length && tickerSection) {
  tickerSection.style.display = 'block';
  updateTicker();
  setInterval(updateTicker, 4000);
}

renderBMKGWeather();
setInterval(renderBMKGWeather, 10 * 60 * 1000);

fetchJadwalSholat();
setInterval(updatePrayerList, 60 * 1000);

scheduleReloadAfterMidnight();
</script>

</body>
</html>
