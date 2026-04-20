<?php
/**
 * IA CRYPTO INVEST - Cron Runner v2.0
 * Exécute le Brain Cycle périodiquement
 */

require_once __DIR__ . '/src/Core.php';

use IACrypto\Core\Engine;

// Empêcher exécution simultanée
$lockFile = __DIR__ . '/cache/cron.lock';
if (file_exists($lockFile) && (time() - filemtime($lockFile)) < 30) {
    die("Déjà en cours d'exécution\n");
}
file_put_contents($lockFile, time());

try {
    $engine = Engine::getInstance();
    
    // Mettre à jour les données marché
    echo "Mise à jour des données marché...\n";
    $coinCount = $engine->getMarket()->update();
    echo "$coinCount cryptos mises à jour\n";
    
    // Exécuter cycle cerveau
    echo "Exécution du cycle cerveau...\n";
    $result = $engine->runBrainCycle();
    
    echo "Cycle terminé:\n";
    echo "  - Agents créés: {$result['created']}\n";
    echo "  - Agents archivés: {$result['archived']}\n";
    echo "  - Actions: " . implode(", ", $result['actions']) . "\n";
    
} catch (Throwable $e) {
    echo "ERREUR: " . $e->getMessage() . "\n";
    error_log("Cron error: " . $e->getMessage());
} finally {
    unlink($lockFile);
}

echo "Terminé à " . date('Y-m-d H:i:s') . "\n";
