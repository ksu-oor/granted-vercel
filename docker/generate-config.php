<?php
/**
 * Regenerates the app's conf.ini and settings.php from environment variables.
 *
 * Runs once per container boot (see docker/entrypoint.sh). The turnkey app
 * normally writes these two files to disk via its interactive setup wizard
 * (setup.php - setup4.php), but Vercel's container filesystem is not
 * persistent across deploys/cold starts, so we regenerate them from env vars
 * every time the container starts instead.
 */

require '/app/includes/nsfproject/vendor/autoload.php';

// helper::$forgotPasswordLink is initialized from WEB_PATH at class-load
// time; define it before the class is autoloaded below since this script
// runs outside of any HTTP request context.
if (!defined('WEB_PATH')) {
    define('WEB_PATH', '');
}

function envOrFail(string $name): string
{
    $value = getenv($name);
    if ($value === false || $value === '') {
        fwrite(STDERR, "Missing required environment variable: {$name}\n");
        exit(1);
    }
    return $value;
}

$resourceLinks = array_values(array_filter(array_map(
    'trim',
    explode(',', getenv('APP_RESOURCE_LINKS') ?: '')
)));

$formInput = [
    'siteTitle' => getenv('APP_SITE_TITLE') ?: 'NSF HERD Dashboard',
    'logo' => getenv('APP_LOGO_URL') ?: '',
    'schoolName' => getenv('APP_SCHOOL_NAME') ?: '',
    'emailSender' => getenv('APP_EMAIL_SENDER') ?: '',
    'copyright' => getenv('APP_COPYRIGHT') ?: '',
    'resourceLink' => $resourceLinks,
    'override' => getenv('APP_CSS_OVERRIDE') ?: '',
    'host' => envOrFail('DB_HOST'),
    'port' => envOrFail('DB_PORT'),
    'dbname' => envOrFail('DB_NAME'),
    'user' => envOrFail('DB_USER'),
    'password' => envOrFail('DB_PASSWORD'),
    // Unused by this script (we hardcode container paths below), but
    // required by helper::prepareIniFile()'s expected input shape.
    'includesdir' => '/app',
];

$ini = \Nsfproject\helper\helper::prepareIniFile($formInput);

$iniPath = '/app/includes/nsfproject/conf/conf.ini';
$writer = new \Matomo\Ini\IniWriter();
$writer->writeToFile($iniPath, $ini);

$settingsPath = '/app/public/nsfproject/assets/nsfproject/settings/settings.php';
$settingsContents = "<?php \r\n"
    . "require_once '/app/includes/nsfproject/vendor/autoload.php';\r\n"
    . "\$inifilelocation='{$iniPath}';\r\n";

file_put_contents($settingsPath, $settingsContents);

fwrite(STDOUT, "Wrote {$iniPath} and {$settingsPath}\n");
