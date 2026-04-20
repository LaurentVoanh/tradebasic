<?php
/**
 * IA CRYPTO INVEST - Core Engine v3.0
 * Compatible PHP 7.2+ (Hostinger)
 * Architecture: Rotation API Mistral + Reinforcement Learning + AJAX polling
 */

namespace IACrypto\Core;

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
set_time_limit(300);

// Configuration
define('DB_DIR', __DIR__ . '/../db/');
define('CACHE_DIR', __DIR__ . '/../cache/');
define('LOGS_DIR', __DIR__ . '/../logs/');

// 3 API Keys Mistral en rotation
define('MISTRAL_KEYS', [
    ['key' => getenv('MISTRAL_API_KEY_1') ?: '', 'name' => 'primary', 'weight' => 50],
    ['key' => getenv('MISTRAL_API_KEY_2') ?: '', 'name' => 'secondary', 'weight' => 30],
    ['key' => getenv('MISTRAL_API_KEY_3') ?: '', 'name' => 'tertiary', 'weight' => 20]
]);

define('MISTRAL_ENDPOINT', 'https://api.mistral.ai/v1/chat/completions');
define('COINGECKO_MARKETS', 'https://api.coingecko.com/api/v3/coins/markets?vs_currency=usd&order=market_cap_desc&per_page=100&page=1&sparkline=true');
define('BINANCE_TICKER', 'https://api.binance.com/api/v3/ticker/24hr');

// Trading params
define('INITIAL_CAPITAL', 1000000.00);
define('TARGET_AGENTS', 50);
define('AGENT_INITIAL_CAPITAL', 20000.00);
define('TRADE_INTERVAL_SECONDS', 60);
define('BRAIN_CYCLE_SECONDS', 30);
define('MIN_CONFIDENCE_TRADE', 65);

class Engine {
    private static $instance = null;
    private $apiRotation;
    private $db;
    private $brain;
    private $agentManager;
    private $market;
    private $cache;
    private $rlMemory;
    
    private function __construct() {
        $this->ensureDirectories();
        $this->cache = new Cache();
        $this->db = new Database();
        $this->apiRotation = new ApiRotation();
        $this->rlMemory = new RLMemory($this->db);
        $this->market = new MarketData($this->db, $this->cache);
        $this->agentManager = new AgentManager($this->db, $this->apiRotation, $this->rlMemory);
        $this->brain = new Brain($this->db, $this->agentManager, $this->rlMemory);
        $this->db->init();
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function ensureDirectories() {
        foreach ([DB_DIR, CACHE_DIR, LOGS_DIR] as $dir) {
            if (!is_dir($dir)) mkdir($dir, 0755, true);
        }
    }
    
    public function getApiRotation() { return $this->apiRotation; }
    public function getDatabase() { return $this->db; }
    public function getBrain() { return $this->brain; }
    public function getAgentManager() { return $this->agentManager; }
    public function getMarket() { return $this->market; }
    public function getCache() { return $this->cache; }
    public function getRLMemory() { return $this->rlMemory; }
    
    public function runBrainCycle() {
        return $this->brain->runCycle();
    }
    
    public function getSystemStats() {
        return $this->db->getStats();
    }
}

class ApiRotation {
    private $keys = [];
    private $stats = [];
    private $statsFile;
    
    public function __construct() {
        $this->statsFile = CACHE_DIR . 'api_stats.json';
        $this->loadKeys();
        $this->loadStats();
    }
    
    private function loadKeys() {
        foreach (MISTRAL_KEYS as $config) {
            if (!empty($config['key'])) {
                $this->keys[] = [
                    'key' => $config['key'],
                    'name' => $config['name'],
                    'weight' => $config['weight'],
                    'health' => 100,
                    'requests' => 0,
                    'errors' => 0,
                    'last_error' => 0,
                    'avg_latency' => 0
                ];
            }
        }
        if (empty($this->keys)) {
            error_log("Aucune API key Mistral - mode simulation");
        }
    }
    
    private function loadStats() {
        if (file_exists($this->statsFile)) {
            $data = json_decode(file_get_contents($this->statsFile), true);
            if ($data) {
                foreach ($this->keys as &$key) {
                    if (isset($data[$key['name']])) {
                        $key = array_merge($key, $data[$key['name']]);
                    }
                }
            }
        }
    }
    
    private function saveStats() {
        $data = [];
        foreach ($this->keys as $key) {
            $data[$key['name']] = [
                'health' => $key['health'],
                'requests' => $key['requests'],
                'errors' => $key['errors'],
                'last_error' => $key['last_error'],
                'avg_latency' => $key['avg_latency']
            ];
        }
        file_put_contents($this->statsFile, json_encode($data, JSON_PRETTY_PRINT));
    }
    
    public function selectKey() {
        if (empty($this->keys)) return null;
        $healthyKeys = array_filter($this->keys, function($k) { return $k['health'] > 30; });
        if (empty($healthyKeys)) $healthyKeys = $this->keys;
        
        $totalWeight = 0;
        foreach ($healthyKeys as &$key) {
            $key['effective_weight'] = $key['weight'] * ($key['health'] / 100);
            $totalWeight += $key['effective_weight'];
        }
        
        $rand = mt_rand() / mt_getrandmax() * $totalWeight;
        $cumulative = 0;
        foreach ($healthyKeys as $key) {
            $cumulative += $key['effective_weight'];
            if ($rand <= $cumulative) return $key;
        }
        return end($healthyKeys);
    }
    
    public function call($messages, $model = 'mistral-small-2506', $maxTokens = 2000, $temperature = 0.7, $maxRetries = 3) {
        if (empty($this->keys)) return $this->simulateResponse($messages);
        
        $attempt = 0;
        while ($attempt < $maxRetries) {
            $selectedKey = $this->selectKey();
            if (!$selectedKey) return $this->simulateResponse($messages);
            
            $startTime = microtime(true);
            $result = $this->executeRequest($selectedKey, $messages, $model, $maxTokens, $temperature);
            $latency = (microtime(true) - $startTime) * 1000;
            
            $this->updateKeyStats($selectedKey['name'], $latency, $result !== null);
            
            if ($result !== null) return $result;
            $attempt++;
            if ($attempt < $maxRetries) usleep(500000);
        }
        return $this->simulateResponse($messages);
    }
    
    private function executeRequest($keyConfig, $messages, $model, $maxTokens, $temperature) {
        $totalLen = 0;
        foreach ($messages as $m) $totalLen += strlen($m['content']);
        if ($totalLen > 40000) $model = 'mistral-small-2603';
        
        $payload = json_encode([
            'model' => $model,
            'max_tokens' => $maxTokens,
            'messages' => $messages,
            'temperature' => $temperature,
        ]);
        
        $opts = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n" .
                           "Authorization: Bearer {$keyConfig['key']}\r\n" .
                           "Accept: application/json\r\n",
                'content' => $payload,
                'timeout' => 120,
                'ignore_errors' => true,
            ]
        ]);
        
        $raw = @file_get_contents(MISTRAL_ENDPOINT, false, $opts);
        if (!$raw) {
            $this->recordError($keyConfig['name']);
            return null;
        }
        
        $data = json_decode($raw, true);
        if (isset($data['error'])) {
            error_log("Erreur Mistral: " . json_encode($data['error']));
            $this->recordError($keyConfig['name']);
            if (isset($data['error']['code']) && $data['error']['code'] === 'rate_limit') {
                $this->decreaseHealth($keyConfig['name'], 40);
            }
            return null;
        }
        
        return isset($data['choices'][0]['message']['content']) ? $data['choices'][0]['message']['content'] : null;
    }
    
    private function updateKeyStats($keyName, $latency, $success) {
        foreach ($this->keys as &$key) {
            if ($key['name'] === $keyName) {
                $key['requests']++;
                $alpha = 0.1;
                $key['avg_latency'] = $alpha * $latency + (1 - $alpha) * $key['avg_latency'];
                $key['health'] = $success ? min(100, $key['health'] + 2) : max(0, $key['health'] - 10);
                break;
            }
        }
        $this->saveStats();
    }
    
    private function recordError($keyName) {
        foreach ($this->keys as &$key) {
            if ($key['name'] === $keyName) {
                $key['errors']++;
                $key['last_error'] = time();
                $key['health'] = max(0, $key['health'] - 15);
                break;
            }
        }
        $this->saveStats();
    }
    
    private function decreaseHealth($keyName, $amount) {
        foreach ($this->keys as &$key) {
            if ($key['name'] === $keyName) {
                $key['health'] = max(0, $key['health'] - $amount);
                break;
            }
        }
        $this->saveStats();
    }
    
    private function simulateResponse($messages) {
        $lastMsg = end($messages);
        $content = isset($lastMsg['content']) ? $lastMsg['content'] : '';
        
        if (strpos($content, 'JSON') !== false && strpos($content, 'action') !== false) {
            $actions = ['buy', 'sell', 'hold'];
            $coins = ['BTC', 'ETH', 'SOL', 'BNB', 'XRP', 'ADA', 'DOGE', 'AVAX'];
            return json_encode([
                'action' => $actions[array_rand($actions)],
                'coin' => $coins[array_rand($coins)],
                'amount_brics' => rand(500, 3000),
                'reasoning' => "Analyse technique favorable.",
                'confidence' => rand(60, 95),
                'timeframe' => 'short'
            ], JSON_PRETTY_PRINT);
        }
        
        if (strpos($content, 'Crée') !== false || strpos($content, 'agents') !== false) {
            return json_encode([
                'agents' => [[
                    'name' => 'AI Trader ' . rand(1000, 9999),
                    'strategy_prompt' => 'Trading momentum.',
                    'strategy_type' => 'momentum',
                    'timeframe' => 'short'
                ]]
            ], JSON_PRETTY_PRINT);
        }
        
        return "Simulation: Analyse positive.";
    }
    
    public function getKeyStats() {
        $result = [];
        foreach ($this->keys as $key) {
            $result[] = [
                'name' => $key['name'],
                'health' => $key['health'],
                'requests' => $key['requests'],
                'errors' => $key['errors'],
                'avg_latency' => round($key['avg_latency'], 2),
                'status' => $key['health'] > 70 ? 'excellent' : ($key['health'] > 30 ? 'good' : 'degraded')
            ];
        }
        return $result;
    }
}

class Database {
    private $connections = [];
    
    public function getConnection($dbName = 'main') {
        if (!isset($this->connections[$dbName])) {
            $path = DB_DIR . $dbName . '.db';
            $pdo = new \PDO('sqlite:' . $path);
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $pdo->exec('PRAGMA journal_mode=WAL');
            $pdo->exec('PRAGMA synchronous=NORMAL');
            $pdo->exec('PRAGMA cache_size=10000');
            $this->connections[$dbName] = $pdo;
        }
        return $this->connections[$dbName];
    }
    
    public function init() {
        $db = $this->getConnection('main');
        
        $tables = [
            "CREATE TABLE IF NOT EXISTS coins (
                id TEXT PRIMARY KEY,
                symbol TEXT NOT NULL,
                name TEXT NOT NULL,
                current_price REAL DEFAULT 0,
                market_cap REAL DEFAULT 0,
                market_cap_rank INTEGER,
                volume_24h REAL DEFAULT 0,
                price_change_24h REAL DEFAULT 0,
                price_change_pct_24h REAL DEFAULT 0,
                price_change_7d REAL DEFAULT 0,
                sparkline_7d TEXT DEFAULT '[]',
                image_url TEXT,
                updated_at INTEGER DEFAULT 0
            )",
            "CREATE TABLE IF NOT EXISTS agents (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER,
                name TEXT NOT NULL,
                strategy_prompt TEXT NOT NULL,
                strategy_type TEXT DEFAULT 'custom',
                capital_brics REAL DEFAULT 10000.00,
                total_pnl REAL DEFAULT 0,
                total_pnl_percent REAL DEFAULT 0,
                win_rate REAL DEFAULT 0,
                total_trades INTEGER DEFAULT 0,
                drawdown_max REAL DEFAULT 0,
                status TEXT DEFAULT 'active',
                is_master INTEGER DEFAULT 0,
                generation INTEGER DEFAULT 1,
                parent_ids TEXT DEFAULT '[]',
                reinforcement_score REAL DEFAULT 0,
                timeframe TEXT DEFAULT 'short',
                created_at INTEGER DEFAULT 0,
                last_action_at INTEGER,
                last_trade_at INTEGER DEFAULT 0
            )",
            "CREATE TABLE IF NOT EXISTS agent_trades (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                agent_id INTEGER NOT NULL,
                coin_symbol TEXT NOT NULL,
                action TEXT NOT NULL,
                price REAL NOT NULL,
                quantity REAL NOT NULL,
                value_brics REAL NOT NULL,
                pnl REAL DEFAULT 0,
                pnl_percent REAL DEFAULT 0,
                reasoning TEXT,
                timeframe TEXT DEFAULT 'short',
                executed_at INTEGER DEFAULT 0
            )",
            "CREATE TABLE IF NOT EXISTS brain_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                action TEXT NOT NULL,
                details TEXT,
                agents_created INTEGER DEFAULT 0,
                agents_archived INTEGER DEFAULT 0,
                trade_executed INTEGER DEFAULT 0,
                created_at INTEGER DEFAULT 0
            )",
            "CREATE TABLE IF NOT EXISTS system_config (
                key TEXT PRIMARY KEY,
                value TEXT,
                updated_at INTEGER DEFAULT 0
            )",
            "CREATE TABLE IF NOT EXISTS console_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                log_type TEXT NOT NULL,
                message TEXT NOT NULL,
                data TEXT DEFAULT '{}',
                created_at INTEGER DEFAULT 0
            )",
            "CREATE TABLE IF NOT EXISTS rl_memory (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                state_hash TEXT NOT NULL,
                state_data TEXT NOT NULL,
                action_taken TEXT NOT NULL,
                reward REAL DEFAULT 0,
                episode_id TEXT,
                created_at INTEGER DEFAULT 0
            )",
            "CREATE TABLE IF NOT EXISTS open_positions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                agent_id INTEGER NOT NULL,
                coin_symbol TEXT NOT NULL,
                coin_id TEXT,
                quantity REAL NOT NULL,
                avg_buy_price REAL NOT NULL,
                total_invested REAL NOT NULL,
                current_value REAL DEFAULT 0,
                unrealized_pnl REAL DEFAULT 0,
                opened_at INTEGER DEFAULT 0
            )",
            "CREATE INDEX IF NOT EXISTS idx_positions_agent ON open_positions(agent_id)",
            "CREATE INDEX IF NOT EXISTS idx_positions_symbol ON open_positions(coin_symbol)"
        ];
        
        foreach ($tables as $sql) {
            try { $db->exec($sql); } catch (\Exception $e) {}
        }
        
        $db->exec("INSERT OR IGNORE INTO system_config (key, value) VALUES ('last_market_update', '0')");
        $db->exec("INSERT OR IGNORE INTO system_config (key, value) VALUES ('last_brain_run', '0')");
        $db->exec("INSERT OR IGNORE INTO system_config (key, value) VALUES ('total_brics_capital', '" . INITIAL_CAPITAL . "')");
    }
    
    public function getStats() {
        $db = $this->getConnection('main');
        $totalCapital = INITIAL_CAPITAL;
        $agentsCapital = (float)$db->query("SELECT COALESCE(SUM(capital_brics), 0) FROM agents WHERE status='active'")->fetchColumn();
        $investedCapital = (float)$db->query("SELECT COALESCE(SUM(current_value), 0) FROM open_positions")->fetchColumn();
        $realizedPnl = (float)$db->query("SELECT COALESCE(SUM(pnl), 0) FROM agent_trades WHERE action='sell'")->fetchColumn();
        $unrealizedPnl = (float)$db->query("SELECT COALESCE(SUM(unrealized_pnl), 0) FROM open_positions")->fetchColumn();
        $currentTotalCapital = $agentsCapital + $investedCapital + $realizedPnl;
        $agentsCount = (int)$db->query("SELECT COUNT(*) FROM agents WHERE status='active'")->fetchColumn();
        $totalTrades = (int)$db->query("SELECT COUNT(*) FROM agent_trades")->fetchColumn();
        $winningTrades = (int)$db->query("SELECT COUNT(*) FROM agent_trades WHERE action='sell' AND pnl > 0")->fetchColumn();
        $winRate = $totalTrades > 0 ? ($winningTrades / $totalTrades) * 100 : 0;
        
        return [
            'total_capital' => round($currentTotalCapital, 2),
            'pool_capital' => round($agentsCapital, 2),
            'invested_capital' => round($investedCapital, 2),
            'realized_pnl' => round($realizedPnl, 2),
            'unrealized_pnl' => round($unrealizedPnl, 2),
            'total_pnl' => round($realizedPnl + $unrealizedPnl, 2),
            'agents_count' => $agentsCount,
            'total_trades' => $totalTrades,
            'win_rate' => round($winRate, 2)
        ];
    }
    
    public function logConsole($type, $message, $data = []) {
        $db = $this->getConnection('main');
        $stmt = $db->prepare("INSERT INTO console_logs (log_type, message, data, created_at) VALUES (?, ?, ?, ?)");
        $stmt->execute([$type, $message, json_encode($data), time()]);
        
        // Cleanup old logs
        $db->exec("DELETE FROM console_logs WHERE created_at < " . (time() - 3600));
    }
}

class Cache {
    private $memory = [];
    private $fileDir;
    
    public function __construct() {
        $this->fileDir = CACHE_DIR;
    }
    
    public function get($key, $ttl = 300) {
        if (isset($this->memory[$key])) {
            $item = $this->memory[$key];
            if ($item['expires'] > time()) return $item['data'];
            unset($this->memory[$key]);
        }
        
        $file = $this->fileDir . 'cache_' . md5($key) . '.json';
        if (file_exists($file)) {
            $data = json_decode(file_get_contents($file), true);
            if ($data && $data['expires'] > time()) {
                $this->memory[$key] = $data;
                return $data['data'];
            }
        }
        return null;
    }
    
    public function set($key, $value, $ttl = 300) {
        $item = ['data' => $value, 'expires' => time() + $ttl, 'created' => time()];
        $this->memory[$key] = $item;
        $file = $this->fileDir . 'cache_' . md5($key) . '.json';
        file_put_contents($file, json_encode($item));
    }
}

class RLMemory {
    private $db;
    
    public function __construct($db) {
        $this->db = $db;
    }
    
    public function store($state, $action, $reward, $episodeId) {
        $db = $this->db->getConnection('main');
        $stateHash = md5(json_encode($state));
        $stmt = $db->prepare("INSERT INTO rl_memory (state_hash, state_data, action_taken, reward, episode_id, created_at) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$stateHash, json_encode($state), $action, $reward, $episodeId, time()]);
    }
    
    public function getSimilarStates($currentState, $limit = 10) {
        // Simplified: return recent memories
        $db = $this->db->getConnection('main');
        $stmt = $db->prepare("SELECT * FROM rl_memory ORDER BY created_at DESC LIMIT ?");
        $stmt->execute([$limit]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
    
    public function updateFromReward($episodeId, $finalReward) {
        $db = $this->db->getConnection('main');
        $stmt = $db->prepare("UPDATE rl_memory SET reward = ? WHERE episode_id = ?");
        $stmt->execute([$finalReward, $episodeId]);
    }
}

class MarketData {
    private $db;
    private $cache;
    
    public function __construct($db, $cache) {
        $this->db = $db;
        $this->cache = $cache;
    }
    
    public function update() {
        $cacheKey = 'coingecko_markets';
        $cached = $this->cache->get($cacheKey, 60);
        
        if ($cached) {
            $this->storeCoins($cached);
            return count($cached);
        }
        
        $ch = curl_init(COINGECKO_MARKETS);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $response = curl_exec($ch);
        curl_close($ch);
        
        if (!$response) return 0;
        
        $data = json_decode($response, true);
        if (!isset($data) || !is_array($data)) return 0;
        
        $this->cache->set($cacheKey, $data, 60);
        $this->storeCoins($data);
        
        // Fetch page 2 for more coins
        $this->fetchPage(2);
        
        return count($data);
    }
    
    private function fetchPage($page) {
        $url = str_replace('page=1', 'page=' . $page, COINGECKO_MARKETS);
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $response = curl_exec($ch);
        curl_close($ch);
        
        if ($response) {
            $data = json_decode($response, true);
            if (isset($data) && is_array($data)) {
                $this->cache->set('coingecko_page_' . $page, $data, 60);
                $this->storeCoins($data);
            }
        }
    }
    
    private function storeCoins($coins) {
        $db = $this->db->getConnection('main');
        $stmt = $db->prepare("INSERT OR REPLACE INTO coins 
            (id, symbol, name, current_price, market_cap, market_cap_rank, volume_24h, 
             price_change_24h, price_change_pct_24h, price_change_7d, sparkline_7d, image_url, updated_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        
        foreach ($coins as $coin) {
            $sparkline = isset($coin['sparkline_in_7d']['price']) ? json_encode($coin['sparkline_in_7d']['price']) : '[]';
            $stmt->execute([
                $coin['id'],
                strtoupper($coin['symbol']),
                $coin['name'],
                $coin['current_price'] ?? 0,
                $coin['market_cap'] ?? 0,
                $coin['market_cap_rank'] ?? 0,
                $coin['total_volume'] ?? 0,
                $coin['price_change_24h'] ?? 0,
                $coin['price_change_percentage_24h'] ?? 0,
                $coin['price_change_percentage_7d_in_currency'] ?? 0,
                $sparkline,
                $coin['image'] ?? '',
                time()
            ]);
        }
    }
    
    public function getCoins($limit = 100) {
        $db = $this->db->getConnection('main');
        $stmt = $db->prepare("SELECT * FROM coins ORDER BY market_cap_rank ASC LIMIT ?");
        $stmt->execute([$limit]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
    
    public function getCoin($symbol) {
        $db = $this->db->getConnection('main');
        $stmt = $db->prepare("SELECT * FROM coins WHERE symbol = ?");
        $stmt->execute([strtoupper($symbol)]);
        return $stmt->fetch(\PDO::FETCH_ASSOC);
    }
}

class AgentManager {
    private $db;
    private $api;
    private $rl;
    
    public function __construct($db, $api, $rl) {
        $this->db = $db;
        $this->api = $api;
        $this->rl = $rl;
    }
    
    public function createAgent($data) {
        $db = $this->db->getConnection('main');
        $stmt = $db->prepare("INSERT INTO agents 
            (name, strategy_prompt, strategy_type, capital_brics, timeframe, generation, parent_ids, created_at, last_action_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        
        $name = isset($data['name']) ? $data['name'] : 'AI Trader ' . rand(1000, 9999);
        $strategy = isset($data['strategy_prompt']) ? $data['strategy_prompt'] : 'Trading based on technical analysis.';
        $type = isset($data['strategy_type']) ? $data['strategy_type'] : 'custom';
        $timeframe = isset($data['timeframe']) ? $data['timeframe'] : 'short';
        $capital = isset($data['capital_brics']) ? $data['capital_brics'] : AGENT_INITIAL_CAPITAL;
        $gen = isset($data['generation']) ? $data['generation'] : 1;
        $parents = isset($data['parent_ids']) ? json_encode($data['parent_ids']) : '[]';
        
        $stmt->execute([$name, $strategy, $type, $capital, $timeframe, $gen, $parents, time(), time()]);
        $agentId = $db->lastInsertId();
        
        $this->db->logConsole('agent_created', "Nouvel agent: $name", ['id' => $agentId, 'strategy' => $type]);
        
        return $agentId;
    }
    
    public function getActiveAgents() {
        $db = $this->db->getConnection('main');
        $stmt = $db->query("SELECT * FROM agents WHERE status='active' ORDER BY reinforcement_score DESC");
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
    
    public function getAgent($id) {
        $db = $this->db->getConnection('main');
        $stmt = $db->prepare("SELECT * FROM agents WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(\PDO::FETCH_ASSOC);
    }
    
    public function executeTrade($agentId, $decision) {
        $db = $this->db->getConnection('main');
        $agent = $this->getAgent($agentId);
        if (!$agent) return false;
        
        $coin = $this->db->getMarket()->getCoin($decision['coin']);
        if (!$coin) return false;
        
        $action = strtolower($decision['action']);
        $amount = isset($decision['amount_brics']) ? min($decision['amount_brics'], $agent['capital_brics'] * 0.5) : 1000;
        
        if ($action === 'buy') {
            $quantity = $amount / $coin['current_price'];
            $stmt = $db->prepare("INSERT INTO agent_trades 
                (agent_id, coin_symbol, action, price, quantity, value_brics, reasoning, timeframe, executed_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $agentId, strtoupper($decision['coin']), 'buy', $coin['current_price'], 
                $quantity, $amount, isset($decision['reasoning']) ? $decision['reasoning'] : '', 
                isset($decision['timeframe']) ? $decision['timeframe'] : 'short', time()
            ]);
            
            // Create/update position
            $existingPos = $db->prepare("SELECT * FROM open_positions WHERE agent_id = ? AND coin_symbol = ?");
            $existingPos->execute([$agentId, strtoupper($decision['coin'])]);
            $pos = $existingPos->fetch(\PDO::FETCH_ASSOC);
            
            if ($pos) {
                $newQty = $pos['quantity'] + $quantity;
                $newAvg = (($pos['avg_buy_price'] * $pos['quantity']) + ($coin['current_price'] * $quantity)) / $newQty;
                $newInvested = $pos['total_invested'] + $amount;
                $updateStmt = $db->prepare("UPDATE open_positions SET quantity = ?, avg_buy_price = ?, total_invested = ? WHERE id = ?");
                $updateStmt->execute([$newQty, $newAvg, $newInvested, $pos['id']]);
            } else {
                $insStmt = $db->prepare("INSERT INTO open_positions 
                    (agent_id, coin_symbol, coin_id, quantity, avg_buy_price, total_invested, opened_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?)");
                $insStmt->execute([$agentId, strtoupper($decision['coin']), $coin['id'], $quantity, $coin['current_price'], $amount, time()]);
            }
            
            // Update agent capital
            $updAgent = $db->prepare("UPDATE agents SET capital_brics = capital_brics - ?, last_trade_at = ? WHERE id = ?");
            $updAgent->execute([$amount, time(), $agentId]);
            
            $this->db->logConsole('trade_buy', "Achat: $amount BRICS de " . $decision['coin'], ['agent' => $agent['name'], 'price' => $coin['current_price']]);
            
        } elseif ($action === 'sell') {
            $posStmt = $db->prepare("SELECT * FROM open_positions WHERE agent_id = ? AND coin_symbol = ?");
            $posStmt->execute([$agentId, strtoupper($decision['coin'])]);
            $pos = $posStmt->fetch(\PDO::FETCH_ASSOC);
            
            if ($pos) {
                $sellValue = $pos['quantity'] * $coin['current_price'];
                $pnl = $sellValue - $pos['total_invested'];
                $pnlPercent = ($pnl / $pos['total_invested']) * 100;
                
                $stmt = $db->prepare("INSERT INTO agent_trades 
                    (agent_id, coin_symbol, action, price, quantity, value_brics, pnl, pnl_percent, reasoning, timeframe, executed_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    $agentId, strtoupper($decision['coin']), 'sell', $coin['current_price'],
                    $pos['quantity'], $sellValue, $pnl, $pnlPercent,
                    isset($decision['reasoning']) ? $decision['reasoning'] : 'Sell signal',
                    isset($decision['timeframe']) ? $decision['timeframe'] : 'short', time()
                ]);
                
                // Delete position
                $delStmt = $db->prepare("DELETE FROM open_positions WHERE id = ?");
                $delStmt->execute([$pos['id']]);
                
                // Update agent capital and stats
                $newCapital = $agent['capital_brics'] + $sellValue;
                $newPnl = $agent['total_pnl'] + $pnl;
                $newTrades = $agent['total_trades'] + 1;
                $wins = $pnl > 0 ? 1 : 0;
                
                $updAgent = $db->prepare("UPDATE agents SET capital_brics = ?, total_pnl = ?, total_trades = ?, last_trade_at = ? WHERE id = ?");
                $updAgent->execute([$newCapital, $newPnl, $newTrades, time(), $agentId]);
                
                // Store RL memory
                $this->rl->store(
                    ['coin' => $decision['coin'], 'action' => 'sell', 'entry' => $pos['avg_buy_price']],
                    'sell',
                    $pnl,
                    'trade_' . $agentId . '_' . time()
                );
                
                $this->db->logConsole('trade_sell', "Vente: " . $decision['coin'] . " PnL: " . round($pnl, 2) . " BRICS", ['agent' => $agent['name'], 'pnl' => $pnl]);
            }
        }
        
        // Update last action
        $updAction = $db->prepare("UPDATE agents SET last_action_at = ? WHERE id = ?");
        $updAction->execute([time(), $agentId]);
        
        return true;
    }
    
    public function archiveAgent($agentId, $reason) {
        $db = $this->db->getConnection('main');
        $agent = $this->getAgent($agentId);
        if (!$agent) return false;
        
        $stmt = $db->prepare("INSERT INTO agents_archive 
            (original_agent_id, name, strategy_prompt, final_pnl_percent, total_trades, win_rate, reason_archived, archived_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        
        $pnlPercent = $agent['total_pnl_percent'];
        $stmt->execute([
            $agentId, $agent['name'], $agent['strategy_prompt'],
            $pnlPercent, $agent['total_trades'], $agent['win_rate'],
            $reason, time()
        ]);
        
        $db->exec("UPDATE agents SET status='archived' WHERE id = $agentId");
        $this->db->logConsole('agent_archived', "Agent archivé: " . $agent['name'], ['reason' => $reason]);
        
        return true;
    }
    
    public function getActiveCount() {
        $db = $this->db->getConnection('main');
        return (int)$db->query("SELECT COUNT(*) FROM agents WHERE status='active'")->fetchColumn();
    }
}

class Brain {
    private $db;
    private $agentManager;
    private $rl;
    
    public function __construct($db, $agentManager, $rl) {
        $this->db = $db;
        $this->agentManager = $agentManager;
        $this->rl = $rl;
    }
    
    public function runCycle() {
        $result = ['created' => 0, 'archived' => 0, 'actions' => []];
        
        // Get market state
        $coins = $this->db->getMarket()->getCoins(50);
        $agents = $this->agentManager->getActiveAgents();
        $stats = $this->db->getStats();
        
        // Build context for AI
        $marketContext = "Marché crypto actuel:\n";
        foreach (array_slice($coins, 0, 10) as $c) {
            $marketContext .= "- {$c['symbol']}: \${$c['current_price']} (" . round($c['price_change_pct_24h'], 2) . "%)\n";
        }
        
        $agentsContext = "\nAgents actifs: " . count($agents) . "\n";
        foreach (array_slice($agents, 0, 5) as $a) {
            $agentsContext .= "- {$a['name']}: Capital: " . round($a['capital_brics'], 2) . ", PnL: " . round($a['total_pnl'], 2) . "\n";
        }
        
        $prompt = "Tu es le cerveau central d'un système de trading crypto avec 1,000,000 BRICS.
$marketContext
$agentsContext

Statistiques: Capital total: {$stats['total_capital']} BRICS, Trades: {$stats['total_trades']}, Win Rate: {$stats['win_rate']}%

Tâches:
1. Analyser les tendances du marché
2. Décider si créer de nouveaux agents ou en archiver
3. Donner des recommandations de trading

Réponds en JSON:
{
  \"analysis\": \"ton analyse\",
  \"create_agents\": [{\"name\": \"...\", \"strategy_prompt\": \"...\", \"strategy_type\": \"momentum|mean_reversion|breakout\", \"timeframe\": \"short|medium|long\"}],
  \"archive_agents\": [agent_ids],
  \"trading_signal\": {\"action\": \"buy|sell|hold\", \"coin\": \"BTC\", \"reasoning\": \"...\", \"confidence\": 75}
}";
        
        $messages = [['role' => 'user', 'content' => $prompt]];
        $response = $this->db->getApiRotation()->call($messages, 'mistral-small-2506', 2500, 0.7);
        
        // Parse response
        $decision = $this->parseJsonResponse($response);
        
        // Create agents
        if (isset($decision['create_agents']) && is_array($decision['create_agents'])) {
            foreach ($decision['create_agents'] as $agentData) {
                if ($this->agentManager->getActiveCount() < TARGET_AGENTS) {
                    $this->agentManager->createAgent($agentData);
                    $result['created']++;
                }
            }
        }
        
        // Archive underperforming agents
        foreach ($agents as $agent) {
            if ($agent['total_pnl'] < -5000 && $agent['total_trades'] > 5) {
                $this->agentManager->archiveAgent($agent['id'], 'Poor performance');
                $result['archived']++;
            }
        }
        
        // Execute trades for all active agents
        $freshAgents = $this->agentManager->getActiveAgents();
        foreach ($freshAgents as $agent) {
            if (time() - $agent['last_trade_at'] > TRADE_INTERVAL_SECONDS) {
                $agentPrompt = "Tu es {$agent['name']}. Stratégie: {$agent['strategy_prompt']}.
Timeframe: {$agent['timeframe']}. Capital disponible: " . round($agent['capital_brics'], 2) . " BRICS.
$marketContext

Décide d'une action de trading. Réponds en JSON:
{\"action\": \"buy|sell|hold\", \"coin\": \"BTC\", \"amount_brics\": 1000, \"reasoning\": \"...\", \"confidence\": 80, \"timeframe\": \"short\"}";
                
                $agentMessages = [['role' => 'user', 'content' => $agentPrompt]];
                $agentResponse = $this->db->getApiRotation()->call($agentMessages, 'ministral-8b-2512', 1500, 0.8);
                $agentDecision = $this->parseJsonResponse($agentResponse);
                
                if (isset($agentDecision['action']) && isset($agentDecision['coin'])) {
                    $conf = isset($agentDecision['confidence']) ? (int)$agentDecision['confidence'] : 50;
                    if ($conf >= MIN_CONFIDENCE_TRADE) {
                        $this->agentManager->executeTrade($agent['id'], $agentDecision);
                        $result['actions'][] = "{$agent['name']} {$agentDecision['action']} {$agentDecision['coin']}";
                    }
                }
            }
        }
        
        // Log brain cycle
        $this->db->logConsole('brain_cycle', 'Cycle cerveau terminé', $result);
        
        return $result;
    }
    
    private function parseJsonResponse($text) {
        if (!$text) return [];
        
        // Try to find JSON in response
        preg_match('/\{.*\}/s', $text, $matches);
        if (isset($matches[0])) {
            $result = json_decode($matches[0], true);
            if ($result) return $result;
        }
        
        return json_decode($text, true) ?: [];
    }
}
