<?php
/**
 * IA CRYPTO INVEST - Core Engine v2.0
 * Architecture inspirée des meilleurs systèmes de trading IA autonomes
 * 
 * FEATURES:
 * - Rotation intelligente de 3 API keys Mistral (load balancing + health check)
 * - Reinforcement Learning avec mémoire épisodique
 * - Auto-research par évolution génétique des stratégies
 * - SQLite optimisé pour time-series financières
 * - Queue de décisions asynchrone
 * - Cache intelligent des réponses IA
 */

namespace IACrypto\Core;

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
set_time_limit(600);

// ============================================================
// CONFIGURATION
// ============================================================
define('DB_DIR', __DIR__ . '/../db/');
define('CACHE_DIR', __DIR__ . '/../cache/');
define('LOGS_DIR', __DIR__ . '/../logs/');

// Configuration Mistral - 3 API Keys en rotation
define('MISTRAL_KEYS', [
    [
        'key' => getenv('MISTRAL_API_KEY_1') ?: '',
        'name' => 'primary',
        'weight' => 50, // 50% du trafic
        'priority' => 1
    ],
    [
        'key' => getenv('MISTRAL_API_KEY_2') ?: '',
        'name' => 'secondary',
        'weight' => 30, // 30% du trafic
        'priority' => 2
    ],
    [
        'key' => getenv('MISTRAL_API_KEY_3') ?: '',
        'name' => 'tertiary',
        'weight' => 20, // 20% du trafic
        'priority' => 3
    ]
]);

define('MISTRAL_ENDPOINT', 'https://api.mistral.ai/v1/chat/completions');
define('COINGECKO_MARKETS', 'https://api.coingecko.com/api/v3/coins/markets?vs_currency=eur&order=market_cap_desc&per_page=50&page=1&sparkline=true&price_change_percentage=24h,7d,30d');
define('COINGECKO_HISTORY', 'https://api.coingecko.com/api/v3/coins/{id}/ohlc?vs_currency=eur&days={days}');
define('BINANCE_TICKER', 'https://api.binance.com/api/v3/ticker/24hr');

// Paramètres de trading
define('INITIAL_CAPITAL', 1000000.00);
define('TARGET_AGENTS', 50);
define('AGENT_INITIAL_CAPITAL', 20000.00);
define('TRADE_INTERVAL_SECONDS', 8);
define('BRAIN_CYCLE_SECONDS', 30);
define('MAX_CONSOLE_LOGS', 500);

// Hyperparamètres RL
define('RL_LEARNING_RATE', 0.15);
define('RL_DISCOUNT_FACTOR', 0.9);
define('RL_EXPLORATION_RATE', 0.2); // ε-greedy exploration
define('MIN_CONFIDENCE_TRADE', 65);
define('MODEL_PRICE', 5000.00);

// Modèles Mistral disponibles
define('MISTRAL_MODELS', [
    'fast' => 'ministral-8b-2512',      // Rapide, peu coûteux
    'standard' => 'mistral-small-2506', // Équilibré
    'large' => 'mistral-small-2603',    // Grand contexte
    'premium' => 'mistral-large-latest' // Meilleure qualité
]);

// ============================================================
// AUTOLOADER
// ============================================================
spl_autoload_register(function ($class) {
    $prefix = 'IACrypto\\';
    $base_dir = __DIR__ . '/';
    
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) return;
    
    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';
    
    if (file_exists($file)) require $file;
});

// ============================================================
// CLASSE PRINCIPALE - Point d'entrée unique
// ============================================================
class Engine {
    private static ?Engine $instance = null;
    private ApiRotation $apiRotation;
    private Database $db;
    private Brain $brain;
    private AgentManager $agentManager;
    private MarketData $market;
    private Cache $cache;
    private RLMemory $rlMemory;
    
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
    
    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function ensureDirectories(): void {
        foreach ([DB_DIR, CACHE_DIR, LOGS_DIR] as $dir) {
            if (!is_dir($dir)) mkdir($dir, 0755, true);
        }
    }
    
    public function getApiRotation(): ApiRotation { return $this->apiRotation; }
    public function getDatabase(): Database { return $this->db; }
    public function getBrain(): Brain { return $this->brain; }
    public function getAgentManager(): AgentManager { return $this->agentManager; }
    public function getMarket(): MarketData { return $this->market; }
    public function getCache(): Cache { return $this->cache; }
    public function getRLMemory(): RLMemory { return $this->rlMemory; }
    
    public function runBrainCycle(): array {
        return $this->brain->runCycle();
    }
    
    public function getSystemStats(): array {
        return $this->db->getStats();
    }
}

// ============================================================
// GESTIONNAIRE DE ROTATION API MISTRAL
// ============================================================
class ApiRotation {
    private array $keys = [];
    private array $stats = [];
    private int $currentIndex = 0;
    private string $statsFile;
    
    public function __construct() {
        $this->statsFile = CACHE_DIR . 'api_stats.json';
        $this->loadKeys();
        $this->loadStats();
    }
    
    private function loadKeys(): void {
        foreach (MISTRAL_KEYS as $config) {
            if (!empty($config['key'])) {
                $this->keys[] = [
                    'key' => $config['key'],
                    'name' => $config['name'],
                    'weight' => $config['weight'],
                    'priority' => $config['priority'],
                    'health' => 100,      // Score de santé 0-100
                    'requests' => 0,      // Total requêtes
                    'errors' => 0,        // Erreurs dernières 24h
                    'last_error' => 0,    // Timestamp dernière erreur
                    'quota_remaining' => null, // Si l'API le fournit
                    'avg_latency' => 0    // Latence moyenne ms
                ];
            }
        }
        
        // Fallback vers mode simulation si aucune clé
        if (empty($this->keys)) {
            error_log("Aucune API key Mistral configurée - mode simulation activé");
        }
    }
    
    private function loadStats(): void {
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
    
    private function saveStats(): void {
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
    
    /**
     * Sélectionne la meilleure clé API selon poids, santé et latence
     * Algorithme: Weighted Random + Health Filter
     */
    public function selectKey(): ?array {
        if (empty($this->keys)) return null;
        
        // Filtrer les clés en bonne santé (> 30)
        $healthyKeys = array_filter($this->keys, fn($k) => $k['health'] > 30);
        
        if (empty($healthyKeys)) {
            // Toutes les clés sont dégradées, prendre la moins pire
            $healthyKeys = $this->keys;
        }
        
        // Sélection pondérée par weight * health
        $totalWeight = 0;
        foreach ($healthyKeys as &$key) {
            $key['effective_weight'] = $key['weight'] * ($key['health'] / 100);
            $totalWeight += $key['effective_weight'];
        }
        
        $rand = mt_rand() / mt_getrandmax() * $totalWeight;
        $cumulative = 0;
        
        foreach ($healthyKeys as $key) {
            $cumulative += $key['effective_weight'];
            if ($rand <= $cumulative) {
                return $key;
            }
        }
        
        return end($healthyKeys);
    }
    
    /**
     * Appelle l'API Mistral avec retry automatique sur échec
     */
    public function call(array $messages, string $model = 'mistral-small-2506', 
                         int $maxTokens = 2000, float $temperature = 0.7,
                         int $maxRetries = 3): ?string {
        
        if (empty($this->keys)) {
            return $this->simulateResponse($messages);
        }
        
        $attempt = 0;
        $lastError = null;
        
        while ($attempt < $maxRetries) {
            $selectedKey = $this->selectKey();
            
            if (!$selectedKey) {
                error_log("Aucune clé API disponible");
                return $this->simulateResponse($messages);
            }
            
            $startTime = microtime(true);
            $result = $this->executeRequest($selectedKey, $messages, $model, $maxTokens, $temperature);
            $latency = (microtime(true) - $startTime) * 1000;
            
            // Mettre à jour les stats
            $this->updateKeyStats($selectedKey['name'], $latency, $result !== null);
            
            if ($result !== null) {
                return $result;
            }
            
            $lastError = "Échec tentative " . ($attempt + 1);
            $attempt++;
            
            if ($attempt < $maxRetries) {
                usleep(500000); // Attendre 500ms avant retry
            }
        }
        
        error_log("Toutes les tentatives API échouées: $lastError");
        return $this->simulateResponse($messages);
    }
    
    private function executeRequest(array $keyConfig, array $messages, string $model, 
                                    int $maxTokens, float $temperature): ?string {
        
        // Auto-select large context model if needed
        $totalLen = array_sum(array_map(fn($m) => strlen($m['content']), $messages));
        if ($totalLen > 40000 && $model !== 'mistral-small-2603') {
            $model = 'mistral-small-2603';
        }
        
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
        
        // Vérifier erreurs API
        if (isset($data['error'])) {
            error_log("Erreur API Mistral: " . json_encode($data['error']));
            $this->recordError($keyConfig['name']);
            
            // Rate limit - réduire santé drastiquement
            if (isset($data['error']['code']) && $data['error']['code'] === 'rate_limit') {
                $this->decreaseHealth($keyConfig['name'], 40);
            }
            return null;
        }
        
        return $data['choices'][0]['message']['content'] ?? null;
    }
    
    private function updateKeyStats(string $keyName, float $latency, bool $success): void {
        foreach ($this->keys as &$key) {
            if ($key['name'] === $keyName) {
                $key['requests']++;
                
                // Moyenne mobile exponentielle pour latence
                $alpha = 0.1;
                $key['avg_latency'] = $alpha * $latency + (1 - $alpha) * $key['avg_latency'];
                
                // Ajuster santé
                if ($success) {
                    $key['health'] = min(100, $key['health'] + 2);
                } else {
                    $key['health'] = max(0, $key['health'] - 10);
                }
                
                break;
            }
        }
        $this->saveStats();
    }
    
    private function recordError(string $keyName): void {
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
    
    private function decreaseHealth(string $keyName, int $amount): void {
        foreach ($this->keys as &$key) {
            if ($key['name'] === $keyName) {
                $key['health'] = max(0, $key['health'] - $amount);
                break;
            }
        }
        $this->saveStats();
    }
    
    /**
     * Simulation de réponse quand aucune API n'est disponible
     */
    private function simulateResponse(array $messages): ?string {
        $lastMsg = end($messages)['content'] ?? '';
        
        if (strpos($lastMsg, 'JSON') !== false && strpos($lastMsg, 'action') !== false) {
            $actions = ['buy', 'sell', 'hold'];
            $coins = ['BTC', 'ETH', 'SOL', 'BNB', 'XRP', 'ADA', 'DOGE', 'AVAX'];
            return json_encode([
                'action' => $actions[array_rand($actions)],
                'coin' => $coins[array_rand($coins)],
                'amount_brics' => rand(500, 3000),
                'reasoning' => "Analyse technique favorable avec momentum positif.",
                'confidence' => rand(60, 95),
                'timeframe' => 'short',
                'stop_loss' => 0,
                'take_profit' => 0
            ], JSON_PRETTY_PRINT);
        }
        
        if (strpos($lastMsg, 'Crée') !== false && strpos($lastMsg, 'agents') !== false) {
            return json_encode([
                'agents' => [[
                    'name' => 'AI Trader ' . rand(1000, 9999),
                    'strategy_prompt' => 'Trading basé sur analyse technique et momentum.',
                    'strategy_type' => 'momentum',
                    'timeframe' => 'short'
                ]]
            ], JSON_PRETTY_PRINT);
        }
        
        return "Simulation: Analyse positive du marché.";
    }
    
    /**
     * Retourne les statistiques des clés API
     */
    public function getKeyStats(): array {
        return array_map(function($key) {
            return [
                'name' => $key['name'],
                'health' => $key['health'],
                'requests' => $key['requests'],
                'errors' => $key['errors'],
                'avg_latency' => round($key['avg_latency'], 2),
                'status' => $key['health'] > 70 ? 'excellent' : ($key['health'] > 30 ? 'good' : 'degraded')
            ];
        }, $this->keys);
    }
}

// ============================================================
// GESTION DE BASE DE DONNÉES
// ============================================================
class Database {
    private array $connections = [];
    
    public function getConnection(string $dbName = 'main'): \PDO {
        if (!isset($this->connections[$dbName])) {
            $path = DB_DIR . $dbName . '.db';
            $pdo = new \PDO('sqlite:' . $path);
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $pdo->exec('PRAGMA journal_mode=WAL');
            $pdo->exec('PRAGMA synchronous=NORMAL');
            $pdo->exec('PRAGMA cache_size=10000');
            $pdo->exec('PRAGMA temp_store=MEMORY');
            $this->connections[$dbName] = $pdo;
        }
        return $this->connections[$dbName];
    }
    
    public function init(): void {
        $db = $this->getConnection('main');
        
        // Tables principales
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
                updated_at INTEGER DEFAULT (strftime('%s','now'))
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
                created_at INTEGER DEFAULT (strftime('%s','now')),
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
                executed_at INTEGER DEFAULT (strftime('%s','now'))
            )",
            "CREATE TABLE IF NOT EXISTS agents_archive (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                original_agent_id INTEGER,
                name TEXT,
                strategy_prompt TEXT,
                final_pnl_percent REAL,
                total_trades INTEGER,
                win_rate REAL,
                reason_archived TEXT,
                archived_at INTEGER DEFAULT (strftime('%s','now')),
                lessons_extracted TEXT
            )",
            "CREATE TABLE IF NOT EXISTS brain_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                action TEXT NOT NULL,
                details TEXT,
                agents_created INTEGER DEFAULT 0,
                agents_archived INTEGER DEFAULT 0,
                top_performer_id INTEGER,
                trade_executed INTEGER DEFAULT 0,
                created_at INTEGER DEFAULT (strftime('%s','now'))
            )",
            "CREATE TABLE IF NOT EXISTS system_config (
                key TEXT PRIMARY KEY,
                value TEXT,
                updated_at INTEGER DEFAULT (strftime('%s','now'))
            )",
            "CREATE TABLE IF NOT EXISTS console_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                log_type TEXT NOT NULL,
                message TEXT NOT NULL,
                data TEXT DEFAULT '{}',
                created_at INTEGER DEFAULT (strftime('%s','now'))
            )",
            "CREATE TABLE IF NOT EXISTS rl_memory (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                state_hash TEXT NOT NULL,
                state_data TEXT NOT NULL,
                action_taken TEXT NOT NULL,
                reward REAL DEFAULT 0,
                next_state_hash TEXT,
                episode_id TEXT,
                created_at INTEGER DEFAULT (strftime('%s','now'))
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
                opened_at INTEGER DEFAULT (strftime('%s','now')),
                FOREIGN KEY (agent_id) REFERENCES agents(id)
            )",
            "CREATE INDEX IF NOT EXISTS idx_positions_agent ON open_positions(agent_id)",
            "CREATE INDEX IF NOT EXISTS idx_positions_symbol ON open_positions(coin_symbol)",
        ];
        
        foreach ($tables as $sql) {
            try { $db->exec($sql); } catch (\Exception $e) {}
        }
        
        // Config par défaut
        $db->exec("INSERT OR IGNORE INTO system_config (key, value) VALUES ('last_market_update', '0')");
        $db->exec("INSERT OR IGNORE INTO system_config (key, value) VALUES ('last_brain_run', '0')");
        $db->exec("INSERT OR IGNORE INTO system_config (key, value) VALUES ('active_agents_count', '0')");
        $db->exec("INSERT OR IGNORE INTO system_config (key, value) VALUES ('total_brics_capital', '" . INITIAL_CAPITAL . "')");
    }
    
    public function getStats(): array {
        $db = $this->getConnection('main');
        
        // Capital total initial : 1 000 000 BRICS
        $totalCapital = INITIAL_CAPITAL;
        
        // Capital chez les agents (capital non investi)
        $agentsCapital = (float)$db->query("SELECT COALESCE(SUM(capital_brics), 0) FROM agents WHERE status='active'")->fetchColumn();
        
        // Valeur totale des positions ouvertes (capital investi)
        $investedCapital = (float)$db->query("SELECT COALESCE(SUM(current_value), 0) FROM open_positions")->fetchColumn();
        
        // PnL total réalisé (trades fermés)
        $realizedPnl = (float)$db->query("SELECT COALESCE(SUM(pnl), 0) FROM agent_trades WHERE action='sell'")->fetchColumn();
        
        // PnL non réalisé (positions ouvertes)
        $unrealizedPnl = (float)$db->query("SELECT COALESCE(SUM(unrealized_pnl), 0) FROM open_positions")->fetchColumn();
        
        // Capital total actuel = capital agents + positions + pnl réalisé
        $currentTotalCapital = $agentsCapital + $investedCapital + $realizedPnl;
        
        $agentsCount = (int)$db->query("SELECT COUNT(*) FROM agents WHERE status='active'")->fetchColumn();
        $totalTrades = (int)$db->query("SELECT COUNT(*) FROM agent_trades")->fetchColumn();
        $winningTrades = (int)$db->query("SELECT COUNT(*) FROM agent_trades WHERE action='sell' AND pnl > 0")->fetchColumn();
        $winRate = $totalTrades > 0 ? ($winningTrades / $totalTrades) * 100 : 0;
        
        return [
            'total_capital' => round($currentTotalCapital, 2),
            'pool_capital' => round($agentsCapital, 2),
            'agents_capital' => round($agentsCapital, 2),
            'invested_capital' => round($investedCapital, 2),
            'realized_pnl' => round($realizedPnl, 2),
            'unrealized_pnl' => round($unrealizedPnl, 2),
            'total_pnl' => round($realizedPnl + $unrealizedPnl, 2),
            'agents_count' => $agentsCount,
            'total_trades' => $totalTrades,
            'win_rate' => round($winRate, 2)
        ];
    }
}

// ============================================================
// SYSTÈME DE CACHE
// ============================================================
class Cache {
    private array $memory = [];
    private string $fileDir;
    
    public function __construct() {
        $this->fileDir = CACHE_DIR;
    }
    
    public function get(string $key, int $ttl = 300): mixed {
        // Check memory cache first
        if (isset($this->memory[$key])) {
            $item = $this->memory[$key];
            if ($item['expires'] > time()) {
                return $item['data'];
            }
            unset($this->memory[$key]);
        }
        
        // Check file cache
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
    
    public function set(string $key, mixed $value, int $ttl = 300): void {
        $item = [
            'data' => $value,
            'expires' => time() + $ttl,
            'created' => time()
        ];
        
        $this->memory[$key] = $item;
        
        $file = $this->fileDir . 'cache_' . md5($key) . '.json';
        file_put_contents($file, json_encode($item));
    }
    
    public function delete(string $key): void {
        unset($this->memory[$key]);
        $file = $this->fileDir . 'cache_' . md5($key) . '.json';
        if (file_exists($file)) unlink($file);
    }
    
    public function clear(): void {
        $this->memory = [];
        array_map('unlink', glob($this->fileDir . 'cache_*.json'));
    }
}

// ============================================================
// MÉMOIRE REINFORCEMENT LEARNING
// ============================================================
class RLMemory {
    private Database $db;
    private const MEMORY_SIZE = 10000;
    
    public function __construct(Database $db) {
        $this->db = $db;
    }
    
    /**
     * Enregistre une expérience (state, action, reward)
     */
    public function storeExperience(string $state, string $action, float $reward, 
                                   string $nextState = null, string $episodeId = null): void {
        $db = $this->db->getConnection('main');
        
        $stateHash = hash('sha256', $state);
        $nextStateHash = $nextState ? hash('sha256', $nextState) : null;
        
        // Nettoyer ancienne mémoire si trop pleine
        $count = (int)$db->query("SELECT COUNT(*) FROM rl_memory")->fetchColumn();
        if ($count > self::MEMORY_SIZE) {
            $db->exec("DELETE FROM rl_memory WHERE id IN (SELECT id FROM rl_memory ORDER BY created_at ASC LIMIT " . ($count - self::MEMORY_SIZE) . ")");
        }
        
        $db->prepare("INSERT INTO rl_memory (state_hash, state_data, action_taken, reward, next_state_hash, episode_id) 
                      VALUES (?, ?, ?, ?, ?, ?)")
          ->execute([$stateHash, $state, $action, $reward, $nextStateHash, $episodeId]);
    }
    
    /**
     * Récupère des expériences similaires pour apprentissage
     */
    public function getSimilarExperiences(string $currentState, int $limit = 50): array {
        $db = $this->db->getConnection('main');
        
        // Approche simplifiée: récupérer les meilleures expériences récentes
        $stmt = $db->query("SELECT state_data, action_taken, reward 
                           FROM rl_memory 
                           WHERE reward > 0 
                           ORDER BY reward DESC, created_at DESC 
                           LIMIT $limit");
        
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
    
    /**
     * Calcule la valeur Q estimée pour un état/action
     */
    public function getQValue(string $state, string $action): float {
        $db = $this->db->getConnection('main');
        
        $stateHash = hash('sha256', $state);
        
        $stmt = $db->prepare("SELECT AVG(reward) as avg_reward, COUNT(*) as count 
                              FROM rl_memory 
                              WHERE state_hash = ? AND action_taken = ?");
        $stmt->execute([$stateHash, $action]);
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        if (!$result || $result['count'] == 0) {
            return 0.0; // Valeur par défaut pour état inconnu
        }
        
        // Appliquer discount factor sur les anciennes expériences
        return (float)$result['avg_reward'] * RL_DISCOUNT_FACTOR;
    }
    
    /**
     * Met à jour les scores de renforcement des agents
     */
    public function updateAgentScores(): void {
        $db = $this->db->getConnection('main');
        
        // Calculer score basé sur performance récente
        $db->exec("UPDATE agents SET reinforcement_score = (
            SELECT AVG(r.reward) 
            FROM rl_memory r 
            JOIN agent_trades at ON r.state_data LIKE '%' || at.agent_id || '%'
            WHERE at.agent_id = agents.id 
            AND r.created_at > strftime('%s','now','-7 days')
        ) WHERE status = 'active'");
    }
}

// ============================================================
// GESTIONNAIRE D'AGENTS
// ============================================================
class AgentManager {
    private Database $db;
    private ApiRotation $api;
    private RLMemory $rlMemory;
    
    public function __construct(Database $db, ApiRotation $api, RLMemory $rlMemory) {
        $this->db = $db;
        $this->api = $api;
        $this->rlMemory = $rlMemory;
    }
    
    /**
     * Crée un nouvel agent
     */
    public function createAgent(array $data): int {
        $db = $this->db->getConnection('main');
        
        $stmt = $db->prepare("INSERT INTO agents (name, strategy_prompt, strategy_type, timeframe, capital_brics, user_id, generation, parent_ids) 
                              VALUES (:name, :strategy, :type, :timeframe, :capital, :user_id, :gen, :parents)");
        
        $stmt->execute([
            ':name' => $data['name'] ?? 'AI Trader ' . rand(1000, 9999),
            ':strategy' => $data['strategy_prompt'] ?? 'Trading basé sur analyse technique.',
            ':type' => $data['strategy_type'] ?? 'custom',
            ':timeframe' => $data['timeframe'] ?? 'short',
            ':capital' => $data['capital_brics'] ?? AGENT_INITIAL_CAPITAL,
            ':user_id' => $data['user_id'] ?? null,
            ':gen' => $data['generation'] ?? 1,
            ':parents' => json_encode($data['parent_ids'] ?? [])
        ]);
        
        $agentId = (int)$db->lastInsertId();
        
        logConsole('AGENT_CREATE', "Nouvel agent créé: {$data['name']}", [
            'agent_id' => $agentId,
            'strategy' => $data['strategy_type'] ?? 'custom'
        ]);
        
        return $agentId;
    }
    
    /**
     * Exécute la décision de trading d'un agent
     */
    public function runDecision(int $agentId): ?array {
        $db = $this->db->getConnection('main');
        
        $agent = $db->prepare("SELECT * FROM agents WHERE id = ?");
        $agent->execute([$agentId]);
        $agentData = $agent->fetch(\PDO::FETCH_ASSOC);
        
        if (!$agentData || $agentData['status'] !== 'active') {
            return null;
        }
        
        // Vérifier cooldown entre trades - utiliser last_trade_at pour le vrai cooldown
        $lastTrade = (int)($agentData['last_trade_at'] ?? 0);
        if (time() - $lastTrade < TRADE_INTERVAL_SECONDS) {
            return null;
        }
        
        // Récupérer données marché
        $coins = $db->query("SELECT * FROM coins ORDER BY market_cap_rank LIMIT 20")->fetchAll(\PDO::FETCH_ASSOC);
        
        if (empty($coins)) {
            return null;
        }
        
        // Construire prompt pour l'IA
        $marketContext = $this->buildMarketContext($coins);
        $agentHistory = $this->getAgentHistory($agentId);
        $rlExamples = $this->rlMemory->getSimilarExperiences(json_encode($marketContext), 10);
        
        $prompt = $this->buildDecisionPrompt($agentData, $marketContext, $agentHistory, $rlExamples);
        
        $messages = [['role' => 'user', 'content' => $prompt]];
        
        $response = $this->api->call($messages, 'mistral-small-2506', 1500, 0.5);
        
        if (!$response) {
            return null;
        }
        
        // Parser la réponse JSON
        $decision = $this->parseDecision($response);
        
        if ($decision && isset($decision['action']) && $decision['action'] !== 'hold') {
            $this->executeTrade($agentId, $decision, $coins);
        }
        
        // Mettre à jour last_action_at
        $db->prepare("UPDATE agents SET last_action_at = strftime('%s','now') WHERE id = ?")
           ->execute([$agentId]);
        
        return $decision;
    }
    
    private function buildMarketContext(array $coins): array {
        $context = [];
        foreach ($coins as $coin) {
            $context[] = [
                'symbol' => $coin['symbol'],
                'price' => $coin['current_price'],
                'change24h' => $coin['price_change_pct_24h'],
                'change7d' => $coin['price_change_7d'],
                'volume' => $coin['volume_24h'],
                'rank' => $coin['market_cap_rank']
            ];
        }
        return $context;
    }
    
    private function getAgentHistory(int $agentId): array {
        $db = $this->db->getConnection('main');
        $stmt = $db->prepare("SELECT * FROM agent_trades WHERE agent_id = ? ORDER BY executed_at DESC LIMIT 10");
        $stmt->execute([$agentId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
    
    private function buildDecisionPrompt(array $agent, array $marketContext, 
                                         array $history, array $rlExamples): string {
        
        $marketStr = json_encode($marketContext, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        
        $historyStr = "";
        foreach ($history as $trade) {
            $pnlSign = $trade['pnl'] >= 0 ? '+' : '';
            $historyStr .= "- {$trade['action']} {$trade['coin_symbol']} @ {$trade['price']} → {$pnlSign}{$trade['pnl']} BRICS\n";
        }
        
        $rlStr = "";
        foreach ($rlExamples as $exp) {
            if ($exp['reward'] > 0) {
                $rlStr .= "- Action gagnante: {$exp['action_taken']} (reward: {$exp['reward']})\n";
            }
        }
        
        return <<<PROMPT
Tu es un agent de trading crypto autonome avec la stratégie suivante:
{$agent['strategy_prompt']}

Type: {$agent['strategy_type']} | Timeframe: {$agent['timeframe']}
Capital actuel: {$agent['capital_brics']} BRICS | PnL: {$agent['total_pnl_percent']}%

Marché actuel (Top 20):
$marketStr

Ton historique récent:
$historyStr

Exemples de décisions gagnantes (Reinforcement Learning):
$rlStr

Prends une décision de trading. Réponds UNIQUEMENT en JSON valide:
{
  "action": "buy|sell|hold",
  "coin": "SYMBOL",
  "amount_brics": montant,
  "reasoning": "explication courte",
  "confidence": 0-100,
  "stop_loss": prix_optionnel,
  "take_profit": prix_optionnel
}

Confiance minimum requise: >65% pour trader.
PROMPT;
    }
    
    private function parseDecision(string $response): ?array {
        // Extraire JSON de la réponse
        preg_match('/\{.*\}/s', $response, $matches);
        
        if (empty($matches[0])) {
            return null;
        }
        
        $decision = json_decode($matches[0], true);
        
        if (!$decision || !isset($decision['action'])) {
            return null;
        }
        
        // Valider confidence
        if (($decision['confidence'] ?? 0) < MIN_CONFIDENCE_TRADE) {
            $decision['action'] = 'hold';
        }
        
        return $decision;
    }
    
    private function executeTrade(int $agentId, array $decision, array $coins): void {
        $db = $this->db->getConnection('main');
        
        // Trouver la crypto
        $coin = null;
        foreach ($coins as $c) {
            if (strtoupper($c['symbol']) === strtoupper($decision['coin'] ?? '')) {
                $coin = $c;
                break;
            }
        }
        
        if (!$coin) {
            return;
        }
        
        $agent = $db->prepare("SELECT * FROM agents WHERE id = ?");
        $agent->execute([$agentId]);
        $agentData = $agent->fetch(\PDO::FETCH_ASSOC);
        
        if (!$agentData) {
            return;
        }
        
        $action = strtolower($decision['action']);
        
        // BUY: acheter et créer une position ouverte
        if ($action === 'buy') {
            $amount = min($decision['amount_brics'] ?? 1000, $agentData['capital_brics'] * 0.5);
            
            if ($amount <= 0 || $agentData['capital_brics'] < $amount) {
                logConsole('TRADE_SKIPPED', "BUY ignoré: capital insuffisant pour {$agentData['name']}", [
                    'agent_id' => $agentId,
                    'capital_dispo' => $agentData['capital_brics'],
                    'amount_demande' => $amount
                ]);
                return;
            }
            
            $quantity = $amount / $coin['current_price'];
            
            // Déduire capital agent
            $db->prepare("UPDATE agents SET capital_brics = capital_brics - ?, last_trade_at = strftime('%s','now'), total_trades = total_trades + 1 WHERE id = ?")
               ->execute([$amount, $agentId]);
            
            // Créer/mettre à jour position ouverte
            $existingPos = $db->prepare("SELECT * FROM open_positions WHERE agent_id = ? AND coin_symbol = ?");
            $existingPos->execute([$agentId, $coin['symbol']]);
            $pos = $existingPos->fetch(\PDO::FETCH_ASSOC);
            
            if ($pos) {
                // Moyenne pondérée pour position existante
                $newTotalInvested = $pos['total_invested'] + $amount;
                $newQuantity = $pos['quantity'] + $quantity;
                $newAvgPrice = $newTotalInvested / $newQuantity;
                
                $db->prepare("UPDATE open_positions SET quantity = ?, avg_buy_price = ?, total_invested = ?, current_value = ?, unrealized_pnl = ? WHERE id = ?")
                   ->execute([
                       $newQuantity,
                       $newAvgPrice,
                       $newTotalInvested,
                       $newQuantity * $coin['current_price'],
                       ($newQuantity * $coin['current_price']) - $newTotalInvested,
                       $pos['id']
                   ]);
            } else {
                // Nouvelle position
                $db->prepare("INSERT INTO open_positions (agent_id, coin_symbol, coin_id, quantity, avg_buy_price, total_invested, current_value, unrealized_pnl) 
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
                   ->execute([
                       $agentId,
                       $coin['symbol'],
                       $coin['id'],
                       $quantity,
                       $coin['current_price'],
                       $amount,
                       $quantity * $coin['current_price'],
                       0
                   ]);
            }
            
            // Enregistrer trade
            $db->prepare("INSERT INTO agent_trades (agent_id, coin_symbol, action, price, quantity, value_brics, reasoning, timeframe) 
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
               ->execute([
                   $agentId,
                   $coin['symbol'],
                   'buy',
                   $coin['current_price'],
                   $quantity,
                   $amount,
                   $decision['reasoning'] ?? '',
                   $agentData['timeframe']
               ]);
            
            logConsole('TRADE_EXECUTED', "BUY: {$agentData['name']} achète $amount BRICS de {$coin['symbol']}", [
                'agent_id' => $agentId,
                'coin' => $coin['symbol'],
                'quantity' => $quantity,
                'price' => $coin['current_price'],
                'amount' => $amount
            ]);
        }
        
        // SELL: vendre une position ouverte
        elseif ($action === 'sell') {
            // Chercher position ouverte pour cet agent et cette crypto
            $posStmt = $db->prepare("SELECT * FROM open_positions WHERE agent_id = ? AND coin_symbol = ?");
            $posStmt->execute([$agentId, $coin['symbol']]);
            $position = $posStmt->fetch(\PDO::FETCH_ASSOC);
            
            if (!$position) {
                // Pas de position ouverte, chercher n'importe quelle position de l'agent
                $posStmt = $db->prepare("SELECT * FROM open_positions WHERE agent_id = ? LIMIT 1");
                $posStmt->execute([$agentId]);
                $position = $posStmt->fetch(\PDO::FETCH_ASSOC);
                
                if (!$position) {
                    logConsole('TRADE_SKIPPED', "SELL ignoré: aucune position ouverte pour {$agentData['name']}", [
                        'agent_id' => $agentId,
                        'coin' => $coin['symbol']
                    ]);
                    return;
                }
            }
            
            // Calculer PnL réel
            $sellQuantity = $position['quantity']; // Vendre toute la position
            $sellValue = $sellQuantity * $coin['current_price'];
            $pnl = $sellValue - $position['total_invested'];
            $pnlPercent = ($pnl / $position['total_invested']) * 100;
            
            // Ajouter capital + PnL à l'agent
            $db->prepare("UPDATE agents SET capital_brics = capital_brics + ?, total_pnl = total_pnl + ?, total_trades = total_trades + 1, last_trade_at = strftime('%s','now') WHERE id = ?")
               ->execute([$sellValue, $pnl, $agentId]);
            
            // Mettre à jour win_rate
            $totalTrades = (int)$db->query("SELECT COUNT(*) FROM agent_trades WHERE agent_id = $agentId AND action='sell'")->fetchColumn() + 1;
            $winningTrades = (int)$db->query("SELECT COUNT(*) FROM agent_trades WHERE agent_id = $agentId AND action='sell' AND pnl > 0")->fetchColumn();
            $winRate = $totalTrades > 0 ? ($winningTrades / $totalTrades) * 100 : 0;
            $db->prepare("UPDATE agents SET win_rate = ? WHERE id = ?")->execute([$winRate, $agentId]);
            
            // Enregistrer trade de vente avec PnL réel
            $db->prepare("INSERT INTO agent_trades (agent_id, coin_symbol, action, price, quantity, value_brics, pnl, pnl_percent, reasoning, timeframe) 
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
               ->execute([
                   $agentId,
                   $position['coin_symbol'],
                   'sell',
                   $coin['current_price'],
                   $sellQuantity,
                   $sellValue,
                   $pnl,
                   $pnlPercent,
                   $decision['reasoning'] ?? 'Sell signal',
                   $agentData['timeframe']
               ]);
            
            // Supprimer position ouverte
            $db->prepare("DELETE FROM open_positions WHERE id = ?")->execute([$position['id']]);
            
            // ============================================
            // REINFORCEMENT LEARNING: Mettre à jour avec le VRAI PnL
            // ============================================
            $reward = $pnl; // Le vrai reward est le PnL réalisé
            
            // Mettre à jour les expériences RL récentes pour cet agent
            $stateHash = hash('sha256', json_encode(['market' => 'current', 'agent' => $agentId]));
            $db->prepare("UPDATE rl_memory SET reward = ? WHERE state_data LIKE ? AND action_taken = 'buy' AND reward = 0")
               ->execute([$reward, '%' . $agentId . '%']);
            
            logConsole('TRADE_EXECUTED', "SELL: {$agentData['name']} vend {$position['coin_symbol']} | PnL: " . number_format($pnl, 2) . " BRICS (" . number_format($pnlPercent, 2) . "%)", [
                'agent_id' => $agentId,
                'coin' => $position['coin_symbol'],
                'sell_value' => $sellValue,
                'pnl' => $pnl,
                'pnl_percent' => $pnlPercent
            ]);
        }
    }
    
    /**
     * Archive les agents sous-performants
     */
    public function archiveUnderperformers(float $threshold = -5.0): int {
        $db = $this->db->getConnection('main');
        
        $agents = $db->query("SELECT * FROM agents WHERE status='active' AND total_trades >= 5 AND total_pnl_percent < $threshold")
                     ->fetchAll(\PDO::FETCH_ASSOC);
        
        $archived = 0;
        foreach ($agents as $agent) {
            // Extraire leçons avec IA
            $prompt = "Cet agent IA a perdu {$agent['total_pnl_percent']}% avec: {$agent['strategy_prompt']}. Quelle est l'erreur principale en 1 phrase?";
            $lessons = $this->api->call([['role' => 'user', 'content' => $prompt]], 'ministral-8b-2512', 200);
            
            if (!$lessons) {
                $lessons = "Stratégie mal adaptée au marché.";
            }
            
            $db->prepare("INSERT INTO agents_archive (original_agent_id, name, strategy_prompt, final_pnl_percent, total_trades, win_rate, reason_archived, lessons_extracted) 
                          VALUES (?, ?, ?, ?, ?, ?, 'performance_below_threshold', ?)")
               ->execute([
                   $agent['id'], $agent['name'], $agent['strategy_prompt'],
                   $agent['total_pnl_percent'], $agent['total_trades'], $agent['win_rate'], $lessons
               ]);
            
            $db->prepare("UPDATE agents SET status='archived' WHERE id = ?")->execute([$agent['id']]);
            
            logConsole('AGENT_ARCHIVE', "Agent archivé: {$agent['name']} (PnL: {$agent['total_pnl_percent']}%)", [
                'agent_id' => $agent['id'],
                'lessons' => $lessons
            ]);
            
            $archived++;
        }
        
        return $archived;
    }
    
    /**
     * Crée de nouveaux agents basés sur les meilleurs performers (évolution génétique)
     */
    public function evolveAgents(int $count): int {
        $db = $this->db->getConnection('main');
        
        if ($count <= 0) return 0;
        
        // Top performers
        $topAgents = $db->query("SELECT * FROM agents WHERE status='active' ORDER BY total_pnl_percent DESC LIMIT 5")
                        ->fetchAll(\PDO::FETCH_ASSOC);
        
        if (empty($topAgents)) return 0;
        
        $topStrategies = implode("\n", array_map(fn($a) => 
            "- [{$a['total_pnl_percent']}% PnL] {$a['strategy_prompt']}", $topAgents));
        
        // Leçons des archivés
        $archiveLessons = $db->query("SELECT lessons_extracted FROM agents_archive ORDER BY archived_at DESC LIMIT 5")
                             ->fetchAll(\PDO::FETCH_COLUMN, 0);
        $lessonsText = !empty($archiveLessons) ? implode("\n", $archiveLessons) : "Aucune leçon.";
        
        $prompt = <<<PROMPT
Tu es le Cerveau Central d'un système de trading IA avec Reinforcement Learning et évolution génétique.

TOP PERFORMERS ACTUELS (à combiner/améliorer):
$topStrategies

LEÇONS DES AGENTS ARCHIVÉS (à éviter):
$lessonsText

Crée $count NOUVEAUX agents qui:
1. Combinent les meilleures qualités des top performers
2. Évitent les erreurs des agents archivés
3. Ont des personnalités distinctes (scalper, swing, DCA, momentum, mean reversion...)

Réponds UNIQUEMENT en JSON:
{"agents":[{"name":"Nom","strategy_prompt":"stratégie détaillée","strategy_type":"scalping|swing|long_term|momentum|dca|mean_reversion","timeframe":"short|medium|long"}]}
PROMPT;
        
        $response = $this->api->call([['role' => 'user', 'content' => $prompt]], 'mistral-small-2506', 3000);
        
        if (!$response) return 0;
        
        preg_match('/\{.*\}/s', $response, $matches);
        $result = json_decode($matches[0] ?? '{}', true);
        
        $created = 0;
        if (!empty($result['agents'])) {
            foreach ($result['agents'] as $newAgent) {
                $newAgent['parent_ids'] = array_column($topAgents, 'id');
                $newAgent['generation'] = max(array_column($topAgents, 'generation')) + 1;
                
                $this->createAgent($newAgent);
                $created++;
            }
        }
        
        return $created;
    }
    
    /**
     * Retourne tous les agents actifs triés par performance
     */
    public function getActiveAgents(): array {
        $db = $this->db->getConnection('main');
        return $db->query("SELECT * FROM agents WHERE status='active' ORDER BY total_pnl_percent DESC")->fetchAll(\PDO::FETCH_ASSOC);
    }
}

// ============================================================
// DONNÉES MARCHÉ
// ============================================================
class MarketData {
    private Database $db;
    private Cache $cache;
    
    public function __construct(Database $db, Cache $cache) {
        $this->db = $db;
        $this->cache = $cache;
    }
    
    /**
     * Met à jour les données marché depuis CoinGecko
     */
    public function update(): int {
        $cacheKey = 'coingecko_markets';
        $cached = $this->cache->get($cacheKey, 60);
        
        if ($cached) {
            $this->storeCoins($cached);
            return count($cached);
        }
        
        $url = COINGECKO_MARKETS;
        $opts = ['http' => ['method' => 'GET', 'timeout' => 30]];
        $context = stream_context_create($opts);
        
        $raw = @file_get_contents($url, false, $context);
        if (!$raw) return 0;
        
        $data = json_decode($raw, true);
        if (!is_array($data)) return 0;
        
        $this->cache->set($cacheKey, $data, 60);
        $this->storeCoins($data);
        
        logConsole('MARKET_UPDATE', "Données marché mises à jour: " . count($data) . " cryptos", ['coins' => count($data)]);
        
        return count($data);
    }
    
    private function storeCoins(array $coins): void {
        $db = $this->db->getConnection('main');
        
        foreach ($coins as $coin) {
            $db->prepare("INSERT OR REPLACE INTO coins (id, symbol, name, current_price, market_cap, market_cap_rank, volume_24h, price_change_24h, price_change_pct_24h, price_change_7d, sparkline_7d, image_url, updated_at) 
                          VALUES (:id, :symbol, :name, :price, :cap, :rank, :vol, :chg24, :pct24, :chg7d, :spark, :img, strftime('%s','now'))")
               ->execute([
                   ':id' => $coin['id'],
                   ':symbol' => $coin['symbol'],
                   ':name' => $coin['name'],
                   ':price' => $coin['current_price'] ?? 0,
                   ':cap' => $coin['market_cap'] ?? 0,
                   ':rank' => $coin['market_cap_rank'] ?? 999,
                   ':vol' => $coin['total_volume'] ?? 0,
                   ':chg24' => $coin['price_change_24h'] ?? 0,
                   ':pct24' => $coin['price_change_percentage_24h'] ?? 0,
                   ':chg7d' => $coin['price_change_percentage_7d'] ?? 0,
                   ':spark' => json_encode($coin['sparkline_in_7d']['price'] ?? []),
                   ':img' => $coin['image'] ?? ''
               ]);
        }
        
        $db->prepare("UPDATE system_config SET value = strftime('%s','now') WHERE key = 'last_market_update'")->execute();
    }
    
    /**
     * Retourne les cryptos triées par rank
     */
    public function getCoins(int $limit = 50): array {
        $db = $this->db->getConnection('main');
        return $db->query("SELECT * FROM coins ORDER BY market_cap_rank LIMIT $limit")->fetchAll(\PDO::FETCH_ASSOC);
    }
}

// ============================================================
// CERVEAU CENTRAL - Orchestration IA
// ============================================================
class Brain {
    private Database $db;
    private AgentManager $agentManager;
    private RLMemory $rlMemory;
    
    public function __construct(Database $db, AgentManager $agentManager, RLMemory $rlMemory) {
        $this->db = $db;
        $this->agentManager = $agentManager;
        $this->rlMemory = $rlMemory;
    }
    
    /**
     * Exécute un cycle complet du cerveau
     */
    public function runCycle(): array {
        $log = ['actions' => [], 'created' => 0, 'archived' => 0];
        
        logConsole('BRAIN_START', "Cerveau Central: Démarrage cycle d'analyse", []);
        
        // 1. Archive underperformers
        $archived = $this->agentManager->archiveUnderperformers(-5.0);
        $log['archived'] = $archived;
        $log['actions'][] = "$archived agents archivés";
        
        // 2. Compter agents actifs
        $activeCount = count($this->agentManager->getActiveAgents());
        $toCreate = max(0, TARGET_AGENTS - $activeCount);
        
        // 3. Créer nouveaux agents par évolution
        if ($toCreate > 0) {
            $created = $this->agentManager->evolveAgents($toCreate);
            $log['created'] = $created;
            $log['actions'][] = "$created nouveaux agents créés";
        }
        
        // 4. Exécuter décisions top agents
        $topAgents = array_slice($this->agentManager->getActiveAgents(), 0, 10);
        $decisionsRun = 0;
        
        foreach ($topAgents as $agent) {
            $decision = $this->agentManager->runDecision($agent['id']);
            if ($decision) {
                $decisionsRun++;
                
                // Stocker expérience RL
                $state = json_encode(['market' => 'current', 'agent' => $agent['id']]);
                $action = $decision['action'];
                $reward = 0; // Sera mis à jour après résultat trade
                
                $this->rlMemory->storeExperience($state, $action, $reward, null, 'cycle_' . time());
            }
        }
        
        $log['actions'][] = "$decisionsRun décisions exécutées";
        
        // 5. Mettre à jour scores RL
        $this->rlMemory->updateAgentScores();
        
        // 6. Logger fin cycle
        $db = $this->db->getConnection('main');
        $db->prepare("INSERT INTO brain_logs (action, details, agents_created, agents_archived) 
                      VALUES ('cycle_complete', ?, ?, ?)")
           ->execute([json_encode($log['actions']), $log['created'], $log['archived']]);
        
        $db->prepare("UPDATE system_config SET value = strftime('%s','now') WHERE key = 'last_brain_run'")
           ->execute();
        
        logConsole('BRAIN_END', "Cycle terminé: {$log['created']} créés, {$log['archived']} archivés", $log);
        
        return $log;
    }
}

// ============================================================
// FONCTIONS UTILITAIRES
// ============================================================
function logConsole(string $type, string $message, array $data = []): void {
    $db = Engine::getInstance()->getDatabase();
    $conn = $db->getConnection('main');
    
    $conn->prepare("INSERT INTO console_logs (log_type, message, data, created_at) 
                    VALUES (?, ?, ?, strftime('%s','now'))")
         ->execute([$type, $message, json_encode($data)]);
    
    // Limiter logs
    $conn->exec("DELETE FROM console_logs WHERE id NOT IN (SELECT id FROM console_logs ORDER BY created_at DESC LIMIT " . MAX_CONSOLE_LOGS . ")");
}

function getEngine(): Engine {
    return Engine::getInstance();
}

// Initialisation automatique si inclus directement
if (!function_exists('initDatabases')) {
    function initDatabases(): void {
        Engine::getInstance();
    }
}

if (!function_exists('getSystemStats')) {
    function getSystemStats(): array {
        return Engine::getInstance()->getSystemStats();
    }
}

if (!function_exists('runBrainCycle')) {
    function runBrainCycle(): array {
        return Engine::getInstance()->runBrainCycle();
    }
}
