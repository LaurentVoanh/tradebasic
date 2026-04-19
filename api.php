<?php
header('Content-Type: application/json');
set_time_limit(300);
error_reporting(0);
session_start();

require_once __DIR__ . '/functions.php';

// Initialize DBs on first run
try {
    initDatabases();
    initDefaultAgents();
} catch (\Throwable $e) { /* silent */ }

$input  = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $input['action'] ?? ($_GET['action'] ?? '');

try {
    switch ($action) {
        // ---- MARKET ----
        case 'get_coins':
            $limit = (int)($input['limit'] ?? 100);
            $coins = getCoins($limit);
            echo json_encode(['success' => true, 'coins' => $coins, 'count' => count($coins), 'ts' => time()]);
            break;

        case 'get_coin':
            $id = $input['id'] ?? '';
            $coin = getCoin($id);
            echo json_encode(['success' => (bool)$coin, 'coin' => $coin]);
            break;

        case 'update_market':
            $result = updateMarketData();
            echo json_encode($result);
            break;

        case 'system_status':
            echo json_encode(['success' => true, 'status' => getSystemStatus()]);
            break;

        // ---- NEWS & ANALYSIS ----
        case 'fetch_news':
            $coinId   = $input['coin_id'] ?? '';
            $coinName = $input['coin_name'] ?? $coinId;
            $symbol   = $input['symbol'] ?? $coinId;
            $articles = fetchNewsForCoin($coinId, $coinName, $symbol);
            $analysis = analyzeCoinNews($coinId, $coinName);
            echo json_encode(['success' => true, 'articles' => $articles, 'analysis' => $analysis]);
            break;

        case 'get_analysis':
            $coinId   = $input['coin_id'] ?? '';
            $analysis = getLatestAnalysis($coinId);
            $news = getDB('main')->prepare("SELECT * FROM news WHERE coin_id=? ORDER BY published_at DESC LIMIT 15");
            $news->execute([$coinId]);
            $articles = $news->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'analysis' => $analysis, 'articles' => $articles]);
            break;

        // ---- AGENTS ----
        case 'get_agents':
            $agents = getActiveAgents(100);
            echo json_encode(['success' => true, 'agents' => $agents]);
            break;

        case 'get_top_agents':
            $agents = getTopAgents(10);
            echo json_encode(['success' => true, 'agents' => $agents]);
            break;

        case 'create_agent':
            if (empty($input['name']) || empty($input['strategy_prompt'])) {
                echo json_encode(['success' => false, 'error' => 'Nom et stratégie requis']);
                break;
            }
            $userId = $_SESSION['user']['id'] ?? null;
            $id = createAgent([
                'user_id'         => $userId,
                'name'            => htmlspecialchars($input['name']),
                'strategy_prompt' => $input['strategy_prompt'],
                'strategy_type'   => $input['strategy_type'] ?? 'custom',
            ]);
            echo json_encode(['success' => true, 'agent_id' => $id]);
            break;

        case 'run_agent':
            $agentId  = (int)($input['agent_id'] ?? 0);
            $decision = runAgentDecision($agentId);
            echo json_encode(['success' => true, 'decision' => $decision]);
            break;

        case 'get_agent_trades':
            $agentId = (int)($input['agent_id'] ?? 0);
            $trades  = getAgentTrades($agentId);
            echo json_encode(['success' => true, 'trades' => $trades]);
            break;

        case 'run_brain':
            $log = runBrainCycle();
            echo json_encode(['success' => true, 'log' => $log]);
            break;

        // ---- AUTH ----
        case 'register':
            $result = registerUser($input['email'] ?? '', $input['password'] ?? '', $input['username'] ?? '');
            if ($result['success']) {
                $_SESSION['user'] = ['id' => $result['user_id'], 'email' => $input['email']];
            }
            echo json_encode($result);
            break;

        case 'login':
            $result = loginUser($input['email'] ?? '', $input['password'] ?? '');
            if ($result['success']) {
                $_SESSION['user'] = $result['user'];
            }
            echo json_encode($result);
            break;

        case 'logout':
            session_destroy();
            echo json_encode(['success' => true]);
            break;

        case 'me':
            $user = getCurrentUser();
            echo json_encode(['success' => (bool)$user, 'user' => $user]);
            break;

        default:
            echo json_encode(['success' => false, 'error' => 'Action inconnue: ' . $action]);
    }
} catch (\Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage(), 'file' => basename($e->getFile()), 'line' => $e->getLine()]);
}
