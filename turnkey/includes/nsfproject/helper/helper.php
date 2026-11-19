<?php
/**
 * Created by PHPStorm
 * User: bteague
 * Date: 4/8/26
 * Time: 2:10 PM
 * PHP Version: 7.4+
 *
 * @category
 * @package
 * @author   Bryan Teague <bryant@sandiego.edu>
 * @license  https://github.sandiego.edu.com/ GPL
 * @link     https://github.sandiego.edu.com/
 **/

namespace Nsfproject\helper;
use Nsfproject\controllers\NSFsettingsController;
use Nsfproject\helper\logger;
use Nsfproject\helper\dbModel;
use Nsfproject\models\navigation;
use Nsfproject\models\pages;
use PDO;

class helper
{
    var $error=array();
    /**
     * Password pepper (HMAC key applied before bcrypt). Never hardcoded: it
     * comes from the APP_PEPPER env var or [security] pepper in conf.ini, via
     * loadSecurityConfig(). Password operations fail closed if it is unset.
     */
    private static ?string $pepper = null;
    /** Previous pepper, only used to migrate existing hashes on login. */
    private static ?string $legacyPepper = null;
    public const MIN_PEPPER_LENGTH = 32;
    public static $forgotPasswordLink = WEB_PATH . "/forgotPassword.php?token=";

    /**
     * Hash of the default admin@admin.com password that older versions of
     * tables.sql seeded and that was published in the README. Any account
     * still carrying it is refused at login and doesn't count as an admin
     * for bootstrapAdmin().
     */
    public const LEAKED_DEFAULT_ADMIN_HASH = '$2y$10$eXE3WfNSXi5kpEikpJPdsuFa5tLVwK1L3w3oc3fjapvQ5yewUv1H.';

    public const MIN_PASSWORD_LENGTH = 12;
    public const MIN_ADMIN_PASSWORD_LENGTH = self::MIN_PASSWORD_LENGTH;

    /**
     * Return $path if it is a same-site absolute path ("/manage/user"),
     * otherwise the site root. Blocks open redirects such as
     * "https://evil.example", "//evil.example" and "/\\evil.example".
     */
    public static function safeLocalPath(string $path): string
    {
        $fallback = (defined('WEB_PATH') ? WEB_PATH : '') . '/';
        if ($path === '' || $path[0] !== '/' || preg_match('#^/[/\\\\]#', $path) || preg_match('/[\x00-\x1f\x7f]/', $path)) {
            return $fallback;
        }
        return $path;
    }

    public static function setPepper(?string $pepper, ?string $legacyPepper = null): void
    {
        self::$pepper = ($pepper === null || $pepper === '') ? null : $pepper;
        self::$legacyPepper = ($legacyPepper === null || $legacyPepper === '') ? null : $legacyPepper;
    }

    /**
     * Load secrets from the environment (preferred) or the [security] section
     * of conf.ini. Called whenever the ini file is applied.
     */
    public static function loadSecurityConfig(array $ini): void
    {
        $pepper = getenv('APP_PEPPER') ?: ($ini['security']['pepper'] ?? null);
        $legacy = getenv('APP_LEGACY_PEPPER') ?: ($ini['security']['legacyPepper'] ?? null);
        self::setPepper($pepper === null ? null : (string)$pepper, $legacy === null ? null : (string)$legacy);
    }

    public static function generatePepper(): string
    {
        return bin2hex(random_bytes(32));
    }

    private static function requirePepper(): string
    {
        if (self::$pepper === null || strlen(self::$pepper) < self::MIN_PEPPER_LENGTH) {
            throw new \RuntimeException(
                'Password pepper is not configured. Set the APP_PEPPER environment variable or [security] pepper in conf.ini (at least '
                . self::MIN_PEPPER_LENGTH . ' characters).'
            );
        }
        return self::$pepper;
    }

    public static function hashPassword(string $password): string
    {
        return password_hash(hash_hmac("sha256", $password, self::requirePepper()), PASSWORD_DEFAULT);
    }

    /**
     * @param bool|null $needsRehash set to true when the hash should be
     *        replaced (made with the legacy pepper or outdated bcrypt cost).
     */
    public static function verifyPassword(string $password, ?string $hash, ?bool &$needsRehash = null): bool
    {
        $needsRehash = false;
        if ($hash === null || $hash === '' || hash_equals(self::LEAKED_DEFAULT_ADMIN_HASH, $hash)) {
            return false;
        }
        if (password_verify(hash_hmac("sha256", $password, self::requirePepper()), $hash)) {
            $needsRehash = password_needs_rehash($hash, PASSWORD_DEFAULT);
            return true;
        }
        if (self::$legacyPepper !== null && password_verify(hash_hmac("sha256", $password, self::$legacyPepper), $hash)) {
            $needsRehash = true;
            return true;
        }
        return false;
    }

    /**
     * Create the first admin account if the database has no usable admin yet.
     *
     * Replaces the old hardcoded admin@admin.com seed. Returns true if an
     * admin was created; false if one already existed or input was invalid
     * (the reason is passed back through $error).
     */
    public static function bootstrapAdmin(PDO $pdo, string $email, string $password, string $first = 'Site', string $last = 'Administrator', ?string &$error = null): bool
    {
        $error = null;
        $email = trim($email);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'A valid administrator email address is required.';
            return false;
        }
        if (strlen($password) < self::MIN_ADMIN_PASSWORD_LENGTH) {
            $error = 'The administrator password must be at least ' . self::MIN_ADMIN_PASSWORD_LENGTH . ' characters.';
            return false;
        }

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE level = 'admin' AND (passwordhash IS NULL OR passwordhash <> ?)");
        $stmt->execute([self::LEAKED_DEFAULT_ADMIN_HASH]);
        if ((int)$stmt->fetchColumn() > 0) {
            $error = 'An administrator account already exists; no new admin was created.';
            return false;
        }

        $stmt = $pdo->prepare("INSERT INTO users (email, passwordhash, first, last, level) VALUES (?, ?, ?, ?, 'admin')");
        $stmt->execute([$email, self::hashPassword($password), trim($first) ?: 'Site', trim($last) ?: 'Administrator']);
        return true;
    }
    public static function prepareIniFile($array)
    {
//        array(
//    'Section1'=>array(
//          'value1'=>'hello',
//          'value2'=>'world',
//      ),
//      'Section2'=>array(
//          'value3'=>'foo',
//         )
//      );
        $inifile=array(
            'header'=>array(
                'siteTitle'=>$array['siteTitle'],
                'logo'=>$array['logo'],
                'schoolName'=>$array['schoolName'],
            ),
            'footer'=>array(
                'emailSender'=>$array['emailSender'],
                'copyright'=>$array['copyright'],
                'logo'=>$array['logo'],
                'schoolName'=>$array['schoolName'],
                'resourceLink'=>$array['resourceLink'],
            ),
            'database'=>array(
                'host'=>$array['host'],
                'port'=>$array['port'],
                'dbname'=>$array['dbname'],
                'user'=>$array['user'],
                'password'=>$array['password'],
            ),
            'CSS'=>array(
                'override'=>$array['override'],
            ),
            'includesdir'=>array(
                'includesdir'=>$array['includesdir'],
            ),
            'security'=>array(
                'pepper'=>$array['pepper'] ?? self::generatePepper(),
            ),
        );
        if (!empty($array['legacyPepper'])) {
            $inifile['security']['legacyPepper'] = $array['legacyPepper'];
        }
        return $inifile;
    }

    public static function hydrateHTML($HTML, $part, $array) {
        $headerVars=$array[$part];
        if ($part === 'footer') {
            $resourceLinks=self::prepareResourceLinks($array['footer']['resourceLink']);
            $updatedHeader=str_replace('MYRESOURCELINKS', $resourceLinks, $HTML);
            unset($headerVars['resourceLink']);
            $updatedHeader= str_replace(array_keys($headerVars), array_values($headerVars), $updatedHeader);
        } else {
            $keys=array_map('strtoupper', array_keys($headerVars));
            $updatedHeader = str_replace($keys, array_values($headerVars), $HTML);
        }
        return $updatedHeader;
    }

    private static function prepareResourceLinks($array) {
        $links = [];
        foreach ($array as $value) {

// Your Markdown input
            $markdown = $value;

            /**
             * The Regex Pattern:
             * \[([^\]]+)\] -> Matches text inside square brackets (Capture Group 1)
             * \(([^)]+)\)  -> Matches the URL inside parentheses (Capture Group 2)
             */
            $pattern = '/\[([^\]]+)\]\(([^)]+)\)/';

            /**
             * The Replacement Template:
             * $2 corresponds to the URL (Group 2)
             * $1 corresponds to the Link Text (Group 1)
             */
            $replacement = '<a href="$2">$1</a>';

// Perform the replacement
            $html_link = preg_replace($pattern, $replacement, $markdown);
            $links[]=$html_link;
// Result: <a href="https://www.sandiego.edu/">University of San Diego</a>

        }
        $return='<ul class="site-footer__links">'."\n";
        foreach ($links as $link) {
            $return .= "<li>$link</li>\n";
        }
        $return.="</ul>\n";
        return $return;
    }

    public static function getHeader($ini) {
        ob_start();
        include PROJECT_ROOT . '/assets/nsfproject/header/regular-header.php';
        $header = ob_get_clean();
        $finishedHeader=\Nsfproject\helper\helper::hydrateHTML($header,'header',$ini);
        $dbh=dbModel::getInstance();
        $nav=new navigation($dbh);
        $secure=false;
        if (isset($_SESSION['email'])) {
            $secure=true;
        }
        $nav->getPages($secure);
        $mynav=$nav->buildNestedNavigation();
        $finishedHeader = str_replace('NAVIGATION', $mynav, $finishedHeader);
        if (isset($ini['CSS']['override']) && !empty($ini['CSS']['override'])) {
            $finishedHeader = str_replace('<OVERRIDECSS>', '<link rel="stylesheet" href="' . $ini['CSS']['override'] . '">', $finishedHeader);
        } else {
            $finishedHeader = str_replace('<OVERRIDECSS>', '', $finishedHeader);
        }
        return $finishedHeader;
    }

    public static function getFooter($ini) {
        ob_start();
        include PROJECT_ROOT . '/assets/nsfproject/footer/regular-footer.php';
        $footer=ob_get_clean();
        $finishedFooter=\Nsfproject\helper\helper::hydrateHTML($footer,'footer',$ini);
        return $finishedFooter;
    }

    public function recordError($message) {
        $this->error[] = $message;
    }

    public function writeError() {
        $report='';
        foreach ($this->error as $error) {
            $report.='<p class="error">'.$error."</p>\n";
        }
        return $report;
    }

    public static function setWebPath($currentDir) {
        $webroot = $_SERVER['DOCUMENT_ROOT'];

        $computedWebPath = str_replace($webroot, '', $currentDir);
        // Fix for Windows systems (backslash vs forward slash)
        // Uncomment this line if you are using Windows and experiencing issues with backslashes in the path
       # $computedWebPath = str_replace('\\', '/', $computedWebPath);

// Ensure it starts with a slash but doesn't end with one
        #$computedWebPath = '/' . trim($computedWebPath, '/');

// Define the constant
        define('WEB_PATH', $computedWebPath);

    }

    private static function checkIfTableExists($dbh,$table) {
        // Detect database type
        $driver = $dbh->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'pgsql') {
            // PostgreSQL
            $sql = "SELECT EXISTS (
                SELECT 1
                FROM information_schema.tables
                WHERE table_schema = 'public'
                AND table_name = :tableName
            )";
            $params = ['tableName' => $table];
        } elseif ($driver === 'sqlite') {
            // SQLite
            $sql = "SELECT name FROM sqlite_master WHERE type='table' AND name=:tableName";
            $params = ['tableName' => $table];
        } else {
            // MySQL/MariaDB
            $sql = "select count(*) from information_schema.tables where table_schema = :dbName and table_name = :tableName";
            $params = ['dbName' => 'nsfproject', 'tableName' => $table];
        }

        $query = $dbh->prepare($sql);
        $query->execute($params);

        if ($driver === 'sqlite') {
            return $query->fetchColumn() !== false;
        } else {
            return $query->fetchColumn() > 0;
        }
    }

    public static function loadSql($dbh,$sqlfile = null) {
        $usercheck=self::checkIfTableExists($dbh,'users');
        $adminpagescheck=self::checkIfTableExists($dbh,'adminpages');
        $pagescheck=self::checkIfTableExists($dbh,'pages');
        $password_resetscheck=self::checkIfTableExists($dbh,'password_resets');
        if ($usercheck || $adminpagescheck || $pagescheck || $password_resetscheck) {
            $logger=logger::getInstance();
            $logger->logUserMessage("One or more tables already exist in the database.  Please drop the existing tables before running the setup. Database was NOT setup");
            $logger->logErrorMessage("One or more tables already exist in the database.  Please drop the existing tables before running the setup.");
            $logger->writeErrors();
            return false;
        }

        // Auto-detect appropriate SQL file if not provided
        if ($sqlfile === null) {
            $driver = $dbh->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($driver === 'pgsql') {
                $sqlfile = __DIR__ . '/../conf/tables_postgres.sql';
            } elseif ($driver === 'sqlite') {
                $sqlfile = __DIR__ . '/../conf/tables_sqlite.sql';
            } else {
                $sqlfile = __DIR__ . '/../conf/tables.sql';
            }
        }

        if (!file_exists($sqlfile)) {
            $logger=logger::getInstance();
            $logger->logUserMessage("SQL file not found: $sqlfile");
            $logger->logErrorMessage("SQL file not found: $sqlfile");
            $logger->writeErrors();
            return false;
        }

        $sql = file_get_contents($sqlfile);
        try {
            $dbh->exec($sql);
            return true;
        } catch (\PDOException $e) {
            $logger = logger::getInstance();
            $logger->logUserMessage("Database error setting up database: " . $e->getMessage());
            $logger->logErrorMessage("Database error setting up database: " . $e->getMessage());
            $logger->writeErrors();
            return false;
        }
    }

    public static function AdminWelcome($ini) {
        include 'views/adminwelcome.php';
    }

    public static function setDatabaseConnect($ini) {
        define('DB_HOST',$ini['database']['host']);
        define('DB_NAME',$ini['database']['dbname']);
        define('DB_USER',$ini['database']['user']);
        define('DB_PASS',$ini['database']['password']);
        define('DB_PORT',$ini['database']['port']);
        self::loadSecurityConfig($ini);

    }

    public static function displayAddUserForm() {
        $logger=logger::getInstance();
        $email = htmlspecialchars($_POST['email']?? null);
        $first = htmlspecialchars($_POST['first']?? null);
        $last = htmlspecialchars($_POST['last']?? null);
        $level = htmlspecialchars($_POST['level']?? null);
        $button = 'Add User';

        include 'views/addedituser.php';

    }

    public static function displayEditUserForm($id) {

        $logger=logger::getInstance();
        $dbh=dbModel::getInstance();
        $user=new \Nsfproject\models\user($dbh);
        $user->fetchUser($id,how:'id');
        $email = htmlspecialchars($user->getEmail())?? null;
        $first = htmlspecialchars($user->getFirst())?? null;
        $last = htmlspecialchars($user->getLast())?? null;
        $level = htmlspecialchars($user->getLevel())?? null;
        $button = 'Update User';
        include 'views/addedituser.php';

    }

    public static function displayAddPageForm() {
        $logger=logger::getInstance();
        $title = htmlspecialchars($_POST['title']?? null);
        $parentid = is_numeric($_POST['parentid']??null) ? $_POST['parentid'] : null;
        $embed = $_POST['embed']?? null;
        $content = $_POST['content']??null;
        $active = htmlspecialchars($_POST['active']??null);
        $secure = htmlspecialchars($_POST['secure']??null);
        $is_default = htmlspecialchars($_POST['is_default']??null);
        $button = 'Add Page';
        $logger->displayUserMessage();
        include 'views/addeditpage.php';
    }

    public static function displayEditPageForm($id) {
        $logger=logger::getInstance();
        $dbh=dbModel::getInstance();
        $pages=new pages($dbh);
        $pages->fetchPage($id, doDisplay:false);
        $title = htmlspecialchars($pages->getTitle())?? null;
        $parentid = htmlspecialchars($pages->getParentid())?? null;
        $embed = $pages->getEmbed()?? null;
        $content = $pages->getContent()??null;
        $active = htmlspecialchars($pages->getActive())?? null;
        $secure = htmlspecialchars($pages->getSecure())?? null;
        $is_default = $pages->getDefault()?? null;
        $button = 'Update Page';
        include 'views/addeditpage.php';

    }

    public static function sendPasswordResetEmail($user,$token,$newUser) {
        $email = $user['email'] ?? '';
        $first = $user['first'] ?? '';
        $last = $user['last'] ?? '';
        $id = $user['id'] ?? '';
        $url = self::$forgotPasswordLink . $token;
        $url = 'https://'.$_SERVER['SERVER_NAME'] . $url;
        //todo:  look at what is needed to make the headers non-static.
        $controller=new NSFsettingsController();
        $controller->readConfig();
        $ini=$controller->getSettings();
        $fromemail=$ini['footer']['emailSender'];
        $siteName=$ini['header']['siteTitle'];
        $from = $siteName . ' ' . '<'.$fromemail.'>';
        $headers = [
            'From'     => $from,
            'X-Mailer' => 'PHP/' . phpversion()
        ];
        if (!$newUser) {
            $subject = $siteName . ' - Password Reset';
            $message = <<<EOL
Hello {$first} {$last},
You or someone else has requested to reset your password.  If you did not generate this request, you can ignore this email.
If you requested a password reset, please visit {$url} to reset your password.  This token is only valid for one (1) hour only.

Thank you.
{$siteName} team
EOL;
        } else {
            $subject = $siteName . ' - Set Password';
            $message = <<<EOL
Hello {$first} {$last},
An account has been created for you on the {$siteName} application.  Please visit {$url} to set your password and access the application. This token is only valid for one (1) hour only. if the time has passed, you may reset your password using the forgot password link on the site. 

Thank you.
{$siteName} team
EOL;
        }
        mail($email, $subject, $message, $headers);
    }

    public static function updatePasswordform() {
        include 'views/resetpassword.php';
    }

    public static function displayUsers($users) {
        include 'views/display_users.php';
    }

    public static function displayPage($page) {
        $content = $page['content'] ?? null;
        $embed = $page['embed'] ?? null;
        if ($page['secure'] && !(isset($_SESSION['id']))) {
            include 'views/noaccess.php';
            return;
        }
        include 'views/displayPage.php';
    }

    public static function displayPages($pages) {
        include 'views/displayPageList.php';
    }

    public static function displayConfForm($ini) {
        include 'views/confform.php';
    }

    public static function displayConfiguration($ini) {
        include 'views/configuration.php';
    }
}