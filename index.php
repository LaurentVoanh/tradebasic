<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>IA Crypto Invest - Trading Autonome en Temps Réel</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', system-ui, sans-serif;
            background: linear-gradient(135deg, #0a0e27 0%, #1a1f4e 50%, #0d1b2a 100%);
            color: #fff;
            min-height: 100vh;
            overflow-x: hidden;
        }
        .container { max-width: 1600px; margin: 0 auto; padding: 20px; }
        
        /* Header */
        header {
            text-align: center;
            padding: 30px 0;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            margin-bottom: 30px;
        }
        h1 {
            font-size: 2.5em;
            background: linear-gradient(90deg, #00d4ff, #7b2cbf, #f72585);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            animation: glow 2s ease-in-out infinite alternate;
        }
        @keyframes glow { from { text-shadow: 0 0 20px rgba(0,212,255,0.5); } to { text-shadow: 0 0 40px rgba(123,44,191,0.8); } }
        .subtitle { color: #8892b0; margin-top: 10px; font-size: 1.1em; }
        
        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .stat-card {
            background: rgba(255,255,255,0.05);
            backdrop-filter: blur(10px);
            border-radius: 15px;
            padding: 25px;
            border: 1px solid rgba(255,255,255,0.1);
            transition: transform 0.3s, box-shadow 0.3s;
        }
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 40px rgba(0,212,255,0.2);
        }
        .stat-value {
            font-size: 2em;
            font-weight: bold;
            margin: 10px 0;
        }
        .stat-label { color: #8892b0; font-size: 0.9em; text-transform: uppercase; letter-spacing: 1px; }
        .positive { color: #00ff88; }
        .negative { color: #ff4757; }
        .neutral { color: #00d4ff; }
        
        /* Main Grid */
        .main-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 20px;
            margin-bottom: 30px;
        }
        @media (max-width: 1200px) { .main-grid { grid-template-columns: 1fr; } }
        
        /* Panels */
        .panel {
            background: rgba(255,255,255,0.05);
            backdrop-filter: blur(10px);
            border-radius: 15px;
            padding: 20px;
            border: 1px solid rgba(255,255,255,0.1);
            margin-bottom: 20px;
        }
        .panel-title {
            font-size: 1.3em;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .panel-title::before {
            content: '';
            width: 4px;
            height: 20px;
            background: linear-gradient(180deg, #00d4ff, #7b2cbf);
            border-radius: 2px;
        }
        
        /* Tables */
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid rgba(255,255,255,0.05); }
        th { color: #8892b0; font-weight: 600; text-transform: uppercase; font-size: 0.8em; }
        tr:hover { background: rgba(255,255,255,0.05); }
        .coin-icon { width: 24px; height: 24px; vertical-align: middle; margin-right: 8px; border-radius: 50%; }
        
        /* Live Feed */
        .live-feed {
            max-height: 400px;
            overflow-y: auto;
        }
        .feed-item {
            padding: 12px;
            border-left: 3px solid;
            margin-bottom: 10px;
            background: rgba(255,255,255,0.03);
            animation: slideIn 0.3s ease;
        }
        @keyframes slideIn { from { opacity: 0; transform: translateX(-20px); } to { opacity: 1; transform: translateX(0); } }
        .feed-buy { border-color: #00ff88; }
        .feed-sell { border-color: #ff4757; }
        .feed-info { border-color: #00d4ff; }
        .feed-time { color: #8892b0; font-size: 0.8em; }
        
        /* Agents List */
        .agent-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px;
            background: rgba(255,255,255,0.03);
            border-radius: 10px;
            margin-bottom: 10px;
            transition: all 0.3s;
        }
        .agent-item:hover { background: rgba(255,255,255,0.08); }
        .agent-name { font-weight: 600; margin-bottom: 5px; }
        .agent-stats { font-size: 0.85em; color: #8892b0; }
        .agent-pnl { font-weight: bold; }
        
        /* Chart Placeholder */
        .chart-container {
            height: 300px;
            background: rgba(0,0,0,0.2);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
        }
        canvas { width: 100% !important; height: 100% !important; }
        
        /* Loading Animation */
        .loading {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 2px solid rgba(255,255,255,0.3);
            border-top-color: #00d4ff;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
        
        /* Status Indicator */
        .status-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            display: inline-block;
            margin-right: 8px;
        }
        .status-active { background: #00ff88; box-shadow: 0 0 10px #00ff88; }
        .status-running { background: #00d4ff; box-shadow: 0 0 10px #00d4ff; animation: pulse 1.5s infinite; }
        @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.5; } }
        
        /* Scrollbar */
        ::-webkit-scrollbar { width: 8px; }
        ::-webkit-scrollbar-track { background: rgba(255,255,255,0.05); }
        ::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.2); border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: rgba(255,255,255,0.3); }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <h1>🤖 IA CRYPTO INVEST</h1>
            <p class="subtitle">Système de Trading Autonome par Intelligence Artificielle • 1,000,000 BRICS</p>
        </header>
        
        <!-- Stats Overview -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-label">Capital Total</div>
                <div class="stat-value neutral" id="total-capital">-- BRICS</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">PnL Total</div>
                <div class="stat-value" id="total-pnl">-- BRICS</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Agents Actifs</div>
                <div class="stat-value neutral" id="agents-count">--</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Trades Exécutés</div>
                <div class="stat-value" id="total-trades">--</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Win Rate</div>
                <div class="stat-value" id="win-rate">--%</div>
            </div>
        </div>
        
        <div class="main-grid">
            <!-- Left Column -->
            <div>
                <!-- Market Overview -->
                <div class="panel">
                    <div class="panel-title">📊 Marché Crypto en Temps Réel</div>
                    <div style="overflow-x: auto;">
                        <table id="coins-table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Coin</th>
                                    <th>Prix</th>
                                    <th>24h %</th>
                                    <th>7d %</th>
                                    <th>Market Cap</th>
                                </tr>
                            </thead>
                            <tbody id="coins-body">
                                <tr><td colspan="6"><span class="loading"></span> Chargement...</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <!-- Live Trades Feed -->
                <div class="panel">
                    <div class="panel-title">⚡ Flux de Trades en Direct</div>
                    <div class="live-feed" id="trades-feed">
                        <div class="feed-item feed-info">
                            <div class="feed-time">En attente de trades...</div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Right Column -->
            <div>
                <!-- Active Agents -->
                <div class="panel">
                    <div class="panel-title">👥 Agents IA Actifs</div>
                    <div id="agents-list" style="max-height: 500px; overflow-y: auto;">
                        <div style="text-align: center; padding: 20px; color: #8892b0;">
                            <span class="loading"></span> Chargement...
                        </div>
                    </div>
                </div>
                
                <!-- Open Positions -->
                <div class="panel">
                    <div class="panel-title">📈 Positions Ouvertes</div>
                    <div id="positions-list" style="max-height: 300px; overflow-y: auto;">
                        <div style="text-align: center; padding: 20px; color: #8892b0;">
                            Aucune position
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Console Logs -->
        <div class="panel">
            <div class="panel-title">📜 Journal d'Activité</div>
            <div class="live-feed" id="console-logs" style="max-height: 200px;">
                <div class="feed-item feed-info">
                    <div class="feed-time">Initialisation du système...</div>
                </div>
            </div>
        </div>
    </div>

    <script>
        const API_URL = 'api.php';
        
        // Format numbers
        function formatNumber(num, decimals = 2) {
            return new Intl.NumberFormat('fr-FR', { minimumFractionDigits: decimals, maximumFractionDigits: decimals }).format(num);
        }
        
        function formatCurrency(num) {
            return formatNumber(num) + ' BRICS';
        }
        
        function formatTime(timestamp) {
            return new Date(timestamp * 1000).toLocaleTimeString('fr-FR');
        }
        
        // Fetch data
        async function fetchData(action, params = {}) {
            try {
                const url = new URL(API_URL);
                url.searchParams.set('action', action);
                Object.keys(params).forEach(k => url.searchParams.set(k, params[k]));
                const res = await fetch(url);
                if (!res.ok) {
                    throw new Error('HTTP error ' + res.status);
                }
                return await res.json();
            } catch (e) {
                console.error('Fetch error for ' + action + ':', e);
                return null;
            }
        }
        
        // Update stats
        async function updateStats() {
            const data = await fetchData('stats');
            if (data && data.success) {
                const s = data.data;
                document.getElementById('total-capital').textContent = formatCurrency(s.total_capital);
                const pnlEl = document.getElementById('total-pnl');
                pnlEl.textContent = (s.total_pnl >= 0 ? '+' : '') + formatCurrency(s.total_pnl);
                pnlEl.className = 'stat-value ' + (s.total_pnl >= 0 ? 'positive' : 'negative');
                document.getElementById('agents-count').textContent = s.agents_count;
                document.getElementById('total-trades').textContent = s.total_trades;
                const wrEl = document.getElementById('win-rate');
                wrEl.textContent = formatNumber(s.win_rate) + '%';
                wrEl.className = 'stat-value ' + (s.win_rate >= 50 ? 'positive' : 'negative');
            }
        }
        
        // Update coins table
        async function updateCoins() {
            const data = await fetchData('coins', { limit: 20 });
            if (data && data.success) {
                const tbody = document.getElementById('coins-body');
                tbody.innerHTML = data.data.map((c, i) => `
                    <tr>
                        <td>${c.market_cap_rank || '-'}</td>
                        <td><img src="${c.image_url}" class="coin-icon" alt="">${c.symbol} <span style="color:#8892b0">${c.name}</span></td>
                        <td>$${formatNumber(c.current_price, c.current_price < 1 ? 6 : 2)}</td>
                        <td class="${c.price_change_pct_24h >= 0 ? 'positive' : 'negative'}">${formatNumber(c.price_change_pct_24h)}%</td>
                        <td class="${c.price_change_7d >= 0 ? 'positive' : 'negative'}">${formatNumber(c.price_change_7d)}%</td>
                        <td>$${formatNumber(c.market_cap, 0)}</td>
                    </tr>
                `).join('');
            }
        }
        
        // Update agents list
        async function updateAgents() {
            const data = await fetchData('agents');
            if (data && data.success) {
                const container = document.getElementById('agents-list');
                if (data.data.length === 0) {
                    container.innerHTML = '<div style="text-align:center;padding:20px;color:#8892b0;">Aucun agent actif</div>';
                } else {
                    container.innerHTML = data.data.map(a => `
                        <div class="agent-item">
                            <div>
                                <div class="agent-name">${a.name}</div>
                                <div class="agent-stats">Capital: ${formatCurrency(a.capital_brics)} • Trades: ${a.total_trades}</div>
                            </div>
                            <div class="agent-pnl ${a.total_pnl >= 0 ? 'positive' : 'negative'}">
                                ${a.total_pnl >= 0 ? '+' : ''}${formatNumber(a.total_pnl)}
                            </div>
                        </div>
                    `).join('');
                }
            }
        }
        
        // Update trades feed
        async function updateTrades() {
            const data = await fetchData('trades', { limit: 20 });
            if (data && data.success) {
                const container = document.getElementById('trades-feed');
                if (data.data.length === 0) {
                    container.innerHTML = '<div class="feed-item feed-info"><div class="feed-time">En attente de trades...</div></div>';
                } else {
                    container.innerHTML = data.data.map(t => `
                        <div class="feed-item feed-${t.action}">
                            <strong>${t.action.toUpperCase()}</strong> - ${t.coin_symbol}
                            <div style="font-size:0.9em;margin-top:5px;">
                                Prix: $${formatNumber(t.price)} • Quantité: ${formatNumber(t.quantity)} • Valeur: ${formatCurrency(t.value_brics)}
                                ${t.pnl !== 0 ? ` • PnL: ${formatNumber(t.pnl)} (${formatNumber(t.pnl_percent)}%)` : ''}
                            </div>
                            <div class="feed-time">${formatTime(t.executed_at)}</div>
                        </div>
                    `).join('');
                }
            }
        }
        
        // Update positions
        async function updatePositions() {
            const data = await fetchData('positions');
            if (data && data.success) {
                const container = document.getElementById('positions-list');
                if (data.data.length === 0) {
                    container.innerHTML = '<div style="text-align:center;padding:20px;color:#8892b0;">Aucune position ouverte</div>';
                } else {
                    container.innerHTML = data.data.map(p => `
                        <div class="agent-item">
                            <div>
                                <div class="agent-name">${p.coin_symbol} <span style="color:#8892b0;font-weight:normal;">via ${p.agent_name}</span></div>
                                <div class="agent-stats">Qty: ${formatNumber(p.quantity)} • Avg: $${formatNumber(p.avg_buy_price)}</div>
                            </div>
                            <div class="agent-pnl ${p.unrealized_pnl >= 0 ? 'positive' : 'negative'}">
                                ${p.unrealized_pnl >= 0 ? '+' : ''}${formatNumber(p.unrealized_pnl)}
                            </div>
                        </div>
                    `).join('');
                }
            }
        }
        
        // Update console logs
        async function updateLogs() {
            const data = await fetchData('logs', { limit: 30 });
            if (data && data.success) {
                const container = document.getElementById('console-logs');
                container.innerHTML = data.data.map(l => {
                    const d = JSON.parse(l.data || '{}');
                    let type = 'info';
                    if (l.log_type.includes('buy')) type = 'buy';
                    if (l.log_type.includes('sell')) type = 'sell';
                    return `
                        <div class="feed-item feed-${type}">
                            <strong>${l.log_type}</strong>: ${l.message}
                            <div class="feed-time">${formatTime(l.created_at)}</div>
                        </div>
                    `;
                }).join('');
            }
        }
        
        // Trigger brain cycle via AJAX (no cron needed)
        async function triggerCycle() {
            await fetchData('run_cycle');
        }
        
        // Initial market update
        async function updateMarket() {
            await fetchData('update_market');
        }
        
        // Main loop
        async function mainLoop() {
            await updateStats();
            await updateCoins();
            await updateAgents();
            await updateTrades();
            await updatePositions();
            await updateLogs();
        }
        
        // Start - Initial load with error handling and logging
        async function init() {
            addLog('info', '🚀 Démarrage du système IA Crypto Invest...');
            try {
                addLog('info', '📡 Mise à jour du marché crypto...');
                await updateMarket();
                addLog('info', '📊 Chargement des données principales...');
                await mainLoop();
                
                // Force first brain cycle to create initial agents
                addLog('info', '🧠 Initialisation du cerveau IA...');
                await triggerCycle();
                
                addLog('success', '✅ Système prêt - Agents IA en cours de déploiement');
            } catch (e) {
                console.error('Initial load error:', e);
                addLog('error', '⚠️ Erreur au chargement: ' + e.message);
            }
        }
        
        // Add log helper
        function addLog(type, message) {
            const container = document.getElementById('console-logs');
            const div = document.createElement('div');
            div.className = 'feed-item feed-' + type;
            div.innerHTML = '<div class="feed-time">' + new Date().toLocaleTimeString() + '</div><div>' + message + '</div>';
            container.insertBefore(div, container.firstChild);
        }
        
        init();
        
        // Polling intervals
        setInterval(mainLoop, 3000);      // Data refresh every 3s
        setInterval(triggerCycle, 5000);   // Brain cycle every 5s for faster startup
        
        // Auto-scroll feeds
        setInterval(() => {
            const feeds = document.querySelectorAll('.live-feed');
            feeds.forEach(f => f.scrollTop = f.scrollHeight);
        }, 1000);
    </script>
</body>
</html>
