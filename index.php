<?php
session_start();
require_once __DIR__ . '/functions.php';

// Initialiser les bases de données et afficher les erreurs pour le débogage
try {
    initDatabases();
    initDefaultAgents();
    $lastUpdate = (int)(getDB()->query("SELECT value FROM system_config WHERE key='last_market_update'")->fetchColumn() ?: 0);
    // Mettre à jour si dernière mise à jour > 60s OU si aucune crypto n'est en base
    $coinCount = (int)(getDB()->query("SELECT COUNT(*) FROM coins")->fetchColumn() ?: 0);
    if ((time() - $lastUpdate) > 60 || $coinCount === 0) {
        updateMarketData();
    }
} catch (Throwable $e) {
    // En production, on loggue l'erreur mais on continue
    error_log("Erreur d'initialisation: " . $e->getMessage());
}

// Récupérer les statistiques du système
try {
    $stats = getSystemStats();
} catch (Throwable $e) {
    // Valeurs par défaut en cas d'erreur
    $stats = [
        'total_capital'   => INITIAL_CAPITAL,
        'pool_capital'    => INITIAL_CAPITAL,
        'agents_capital'  => 0,
        'total_pnl'       => 0,
        'agents_count'    => 0,
        'total_trades'    => 0,
        'win_rate'        => 0
    ];
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>IA CRYPTO INVEST</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<style>
:root{--bg:#f8f9fa;--white:#fff;--cyan:#0dcaf0;--green:#198754;--purple:#6f42c1;--gold:#ffc107;--border:#dee2e6}
body{font-family:'Segoe UI',system-ui,sans-serif;background:var(--bg);min-height:100vh}
.header{background:var(--white);border-bottom:2px solid var(--cyan);padding:.75rem 1.5rem;position:sticky;top:0;z-index:100;box-shadow:0 2px 8px rgba(0,0,0,.08)}
.logo{display:flex;align-items:center;gap:12px;font-weight:700;color:var(--purple)}
.logo i{width:40px;height:40px;background:linear-gradient(135deg,var(--cyan),var(--purple));border-radius:10px;display:flex;align-items:center;justify-content:center;color:#fff}
.live{display:inline-flex;align-items:center;gap:6px;padding:4px 12px;background:rgba(25,135,84,.1);border-radius:20px;font-size:.75rem;font-weight:600;color:var(--green)}
.dot{width:8px;height:8px;background:var(--green);border-radius:50%;animation:pulse 2s infinite}
@keyframes pulse{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.5;transform:scale(1.2)}}
.capital{font-family:'Courier New',monospace;font-size:1rem;font-weight:700;color:var(--gold);background:rgba(255,193,7,.1);padding:6px 14px;border-radius:8px;border:1px solid rgba(255,193,7,.3)}
.main{display:grid;grid-template-columns:350px 1fr 380px;height:calc(100vh - 70px);overflow:hidden}
@media(max-width:1200px){.main{grid-template-columns:1fr;height:auto}}
.panel{background:var(--white);border-right:1px solid var(--border);overflow-y:auto;padding:1rem}
.panel:last-child{border-right:none;border-left:1px solid var(--border)}
.panel-h{display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem;padding-bottom:.75rem;border-bottom:2px solid var(--border)}
.panel-t{font-size:.85rem;font-weight:700;text-transform:uppercase;color:#6c757d;display:flex;align-items:center;gap:8px}
.console{background:#1e1e1e;color:#d4d4d4;font-family:'Courier New',monospace;font-size:.75rem;padding:1rem;border-radius:8px;max-height:calc(100vh - 100px);overflow-y:auto}
.log{padding:4px 0;border-bottom:1px solid #333;animation:fadeIn .3s}@keyframes fadeIn{from{opacity:0;transform:translateX(-10px)}to{opacity:1;transform:translateX(0)}}
.log-t{color:#6a9955;margin-right:8px}
.log-type{display:inline-block;padding:2px 6px;border-radius:4px;font-size:.65rem;font-weight:600;margin-right:8px}
.log-type.brain_start{background:#6f42c1;color:#fff}.log-type.brain_end{background:#42c1f5;color:#fff}.log-type.trade_executed{background:#198754;color:#fff}.log-type.agent_create{background:#0dcaf0;color:#000}.log-type.agent_archive{background:#dc3545;color:#fff}.log-type.market_update{background:#ffc107;color:#000}
.agent-card{background:#e9ecef;border-radius:10px;padding:12px;margin-bottom:10px;cursor:pointer;transition:all .2s;border:2px solid transparent}
.agent-card:hover{transform:translateX(5px);border-color:var(--cyan)}
.agent-card.top{border-color:var(--gold);background:linear-gradient(135deg,rgba(255,193,7,.1),rgba(255,193,7,.05))}
.agent-name{font-weight:700;font-size:.9rem;margin-bottom:6px;display:flex;align-items:center;justify-content:space-between}
.agent-stats{display:grid;grid-template-columns:1fr 1fr;gap:6px;font-size:.75rem}
.badge-stat{padding:3px 8px;border-radius:6px;text-align:center;font-weight:600}
.badge-pnl.pos{background:rgba(25,135,84,.2);color:var(--green)}.badge-pnl.neg{background:rgba(220,53,69,.2);color:#dc3545}
.tbl{width:100%;font-size:.85rem}.tbl th{position:sticky;top:0;background:var(--white);font-weight:700;font-size:.7rem;text-transform:uppercase;color:#6c757d;padding:10px;border-bottom:2px solid var(--border)}.tbl td{padding:10px;border-bottom:1px solid var(--border)}
.coin-info{display:flex;align-items:center;gap:10px}.coin-img{width:28px;height:28px;border-radius:50%}
.up{color:var(--green)}.down{color:#dc3545}
.stats-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:12px;margin-bottom:1.5rem}
.stat-card{background:#e9ecef;border-radius:10px;padding:1rem;text-align:center}
.stat-val{font-size:1.4rem;font-weight:700;color:var(--cyan);font-family:'Courier New',monospace}
.stat-lbl{font-size:.7rem;text-transform:uppercase;color:#6c757d;margin-top:4px}
.analysis-card{background:#e9ecef;border-radius:10px;padding:1rem;margin-bottom:1rem}
@media(max-width:768px){.main{grid-template-columns:1fr}.panel{border-right:none;border-bottom:1px solid var(--border);max-height:400px}.stats-grid{grid-template-columns:1fr}}
</style>
</head>
<body>
<header class="header"><div class="container-fluid"><div class="d-flex align-items-center justify-content-between">
<div class="logo"><i class="fas fa-brain"></i><div>IA CRYPTO INVEST<small style="display:block;font-size:.65rem;color:#6c757d;font-weight:400">Trading Autonome IA</small></div></div>
<div class="d-flex align-items-center gap-3"><div class="live"><span class="dot"></span>EN DIRECT</div>
<div class="capital"><i class="fas fa-coins me-1"></i><span id="totalCapital"><?=number_format($stats['total_capital'],0,',',' ')?></span> BRICS</div>
<button class="btn btn-sm btn-outline-primary" onclick="forceBrain()"><i class="fas fa-bolt"></i> Cycle IA</button></div>
</div></div></header>
<div class="main">
<div class="panel"><div class="panel-h"><div class="panel-t"><i class="fas fa-terminal"></i>Console IA</div><span class="badge bg-primary" id="timer">8s</span></div>
<div class="console" id="consoleLogs"><div class="log"><span class="log-t"><?=date('H:i:s')?></span><span class="log-type brain_start">INIT</span>Système démarré...</div></div></div>
<div class="panel" style="background:var(--bg)"><div class="panel-h"><div class="panel-t"><i class="fas fa-chart-line"></i>Marché & Performance</div><span class="badge bg-success">● À jour</span></div>
<div class="stats-grid">
<div class="stat-card"><div class="stat-val" id="poolCapital"><?=number_format($stats['pool_capital'],0,',',' ')?></div><div class="stat-lbl">Capital Disponible</div></div>
<div class="stat-card"><div class="stat-val" id="agentsCapital"><?=number_format($stats['agents_capital'],0,',',' ')?></div><div class="stat-lbl">Chez les Agents</div></div>
<div class="stat-card"><div class="stat-val" style="color:var(--green)" id="totalPnl">+<?=number_format($stats['total_pnl'],2,',',' ')?></div><div class="stat-lbl">Profit Total</div></div>
<div class="stat-card"><div class="stat-val" style="color:var(--purple)" id="agentsCount"><?=$stats['agents_count']?></div><div class="stat-lbl">Agents Actifs</div></div>
</div>
<div class="card shadow-sm"><div class="card-header bg-white py-2"><h6 class="mb-0"><i class="fab fa-bitcoin text-warning me-2"></i>Top Cryptos</h6><input type="text" class="form-control form-control-sm" placeholder="Rechercher..." style="width:150px" id="coinSearch" onkeyup="filterCoins()"></div>
<div style="max-height:400px;overflow-y:auto"><table class="table table-hover mb-0 tbl"><thead><tr><th>#</th><th>Crypto</th><th>Prix</th><th>24h</th><th>7j</th><th>Cap.</th></tr></thead><tbody id="coinsBody"><tr><td colspan="6" class="text-center py-4">Chargement...</td></tr></tbody></table></div></div></div>
<div class="panel"><div class="panel-h"><div class="panel-t"><i class="fas fa-robot"></i>Agents IA</div><span class="badge bg-info" id="agentBadge"><?=$stats['agents_count']?> actifs</span></div>
<div class="analysis-card mb-3"><div class="d-flex align-items-center justify-content-between mb-2"><h6 class="mb-0"><i class="fas fa-trophy text-warning me-2"></i>Meilleur Agent</h6><span class="badge bg-success">Top</span></div><div id="topPerformer"><p class="text-muted small mb-0">En attente...</p></div></div>
<div id="agentsList"><div class="text-center py-4 text-muted"><i class="fas fa-spinner fa-spin fa-2x mb-2"></i><p class="small">Chargement...</p></div></div></div>
</div>
<script>
const INTERVAL=<?=TRADE_INTERVAL_SECONDS?>;
async function fetchAll(){try{const[s,c,a,l]=await Promise.all([fetch('api.php?action=stats'),fetch('api.php?action=coins'),fetch('api.php?action=agents'),fetch('api.php?action=logs&limit=50')]);updateStats(await s.json());updateMarket(await c.json());updateAgents(await a.json());updateConsole(await l.json());}catch(e){console.error('Erreur fetchAll:',e)}}
function updateStats(s){document.getElementById('poolCapital').textContent=Math.floor(s.pool_capital).toLocaleString('fr-FR');document.getElementById('agentsCapital').textContent=Math.floor(s.agents_capital).toLocaleString('fr-FR');const p=document.getElementById('totalPnl');p.textContent=(s.total_pnl>=0?'+':'')+s.total_pnl.toFixed(2).replace('.',',');p.style.color=s.total_pnl>=0?'var(--green)':'#dc3545';document.getElementById('agentsCount').textContent=s.agents_count;}
function updateMarket(c){const t=document.getElementById('coinsBody');if(!c||!c.length){t.innerHTML='<tr><td colspan="6" class="text-center py-4 text-muted"><i class="fas fa-exclamation-triangle me-2"></i>Aucune crypto disponible.</td></tr>';return;}let h='';c.slice(0,20).forEach(x=>{const c24=x.price_change_pct_24h||0,c7d=x.price_change_7d||0,cl=c24>=0?'up':'down';h+=`<tr><td>${x.market_cap_rank}</td><td><div class="coin-info"><img src="${x.image_url}" class="coin-img"><div><div style="font-weight:600">${x.name}</div><small style="color:#6c757d">${x.symbol.toUpperCase()}</small></div></div></td><td style="font-family:'Courier New';font-weight:600">${x.current_price<1?x.current_price.toFixed(6):x.current_price.toFixed(2)}€</td><td class="${cl}" style="font-weight:600">${c24>=0?'+':''}${c24.toFixed(2)}%</td><td class="${c7d>=0?'up':'down'}" style="font-weight:600">${c7d>=0?'+':''}${c7d.toFixed(2)}%</td><td style="color:#6c757d">${(x.market_cap/1e9).toFixed(2)}B</td></tr>`});t.innerHTML=h;}
function updateAgents(a){const c=document.getElementById('agentsList');if(!a||!a.length){c.innerHTML='<div class="text-center text-muted py-3">Aucun agent</div>';return;}if(a[0]){const t=a[0],pc=t.total_pnl_percent>=0?'pos':'neg';document.getElementById('topPerformer').innerHTML=`<div class="d-flex align-items-center justify-content-between"><div><div style="font-weight:700">${t.name}</div><small style="color:#6c757d">${t.timeframe}•${t.total_trades} trades</small></div><div class="badge-stat badge-pnl ${pc}">${(t.total_pnl_percent>=0?'+':'')+t.total_pnl_percent.toFixed(2)}%</div></div>`;}let h='';a.slice(0,15).forEach((x,i)=>{const pc=x.total_pnl_percent>=0?'pos':'neg',tc=i===0?'top':'';h+=`<div class="agent-card ${tc}"><div class="agent-name"><span>${i+1}. ${x.name}</span><span class="badge bg-secondary" style="font-size:.6rem">${x.timeframe}</span></div><div class="agent-stats"><div class="badge-stat badge-pnl ${pc}">${(x.total_pnl_percent>=0?'+':'')+x.total_pnl_percent.toFixed(2)}%</div><div class="badge-stat" style="background:rgba(13,202,240,.15);color:var(--cyan)">${x.total_trades} trades</div><div class="badge-stat" style="background:rgba(111,66,193,.15);color:var(--purple)">Win:${x.win_rate.toFixed(1)}%</div><div class="badge-stat" style="background:rgba(255,193,7,.15);color:var(--gold)">${(x.capital_brics||0).toFixed(0)} BRICS</div></div></div>`});c.innerHTML=h;document.getElementById('agentBadge').textContent=a.length+' actifs';}
function updateConsole(l){const c=document.getElementById('consoleLogs');if(!l||!l.length)return;let h='';l.forEach(x=>{const t=new Date(x.created_at*1000).toLocaleTimeString('fr-FR');h+=`<div class="log"><span class="log-t">${t}</span><span class="log-type ${x.log_type}">${x.log_type.toUpperCase()}</span>${escapeHtml(x.message)}</div>`});c.innerHTML=h;}
function escapeHtml(t){const d=document.createElement('div');d.textContent=t;return d.innerHTML;}
function forceBrain(){fetch('api.php?action=brain_cycle',{method:'POST'}).then(()=>fetchAll());}
function filterCoins(){const q=document.getElementById('coinSearch').value.toLowerCase();document.querySelectorAll('#coinsBody tr').forEach(r=>{r.style.display=r.textContent.toLowerCase().includes(q)?'':'none';});}
let cd=INTERVAL;setInterval(()=>{cd--;if(cd<=0)cd=INTERVAL;document.getElementById('timer').textContent=cd+'s';},1000);
fetchAll();setInterval(fetchAll,3000);
</script>
</body>
</html>
