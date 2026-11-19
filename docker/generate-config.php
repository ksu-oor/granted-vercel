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
    // Password pepper. Must stay stable across deploys: changing it without
    // setting APP_LEGACY_PEPPER to the old value invalidates every password.
    'pepper' => envOrFail('APP_PEPPER'),
    'legacyPepper' => getenv('APP_LEGACY_PEPPER') ?: '',
    // Unused by this script (we hardcode container paths below), but
    // required by helper::prepareIniFile()'s expected input shape.
    'includesdir' => '/app',
];

if (strlen($formInput['pepper']) < \Nsfproject\helper\helper::MIN_PEPPER_LENGTH) {
    fwrite(STDERR, 'APP_PEPPER must be at least ' . \Nsfproject\helper\helper::MIN_PEPPER_LENGTH . " characters (e.g. `openssl rand -hex 32`).\n");
    exit(1);
}

$ini = \Nsfproject\helper\helper::prepareIniFile($formInput);
\Nsfproject\helper\helper::loadSecurityConfig($ini);

$iniPath = '/app/includes/nsfproject/conf/conf.ini';
$writer = new \Matomo\Ini\IniWriter();
$writer->writeToFile($iniPath, $ini);

$settingsPath = '/app/public/nsfproject/assets/nsfproject/settings/settings.php';
$settingsContents = "<?php \r\n"
    . "require_once '/app/includes/nsfproject/vendor/autoload.php';\r\n"
    . "\$inifilelocation='{$iniPath}';\r\n";

file_put_contents($settingsPath, $settingsContents);

fwrite(STDOUT, "Wrote {$iniPath} and {$settingsPath}\n");

// First-admin bootstrap. There is no seeded default admin; if ADMIN_EMAIL and
// ADMIN_PASSWORD are set and the database has no usable admin yet, create
// one. Failures are reported but never block the container from starting.
$adminEmail = getenv('ADMIN_EMAIL') ?: '';
$adminPassword = getenv('ADMIN_PASSWORD') ?: '';
if ($adminEmail !== '' && $adminPassword !== '') {
    try {
        $host = $formInput['host'];
        $port = $formInput['port'];
        $db = $formInput['dbname'];
        // Same driver detection as Nsfproject\helper\dbModel::connectDB().
        if (strpos($host, 'postgres') !== false || $port == '5432') {
            $dsn = "pgsql:host={$host};port={$port};dbname={$db}";
        } else {
            $dsn = "mysql:host={$host};dbname={$db};port={$port}";
        }
        $pdo = new PDO($dsn, $formInput['user'], $formInput['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 5,
        ]);
        $adminError = null;
        $created = \Nsfproject\helper\helper::bootstrapAdmin(
            $pdo,
            $adminEmail,
            $adminPassword,
            getenv('ADMIN_FIRST') ?: 'Site',
            getenv('ADMIN_LAST') ?: 'Administrator',
            $adminError
        );
        fwrite(STDOUT, $created ? "Created admin account {$adminEmail}\n" : "Admin bootstrap skipped: {$adminError}\n");
    } catch (Throwable $e) {
        fwrite(STDERR, "Admin bootstrap failed (continuing): {$e->getMessage()}\n");
    }
}
