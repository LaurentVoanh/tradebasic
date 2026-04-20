<?php
/**
 * IA CRYPTO INVEST - Cron Job
 * Exécuté toutes les 8 secondes pour le trading automatique
 * Et toutes les 30 secondes pour le cycle cerveau complet
 */

require_once __DIR__ . '/functions.php';

try {
    initDatabases();
    initDefaultAgents();
    
    $db = getDB();
    $now = time();
    
    // Récupérer les derniers timestamps
    $lastTrade = (int)$db->query("SELECT value FROM system_config WHERE key='last_trade_cycle'")->fetchColumn() ?: 0;
    $lastBrain = (int)$db->query("SELECT value FROM system_config WHERE key='last_brain_cycle'")->fetchColumn() ?: 0;
    
    $result = ['timestamp' => $now, 'actions' => []];
    
    // Mise à jour marché si nécessaire (>60s)
    $lastMarket = (int)$db->query("SELECT value FROM system_config WHERE key='last_market_update'")->fetchColumn() ?: 0;
    if (($now - $lastMarket) > 60) {
        updateMarketData();
        $result['actions'][] = 'market_updated';
    }
    
    // Cycle de trade toutes les 8 secondes
    if (($now - $lastTrade) >= TRADE_INTERVAL_SECONDS) {
        $agents = getActiveAgents();
        $coins = getCoins();
        
        if (!empty($agents) && !empty($coins)) {
            // Sélectionner top 15 agents pour trader
            $traders = array_slice($agents, 0, 15);
            $tradesExecuted = 0;
            
            foreach ($traders as $agent) {
                $decision = runAgentDecision($agent['id']);
                if ($decision && $decision['action'] !== 'hold') {
                    $tradesExecuted++;
                }
                usleep(100000); // 100ms entre chaque
            }
            
            $db->prepare("UPDATE system_config SET value=? WHERE key='last_trade_cycle'")
               ->execute([$now]);
            
            $result['actions'][] = 'trade_cycle';
            $result['trades_executed'] = $tradesExecuted;
        }
    }
    
    // Cycle cerveau complet toutes les 30 secondes
    if (($now - $lastBrain) >= BRAIN_CYCLE_SECONDS) {
        runBrainCycle();
        $result['actions'][] = 'brain_cycle';
    }
    
    echo json_encode($result);
    
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
}
