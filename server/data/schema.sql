-- API Keys Table
CREATE TABLE IF NOT EXISTS api_keys (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    api_key TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at TEXT,
    is_active INTEGER NOT NULL DEFAULT 1,
    requests_count INTEGER NOT NULL DEFAULT 0,
    notes TEXT
);

-- Statistics Table
CREATE TABLE IF NOT EXISTS stats (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    api_key TEXT NOT NULL,
    isbn TEXT NOT NULL,
    success INTEGER NOT NULL DEFAULT 0,
    scraper_used TEXT,
    response_time_ms INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (api_key) REFERENCES api_keys(api_key)
);

-- Create indexes
CREATE INDEX IF NOT EXISTS idx_api_keys_key ON api_keys(api_key);
CREATE INDEX IF NOT EXISTS idx_api_keys_active ON api_keys(is_active);
CREATE INDEX IF NOT EXISTS idx_stats_api_key ON stats(api_key);
CREATE INDEX IF NOT EXISTS idx_stats_created_at ON stats(created_at);
CREATE INDEX IF NOT EXISTS idx_stats_isbn ON stats(isbn);
