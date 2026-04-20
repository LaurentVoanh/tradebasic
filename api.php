<?php
/**
 * IA CRYPTO INVEST - API Endpoint
 * Fournit les données en temps réel via AJAX polling
 */

require_once __DIR__ . '/src/Core.php';

use IACrypto\Core\Engine;

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type');

$action = isset($_GET['action']) ? $_GET['action'] : (isset($_POST['action']) ? $_POST['action'] : '');

try {
    $engine = Engine::getInstance();
    
    switch ($action) {
        case 'stats':
            echo json_encode(['success' => true, 'data' => $engine->getSystemStats()]);
            break;
            
        case 'coins':
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
            $coins = $engine->getMarket()->getCoins($limit);
            echo json_encode(['success' => true, 'data' => $coins]);
            break;
            
        case 'agents':
            $agents = $engine->getAgentManager()->getActiveAgents();
            echo json_encode(['success' => true, 'data' => $agents]);
            break;
            
        case 'trades':
            $db = $engine->getDatabase()->getConnection('main');
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
            $stmt = $db->prepare("SELECT * FROM agent_trades ORDER BY executed_at DESC LIMIT ?");
            $stmt->execute([$limit]);
            $trades = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'data' => $trades]);
            break;
            
        case 'logs':
            $db = $engine->getDatabase()->getConnection('main');
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
            $stmt = $db->prepare("SELECT * FROM console_logs ORDER BY created_at DESC LIMIT ?");
            $stmt->execute([$limit]);
            $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'data' => $logs]);
            break;
            
        case 'positions':
            $db = $engine->getDatabase()->getConnection('main');
            $stmt = $db->query("SELECT op.*, a.name as agent_name FROM open_positions op 
                JOIN agents a ON op.agent_id = a.id ORDER BY unrealized_pnl DESC");
            $positions = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'data' => $positions]);
            break;
            
        case 'run_cycle':
            $result = $engine->runBrainCycle();
            echo json_encode(['success' => true, 'data' => $result]);
            break;
            
        case 'update_market':
            $count = $engine->getMarket()->update();
            echo json_encode(['success' => true, 'data' => ['coins_updated' => $count]]);
            break;
            
        case 'api_stats':
            $stats = $engine->getApiRotation()->getKeyStats();
            echo json_encode(['success' => true, 'data' => $stats]);
            break;
            
        default:
            echo json_encode(['success' => false, 'error' => 'Action inconnue']);
    }
    
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
