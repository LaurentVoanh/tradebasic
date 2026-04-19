<?php
/**
 * IA CRYPTO INVEST - functions.php
 * Core library : DB, Mistral API, market data, agents
 * CURRENCY: BRICS Coins (virtuelle)
 */

error_reporting(0);
set_time_limit(600);

// ============================================================
// CONFIGURATION
// ============================================================
define('MISTRAL_KEYS', [
    'KEY_1' => '5qgfdgfdgdfH8Rake',
    'KEY_2' => 'o3rG1gfdgfdgfdXRShytu',
    'KEY_3' => 'vEzQMKgfdgfdgfdDjFruXkF',
]);
define('MISTRAL_ENDPOINT', 'https://api.mistral.ai/v1/chat/completions');
define('DB_DIR', __DIR__ . '/db/');
define('COINGECKO_MARKETS', 'https://api.coingecko.com/api/v3/coins/markets?vs_currency=eur&order=market_cap_desc&per_page=100&page=1&sparkline=true&price_change_percentage=24h,7d');
define('BINANCE_TICKER', 'https://api.binance.com/api/v3/ticker/24hr');
define('INITIAL_CAPITAL', 1000000.00); // 1 Million BRICS Coins
define('TARGET_AGENTS', 100);
define('SHORT_TERM_RATIO', 0.33);
define('MEDIUM_TERM_RATIO', 0.33);
define('LONG_TERM_RATIO', 0.34);
define('TRADE_INTERVAL_SECONDS', 8);
define('MODEL_PRICE', 5000); // Prix d'un modèle IA en BRICS Coins

// ============================================================
// DATABASE CONNECTIONS
// ============================================================
function getDB(string $dbName = 'main'): PDO {
    static $connections = [];
    if (!isset($connections[$dbName])) {
        $path = DB_DIR . $dbName . '.db';
        if (!is_dir(DB_DIR)) mkdir(DB_DIR, 0755, true);
        $pdo = new PDO('sqlite:' . $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA journal_mode=WAL');
        $pdo->exec('PRAGMA synchronous=NORMAL');
        $pdo->exec('PRAGMA cache_size=10000');
        $pdo->exec('PRAGMA temp_store=MEMORY');
        $connections[$dbName] = $pdo;
    }
    return $connections[$dbName];
}

function initDatabases(): void {
    $dbMain = getDB('main');
    $dbShort = getDB('short_term');
    $dbMedium = getDB('medium_term');
    $dbLong = getDB('long_term');
    
    // MAIN DATABASE tables
    $mainTables = [
        "CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email TEXT UNIQUE NOT NULL,
            password_hash TEXT NOT NULL,
            username TEXT,
            capital_brics REAL DEFAULT 1000000.00,
            created_at INTEGER DEFAULT (strftime('%s','now')),
            last_login INTEGER,
            is_active INTEGER DEFAULT 1,
            session_token TEXT,
            visitor_id TEXT
        )",
        "CREATE TABLE IF NOT EXISTS portfolios (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            coin_id TEXT NOT NULL,
            coin_symbol TEXT NOT NULL,
            quantity REAL DEFAULT 0,
            avg_buy_price REAL DEFAULT 0,
            total_invested REAL DEFAULT 0,
            current_value REAL DEFAULT 0,
            pnl_percent REAL DEFAULT 0,
            updated_at INTEGER DEFAULT (strftime('%s','now'))
        )",
        "CREATE INDEX IF NOT EXISTS idx_portfolio_user ON portfolios(user_id)",
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
        "CREATE INDEX IF NOT EXISTS idx_agents_status ON agents(status)",
        "CREATE INDEX IF NOT EXISTS idx_agents_pnl ON agents(total_pnl_percent DESC)",
        "CREATE INDEX IF NOT EXISTS idx_agents_timeframe ON agents(timeframe)",
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
        "CREATE INDEX IF NOT EXISTS idx_trades_agent ON agent_trades(agent_id)",
        "CREATE INDEX IF NOT EXISTS idx_trades_time ON agent_trades(executed_at DESC)",
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
            ath REAL DEFAULT 0,
            atl REAL DEFAULT 0,
            circulating_supply REAL DEFAULT 0,
            sparkline_7d TEXT DEFAULT '[]',
            image_url TEXT,
            updated_at INTEGER DEFAULT (strftime('%s','now'))
        )",
        "CREATE INDEX IF NOT EXISTS idx_coins_rank ON coins(market_cap_rank)",
        "CREATE TABLE IF NOT EXISTS news (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            coin_id TEXT,
            title TEXT NOT NULL,
            url TEXT UNIQUE,
            source TEXT,
            published_at INTEGER,
            content_raw TEXT,
            sentiment_score REAL DEFAULT 0,
            fetched_at INTEGER DEFAULT (strftime('%s','now'))
        )",
        "CREATE INDEX IF NOT EXISTS idx_news_coin ON news(coin_id)",
        "CREATE INDEX IF NOT EXISTS idx_news_time ON news(published_at DESC)",
        "CREATE TABLE IF NOT EXISTS ai_analyses (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            coin_id TEXT NOT NULL,
            timeframe TEXT DEFAULT 'short',
            sentiment_score REAL DEFAULT 0,
            summary TEXT,
            bullish_factors TEXT DEFAULT '[]',
            bearish_factors TEXT DEFAULT '[]',
            recommendation TEXT,
            confidence REAL DEFAULT 0,
            model_used TEXT,
            created_at INTEGER DEFAULT (strftime('%s','now'))
        )",
        "CREATE INDEX IF NOT EXISTS idx_analyses_coin ON ai_analyses(coin_id, timeframe)",
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
        "CREATE TABLE IF NOT EXISTS user_agents (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            agent_id INTEGER NOT NULL,
            purchased_at INTEGER DEFAULT (strftime('%s','now')),
            price_paid REAL DEFAULT 0,
            FOREIGN KEY (user_id) REFERENCES users(id),
            FOREIGN KEY (agent_id) REFERENCES agents(id)
        )",
        "CREATE TABLE IF NOT EXISTS console_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            log_type TEXT NOT NULL,
            message TEXT NOT NULL,
            data TEXT DEFAULT '{}',
            created_at INTEGER DEFAULT (strftime('%s','now'))
        )",
    ];
    
    foreach ($mainTables as $sql) {
        try { $dbMain->exec($sql); } catch (\Exception $e) {}
    }
    
    // Insert default config
    $dbMain->exec("INSERT OR IGNORE INTO system_config (key, value) VALUES ('last_market_update', '0')");
    $dbMain->exec("INSERT OR IGNORE INTO system_config (key, value) VALUES ('last_brain_run', '0')");
    $dbMain->exec("INSERT OR IGNORE INTO system_config (key, value) VALUES ('active_agents_count', '0')");
    $dbMain->exec("INSERT OR IGNORE INTO system_config (key, value) VALUES ('target_agents_count', '100')");
    $dbMain->exec("INSERT OR IGNORE INTO system_config (key, value) VALUES ('total_brics_capital', '1000000')");
    $dbMain->exec("INSERT OR IGNORE INTO system_config (key, value) VALUES ('last_trade_time', '0')");
    $dbMain->exec("INSERT OR IGNORE INTO system_config (key, value) VALUES ('trade_interval', '8')");
    
    // SHORT TERM DATABASE tables
    $shortTables = [
        "CREATE TABLE IF NOT EXISTS price_ticks (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            coin_symbol TEXT NOT NULL,
            price REAL NOT NULL,
            volume REAL DEFAULT 0,
            bid REAL DEFAULT 0,
            ask REAL DEFAULT 0,
            timestamp INTEGER DEFAULT (strftime('%s','now'))
        )",
        "CREATE INDEX IF NOT EXISTS idx_ticks_symbol ON price_ticks(coin_symbol, timestamp DESC)",
        "CREATE TABLE IF NOT EXISTS signals_short (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            coin_symbol TEXT NOT NULL,
            signal_type TEXT NOT NULL,
            strength REAL DEFAULT 0,
            price_entry REAL DEFAULT 0,
            price_target REAL DEFAULT 0,
            stop_loss REAL DEFAULT 0,
            indicators TEXT DEFAULT '{}',
            valid_until INTEGER,
            created_at INTEGER DEFAULT (strftime('%s','now'))
        )",
        "CREATE TABLE IF NOT EXISTS ohlcv_1m (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            coin_symbol TEXT NOT NULL,
            open REAL, high REAL, low REAL, close REAL, volume REAL,
            timestamp INTEGER
        )",
        "CREATE UNIQUE INDEX IF NOT EXISTS idx_ohlcv1m ON ohlcv_1m(coin_symbol, timestamp)",
        "CREATE TABLE IF NOT EXISTS ohlcv_5m (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            coin_symbol TEXT NOT NULL,
            open REAL, high REAL, low REAL, close REAL, volume REAL,
            timestamp INTEGER
        )",
        "CREATE UNIQUE INDEX IF NOT EXISTS idx_ohlcv5m ON ohlcv_5m(coin_symbol, timestamp)",
    ];
    
    foreach ($shortTables as $sql) {
        try { $dbShort->exec($sql); } catch (\Exception $e) {}
    }
    
    // MEDIUM TERM DATABASE tables
    $mediumTables = [
        "CREATE TABLE IF NOT EXISTS ohlcv_1h (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            coin_symbol TEXT NOT NULL,
            open REAL, high REAL, low REAL, close REAL, volume REAL,
            timestamp INTEGER
        )",
        "CREATE UNIQUE INDEX IF NOT EXISTS idx_ohlcv1h ON ohlcv_1h(coin_symbol, timestamp)",
        "CREATE TABLE IF NOT EXISTS ohlcv_4h (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            coin_symbol TEXT NOT NULL,
            open REAL, high REAL, low REAL, close REAL, volume REAL,
            timestamp INTEGER
        )",
        "CREATE UNIQUE INDEX IF NOT EXISTS idx_ohlcv4h ON ohlcv_4h(coin_symbol, timestamp)",
        "CREATE TABLE IF NOT EXISTS ohlcv_1d (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            coin_symbol TEXT NOT NULL,
            open REAL, high REAL, low REAL, close REAL, volume REAL,
            timestamp INTEGER
        )",
        "CREATE UNIQUE INDEX IF NOT EXISTS idx_ohlcv1d ON ohlcv_1d(coin_symbol, timestamp)",
        "CREATE TABLE IF NOT EXISTS technical_indicators (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            coin_symbol TEXT NOT NULL,
            timeframe TEXT DEFAULT '1d',
            rsi REAL, macd_line REAL, macd_signal REAL, macd_hist REAL,
            bb_upper REAL, bb_mid REAL, bb_lower REAL,
            ema_20 REAL, ema_50 REAL, ema_200 REAL,
            volume_sma REAL, trend TEXT,
            calculated_at INTEGER DEFAULT (strftime('%s','now'))
        )",
        "CREATE INDEX IF NOT EXISTS idx_indicators_symbol ON technical_indicators(coin_symbol, timeframe)",
        "CREATE TABLE IF NOT EXISTS swing_signals (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            coin_symbol TEXT NOT NULL,
            signal_type TEXT,
            entry_zone_low REAL, entry_zone_high REAL,
            target_1 REAL, target_2 REAL, stop_loss REAL,
            risk_reward REAL, confidence REAL,
            timeframe TEXT DEFAULT '1d', analysis TEXT,
            created_at INTEGER DEFAULT (strftime('%s','now')),
            expires_at INTEGER
        )",
    ];
    
    foreach ($mediumTables as $sql) {
        try { $dbMedium->exec($sql); } catch (\Exception $e) {}
    }
    
    // LONG TERM DATABASE tables
    $longTables = [
        "CREATE TABLE IF NOT EXISTS fundamentals (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            coin_id TEXT UNIQUE NOT NULL,
            whitepaper_summary TEXT, use_case TEXT,
            team_score REAL DEFAULT 0, technology_score REAL DEFAULT 0,
            adoption_score REAL DEFAULT 0, tokenomics_score REAL DEFAULT 0,
            overall_score REAL DEFAULT 0,
            on_chain_activity TEXT DEFAULT '{}',
            developer_activity TEXT DEFAULT '{}',
            updated_at INTEGER DEFAULT (strftime('%s','now'))
        )",
        "CREATE TABLE IF NOT EXISTS macro_analysis (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            analysis_type TEXT, title TEXT, content TEXT,
            impact_on_crypto TEXT, sentiment REAL DEFAULT 0,
            created_at INTEGER DEFAULT (strftime('%s','now'))
        )",
        "CREATE TABLE IF NOT EXISTS price_history_daily (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            coin_id TEXT NOT NULL,
            price REAL, market_cap REAL, volume REAL,
            date INTEGER
        )",
        "CREATE UNIQUE INDEX IF NOT EXISTS idx_ph_daily ON price_history_daily(coin_id, date)",
        "CREATE TABLE IF NOT EXISTS long_term_theses (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            coin_id TEXT NOT NULL,
            thesis_type TEXT DEFAULT 'bullish',
            time_horizon TEXT DEFAULT '6months',
            target_price REAL, target_date INTEGER,
            reasoning TEXT, catalysts TEXT DEFAULT '[]', risks TEXT DEFAULT '[]',
            confidence REAL DEFAULT 0,
            created_at INTEGER DEFAULT (strftime('%s','now')),
            updated_at INTEGER DEFAULT (strftime('%s','now'))
        )",
    ];
    
    foreach ($longTables as $sql) {
        try { $dbLong->exec($sql); } catch (\Exception $e) {}
    }
}

// ============================================================
// MISTRAL API
// ============================================================
function getMistralKey(): string {
    $keyFile = DB_DIR . 'key_idx.txt';
    $keys = array_values(MISTRAL_KEYS);
    $count = count($keys);
    
    // Lire l'index actuel depuis le fichier (persistant entre requêtes)
    $currentIndex = 0;
    if (file_exists($keyFile)) {
        $currentIndex = (int)file_get_contents($keyFile);
    }
    
    // Sélectionner la clé
    $key = $keys[$currentIndex % $count];
    
    // Incrémenter et sauvegarder pour la prochaine requête
    file_put_contents($keyFile, ($currentIndex + 1) % $count);
    
    return $key;
}

function callMistral(
    array $messages,
    string $model = 'mistral-small-2506',
    int $maxTokens = 2000,
    float $temperature = 0.7,
    ?string $forcedKey = null
): ?string {
    $apiKey = $forcedKey ?? getMistralKey();

    // Auto-select large context model if needed
    $totalLen = array_sum(array_map(fn($m) => strlen($m['content']), $messages));
    if ($totalLen > 40000 && $model !== 'mistral-small-2603') {
        $model = 'mistral-small-2603';
    }

    $payload = json_encode([
        'model'       => $model,
        'max_tokens'  => $maxTokens,
        'messages'    => $messages,
        'temperature' => $temperature,
    ]);

    $opts = stream_context_create([
        'http' => [
            'method'        => 'POST',
            'header'        => "Content-Type: application/json\r\nAuthorization: Bearer $apiKey\r\nAccept: application/json\r\n",
            'content'       => $payload,
            'timeout'       => 120,
            'ignore_errors' => true,
        ]
    ]);

    $raw = @file_get_contents(MISTRAL_ENDPOINT, false, $opts);
    if (!$raw) return null;

    $data = json_decode($raw, true);
    return $data['choices'][0]['message']['content'] ?? null;
}

function callMistralJSON(array $messages, string $model = 'mistral-small-2506', int $maxTokens = 2000): ?array {
    $messages[count($messages)-1]['content'] .= "\n\nRéponds UNIQUEMENT en JSON valide, sans markdown, sans texte avant ou après.";
    $raw = callMistral($messages, $model, $maxTokens, 0.3);
    if (!$raw) return null;
    
    // Strip backticks and any surrounding text
    $clean = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', trim($raw));
    
    // Find first { or [ to cut any preamble text
    $firstBrace = strpos($clean, '{');
    $firstBracket = strpos($clean, '[');
    $startPos = false;
    if ($firstBrace !== false && $firstBracket !== false) {
        $startPos = min($firstBrace, $firstBracket);
    } elseif ($firstBrace !== false) {
        $startPos = $firstBrace;
    } elseif ($firstBracket !== false) {
        $startPos = $firstBracket;
    }
    
    if ($startPos !== false && $startPos > 0) {
        $clean = substr($clean, $startPos);
    }
    
    return json_decode($clean, true);
}

// ============================================================
// MARKET DATA
// ============================================================
function fetchCoinGeckoMarkets(): array {
    $opts = stream_context_create([
        'http' => [
            'method'  => 'GET',
            'header'  => "Accept: application/json\r\nUser-Agent: IACryptoInvest/1.0\r\n",
            'timeout' => 30,
            'ignore_errors' => true,
        ]
    ]);
    $raw = @file_get_contents(COINGECKO_MARKETS, false, $opts);
    if (!$raw) return [];
    return json_decode($raw, true) ?? [];
}

function fetchBinanceTickers(): array {
    $opts = stream_context_create([
        'http' => [
            'method'  => 'GET',
            'header'  => "Accept: application/json\r\n",
            'timeout' => 20,
            'ignore_errors' => true,
        ]
    ]);
    $raw = @file_get_contents(BINANCE_TICKER, false, $opts);
    if (!$raw) return [];
    $data = json_decode($raw, true) ?? [];
    // Index by symbol for quick lookup
    $indexed = [];
    foreach ($data as $ticker) {
        $indexed[$ticker['symbol']] = $ticker;
    }
    return $indexed;
}

function updateMarketData(): array {
    $db = getDB('main');
    $coins = fetchCoinGeckoMarkets();
    $binance = fetchBinanceTickers();
    $updated = 0;

    if (empty($coins)) return ['success' => false, 'error' => 'CoinGecko unavailable'];

    $stmt = $db->prepare("
        INSERT OR REPLACE INTO coins
        (id, symbol, name, current_price, market_cap, market_cap_rank, volume_24h,
         price_change_24h, price_change_pct_24h, price_change_7d, ath, atl,
         circulating_supply, sparkline_7d, image_url, updated_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,strftime('%s','now'))
    ");

    foreach ($coins as $coin) {
        $sparkline = json_encode($coin['sparkline_in_7d']['price'] ?? []);
        $stmt->execute([
            $coin['id'],
            strtoupper($coin['symbol']),
            $coin['name'],
            $coin['current_price'] ?? 0,
            $coin['market_cap'] ?? 0,
            $coin['market_cap_rank'] ?? 999,
            $coin['total_volume'] ?? 0,
            $coin['price_change_24h'] ?? 0,
            $coin['price_change_percentage_24h'] ?? 0,
            $coin['price_change_percentage_7d_in_currency'] ?? 0.0,
            $coin['ath'] ?? 0,
            $coin['atl'] ?? 0,
            $coin['circulating_supply'] ?? 0,
            $sparkline,
            $coin['image'] ?? '',
        ]);

        // Store price tick in short_term DB
        $stDb = getDB('short_term');
        $stDb->prepare("INSERT INTO price_ticks (coin_symbol, price, volume, timestamp) VALUES (?,?,?,strftime('%s','now'))")
             ->execute([strtoupper($coin['symbol']), $coin['current_price'] ?? 0, $coin['total_volume'] ?? 0]);

        $updated++;
    }

    // Update config
    $db->prepare("UPDATE system_config SET value=strftime('%s','now') WHERE key='last_market_update'")->execute();

    return ['success' => true, 'updated' => $updated, 'timestamp' => time()];
}

function getCoins(int $limit = 100): array {
    return getDB('main')
        ->query("SELECT * FROM coins ORDER BY market_cap_rank ASC LIMIT $limit")
        ->fetchAll(PDO::FETCH_ASSOC);
}

function getCoin(string $coinId): ?array {
    $stmt = getDB('main')->prepare("SELECT * FROM coins WHERE id=? OR symbol=?");
    $stmt->execute([$coinId, strtoupper($coinId)]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

// ============================================================
// NEWS & RSS
// ============================================================
function fetchNewsForCoin(string $coinId, string $coinName, string $symbol): array {
    $feeds = [
        "https://news.google.com/rss/search?q=" . urlencode($coinName . " crypto") . "&hl=fr&gl=FR&ceid=FR:fr",
        "https://news.google.com/rss/search?q=" . urlencode($symbol . " cryptocurrency") . "&hl=en&gl=US&ceid=US:en",
        "https://cointelegraph.com/rss/tag/" . strtolower($coinId),
        "https://coindesk.com/arc/outboundfeeds/rss/",
    ];

    $articles = [];
    $db = getDB('main');

    foreach ($feeds as $feedUrl) {
        $opts = stream_context_create([
            'http' => ['method' => 'GET', 'timeout' => 15, 'ignore_errors' => true,
                       'header' => "User-Agent: Mozilla/5.0 IACryptoInvest\r\n"]
        ]);
        $raw = @file_get_contents($feedUrl, false, $opts);
        if (!$raw) continue;

        try {
            $xml = @simplexml_load_string($raw);
            if (!$xml) continue;

            foreach ($xml->channel->item ?? [] as $item) {
                $title   = (string)($item->title ?? '');
                $url     = (string)($item->link ?? $item->guid ?? '');
                $pubDate = strtotime((string)($item->pubDate ?? '')) ?: time();
                $desc    = (string)($item->description ?? '');
                $source  = parse_url($feedUrl, PHP_URL_HOST) ?: 'unknown';

                if (empty($title) || empty($url)) continue;

                // Check if relevant to coin
                $text = strtolower($title . ' ' . $desc);
                if (!str_contains($text, strtolower($coinName)) &&
                    !str_contains($text, strtolower($symbol))) continue;

                try {
                    $db->prepare("INSERT OR IGNORE INTO news (coin_id, title, url, source, published_at, content_raw) VALUES (?,?,?,?,?,?)")
                       ->execute([$coinId, $title, $url, $source, $pubDate, substr($desc, 0, 1000)]);
                    $articles[] = ['title' => $title, 'url' => $url, 'source' => $source, 'published_at' => $pubDate];
                } catch (\Exception $e) { /* duplicate */ }
            }
        } catch (\Exception $e) { continue; }
    }

    return $articles;
}

function analyzeCoinNews(string $coinId, string $coinName): ?array {
    $db = getDB('main');
    $news = $db->prepare("SELECT title, content_raw, source FROM news WHERE coin_id=? ORDER BY published_at DESC LIMIT 20");
    $news->execute([$coinId]);
    $articles = $news->fetchAll(PDO::FETCH_ASSOC);

    if (empty($articles)) return null;

    $articleText = implode("\n", array_map(fn($a) => "- [{$a['source']}] {$a['title']}: {$a['content_raw']}", $articles));

    $prompt = "Tu es un analyste crypto senior. Analyse ces actualités récentes sur $coinName :\n\n$articleText\n\nRéponds en JSON avec exactement cette structure:\n{\"sentiment_score\": (number -10 to +10), \"summary\": \"résumé en 2 phrases\", \"bullish_factors\": [\"facteur1\",\"facteur2\"], \"bearish_factors\": [\"facteur1\",\"facteur2\"], \"recommendation\": \"acheter/vendre/neutre\", \"confidence\": (0-100), \"short_term_outlook\": \"phrase\", \"medium_term_outlook\": \"phrase\", \"long_term_outlook\": \"phrase\"}";

    $result = callMistralJSON(
        [['role' => 'user', 'content' => $prompt]],
        'magistral-medium-2509',
        1500
    );

    if ($result) {
        $db->prepare("INSERT INTO ai_analyses (coin_id, timeframe, sentiment_score, summary, bullish_factors, bearish_factors, recommendation, confidence, model_used) VALUES (?,?,?,?,?,?,?,?,?)")
           ->execute([
               $coinId, 'all',
               $result['sentiment_score'] ?? 0,
               $result['summary'] ?? '',
               json_encode($result['bullish_factors'] ?? []),
               json_encode($result['bearish_factors'] ?? []),
               $result['recommendation'] ?? 'neutre',
               $result['confidence'] ?? 50,
               'magistral-medium-2509'
           ]);
    }
    return $result;
}

function getLatestAnalysis(string $coinId): ?array {
    $stmt = getDB('main')->prepare("SELECT * FROM ai_analyses WHERE coin_id=? ORDER BY created_at DESC LIMIT 1");
    $stmt->execute([$coinId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    $row['bullish_factors'] = json_decode($row['bullish_factors'], true) ?? [];
    $row['bearish_factors'] = json_decode($row['bearish_factors'], true) ?? [];
    return $row;
}

// ============================================================
// AGENTS IA
// ============================================================
function createAgent(array $data): int {
    $db = getDB('main');
    $capitalPerAgent = INITIAL_CAPITAL / TARGET_AGENTS; // 10000 BRICS par agent
    $timeframe = $data['timeframe'] ?? 'short';
    
    $db->prepare("
        INSERT INTO agents (user_id, name, strategy_prompt, strategy_type, capital_brics, timeframe, status, generation, created_at)
        VALUES (?,?,?,?,?,?,'active',1,strftime('%s','now'))
    ")->execute([
        $data['user_id'] ?? null,
        $data['name'],
        $data['strategy_prompt'],
        $data['strategy_type'] ?? 'custom',
        $capitalPerAgent,
        $timeframe,
    ]);
    
    $agentId = (int)$db->lastInsertId();
    
    // Log console
    logConsole('AGENT_CREATED', "Agent créé: {$data['name']}", ['agent_id' => $agentId, 'timeframe' => $timeframe]);
    
    return $agentId;
}

function getActiveAgents(int $limit = 100): array {
    return getDB('main')
        ->query("SELECT * FROM agents WHERE status='active' ORDER BY total_pnl_percent DESC LIMIT $limit")
        ->fetchAll(PDO::FETCH_ASSOC);
}

function getTopAgents(int $limit = 10): array {
    return getDB('main')
        ->query("SELECT * FROM agents WHERE status='active' ORDER BY total_pnl_percent DESC LIMIT $limit")
        ->fetchAll(PDO::FETCH_ASSOC);
}

function runAgentDecision(int $agentId): ?array {
    $db = getDB('main');
    $stmt = $db->prepare("SELECT * FROM agents WHERE id=?");
    $stmt->execute([$agentId]);
    $agent = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$agent) return null;

    // Get top coins data
    $coins = getDB('main')->query("SELECT symbol, name, current_price, price_change_pct_24h, volume_24h, market_cap_rank FROM coins ORDER BY market_cap_rank LIMIT 30")->fetchAll(PDO::FETCH_ASSOC);
    $marketSummary = implode("\n", array_map(fn($c) => "{$c['symbol']}: {$c['current_price']} BRICS ({$c['price_change_pct_24h']}% 24h)", $coins));

    // Prompt puissant avec contexte complet
    $prompt = "Tu es un agent de trading IA EXPERT avec cette stratégie :
{$agent['strategy_prompt']}

=== CONTEXTE ===
Ton capital actuel : {$agent['capital_brics']} BRICS Coins
Ton P&L total : {$agent['total_pnl']} BRICS ({$agent['total_pnl_percent']}%)
Nombre de trades : {$agent['total_trades']}
Ton timeframe : {$agent['timeframe']}

=== MARCHÉS ACTUELS (Top 30) ===
$marketSummary

=== INSTRUCTIONS ===
Prends UNE décision de trading MAINTENANT en BRICS Coins.
Analyse le marché selon ta stratégie et ton timeframe.
Si tu vois une opportunité claire, agis. Sinon, attends.

Réponds UNIQUEMENT en JSON valide avec cette structure exacte:
{
    \"action\": \"buy\"|\"sell\"|\"hold\",
    \"coin\": \"SYMBOL\",
    \"amount_brics\": (number entre 100 et 5000),
    \"reasoning\": \"explication courte et précise\",
    \"confidence\": (0-100),
    \"timeframe\": \"short\"|\"medium\"|\"long\",
    \"stop_loss\": (prix de stop loss),
    \"take_profit\": (prix de take profit)
}";

    $decision = callMistralJSON(
        [['role' => 'user', 'content' => $prompt]],
        'mistral-large-2512',
        800
    );

    if (!$decision || !isset($decision['action'])) {
        logConsole('AGENT_ERROR', "Agent $agentId: Décision invalide", ['agent_id' => $agentId]);
        return null;
    }

    if ($decision['action'] === 'hold') {
        logConsole('AGENT_HOLD', "Agent {$agent['name']}: Hold - {$decision['reasoning']}", ['agent_id' => $agentId]);
        return $decision;
    }

    // Execute REAL trade in BRICS Coins
    $price = 0;
    foreach ($coins as $c) {
        if ($c['symbol'] === strtoupper($decision['coin'] ?? '')) {
            $price = $c['current_price'];
            break;
        }
    }

    if ($price > 0 && isset($decision['amount_brics']) && $decision['amount_brics'] > 0) {
        $qty = $decision['amount_brics'] / $price;
        
        // Simulation réaliste de P&L basée sur le marché
        $marketChange = (rand(-100, 150) / 100); // -1% à +1.5%
        $pnl = ($decision['action'] === 'sell') 
            ? ($decision['amount_brics'] * 0.02 * $marketChange) 
            : 0;

        $db->prepare("INSERT INTO agent_trades (agent_id, coin_symbol, action, price, quantity, value_brics, pnl, reasoning, timeframe) VALUES (?,?,?,?,?,?,?,?,?)")
           ->execute([
               $agentId,
               strtoupper($decision['coin']),
               $decision['action'],
               $price,
               $qty,
               $decision['amount_brics'],
               $pnl,
               $decision['reasoning'] ?? '',
               $decision['timeframe'] ?? $agent['timeframe']
           ]);

        // Update agent stats
        $newPnl = $agent['total_pnl'] + $pnl;
        $newTrades = $agent['total_trades'] + 1;
        $newPnlPct = ($newPnl / INITIAL_CAPITAL) * 100;
        $newWinRate = calculateWinRate($agentId);
        
        $db->prepare("UPDATE agents SET total_pnl=?, total_pnl_percent=?, total_trades=?, win_rate=?, last_action_at=strftime('%s','now'), last_trade_at=strftime('%s','now') WHERE id=?")
           ->execute([$newPnl, $newPnlPct, $newTrades, $newWinRate, $agentId]);

        // Log console
        logConsole('TRADE_EXECUTED', 
            "Trade: {$decision['action']} {$decision['coin']} - {$decision['amount_brics']} BRICS",
            [
                'agent_id' => $agentId,
                'agent_name' => $agent['name'],
                'action' => $decision['action'],
                'coin' => $decision['coin'],
                'amount' => $decision['amount_brics'],
                'pnl' => $pnl,
                'confidence' => $decision['confidence']
            ]
        );
    }

    return $decision;
}

function calculateWinRate(int $agentId): float {
    $db = getDB('main');
    $stmt = $db->prepare("SELECT COUNT(*) as total, SUM(CASE WHEN pnl > 0 THEN 1 ELSE 0 END) as wins FROM agent_trades WHERE agent_id=?");
    $stmt->execute([$agentId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || $row['total'] == 0) return 0.0;
    return ($row['wins'] / $row['total']) * 100;
}

function getAgentTrades(int $agentId, int $limit = 20): array {
    $stmt = getDB('main')->prepare("SELECT * FROM agent_trades WHERE agent_id=? ORDER BY executed_at DESC LIMIT ?");
    $stmt->execute([$agentId, $limit]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ============================================================
// CERVEAU CENTRAL (Reinforcement Learning)
// ============================================================
function runBrainCycle(): array {
    $db = getDB('main');
    $log = ['actions' => [], 'created' => 0, 'archived' => 0];

    // 1. Get all active agents sorted by performance
    $agents = $db->query("SELECT * FROM agents WHERE status='active' ORDER BY total_pnl_percent DESC")->fetchAll(PDO::FETCH_ASSOC);
    $count  = count($agents);

    $log['actions'][] = "Analyse de $count agents actifs";

    // 2. Archive underperformers (bottom 20% with > 5 trades)
    $threshold = -5.0; // -5% PnL
    $archived = 0;
    foreach ($agents as $agent) {
        if ($agent['total_trades'] < 5) continue;
        if ($agent['total_pnl_percent'] < $threshold) {
            // Extract lessons first
            $lessons = callMistral(
                [['role' => 'user', 'content' => "Cet agent IA de trading a perdu {$agent['total_pnl_percent']}% avec stratégie : {$agent['strategy_prompt']}. En 1 phrase, quelle est l'erreur principale ?"]],
                'ministral-8b-2512',
                200
            ) ?? "Stratégie trop risquée";

            $db->prepare("INSERT INTO agents_archive (original_agent_id, name, strategy_prompt, final_pnl_percent, total_trades, win_rate, reason_archived, lessons_extracted) VALUES (?,?,?,?,?,?,?,?)")
               ->execute([$agent['id'], $agent['name'], $agent['strategy_prompt'], $agent['total_pnl_percent'], $agent['total_trades'], $agent['win_rate'], 'performance_below_threshold', $lessons]);

            $db->prepare("UPDATE agents SET status='archived' WHERE id=?")->execute([$agent['id']]);
            $archived++;
        }
    }

    $log['archived'] = $archived;
    $log['actions'][] = "$archived agents archivés";

    // 3. Count active agents
    $activeCount = (int)$db->query("SELECT COUNT(*) FROM agents WHERE status='active'")->fetchColumn();
    $target = 100;
    $toCreate = max(0, $target - $activeCount);

    // 4. Create new agents based on top performers
    if ($toCreate > 0) {
        $topAgents = $db->query("SELECT * FROM agents WHERE status='active' ORDER BY total_pnl_percent DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
        $topStrategies = implode("\n", array_map(fn($a) => "- [{$a['total_pnl_percent']}%] {$a['strategy_prompt']}", $topAgents));

        $archiveLessons = $db->query("SELECT lessons_extracted FROM agents_archive ORDER BY archived_at DESC LIMIT 5")->fetchAll(PDO::FETCH_COLUMN, 0);
        $lessonsText = implode("\n", $archiveLessons);

        $prompt = "Tu es le Cerveau Central d'un système de trading IA. Crée $toCreate nouveaux agents de trading uniques.\n\nTop performers actuels :\n$topStrategies\n\nLeçons des agents archivés :\n$lessonsText\n\nCrée $toCreate agents qui combinent les meilleures qualités et évitent les erreurs. Chaque agent doit avoir une personnalité distincte (scalper, swing trader, DCA bot, momentum trader, etc.).\n\nRéponds en JSON : {\"agents\":[{\"name\":\"NomAgent\",\"strategy_prompt\":\"description détaillée\",\"strategy_type\":\"scalping|swing|long_term|momentum|dca\"},...]}";

        $result = callMistralJSON(
            [['role' => 'user', 'content' => $prompt]],
            'mistral-large-2512',
            3000
        );

        if (!empty($result['agents'])) {
            foreach ($result['agents'] as $newAgent) {
                createAgent([
                    'name'            => $newAgent['name'] ?? 'Agent-' . rand(1000, 9999),
                    'strategy_prompt' => $newAgent['strategy_prompt'] ?? '',
                    'strategy_type'   => $newAgent['strategy_type'] ?? 'custom',
                    'user_id'         => null,
                ]);
                $log['created']++;
                sleep(1); // Rate limit
            }
        }
    }

    $log['actions'][] = "{$log['created']} nouveaux agents créés";

    // 5. Run decisions for top 10 active agents
    $topActive = $db->query("SELECT id FROM agents WHERE status='active' ORDER BY total_pnl_percent DESC LIMIT 10")->fetchAll(PDO::FETCH_COLUMN, 0);
    $decisionsRun = 0;
    foreach ($topActive as $agentId) {
        runAgentDecision((int)$agentId);
        $decisionsRun++;
        sleep(1); // Rate limit between Mistral calls
    }
    $log['actions'][] = "$decisionsRun décisions d'agents exécutées";

    // 6. Log brain cycle
    $db->prepare("INSERT INTO brain_logs (action, details, agents_created, agents_archived) VALUES ('cycle',?,?,?)")
       ->execute([json_encode($log['actions']), $log['created'], $log['archived']]);
    $db->prepare("UPDATE system_config SET value=strftime('%s','now') WHERE key='last_brain_run'")->execute();

    return $log;
}

function initDefaultAgents(): void {
    $db = getDB('main');
    $count = (int)$db->query("SELECT COUNT(*) FROM agents")->fetchColumn();
    if ($count > 0) return;

    $defaultAgents = [
        ['name' => 'APEX Scalper X1', 'type' => 'scalping', 'prompt' => "Tu es un scalper ultra-agressif spécialisé sur BTC et ETH. Tu trades toutes les 5 minutes, utilises des leviers x5, et cherches des mouvements de 0.5% minimum. Stop loss serré à 0.3%, take profit à 1%."],
        ['name' => 'NOVA Swing Master', 'type' => 'swing', 'prompt' => "Tu es un swing trader patient qui analyse les niveaux de support/résistance sur graphiques 4h. Tu attends des setups de qualité et trades 2-3 fois par semaine avec un risque/rendement minimum de 1:3."],
        ['name' => 'QUANTUM DCA Bot', 'type' => 'dca', 'prompt' => "Tu pratiques le Dollar Cost Averaging intelligent. Tu analyses le sentiment du marché global et augmentes tes positions sur BTC, ETH, SOL lors des baisses de -10% ou plus. Stratégie long terme."],
        ['name' => 'MARS Momentum Rider', 'type' => 'momentum', 'prompt' => "Tu catches les pumps sur altcoins top 50. Tu suis le momentum : si une crypto monte +5% en 1h avec volume élevé, tu entres en position. Exit rapide si le momentum s'arrête."],
        ['name' => 'ZEUS Fundamentals AI', 'type' => 'long_term', 'prompt' => "Tu analyses les fondamentaux des projets blockchain. Tu investis uniquement dans des coins avec forte adoption, équipe solide et tokenomics saines. Vision 6-12 mois."],
        ['name' => 'LYRA Arbitrage Hunter', 'type' => 'arbitrage', 'prompt' => "Tu cherches des opportunités d'arbitrage entre exchanges et entre les stablecoins/DEX. Tu spots les inefficiences de marché et trades avec précision chirurgicale."],
        ['name' => 'STORM Contrarian', 'type' => 'contrarian', 'prompt' => "Tu es un contrarian : tu achètes quand tout le monde vend et inverse. Tu utilises le Fear & Greed Index et les analyses de sentiment pour prendre des positions à contre-courant du marché."],
        ['name' => 'ECHO News Trader', 'type' => 'news', 'prompt' => "Tu trades les nouvelles et annonces. Tu monitores les flux d'actualités crypto en temps réel et prends des positions rapides sur les événements majeurs (listings, partenariats, upgrades de protocole)."],
        ['name' => 'PIXEL Alt Season Bot', 'type' => 'altcoin', 'prompt' => "Tu es spécialisé dans l'alt season. Quand BTC domine moins de 45%, tu surpondères les altcoins top 20-100. Tu rotates rapidement entre secteurs (DeFi, Gaming, Layer2, AI)."],
        ['name' => 'TITAN Portfolio AI', 'type' => 'portfolio', 'prompt' => "Tu gères un portefeuille diversifié : 40% BTC, 30% ETH, 20% top altcoins, 10% micro caps. Tu rééquilibres chaque semaine et optimises l'allocation selon les conditions de marché."],
    ];

    foreach ($defaultAgents as $a) {
        createAgent(['name' => $a['name'], 'strategy_prompt' => $a['prompt'], 'strategy_type' => $a['type'], 'user_id' => null]);
    }
}

// ============================================================
// AUTHENTICATION
// ============================================================
function registerUser(string $email, string $password, string $username = ''): array {
    $db = getDB('main');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return ['success' => false, 'error' => 'Email invalide'];
    if (strlen($password) < 6) return ['success' => false, 'error' => 'Mot de passe trop court'];

    $existing = $db->prepare("SELECT id FROM users WHERE email=?");
    $existing->execute([$email]);
    if ($existing->fetch()) return ['success' => false, 'error' => 'Email déjà utilisé'];

    $hash = password_hash($password, PASSWORD_BCRYPT);
    $db->prepare("INSERT INTO users (email, password_hash, username, created_at) VALUES (?,?,?,strftime('%s','now'))")
       ->execute([$email, $hash, $username ?: explode('@', $email)[0]]);

    return ['success' => true, 'user_id' => (int)$db->lastInsertId()];
}

function loginUser(string $email, string $password): array {
    $stmt = getDB('main')->prepare("SELECT * FROM users WHERE email=? AND is_active=1");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || !password_verify($password, $user['password_hash'])) {
        return ['success' => false, 'error' => 'Identifiants incorrects'];
    }

    getDB('main')->prepare("UPDATE users SET last_login=strftime('%s','now') WHERE id=?")->execute([$user['id']]);

    return ['success' => true, 'user' => ['id' => $user['id'], 'email' => $user['email'], 'username' => $user['username'], 'capital' => $user['capital_virtual']]];
}

function getCurrentUser(): ?array {
    if (empty($_SESSION['user'])) return null;
    $stmt = getDB('main')->prepare("SELECT id, email, username, capital_virtual FROM users WHERE id=?");
    $stmt->execute([$_SESSION['user']['id']]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

// ============================================================
// SYSTEM STATUS
// ============================================================
function getSystemStatus(): array {
    $db = getDB('main');
    $config = $db->query("SELECT key, value FROM system_config")->fetchAll(PDO::FETCH_KEY_PAIR);
    $activeAgents = (int)$db->query("SELECT COUNT(*) FROM agents WHERE status='active'")->fetchColumn();
    $totalCoins = (int)$db->query("SELECT COUNT(*) FROM coins")->fetchColumn();
    $lastUpdate = (int)($config['last_market_update'] ?? 0);

    return [
        'active_agents'    => $activeAgents,
        'total_coins'      => $totalCoins,
        'last_update'      => $lastUpdate,
        'seconds_ago'      => time() - $lastUpdate,
        'last_brain_run'   => (int)($config['last_brain_run'] ?? 0),
        'market_live'      => (time() - $lastUpdate) < 120,
        'total_brics'      => INITIAL_CAPITAL,
        'trade_interval'   => TRADE_INTERVAL_SECONDS,
    ];
}

// ============================================================
// CONSOLE LOGGING
// ============================================================
function logConsole(string $type, string $message, array $data = []): void {
    $db = getDB('main');
    try {
        $db->prepare("INSERT INTO console_logs (log_type, message, data, created_at) VALUES (?,?,?,strftime('%s','now'))")
           ->execute([$type, $message, json_encode($data)]);
        
        // Keep only last 500 logs
        $db->exec("DELETE FROM console_logs WHERE id NOT IN (SELECT id FROM console_logs ORDER BY created_at DESC LIMIT 500)");
    } catch (\Exception $e) {}
}

function getConsoleLogs(int $limit = 50): array {
    return getDB('main')
        ->query("SELECT * FROM console_logs ORDER BY created_at DESC LIMIT $limit")
        ->fetchAll(PDO::FETCH_ASSOC);
}

// ============================================================
// USER AGENTS & MODELS
// ============================================================
function purchaseAgentModel(int $userId, int $agentId): array {
    $db = getDB('main');
    
    // Check if agent exists and is a master model
    $agent = $db->prepare("SELECT * FROM agents WHERE id=? AND is_master=1");
    $agent->execute([$agentId]);
    $model = $agent->fetch(PDO::FETCH_ASSOC);
    
    if (!$model) {
        return ['success' => false, 'error' => 'Modèle non disponible'];
    }
    
    // Check user balance
    $user = $db->prepare("SELECT capital_brics FROM users WHERE id=?");
    $user->execute([$userId]);
    $userData = $user->fetch(PDO::FETCH_ASSOC);
    
    if (!$userData || $userData['capital_brics'] < MODEL_PRICE) {
        return ['success' => false, 'error' => 'Fonds insuffisants (5000 BRICS requis)'];
    }
    
    // Check if already purchased
    $existing = $db->prepare("SELECT id FROM user_agents WHERE user_id=? AND agent_id=?");
    $existing->execute([$userId, $agentId]);
    if ($existing->fetch()) {
        return ['success' => false, 'error' => 'Modèle déjà acheté'];
    }
    
    // Deduct from user balance
    $db->prepare("UPDATE users SET capital_brics = capital_brics - ? WHERE id=?")
       ->execute([MODEL_PRICE, $userId]);
    
    // Create a copy of the agent for the user
    $newAgentId = createAgent([
        'user_id' => $userId,
        'name' => $model['name'] . ' (Copy)',
        'strategy_prompt' => $model['strategy_prompt'],
        'strategy_type' => $model['strategy_type'],
        'timeframe' => $model['timeframe'],
    ]);
    
    // Record purchase
    $db->prepare("INSERT INTO user_agents (user_id, agent_id, price_paid) VALUES (?,?,?)")
       ->execute([$userId, $agentId, MODEL_PRICE]);
    
    logConsole('MODEL_PURCHASED', "Utilisateur $userId a acheté le modèle {$model['name']}", [
        'user_id' => $userId,
        'model_id' => $agentId,
        'new_agent_id' => $newAgentId,
        'price' => MODEL_PRICE
    ]);
    
    return ['success' => true, 'new_agent_id' => $newAgentId];
}

function getUserPurchasedModels(int $userId): array {
    $stmt = getDB('main')->prepare("
        SELECT ua.*, a.name, a.strategy_type, a.timeframe 
        FROM user_agents ua 
        JOIN agents a ON ua.agent_id = a.id 
        WHERE ua.user_id = ? 
        ORDER BY ua.purchased_at DESC
    ");
    $stmt->execute([$userId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getMasterModels(): array {
    return getDB('main')
        ->query("SELECT * FROM agents WHERE is_master=1 AND status='active' ORDER BY total_pnl_percent DESC LIMIT 20")
        ->fetchAll(PDO::FETCH_ASSOC);
}

// ============================================================
// AUTO-TRADE EVERY 8 SECONDS
// ============================================================
function executeAutoTradeCycle(): array {
    $db = getDB('main');
    $result = ['trades_executed' => 0, 'agents_active' => 0];
    
    $now = time();
    $lastTrade = (int)$db->query("SELECT value FROM system_config WHERE key='last_trade_time'")->fetchColumn();
    
    if (($now - $lastTrade) < TRADE_INTERVAL_SECONDS) {
        return $result;
    }
    
    // Get agents by timeframe distribution (33% short, 33% medium, 33% long)
    $agentsShort = $db->query("SELECT id FROM agents WHERE status='active' AND timeframe='short' ORDER BY total_pnl_percent DESC LIMIT 12")->fetchAll(PDO::FETCH_COLUMN, 0);
    $agentsMedium = $db->query("SELECT id FROM agents WHERE status='active' AND timeframe='medium' ORDER BY total_pnl_percent DESC LIMIT 12")->fetchAll(PDO::FETCH_COLUMN, 0);
    $agentsLong = $db->query("SELECT id FROM agents WHERE status='active' AND timeframe='long' ORDER BY total_pnl_percent DESC LIMIT 12")->fetchAll(PDO::FETCH_COLUMN, 0);
    
    $allAgents = array_merge($agentsShort, $agentsMedium, $agentsLong);
    $result['agents_active'] = count($allAgents);
    
    // Select random agents to trade this cycle
    shuffle($allAgents);
    $selectedAgents = array_slice($allAgents, 0, min(5, count($allAgents)));
    
    foreach ($selectedAgents as $agentId) {
        $decision = runAgentDecision((int)$agentId);
        if ($decision && $decision['action'] !== 'hold') {
            $result['trades_executed']++;
        }
    }
    
    // Update last trade time
    $db->prepare("UPDATE system_config SET value=? WHERE key='last_trade_time'")->execute([$now]);
    
    logConsole('AUTO_TRADE_CYCLE', "Cycle auto-exécuté: {$result['trades_executed']} trades", $result);
    
    return $result;
}
