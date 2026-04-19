<?php
session_start();
error_reporting(0);

// Bootstrap
require_once __DIR__ . '/functions.php';
try {
    initDatabases();
    initDefaultAgents();
    // Trigger market update if stale > 90s
    $lastUpdate = (int)(getDB('main')->query("SELECT value FROM system_config WHERE key='last_market_update'")->fetchColumn() ?? 0);
    if ((time() - $lastUpdate) > 90) updateMarketData();
} catch (\Throwable $e) {}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>IA CRYPTO INVEST — Plateforme IA de Trading Virtuel</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Space+Mono:wght@400;700&family=Syne:wght@400;600;700;800&family=JetBrains+Mono:wght@300;400;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<style>
:root {
    --bg:        #050710;
    --bg2:       #090d1a;
    --bg3:       #0d1224;
    --border:    #1a2040;
    --border2:   #242b4a;
    --cyan:      #00e5ff;
    --green:     #00ff9d;
    --red:       #ff3d71;
    --gold:      #ffd600;
    --purple:    #7c4dff;
    --text:      #e2e8f0;
    --text2:     #7a8aaa;
    --text3:     #3d4a6a;
    --glow-c:    0 0 20px rgba(0,229,255,0.3);
    --glow-g:    0 0 20px rgba(0,255,157,0.3);
    --glow-r:    0 0 20px rgba(255,61,113,0.3);
}
* { margin:0; padding:0; box-sizing:border-box; }
html { scroll-behavior:smooth; }
body {
    font-family: 'Syne', sans-serif;
    background: var(--bg);
    color: var(--text);
    min-height:100vh;
    overflow-x:hidden;
}
body::before {
    content:'';
    position:fixed;
    top:0; left:0; right:0; bottom:0;
    background:
        radial-gradient(ellipse 60% 40% at 10% 20%, rgba(0,229,255,0.04) 0%, transparent 70%),
        radial-gradient(ellipse 50% 30% at 90% 80%, rgba(124,77,255,0.04) 0%, transparent 70%);
    pointer-events:none;
    z-index:0;
}

/* ── SCROLLBAR ── */
::-webkit-scrollbar { width:6px; height:6px; }
::-webkit-scrollbar-track { background:var(--bg2); }
::-webkit-scrollbar-thumb { background:var(--border2); border-radius:3px; }

/* ── LAYOUT ── */
.app { position:relative; z-index:1; }

/* ── HEADER ── */
header {
    position:sticky; top:0; z-index:100;
    background:rgba(5,7,16,0.92);
    backdrop-filter:blur(20px);
    border-bottom:1px solid var(--border);
    padding:0 2rem;
    height:64px;
    display:flex; align-items:center; justify-content:space-between;
}
.logo {
    display:flex; align-items:center; gap:12px;
    font-family:'Space Mono',monospace; font-weight:700; font-size:1.1rem;
    color:var(--cyan); letter-spacing:-0.5px;
}
.logo-icon {
    width:36px; height:36px;
    background:linear-gradient(135deg, var(--cyan), var(--purple));
    border-radius:8px;
    display:flex; align-items:center; justify-content:center;
    font-size:1rem; color:#fff;
    box-shadow: var(--glow-c);
}
.logo span { color: var(--text2); font-size:0.75rem; display:block; margin-top:1px; }

.header-center {
    display:flex; align-items:center; gap:24px;
}
.live-badge {
    display:flex; align-items:center; gap:6px;
    font-family:'JetBrains Mono',monospace; font-size:0.72rem;
    color:var(--green);
}
.live-dot {
    width:8px; height:8px; border-radius:50%;
    background:var(--green);
    animation:pulse-green 2s infinite;
}
@keyframes pulse-green {
    0%,100% { opacity:1; box-shadow:0 0 6px var(--green); }
    50% { opacity:0.5; box-shadow:0 0 2px var(--green); }
}
.header-capital {
    font-family:'JetBrains Mono',monospace;
    font-size:0.85rem; color:var(--gold);
    background:rgba(255,214,0,0.08);
    border:1px solid rgba(255,214,0,0.2);
    padding:6px 14px; border-radius:6px;
}

.header-right { display:flex; align-items:center; gap:12px; }
.btn {
    padding:8px 18px; border-radius:8px; border:none; cursor:pointer;
    font-family:'Syne',sans-serif; font-size:0.82rem; font-weight:600;
    transition:all 0.2s; letter-spacing:0.3px;
}
.btn-primary {
    background:linear-gradient(135deg, var(--cyan), #0090b8);
    color:#000;
    box-shadow: var(--glow-c);
}
.btn-primary:hover { transform:translateY(-1px); filter:brightness(1.1); }
.btn-ghost {
    background:transparent; color:var(--text2);
    border:1px solid var(--border2);
}
.btn-ghost:hover { border-color:var(--cyan); color:var(--cyan); }
.btn-sm { padding:6px 12px; font-size:0.75rem; }
.btn-green { background:linear-gradient(135deg,var(--green),#00b86e); color:#000; box-shadow:var(--glow-g); }
.btn-red   { background:linear-gradient(135deg,var(--red),#b8001e); color:#fff; }
.btn-purple{ background:linear-gradient(135deg,var(--purple),#5500cc); color:#fff; }

/* ── MAIN LAYOUT ── */
.main-layout {
    display:grid;
    grid-template-columns: 280px 1fr 320px;
    grid-template-rows: auto 1fr;
    gap:0;
    height: calc(100vh - 64px);
}

/* ── SIDEBAR LEFT (Agents) ── */
.sidebar-left {
    background:var(--bg2);
    border-right:1px solid var(--border);
    overflow-y:auto;
    padding:16px;
}
.sidebar-section { margin-bottom:20px; }
.sidebar-title {
    font-size:0.7rem; font-weight:700; letter-spacing:2px;
    color:var(--text3); text-transform:uppercase;
    margin-bottom:12px;
    display:flex; align-items:center; justify-content:space-between;
}

.agent-card {
    background:var(--bg3);
    border:1px solid var(--border);
    border-radius:10px;
    padding:12px;
    margin-bottom:8px;
    cursor:pointer;
    transition:all 0.2s;
    position:relative;
    overflow:hidden;
}
.agent-card::before {
    content:'';
    position:absolute; top:0; left:0; right:0; height:2px;
    background:linear-gradient(90deg,var(--cyan),var(--purple));
    opacity:0;
    transition:opacity 0.2s;
}
.agent-card:hover { border-color:var(--border2); transform:translateX(2px); }
.agent-card:hover::before { opacity:1; }
.agent-card.top-1::before { opacity:1; background:linear-gradient(90deg,var(--gold),var(--green)); }

.agent-name { font-size:0.82rem; font-weight:700; color:var(--text); margin-bottom:4px; }
.agent-meta { display:flex; justify-content:space-between; align-items:center; }
.agent-pnl { font-family:'JetBrains Mono',monospace; font-size:0.8rem; font-weight:600; }
.agent-pnl.pos { color:var(--green); }
.agent-pnl.neg { color:var(--red); }
.agent-trades { font-size:0.7rem; color:var(--text3); }
.agent-type { font-size:0.65rem; color:var(--cyan); background:rgba(0,229,255,0.08); padding:2px 6px; border-radius:4px; }

/* ── CENTER (Market Table) ── */
.market-center {
    overflow-y:auto;
    background:var(--bg);
}

.market-header {
    padding:16px 20px;
    border-bottom:1px solid var(--border);
    display:flex; align-items:center; justify-content:space-between;
    position:sticky; top:0; z-index:10;
    background:rgba(5,7,16,0.95);
    backdrop-filter:blur(10px);
}
.market-title {
    font-size:0.9rem; font-weight:700;
    display:flex; align-items:center; gap:8px;
}
.market-stats {
    display:flex; gap:20px;
}
.mstat { text-align:center; }
.mstat-val { font-family:'JetBrains Mono',monospace; font-size:0.8rem; color:var(--cyan); }
.mstat-lbl { font-size:0.65rem; color:var(--text3); }

.search-bar {
    display:flex; align-items:center; gap:8px;
    background:var(--bg3); border:1px solid var(--border);
    border-radius:8px; padding:6px 12px;
    width:220px;
}
.search-bar input {
    background:none; border:none; outline:none;
    color:var(--text); font-size:0.82rem; font-family:'Syne',sans-serif;
    width:100%;
}
.search-bar i { color:var(--text3); font-size:0.8rem; }

/* Table */
.crypto-table { width:100%; border-collapse:collapse; }
.crypto-table th {
    position:sticky; top:0;
    padding:10px 16px;
    text-align:left; font-size:0.68rem; font-weight:600;
    color:var(--text3); text-transform:uppercase; letter-spacing:1px;
    border-bottom:1px solid var(--border);
    background:var(--bg2);
    cursor:pointer;
    user-select:none;
    white-space:nowrap;
}
.crypto-table th:hover { color:var(--cyan); }
.crypto-table td {
    padding:10px 16px;
    border-bottom:1px solid rgba(26,32,64,0.5);
    font-size:0.82rem;
    white-space:nowrap;
}
.crypto-table tr { cursor:pointer; transition:background 0.15s; }
.crypto-table tr:hover td { background:rgba(255,255,255,0.02); }

.coin-info { display:flex; align-items:center; gap:10px; }
.coin-img { width:28px; height:28px; border-radius:50%; background:var(--border); }
.coin-name-wrap .coin-name { font-weight:700; font-size:0.85rem; }
.coin-name-wrap .coin-sym { font-size:0.7rem; color:var(--text3); font-family:'JetBrains Mono',monospace; }

.price-cell { font-family:'JetBrains Mono',monospace; font-size:0.82rem; font-weight:600; color:var(--text); }
.pct-cell { font-family:'JetBrains Mono',monospace; font-size:0.78rem; font-weight:600; padding:3px 8px; border-radius:5px; }
.pct-pos { color:var(--green); background:rgba(0,255,157,0.08); }
.pct-neg { color:var(--red); background:rgba(255,61,113,0.08); }

.sparkline-canvas { display:block; }

.rank-badge {
    font-family:'JetBrains Mono',monospace; font-size:0.7rem;
    color:var(--text3); min-width:28px;
}

/* ── SIDEBAR RIGHT (News/Analysis) ── */
.sidebar-right {
    background:var(--bg2);
    border-left:1px solid var(--border);
    overflow-y:auto;
    padding:16px;
}
.news-empty {
    text-align:center; padding:40px 20px;
    color:var(--text3); font-size:0.82rem;
}
.news-empty i { font-size:2rem; margin-bottom:12px; display:block; }

.analysis-card {
    background:var(--bg3); border:1px solid var(--border);
    border-radius:10px; padding:16px; margin-bottom:16px;
}
.sentiment-bar-wrap {
    margin:12px 0;
}
.sentiment-bar-track {
    height:6px; border-radius:3px;
    background:linear-gradient(90deg, var(--red) 0%, #333 50%, var(--green) 100%);
    position:relative;
}
.sentiment-needle {
    position:absolute; top:-4px;
    width:14px; height:14px;
    border-radius:50%; border:2px solid #fff;
    background:var(--gold);
    transform:translateX(-50%);
    transition:left 0.5s;
}
.factor-list { list-style:none; }
.factor-list li {
    font-size:0.75rem; color:var(--text2);
    padding:4px 0; border-bottom:1px solid var(--border);
    display:flex; gap:6px; align-items:flex-start;
}
.factor-list li i { margin-top:2px; }
.factor-list li.bull i { color:var(--green); }
.factor-list li.bear i { color:var(--red); }

.news-item {
    background:var(--bg3); border:1px solid var(--border);
    border-radius:8px; padding:12px; margin-bottom:8px;
    text-decoration:none; display:block;
    transition:border-color 0.2s;
}
.news-item:hover { border-color:var(--cyan); }
.news-title { font-size:0.78rem; color:var(--text); line-height:1.4; margin-bottom:6px; }
.news-meta { font-size:0.68rem; color:var(--text3); display:flex; gap:8px; }

/* ── MODALS ── */
.modal-overlay {
    display:none;
    position:fixed; inset:0; z-index:200;
    background:rgba(0,0,0,0.8);
    backdrop-filter:blur(4px);
    align-items:center; justify-content:center;
}
.modal-overlay.open { display:flex; }
.modal {
    background:var(--bg2);
    border:1px solid var(--border2);
    border-radius:16px;
    padding:28px;
    max-width:560px; width:90%;
    max-height:85vh; overflow-y:auto;
    position:relative;
    animation:modal-in 0.25s ease;
}
@keyframes modal-in {
    from { opacity:0; transform:scale(0.96) translateY(10px); }
    to   { opacity:1; transform:scale(1) translateY(0); }
}
.modal-close {
    position:absolute; top:16px; right:16px;
    background:none; border:none; color:var(--text3);
    font-size:1.1rem; cursor:pointer;
    transition:color 0.2s;
}
.modal-close:hover { color:var(--red); }
.modal-title {
    font-size:1.1rem; font-weight:800; color:var(--cyan);
    margin-bottom:20px;
    display:flex; align-items:center; gap:10px;
}

.form-group { margin-bottom:16px; }
.form-label { font-size:0.75rem; color:var(--text2); font-weight:600; margin-bottom:6px; display:block; letter-spacing:0.5px; }
.form-input, .form-textarea, .form-select {
    width:100%; background:var(--bg3);
    border:1px solid var(--border2); border-radius:8px;
    padding:10px 14px; color:var(--text);
    font-family:'Syne',sans-serif; font-size:0.85rem;
    outline:none; transition:border-color 0.2s;
}
.form-input:focus, .form-textarea:focus { border-color:var(--cyan); }
.form-textarea { resize:vertical; min-height:100px; }

/* ── COIN DETAIL MODAL ── */
.coin-modal { max-width:720px; }
.coin-header-detail {
    display:flex; align-items:center; gap:16px;
    margin-bottom:20px;
}
.coin-modal-img { width:52px; height:52px; border-radius:50%; }
.coin-modal-name { font-size:1.4rem; font-weight:800; }
.coin-modal-sym { color:var(--text3); font-family:'JetBrains Mono',monospace; }
.coin-price-big { font-family:'JetBrains Mono',monospace; font-size:1.6rem; font-weight:700; color:var(--cyan); }
.coin-stats-grid {
    display:grid; grid-template-columns:repeat(3,1fr); gap:12px;
    margin:16px 0;
}
.coin-stat {
    background:var(--bg3); border:1px solid var(--border);
    border-radius:8px; padding:12px; text-align:center;
}
.coin-stat-val { font-family:'JetBrains Mono',monospace; font-size:0.9rem; color:var(--text); font-weight:600; }
.coin-stat-lbl { font-size:0.65rem; color:var(--text3); margin-top:3px; }

/* ── AGENT DETAIL ── */
.agent-detail-trades { max-height:300px; overflow-y:auto; }
.trade-row {
    display:flex; justify-content:space-between; align-items:center;
    padding:8px 0; border-bottom:1px solid var(--border);
    font-size:0.78rem;
}
.trade-buy { color:var(--green); }
.trade-sell { color:var(--red); }

/* ── TOAST ── */
#toast {
    position:fixed; bottom:24px; right:24px; z-index:500;
    background:var(--bg3); border:1px solid var(--border2);
    border-radius:10px; padding:14px 20px;
    font-size:0.82rem; color:var(--text);
    display:none; max-width:320px;
    box-shadow:0 8px 32px rgba(0,0,0,0.5);
    animation:toast-in 0.3s ease;
}
@keyframes toast-in { from { opacity:0; transform:translateY(10px); } to { opacity:1; transform:translateY(0); } }
#toast.success { border-color:var(--green); color:var(--green); }
#toast.error   { border-color:var(--red);   color:var(--red); }

/* ── LOADING ── */
.spinner {
    display:inline-block; width:18px; height:18px;
    border:2px solid rgba(0,229,255,0.2);
    border-top-color:var(--cyan);
    border-radius:50%;
    animation:spin 0.7s linear infinite;
}
@keyframes spin { to { transform:rotate(360deg); } }

/* ── BRAIN PANEL ── */
.brain-section {
    background:var(--bg2); border-top:1px solid var(--border);
    padding:16px 20px;
    display:flex; align-items:center; gap:16px;
}
.brain-title { font-size:0.75rem; font-weight:700; color:var(--purple); letter-spacing:1px; }
.brain-log { font-family:'JetBrains Mono',monospace; font-size:0.7rem; color:var(--text3); flex:1; }

/* ── TABS ── */
.tabs { display:flex; gap:0; border-bottom:1px solid var(--border); margin-bottom:16px; }
.tab {
    padding:8px 16px; font-size:0.78rem; font-weight:600; cursor:pointer;
    color:var(--text3); border-bottom:2px solid transparent; transition:all 0.2s;
}
.tab.active { color:var(--cyan); border-bottom-color:var(--cyan); }

/* ── PRICE UPDATE FLASH ── */
@keyframes price-up   { 0%,100%{background:transparent} 50%{background:rgba(0,255,157,0.12)} }
@keyframes price-down { 0%,100%{background:transparent} 50%{background:rgba(255,61,113,0.12)} }
.flash-up   { animation:price-up 0.6s; }
.flash-down { animation:price-down 0.6s; }

/* ── RESPONSIVE ── */
@media(max-width:1200px) {
    .main-layout { grid-template-columns: 240px 1fr 0; }
    .sidebar-right { display:none; }
}
@media(max-width:768px) {
    .main-layout { grid-template-columns: 1fr; }
    .sidebar-left { display:none; }
    .main-layout { height:auto; }
}
</style>
</head>
<body>
<div class="app">

<!-- ═══════════════ HEADER ═══════════════ -->
<header>
    <div class="logo">
        <div class="logo-icon"><i class="fa-solid fa-brain"></i></div>
        <div>
            IA CRYPTO INVEST
            <span>Plateforme IA de Simulation Trading</span>
        </div>
    </div>
    <div class="header-center">
        <div class="live-badge">
            <div class="live-dot" id="liveDot"></div>
            <span id="liveLabel">LIVE</span>
        </div>
        <div id="headerCapital" class="header-capital">
            <i class="fa-solid fa-coins"></i> 1 000 000 € Virtuels
        </div>
        <div id="updateCountdown" style="font-family:'JetBrains Mono',monospace;font-size:0.72rem;color:var(--text3);">
            Mise à jour dans <span id="countdownSecs">60</span>s
        </div>
    </div>
    <div class="header-right">
        <button class="btn btn-ghost btn-sm" id="btnBrain" title="Lancer le Cerveau Central">
            <i class="fa-solid fa-robot"></i> Cerveau IA
        </button>
        <div id="authSection">
            <button class="btn btn-ghost btn-sm" onclick="openModal('authModal')">
                <i class="fa-solid fa-user"></i> Connexion
            </button>
        </div>
    </div>
</header>

<!-- ═══════════════ MAIN LAYOUT ═══════════════ -->
<div class="main-layout">

    <!-- ── SIDEBAR LEFT : Agents ── -->
    <aside class="sidebar-left">
        <div class="sidebar-section">
            <div class="sidebar-title">
                Top Agents IA
                <button class="btn btn-primary btn-sm" onclick="openModal('createAgentModal')">
                    + Créer
                </button>
            </div>
            <div id="agentsList">
                <div style="text-align:center;padding:20px;color:var(--text3);">
                    <div class="spinner"></div>
                    <div style="margin-top:8px;font-size:0.75rem;">Chargement agents...</div>
                </div>
            </div>
        </div>
        <div class="sidebar-section">
            <div class="sidebar-title">Derniers Trades</div>
            <div id="recentTradesList" style="font-size:0.75rem;color:var(--text3);">
                <div style="text-align:center;padding:20px;">Aucun trade récent</div>
            </div>
        </div>
    </aside>

    <!-- ── CENTER : Market Table ── -->
    <main class="market-center">
        <div class="market-header">
            <div class="market-title">
                <i class="fa-solid fa-chart-line" style="color:var(--cyan)"></i>
                Marchés Crypto
                <span style="font-size:0.7rem;color:var(--text3);font-family:'JetBrains Mono',monospace;" id="coinCount">— coins</span>
            </div>
            <div class="market-stats">
                <div class="mstat">
                    <div class="mstat-val" id="statBTCPrice">—</div>
                    <div class="mstat-lbl">BTC/EUR</div>
                </div>
                <div class="mstat">
                    <div class="mstat-val" id="statETHPrice">—</div>
                    <div class="mstat-lbl">ETH/EUR</div>
                </div>
                <div class="mstat">
                    <div class="mstat-val" id="statActiveAgents">—</div>
                    <div class="mstat-lbl">Agents actifs</div>
                </div>
            </div>
            <div class="search-bar">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="text" id="searchInput" placeholder="Rechercher..." oninput="filterCoins()">
            </div>
        </div>

        <table class="crypto-table">
            <thead>
                <tr>
                    <th onclick="sortTable('rank')">#</th>
                    <th onclick="sortTable('name')">Coin</th>
                    <th onclick="sortTable('price')">Prix</th>
                    <th onclick="sortTable('change24h')">24h</th>
                    <th onclick="sortTable('change7d')">7j</th>
                    <th onclick="sortTable('volume')">Volume 24h</th>
                    <th onclick="sortTable('marketcap')">Market Cap</th>
                    <th>Sparkline</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody id="coinsTableBody">
                <tr><td colspan="9" style="text-align:center;padding:40px;color:var(--text3);">
                    <div class="spinner" style="margin:auto"></div>
                    <div style="margin-top:12px;">Chargement du marché...</div>
                </td></tr>
            </tbody>
        </table>
    </main>

    <!-- ── SIDEBAR RIGHT : News/Analysis ── -->
    <aside class="sidebar-right" id="rightSidebar">
        <div class="sidebar-title">Analyse IA</div>
        <div id="rightPanelContent">
            <div class="news-empty">
                <i class="fa-solid fa-newspaper"></i>
                Cliquez sur une crypto pour voir l'analyse IA et les actualités
            </div>
        </div>
    </aside>

</div><!-- /main-layout -->

<!-- Brain Log Bar -->
<div class="brain-section">
    <i class="fa-solid fa-brain" style="color:var(--purple)"></i>
    <div class="brain-title">CERVEAU CENTRAL</div>
    <div class="brain-log" id="brainLog">En attente...</div>
    <div style="font-size:0.7rem;color:var(--text3);font-family:'JetBrains Mono',monospace;" id="brainLastRun">—</div>
</div>

</div><!-- /app -->

<!-- ═══════════════ MODALS ═══════════════ -->

<!-- AUTH MODAL -->
<div class="modal-overlay" id="authModal">
    <div class="modal">
        <button class="modal-close" onclick="closeModal('authModal')"><i class="fa-solid fa-xmark"></i></button>
        <div class="tabs" id="authTabs">
            <div class="tab active" onclick="switchAuthTab('login')">Connexion</div>
            <div class="tab" onclick="switchAuthTab('register')">Inscription</div>
        </div>
        <div id="loginForm">
            <div class="form-group">
                <label class="form-label">Email</label>
                <input type="email" class="form-input" id="loginEmail" placeholder="votre@email.com">
            </div>
            <div class="form-group">
                <label class="form-label">Mot de passe</label>
                <input type="password" class="form-input" id="loginPwd" placeholder="••••••">
            </div>
            <button class="btn btn-primary" style="width:100%" onclick="doLogin()">
                <i class="fa-solid fa-right-to-bracket"></i> Se connecter
            </button>
        </div>
        <div id="registerForm" style="display:none">
            <div class="form-group">
                <label class="form-label">Pseudo</label>
                <input type="text" class="form-input" id="regName" placeholder="Trader_Master">
            </div>
            <div class="form-group">
                <label class="form-label">Email</label>
                <input type="email" class="form-input" id="regEmail" placeholder="votre@email.com">
            </div>
            <div class="form-group">
                <label class="form-label">Mot de passe</label>
                <input type="password" class="form-input" id="regPwd" placeholder="Min. 6 caractères">
            </div>
            <button class="btn btn-green" style="width:100%" onclick="doRegister()">
                <i class="fa-solid fa-user-plus"></i> Créer mon compte
            </button>
        </div>
    </div>
</div>

<!-- CREATE AGENT MODAL -->
<div class="modal-overlay" id="createAgentModal">
    <div class="modal">
        <button class="modal-close" onclick="closeModal('createAgentModal')"><i class="fa-solid fa-xmark"></i></button>
        <div class="modal-title"><i class="fa-solid fa-robot"></i> Créer un Agent IA</div>
        <div class="form-group">
            <label class="form-label">Nom de l'agent</label>
            <input type="text" class="form-input" id="agentName" placeholder="ex: TITAN Scalper Pro">
        </div>
        <div class="form-group">
            <label class="form-label">Type de stratégie</label>
            <select class="form-select" id="agentType">
                <option value="scalping">Scalping (court terme, rapide)</option>
                <option value="swing">Swing Trading (1-7 jours)</option>
                <option value="momentum">Momentum (suivre la tendance)</option>
                <option value="dca">DCA (accumulation progressive)</option>
                <option value="long_term">Long Terme (fondamentaux)</option>
                <option value="contrarian">Contrarian (contre-courant)</option>
                <option value="custom">Custom (libre)</option>
            </select>
        </div>
        <div class="form-group">
            <label class="form-label">Décris la stratégie en langage naturel</label>
            <textarea class="form-textarea" id="agentPrompt" placeholder="ex: Tu es un trader agressif spécialisé dans les altcoins. Tu achètes quand le RSI est sous 30 et le volume explose. Tu vends quand tu atteins +8% de profit ou -4% de perte..."></textarea>
        </div>
        <div style="display:flex;gap:12px">
            <button class="btn btn-primary" style="flex:1" onclick="doCreateAgent()">
                <i class="fa-solid fa-plus"></i> Créer l'agent
            </button>
            <button class="btn btn-ghost" onclick="generateRandomAgent()">
                <i class="fa-solid fa-dice"></i> Aléatoire
            </button>
        </div>
    </div>
</div>

<!-- COIN DETAIL MODAL -->
<div class="modal-overlay" id="coinModal">
    <div class="modal coin-modal" style="max-width:720px">
        <button class="modal-close" onclick="closeModal('coinModal')"><i class="fa-solid fa-xmark"></i></button>
        <div id="coinModalContent">
            <div style="text-align:center;padding:40px"><div class="spinner" style="margin:auto"></div></div>
        </div>
    </div>
</div>

<!-- AGENT DETAIL MODAL -->
<div class="modal-overlay" id="agentModal">
    <div class="modal">
        <button class="modal-close" onclick="closeModal('agentModal')"><i class="fa-solid fa-xmark"></i></button>
        <div id="agentModalContent">
            <div style="text-align:center;padding:40px"><div class="spinner" style="margin:auto"></div></div>
        </div>
    </div>
</div>

<!-- TOAST -->
<div id="toast"></div>

<script>
// ══════════════════════════════════════════════
// STATE
// ══════════════════════════════════════════════
const state = {
    coins: [],
    agents: [],
    sortField: 'rank',
    sortAsc: true,
    user: null,
    updateTimer: 60,
    sparklines: {}
};

// ══════════════════════════════════════════════
// API
// ══════════════════════════════════════════════
async function api(action, data = {}) {
    const resp = await fetch('api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action, ...data })
    });
    return resp.json();
}

// ══════════════════════════════════════════════
// INIT
// ══════════════════════════════════════════════
async function init() {
    await loadCoins();
    await loadAgents();
    await loadStatus();
    checkAuth();
    startCountdown();
    startAutoRefresh();
}

// ══════════════════════════════════════════════
// MARKET DATA
// ══════════════════════════════════════════════
async function loadCoins() {
    const data = await api('get_coins', { limit: 100 });
    if (!data.success || !data.coins) return;

    // Flash changed prices
    const prevPrices = {};
    state.coins.forEach(c => prevPrices[c.id] = c.current_price);

    state.coins = data.coins;
    document.getElementById('coinCount').textContent = data.coins.length + ' coins';

    // Update header stats
    const btc = data.coins.find(c => c.symbol === 'BTC');
    const eth = data.coins.find(c => c.symbol === 'ETH');
    if (btc) document.getElementById('statBTCPrice').textContent = formatPrice(btc.current_price);
    if (eth) document.getElementById('statETHPrice').textContent = formatPrice(eth.current_price);

    renderTable(data.coins, prevPrices);
}

function renderTable(coins, prevPrices = {}) {
    const query = document.getElementById('searchInput').value.toLowerCase();
    let filtered = query
        ? coins.filter(c => c.name.toLowerCase().includes(query) || c.symbol.toLowerCase().includes(query))
        : coins;

    // Sort
    filtered = [...filtered].sort((a, b) => {
        let v;
        switch (state.sortField) {
            case 'rank':    v = a.market_cap_rank - b.market_cap_rank; break;
            case 'price':   v = b.current_price - a.current_price; break;
            case 'change24h': v = b.price_change_pct_24h - a.price_change_pct_24h; break;
            case 'change7d':  v = (b.price_change_7d||0) - (a.price_change_7d||0); break;
            case 'volume':  v = b.volume_24h - a.volume_24h; break;
            case 'marketcap': v = b.market_cap - a.market_cap; break;
            default: v = 0;
        }
        return state.sortAsc ? v : -v;
    });

    const tbody = document.getElementById('coinsTableBody');
    tbody.innerHTML = filtered.map(coin => {
        const p24 = coin.price_change_pct_24h || 0;
        const p7  = coin.price_change_7d || 0;
        const flashClass = prevPrices[coin.id] && coin.current_price > prevPrices[coin.id] ? 'flash-up'
                         : prevPrices[coin.id] && coin.current_price < prevPrices[coin.id] ? 'flash-down' : '';

        const spark = JSON.parse(coin.sparkline_7d || '[]');
        const sparkId = 'spark_' + coin.id.replace(/[^a-z0-9]/gi,'_');

        return `<tr onclick="openCoin('${coin.id}')" class="${flashClass}">
            <td><span class="rank-badge">${coin.market_cap_rank || '—'}</span></td>
            <td>
                <div class="coin-info">
                    <img src="${coin.image_url||''}" class="coin-img" onerror="this.style.display='none'">
                    <div class="coin-name-wrap">
                        <div class="coin-name">${coin.name}</div>
                        <div class="coin-sym">${coin.symbol}</div>
                    </div>
                </div>
            </td>
            <td><span class="price-cell">${formatPrice(coin.current_price)}</span></td>
            <td><span class="pct-cell ${p24>=0?'pct-pos':'pct-neg'}">${p24>=0?'+':''}${p24.toFixed(2)}%</span></td>
            <td><span class="pct-cell ${p7>=0?'pct-pos':'pct-neg'}">${p7>=0?'+':''}${p7.toFixed(2)}%</span></td>
            <td style="color:var(--text2);font-family:'JetBrains Mono',monospace;font-size:0.76rem">${formatLarge(coin.volume_24h)}</td>
            <td style="color:var(--text2);font-family:'JetBrains Mono',monospace;font-size:0.76rem">${formatLarge(coin.market_cap)}</td>
            <td><canvas id="${sparkId}" width="90" height="32" class="sparkline-canvas"></canvas></td>
            <td>
                <div style="display:flex;gap:6px">
                    <button class="btn btn-green btn-sm" onclick="event.stopPropagation();quickTrade('${coin.id}','buy')">▲</button>
                    <button class="btn btn-red btn-sm" onclick="event.stopPropagation();quickTrade('${coin.id}','sell')">▼</button>
                </div>
            </td>
        </tr>`;
    }).join('');

    // Draw sparklines
    filtered.forEach(coin => {
        const spark = JSON.parse(coin.sparkline_7d || '[]');
        if (spark.length < 2) return;
        const sparkId = 'spark_' + coin.id.replace(/[^a-z0-9]/gi,'_');
        const canvas = document.getElementById(sparkId);
        if (!canvas) return;
        const isUp = spark[spark.length-1] >= spark[0];
        drawSparkline(canvas, spark, isUp ? '#00ff9d' : '#ff3d71');
    });
}

function drawSparkline(canvas, data, color) {
    const ctx = canvas.getContext('2d');
    const w = canvas.width, h = canvas.height;
    ctx.clearRect(0, 0, w, h);
    const min = Math.min(...data), max = Math.max(...data);
    const range = max - min || 1;
    const points = data.map((v,i) => ({ x: (i/(data.length-1))*w, y: h - ((v-min)/range)*(h-4)-2 }));

    ctx.beginPath();
    ctx.moveTo(points[0].x, points[0].y);
    for (let i=1; i<points.length; i++) ctx.lineTo(points[i].x, points[i].y);
    ctx.strokeStyle = color;
    ctx.lineWidth = 1.5;
    ctx.stroke();

    // Fill
    ctx.lineTo(w, h); ctx.lineTo(0, h); ctx.closePath();
    ctx.fillStyle = color.replace(')', ',0.1)').replace('rgb','rgba');
    ctx.fill();
}

function filterCoins() {
    renderTable(state.coins);
}

function sortTable(field) {
    if (state.sortField === field) state.sortAsc = !state.sortAsc;
    else { state.sortField = field; state.sortAsc = true; }
    renderTable(state.coins);
}

// ══════════════════════════════════════════════
// AGENTS
// ══════════════════════════════════════════════
async function loadAgents() {
    const data = await api('get_top_agents');
    if (!data.success) return;
    state.agents = data.agents || [];
    document.getElementById('statActiveAgents').textContent = data.agents.length + '+';
    renderAgents(data.agents);
}

function renderAgents(agents) {
    if (!agents.length) {
        document.getElementById('agentsList').innerHTML = '<div style="text-align:center;padding:20px;color:var(--text3);font-size:0.75rem">Aucun agent actif</div>';
        return;
    }
    document.getElementById('agentsList').innerHTML = agents.map((a, i) => `
        <div class="agent-card ${i===0?'top-1':''}" onclick="openAgent(${a.id})">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:6px">
                <div class="agent-name">${a.name}</div>
                <span class="agent-type">${a.strategy_type||'custom'}</span>
            </div>
            <div class="agent-meta">
                <span class="agent-pnl ${(a.total_pnl_percent||0)>=0?'pos':'neg'}">
                    ${(a.total_pnl_percent||0)>=0?'+':''}${(a.total_pnl_percent||0).toFixed(2)}%
                </span>
                <span class="agent-trades">${a.total_trades||0} trades</span>
            </div>
            <div style="height:3px;background:var(--border);border-radius:2px;margin-top:8px">
                <div style="height:100%;width:${Math.min(100,Math.abs(a.total_pnl_percent||0)*5)}%;background:${(a.total_pnl_percent||0)>=0?'var(--green)':'var(--red)'};border-radius:2px;transition:width 0.5s"></div>
            </div>
        </div>
    `).join('');
}

async function openAgent(agentId) {
    openModal('agentModal');
    const [agentData, tradesData] = await Promise.all([
        api('get_agents'),
        api('get_agent_trades', { agent_id: agentId })
    ]);

    const agent = agentData.agents?.find(a => a.id === agentId) || { id: agentId, name: 'Agent #'+agentId };
    const trades = tradesData.trades || [];

    document.getElementById('agentModalContent').innerHTML = `
        <div class="modal-title"><i class="fa-solid fa-robot"></i> ${agent.name}</div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px">
            <div class="coin-stat"><div class="coin-stat-val ${(agent.total_pnl_percent||0)>=0?'':''}">
                ${(agent.total_pnl_percent||0)>=0?'+':''}${(agent.total_pnl_percent||0).toFixed(2)}%
            </div><div class="coin-stat-lbl">P&L Total</div></div>
            <div class="coin-stat"><div class="coin-stat-val">${formatPrice(Math.abs(agent.total_pnl||0))}</div><div class="coin-stat-lbl">Gain/Perte</div></div>
            <div class="coin-stat"><div class="coin-stat-val">${agent.total_trades||0}</div><div class="coin-stat-lbl">Trades</div></div>
            <div class="coin-stat"><div class="coin-stat-val">${agent.strategy_type||'custom'}</div><div class="coin-stat-lbl">Type</div></div>
        </div>
        <div style="background:var(--bg3);border:1px solid var(--border);border-radius:8px;padding:12px;margin-bottom:16px">
            <div style="font-size:0.7rem;color:var(--text3);margin-bottom:6px">STRATÉGIE</div>
            <div style="font-size:0.8rem;color:var(--text2);line-height:1.5">${agent.strategy_prompt||'—'}</div>
        </div>
        <div style="display:flex;gap:8px;margin-bottom:16px">
            <button class="btn btn-primary btn-sm" onclick="runAgent(${agentId})"><i class="fa-solid fa-play"></i> Exécuter</button>
        </div>
        <div style="font-size:0.7rem;color:var(--text3);margin-bottom:8px">DERNIERS TRADES</div>
        <div class="agent-detail-trades">
            ${trades.length ? trades.map(t => `
                <div class="trade-row">
                    <span class="${t.action==='buy'?'trade-buy':'trade-sell'}">
                        <i class="fa-solid fa-${t.action==='buy'?'arrow-up':'arrow-down'}"></i> ${t.action.toUpperCase()}
                    </span>
                    <span style="font-family:'JetBrains Mono',monospace">${t.coin_symbol}</span>
                    <span style="color:var(--text2)">${formatPrice(t.price)}</span>
                    <span class="${(t.pnl||0)>=0?'trade-buy':'trade-sell'}">${(t.pnl||0)>=0?'+':''}${(t.pnl||0).toFixed(2)}€</span>
                </div>
            `).join('') : '<div style="padding:20px;text-align:center;color:var(--text3)">Aucun trade encore</div>'}
        </div>
    `;
}

async function runAgent(agentId) {
    showToast('Agent en cours d\'exécution...', 'info');
    const data = await api('run_agent', { agent_id: agentId });
    if (data.decision) {
        const d = data.decision;
        showToast(`${d.action?.toUpperCase()} ${d.coin} — Confiance: ${d.confidence}%`, d.action==='buy'?'success':'error');
    }
    await loadAgents();
}

// ══════════════════════════════════════════════
// COIN DETAIL
// ══════════════════════════════════════════════
async function openCoin(coinId) {
    openModal('coinModal');
    document.getElementById('coinModalContent').innerHTML = '<div style="text-align:center;padding:40px"><div class="spinner" style="margin:auto"></div><div style="margin-top:12px;color:var(--text3)">Chargement...</div></div>';

    const [coinData, analysisData] = await Promise.all([
        api('get_coin', { id: coinId }),
        api('get_analysis', { coin_id: coinId })
    ]);

    const coin = coinData.coin;
    if (!coin) {
        document.getElementById('coinModalContent').innerHTML = '<p style="color:var(--red)">Coin introuvable</p>';
        return;
    }

    const analysis = analysisData.analysis;
    const articles  = analysisData.articles || [];
    const p24 = coin.price_change_pct_24h || 0;
    const sentPercent = analysis ? ((analysis.sentiment_score + 10) / 20) * 100 : 50;

    document.getElementById('coinModalContent').innerHTML = `
        <div class="coin-header-detail">
            <img src="${coin.image_url||''}" class="coin-modal-img" onerror="this.style.display='none'">
            <div style="flex:1">
                <div class="coin-modal-name">${coin.name}</div>
                <div class="coin-modal-sym">${coin.symbol} · Rang #${coin.market_cap_rank||'?'}</div>
            </div>
            <div>
                <div class="coin-price-big">${formatPrice(coin.current_price)}</div>
                <div style="text-align:right"><span class="pct-cell ${p24>=0?'pct-pos':'pct-neg'}">${p24>=0?'+':''}${p24.toFixed(2)}%</span></div>
            </div>
        </div>

        <div class="coin-stats-grid">
            <div class="coin-stat"><div class="coin-stat-val">${formatLarge(coin.market_cap)}</div><div class="coin-stat-lbl">Market Cap</div></div>
            <div class="coin-stat"><div class="coin-stat-val">${formatLarge(coin.volume_24h)}</div><div class="coin-stat-lbl">Volume 24h</div></div>
            <div class="coin-stat"><div class="coin-stat-val">${formatPrice(coin.ath||0)}</div><div class="coin-stat-lbl">ATH</div></div>
        </div>

        ${analysis ? `
        <div class="analysis-card">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
                <div style="font-size:0.75rem;font-weight:700;color:var(--cyan)">ANALYSE IA</div>
                <div style="font-family:'JetBrains Mono',monospace;font-size:0.8rem;color:${analysis.sentiment_score>0?'var(--green)':analysis.sentiment_score<0?'var(--red)':'var(--text3)'};font-weight:700">
                    Score: ${analysis.sentiment_score > 0 ? '+' : ''}${analysis.sentiment_score}
                </div>
            </div>
            <div class="sentiment-bar-wrap">
                <div class="sentiment-bar-track">
                    <div class="sentiment-needle" style="left:${sentPercent}%"></div>
                </div>
            </div>
            <div style="font-size:0.8rem;color:var(--text2);line-height:1.5;margin:10px 0">${analysis.summary||''}</div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                <div>
                    <div style="font-size:0.68rem;color:var(--green);margin-bottom:4px">✦ HAUSSIER</div>
                    <ul class="factor-list">
                        ${(analysis.bullish_factors||[]).slice(0,3).map(f=>`<li class="bull"><i class="fa-solid fa-caret-up"></i>${f}</li>`).join('')}
                    </ul>
                </div>
                <div>
                    <div style="font-size:0.68rem;color:var(--red);margin-bottom:4px">✦ BAISSIER</div>
                    <ul class="factor-list">
                        ${(analysis.bearish_factors||[]).slice(0,3).map(f=>`<li class="bear"><i class="fa-solid fa-caret-down"></i>${f}</li>`).join('')}
                    </ul>
                </div>
            </div>
            <div style="margin-top:12px;padding:10px;background:rgba(0,0,0,0.3);border-radius:6px;font-size:0.8rem">
                <span style="color:var(--text3)">Recommandation: </span>
                <span style="color:var(--gold);font-weight:700;text-transform:uppercase">${analysis.recommendation||'neutre'}</span>
                <span style="color:var(--text3);margin-left:8px">Confiance: ${analysis.confidence||0}%</span>
            </div>
        </div>` : `
        <div class="analysis-card" style="text-align:center;padding:20px">
            <div style="color:var(--text3);margin-bottom:12px">Aucune analyse disponible</div>
            <button class="btn btn-primary btn-sm" onclick="fetchCoinNews('${coinId}','${coin.name}','${coin.symbol}')">
                <i class="fa-solid fa-robot"></i> Analyser avec l'IA
            </button>
        </div>`}

        <div style="font-size:0.75rem;font-weight:700;color:var(--text3);margin:16px 0 8px;letter-spacing:1px">ACTUALITÉS</div>
        ${articles.length ? articles.slice(0,8).map(a=>`
            <a href="${a.url}" target="_blank" rel="noopener" class="news-item">
                <div class="news-title">${a.title}</div>
                <div class="news-meta">
                    <span><i class="fa-solid fa-rss"></i> ${a.source||'unknown'}</span>
                    <span>${formatDate(a.published_at)}</span>
                </div>
            </a>
        `).join('') : `
            <div style="text-align:center;padding:16px">
                <button class="btn btn-ghost btn-sm" onclick="fetchCoinNews('${coinId}','${coin.name}','${coin.symbol}')">
                    <i class="fa-solid fa-newspaper"></i> Charger les actualités
                </button>
            </div>
        `}
    `;

    // Also update right sidebar
    updateRightPanel(coin, analysis, articles);
}

function updateRightPanel(coin, analysis, articles) {
    const sentPercent = analysis ? ((analysis.sentiment_score + 10) / 20) * 100 : 50;
    document.getElementById('rightPanelContent').innerHTML = `
        <div style="font-weight:700;font-size:0.9rem;margin-bottom:12px;display:flex;align-items:center;gap:8px">
            <img src="${coin.image_url}" style="width:20px;height:20px;border-radius:50%">
            ${coin.name}
        </div>
        ${analysis ? `
        <div class="analysis-card" style="padding:12px">
            <div style="font-size:0.7rem;color:var(--text3);margin-bottom:6px">Score sentiment</div>
            <div style="font-family:'JetBrains Mono',monospace;font-size:1.2rem;font-weight:700;color:${analysis.sentiment_score>0?'var(--green)':analysis.sentiment_score<0?'var(--red)':'var(--text3)'}">
                ${analysis.sentiment_score>0?'+':''}${analysis.sentiment_score} / 10
            </div>
            <div class="sentiment-bar-wrap" style="margin:8px 0">
                <div class="sentiment-bar-track">
                    <div class="sentiment-needle" style="left:${sentPercent}%"></div>
                </div>
            </div>
            <div style="font-size:0.75rem;color:var(--gold);font-weight:700;margin-top:8px">${analysis.recommendation?.toUpperCase()||'NEUTRE'}</div>
        </div>` : ''}
        <div style="font-size:0.7rem;color:var(--text3);margin:12px 0 8px;letter-spacing:1px">DERNIÈRES NEWS</div>
        ${articles.slice(0,5).map(a=>`
            <a href="${a.url}" target="_blank" rel="noopener" class="news-item">
                <div class="news-title" style="font-size:0.72rem">${a.title}</div>
                <div class="news-meta" style="font-size:0.63rem">
                    <span>${a.source||''}</span>
                    <span>${formatDate(a.published_at)}</span>
                </div>
            </a>
        `).join('')}
    `;
}

async function fetchCoinNews(coinId, coinName, symbol) {
    showToast('Récupération des actualités...', 'info');
    const data = await api('fetch_news', { coin_id: coinId, coin_name: coinName, symbol: symbol });
    if (data.success) {
        showToast('Actualités analysées !', 'success');
        openCoin(coinId);
    } else {
        showToast('Erreur: ' + (data.error||'inconnue'), 'error');
    }
}

// ══════════════════════════════════════════════
// CREATE AGENT
// ══════════════════════════════════════════════
async function doCreateAgent() {
    const name   = document.getElementById('agentName').value.trim();
    const type   = document.getElementById('agentType').value;
    const prompt = document.getElementById('agentPrompt').value.trim();
    if (!name || !prompt) { showToast('Nom et stratégie requis', 'error'); return; }

    const data = await api('create_agent', { name, strategy_type: type, strategy_prompt: prompt });
    if (data.success) {
        showToast('Agent créé !', 'success');
        closeModal('createAgentModal');
        document.getElementById('agentName').value = '';
        document.getElementById('agentPrompt').value = '';
        await loadAgents();
    } else {
        showToast(data.error || 'Erreur', 'error');
    }
}

async function generateRandomAgent() {
    showToast('Génération d\'une stratégie aléatoire...', 'info');
    const strategies = [
        { name: 'NEXUS Flash Trader', type:'scalping', prompt:'Tu es un scalper ultra-rapide spécialisé sur BTC. Tu entres sur chaque breakout de résistance avec stop loss 0.5%, target 1.5%.' },
        { name: 'AURORA DeFi Hunter', type:'long_term', prompt:'Tu identifies les projets DeFi sous-évalués avec TVL croissant et forte adoption. Tu construis des positions long terme.' },
        { name: 'VEGA Sentiment Bot', type:'momentum', prompt:'Tu trades sur le sentiment Twitter et Google Trends. Quand le buzz monte, tu achètes. Tu sors vite si le sentiment retourne.' },
        { name: 'ORION Risk Manager', type:'swing', prompt:'Tu gères le risque avant tout. Max 2% de capital par trade, diversification sur 5 coins minimum, cut losses rapide.' }
    ];
    const s = strategies[Math.floor(Math.random() * strategies.length)];
    document.getElementById('agentName').value = s.name;
    document.getElementById('agentType').value = s.type;
    document.getElementById('agentPrompt').value = s.prompt;
    showToast('Stratégie générée !', 'success');
}

// ══════════════════════════════════════════════
// BRAIN CENTRAL
// ══════════════════════════════════════════════
document.getElementById('btnBrain').addEventListener('click', async () => {
    const btn = document.getElementById('btnBrain');
    btn.disabled = true;
    btn.innerHTML = '<div class="spinner" style="width:14px;height:14px"></div> En cours...';
    document.getElementById('brainLog').textContent = '⟳ Cycle cerveau en cours...';

    const data = await api('run_brain');
    btn.disabled = false;
    btn.innerHTML = '<i class="fa-solid fa-robot"></i> Cerveau IA';

    if (data.success) {
        const log = data.log;
        document.getElementById('brainLog').textContent = `✓ ${log.created} créés · ${log.archived} archivés · ${(log.actions||[]).join(' · ')}`;
        document.getElementById('brainLastRun').textContent = new Date().toLocaleTimeString('fr-FR');
        showToast(`Cerveau: ${log.created} agents créés, ${log.archived} archivés`, 'success');
        await loadAgents();
    }
});

// ══════════════════════════════════════════════
// AUTH
// ══════════════════════════════════════════════
async function checkAuth() {
    const data = await api('me');
    if (data.success && data.user) {
        state.user = data.user;
        document.getElementById('authSection').innerHTML = `
            <div style="display:flex;align-items:center;gap:10px">
                <span style="font-size:0.8rem;color:var(--text2)">${data.user.username}</span>
                <button class="btn btn-ghost btn-sm" onclick="doLogout()">Déconnexion</button>
            </div>`;
        document.getElementById('headerCapital').innerHTML = `<i class="fa-solid fa-coins"></i> ${formatPrice(data.user.capital_virtual)}`;
    }
}

async function doLogin() {
    const email = document.getElementById('loginEmail').value;
    const pwd   = document.getElementById('loginPwd').value;
    const data  = await api('login', { email, password: pwd });
    if (data.success) {
        closeModal('authModal');
        checkAuth();
        showToast('Connecté !', 'success');
    } else {
        showToast(data.error || 'Erreur', 'error');
    }
}

async function doRegister() {
    const email    = document.getElementById('regEmail').value;
    const pwd      = document.getElementById('regPwd').value;
    const username = document.getElementById('regName').value;
    const data     = await api('register', { email, password: pwd, username });
    if (data.success) {
        closeModal('authModal');
        checkAuth();
        showToast('Compte créé !', 'success');
    } else {
        showToast(data.error || 'Erreur', 'error');
    }
}

async function doLogout() {
    await api('logout');
    state.user = null;
    location.reload();
}

function switchAuthTab(tab) {
    document.getElementById('loginForm').style.display = tab === 'login' ? '' : 'none';
    document.getElementById('registerForm').style.display = tab === 'register' ? '' : 'none';
    document.querySelectorAll('.tab').forEach((t,i) => t.classList.toggle('active', (i===0 && tab==='login') || (i===1 && tab==='register')));
}

// ══════════════════════════════════════════════
// STATUS & REFRESH
// ══════════════════════════════════════════════
async function loadStatus() {
    const data = await api('system_status');
    if (!data.success) return;
    const s = data.status;
    const isLive = s.market_live;
    document.getElementById('liveDot').style.background = isLive ? 'var(--green)' : 'var(--red)';
    document.getElementById('liveLabel').style.color = isLive ? 'var(--green)' : 'var(--red)';
    if (s.last_brain_run > 0) {
        document.getElementById('brainLastRun').textContent = new Date(s.last_brain_run * 1000).toLocaleTimeString('fr-FR');
    }
}

function startCountdown() {
    let secs = 60;
    setInterval(() => {
        secs--;
        if (secs < 0) secs = 60;
        document.getElementById('countdownSecs').textContent = secs;
    }, 1000);
}

function startAutoRefresh() {
    // Refresh market every 60s
    setInterval(async () => {
        await api('update_market');
        await loadCoins();
        await loadStatus();
    }, 60000);

    // Refresh agents every 30s
    setInterval(loadAgents, 30000);
}

function quickTrade(coinId, action) {
    showToast(`${action.toUpperCase()} ${coinId} — Ordre simulé envoyé`, action==='buy'?'success':'error');
}

// ══════════════════════════════════════════════
// MODALS
// ══════════════════════════════════════════════
function openModal(id) {
    document.getElementById(id).classList.add('open');
}
function closeModal(id) {
    document.getElementById(id).classList.remove('open');
}
document.querySelectorAll('.modal-overlay').forEach(m => {
    m.addEventListener('click', e => { if (e.target === m) m.classList.remove('open'); });
});

// ══════════════════════════════════════════════
// UTILS
// ══════════════════════════════════════════════
function formatPrice(v) {
    if (!v && v !== 0) return '—';
    if (v >= 1000) return v.toLocaleString('fr-FR', {maximumFractionDigits:2}) + ' €';
    if (v >= 1) return v.toLocaleString('fr-FR', {minimumFractionDigits:2, maximumFractionDigits:4}) + ' €';
    return v.toLocaleString('fr-FR', {minimumFractionDigits:4, maximumFractionDigits:8}) + ' €';
}
function formatLarge(v) {
    if (!v) return '—';
    if (v >= 1e12) return (v/1e12).toFixed(2) + ' T€';
    if (v >= 1e9)  return (v/1e9).toFixed(2) + ' Md€';
    if (v >= 1e6)  return (v/1e6).toFixed(2) + ' M€';
    if (v >= 1e3)  return (v/1e3).toFixed(2) + ' K€';
    return v.toFixed(2) + ' €';
}
function formatDate(ts) {
    if (!ts) return '—';
    const d = new Date(ts * 1000);
    return d.toLocaleDateString('fr-FR', { day:'2-digit', month:'short', hour:'2-digit', minute:'2-digit' });
}

function showToast(msg, type='info') {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.className = type;
    t.style.display = 'block';
    clearTimeout(t._timer);
    t._timer = setTimeout(() => { t.style.display = 'none'; }, 3500);
}

// ── START ──
init();
</script>
</body>
</html>
