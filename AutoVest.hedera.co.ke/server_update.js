// autovest_nse_poster.js
(function () {
  // ---------- Config ----------
  const ENDPOINT = "https://whatsapp.hedera.co.ke/ingest_nse_snapshot.php";
  const API_KEY  = "7i9t9g78ttguig789t9iuiy897y89uiy89y689oh89y89ohljioi0970oio";

  // Post every 10 minutes
  const INTERVAL_MS = 10 * 60 * 1000;

  // Network timeouts and safety
  const FETCH_TIMEOUT_MS = 12_000;

  // ---------- Logging ----------
  const log = (...a) => console.log("[AutoVest NSE]", ...a);
  const err = (...a) => console.error("[AutoVest NSE]", ...a);

  // ---------- Discovery ----------
  function findDocWithBoardTable() {
    if (document.getElementById("board_table")) return document;
    const iframes = Array.from(document.querySelectorAll("iframe"));
    for (const f of iframes) {
      try {
        const d = f.contentDocument || f.contentWindow?.document;
        if (d && d.getElementById("board_table")) return d;
      } catch {
        // cross-origin iframe; ignore
      }
    }
    return null;
  }

  // ---------- Parsers ----------
  function parseNumLoose(text) {
    if (!text) return null;
    const t = text.replace(/,/g, "").trim();
    // supports "12.3K", "4.1M", "1234", "-0.55"
    const m = t.match(/^(-?\d+(?:\.\d+)?)([KkMm])?$/);
    if (!m) return Number.isFinite(+t) ? +t : null;
    const v = parseFloat(m[1]);
    const suf = (m[2] || "").toLowerCase();
    if (suf === "k") return v * 1e3;
    if (suf === "m") return v * 1e6;
    return v;
  }

  function parsePct(text) {
    if (!text) return null;
    const m = text.replace(/\s/g, "").match(/-?\d+(?:\.\d+)?%/);
    return m ? parseFloat(m[0]) / 100 : null;
  }

  function looksLikeClock(t) {
    return /^\d{1,2}:\d{1,2}:\d{1,2}$/.test((t || "").trim());
  }

  function padClock(t) {
    const m = (t || "").trim().match(/^(\d{1,2}):(\d{1,2}):(\d{1,2})$/);
    if (!m) return t || "";
    const p = x => String(x).padStart(2, "0");
    return `${p(m[1])}:${p(m[2])}:${p(m[3])}`;
  }

  function clockToSec(t) {
    const m = (t || "").match(/^(\d{1,2}):(\d{1,2}):(\d{1,2})$/);
    if (!m) return null;
    return (+m[1]) * 3600 + (+m[2]) * 60 + (+m[3]);
  }

  // ---------- Extraction ----------
  function extractAnnouncements(rootDoc) {
    const out = [];

    // 1) Direct, structured target (your snippet)
    const annTable = rootDoc.getElementById("announcement");
    if (annTable) {
      const rows = annTable.querySelectorAll("tbody tr.row");
      rows.forEach(tr => {
        const msgCell  = tr.querySelector("td:first-child");
        const timeCell = tr.querySelector("td.t");
        const msg  = (msgCell?.textContent || "").trim();
        const time = (timeCell?.textContent || "").trim();
        if (msg) {
          out.push({
            type: /^Corporate/i.test(msg) ? "corporate" : "exchange",
            message: msg,
            time: looksLikeClock(time) ? padClock(time) : time || null
          });
        }
      });
    }

    // 2) Fallback: previous heuristic (kept for other layouts)
    if (!out.length) {
      const anchors = Array.from(rootDoc.querySelectorAll("*"))
        .filter(n => /Exchange\s+Announcements/i.test(n.textContent || ""));
      for (const a of anchors) {
        const table = a.closest("table");
        const rows = table ? table.querySelectorAll("tr") : [];
        rows.forEach(tr => {
          const tds = tr.querySelectorAll("td,th");
          if (tds.length >= 2) {
            const msg = (tds[0].textContent || "").trim();
            const raw = (tds[1].textContent || "").trim();
            if (msg) {
              out.push({
                type: /^Corporate/i.test(msg) ? "corporate" : "exchange",
                message: msg,
                time: looksLikeClock(raw) ? padClock(raw) : raw || null
              });
            }
          }
        });
      }
    }

    // De-dupe
    const seen = new Set();
    return out.filter(a => {
      const k = `${a.type}|${a.message}|${a.time || ""}`;
      if (seen.has(k)) return false;
      seen.add(k);
      return true;
    });
  }
  


  function extractStocks(rootDoc) {
    const rows = Array.from(rootDoc.querySelectorAll("#board_table tbody tr"));
    const skipWords = [
      "BIDS", "ASKS", "TIME", "SPLITS", "QUANTITY", "PRICE",
      "NORMAL", "BLOCK", "FOREIGN", "BUYS", "SELLS"
    ];
    const raw = [];

    rows.forEach(r => {
      const td = r.querySelectorAll("td,th");
      if (td.length < 10) return;

      const name = (td[1]?.textContent || "").trim();
      if (!name || !/[A-Za-z]/.test(name)) return;
      if (skipWords.some(w => name.toUpperCase().includes(w))) return;

      // Common column pattern during active sessions:
      // [#, Security, Prev., Latest/Closing, Change, % (sometimes), High, Low, Volume, VWAP, Deals, Turnover, Foreign, Time]
      const prev     = parseNumLoose(td[2]?.textContent.trim());
      const latest   = parseNumLoose(td[3]?.textContent.trim());
      const chRaw    = (td[4]?.textContent || "").trim();
      const pctCell  = (td[5]?.textContent || "").trim();
      const chPct    = parsePct(chRaw) ?? parsePct(pctCell);
      const high     = parseNumLoose(td[6]?.textContent.trim());
      const low      = parseNumLoose(td[7]?.textContent.trim());
      const volume   = parseNumLoose(td[8]?.textContent.trim());
      const vwap     = parseNumLoose(td[9]?.textContent.trim());
      const deals    = parseNumLoose(td[10]?.textContent.trim());
      const turnover = parseNumLoose(td[11]?.textContent.trim());
      const foreign  = parsePct(td[12]?.textContent.trim());
      const time     = padClock((td[13]?.textContent || "").trim());
      const time_sec = looksLikeClock(time) ? clockToSec(time) : null;

      const status = [latest, high, low, vwap].every(v => v == null) ? "no_trades" : "traded";

      raw.push({
        name, prev, latest, ch_raw: chRaw, ch_pct: chPct, high, low,
        volume, vwap, deals, turnover, foreign, time, time_sec, status
      });
    });

    // Keep most recent per ticker
    const byTicker = new Map();
    for (const r of raw) {
      const cur = byTicker.get(r.name);
      if (!cur || ((r.time_sec ?? -1) > (cur.time_sec ?? -1))) byTicker.set(r.name, r);
    }
    return Array.from(byTicker.values()).sort((a, b) => (b.time_sec ?? -1) - (a.time_sec ?? -1));
  }

  // ---------- Market status ----------
  function inferMarketStatus(stocks) {
    if (!stocks.length) return { status: "unknown", note: "no rows" };
    const withTimes = stocks.filter(s => s.time_sec != null);
    if (!withTimes.length) return { status: "closed", note: "no timestamps" };

    // If the latest timestamp is recent within session hours, mark open.
    // NSE trading hours approx: 09:00–15:00 EAT. We infer by activity instead of clock.
    const latestSec = Math.max(...withTimes.map(s => s.time_sec || 0));
    const spread = latestSec - Math.min(...withTimes.map(s => s.time_sec || 0));
    // If times span is small and many have the same close time, likely closed.
    const uniqueTimes = new Set(withTimes.map(s => s.time));
    if (uniqueTimes.size <= 2 && spread <= 60) return { status: "closed", note: "static times" };
    return { status: "open", note: `latest ${latestSec}s` };
  }

  // ---------- Network ----------
  async function postSnapshot(payload) {
    const ctrl = new AbortController();
    const t = setTimeout(() => ctrl.abort(), FETCH_TIMEOUT_MS);

    try {
      const res = await fetch(ENDPOINT, {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          "X-API-Key": API_KEY
        },
        body: JSON.stringify(payload),
        signal: ctrl.signal
      });
      clearTimeout(t);
      if (!res.ok) {
        const text = await res.text().catch(() => "");
        throw new Error(`HTTP ${res.status} ${res.statusText} ${text}`);
      }
      const body = await res.json().catch(() => ({}));

      const savedStocks = body.saved_stocks ?? body.saved ?? 0;
      const savedAnns   = body.saved_announcements ?? 0;
      const mstatus     = body.market_status ?? "?";

      console.log(
        "[AutoVest NSE]",
        `POST ok: stocks=${savedStocks}, anns=${savedAnns}, market=${mstatus}`
      );
      // log(`POST ok: saved=${body.saved ?? body.inserted_or_updated ?? "?"}`);
    } catch (e) {
      err("POST failed:", e.message || e);
    }
  }

  // ---------- Dedupe ----------
  function hashString(s) {
    let h = 5381;
    for (let i = 0; i < s.length; i++) h = ((h << 5) + h) ^ s.charCodeAt(i);
    return (h >>> 0).toString(16);
  }
  let lastHash = null;

  // ---------- Runner ----------
  async function collectAndPost() {
    const rootDoc = findDocWithBoardTable();
    if (!rootDoc) {
      log("Waiting for #board_table…");
      return;
    }

    const stocks = extractStocks(rootDoc);
    const announcements = extractAnnouncements(rootDoc);
    const market = inferMarketStatus(stocks);

    const payload = {
      source: "nse_board_table",
      ts_client: new Date().toISOString(),
      market_status: market.status,
      market_note: market.note,
      counts: {
        tickers: stocks.length,
        announcements: announcements.length,
        traded: stocks.filter(s => s.status === "traded" && s.latest != null).length,
        no_trades: stocks.filter(s => s.status === "no_trades").length
      },
      stocks,
      announcements
    };

    const json = JSON.stringify(payload);
    const h = hashString(json);

    if (h !== lastHash) {
      log(`Posting snapshot… status=${market.status}, tickers=${stocks.length}, anns=${announcements.length}`);
      await postSnapshot(payload);
      lastHash = h;
    } else {
      log("Snapshot unchanged; skipping POST.");
    }
  }

  function init() {
    log(`AutoVest NSE poster starting. Interval=${INTERVAL_MS / 60000} min`);
    // First run immediately
    collectAndPost();
    // Schedule subsequent runs
    setInterval(collectAndPost, INTERVAL_MS);
    // Optional: also post once when the tab becomes visible again
    document.addEventListener("visibilitychange", () => {
      if (!document.hidden) collectAndPost();
    });
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();
