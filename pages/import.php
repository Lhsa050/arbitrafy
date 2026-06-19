<?php
/**
 * Import CSV Page
 */

$type = $_GET['type'] ?? '';
$message = '';
$error = '';

$importTypes = [
    'fb_campaigns' => ['name' => 'Campanhas Facebook', 'table' => 'fb_campaigns'],
    'revenue' => ['name' => 'Revenue GAM', 'table' => 'revenue'],
    'receita_programatica' => ['name' => 'Receita Programática', 'table' => 'receita_programatica'],
    'google_ads' => ['name' => 'Google Ads', 'table' => 'google_ads'],
    'compass_daily' => ['name' => 'Compass Daily', 'table' => 'compass_daily'],
    'plano_gastos' => ['name' => 'Plano de Gastos', 'table' => 'plano_gastos'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    $uploadType = $_POST['import_type'] ?? '';

    if (!isset($importTypes[$uploadType])) {
        $error = 'Tipo de importação inválido.';
    } else {
        $file = $_FILES['csv_file'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $error = 'Erro no upload do arquivo.';
        } else {
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['csv', 'tsv', 'txt'])) {
                $error = 'Formato inválido. Use CSV, TSV ou TXT.';
            } else {
                $uploadDir = UPLOADS_PATH;
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
                $uploadPath = $uploadDir . '/' . uniqid() . '.' . $ext;
                move_uploaded_file($file['tmp_name'], $uploadPath);

                try {
                    $delimiter = $ext === 'tsv' ? "\t" : ',';
                    // Try to auto-detect delimiter
                    $firstLine = fgets(fopen($uploadPath, 'r'));
                    if (substr_count($firstLine, "\t") > substr_count($firstLine, ',')) {
                        $delimiter = "\t";
                    } elseif (substr_count($firstLine, ';') > substr_count($firstLine, ',')) {
                        $delimiter = ';';
                    }

                    $rows = csvToArray($uploadPath, $delimiter);
                    $imported = 0;

                    foreach ($rows as $row) {
                        $row = array_map('trim', $row);
                        $insertData = mapCsvRow($uploadType, $row);
                        if ($insertData) {
                            try {
                                $table = $importTypes[$uploadType]['table'];
                                insert($table, $insertData);
                                $imported++;
                            } catch (Exception $e) {
                                // Skip duplicates
                            }
                        }
                    }

                    $message = "✅ Importados {$imported} de " . count($rows) . " registros para {$importTypes[$uploadType]['name']}.";

                    // After importing revenue, auto-cross-reference with campaigns
                    if ($uploadType === 'revenue') {
                        crossReferenceCampaigns();
                        $message .= " Cruzamento com campanhas Facebook atualizado.";
                    }

                    // After importing compass, recalculate
                    if ($uploadType === 'compass_daily') {
                        $message .= " Dashboard atualizado.";
                    }

                } catch (Exception $e) {
                    $error = 'Erro ao processar CSV: ' . $e->getMessage();
                }

                @unlink($uploadPath);
            }
        }
    }
}

function mapCsvRow($type, $row) {
    // Normalize keys to lowercase
    $row = array_change_key_case($row, CASE_LOWER);

    switch ($type) {
        case 'revenue':
            $date = $row['date'] ?? $row['data'] ?? null;
            $campaignId = $row['campaign_id'] ?? $row['campaign'] ?? null;
            $utm = $row['utm_campaign'] ?? $row['campaign'] ?? '';
            $receita = $row['receita'] ?? $row['revenue'] ?? $row['receita_usd'] ?? $row['revenue_usd'] ?? 0;

            // Strip utm_campaign= prefix
            if (strpos($utm, 'utm_campaign=') !== false) {
                $campaignId = str_replace('utm_campaign=', '', $utm);
            }

            if (!$date || !$campaignId) return null;
            return [
                'date' => date('Y-m-d', strtotime($date)),
                'campaign_id' => $campaignId,
                'utm_campaign' => $utm,
                'receita_usd' => (float)$receita,
            ];

        case 'fb_campaigns':
            $date = $row['date'] ?? $row['data'] ?? $row['date_start'] ?? null;
            if (!$date) return null;
            return [
                'date' => date('Y-m-d', strtotime($date)),
                'account_name' => $row['account'] ?? $row['conta'] ?? $row['account_name'] ?? 'Default',
                'campaign_id' => $row['campaign_id'] ?? $row['id campanha'] ?? '',
                'campaign_name' => $row['campaign_name'] ?? $row['campanha'] ?? '',
                'investimento' => (float)($row['spend'] ?? $row['investimento'] ?? $row['cost'] ?? 0),
                'impressoes' => (int)($row['impressions'] ?? $row['impressões'] ?? $row['impressoes'] ?? 0),
                'cliques' => (int)($row['clicks'] ?? $row['cliques'] ?? $row['inline_link_clicks'] ?? 0),
                'cpc_ads' => (float)($row['cpc'] ?? $row['cpc_ads'] ?? $row['cost_per_inline_link_click'] ?? 0),
                'ctr_ads' => (float)($row['ctr'] ?? $row['ctr_ads'] ?? $row['inline_link_click_ctr'] ?? 0),
            ];

        case 'receita_programatica':
            $date = $row['date'] ?? $row['data'] ?? null;
            if (!$date) return null;
            return [
                'date' => date('Y-m-d', strtotime($date)),
                'day_of_week' => $row['day of the week'] ?? $row['day_of_week'] ?? '',
                'impressions' => (int)($row['impressions'] ?? $row[' impressions'] ?? 0),
                'clicks' => (int)($row['clicks'] ?? $row[' clicks'] ?? 0),
                'ctr' => (float)($row['ctr'] ?? $row[' ctr'] ?? 0),
                'revenue_usd' => (float)($row['revenue'] ?? $row['revenue ($)'] ?? $row[' revenue ($)'] ?? 0),
                'avg_ecpm' => (float)($row['ecpm'] ?? $row['average ecpm ($)'] ?? $row[' average ecpm ($)'] ?? 0),
                'ad_requests' => (int)($row['ad requests'] ?? $row[' ad requests'] ?? 0),
                'match_rate' => (float)($row['match rate'] ?? $row[' match rate'] ?? 0),
                'views' => (int)($row['views'] ?? $row['pageviews'] ?? 0),
                'sessions' => (int)($row['sessions'] ?? 0),
                'bounce_rate' => (float)($row['bounce rate'] ?? $row['bounce_rate'] ?? 0),
            ];

        case 'google_ads':
            $date = $row['date'] ?? $row['data'] ?? null;
            if (!$date) return null;
            return [
                'date' => date('Y-m-d', strtotime($date)),
                'campaign_id' => $row['campaign_id'] ?? '',
                'campaign_name' => $row['campaign_name'] ?? $row['campaign'] ?? '',
                'cost' => (float)($row['cost'] ?? $row['spend'] ?? 0),
                'impressions' => (int)($row['impressions'] ?? 0),
                'clicks' => (int)($row['clicks'] ?? 0),
                'avg_cpc' => (float)($row['avg_cpc'] ?? $row['cpc'] ?? 0),
                'ctr' => (float)($row['ctr'] ?? 0),
                'status' => $row['status'] ?? 'Ativo',
            ];

        case 'compass_daily':
            $date = $row['date'] ?? $row['data'] ?? $row['dia'] ?? null;
            if (!$date) return null;
            $d = date('Y-m-d', strtotime($date));
            return [
                'year' => (int)date('Y', strtotime($d)),
                'date' => $d,
                'month_name' => getMonthName((int)date('n', strtotime($d))),
                'investimento' => (float)($row['investimento'] ?? $row['invest'] ?? 0),
                'receita_usd' => (float)($row['receita'] ?? $row['receita ($)'] ?? $row['receita_usd'] ?? 0),
                'retencao' => (float)($row['retencao'] ?? $row['retenção'] ?? 0),
                'lucro_bruto' => (float)($row['lucro bruto'] ?? $row['lucro_bruto'] ?? 0),
                'roi_bruto' => (float)($row['roi bruto'] ?? $row['roi_bruto'] ?? 0),
                'imposto' => (float)($row['imposto'] ?? 0),
                'custo_fixo' => (float)($row['custo fixo'] ?? $row['custo_fixo'] ?? $row['custo fixo mensal'] ?? 0),
                'lucro_liquido' => (float)($row['lucro líquido'] ?? $row['lucro_liquido'] ?? 0),
                'roi_liquido' => (float)($row['roi líquido'] ?? $row['roi_liquido'] ?? 0),
            ];

        default:
            return null;
    }
}

function crossReferenceCampaigns() {
    // Update fb_campaigns with revenue data from revenue table
    $revenues = fetchAll("
        SELECT date, campaign_id, SUM(receita_usd) as total_receita
        FROM revenue GROUP BY date, campaign_id
    ");

    $cotacao = (float)getSetting('cotacao_dolar', '5.80');

    foreach ($revenues as $r) {
        $receitaBrl = $r['total_receita'] * $cotacao;
        $campaign = fetchOne("
            SELECT id, investimento FROM fb_campaigns
            WHERE date = ? AND campaign_id = ?
        ", [$r['date'], $r['campaign_id']]);

        if ($campaign) {
            $invest = (float)$campaign['investimento'];
            $roas = $invest > 0 ? (($receitaBrl - $invest) / $invest) * 100 : 0;
            $profit = $receitaBrl - $invest;
            $roi = $invest > 0 ? ($receitaBrl - $invest) / $invest : 0;

            update('fb_campaigns', [
                'receita_usd' => $r['total_receita'],
                'receita_brl' => $receitaBrl,
                'roas' => $roas,
                'profit' => $profit,
                'roi' => $roi,
            ], 'id = ?', [$campaign['id']]);
        }
    }
}
?>

<?php if ($message): ?><div class="alert alert-success"><?= $message ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-error"><?= $error ?></div><?php endif; ?>

<div class="two-columns">
    <!-- Upload Area -->
    <div>
        <div class="chart-container">
            <h3 class="chart-title" style="margin-bottom:20px;">📤 Importar Arquivo CSV</h3>

            <form method="POST" enctype="multipart/form-data" id="importForm">
                <div class="form-group">
                    <label class="form-label">Tipo de Dado</label>
                    <select name="import_type" class="form-select" required>
                        <option value="">Selecionar...</option>
                        <?php foreach ($importTypes as $key => $info): ?>
                        <option value="<?= $key ?>" <?= $type === $key ? 'selected' : '' ?>><?= $info['name'] ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="upload-area" id="uploadArea" onclick="document.getElementById('csvFile').click()">
                    <div class="upload-area-icon">📁</div>
                    <div class="upload-area-text">Clique ou arraste um arquivo CSV aqui</div>
                    <div class="upload-area-hint">Formatos aceitos: .csv, .tsv, .txt</div>
                    <input type="file" name="csv_file" id="csvFile" accept=".csv,.tsv,.txt" style="display:none;" required>
                </div>

                <div id="fileName" style="margin-top:8px;font-size:12px;color:var(--accent);display:none;"></div>

                <button type="submit" class="btn btn-primary" style="margin-top:16px;width:100%;">📤 Importar</button>
            </form>
        </div>
    </div>

    <!-- Instructions -->
    <div>
        <div class="chart-container">
            <h3 class="chart-title" style="margin-bottom:20px;">📋 Instruções</h3>

            <div class="section">
                <h4 style="color:var(--accent);font-size:13px;margin-bottom:8px;">Revenue GAM</h4>
                <p style="font-size:12px;color:var(--text-secondary);line-height:1.8;">
                    Exporte o relatório do GAM com as colunas:<br>
                    <code style="background:var(--bg-input);padding:2px 6px;border-radius:4px;">Date, campaign (utm_campaign), receita</code><br>
                    O sistema identifica automaticamente o campaign_id do utm_campaign.
                </p>
            </div>

            <div class="nav-divider"></div>

            <div class="section">
                <h4 style="color:var(--accent);font-size:13px;margin-bottom:8px;">Campanhas Facebook</h4>
                <p style="font-size:12px;color:var(--text-secondary);line-height:1.8;">
                    Pode importar CSV ou usar o botão "Sincronizar FB" na página de Campanhas.<br>
                    Colunas esperadas: <code style="background:var(--bg-input);padding:2px 6px;border-radius:4px;">date, campaign_id, campaign_name, spend, impressions, clicks</code>
                </p>
            </div>

            <div class="nav-divider"></div>

            <div class="section">
                <h4 style="color:var(--accent);font-size:13px;margin-bottom:8px;">Compass Daily</h4>
                <p style="font-size:12px;color:var(--text-secondary);line-height:1.8;">
                    Colunas: <code style="background:var(--bg-input);padding:2px 6px;border-radius:4px;">date, investimento, receita, lucro_bruto, roi_bruto, imposto, lucro_liquido, roi_liquido</code>
                </p>
            </div>

            <div class="nav-divider"></div>

            <div class="section">
                <h4 style="color:var(--accent);font-size:13px;margin-bottom:8px;">Auto-detecção</h4>
                <p style="font-size:12px;color:var(--text-secondary);line-height:1.8;">
                    O sistema detecta automaticamente o delimitador (vírgula, tab ou ponto-e-vírgula) e faz o mapeamento das colunas.
                </p>
            </div>
        </div>
    </div>
</div>

<script>
const uploadArea = document.getElementById('uploadArea');
const csvFile = document.getElementById('csvFile');
const fileNameDiv = document.getElementById('fileName');

csvFile.addEventListener('change', function() {
    if (this.files[0]) {
        fileNameDiv.textContent = '📎 ' + this.files[0].name;
        fileNameDiv.style.display = 'block';
    }
});

uploadArea.addEventListener('dragover', e => { e.preventDefault(); uploadArea.classList.add('dragover'); });
uploadArea.addEventListener('dragleave', () => uploadArea.classList.remove('dragover'));
uploadArea.addEventListener('drop', e => {
    e.preventDefault();
    uploadArea.classList.remove('dragover');
    csvFile.files = e.dataTransfer.files;
    if (e.dataTransfer.files[0]) {
        fileNameDiv.textContent = '📎 ' + e.dataTransfer.files[0].name;
        fileNameDiv.style.display = 'block';
    }
});
</script>
