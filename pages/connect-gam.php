<?php
/**
 * Conectar GAM — Service Account (JWT)
 * Configuração do Google Ad Manager via Service Account JSON
 */

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'save_gam_settings') {
        setSetting('gam_network_code', $_POST['gam_network_code'] ?? '');
        
        // Handle Service Account JSON file upload
        if (!empty($_FILES['gam_sa_file']['tmp_name'])) {
            $uploadDir = __DIR__ . '/../uploads/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
            
            $content = file_get_contents($_FILES['gam_sa_file']['tmp_name']);
            $json = json_decode($content, true);
            
            if ($json && isset($json['private_key']) && isset($json['client_email'])) {
                $fileName = 'gam_service_account_' . time() . '.json';
                $destPath = $uploadDir . $fileName;
                move_uploaded_file($_FILES['gam_sa_file']['tmp_name'], $destPath);
                setSetting('gam_service_account_path', 'uploads/' . $fileName);
                $message = '✅ Configurações GAM e Service Account salvos!';
            } else {
                $message = '❌ JSON inválido. O arquivo deve conter "private_key" e "client_email".';
            }
        } else {
            // Allow manual path entry
            if (!empty($_POST['gam_sa_path'])) {
                setSetting('gam_service_account_path', $_POST['gam_sa_path']);
            }
            $message = '✅ Configurações GAM salvas!';
        }
    }
    if ($action === 'add_gam_site') {
        $siteName = sanitize($_POST['site_name'] ?? '');
        $adUnitPattern = sanitize($_POST['ad_unit_pattern'] ?? '');
        if ($siteName && $adUnitPattern) {
            insert('gam_sites', ['site_name' => $siteName, 'ad_unit_pattern' => $adUnitPattern]);
            $message = '✅ Site GAM adicionado!';
        } else {
            $message = '❌ Preencha todos os campos.';
        }
    }
    if ($action === 'remove_gam_site') {
        delete('gam_sites', 'id = ?', [(int)$_POST['id']]);
        $message = '✅ Site GAM removido!';
    }
}

// Load data
$gamNetworkCode = getSetting('gam_network_code', '');
$gamSaPath = getSetting('gam_service_account_path', '');
if (empty($gamSaPath)) $gamSaPath = getSetting('gam_service_account_json', '');

// Validate SA file
$saStatus = 'not_configured';
$saEmail = '';
if (!empty($gamSaPath)) {
    $fullPath = realpath(__DIR__ . '/../' . $gamSaPath);
    if ($fullPath && file_exists($fullPath)) {
        $credentials = json_decode(file_get_contents($fullPath), true);
        if ($credentials && isset($credentials['private_key'])) {
            $saStatus = 'valid';
            $saEmail = $credentials['client_email'] ?? '';
        } else {
            $saStatus = 'invalid';
        }
    } else {
        $saStatus = 'not_found';
    }
}

// GAM Sites
try {
    getDB()->exec("CREATE TABLE IF NOT EXISTS gam_sites (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        site_name VARCHAR(255) NOT NULL,
        ad_unit_pattern VARCHAR(255) NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $gamSites = fetchAll("SELECT * FROM gam_sites ORDER BY site_name");
} catch (Exception $e) {
    $gamSites = [];
}
?>

<?php if ($message): ?><div class="alert alert-success"><?= $message ?></div><?php endif; ?>

<!-- Connection Status -->
<div class="cards-grid" style="margin-bottom:24px;">
    <div class="card <?= $saStatus === 'valid' ? 'card-green' : 'card-red' ?>">
        <div class="card-icon"><?= $saStatus === 'valid' ? '✅' : '❌' ?></div>
        <div class="card-label">Service Account</div>
        <div class="card-value" style="font-size:18px;">
            <?php if ($saStatus === 'valid'): ?>
                Conectado
            <?php elseif ($saStatus === 'not_found'): ?>
                Arquivo não encontrado
            <?php elseif ($saStatus === 'invalid'): ?>
                JSON inválido
            <?php else: ?>
                Não configurado
            <?php endif; ?>
        </div>
        <?php if ($saEmail): ?>
        <div class="card-change"><?= sanitize($saEmail) ?></div>
        <?php endif; ?>
    </div>
    <div class="card <?= !empty($gamNetworkCode) ? 'card-blue' : 'card-red' ?>">
        <div class="card-icon">🌐</div>
        <div class="card-label">Network Code</div>
        <div class="card-value" style="font-size:18px;">
            <?= !empty($gamNetworkCode) ? sanitize($gamNetworkCode) : 'Não configurado' ?>
        </div>
    </div>
    <div class="card">
        <div class="card-icon">🌐</div>
        <div class="card-label">Sites Cadastrados</div>
        <div class="card-value" style="font-size:22px;"><?= count($gamSites) ?></div>
    </div>
    <div class="card">
        <div class="card-label">Testar Conexão</div>
        <div style="padding-top:8px;">
            <button class="btn btn-primary btn-sm" onclick="testGAMConnection()" id="btnTestGam">🧪 Testar GAM</button>
        </div>
        <div id="gamTestResult" style="margin-top:8px;font-size:12px;"></div>
    </div>
</div>

<!-- 1. Service Account + Network Code -->
<div class="chart-container">
    <h3 class="chart-title" style="margin-bottom:16px;">🔑 Configuração do Service Account</h3>
    <p style="font-size:12px;color:var(--text-muted);margin-bottom:16px;">
        Para conectar ao Google Ad Manager, você precisa de um <strong>Service Account</strong> do Google Cloud. 
        <a href="https://console.cloud.google.com/iam-admin/serviceaccounts" target="_blank" style="color:var(--accent);text-decoration:underline;">Crie um aqui</a>, 
        habilite a <strong>Ad Manager API</strong> e adicione o e-mail do Service Account como usuário no GAM.
    </p>
    
    <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="action" value="save_gam_settings">
        
        <div class="form-row">
            <div class="form-group">
                <label class="form-label">Network Code</label>
                <input type="text" name="gam_network_code" class="form-input" value="<?= sanitize($gamNetworkCode) ?>" placeholder="Ex: 12345678">
                <small style="color:var(--text-muted);font-size:11px;">Encontrado em GAM → Admin → Configurações globais → Código de rede</small>
            </div>
        </div>
        
        <div class="form-group">
            <label class="form-label">Service Account JSON</label>
            <div class="upload-area" onclick="document.getElementById('saFileInput').click()" id="saUploadArea" style="padding:24px;">
                <input type="file" name="gam_sa_file" id="saFileInput" accept=".json" style="display:none;" onchange="handleSAFileSelect(this)">
                <?php if ($saStatus === 'valid'): ?>
                    <div style="color:var(--green);font-size:14px;margin-bottom:4px;">✅ Service Account configurado</div>
                    <div style="color:var(--text-muted);font-size:12px;"><?= sanitize($saEmail) ?></div>
                    <div style="color:var(--text-muted);font-size:11px;margin-top:4px;">Arquivo: <?= sanitize($gamSaPath) ?></div>
                    <div style="color:var(--text-secondary);font-size:11px;margin-top:8px;">Clique para substituir</div>
                <?php else: ?>
                    <div style="font-size:28px;margin-bottom:8px;opacity:0.5;">📄</div>
                    <div style="color:var(--text-secondary);font-size:13px;">Clique para selecionar o arquivo JSON do Service Account</div>
                    <div style="color:var(--text-muted);font-size:11px;margin-top:4px;">ou arraste o arquivo aqui</div>
                <?php endif; ?>
            </div>
            <div id="saFileName" style="margin-top:6px;font-size:12px;color:var(--accent);"></div>
        </div>

        <?php if (!empty($gamSaPath)): ?>
        <div class="form-group">
            <label class="form-label">Caminho Atual do JSON</label>
            <input type="text" name="gam_sa_path" class="form-input" value="<?= sanitize($gamSaPath) ?>" placeholder="uploads/service-account.json" style="font-family:monospace;font-size:12px;">
            <small style="color:var(--text-muted);font-size:11px;">Caminho relativo à raiz do projeto</small>
        </div>
        <?php endif; ?>
        
        <button type="submit" class="btn btn-primary" style="margin-top:8px;">💾 Salvar Configurações</button>
    </form>
</div>

<!-- 2. GAM Sites -->
<div class="chart-container">
    <h3 class="chart-title" style="margin-bottom:16px;">🌐 Sites GAM (Filtro por Site)</h3>
    <p style="font-size:12px;color:var(--text-muted);margin-bottom:12px;">
        Cadastre seus sites para filtrar receita por site quando o GAM tem múltiplos domínios. 
        O "Padrão Ad Unit" é o texto que identifica o site no nome do Ad Unit do GAM.
    </p>
    
    <?php if (!empty($gamSites)): ?>
    <div style="margin-bottom:16px;">
        <?php foreach ($gamSites as $site): ?>
        <div style="display:flex;justify-content:space-between;align-items:center;padding:10px 12px;border-bottom:1px solid var(--border);">
            <span style="display:flex;align-items:center;gap:10px;">
                <span class="badge badge-green"><?= sanitize($site['site_name']) ?></span>
                <span style="font-family:monospace;font-size:11px;color:var(--text-muted);">Padrão: <?= sanitize($site['ad_unit_pattern']) ?></span>
            </span>
            <form method="POST" style="display:inline;">
                <input type="hidden" name="action" value="remove_gam_site">
                <input type="hidden" name="id" value="<?= $site['id'] ?>">
                <button type="submit" class="btn btn-sm" style="color:var(--red);padding:2px 8px;" onclick="return confirm('Remover este site?')">✕</button>
            </form>
        </div>
        <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div style="text-align:center;padding:20px;color:var(--text-muted);font-size:13px;border:1px dashed var(--border);border-radius:var(--radius-sm);margin-bottom:16px;">
        Nenhum site cadastrado. Adicione sites para filtrar receita por domínio.
    </div>
    <?php endif; ?>

    <form method="POST">
        <input type="hidden" name="action" value="add_gam_site">
        <div class="form-row">
            <div class="form-group">
                <label class="form-label">Nome do Site</label>
                <input type="text" name="site_name" class="form-input" placeholder="meusite.com.br" required>
            </div>
            <div class="form-group">
                <label class="form-label">Padrão Ad Unit</label>
                <input type="text" name="ad_unit_pattern" class="form-input" placeholder="meusite ou /12345/meusite" required>
            </div>
        </div>
        <button type="submit" class="btn btn-secondary btn-sm">+ Adicionar Site</button>
    </form>
</div>

<!-- 3. Passo a Passo -->
<div class="chart-container">
    <h3 class="chart-title" style="margin-bottom:16px;">📖 Como Configurar</h3>
    <div style="font-size:13px;color:var(--text-secondary);line-height:1.8;">
        <p><strong>1.</strong> Acesse o <a href="https://console.cloud.google.com" target="_blank" style="color:var(--accent);">Google Cloud Console</a></p>
        <p><strong>2.</strong> Crie ou selecione um projeto</p>
        <p><strong>3.</strong> Ative a <strong>Google Ad Manager API</strong> em "APIs e Serviços"</p>
        <p><strong>4.</strong> Vá em <strong>IAM → Contas de serviço</strong> e crie uma conta de serviço</p>
        <p><strong>5.</strong> Gere uma <strong>chave JSON</strong> e faça upload acima</p>
        <p><strong>6.</strong> No <strong>Google Ad Manager</strong>, vá em Admin → Acesso e autorização → Usuários de rede de serviço</p>
        <p><strong>7.</strong> Adicione o e-mail do Service Account (ex: <code style="color:var(--green);font-size:12px;">nome@projeto.iam.gserviceaccount.com</code>)</p>
        <p><strong>8.</strong> Copie o <strong>Network Code</strong> de Admin → Configurações globais e cole acima</p>
    </div>
</div>

<script>
function handleSAFileSelect(input) {
    const label = document.getElementById('saFileName');
    if (input.files.length > 0) {
        label.textContent = '📄 ' + input.files[0].name + ' selecionado';
    }
}

async function testGAMConnection() {
    const btn = document.getElementById('btnTestGam');
    const result = document.getElementById('gamTestResult');
    btn.disabled = true;
    btn.textContent = '⏳ Testando...';
    result.innerHTML = '';
    
    try {
        const res = await fetch('api/sync.php?action=sync_gam');
        const data = await res.json();
        
        if (data.success) {
            result.innerHTML = '<span style="color:#30d158;">✅ ' + data.message + '</span>';
            setTimeout(() => location.reload(), 2000);
        } else {
            result.innerHTML = '<span style="color:#ff453a;">❌ ' + (data.error || 'Erro desconhecido') + '</span>';
        }
    } catch (e) {
        result.innerHTML = '<span style="color:#ff453a;">❌ Erro: ' + e.message + '</span>';
    } finally {
        btn.disabled = false;
        btn.textContent = '🧪 Testar GAM';
    }
}

// Drag & drop for SA upload
const uploadArea = document.getElementById('saUploadArea');
if (uploadArea) {
    uploadArea.addEventListener('dragover', e => { e.preventDefault(); uploadArea.classList.add('dragover'); });
    uploadArea.addEventListener('dragleave', () => uploadArea.classList.remove('dragover'));
    uploadArea.addEventListener('drop', e => {
        e.preventDefault();
        uploadArea.classList.remove('dragover');
        const input = document.getElementById('saFileInput');
        input.files = e.dataTransfer.files;
        handleSAFileSelect(input);
    });
}
</script>
