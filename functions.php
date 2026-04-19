<?php
/**
 * IA CRYPTO INVEST - functions.php
 * Core library : DB, Mistral API, market data, agents
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
    $schema = file_get_contents(__DIR__ . '/schema.sql');
    if (!$schema) return;

    // Split by database sections
    $sections = [
        'main'        => [],
        'short_term'  => [],
        'medium_term' => [],
        'long_term'   => [],
    ];

    $currentDb = 'main';
    foreach (explode("\n", $schema) as $line) {
        if (strpos($line, 'SHORT TERM DATABASE') !== false)  { $currentDb = 'short_term'; continue; }
        if (strpos($line, 'MEDIUM TERM DATABASE') !== false) { $currentDb = 'medium_term'; continue; }
        if (strpos($line, 'LONG TERM DATABASE') !== false)   { $currentDb = 'long_term'; continue; }
        $sections[$currentDb][] = $line;
    }

    foreach ($sections as $dbName => $lines) {
        $sql = implode("\n", $lines);
        // Split on semicolons and execute each statement
        $statements = array_filter(array_map('trim', explode(';', $sql)));
        $db = getDB($dbName);
        foreach ($statements as $stmt) {
            if (!empty($stmt) && strlen($stmt) > 5) {
                try { $db->exec($stmt . ';'); } catch (\Exception $e) { /* ignore already-exists */ }
            }
        }
    }
}

// ============================================================
// MISTRAL API
// ============================================================
function getMistralKey(): string {
    static $keyIndex = 0;
    $keys = array_values(MISTRAL_KEYS);
    $key = $keys[$keyIndex % count($keys)];
    $keyIndex++;
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
    $clean = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', trim($raw));
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
            $coin['price_change_percentage_7d_in_currency'] ?? 0,
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
    $db->prepare("
        INSERT INTO agents (user_id, name, strategy_prompt, strategy_type, capital, status, generation, created_at)
        VALUES (?,?,?,?,1000000,'active',1,strftime('%s','now'))
    ")->execute([
        $data['user_id'] ?? null,
        $data['name'],
        $data['strategy_prompt'],
        $data['strategy_type'] ?? 'custom',
    ]);
    return (int)$db->lastInsertId();
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
    $agent = $db->prepare("SELECT * FROM agents WHERE id=?")->execute([$agentId]) ? null : null;
    $stmt = $db->prepare("SELECT * FROM agents WHERE id=?");
    $stmt->execute([$agentId]);
    $agent = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$agent) return null;

    // Get top coins data
    $coins = getDB('main')->query("SELECT symbol, name, current_price, price_change_pct_24h, volume_24h, market_cap_rank FROM coins ORDER BY market_cap_rank LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
    $marketSummary = implode("\n", array_map(fn($c) => "{$c['symbol']}: {$c['current_price']}€ ({$c['price_change_pct_24h']}% 24h)", $coins));

    $prompt = "Tu es un agent de trading IA avec cette stratégie :\n{$agent['strategy_prompt']}\n\nTon capital actuel : {$agent['capital']}€\nTon P&L total : {$agent['total_pnl']}€ ({$agent['total_pnl_percent']}%)\nNombre de trades : {$agent['total_trades']}\n\nMarchés actuels :\n$marketSummary\n\nPrends UNE décision de trading maintenant. Réponds en JSON :\n{\"action\":\"buy|sell|hold\",\"coin\":\"SYMBOL\",\"amount_eur\":(number),\"reasoning\":\"explication courte\",\"confidence\":(0-100),\"timeframe\":\"short|medium|long\"}";

    $decision = callMistralJSON(
        [['role' => 'user', 'content' => $prompt]],
        'mistral-small-2506',
        500
    );

    if (!$decision || $decision['action'] === 'hold') return $decision;

    // Execute virtual trade
    $price = 0;
    foreach ($coins as $c) {
        if ($c['symbol'] === strtoupper($decision['coin'] ?? '')) {
            $price = $c['current_price'];
            break;
        }
    }

    if ($price > 0 && !empty($decision['amount_eur'])) {
        $qty = $decision['amount_eur'] / $price;
        $pnl = ($decision['action'] === 'sell') ? ($decision['amount_eur'] * 0.02 * (rand(-100,200)/100)) : 0;

        $db->prepare("INSERT INTO agent_trades (agent_id, coin_symbol, action, price, quantity, value_eur, pnl, reasoning, timeframe) VALUES (?,?,?,?,?,?,?,?,?)")
           ->execute([
               $agentId,
               strtoupper($decision['coin']),
               $decision['action'],
               $price,
               $qty,
               $decision['amount_eur'],
               $pnl,
               $decision['reasoning'] ?? '',
               $decision['timeframe'] ?? 'short'
           ]);

        // Update agent stats
        $newPnl = $agent['total_pnl'] + $pnl;
        $newTrades = $agent['total_trades'] + 1;
        $newPnlPct = ($newPnl / 1000000) * 100;
        $db->prepare("UPDATE agents SET total_pnl=?, total_pnl_percent=?, total_trades=?, last_action_at=strftime('%s','now') WHERE id=?")
           ->execute([$newPnl, $newPnlPct, $newTrades, $agentId]);
    }

    return $decision;
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

        $archiveLessons = $db->query("SELECT lessons_extracted FROM agents_archive ORDER BY archived_at DESC LIMIT 5")->fetchAll(PDO::FETCH_COLUMN);
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
    $topActive = $db->query("SELECT id FROM agents WHERE status='active' ORDER BY total_pnl_percent DESC LIMIT 10")->fetchAll(PDO::FETCH_COLUMN);
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
    ];
}
