<?php
/**
 * IA CRYPTO INVEST - API Endpoints
 */
header('Content-Type: application/json');
require_once __DIR__ . '/functions.php';

try {
    initDatabases();
} catch (Throwable $e) {
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    case 'stats':
        echo json_encode(getSystemStats());
        break;
    
    case 'coins':
        echo json_encode(getCoins());
        break;
    
    case 'agents':
        echo json_encode(getActiveAgents());
        break;
    
    case 'logs':
        $limit = (int)($_GET['limit'] ?? 50);
        echo json_encode(getConsoleLogs($limit));
        break;
    
    case 'brain_cycle':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            runBrainCycle();
            echo json_encode(['success' => true, 'message' => 'Cycle cerveau exécuté']);
        } else {
            http_response_code(405);
            echo json_encode(['error' => 'Méthode non autorisée']);
        }
        break;
    
    case 'market_update':
        $coins = updateMarketData();
        echo json_encode(['success' => true, 'coins_count' => count($coins)]);
        break;
    
    case 'price_history':
        $coinId = $_GET['coin_id'] ?? 'bitcoin';
        $days = (int)($_GET['days'] ?? 30);
        echo json_encode(getPriceHistory($coinId, $days));
        break;
    
    default:
        http_response_code(400);
        echo json_encode(['error' => 'Action inconnue']);
}
