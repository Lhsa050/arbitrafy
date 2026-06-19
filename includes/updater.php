<?php
/**
 * GitHub updater for ArbitraFy.
 *
 * Uses GitHub ZIP archives instead of shelling out to git so it works on
 * shared hosting plans where git/shell_exec are often unavailable.
 */

const UPDATER_DEFAULT_REPO = 'Lhsa050/arbitrafy';
const UPDATER_DEFAULT_BRANCH = 'main';

function updaterConfig(): array {
    return [
        'repo' => getSetting('update_repo', UPDATER_DEFAULT_REPO),
        'branch' => getSetting('update_branch', UPDATER_DEFAULT_BRANCH),
        'token' => getSetting('update_token', ''),
        'current_sha' => getSetting('update_current_sha', ''),
        'last_run_at' => getSetting('update_last_run_at', ''),
    ];
}

function updaterSaveConfig(string $repo, string $branch, string $token, bool $clearToken = false): array {
    $repo = updaterNormalizeRepo($repo);
    $branch = updaterNormalizeBranch($branch);

    setSetting('update_repo', $repo);
    setSetting('update_branch', $branch);

    if ($clearToken) {
        setSetting('update_token', '');
    } elseif ($token !== '') {
        setSetting('update_token', $token);
    }

    return updaterConfig();
}

function updaterNormalizeRepo(string $repo): string {
    $repo = trim($repo);
    $repo = preg_replace('#^https?://github\.com/#i', '', $repo);
    $repo = preg_replace('#\.git$#i', '', $repo);
    $repo = trim($repo, "/ \t\n\r\0\x0B");

    if (!preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/', $repo)) {
        throw new Exception('Repositorio invalido. Use o formato owner/repo.');
    }

    return $repo;
}

function updaterNormalizeBranch(string $branch): string {
    $branch = trim($branch);

    if (
        $branch === '' ||
        strlen($branch) > 150 ||
        str_contains($branch, '..') ||
        str_starts_with($branch, '/') ||
        str_ends_with($branch, '/') ||
        !preg_match('/^[A-Za-z0-9._\/-]+$/', $branch)
    ) {
        throw new Exception('Branch invalida.');
    }

    return $branch;
}

function updaterTokenPreview(string $token): string {
    if ($token === '') {
        return 'Nao configurado';
    }

    return 'Configurado (*' . substr($token, -4) . ')';
}

function updaterStoragePaths(): array {
    $root = BASE_PATH . '/storage/updater';

    return [
        'storage' => BASE_PATH . '/storage',
        'root' => $root,
        'downloads' => $root . '/downloads',
        'extract' => $root . '/extract',
        'backups' => $root . '/backups',
        'lock' => $root . '/update.lock',
    ];
}

function updaterEnsureStorage(): array {
    $paths = updaterStoragePaths();

    foreach (['storage', 'root', 'downloads', 'extract', 'backups'] as $key) {
        if (!is_dir($paths[$key]) && !mkdir($paths[$key], 0755, true)) {
            throw new Exception('Nao foi possivel criar o diretorio: ' . $paths[$key]);
        }
    }

    $htaccess = $paths['storage'] . '/.htaccess';
    if (!file_exists($htaccess)) {
        @file_put_contents($htaccess, "Deny from all\n");
    }

    return $paths;
}

function updaterProtectedPaths(): array {
    return [
        'config.php',
        'config.local.php',
        '.env',
        '.user.ini',
        'database',
        'uploads',
        'storage',
        'config',
        '.git',
    ];
}

function updaterShouldSkip(string $relativePath, bool $isDir = false): bool {
    $relativePath = updaterNormalizeRelativePath($relativePath);

    if ($relativePath === '') {
        return false;
    }

    if (preg_match('#(^|/)\.\.($|/)#', $relativePath)) {
        return true;
    }

    foreach (updaterProtectedPaths() as $protected) {
        if ($relativePath === $protected || str_starts_with($relativePath, $protected . '/')) {
            return true;
        }
    }

    return false;
}

function updaterCheckLatest(?string $repo = null, ?string $branch = null, ?string $token = null): array {
    $config = updaterConfig();
    $repo = updaterNormalizeRepo($repo ?? $config['repo']);
    $branch = updaterNormalizeBranch($branch ?? $config['branch']);
    $token = $token ?? $config['token'];

    $url = 'https://api.github.com/repos/' . $repo . '/commits/' . rawurlencode($branch);
    $response = updaterHttpGet($url, $token);
    $payload = json_decode($response['body'], true);

    if (!is_array($payload) || empty($payload['sha'])) {
        throw new Exception('Resposta inesperada do GitHub ao consultar o commit.');
    }

    $message = $payload['commit']['message'] ?? '';
    $message = trim(strtok($message, "\n") ?: $message);
    $sha = $payload['sha'];
    $currentSha = getSetting('update_current_sha', '');

    return [
        'repo' => $repo,
        'branch' => $branch,
        'sha' => $sha,
        'short_sha' => substr($sha, 0, 7),
        'message' => $message,
        'author_date' => $payload['commit']['author']['date'] ?? '',
        'html_url' => $payload['html_url'] ?? '',
        'current_sha' => $currentSha,
        'current_short_sha' => $currentSha ? substr($currentSha, 0, 7) : '',
        'has_update' => $currentSha === '' ? null : !hash_equals($currentSha, $sha),
    ];
}

function updaterApplyUpdate(?string $repo = null, ?string $branch = null, ?string $token = null): array {
    if (!class_exists('ZipArchive')) {
        throw new Exception('A extensao PHP ZipArchive nao esta habilitada no servidor.');
    }

    if (function_exists('set_time_limit')) {
        @set_time_limit(300);
    }

    $paths = updaterEnsureStorage();
    $lock = updaterAcquireLock($paths['lock']);
    $extractDir = '';

    try {
        $latest = updaterCheckLatest($repo, $branch, $token);
        $config = updaterConfig();
        $token = $token ?? $config['token'];
        $batch = date('Ymd-His') . '-' . $latest['short_sha'];
        $zipPath = $paths['downloads'] . '/update-' . $batch . '.zip';
        $extractDir = $paths['extract'] . '/' . $batch;

        if (!mkdir($extractDir, 0755, true)) {
            throw new Exception('Nao foi possivel preparar o diretorio temporario.');
        }

        updaterDownloadArchive($latest['repo'], $latest['branch'], $token, $zipPath);
        $sourceRoot = updaterExtractArchive($zipPath, $extractDir);
        $backupPath = $paths['backups'] . '/backup-' . $batch . '.zip';
        $backupCount = updaterCreateBackup($sourceRoot, $backupPath);
        $copyResult = updaterCopyTree($sourceRoot, BASE_PATH);

        setSetting('update_current_sha', $latest['sha']);
        setSetting('update_last_run_at', date('Y-m-d H:i:s'));
        setSetting('update_last_backup', updaterRelativeToBase($backupPath));

        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        logSync('UPDATE', 'INFO', 'github_update', 'Atualizacao aplicada com sucesso', [
            'repo' => $latest['repo'],
            'branch' => $latest['branch'],
            'sha' => $latest['sha'],
            'copied' => $copyResult['copied'],
            'backup' => updaterRelativeToBase($backupPath),
        ]);

        return [
            'ok' => true,
            'latest' => $latest,
            'copied' => $copyResult['copied'],
            'skipped' => $copyResult['skipped'],
            'backup' => updaterRelativeToBase($backupPath),
            'backup_count' => $backupCount,
        ];
    } catch (Throwable $e) {
        logSync('UPDATE', 'ERROR', 'github_update', $e->getMessage());
        throw $e;
    } finally {
        if ($extractDir !== '') {
            updaterRemoveDir($extractDir);
        }
        updaterReleaseLock($lock);
    }
}

function updaterDownloadArchive(string $repo, string $branch, string $token, string $targetFile): void {
    $url = 'https://api.github.com/repos/' . $repo . '/zipball/' . rawurlencode($branch);
    updaterHttpGet($url, $token, $targetFile, 300);

    if (!is_file($targetFile) || filesize($targetFile) < 1000) {
        throw new Exception('Arquivo ZIP baixado parece invalido.');
    }
}

function updaterExtractArchive(string $zipPath, string $extractDir): string {
    $zip = new ZipArchive();
    $opened = $zip->open($zipPath);

    if ($opened !== true) {
        throw new Exception('Nao foi possivel abrir o ZIP baixado.');
    }

    if (!$zip->extractTo($extractDir)) {
        $zip->close();
        throw new Exception('Nao foi possivel extrair o ZIP baixado.');
    }

    $zip->close();

    $entries = array_values(array_filter(scandir($extractDir) ?: [], fn($entry) => $entry !== '.' && $entry !== '..'));
    if (count($entries) !== 1 || !is_dir($extractDir . '/' . $entries[0])) {
        throw new Exception('Estrutura inesperada dentro do ZIP do GitHub.');
    }

    return $extractDir . '/' . $entries[0];
}

function updaterCreateBackup(string $sourceRoot, string $backupPath): int {
    $zip = new ZipArchive();
    if ($zip->open($backupPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new Exception('Nao foi possivel criar o backup.');
    }

    $count = 0;
    foreach (updaterSourceIterator($sourceRoot) as $file) {
        if (!$file->isFile()) {
            continue;
        }

        $relative = updaterRelativePath($sourceRoot, $file->getPathname());
        if (updaterShouldSkip($relative, false)) {
            continue;
        }

        $target = BASE_PATH . '/' . $relative;
        if (is_file($target)) {
            $zip->addFile($target, $relative);
            $count++;
        }
    }

    $zip->close();
    return $count;
}

function updaterCopyTree(string $sourceRoot, string $targetRoot): array {
    $copied = 0;
    $skipped = [];

    foreach (updaterSourceIterator($sourceRoot, $skipped) as $item) {
        $relative = updaterRelativePath($sourceRoot, $item->getPathname());
        $target = rtrim($targetRoot, '/\\') . '/' . $relative;

        if ($item->isDir()) {
            if (!is_dir($target) && !mkdir($target, 0755, true)) {
                throw new Exception('Nao foi possivel criar diretorio: ' . $relative);
            }
            continue;
        }

        if (!$item->isFile()) {
            continue;
        }

        $targetDir = dirname($target);
        if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true)) {
            throw new Exception('Nao foi possivel criar diretorio: ' . updaterRelativeToBase($targetDir));
        }

        if (!copy($item->getPathname(), $target)) {
            throw new Exception('Falha ao copiar arquivo: ' . $relative);
        }

        @chmod($target, 0644);
        $copied++;
    }

    $skipped = array_values(array_unique($skipped));
    sort($skipped);

    return [
        'copied' => $copied,
        'skipped' => $skipped,
    ];
}

function updaterSourceIterator(string $sourceRoot, ?array &$skipped = null): RecursiveIteratorIterator {
    if ($skipped === null) {
        $skipped = [];
    }

    $directory = new RecursiveDirectoryIterator($sourceRoot, FilesystemIterator::SKIP_DOTS);
    $filter = new RecursiveCallbackFilterIterator(
        $directory,
        function (SplFileInfo $current) use ($sourceRoot, &$skipped): bool {
            $relative = updaterRelativePath($sourceRoot, $current->getPathname());

            if (updaterShouldSkip($relative, $current->isDir())) {
                $skipped[] = $relative;
                return false;
            }

            return true;
        }
    );

    return new RecursiveIteratorIterator($filter, RecursiveIteratorIterator::SELF_FIRST);
}

function updaterHttpGet(string $url, string $token = '', ?string $targetFile = null, int $timeout = 60): array {
    $headers = [
        'User-Agent: ArbitraFy-Updater',
        'Accept: application/vnd.github+json',
        'X-GitHub-Api-Version: 2022-11-28',
    ];

    if ($token !== '') {
        $headers[] = 'Authorization: Bearer ' . $token;
    }

    if (function_exists('curl_init')) {
        return updaterCurlGet($url, $headers, $targetFile, $timeout);
    }

    return updaterStreamGet($url, $headers, $targetFile, $timeout);
}

function updaterCurlGet(string $url, array $headers, ?string $targetFile, int $timeout): array {
    $ch = curl_init($url);
    $fileHandle = null;

    $options = [
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HEADER => false,
    ];

    if ($targetFile !== null) {
        $fileHandle = fopen($targetFile, 'wb');
        if (!$fileHandle) {
            throw new Exception('Nao foi possivel criar arquivo temporario.');
        }
        $options[CURLOPT_FILE] = $fileHandle;
        $options[CURLOPT_RETURNTRANSFER] = false;
    } else {
        $options[CURLOPT_RETURNTRANSFER] = true;
    }

    curl_setopt_array($ch, $options);
    $body = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (is_resource($fileHandle)) {
        fclose($fileHandle);
    }

    if ($body === false && $targetFile === null) {
        throw new Exception('Erro HTTP: ' . $error);
    }

    if ($status < 200 || $status >= 300) {
        $detail = $targetFile ? '' : ' - ' . substr((string)$body, 0, 300);
        throw new Exception('GitHub retornou HTTP ' . $status . $detail);
    }

    return [
        'status' => $status,
        'body' => $targetFile ? '' : (string)$body,
    ];
}

function updaterStreamGet(string $url, array $headers, ?string $targetFile, int $timeout): array {
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => implode("\r\n", $headers),
            'timeout' => $timeout,
            'ignore_errors' => true,
            'follow_location' => 1,
            'max_redirects' => 5,
        ],
    ]);

    $body = @file_get_contents($url, false, $context);
    $status = updaterParseStatus($http_response_header ?? []);

    if ($body === false) {
        throw new Exception('Nao foi possivel conectar ao GitHub.');
    }

    if ($status < 200 || $status >= 300) {
        throw new Exception('GitHub retornou HTTP ' . $status . ' - ' . substr($body, 0, 300));
    }

    if ($targetFile !== null && file_put_contents($targetFile, $body) === false) {
        throw new Exception('Nao foi possivel salvar o ZIP baixado.');
    }

    return [
        'status' => $status,
        'body' => $targetFile ? '' : $body,
    ];
}

function updaterParseStatus(array $headers): int {
    $status = 0;
    foreach ($headers as $header) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $matches)) {
            $status = (int)$matches[1];
        }
    }
    return $status;
}

function updaterAcquireLock(string $lockPath): array {
    $handle = fopen($lockPath, 'c');
    if (!$handle) {
        throw new Exception('Nao foi possivel criar o lock de atualizacao.');
    }

    if (!flock($handle, LOCK_EX | LOCK_NB)) {
        fclose($handle);
        throw new Exception('Ja existe uma atualizacao em andamento.');
    }

    ftruncate($handle, 0);
    fwrite($handle, date('c'));

    return [
        'handle' => $handle,
        'path' => $lockPath,
    ];
}

function updaterReleaseLock(array $lock): void {
    if (!empty($lock['handle']) && is_resource($lock['handle'])) {
        flock($lock['handle'], LOCK_UN);
        fclose($lock['handle']);
    }

    if (!empty($lock['path']) && is_file($lock['path'])) {
        @unlink($lock['path']);
    }
}

function updaterRemoveDir(string $dir): void {
    $paths = updaterStoragePaths();
    $storageRoot = realpath($paths['root']);
    $target = realpath($dir);

    if (!$storageRoot || !$target || !str_starts_with(updaterNormalizePath($target), updaterNormalizePath($storageRoot))) {
        return;
    }

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($target, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($items as $item) {
        if ($item->isDir()) {
            @rmdir($item->getPathname());
        } else {
            @unlink($item->getPathname());
        }
    }

    @rmdir($target);
}

function updaterRelativePath(string $base, string $path): string {
    $base = rtrim(updaterNormalizePath(realpath($base) ?: $base), '/') . '/';
    $path = updaterNormalizePath(realpath($path) ?: $path);

    if (str_starts_with($path, $base)) {
        return updaterNormalizeRelativePath(substr($path, strlen($base)));
    }

    return updaterNormalizeRelativePath($path);
}

function updaterRelativeToBase(string $path): string {
    return updaterRelativePath(BASE_PATH, $path);
}

function updaterNormalizePath(string $path): string {
    return str_replace('\\', '/', $path);
}

function updaterNormalizeRelativePath(string $path): string {
    return trim(str_replace('\\', '/', $path), '/');
}
