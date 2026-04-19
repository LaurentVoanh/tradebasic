-- ============================================================
-- IA CRYPTO INVEST - SCHEMA COMPLET
-- 3 bases : short_term.db, medium_term.db, long_term.db
-- + main.db pour users/agents/news
-- ============================================================

-- ============================================================
-- MAIN DATABASE (main.db)
-- ============================================================

CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    email TEXT UNIQUE NOT NULL,
    password_hash TEXT NOT NULL,
    username TEXT,
    capital_virtual REAL DEFAULT 1000000.00,
    created_at INTEGER DEFAULT (strftime('%s','now')),
    last_login INTEGER,
    is_active INTEGER DEFAULT 1
);

CREATE TABLE IF NOT EXISTS portfolios (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    coin_id TEXT NOT NULL,
    coin_symbol TEXT NOT NULL,
    quantity REAL DEFAULT 0,
    avg_buy_price REAL DEFAULT 0,
    total_invested REAL DEFAULT 0,
    current_value REAL DEFAULT 0,
    pnl_percent REAL DEFAULT 0,
    updated_at INTEGER DEFAULT (strftime('%s','now')),
    FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE INDEX IF NOT EXISTS idx_portfolio_user ON portfolios(user_id);

CREATE TABLE IF NOT EXISTS agents (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER,
    name TEXT NOT NULL,
    strategy_prompt TEXT NOT NULL,
    strategy_type TEXT DEFAULT 'custom',
    capital REAL DEFAULT 1000000.00,
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
    created_at INTEGER DEFAULT (strftime('%s','now')),
    last_action_at INTEGER,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE INDEX IF NOT EXISTS idx_agents_status ON agents(status);
CREATE INDEX IF NOT EXISTS idx_agents_pnl ON agents(total_pnl_percent DESC);

CREATE TABLE IF NOT EXISTS agent_trades (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    agent_id INTEGER NOT NULL,
    coin_symbol TEXT NOT NULL,
    action TEXT NOT NULL,
    price REAL NOT NULL,
    quantity REAL NOT NULL,
    value_eur REAL NOT NULL,
    pnl REAL DEFAULT 0,
    pnl_percent REAL DEFAULT 0,
    reasoning TEXT,
    timeframe TEXT DEFAULT 'short',
    executed_at INTEGER DEFAULT (strftime('%s','now')),
    FOREIGN KEY (agent_id) REFERENCES agents(id)
);

CREATE INDEX IF NOT EXISTS idx_trades_agent ON agent_trades(agent_id);
CREATE INDEX IF NOT EXISTS idx_trades_time ON agent_trades(executed_at DESC);

CREATE TABLE IF NOT EXISTS agents_archive (
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
);

CREATE TABLE IF NOT EXISTS coins (
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
);

CREATE INDEX IF NOT EXISTS idx_coins_rank ON coins(market_cap_rank);

CREATE TABLE IF NOT EXISTS news (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    coin_id TEXT,
    title TEXT NOT NULL,
    url TEXT UNIQUE,
    source TEXT,
    published_at INTEGER,
    content_raw TEXT,
    sentiment_score REAL DEFAULT 0,
    fetched_at INTEGER DEFAULT (strftime('%s','now'))
);

CREATE INDEX IF NOT EXISTS idx_news_coin ON news(coin_id);
CREATE INDEX IF NOT EXISTS idx_news_time ON news(published_at DESC);

CREATE TABLE IF NOT EXISTS ai_analyses (
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
);

CREATE INDEX IF NOT EXISTS idx_analyses_coin ON ai_analyses(coin_id, timeframe);

CREATE TABLE IF NOT EXISTS brain_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    action TEXT NOT NULL,
    details TEXT,
    agents_created INTEGER DEFAULT 0,
    agents_archived INTEGER DEFAULT 0,
    top_performer_id INTEGER,
    created_at INTEGER DEFAULT (strftime('%s','now'))
);

CREATE TABLE IF NOT EXISTS system_config (
    key TEXT PRIMARY KEY,
    value TEXT,
    updated_at INTEGER DEFAULT (strftime('%s','now'))
);

INSERT OR IGNORE INTO system_config (key, value) VALUES
    ('last_market_update', '0'),
    ('last_brain_run', '0'),
    ('active_agents_count', '0'),
    ('target_agents_count', '100'),
    ('current_mistral_key', 'KEY_1');

-- ============================================================
-- SHORT TERM DATABASE (short_term.db)
-- Scalping : 1min à 1h
-- ============================================================

CREATE TABLE IF NOT EXISTS price_ticks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    coin_symbol TEXT NOT NULL,
    price REAL NOT NULL,
    volume REAL DEFAULT 0,
    bid REAL DEFAULT 0,
    ask REAL DEFAULT 0,
    timestamp INTEGER DEFAULT (strftime('%s','now'))
);

CREATE INDEX IF NOT EXISTS idx_ticks_symbol ON price_ticks(coin_symbol, timestamp DESC);

CREATE TABLE IF NOT EXISTS signals_short (
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
);

CREATE TABLE IF NOT EXISTS ohlcv_1m (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    coin_symbol TEXT NOT NULL,
    open REAL, high REAL, low REAL, close REAL, volume REAL,
    timestamp INTEGER
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_ohlcv1m ON ohlcv_1m(coin_symbol, timestamp);

CREATE TABLE IF NOT EXISTS ohlcv_5m (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    coin_symbol TEXT NOT NULL,
    open REAL, high REAL, low REAL, close REAL, volume REAL,
    timestamp INTEGER
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_ohlcv5m ON ohlcv_5m(coin_symbol, timestamp);

-- ============================================================
-- MEDIUM TERM DATABASE (medium_term.db)
-- Swing trading : 1 jour à 1 semaine
-- ============================================================

CREATE TABLE IF NOT EXISTS ohlcv_1h (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    coin_symbol TEXT NOT NULL,
    open REAL, high REAL, low REAL, close REAL, volume REAL,
    timestamp INTEGER
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_ohlcv1h ON ohlcv_1h(coin_symbol, timestamp);

CREATE TABLE IF NOT EXISTS ohlcv_4h (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    coin_symbol TEXT NOT NULL,
    open REAL, high REAL, low REAL, close REAL, volume REAL,
    timestamp INTEGER
);

CREATE TABLE IF NOT EXISTS ohlcv_1d (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    coin_symbol TEXT NOT NULL,
    open REAL, high REAL, low REAL, close REAL, volume REAL,
    timestamp INTEGER
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_ohlcv1d ON ohlcv_1d(coin_symbol, timestamp);

CREATE TABLE IF NOT EXISTS technical_indicators (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    coin_symbol TEXT NOT NULL,
    timeframe TEXT DEFAULT '1d',
    rsi REAL,
    macd_line REAL,
    macd_signal REAL,
    macd_hist REAL,
    bb_upper REAL,
    bb_mid REAL,
    bb_lower REAL,
    ema_20 REAL,
    ema_50 REAL,
    ema_200 REAL,
    volume_sma REAL,
    trend TEXT,
    calculated_at INTEGER DEFAULT (strftime('%s','now'))
);

CREATE INDEX IF NOT EXISTS idx_indicators_symbol ON technical_indicators(coin_symbol, timeframe);

CREATE TABLE IF NOT EXISTS swing_signals (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    coin_symbol TEXT NOT NULL,
    signal_type TEXT,
    entry_zone_low REAL,
    entry_zone_high REAL,
    target_1 REAL,
    target_2 REAL,
    stop_loss REAL,
    risk_reward REAL,
    confidence REAL,
    timeframe TEXT DEFAULT '1d',
    analysis TEXT,
    created_at INTEGER DEFAULT (strftime('%s','now')),
    expires_at INTEGER
);

-- ============================================================
-- LONG TERM DATABASE (long_term.db)
-- Investissement : semaines à mois
-- ============================================================

CREATE TABLE IF NOT EXISTS fundamentals (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    coin_id TEXT UNIQUE NOT NULL,
    whitepaper_summary TEXT,
    use_case TEXT,
    team_score REAL DEFAULT 0,
    technology_score REAL DEFAULT 0,
    adoption_score REAL DEFAULT 0,
    tokenomics_score REAL DEFAULT 0,
    overall_score REAL DEFAULT 0,
    on_chain_activity TEXT DEFAULT '{}',
    developer_activity TEXT DEFAULT '{}',
    updated_at INTEGER DEFAULT (strftime('%s','now'))
);

CREATE TABLE IF NOT EXISTS macro_analysis (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    analysis_type TEXT,
    title TEXT,
    content TEXT,
    impact_on_crypto TEXT,
    sentiment REAL DEFAULT 0,
    created_at INTEGER DEFAULT (strftime('%s','now'))
);

CREATE TABLE IF NOT EXISTS price_history_daily (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    coin_id TEXT NOT NULL,
    price REAL,
    market_cap REAL,
    volume REAL,
    date INTEGER
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_ph_daily ON price_history_daily(coin_id, date);

CREATE TABLE IF NOT EXISTS long_term_theses (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    coin_id TEXT NOT NULL,
    thesis_type TEXT DEFAULT 'bullish',
    time_horizon TEXT DEFAULT '6months',
    target_price REAL,
    target_date INTEGER,
    reasoning TEXT,
    catalysts TEXT DEFAULT '[]',
    risks TEXT DEFAULT '[]',
    confidence REAL DEFAULT 0,
    created_at INTEGER DEFAULT (strftime('%s','now')),
    updated_at INTEGER DEFAULT (strftime('%s','now'))
);
