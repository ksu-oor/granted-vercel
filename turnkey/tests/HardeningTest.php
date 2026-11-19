<?php
/**
 * Follow-up hardening: open-redirect guard, single-use password reset
 * tokens, POST-only logout, DB password never rendered.
 *
 * @category Testing
 * @package Nsfproject\Tests
 */

namespace Nsfproject\Tests;

use PDO;
use PHPUnit\Framework\TestCase;
use Nsfproject\helper\dbModel;
use Nsfproject\helper\helper;
use Nsfproject\models\user;

class HardeningTest extends TestCase
{
    public function testSafeLocalPathAllowsSameSitePaths(): void
    {
        $this->assertSame('/manage/user', helper::safeLocalPath('/manage/user'));
        $this->assertSame('/nsfproject/index.php?_focus=3', helper::safeLocalPath('/nsfproject/index.php?_focus=3'));
    }

    public function testSafeLocalPathBlocksOffSiteRedirects(): void
    {
        $home = WEB_PATH . '/';
        foreach (['https://evil.example', '//evil.example', '/\\evil.example', 'evil.example', '', "/ok\r\nSet-Cookie: x=y", 'javascript:alert(1)'] as $bad) {
            $this->assertSame($home, helper::safeLocalPath($bad), var_export($bad, true));
        }
    }

    public function testResetTokensAreSingleUse(): void
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $pdo->exec('CREATE TABLE password_resets (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT, token TEXT, expires TEXT)');
        // sqlite has no now(); register one that matches MySQL's format.
        $pdo->sqliteCreateFunction('now', fn() => date('Y-m-d H:i:s'), 0);
        $pdo->prepare('INSERT INTO password_resets (email, token, expires) VALUES (?, ?, ?)')
            ->execute(['a@x.edu', 'tok123', date('Y-m-d H:i:s', time() + 3600)]);

        $db = $this->createMock(dbModel::class);
        $db->method('getDB')->willReturn($pdo);
        $user = new user($db);

        $this->assertSame('a@x.edu', $user->checkToken('tok123'));
        $user->consumeResetTokens('a@x.edu');
        $this->assertFalse($user->checkToken('tok123'), 'token must not be reusable after a reset');
        $this->assertFalse($user->checkToken(''));
        $this->assertFalse($user->checkToken(null));
    }

    public function testUpdatePasswordRechecksAndConsumesToken(): void
    {
        $src = file_get_contents(dirname(__DIR__) . '/html/nsfproject/updatePassword.php');
        $this->assertStringContainsString('checkToken($_SESSION[\'reset_token\'])', $src);
        $this->assertStringContainsString('consumeResetTokens(', $src);
        $this->assertStringContainsString('MIN_PASSWORD_LENGTH', $src);
        $forgot = file_get_contents(dirname(__DIR__) . '/html/nsfproject/forgotPassword.php');
        $this->assertStringNotContainsString('is email', $forgot, 'debug echo of the email removed');
    }

    public function testLogoutIsPostOnly(): void
    {
        $root = dirname(__DIR__) . '/html/nsfproject';
        $logout = file_get_contents("$root/logout.php");
        $this->assertStringContainsString("start/init.php", $logout, 'init.php enforces CSRF on the POST');
        $postBranch = strpos($logout, "=== 'POST')");
        $destroy = strpos($logout, 'session_destroy()');
        $this->assertNotFalse($postBranch);
        $this->assertGreaterThan($postBranch, $destroy, 'session is only destroyed inside the POST branch');
        $this->assertSame(1, substr_count($logout, 'session_destroy()'));
        $header = file_get_contents("$root/assets/nsfproject/header/regular-header.php");
        $this->assertStringNotContainsString("href='\". WEB_PATH . \"/logout.php'", $header);
        $this->assertStringContainsString('csrf::field()', $header);
    }

    public function testDatabasePasswordIsNeverRendered(): void
    {
        $views = dirname(__DIR__) . '/includes/nsfproject/helper/views';
        $ini = [
            'header' => ['siteTitle' => 't', 'logo' => 'l', 'schoolName' => 's'],
            'database' => ['host' => 'h', 'port' => 3306, 'dbname' => 'd', 'user' => 'u', 'password' => 'Sup3r-Secret-DB-pw'],
            'security' => ['pepper' => 'Pepper-Should-Not-Show-0123456789abcdef'],
        ];
        // confform.php renders a CSRF field, which needs a session.
        if (session_status() !== PHP_SESSION_ACTIVE) {
            ini_set('session.use_cookies', '0');
            ini_set('session.cache_limiter', '');
            session_start();
        }
        foreach (['configuration.php', 'confform.php'] as $view) {
            ob_start();
            include "$views/$view";
            $html = ob_get_clean();
            $this->assertStringNotContainsString('Sup3r-Secret-DB-pw', $html, $view);
            $this->assertStringNotContainsString('Pepper-Should-Not-Show', $html, $view);
        }
    }
}
