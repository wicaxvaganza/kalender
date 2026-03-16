const APP_DATA = window.APP_DATA || {};
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
  img.src = APP_DATA.bgUrl;
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
const tickerEvents = APP_DATA.tickerEvents || [];
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
const bmkgForecast = APP_DATA.bmkgForecast || [];
const bmkgError = APP_DATA.bmkgError || "";

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
