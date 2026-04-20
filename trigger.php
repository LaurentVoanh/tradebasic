<?php
/**
 * IA CRYPTO INVEST - Trigger AJAX pour cycle cerveau
 * À appeler via setInterval() depuis le frontend
 */

require_once __DIR__ . '/src/Core.php';

use IACrypto\Core\Engine;

header('Content-Type: application/json');

// Empêcher exécution simultanée avec lock file
$lockFile = __DIR__ . '/cache/brain.lock';
if (file_exists($lockFile) && (time() - filemtime($lockFile)) < 8) {
    echo json_encode(['success' => false, 'message' => 'Déjà en cours']);
    exit;
}

file_put_contents($lockFile, time());

try {
    $engine = Engine::getInstance();
    $result = $engine->runBrainCycle();
    
    // Mettre à jour les positions avec prix actuels
    $db = $engine->getDatabase()->getConnection('main');
    $coins = $engine->getMarket()->getCoins(200);
    $coinPrices = [];
    foreach ($coins as $c) {
        $coinPrices[$c['symbol']] = $c['current_price'];
    }
    
    // Update open positions values
    $positions = $db->query("SELECT * FROM open_positions")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($positions as $pos) {
        if (isset($coinPrices[$pos['coin_symbol']])) {
            $currentValue = $pos['quantity'] * $coinPrices[$pos['coin_symbol']];
            $unrealizedPnl = $currentValue - $pos['total_invested'];
            $upd = $db->prepare("UPDATE open_positions SET current_value = ?, unrealized_pnl = ? WHERE id = ?");
            $upd->execute([$currentValue, $unrealizedPnl, $pos['id']]);
        }
    }
    
    echo json_encode([
        'success' => true,
        'data' => $result,
        'timestamp' => time()
    ]);
    
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} finally {
    if (file_exists($lockFile)) unlink($lockFile);
}
