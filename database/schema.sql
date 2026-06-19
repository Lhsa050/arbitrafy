-- Bússola do Tráfego - Schema SQL
-- Compatível com SQLite e MySQL

-- Settings (API keys, configurações gerais)
CREATE TABLE IF NOT EXISTS settings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    setting_key VARCHAR(100) UNIQUE NOT NULL,
    setting_value TEXT,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Compass Daily (2025 + 2026)
CREATE TABLE IF NOT EXISTS compass_daily (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    year INTEGER NOT NULL,
    date DATE NOT NULL,
    month_name VARCHAR(20),
    investimento DECIMAL(12,2) DEFAULT 0,
    receita_usd DECIMAL(12,6) DEFAULT 0,
    retencao DECIMAL(12,6) DEFAULT 0,
    lucro_bruto DECIMAL(12,2) DEFAULT 0,
    roi_bruto DECIMAL(8,4) DEFAULT 0,
    imposto DECIMAL(12,2) DEFAULT 0,
    custo_fixo DECIMAL(12,2) DEFAULT 0,
    lucro_liquido DECIMAL(12,2) DEFAULT 0,
    roi_liquido DECIMAL(8,4) DEFAULT 0,
    cotacao_dolar DECIMAL(8,4) DEFAULT 0,
    receita_prog_usd DECIMAL(12,6) DEFAULT 0,
    receita_prog_brl DECIMAL(12,2) DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(year, date)
);

-- Campanhas Facebook
CREATE TABLE IF NOT EXISTS fb_campaigns (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    account_name VARCHAR(100) DEFAULT 'BM Para SLT',
    date DATE NOT NULL,
    campaign_id VARCHAR(50) NOT NULL,
    campaign_name VARCHAR(255),
    investimento DECIMAL(12,2) DEFAULT 0,
    impressoes INTEGER DEFAULT 0,
    cliques INTEGER DEFAULT 0,
    viz_lp INTEGER DEFAULT 0,
    cpc_ads DECIMAL(10,6) DEFAULT 0,
    ctr_ads DECIMAL(10,6) DEFAULT 0,
    conv_pct DECIMAL(10,6) DEFAULT 0,
    sessoes_lp INTEGER DEFAULT 0,
    cr_pct DECIMAL(10,6) DEFAULT 0,
    ecpm_lp DECIMAL(10,6) DEFAULT 0,
    cobertura DECIMAL(10,6) DEFAULT 0,
    viewability DECIMAL(10,6) DEFAULT 0,
    receita_usd DECIMAL(12,6) DEFAULT 0,
    receita_brl DECIMAL(12,2) DEFAULT 0,
    roas DECIMAL(10,4) DEFAULT 0,
    produto VARCHAR(100),
    other VARCHAR(100),
    total_investimento_brl DECIMAL(12,2) DEFAULT 0,
    total_receita_brl DECIMAL(12,2) DEFAULT 0,
    roi DECIMAL(10,4) DEFAULT 0,
    rpc DECIMAL(10,6) DEFAULT 0,
    cpm DECIMAL(10,6) DEFAULT 0,
    profit DECIMAL(12,2) DEFAULT 0,
    observacoes TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(date, campaign_id, account_name)
);

-- Revenue (GAM por utm_campaign)
CREATE TABLE IF NOT EXISTS revenue (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    date DATE NOT NULL,
    campaign_id VARCHAR(50) NOT NULL,
    utm_campaign VARCHAR(255),
    receita_usd DECIMAL(12,6) DEFAULT 0,
    gam_impressions INTEGER DEFAULT 0,
    gam_ad_requests INTEGER DEFAULT 0,
    site_name VARCHAR(255) DEFAULT '',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(date, campaign_id, site_name)
);

-- Sites GAM (para filtrar receita por site)
CREATE TABLE IF NOT EXISTS gam_sites (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_name VARCHAR(255) NOT NULL,
    ad_unit_pattern VARCHAR(255) NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Receita Programática
CREATE TABLE IF NOT EXISTS receita_programatica (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    date DATE NOT NULL,
    day_of_week VARCHAR(20),
    eventos VARCHAR(255),
    drop_off_rate DECIMAL(10,6) DEFAULT 0,
    impressions INTEGER DEFAULT 0,
    clicks INTEGER DEFAULT 0,
    ctr DECIMAL(10,6) DEFAULT 0,
    revenue_usd DECIMAL(12,6) DEFAULT 0,
    avg_ecpm DECIMAL(10,6) DEFAULT 0,
    ad_requests INTEGER DEFAULT 0,
    match_rate DECIMAL(10,6) DEFAULT 0,
    ad_request_ecpm DECIMAL(10,6) DEFAULT 0,
    delivery_rate DECIMAL(10,6) DEFAULT 0,
    active_view_pct DECIMAL(10,6) DEFAULT 0,
    varianca_ecpm DECIMAL(10,6) DEFAULT 0,
    views INTEGER DEFAULT 0,
    sessions INTEGER DEFAULT 0,
    bounce_rate DECIMAL(10,6) DEFAULT 0,
    avg_engagement_time DECIMAL(10,6) DEFAULT 0,
    rpp DECIMAL(12,6) DEFAULT 0,
    rps DECIMAL(12,6) DEFAULT 0,
    imp_pageview DECIMAL(10,6) DEFAULT 0,
    req_pageview DECIMAL(10,6) DEFAULT 0,
    req_sess DECIMAL(10,6) DEFAULT 0,
    imp_sess DECIMAL(10,6) DEFAULT 0,
    view_sess DECIMAL(10,6) DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(date)
);

-- Google Ads Raw
CREATE TABLE IF NOT EXISTS google_ads (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    date DATE NOT NULL,
    campaign_id VARCHAR(50),
    campaign_name VARCHAR(255),
    cost DECIMAL(12,2) DEFAULT 0,
    impressions INTEGER DEFAULT 0,
    clicks INTEGER DEFAULT 0,
    avg_cpc DECIMAL(10,6) DEFAULT 0,
    ctr DECIMAL(10,6) DEFAULT 0,
    conversion_rate DECIMAL(10,6) DEFAULT 0,
    cpm DECIMAL(10,6) DEFAULT 0,
    conversions DECIMAL(10,2) DEFAULT 0,
    status VARCHAR(50) DEFAULT 'Ativo',
    last_updated DATETIME,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(date, campaign_id)
);

-- Financeiro - Custos
CREATE TABLE IF NOT EXISTS financeiro_custos (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    ano INTEGER NOT NULL,
    mes VARCHAR(20) NOT NULL,
    mes_num INTEGER NOT NULL,
    tipo VARCHAR(20) NOT NULL, -- 'fixo' ou 'variavel'
    descricao VARCHAR(255) NOT NULL,
    valor DECIMAL(12,2) DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Financeiro - Pagamentos Google
CREATE TABLE IF NOT EXISTS financeiro_pagamentos (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    mes VARCHAR(20) NOT NULL,
    ano INTEGER NOT NULL,
    valor DECIMAL(12,2) DEFAULT 0,
    saldo DECIMAL(12,2) DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Plano de Gastos
CREATE TABLE IF NOT EXISTS plano_gastos (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    periodo VARCHAR(50) NOT NULL, -- Ex: 'Fev 2026'
    dia DATE,
    conta VARCHAR(100),
    campaign_id VARCHAR(50),
    campaign_name VARCHAR(255),
    status VARCHAR(50),
    orcamento DECIMAL(12,2) DEFAULT 0,
    escala VARCHAR(50),
    data_ref DATE,
    projetado DECIMAL(12,2) DEFAULT 0,
    realizado DECIMAL(12,2) DEFAULT 0,
    pacing DECIMAL(10,6) DEFAULT 0,
    tx_cresc_real DECIMAL(10,6) DEFAULT 0,
    tx_cresc_proj DECIMAL(10,6) DEFAULT 0,
    meta DECIMAL(12,2) DEFAULT 0,
    restante_meta DECIMAL(12,2) DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Tarefas (Mentoria Alvo10k)
CREATE TABLE IF NOT EXISTS tarefas (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    status VARCHAR(50) DEFAULT 'Pendente',
    prazo DATE,
    descricao TEXT NOT NULL,
    responsavel VARCHAR(100),
    prioridade VARCHAR(50) DEFAULT '2. Média',
    task_status VARCHAR(50),
    formato VARCHAR(100),
    escopo TEXT,
    obs TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- FB Logs
CREATE TABLE IF NOT EXISTS fb_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    timestamp DATETIME NOT NULL,
    conta VARCHAR(100),
    http_code INTEGER,
    mensagem TEXT,
    url TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Sync Logs (GAM + FB errors/info)
CREATE TABLE IF NOT EXISTS sync_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
    source VARCHAR(20) NOT NULL,
    level VARCHAR(20) DEFAULT 'ERROR',
    step VARCHAR(100),
    message TEXT,
    details TEXT,
    http_code INTEGER,
    duration_ms INTEGER
);

-- Impostos config
CREATE TABLE IF NOT EXISTS impostos_config (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    nome VARCHAR(100) NOT NULL,
    percentual DECIMAL(8,4) NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Fonte de Dados (listas auxiliares)
CREATE TABLE IF NOT EXISTS fonte_dados_status (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    nome VARCHAR(100) NOT NULL
);

CREATE TABLE IF NOT EXISTS fonte_dados_prioridades (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    nome VARCHAR(100) NOT NULL
);

CREATE TABLE IF NOT EXISTS fonte_dados_responsaveis (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    nome VARCHAR(100) NOT NULL
);

-- Insert default data
INSERT OR IGNORE INTO settings (setting_key, setting_value) VALUES ('fb_access_token', '');
INSERT OR IGNORE INTO settings (setting_key, setting_value) VALUES ('fb_ad_accounts', '[]');
INSERT OR IGNORE INTO settings (setting_key, setting_value) VALUES ('fb_api_version', 'v21.0');
INSERT OR IGNORE INTO settings (setting_key, setting_value) VALUES ('gam_network_code', '');
INSERT OR IGNORE INTO settings (setting_key, setting_value) VALUES ('gam_service_account_json', '');
INSERT OR IGNORE INTO settings (setting_key, setting_value) VALUES ('imposto_facebook', '0.125');
INSERT OR IGNORE INTO settings (setting_key, setting_value) VALUES ('imposto_outros', '0.16');
INSERT OR IGNORE INTO settings (setting_key, setting_value) VALUES ('cotacao_dolar', '5.80');
INSERT OR IGNORE INTO settings (setting_key, setting_value) VALUES ('gads_customer_id', '');
INSERT OR IGNORE INTO settings (setting_key, setting_value) VALUES ('gads_developer_token', '');
INSERT OR IGNORE INTO settings (setting_key, setting_value) VALUES ('gads_client_id', '');
INSERT OR IGNORE INTO settings (setting_key, setting_value) VALUES ('gads_client_secret', '');
INSERT OR IGNORE INTO settings (setting_key, setting_value) VALUES ('gads_refresh_token', '');
INSERT OR IGNORE INTO settings (setting_key, setting_value) VALUES ('gam_sites', '[]');

-- Facebook App (Social Login OAuth)
INSERT OR IGNORE INTO settings (setting_key, setting_value) VALUES ('fb_app_id', '');
INSERT OR IGNORE INTO settings (setting_key, setting_value) VALUES ('fb_app_secret', '');

-- Facebook Connections (múltiplas contas via OAuth)
CREATE TABLE IF NOT EXISTS fb_connections (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    fb_user_id VARCHAR(50) UNIQUE NOT NULL,
    fb_user_name VARCHAR(255),
    fb_email VARCHAR(255),
    access_token TEXT NOT NULL,
    token_expires_at DATETIME,
    ad_accounts TEXT DEFAULT '[]',
    selected_accounts TEXT DEFAULT '[]',
    status VARCHAR(20) DEFAULT 'active',
    connected_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Google OAuth settings
INSERT OR IGNORE INTO settings (setting_key, setting_value) VALUES ('google_oauth_client_id', '');
INSERT OR IGNORE INTO settings (setting_key, setting_value) VALUES ('google_oauth_client_secret', '');

-- Google Connections (múltiplas contas via OAuth - Google Ads + GAM)
CREATE TABLE IF NOT EXISTS google_connections (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    google_user_id VARCHAR(100) UNIQUE NOT NULL,
    google_user_name VARCHAR(255),
    google_email VARCHAR(255),
    google_avatar VARCHAR(500),
    refresh_token TEXT NOT NULL,
    access_token TEXT,
    token_expires_at DATETIME,
    gads_accounts TEXT DEFAULT '[]',
    gads_selected TEXT DEFAULT '[]',
    gam_networks TEXT DEFAULT '[]',
    gam_selected TEXT DEFAULT '[]',
    status VARCHAR(20) DEFAULT 'active',
    connected_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- GA4 Sessions (sessões por campanha/data vindas do Google Analytics 4)
CREATE TABLE IF NOT EXISTS ga4_sessions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    date DATE NOT NULL,
    campaign_id VARCHAR(50) NOT NULL,
    utm_source VARCHAR(100) DEFAULT 'facebook',
    sessions INTEGER DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(date, campaign_id, utm_source)
);

-- GA4 Settings
INSERT OR IGNORE INTO settings (setting_key, setting_value) VALUES ('ga4_property_id', '');
INSERT OR IGNORE INTO settings (setting_key, setting_value) VALUES ('ga4_utm_source', 'facebook');

INSERT OR IGNORE INTO fonte_dados_status (nome) VALUES ('Pendente');
INSERT OR IGNORE INTO fonte_dados_status (nome) VALUES ('Em Andamento');
INSERT OR IGNORE INTO fonte_dados_status (nome) VALUES ('Concluído');
INSERT OR IGNORE INTO fonte_dados_status (nome) VALUES ('Arquivado');

INSERT OR IGNORE INTO fonte_dados_prioridades (nome) VALUES ('0. Urgente');
INSERT OR IGNORE INTO fonte_dados_prioridades (nome) VALUES ('1. Alta');
INSERT OR IGNORE INTO fonte_dados_prioridades (nome) VALUES ('2. Média');
INSERT OR IGNORE INTO fonte_dados_prioridades (nome) VALUES ('3. Baixa');

INSERT OR IGNORE INTO fonte_dados_responsaveis (nome) VALUES ('Letícia');
INSERT OR IGNORE INTO fonte_dados_responsaveis (nome) VALUES ('Carla');
INSERT OR IGNORE INTO fonte_dados_responsaveis (nome) VALUES ('Cris');
INSERT OR IGNORE INTO fonte_dados_responsaveis (nome) VALUES ('Will');
INSERT OR IGNORE INTO fonte_dados_responsaveis (nome) VALUES ('Arilaine');
INSERT OR IGNORE INTO fonte_dados_responsaveis (nome) VALUES ('Carol');
INSERT OR IGNORE INTO fonte_dados_responsaveis (nome) VALUES ('Sam');

INSERT OR IGNORE INTO impostos_config (nome, percentual) VALUES ('Facebook', 0.125);
INSERT OR IGNORE INTO impostos_config (nome, percentual) VALUES ('Outros Impostos', 0.16);
