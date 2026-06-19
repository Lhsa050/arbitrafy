<?php
/**
 * Conectar GA4 — Google Analytics 4
 * Configuração do Property ID e utm_source para sincronização de sessões
 */

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'save_ga4_settings') {
        $propId = trim($_POST['ga4_property_id'] ?? '');
        // Clean: remove "properties/" prefix if pasted
        $propId = preg_replace('/^properties\//', '', $propId);
        setSetting('ga4_property_id', $propId);
        setSetting('ga4_utm_source', trim($_POST['ga4_utm_source'] ?? 'facebook'));
        $message = '✅ Configurações GA4 salvas!';
    }
}

// Load settings
$ga4PropertyId = getSetting('ga4_property_id', '');
$ga4UtmSource = getSetting('ga4_utm_source', 'facebook');

// Check Google OAuth connection
$hasGoogleConnection = false;
$googleUserName = '';
try {
    $conn = fetchOne("SELECT google_user_name FROM google_connections WHERE status = 'active' LIMIT 1");
    if ($conn) {
        $hasGoogleConnection = true;
        $googleUserName = $conn['google_user_name'] ?? '';
    }
} catch (Exception $e) {}

// Ensure ga4_sessions table exists
ensureGA4Table();

// Stats
try {
    $ga4Stats = fetchOne("SELECT COUNT(*) as total_records, COUNT(DISTINCT campaign_id) as total_campaigns, SUM(sessions) as total_sessions, MIN(date) as first_date, MAX(date) as last_date FROM ga4_sessions");
} catch (Exception $e) {
    $ga4Stats = ['total_records' => 0, 'total_campaigns' => 0, 'total_sessions' => 0, 'first_date' => null, 'last_date' => null];
}
?>

<?php if ($message): ?><div class="alert alert-success"><?= $message ?></div><?php endif; ?>

<!-- Connection Status -->
<div class="cards-grid" style="margin-bottom:24px;">
    <div class="card <?= $hasGoogleConnection ? 'card-green' : 'card-red' ?>">
        <div class="card-icon"><?= $hasGoogleConnection ? '✅' : '❌' ?></div>
        <div class="card-label">Conta Google</div>
        <div class="card-value" style="font-size:18px;">
            <?= $hasGoogleConnection ? 'Conectada' : 'Não conectada' ?>
        </div>
        <?php if ($googleUserName): ?>
        <div class="card-change"><?= sanitize($googleUserName) ?></div>
        <?php endif; ?>
    </div>
    <div class="card <?= !empty($ga4PropertyId) ? 'card-blue' : 'card-red' ?>">
        <div class="card-icon">📊</div>
        <div class="card-label">Property ID</div>
        <div class="card-value" style="font-size:18px;">
            <?= !empty($ga4PropertyId) ? sanitize($ga4PropertyId) : 'Não configurado' ?>
        </div>
    </div>
    <div class="card">
        <div class="card-icon">📈</div>
        <div class="card-label">Sessões Importadas</div>
        <div class="card-value" style="font-size:22px;"><?= formatNumber($ga4Stats['total_sessions'] ?? 0) ?></div>
        <div class="card-change"><?= ($ga4Stats['total_campaigns'] ?? 0) ?> campanhas</div>
    </div>
    <div class="card">
        <div class="card-label">Testar / Sincronizar</div>
        <div style="padding-top:8px;display:flex;gap:8px;flex-wrap:wrap;">
            <button class="btn btn-secondary btn-sm" onclick="testGA4()" id="btnTestGa4">🧪 Testar</button>
            <button class="btn btn-primary btn-sm" onclick="syncGA4()" id="btnSyncGa4">🔄 Sincronizar</button>
        </div>
        <div id="ga4Result" style="margin-top:8px;font-size:12px;"></div>
    </div>
</div>

<!-- Configuration -->
<div class="chart-container">
    <h3 class="chart-title" style="margin-bottom:16px;">⚙️ Configuração do Google Analytics 4</h3>
    <p style="font-size:12px;color:var(--text-muted);margin-bottom:16px;">
        Configure o <strong>Property ID</strong> do GA4 e a <strong>utm_source</strong> usada nas suas campanhas do Facebook.
        A conexão usa a mesma conta Google já conectada em "Conectar Google Ads".
    </p>
    
    <?php if (!$hasGoogleConnection): ?>
    <div class="alert alert-warning" style="margin-bottom:16px;">
        ⚠️ <strong>Conta Google necessária.</strong> Vá em <a href="?page=connect-google" style="color:var(--accent);text-decoration:underline;">Conectar Google Ads</a> 
        e conecte sua conta Google primeiro. A mesma conexão será usada para o GA4.
    </div>
    <?php endif; ?>

    <form method="POST">
        <input type="hidden" name="action" value="save_ga4_settings">
        
        <div class="form-row">
            <div class="form-group">
                <label class="form-label">Property ID do GA4 <span style="color:var(--red);">*</span></label>
                <input type="text" name="ga4_property_id" class="form-input" value="<?= sanitize($ga4PropertyId) ?>" 
                       placeholder="Ex: 513642901" style="font-family:monospace;">
                <small style="color:var(--text-muted);font-size:11px;">
                    Encontrado no GA4 → Administrador → Detalhes da propriedade.<br>
                    Ou na URL do GA4: <code style="color:var(--green);font-size:10px;">analytics.google.com/.../<strong>p513642901</strong>/...</code> (número após "p")
                </small>
            </div>
            <div class="form-group">
                <label class="form-label">utm_source das campanhas</label>
                <input type="text" name="ga4_utm_source" class="form-input" value="<?= sanitize($ga4UtmSource) ?>" 
                       placeholder="facebook">
                <small style="color:var(--text-muted);font-size:11px;">
                    Valor exato da utm_source que você usa nos links do Facebook Ads.
                    O mais comum é <code style="color:var(--green);font-size:10px;">facebook</code>.
                </small>
            </div>
        </div>
        
        <button type="submit" class="btn btn-primary" style="margin-top:8px;">💾 Salvar Configurações</button>
    </form>
</div>

<!-- How it Works -->
<div class="chart-container">
    <h3 class="chart-title" style="margin-bottom:16px;">📖 Como Funciona</h3>
    <div style="font-size:13px;color:var(--text-secondary);line-height:1.8;">
        <p><strong>1.</strong> A sincronização puxa <strong>sessões</strong> do GA4 filtradas pela <strong>utm_source</strong> configurada</p>
        <p><strong>2.</strong> Cada sessão é agrupada por <strong>date</strong> + <strong>utm_campaign</strong> (campaign_id do Facebook)</p>
        <p><strong>3.</strong> Esse dado é usado para calcular o <strong>RPS</strong> (Revenue Per Session):</p>
        <div style="background:rgba(99,102,241,0.08);border:1px solid rgba(99,102,241,0.2);border-radius:8px;padding:16px;margin:12px 0;text-align:center;">
            <span style="font-size:18px;font-weight:700;color:var(--primary);">
                RPS = Receita GAM ÷ Sessões GA4
            </span>
        </div>
        <p><strong>4.</strong> As sessões vêm exatamente do tráfego da campanha do Facebook (mesma utm_source + utm_campaign)</p>
        <p><strong>5.</strong> A receita vem do GAM, que já puxa por utm_campaign</p>
    </div>
</div>

<!-- Session Data Preview -->
<?php if (($ga4Stats['total_records'] ?? 0) > 0): ?>
<div class="chart-container">
    <h3 class="chart-title" style="margin-bottom:16px;">📋 Últimas Sessões Importadas</h3>
    <?php
    $recentSessions = fetchAll("SELECT gs.date, gs.campaign_id, gs.sessions, gs.utm_source, fc.campaign_name
        FROM ga4_sessions gs
        LEFT JOIN (SELECT DISTINCT campaign_id, MIN(campaign_name) as campaign_name FROM fb_campaigns GROUP BY campaign_id) fc ON fc.campaign_id = gs.campaign_id
        ORDER BY gs.date DESC, gs.sessions DESC LIMIT 20");
    ?>
    <div class="table-scroll">
        <table>
            <thead>
                <tr>
                    <th>Data</th>
                    <th>Campaign ID</th>
                    <th>Campanha</th>
                    <th>utm_source</th>
                    <th>Sessões</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recentSessions as $s): ?>
                <tr>
                    <td><?= formatDate($s['date']) ?></td>
                    <td style="font-family:monospace;font-size:11px;"><?= $s['campaign_id'] ?></td>
                    <td title="<?= sanitize($s['campaign_name'] ?? '') ?>"><?= sanitize(mb_substr($s['campaign_name'] ?? '-', 0, 35)) ?></td>
                    <td><span class="badge badge-blue"><?= sanitize($s['utm_source']) ?></span></td>
                    <td><strong><?= formatNumber($s['sessions']) ?></strong></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- Scope Notice -->
<div class="chart-container">
    <h3 class="chart-title" style="margin-bottom:12px;">⚠️ Permissões Necessárias</h3>
    <div style="font-size:12px;color:var(--text-muted);line-height:1.7;">
        <p>O GA4 usa a mesma conexão OAuth do Google Ads. Se você conectou sua conta <strong>antes</strong> 
        da integração do GA4, pode ser necessário <strong>reconectar</strong> para autorizar o escopo adicional:</p>
        <p><code style="color:var(--green);font-size:11px;">https://www.googleapis.com/auth/analytics.readonly</code></p>
        <p>Para reconectar, vá em <a href="?page=connect-google" style="color:var(--accent);text-decoration:underline;">Conectar Google Ads</a> 
        e clique em "Conectar com Google" novamente.</p>
    </div>
</div>

<script>
async function testGA4() {
    const btn = document.getElementById('btnTestGa4');
    const result = document.getElementById('ga4Result');
    btn.disabled = true;
    btn.textContent = '⏳ Testando...';
    result.innerHTML = '';
    
    try {
        const res = await fetch('/api/ga4.php?action=test');
        const data = await res.json();
        
        if (data.success) {
            result.innerHTML = '<span style="color:#30d158;">✅ ' + data.message + '</span>';
        } else {
            result.innerHTML = '<span style="color:#ff453a;">❌ ' + (data.error || 'Erro desconhecido') + '</span>';
            if (data.hint) {
                result.innerHTML += '<br><span style="color:var(--text-muted);font-size:11px;">' + data.hint + '</span>';
            }
        }
    } catch (e) {
        result.innerHTML = '<span style="color:#ff453a;">❌ Erro: ' + e.message + '</span>';
    } finally {
        btn.disabled = false;
        btn.textContent = '🧪 Testar';
    }
}

async function syncGA4() {
    const btn = document.getElementById('btnSyncGa4');
    const result = document.getElementById('ga4Result');
    btn.disabled = true;
    btn.textContent = '⏳ Sincronizando...';
    result.innerHTML = '';
    
    try {
        const res = await fetch('/api/ga4.php?action=sync');
        const data = await res.json();
        
        if (data.success) {
            result.innerHTML = '<span style="color:#30d158;">✅ ' + data.message + '</span>';
            setTimeout(() => location.reload(), 1500);
        } else {
            result.innerHTML = '<span style="color:#ff453a;">❌ ' + (data.error || 'Erro desconhecido') + '</span>';
        }
    } catch (e) {
        result.innerHTML = '<span style="color:#ff453a;">❌ Erro: ' + e.message + '</span>';
    } finally {
        btn.disabled = false;
        btn.textContent = '🔄 Sincronizar';
    }
}
</script>
