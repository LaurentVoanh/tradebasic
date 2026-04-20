<?php
/**
 * IA CRYPTO INVEST - API Endpoint v2.0
 * Compatible avec le nouveau Core Engine
 */

require_once __DIR__ . '/src/Core.php';

use IACrypto\Core\Engine;

header('Content-Type: application/json');

try {
    $engine = Engine::getInstance();
    $action = $_GET['action'] ?? 'stats';
    
    switch ($action) {
        case 'stats':
            echo json_encode($engine->getSystemStats());
            break;
            
        case 'coins':
            echo json_encode($engine->getMarket()->getCoins(50));
            break;
            
        case 'agents':
            echo json_encode($engine->getAgentManager()->getActiveAgents());
            break;
            
        case 'logs':
            $limit = (int)($_GET['limit'] ?? 50);
            $db = $engine->getDatabase()->getConnection('main');
            $stmt = $db->prepare("SELECT * FROM console_logs ORDER BY created_at DESC LIMIT ?");
            $stmt->execute([$limit]);
            echo json_encode(array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC)));
            break;
            
        case 'brain_cycle':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                echo json_encode($engine->runBrainCycle());
            } else {
                http_response_code(405);
                echo json_encode(['error' => 'Method not allowed']);
            }
            break;
            
        case 'api_stats':
            echo json_encode($engine->getApiRotation()->getKeyStats());
            break;
            
        case 'market_update':
            $count = $engine->getMarket()->update();
            echo json_encode(['updated' => $count, 'success' => $count > 0]);
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Unknown action']);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Internal server error',
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
}
