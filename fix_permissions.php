<?php
/**
 * Fix de permissões e teste de escrita no banco
 * Suba para public_html/ e acesse no navegador
 * DELETE DEPOIS DE USAR
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);
echo "<h2>🔧 Fix de Permissões</h2><pre>";

$dbDir = __DIR__ . '/database';
$dbFile = $dbDir . '/bussola.sqlite';

// 1. Checar se existe
echo "Banco existe: " . (file_exists($dbFile) ? "✅ SIM" : "❌ NÃO") . "\n";
echo "Pasta database existe: " . (is_dir($dbDir) ? "✅ SIM" : "❌ NÃO") . "\n";

// 2. Checar permissões atuais
echo "\nPermissões atuais:\n";
echo "  database/: " . substr(sprintf('%o', fileperms($dbDir)), -4) . " - Escrita: " . (is_writable($dbDir) ? "✅" : "❌") . "\n";
echo "  bussola.sqlite: " . substr(sprintf('%o', fileperms($dbFile)), -4) . " - Escrita: " . (is_writable($dbFile) ? "✅" : "❌") . "\n";

// 3. Tentar corrigir permissões
echo "\nCorrigindo permissões...\n";
@chmod($dbDir, 0775);
@chmod($dbFile, 0664);

// Tentar 0777 se ainda não tiver escrita
if (!is_writable($dbFile)) {
    @chmod($dbDir, 0777);
    @chmod($dbFile, 0666);
    echo "  Tentando permissões mais abertas...\n";
}

echo "  database/: " . substr(sprintf('%o', fileperms($dbDir)), -4) . " - Escrita: " . (is_writable($dbDir) ? "✅" : "❌") . "\n";
echo "  bussola.sqlite: " . substr(sprintf('%o', fileperms($dbFile)), -4) . " - Escrita: " . (is_writable($dbFile) ? "✅" : "❌") . "\n";

// 4. Testar escrita no banco
echo "\nTestando escrita no banco...\n";
try {
    $pdo = new PDO('sqlite:' . $dbFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Tentar inserir e ler
    $pdo->exec("INSERT OR REPLACE INTO settings (setting_key, setting_value) VALUES ('_test_write', 'ok_" . time() . "')");
    $row = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = '_test_write'")->fetch(PDO::FETCH_ASSOC);
    
    if ($row) {
        echo "  ✅ ESCRITA OK! Valor salvo: " . $row['setting_value'] . "\n";
        $pdo->exec("DELETE FROM settings WHERE setting_key = '_test_write'");
    }
    
    // Mostrar settings atuais
    echo "\nSettings atuais no banco:\n";
    $rows = $pdo->query("SELECT setting_key, CASE WHEN length(setting_value) > 50 THEN substr(setting_value,1,50)||'...' ELSE setting_value END as val FROM settings ORDER BY setting_key")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        echo "  {$r['setting_key']} = {$r['val']}\n";
    }
    
} catch (Exception $e) {
    echo "  ❌ ERRO: " . $e->getMessage() . "\n";
    echo "\n  SOLUÇÃO: No Gerenciador de Arquivos da Hostinger:\n";
    echo "  1. Clique com botão direito em database/ → Permissões → 777\n";
    echo "  2. Clique com botão direito em bussola.sqlite → Permissões → 666\n";
}

// 5. Verificar WAL files
$walFile = $dbFile . '-wal';
$shmFile = $dbFile . '-shm';
if (file_exists($walFile)) {
    echo "\nWAL file existe: " . filesize($walFile) . " bytes - Escrita: " . (is_writable($walFile) ? "✅" : "❌") . "\n";
    @chmod($walFile, 0666);
}
if (file_exists($shmFile)) {
    echo "SHM file existe: " . filesize($shmFile) . " bytes - Escrita: " . (is_writable($shmFile) ? "✅" : "❌") . "\n";
    @chmod($shmFile, 0666);
}

echo "\n<b>Se tudo estiver ✅, volte nas Configurações e tente salvar novamente!</b>\n";
echo "<b>IMPORTANTE: Delete este arquivo (fix_permissions.php) depois de usar.</b>\n";
echo "</pre>";
