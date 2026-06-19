<?php
/**
 * ============================================
 *  BÚSSOLA DO TRÁFEGO — SETUP AUTOMÁTICO
 *  Suba este arquivo para public_html/ e acesse no navegador.
 *  Ele configura tudo e se auto-deleta no final.
 * ============================================
 */
error_reporting(E_ALL & ~E_DEPRECATED);
set_time_limit(120);

$baseDir = __DIR__;
$results = [];
$errors = [];

// =============================================
// 1. CRIAR/CORRIGIR .htaccess
// =============================================
$htaccess = <<<'HTACCESS'
# === Bússola do Tráfego — .htaccess ===
RewriteEngine On

# Proteção: bloquear acesso ao banco de dados
<FilesMatch "\.(sqlite|db)$">
    Order Allow,Deny
    Deny from all
</FilesMatch>

# Proteção: bloquear diretórios sensíveis
RewriteRule ^database/ - [F,L]
RewriteRule ^config/ - [F,L]
RewriteRule ^includes/ - [F,L]

# Bloquear acesso direto a CLI scripts
RewriteRule ^cli_ - [F,L]

# PHP settings
php_value upload_max_filesize 20M
php_value post_max_size 25M
php_value max_execution_time 300
php_value memory_limit 256M
HTACCESS;

if (file_put_contents($baseDir . '/.htaccess', $htaccess)) {
    $results[] = '✅ .htaccess criado/atualizado com proteções de segurança';
} else {
    $errors[] = '❌ Não foi possível criar .htaccess';
}

// =============================================
// 2. CRIAR DIRETÓRIOS NECESSÁRIOS
// =============================================
$dirs = ['database', 'uploads', 'config'];
foreach ($dirs as $dir) {
    $path = $baseDir . '/' . $dir;
    if (!is_dir($path)) {
        mkdir($path, 0755, true);
        $results[] = "✅ Pasta {$dir}/ criada";
    } else {
        $results[] = "✅ Pasta {$dir}/ já existe";
    }
    @chmod($path, 0755);
}

// =============================================
// 3. INICIALIZAR BANCO DE DADOS
// =============================================
$dbPath = $baseDir . '/database/bussola.sqlite';
$schemaPath = $baseDir . '/database/schema.sql';

try {
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA journal_mode=WAL');
    $pdo->exec('PRAGMA foreign_keys=ON');

    if (file_exists($schemaPath)) {
        $sql = file_get_contents($schemaPath);
        $pdo->exec($sql);
        $results[] = '✅ Banco de dados inicializado com todas as tabelas';
    } else {
        $errors[] = '❌ Arquivo schema.sql não encontrado em database/';
    }
    
    @chmod($dbPath, 0664);
    $results[] = '✅ Permissões do banco ajustadas';
} catch (Exception $e) {
    $errors[] = '❌ Erro no banco: ' . $e->getMessage();
}

// =============================================
// 4. GERAR TOKEN DO CRON
// =============================================
$cronToken = '';
try {
    $existing = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'cron_secret_token'")->fetch(PDO::FETCH_ASSOC);
    
    if ($existing && !empty($existing['setting_value'])) {
        $cronToken = $existing['setting_value'];
        $results[] = '✅ Token do cron já existe';
    } else {
        $cronToken = bin2hex(random_bytes(16));
        $stmt = $pdo->prepare("INSERT OR REPLACE INTO settings (setting_key, setting_value) VALUES ('cron_secret_token', ?)");
        $stmt->execute([$cronToken]);
        $results[] = '✅ Token do cron gerado';
    }
} catch (Exception $e) {
    $errors[] = '❌ Erro ao gerar token: ' . $e->getMessage();
}

// =============================================
// 5. DETECTAR CAMINHOS DO SERVIDOR
// =============================================
$docRoot = $_SERVER['DOCUMENT_ROOT'] ?? $baseDir;
$phpBin = PHP_BINARY ?: '/usr/bin/php';
$cronCmd = "public_html/api/cron.php --token={$cronToken}";

// Try to figure out the home path from DOCUMENT_ROOT
$homePath = '';
if (preg_match('#^(/home/[^/]+)/#', $docRoot, $m)) {
    $homePath = $m[1];
    $cronCmd = str_replace($homePath . '/', '', $docRoot) . "/api/cron.php --token={$cronToken}";
}

$results[] = "✅ Caminho detectado: {$docRoot}";

// =============================================
// 6. REMOVER install.php E test_gam.php
// =============================================
$filesToDelete = ['install.php', 'test_gam.php'];
foreach ($filesToDelete as $f) {
    $fp = $baseDir . '/' . $f;
    if (file_exists($fp)) {
        @unlink($fp);
        $results[] = "✅ {$f} removido (segurança)";
    }
}

// =============================================
// OUTPUT — PÁGINA DE RESULTADO
// =============================================
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Setup — Bússola do Tráfego</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: 'Segoe UI', system-ui, sans-serif; 
            background: #0f1923; color: #e0e6ed; 
            padding: 40px 20px; line-height: 1.6;
        }
        .container { max-width: 700px; margin: 0 auto; }
        h1 { color: #3b82f6; margin-bottom: 8px; font-size: 28px; }
        h2 { color: #8b95b0; font-size: 14px; margin-bottom: 30px; font-weight: normal; }
        .section { 
            background: #1a2332; border-radius: 12px; 
            padding: 24px; margin-bottom: 20px;
            border: 1px solid #253141;
        }
        .section h3 { color: #3b82f6; margin-bottom: 16px; font-size: 16px; }
        .result { padding: 6px 0; font-size: 14px; }
        .error { color: #ef4444; }
        .cron-box {
            background: #0d1520; border: 1px solid #3b82f6; 
            border-radius: 8px; padding: 16px; margin: 12px 0;
            font-family: monospace; font-size: 13px; 
            word-break: break-all; color: #10b981;
        }
        .warning { 
            background: #2d1b00; border: 1px solid #f59e0b; 
            border-radius: 8px; padding: 16px; margin: 16px 0;
            font-size: 13px; color: #fbbf24;
        }
        .btn {
            display: inline-block; padding: 12px 24px;
            background: #3b82f6; color: white; border: none;
            border-radius: 8px; font-size: 14px; cursor: pointer;
            text-decoration: none; margin-top: 16px;
        }
        .btn:hover { background: #2563eb; }
        .btn-red { background: #ef4444; }
        .btn-red:hover { background: #dc2626; }
        .token-box {
            background: #0d1520; border: 2px solid #10b981;
            border-radius: 8px; padding: 16px; margin: 12px 0;
            font-family: monospace; font-size: 18px; text-align: center;
            color: #10b981; letter-spacing: 1px;
        }
    </style>
</head>
<body>
<div class="container">
    <h1>🧭 Bússola do Tráfego — Setup</h1>
    <h2>Configuração automática concluída</h2>

    <div class="section">
        <h3>📋 Resultado da Instalação</h3>
        <?php foreach ($results as $r): ?>
            <div class="result"><?= $r ?></div>
        <?php endforeach; ?>
        <?php foreach ($errors as $e): ?>
            <div class="result error"><?= $e ?></div>
        <?php endforeach; ?>
    </div>

    <?php if ($cronToken): ?>
    <div class="section">
        <h3>🔑 Token do Cron (SALVE ISSO!)</h3>
        <div class="token-box"><?= $cronToken ?></div>
        
        <h3 style="margin-top:20px;">⏰ Comando para o Cron Job da Hostinger</h3>
        <p style="font-size:13px;color:#8b95b0;margin-bottom:8px;">
            Cole este comando no campo "Comandos a rodar" do Cron Jobs:
        </p>
        <div class="cron-box"><?= htmlspecialchars($cronCmd) ?></div>
        
        <p style="font-size:12px;color:#8b95b0;margin-top:12px;">
            <strong>Configuração:</strong> Minuto = Cada 30 min | Hora/Dia/Mês/Semana = Todos
        </p>
    </div>
    <?php endif; ?>

    <div class="warning">
        ⚠️ <strong>IMPORTANTE:</strong> Após anotar o token acima, clique no botão abaixo 
        para <strong>deletar este arquivo de setup</strong> (segurança). 
        Ou delete manualmente pelo Gerenciador de Arquivos.
    </div>

    <div class="section">
        <h3>📝 Próximos Passos</h3>
        <div class="result">1. Copie o token e comando do cron acima</div>
        <div class="result">2. Configure o Cron Job no hPanel → Avançado → Cron Jobs</div>
        <div class="result">3. Delete este arquivo (botão abaixo)</div>
        <div class="result">4. Acesse o painel e troque a senha padrão (admin / password)</div>
        <div class="result">5. Configure o Token do Facebook e GAM nas Configurações</div>
    </div>

    <div style="display:flex;gap:12px;margin-top:20px;">
        <a href="?delete_setup=1" class="btn btn-red" 
           onclick="return confirm('Tem certeza? Este arquivo será deletado permanentemente.')">
            🗑️ Deletar setup.php (recomendado)
        </a>
        <a href="/" class="btn">🏠 Ir para o Painel</a>
    </div>
</div>
</body>
</html>
<?php
// =============================================
// AUTO-DELETE
// =============================================
if (isset($_GET['delete_setup'])) {
    @unlink(__FILE__);
    header('Location: /');
    exit;
}
