<?php
/**
 * Created by PHPStorm
 * User: bteague
 * Date: 4/10/26
 * Time: 2:06 PM
 * PHP Version: 7.4+
 *
 * @category
 * @package
 * @author   Bryan Teague <bryant@sandiego.edu>
 * @license  https://github.sandiego.edu.com/ GPL
 * @link     https://github.sandiego.edu.com/
 **/
// Harden the session cookie: not readable from JS, not sent on cross-site
// subrequests/POSTs (SameSite=Lax), and HTTPS-only when served over HTTPS
// (Vercel terminates TLS and forwards X-Forwarded-Proto).
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax',
]);
ini_set('session.use_strict_mode', '1');
session_start();
// Buffer output so redirects issued after the page header has been rendered
// (e.g. after login) still work when output_buffering is off, as it is in
// the FrankenPHP container.
ob_start();
define('PROJECT_ROOT', realpath(__DIR__ .'/../../..' ));

if (!file_exists(PROJECT_ROOT . '/assets/nsfproject/settings/settings.php')) {
    header('Location: setup.php');
    exit;
} else {
    include PROJECT_ROOT . '/assets/nsfproject/settings/settings.php';
}

// Reject cross-site form submissions. The setup wizard (which includes this
// file mid-request, before any session exists) opts out explicitly.
if (!defined('NSF_SKIP_CSRF_CHECK')) {
    \Nsfproject\helper\csrf::enforce();
}