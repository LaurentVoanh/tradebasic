<?php
/**
 * IA CRYPTO INVEST - cron_update.php
 * À appeler via crontab : * * * * * php /path/to/cron_update.php
 * OU depuis le browser toutes les 60s via AJAX
 */
set_time_limit(600);
error_reporting(0);

// Prevent concurrent runs
$lockFile = __DIR__ . '/db/cron.lock';
if (file_exists($lockFile) && (time() - filemtime($lockFile)) < 55) {
    exit("Already running\n");
}
file_put_contents($lockFile, time());

require_once __DIR__ . '/functions.php';

try {
    initDatabases();

    // 1. Update market data (every run = every 60s)
    $marketResult = updateMarketData();
    echo "Market: " . ($marketResult['success'] ? "OK ({$marketResult['updated']} coins)" : "FAIL") . "\n";

    // 2. Run brain cycle every 10 minutes
    $db = getDB('main');
    $lastBrain = (int)$db->query("SELECT value FROM system_config WHERE key='last_brain_run'")->fetchColumn();
    if ((time() - $lastBrain) > 600) {
        echo "Running brain cycle...\n";
        $brainResult = runBrainCycle();
        echo "Brain: {$brainResult['created']} créés, {$brainResult['archived']} archivés\n";
    }

    // 3. Fetch news for top 5 coins every 15 minutes
    $lastNewsKey = 'last_news_fetch';
    $lastNews = (int)($db->query("SELECT value FROM system_config WHERE key='$lastNewsKey'")->fetchColumn() ?: 0);
    if ((time() - $lastNews) > 900) {
        $topCoins = getDB('main')->query("SELECT id, name, symbol FROM coins ORDER BY market_cap_rank LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($topCoins as $coin) {
            fetchNewsForCoin($coin['id'], $coin['name'], $coin['symbol']);
            sleep(2);
        }
        $db->prepare("INSERT OR REPLACE INTO system_config (key, value) VALUES (?,strftime('%s','now'))")->execute([$lastNewsKey]);
        echo "News fetched for top 5 coins\n";
    }

} catch (\Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

@unlink($lockFile);
echo "Done at " . date('Y-m-d H:i:s') . "\n";
