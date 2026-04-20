<?php
/**
 * NEXUS TRADER - Trigger AJAX pour cycle cerveau
 * À appeler via setInterval() depuis le frontend
 */

require_once __DIR__ . '/src/Core.php';

use Nexus\Core\Engine;
use Nexus\Core\NEXUS_LOCK_FILE;

header('Content-Type: application/json');

// Lock file pour éviter exécution simultanée
if (file_exists(NEXUS_LOCK_FILE) && (time() - filemtime(NEXUS_LOCK_FILE)) < 8) {
    echo json_encode(['success' => false, 'message' => 'Déjà en cours']);
    exit;
}

file_put_contents(NEXUS_LOCK_FILE, time());

try {
    $engine = Engine::getInstance();
    $result = $engine->runBrainCycle();
    
    // Mettre à jour les positions avec prix actuels
    $db = $engine->getDatabase()->getPDO();
    $marketData = $engine->getMarketData();
    
    $positions = $db->query("SELECT * FROM positions WHERE status = 'open'")->fetchAll();
    foreach ($positions as $pos) {
        $currentPrice = $marketData->getCoinPrice($pos['coin_symbol']);
        if ($currentPrice > 0) {
            $currentValue = $pos['amount'] * $currentPrice;
            $pnl = $currentValue - ($pos['amount'] * $pos['avg_price']);
            $pnlPercent = ($pnl / ($pos['amount'] * $pos['avg_price'])) * 100;
            
            $upd = $db->prepare("UPDATE positions SET current_value = ?, pnl = ?, pnl_percent = ? WHERE id = ?");
            $upd->execute([$currentValue, $pnl, $pnlPercent, $pos['id']]);
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
    if (file_exists(NEXUS_LOCK_FILE)) {
        unlink(NEXUS_LOCK_FILE);
    }
}
