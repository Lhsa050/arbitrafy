<?php
/**
 * Configura todas as settings de uma vez
 * Suba para public_html/ e acesse no navegador
 * DELETE DEPOIS DE USAR
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

$dbFile = __DIR__ . '/database/bussola.sqlite';
$pdo = new PDO('sqlite:' . $dbFile);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// === CONFIGURAÇÕES CORRETAS ===
$settings = [
    'fb_access_token' => 'EAArKHWzhl2ABQyjXGdIVa6sZB9EMTMpZAoEcr18sVgRsiYWzYVXa29a7r9xfJDGjDdbwR97BCbI1sgXQOb1ZBgQw8a6FerfcGuZCXEClDvHDEXbSHAwhP6RtXGJgv55iBYKzbyjeEp1heDwi8nk3ieq9lnjn7vPA8zwJgSTqwMHcVbRWuFQ25jZCQxcZCl9QZDZD',
    'fb_api_version' => 'v24.0',
    'fb_ad_accounts' => json_encode([
        ['id' => 'act_897183466804908', 'name' => 'ARBITRAGEM']
    ]),
    'gam_network_code' => '23341791426',
    'gam_service_account_json' => 'config/gam-service-account.json',
    'cotacao_dolar' => '5.30',
    'imposto_facebook' => '12.5',
    'imposto_outros' => '16.00',
];

echo "<h2>⚙️ Configurando Settings</h2><pre>";

$stmt = $pdo->prepare("INSERT OR REPLACE INTO settings (setting_key, setting_value, updated_at) VALUES (?, ?, datetime('now'))");

foreach ($settings as $key => $value) {
    $stmt->execute([$key, $value]);
    $display = strlen($value) > 60 ? substr($value, 0, 60) . '...' : $value;
    echo "✅ {$key} = {$display}\n";
}

echo "\n<b>PRONTO! Todas as configurações foram salvas.</b>\n";
echo "\n<a href='/?page=settings'>👉 Ir para Configurações (verificar)</a>\n";
echo "<a href='/?page=dashboard'>👉 Ir para Dashboard</a>\n";
echo "\n⚠️ DELETE este arquivo (apply_settings.php) depois de verificar!\n";
echo "</pre>";
