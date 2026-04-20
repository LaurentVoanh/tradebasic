<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NEXUS TRADER - IA Trading Autonome</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root {
            --bg-primary: #0a0e27;
            --bg-secondary: #1a1f4e;
            --bg-card: rgba(255,255,255,0.05);
            --accent-cyan: #00d4ff;
            --accent-purple: #7b2cbf;
            --accent-pink: #f72585;
            --success: #00ff88;
            --danger: #ff4757;
            --text-muted: #8892b0;
        }
        
        body {
            background: linear-gradient(135deg, var(--bg-primary) 0%, var(--bg-secondary) 50%, #0d1b2a 100%);
            color: #fff;
            min-height: 100vh;
        }
        
        .card {
            background: var(--bg-card);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 15px;
            transition: transform 0.3s, box-shadow 0.3s;
        }
        
        .card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 40px rgba(0,212,255,0.2);
        }
        
        .stat-value { font-size: 2em; font-weight: bold; }
        .text-cyan { color: var(--accent-cyan) !important; }
        .text-success-glow { color: var(--success); text-shadow: 0 0 10px var(--success); }
        .text-danger-glow { color: var(--danger); text-shadow: 0 0 10px var(--danger); }
        
        .table { color: #fff; }
        .table th { color: var(--text-muted); text-transform: uppercase; font-size: 0.8em; }
        .table td, .table th { border-color: rgba(255,255,255,0.05); }
        .table-hover tbody tr:hover { background: rgba(255,255,255,0.05); }
        
        .coin-icon { width: 24px; height: 24px; border-radius: 50%; margin-right: 8px; }
        
        .feed-item {
            padding: 12px;
            border-left: 3px solid;
            margin-bottom: 10px;
            background: rgba(255,255,255,0.03);
            animation: slideIn 0.3s ease;
        }
        @keyframes slideIn { from { opacity: 0; transform: translateX(-20px); } to { opacity: 1; transform: translateX(0); } }
        .feed-buy { border-color: var(--success); }
        .feed-sell { border-color: var(--danger); }
        .feed-info { border-color: var(--accent-cyan); }
        
        .status-dot {
            width: 10px; height: 10px; border-radius: 50%; display: inline-block;
            margin-right: 8px;
        }
        .status-active { background: var(--success); box-shadow: 0 0 10px var(--success); }
        .status-running { background: var(--accent-cyan); box-shadow: 0 0 10px var(--accent-cyan); animation: pulse 1.5s infinite; }
        @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.5; } }
        
        .loading-spinner {
            width: 20px; height: 20px; border: 2px solid rgba(255,255,255,0.3);
            border-top-color: var(--accent-cyan); border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
        
        h1 {
            background: linear-gradient(90deg, var(--accent-cyan), var(--accent-purple), var(--accent-pink));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            animation: glow 2s ease-in-out infinite alternate;
        }
        @keyframes glow { from { text-shadow: 0 0 20px rgba(0,212,255,0.5); } to { text-shadow: 0 0 40px rgba(123,44,191,0.8); } }
        
        ::-webkit-scrollbar { width: 8px; }
        ::-webkit-scrollbar-track { background: rgba(255,255,255,0.05); }
        ::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.2); border-radius: 4px; }
    </style>
</head>
<body>
<div class="container py-4">
    <header class="text-center mb-5">
        <h1 class="display-4"><i class="bi bi-robot"></i> NEXUS TRADER</h1>
        <p class="text-muted">Système de Trading Autonome par Intelligence Artificielle</p>
        <span class="badge bg-primary"><span class="status-dot status-running"></span>En ligne</span>
    </header>
    
    <!-- Stats Cards -->
    <div class="row g-4 mb-4">
        <div class="col-md-4 col-lg-2">
            <div class="card h-100 p-3">
                <div class="text-muted text-uppercase small">Capital Total</div>
                <div class="stat-value text-cyan" id="total-capital">--</div>
            </div>
        </div>
        <div class="col-md-4 col-lg-2">
            <div class="card h-100 p-3">
                <div class="text-muted text-uppercase small">PnL Total</div>
                <div class="stat-value" id="total-pnl">--</div>
            </div>
        </div>
        <div class="col-md-4 col-lg-2">
            <div class="card h-100 p-3">
                <div class="text-muted text-uppercase small">Agents Actifs</div>
                <div class="stat-value text-cyan" id="agents-count">--</div>
            </div>
        </div>
        <div class="col-md-4 col-lg-2">
            <div class="card h-100 p-3">
                <div class="text-muted text-uppercase small">Trades</div>
                <div class="stat-value" id="total-trades">--</div>
            </div>
        </div>
        <div class="col-md-4 col-lg-2">
            <div class="card h-100 p-3">
                <div class="text-muted text-uppercase small">Win Rate</div>
                <div class="stat-value" id="win-rate">--%</div>
            </div>
        </div>
        <div class="col-md-4 col-lg-2">
            <div class="card h-100 p-3">
                <div class="text-muted text-uppercase small">Positions</div>
                <div class="stat-value" id="open-positions">--</div>
            </div>
        </div>
    </div>
    
    <div class="row g-4">
        <!-- Left Column -->
        <div class="col-lg-8">
            <!-- Market Table -->
            <div class="card p-3 mb-4">
                <h5 class="mb-3"><i class="bi bi-graph-up"></i> Marché Crypto</h5>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr><th>#</th><th>Coin</th><th>Prix</th><th>24h %</th><th>7d %</th><th>Market Cap</th></tr>
                        </thead>
                        <tbody id="coins-body">
                            <tr><td colspan="6"><span class="loading-spinner"></span> Chargement...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <!-- Console Logs -->
            <div class="card p-3">
                <h5 class="mb-3"><i class="bi bi-terminal"></i> Journal d'Activité</h5>
                <div id="console-logs" style="max-height: 250px; overflow-y: auto;">
                    <div class="feed-item feed-info"><small class="text-muted">Initialisation...</small></div>
                </div>
            </div>
        </div>
        
        <!-- Right Column -->
        <div class="col-lg-4">
            <!-- Agents -->
            <div class="card p-3 mb-4">
                <h5 class="mb-3"><i class="bi bi-people"></i> Agents IA</h5>
                <div id="agents-list" style="max-height: 300px; overflow-y: auto;">
                    <div class="text-center text-muted py-3"><span class="loading-spinner"></span></div>
                </div>
            </div>
            
            <!-- Positions -->
            <div class="card p-3 mb-4">
                <h5 class="mb-3"><i class="bi bi-briefcase"></i> Positions</h5>
                <div id="positions-list" style="max-height: 200px; overflow-y: auto;">
                    <div class="text-center text-muted py-3">Aucune position</div>
                </div>
            </div>
            
            <!-- Trades Feed -->
            <div class="card p-3">
                <h5 class="mb-3"><i class="bi bi-lightning"></i> Derniers Trades</h5>
                <div id="trades-feed" style="max-height: 200px; overflow-y: auto;">
                    <div class="feed-item feed-info"><small class="text-muted">En attente...</small></div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
const API_URL = 'api.php';
const TRIGGER_URL = 'trigger.php';

function fmt(n, d=2) { return new Intl.NumberFormat('fr-FR', {minimumFractionDigits:d, maximumFractionDigits:d}).format(n||0); }
function fmtCurr(n) { return fmt(n) + ' $'; }
function fmtPct(n) { return fmt(n) + '%'; }

async function fetchAPI(action, params={}) {
    try {
        const url = new URL(API_URL);
        url.searchParams.set('action', action);
        Object.entries(params).forEach(([k,v]) => url.searchParams.set(k,v));
        const res = await fetch(url);
        return await res.json();
    } catch(e) { console.error(e); return null; }
}

async function updateStats() {
    const data = await fetchAPI('stats');
    if(data && data.success) {
        const s = data.data;
        document.getElementById('total-capital').textContent = fmtCurr(s.total_capital);
        const pnlEl = document.getElementById('total-pnl');
        pnlEl.textContent = (s.total_pnl>=0?'+':'') + fmtCurr(s.total_pnl);
        pnlEl.className = 'stat-value ' + (s.total_pnl>=0?'text-success-glow':'text-danger-glow');
        document.getElementById('agents-count').textContent = s.agents_count;
        document.getElementById('total-trades').textContent = s.total_trades;
        const wrEl = document.getElementById('win-rate');
        wrEl.textContent = fmtPct(s.win_rate);
        wrEl.className = 'stat-value ' + (s.win_rate>=50?'text-success-glow':'text-danger-glow');
        document.getElementById('open-positions').textContent = s.open_positions;
    }
}

async function updateCoins() {
    const data = await fetchAPI('coins', {limit:15});
    if(data && data.success) {
        document.getElementById('coins-body').innerHTML = data.data.map((c,i)=>`
            <tr>
                <td>${c.market_cap_rank||'-'}</td>
                <td><img src="${c.image_url||''}" class="coin-icon">${c.symbol}<small class="text-muted">${c.name}</small></td>
                <td>$${fmt(c.price, c.price<1?6:2)}</td>
                <td class="${(c.change_24h||0)>=0?'text-success-glow':'text-danger-glow'}">${fmtPct(c.change_24h||0)}</td>
                <td class="${(c.change_7d||0)>=0?'text-success-glow':'text-danger-glow'}">${fmtPct(c.change_7d||0)}</td>
                <td>$${fmt(c.market_cap,0)}</td>
            </tr>
        `).join('');
    }
}

async function updateAgents() {
    const data = await fetchAPI('agents');
    if(data && data.success) {
        const el = document.getElementById('agents-list');
        if(!data.data.length) {
            el.innerHTML = '<div class="text-center text-muted py-3">Aucun agent actif</div>';
        } else {
            el.innerHTML = data.data.map(a=>`
                <div class="p-2 mb-2" style="background:rgba(255,255,255,0.03);border-radius:8px;">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <strong>${a.name}</strong><br>
                            <small class="text-muted">${a.dna}</small>
                        </div>
                        <div class="text-end">
                            <div class="${(a.total_pnl||0)>=0?'text-success-glow':'text-danger-glow'}">
                                ${(a.total_pnl||0)>=0?'+':''}${fmt(a.total_pnl||0)}
                            </div>
                            <small class="text-muted">${a.total_trades} trades</small>
                        </div>
                    </div>
                </div>
            `).join('');
        }
    }
}

async function updateTrades() {
    const data = await fetchAPI('trades', {limit:10});
    if(data && data.success) {
        const el = document.getElementById('trades-feed');
        if(!data.data.length) {
            el.innerHTML = '<div class="feed-item feed-info"><small class="text-muted">En attente de trades...</small></div>';
        } else {
            el.innerHTML = data.data.map(t=>`
                <div class="feed-item feed-${t.type==='buy'?'buy':'sell'}">
                    <strong>${t.type.toUpperCase()}</strong> ${t.coin_symbol}
                    <small class="text-muted d-block">$${fmt(t.price)} × ${fmt(t.amount)}</small>
                </div>
            `).join('');
        }
    }
}

async function updatePositions() {
    const data = await fetchAPI('positions');
    if(data && data.success) {
        const el = document.getElementById('positions-list');
        if(!data.data.length) {
            el.innerHTML = '<div class="text-center text-muted py-3">Aucune position ouverte</div>';
        } else {
            el.innerHTML = data.data.map(p=>`
                <div class="p-2 mb-2" style="background:rgba(255,255,255,0.03);border-radius:8px;">
                    <div class="d-flex justify-content-between">
                        <div><strong>${p.coin_symbol}</strong><br><small class="text-muted">${p.agent_name}</small></div>
                        <div class="${(p.pnl||0)>=0?'text-success-glow':'text-danger-glow'}">
                            ${(p.pnl||0)>=0?'+':''}${fmt(p.pnl||0)} (${fmtPct(p.pnl_percent||0)})
                        </div>
                    </div>
                </div>
            `).join('');
        }
    }
}

async function updateLogs() {
    const data = await fetchAPI('logs', {limit:20});
    if(data && data.success) {
        document.getElementById('console-logs').innerHTML = data.data.map(l=>{
            const levelClass = l.level==='error'?'feed-sell':(l.level==='warning'?'feed-info':'feed-info');
            return `<div class="feed-item ${levelClass}">
                <small class="text-muted">${new Date(l.timestamp*1000).toLocaleTimeString()}</small>
                ${l.message}
            </div>`;
        }).join('');
    }
}

async function triggerBrain() {
    try { await fetch(TRIGGER_URL); } catch(e) {}
}

// Main loop
async function refreshAll() {
    await Promise.all([updateStats(), updateCoins(), updateAgents(), updateTrades(), updatePositions(), updateLogs()]);
}

// Initial load
refreshAll();

// Polling toutes les 3 secondes
setInterval(refreshAll, 3000);

// Trigger brain cycle toutes les 10 secondes
setInterval(triggerBrain, 10000);
</script>
</body>
</html>
