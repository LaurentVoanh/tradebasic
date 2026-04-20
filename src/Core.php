<?php
/**
 * NEXUS TRADER - Core Engine v4.0
 * Architecture monobloc optimisée pour Hostinger (Mutualisé)
 * Namespace: Nexus\Core
 */

namespace Nexus\Core;

use PDO;
use PDOException;
use Exception;

// Configuration
define('NEXUS_DB_DIR', __DIR__ . '/../db/');
define('NEXUS_CACHE_DIR', __DIR__ . '/../cache/');
define('NEXUS_LOGS_DIR', __DIR__ . '/../logs/');
define('NEXUS_LOCK_FILE', NEXUS_CACHE_DIR . 'brain.lock');

define('NEXUS_MISTRAL_KEYS', [
    getenv('MISTRAL_API_KEY_1') ?: getenv('MISTRAL_API_KEY') ?: '',
    getenv('MISTRAL_API_KEY_2') ?: '',
    getenv('MISTRAL_API_KEY_3') ?: ''
]);

define('NEXUS_MISTRAL_ENDPOINT', 'https://api.mistral.ai/v1/chat/completions');
define('NEXUS_COINGECKO_ENDPOINT', 'https://api.coingecko.com/api/v3/coins/markets');

define('NEXUS_AGENT_INITIAL_CAPITAL', 1000.00);
define('NEXUS_LIQUIDATION_THRESHOLD', -5.0);

define('NEXUS_DNA_STRATEGIES', [
    'scalping' => 'Trading rapide sur petites fluctuations 1-3%',
    'trend_following' => 'Suit la tendance majeure sur 7-14 jours',
    'mean_reversion' => 'Achète les dips, vend les pumps extrêmes',
    'breakout' => 'Détecte et trade les cassures de résistance/support',
    'momentum' => 'Trade les cryptos avec forte momentum 24h'
]);

class Database {
    private $pdo;
    private $dbPath;
    
    public function __construct($dbName = 'nexus_trader.db') {
        $this->ensureDirectories();
        $this->dbPath = NEXUS_DB_DIR . $dbName;
        $this->connect();
        $this->initTables();
    }
    
    private function ensureDirectories() {
        foreach ([NEXUS_DB_DIR, NEXUS_CACHE_DIR, NEXUS_LOGS_DIR] as $dir) {
            if (!is_dir($dir)) mkdir($dir, 0777, true);
        }
    }
    
    private function connect() {
        try {
            $this->pdo = new PDO('sqlite:' . $this->dbPath);
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $this->pdo->exec('PRAGMA journal_mode=WAL');
            $this->pdo->exec('PRAGMA synchronous=NORMAL');
            $this->pdo->exec('PRAGMA cache_size=10000');
        } catch (PDOException $e) {
            throw new Exception('Database connection failed: ' . $e->getMessage());
        }
    }
    
    private function initTables() {
        $tables = [
            'coins' => "CREATE TABLE IF NOT EXISTS coins (id INTEGER PRIMARY KEY AUTOINCREMENT, symbol TEXT UNIQUE NOT NULL, name TEXT NOT NULL, price REAL NOT NULL, change_24h REAL, change_7d REAL, market_cap REAL, volume_24h REAL, image_url TEXT, last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP)",
            'agents' => "CREATE TABLE IF NOT EXISTS agents (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT UNIQUE NOT NULL, dna TEXT NOT NULL, status TEXT DEFAULT 'active', capital REAL DEFAULT " . NEXUS_AGENT_INITIAL_CAPITAL . ", balance REAL DEFAULT " . NEXUS_AGENT_INITIAL_CAPITAL . ", total_trades INTEGER DEFAULT 0, wins INTEGER DEFAULT 0, losses INTEGER DEFAULT 0, total_pnl REAL DEFAULT 0, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)",
            'trades' => "CREATE TABLE IF NOT EXISTS trades (id INTEGER PRIMARY KEY AUTOINCREMENT, agent_id INTEGER, coin_symbol TEXT NOT NULL, type TEXT NOT NULL, amount REAL NOT NULL, price REAL NOT NULL, profit_loss REAL DEFAULT 0, status TEXT DEFAULT 'open', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, closed_at TIMESTAMP, FOREIGN KEY (agent_id) REFERENCES agents(id))",
            'positions' => "CREATE TABLE IF NOT EXISTS positions (id INTEGER PRIMARY KEY AUTOINCREMENT, agent_id INTEGER, coin_symbol TEXT NOT NULL, amount REAL NOT NULL, avg_price REAL NOT NULL, current_value REAL, pnl REAL DEFAULT 0, pnl_percent REAL DEFAULT 0, status TEXT DEFAULT 'open', opened_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, closed_at TIMESTAMP, FOREIGN KEY (agent_id) REFERENCES agents(id))",
            'rl_memory' => "CREATE TABLE IF NOT EXISTS rl_memory (id INTEGER PRIMARY KEY AUTOINCREMENT, strategy_name TEXT UNIQUE NOT NULL, success_count INTEGER DEFAULT 0, failure_count INTEGER DEFAULT 0, avg_profit REAL DEFAULT 0, total_profit REAL DEFAULT 0, last_used TIMESTAMP DEFAULT CURRENT_TIMESTAMP)",
            'console_logs' => "CREATE TABLE IF NOT EXISTS console_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, message TEXT NOT NULL, level TEXT DEFAULT 'info', context TEXT, timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP)"
        ];
        
        foreach ($tables as $sql) {
            $this->pdo->exec($sql);
        }
        $this->initializeRLMemory();
    }
    
    private function initializeRLMemory() {
        $stmt = $this->pdo->prepare("INSERT OR IGNORE INTO rl_memory (strategy_name, success_count, failure_count) VALUES (?, 0, 0)");
        foreach (array_keys(NEXUS_DNA_STRATEGIES) as $strategy) {
            $stmt->execute([$strategy]);
        }
    }
    
    public function getPDO() { return $this->pdo; }
    
    public function log($message, $level = 'info', $context = null) {
        $stmt = $this->pdo->prepare("INSERT INTO console_logs (message, level, context) VALUES (?, ?, ?)");
        $stmt->execute([$message, $level, $context ? json_encode($context) : null]);
    }
    
    public function getStats() {
        $stats = [];
        $stmt = $this->pdo->query("SELECT COUNT(*) as count, SUM(capital) as total_capital, SUM(total_pnl) as total_pnl FROM agents WHERE status = 'active'");
        $agentStats = $stmt->fetch();
        $stats['agents_count'] = (int)($agentStats['count'] ?? 0);
        $stats['total_capital'] = (float)($agentStats['total_capital'] ?? 0);
        $stats['total_pnl'] = (float)($agentStats['total_pnl'] ?? 0);
        
        $stmt = $this->pdo->query("SELECT COUNT(*) as total, SUM(CASE WHEN profit_loss > 0 THEN 1 ELSE 0 END) as wins FROM trades WHERE status = 'closed'");
        $tradeStats = $stmt->fetch();
        $stats['total_trades'] = (int)($tradeStats['total'] ?? 0);
        $wins = (int)($tradeStats['wins'] ?? 0);
        $stats['win_rate'] = $stats['total_trades'] > 0 ? round(($wins / $stats['total_trades']) * 100, 2) : 0;
        
        $stmt = $this->pdo->query("SELECT COUNT(*) as count FROM positions WHERE status = 'open'");
        $stats['open_positions'] = (int)$stmt->fetchColumn();
        
        return $stats;
    }
}

class ApiManager {
    private $mistralKeys = [];
    private $currentKeyIndex = 0;
    private $hasValidKeys = false;
    
    public function __construct() {
        $keys = NEXUS_MISTRAL_KEYS;
        foreach ($keys as $key) {
            if (!empty(trim($key))) {
                $this->mistralKeys[] = trim($key);
            }
        }
        $this->hasValidKeys = !empty($this->mistralKeys);
        if (!$this->hasValidKeys) {
            error_log("NEXUS: No valid Mistral API keys - Simulation mode enabled");
        }
    }
    
    public function callMistral($prompt, $temperature = 0.7) {
        if (!$this->hasValidKeys) {
            return $this->simulateResponse($prompt);
        }
        
        $apiKey = $this->getCurrentKey();
        $systemPrompt = $this->getSystemPrompt();
        
        $data = [
            'model' => 'mistral-small-latest',
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $prompt]
            ],
            'temperature' => $temperature,
            'max_tokens' => 1000
        ];
        
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $apiKey,
                    'Accept: application/json'
                ],
                'content' => json_encode($data),
                'timeout' => 30,
                'ignore_errors' => true
            ]
        ]);
        
        $response = @file_get_contents(NEXUS_MISTRAL_ENDPOINT, false, $context);
        
        if ($response === false) {
            $this->rotateKey();
            return $this->simulateResponse($prompt);
        }
        
        $decoded = json_decode($response, true);
        
        if (!$decoded || isset($decoded['error'])) {
            $this->rotateKey();
            return $this->simulateResponse($prompt);
        }
        
        return $decoded['choices'][0]['message']['content'] ?? $this->simulateResponse($prompt);
    }
    
    private function getCurrentKey() {
        return $this->mistralKeys[$this->currentKeyIndex % count($this->mistralKeys)];
    }
    
    private function rotateKey() {
        $this->currentKeyIndex++;
    }
    
    private function getSystemPrompt() {
        return "Tu es l'unite centrale NEXUS-1. Ton role est de gerer une flotte d'agents de trading crypto.\n\nAnalyse les prix actuels, les variations 24h et le sentiment du marche.\n\nSi un agent est en perte de plus de 5%, ordonne sa liquidation.\n\nSi des opportunites existent, cree un nouvel agent avec une strategie specifique (DNA) et un nom unique.\n\nApprends des trades passes : si la strategie 'Breakout' a echoue 3 fois, essaie une approche 'Mean Reversion'.\nTu dois repondre exclusivement en JSON avec ce format : { \"action\": \"create_agent\"|\"liquidate\"|\"wait\", \"reasoning\": \"...\", \"agent_config\": {...} }.";
    }
    
    public function fetchMarketData() {
        $url = NEXUS_COINGECKO_ENDPOINT . '?vs_currency=usd&order=market_cap_desc&per_page=50&page=1&sparkline=false';
        
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => [
                    'User-Agent: Mozilla/5.0 (compatible; NexusTrader/1.0)',
                    'Accept: application/json'
                ],
                'timeout' => 15
            ]
        ]);
        
        $response = @file_get_contents($url, false, $context);
        
        if ($response === false) {
            throw new Exception("Failed to fetch market data from CoinGecko");
        }
        
        $data = json_decode($response, true);
        if (!$data) {
            throw new Exception("Invalid response from CoinGecko API");
        }
        
        return $data;
    }
    
    private function simulateResponse($prompt) {
        $actions = ['wait', 'wait', 'wait', 'create_agent'];
        $action = $actions[array_rand($actions)];
        
        if ($action === 'create_agent') {
            $strategies = array_keys(NEXUS_DNA_STRATEGIES);
            $dna = $strategies[array_rand($strategies)];
            $coins = ['BTC', 'ETH', 'SOL', 'BNB', 'XRP', 'ADA', 'DOGE', 'AVAX'];
            $coin = $coins[array_rand($coins)];
            
            return json_encode([
                'action' => 'create_agent',
                'reasoning' => "Opportunite detectee sur $coin avec strategie $dna",
                'agent_config' => [
                    'name' => 'NEXUS-' . strtoupper(substr(md5(time() . rand()), 0, 4)),
                    'dna' => $dna,
                    'capital' => NEXUS_AGENT_INITIAL_CAPITAL
                ]
            ]);
        }
        
        return json_encode([
            'action' => 'wait',
            'reasoning' => 'Marche en consolidation, attente de signaux clairs'
        ]);
    }
}

class MarketData {
    private $db;
    private $api;
    
    public function __construct(Database $db, ApiManager $api) {
        $this->db = $db;
        $this->api = $api;
    }
    
    public function updateMarketData() {
        try {
            $rawData = $this->api->fetchMarketData();
            
            $stmt = $this->db->getPDO()->prepare(
                "INSERT OR REPLACE INTO coins (symbol, name, price, change_24h, change_7d, market_cap, volume_24h, image_url, last_updated) VALUES (?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)"
            );
            
            foreach ($rawData as $coin) {
                $stmt->execute([
                    strtoupper($coin['symbol']),
                    $coin['name'],
                    $coin['current_price'],
                    $coin['price_change_percentage_24h'] ?? null,
                    $coin['price_change_percentage_7d_in_currency'] ?? $coin['price_change_percentage_7d'] ?? null,
                    $coin['market_cap'],
                    $coin['total_volume'],
                    $coin['image'] ?? null
                ]);
            }
            
            return count($rawData);
        } catch (Exception $e) {
            $this->db->log("MarketData update error: " . $e->getMessage(), 'error');
            return 0;
        }
    }
    
    public function getTopCoins($limit = 20) {
        $stmt = $this->db->getPDO()->prepare("SELECT * FROM coins ORDER BY market_cap DESC LIMIT ?");
        $stmt->execute([$limit]);
        return $stmt->fetchAll();
    }
    
    public function getCoinPrice($symbol) {
        $stmt = $this->db->getPDO()->prepare("SELECT price FROM coins WHERE symbol = ?");
        $stmt->execute([strtoupper($symbol)]);
        $result = $stmt->fetch();
        return $result ? (float)$result['price'] : 0;
    }
}

class Brain {
    private $db;
    private $api;
    private $marketData;
    
    public function __construct(Database $db, ApiManager $api, MarketData $marketData) {
        $this->db = $db;
        $this->api = $api;
        $this->marketData = $marketData;
    }
    
    public function executeCycle() {
        try {
            $updatedCount = $this->marketData->updateMarketData();
            $topCoins = $this->marketData->getTopCoins(10);
            $activeAgents = $this->getActiveAgents();
            $agentsToLiquidate = $this->checkAgentsForLiquidation();
            
            $context = [
                'top_coins' => $topCoins,
                'active_agents' => $activeAgents,
                'agents_to_liquidate' => $agentsToLiquidate,
                'timestamp' => date('Y-m-d H:i:s')
            ];
            
            $decision = $this->makeDecision($context);
            $this->executeActions($decision);
            
            $this->db->log("Brain cycle completed", 'info', [
                'coins_updated' => $updatedCount,
                'decision' => $decision['action'] ?? 'none',
                'active_agents' => count($activeAgents)
            ]);
            
            return $decision;
        } catch (Exception $e) {
            $this->db->log("Brain cycle error: " . $e->getMessage(), 'error');
            return ['action' => 'wait', 'reasoning' => 'Error: ' . $e->getMessage()];
        }
    }
    
    private function checkAgentsForLiquidation() {
        $stmt = $this->db->getPDO()->query(
            "SELECT id, name, capital, balance, ((balance - capital) / capital * 100) as pnl_percent FROM agents WHERE status = 'active'"
        );
        $agents = $stmt->fetchAll();
        $toLiquidate = [];
        
        foreach ($agents as $agent) {
            if ($agent['pnl_percent'] <= NEXUS_LIQUIDATION_THRESHOLD) {
                $toLiquidate[] = $agent;
            }
        }
        
        return $toLiquidate;
    }
    
    private function makeDecision($context) {
        $prompt = "Contexte marche:\n" . json_encode($context, JSON_PRETTY_PRINT);
        $prompt .= "\n\nPrends une decision selon ton role NEXUS-1.";
        
        try {
            $response = $this->api->callMistral($prompt);
            
            $jsonStart = strpos($response, '{');
            $jsonEnd = strrpos($response, '}');
            
            if ($jsonStart !== false && $jsonEnd !== false) {
                $jsonStr = substr($response, $jsonStart, $jsonEnd - $jsonStart + 1);
                $decision = json_decode($jsonStr, true);
                
                if ($decision && isset($decision['action'])) {
                    return $decision;
                }
            }
            
            return ['action' => 'wait', 'reasoning' => 'Could not parse AI response'];
        } catch (Exception $e) {
            return ['action' => 'wait', 'reasoning' => 'AI error: ' . $e->getMessage()];
        }
    }
    
    private function executeActions($decision) {
        switch ($decision['action']) {
            case 'create_agent':
                if (isset($decision['agent_config'])) {
                    $this->createAgent($decision['agent_config']);
                }
                break;
            case 'liquidate':
                if (isset($decision['agent_config']['id'])) {
                    $this->liquidateAgent($decision['agent_config']['id']);
                }
                break;
        }
    }
    
    private function createAgent($config) {
        $name = $config['name'] ?? 'NEXUS-' . time();
        $dna = $config['dna'] ?? 'momentum';
        $capital = $config['capital'] ?? NEXUS_AGENT_INITIAL_CAPITAL;
        
        $stmt = $this->db->getPDO()->prepare("SELECT COUNT(*) FROM agents WHERE name = ?");
        $stmt->execute([$name]);
        if ($stmt->fetchColumn() > 0) {
            $name .= '-' . substr(md5(time()), 0, 4);
        }
        
        try {
            $stmt = $this->db->getPDO()->prepare(
                "INSERT INTO agents (name, dna, capital, balance) VALUES (?, ?, ?, ?)"
            );
            $stmt->execute([$name, $dna, $capital, $capital]);
            
            $this->db->log("Created agent: $name (DNA: $dna)", 'info');
            $this->updateRLMemory($dna, true);
        } catch (Exception $e) {
            $this->db->log("Failed to create agent: " . $e->getMessage(), 'error');
        }
    }
    
    private function liquidateAgent($agentId) {
        try {
            $stmt = $this->db->getPDO()->prepare(
                "UPDATE agents SET status = 'liquidated', updated_at = CURRENT_TIMESTAMP WHERE id = ?"
            );
            $stmt->execute([$agentId]);
            $this->db->log("Liquidated agent ID: $agentId", 'info');
        } catch (Exception $e) {
            $this->db->log("Failed to liquidate agent: " . $e->getMessage(), 'error');
        }
    }
    
    private function getActiveAgents() {
        $stmt = $this->db->getPDO()->query("SELECT * FROM agents WHERE status = 'active'");
        return $stmt->fetchAll();
    }
    
    private function updateRLMemory($strategy, $success) {
        if ($success) {
            $stmt = $this->db->getPDO()->prepare(
                "UPDATE rl_memory SET success_count = success_count + 1, last_used = CURRENT_TIMESTAMP WHERE strategy_name = ?"
            );
        } else {
            $stmt = $this->db->getPDO()->prepare(
                "UPDATE rl_memory SET failure_count = failure_count + 1, last_used = CURRENT_TIMESTAMP WHERE strategy_name = ?"
            );
        }
        $stmt->execute([$strategy]);
    }
}

class AgentManager {
    private $db;
    
    public function __construct(Database $db) {
        $this->db = $db;
    }
    
    public function getActiveAgents() {
        $stmt = $this->db->getPDO()->query(
            "SELECT * FROM agents WHERE status = 'active' ORDER BY total_pnl DESC"
        );
        return $stmt->fetchAll();
    }
    
    public function getAgentById($id) {
        $stmt = $this->db->getPDO()->prepare("SELECT * FROM agents WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch();
    }
    
    public function executeTrade($agentId, $coinSymbol, $type, $amount, $price) {
        try {
            $pdo = $this->db->getPDO();
            $pdo->beginTransaction();
            
            $stmt = $pdo->prepare(
                "INSERT INTO trades (agent_id, coin_symbol, type, amount, price, status) VALUES (?, ?, ?, ?, ?, 'closed')"
            );
            $stmt->execute([$agentId, $coinSymbol, $type, $amount, $price]);
            
            $agent = $this->getAgentById($agentId);
            if (!$agent) {
                $pdo->rollBack();
                return false;
            }
            
            $tradeValue = $amount * $price;
            $newBalance = $type === 'buy' ? $agent['balance'] - $tradeValue : $agent['balance'] + $tradeValue;
            
            $stmt = $pdo->prepare(
                "UPDATE agents SET balance = ?, total_trades = total_trades + 1, updated_at = CURRENT_TIMESTAMP WHERE id = ?"
            );
            $stmt->execute([$newBalance, $agentId]);
            
            $pdo->commit();
            return true;
        } catch (Exception $e) {
            $this->db->getPDO()->rollBack();
            $this->db->log("Trade execution error: " . $e->getMessage(), 'error');
            return false;
        }
    }
}

class Engine {
    private static $instance = null;
    private $db;
    private $api;
    private $marketData;
    private $brain;
    private $agentManager;
    
    private function __construct() {
        $this->db = new Database();
        $this->api = new ApiManager();
        $this->marketData = new MarketData($this->db, $this->api);
        $this->brain = new Brain($this->db, $this->api, $this->marketData);
        $this->agentManager = new AgentManager($this->db);
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function getDatabase() { return $this->db; }
    public function getApiManager() { return $this->api; }
    public function getMarketData() { return $this->marketData; }
    public function getBrain() { return $this->brain; }
    public function getAgentManager() { return $this->agentManager; }
    
    public function runBrainCycle() {
        return $this->brain->executeCycle();
    }
    
    public function getSystemStats() {
        return $this->db->getStats();
    }
}
