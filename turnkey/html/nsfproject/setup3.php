<?php
/**
 * Created by PHPStorm
 * User: bteague
 * Date: 4/7/26
 * Time: 3:41 PM
 * PHP Version: 7.4+
 *
 * @category
 * @package
 * @author   Bryan Teague <bryant@sandiego.edu>
 * @license  https://github.sandiego.edu.com/ GPL
 * @link     https://github.sandiego.edu.com/
 **/
// Refuse to run once the site is configured: re-running setup would let an
// unauthenticated visitor repoint the app at a database they control.
if (file_exists(__DIR__ . '/assets/nsfproject/settings/settings.php')) {
    header('Location: setup4.php');
    exit;
}
/**
 * 1. Validate the Database connection, and if valid, create tables.
 * 2. Write the config to the proper ini file (conf.ini)
 * 3. If no problems detected above, load the "final header" and "final footer" with create admin user dialog.
 *  4. If problems, load the setup header / footer and resolve the database issue.
 */

// validate connection information
$host = $_POST['host'];
$port = $_POST['port'];
$dbname = $_POST['dbname'];
$user = $_POST['user'];
$password = $_POST['password'];
$includesdir=($_POST["includesdir"]??null);
$error = '';

// Validate the first admin account up front, before anything is written:
// once settings.php exists this wizard refuses to run again.
$adminEmailInput = trim((string)($_POST['adminEmail'] ?? ''));
$adminPasswordInput = (string)($_POST['adminPassword'] ?? '');
$adminInputValid = true;
if (!filter_var($adminEmailInput, FILTER_VALIDATE_EMAIL)) {
    $error .= '<p class="error">Please enter a valid administrator email address.</p>' . "\n";
    $adminInputValid = false;
}
if (strlen($adminPasswordInput) < 12) {
    $error .= '<p class="error">The administrator password must be at least 12 characters.</p>' . "\n";
    $adminInputValid = false;
}

$dbconnection=false;
if ($adminInputValid) {
    try {
        $pdo=new PDO("mysql:host=$host;port=$port;dbname=$dbname", $user, $password);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $dbconnection=true;
    } catch (PDOException $e) {
        $error .= '<p class="error">Unable to connect to the database with the information provided.</p>' . "\n";
        $dbconnection=false;
    }
}
if ($dbconnection) {
    $autoloaddir = $includesdir . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'nsfproject' . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

    $inifilelocation = $includesdir . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'nsfproject' . DIRECTORY_SEPARATOR . 'conf' . DIRECTORY_SEPARATOR . 'conf.ini';
    $sqlfileLocation = $includesdir . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'nsfproject' . DIRECTORY_SEPARATOR . 'conf' . DIRECTORY_SEPARATOR . 'tables.sql';

    $fileContents = "<?php \r\n require_once '{$autoloaddir}';\r\n";
    $fileContents .= '$inifilelocation=' . "'" . $inifilelocation . "';\r\n";
    $foundError = false;
    $error = '';

    $result = file_put_contents('assets/nsfproject/settings/settings.php', $fileContents);
    if ($result === false) {
        $error .= '<p class="error">Error writing settings file: ' . "</p>\n";
        $foundError = true;
    }


    try {
        require_once $autoloaddir;
        // $iniwriter = \Matomo\Ini\IniWriter::class;
        $iniwriter = new \Matomo\Ini\IniWriter();
        // Never take the pepper from the request; prepareIniFile() generates one.
        $ini = \Nsfproject\helper\helper::prepareIniFile(array_diff_key($_POST, ['pepper' => 1, 'legacyPepper' => 1]));
        $iniwriter->writeToFile($inifilelocation, $ini);
        \Nsfproject\helper\helper::loadSecurityConfig($ini);
    } catch (Exception $e) {
        $error .= '<p class="error">Error writing conf.ini file: ' . $e->getMessage() . "</p>\n";
        $foundError = true;
    }


    //do this later
    if (!$foundError) {
        $loadsqlReturn=\Nsfproject\helper\helper::loadSql($pdo,$sqlfileLocation);
        $adminError = null;
        $adminCreated = false;
        if ($loadsqlReturn) {
            try {
                $adminCreated = \Nsfproject\helper\helper::bootstrapAdmin(
                    $pdo,
                    (string)($_POST['adminEmail'] ?? ''),
                    (string)($_POST['adminPassword'] ?? ''),
                    (string)($_POST['adminFirst'] ?? ''),
                    (string)($_POST['adminLast'] ?? ''),
                    $adminError
                );
            } catch (PDOException $e) {
                $adminError = 'Database error creating the administrator account.';
                error_log('setup3 bootstrapAdmin: ' . $e->getMessage());
            }
        }
        define('NSF_SKIP_CSRF_CHECK', true);
        include 'assets/nsfproject/start/init.php';
        include 'assets/nsfproject/start/noLoginNeeded.php';
        $logger=\Nsfproject\helper\logger::getInstance();
        $logger->displayUserMessage();
        $logger->displaySuccessMessage();
        if ($loadsqlReturn && !$adminCreated) {
            echo "<h2>The database was set up, but the administrator account was not created.</h2>\n";
            echo "<p class='error'>" . htmlspecialchars((string)$adminError) . "</p>\n";
            echo "<p>To retry, drop the tables, delete <code>assets/nsfproject/settings/settings.php</code>, and run setup again.</p>\n";
        }
        if ($loadsqlReturn) {
            echo "<h2>Completed writing the files!</h2>\n";
            echo "<form action='setup4.php' method='post'>\n";
            echo "<div><label for='deleteSetup'>Would you like to delete your setup files now?</label>\n";
            echo "<input type='checkbox' name='deleteSetup' id='deleteSetup' value='yes'> YES </div>\n";
            echo "<input class='button' type='submit' name='submit' id='deleteSetup' value='submit'>\n";
            echo "</form>\n";
        } else {
            echo "<h2>There was an error loading the SQL file. Please review the errors, resolve and hit reload to continue setup.</h2>\n";
        }
        $finishedFooter=\Nsfproject\helper\helper::getFooter($ini);
        echo $finishedFooter;
        exit;
    }
}
use Nsfproject\helper\setupForm;
$fallbackAutoload = $includesdir . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'nsfproject' . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
if (is_file($fallbackAutoload)) {
    require_once $fallbackAutoload;
}
$ini = \Nsfproject\helper\helper::prepareIniFile($_POST);
include 'assets/nsfproject/header/setup-header.php';
echo "<h2>Please correct the following errors:</h2>\n";
echo "$error\n";
$form = new setupForm($ini,$includesdir);
$form->buildSetupForm();
$form->display('setup3.php');
include 'assets/nsfproject/footer/setup-footer.php';