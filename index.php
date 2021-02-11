<?php

// Version (COMMIT)
define('VERSION', '1.5.6.1');
// рассчет времени работы
$sysstart = microtime(true);
// рассчет памяти
function_exists('memory_get_usage') ? define('MEM_USAGE', memory_get_usage()) : null;
/* Testing non-beta */

$dev_env = true;

// Configuration
if($dev_env) {
    if (file_exists('config_dev.php')) {
        require_once('config_dev.php');
    } elseif (file_exists('config.php')) {
        require_once('config.php');
    }
} else {
    if (file_exists('config.php')) {
        require_once('config.php');
    }
}

/* Cacher */
require_once('pagecache/caching.php');
require_once('pagecache/timer.class.php');
/* Cacher */

// Startup
require_once(DIR_SYSTEM . 'startup.php');

// Application Classes
require_once(DIR_SYSTEM . 'library/customer.php');
require_once(DIR_SYSTEM . 'library/currency.php');
require_once(DIR_SYSTEM . 'library/debug.php');


// Registry
$registry = new Registry();

// Loader
$loader = new Loader($registry);
$registry->set('load', $loader);

// Config
$config = new Config();
$registry->set('config', $config);

// Database 
$db = new DB(DB_DRIVER, DB_HOSTNAME, DB_USERNAME, DB_PASSWORD, DB_DATABASE);
$registry->set('db', $db);


// Settings
$query = $db->query("SELECT * FROM " . DB_PREFIX . "setting WHERE store_id = '0' OR store_id = '" . (int)$config->get('config_store_id') . "' ORDER BY store_id ASC");

foreach ($query->rows as $setting) {
    if (!$setting['serialized']) {
        $config->set($setting['key'], $setting['value']);
    } else {
        $config->set($setting['key'], unserialize($setting['value']));
    }
}

$config->set('config_url', HTTP_SERVER);
$config->set('config_ssl', HTTPS_SERVER);

// Url
$url = new Url($config->get('config_url'), $config->get('config_secure') ? $config->get('config_ssl') : $config->get('config_url'));
$registry->set('url', $url);

// Log 
$log = new Log($config->get('config_error_filename'));
$registry->set('log', $log);

function error_handler($errno, $errstr, $errfile, $errline)
{
    global $log, $config;

    switch ($errno) {
        case E_NOTICE:
        case E_USER_NOTICE:
            $error = 'Notice';
            break;
        case E_WARNING:
        case E_USER_WARNING:
            $error = 'Warning';
            break;
        case E_ERROR:
        case E_USER_ERROR:
            $error = 'Fatal Error';
            break;
        default:
            $error = 'Unknown';
            break;
    }

    if ($config->get('config_error_display')) {
        echo '<b>' . $error . '</b>: ' . $errstr . ' in <b>' . $errfile . '</b> on line <b>' . $errline . '</b>';
    }

    if ($config->get('config_error_log')) {
        $log->write('PHP ' . $error . ':  ' . $errstr . ' in ' . $errfile . ' on line ' . $errline);
    }

    return true;
}

// Error Handler
if(!$dev_env) {
    set_error_handler('error_handler');
}
// Request
$request = new Request();
$registry->set('request', $request);

// Response
$response = new Response();
$response->addHeader('Content-Type: text/html; charset=utf-8');
$response->setCompression($config->get('config_compression'));
$registry->set('response', $response);

// Cache
$cache = new Cache();
$registry->set('cache', $cache);

// Session
$session = new Session();
$registry->set('session', $session);

// Language Detection
$languages = array();

$query = $db->query("SELECT * FROM `" . DB_PREFIX . "language` WHERE status = '1'");

foreach ($query->rows as $result) {
    $languages[$result['code']] = $result;
}

$detect = '';


if (isset($request->server['HTTP_ACCEPT_LANGUAGE']) && $request->server['HTTP_ACCEPT_LANGUAGE']) {
    $browser_languages = explode(',', $request->server['HTTP_ACCEPT_LANGUAGE']);

    foreach ($browser_languages as $browser_language) {
        foreach ($languages as $key => $value) {
            if ($value['status']) {
                $locale = explode(',', $value['locale']);

                if (in_array($browser_language, $locale)) {
                    $detect = $key;
                }
            }
        }
    }
}

if (isset($session->data['language']) && array_key_exists($session->data['language'], $languages) && $languages[$session->data['language']]['status']) {
    $code = $session->data['language'];
} elseif (isset($request->cookie['language']) && array_key_exists($request->cookie['language'], $languages) && $languages[$request->cookie['language']]['status']) {
    $code = $request->cookie['language'];
} elseif ($detect) {
    $code = $detect;
} else {
    $code = $config->get('config_language');
}

if (!isset($session->data['language']) || $session->data['language'] != $code) {
    $session->data['language'] = $code;
}

if (!isset($request->cookie['language']) || $request->cookie['language'] != $code) {
    setcookie('language', $code, time() + 60 * 60 * 24 * 30, '/', $request->server['HTTP_HOST']);
}

$config->set('config_language_id', $languages[$code]['language_id']);
$config->set('config_language', $languages[$code]['code']);

// Language	
$language = new Language($languages[$code]['directory']);
$language->load($languages[$code]['filename']);
$registry->set('language', $language);

// Document
$registry->set('document', new Document());

// Customer
$registry->set('customer', new Customer($registry));


if (isset($request->get['tracking'])) {
    setcookie('tracking', $request->get['tracking'], time() + 3600 * 24 * 1000, '/');
}
// Currency
$registry->set('currency', new Currency($registry));

// Sms
$registry->set('sms', new Sms($registry));

// Encryption
$registry->set('encryption', new Encryption($config->get('config_encryption')));

// Front Controller 
$controller = new Front($registry);

// Maintenance Mode
$controller->addPreAction(new Action('common/maintenance'));

// SEO URL's
$controller->addPreAction(new Action('common/seo_url'));

// Router
if (isset($request->get['route'])) {
    $action = new Action($request->get['route']);
} else {
    $action = new Action('common/home');
}

// Dispatch
$controller->dispatch($action, new Action('error/not_found'));

// Output
$response->output();


$debug_ips = array('127.0.0.1', '109.87.31.220');
if (
    (   isset($_SERVER['REMOTE_ADDR']) && in_array($_SERVER['REMOTE_ADDR'], $debug_ips) ||
        isset($_SERVER['HTTP_X_REAL_IP']) && in_array($_SERVER['REMOTE_ADDR'], $debug_ips)
    ) && !$request->isXhr()
) {
    echo !DEBUG ? null : Debug::show($sysstart);
}
