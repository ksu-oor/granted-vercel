<?php
/**
 * H3: CSRF tokens on state-changing requests; user delete is POST-only.
 *
 * @category Testing
 * @package Nsfproject\Tests
 */

namespace Nsfproject\Tests;

use PHPUnit\Framework\TestCase;
use Nsfproject\helper\csrf;

class CsrfTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        $_POST = [];
    }

    public function testTokenRequiresSession(): void
    {
        $this->expectException(\RuntimeException::class);
        csrf::token();
    }

    public function testValidation(): void
    {
        $this->assertFalse(csrf::isValid('anything'), 'no token issued yet');
        $_SESSION[csrf::FIELD] = str_repeat('a', 64);
        $this->assertTrue(csrf::isValid(str_repeat('a', 64)));
        $this->assertFalse(csrf::isValid(str_repeat('b', 64)));
        $this->assertFalse(csrf::isValid(''));
        $this->assertFalse(csrf::isValid(null));
        $this->assertFalse(csrf::isValid(['array']));
    }

    public function testEveryPostFormIncludesTheToken(): void
    {
        $root = dirname(__DIR__);
        $files = [
            'html/nsfproject/login.php' => 2,
            'includes/nsfproject/helper/views/addedituser.php' => 1,
            'includes/nsfproject/helper/views/addeditpage.php' => 1,
            'includes/nsfproject/helper/views/confform.php' => 1,
            'includes/nsfproject/helper/views/resetpassword.php' => 1,
            'includes/nsfproject/helper/views/display_users.php' => 1,
            'html/nsfproject/assets/nsfproject/header/regular-header.php' => 1,
        ];
        foreach ($files as $file => $forms) {
            $src = file_get_contents("$root/$file");
            $this->assertSame($forms, preg_match_all('/<form[^>]*method=["\']post["\']/i', $src), $file);
            $this->assertSame($forms, substr_count($src, 'csrf::field()'), $file);
        }
    }

    public function testInitEnforcesCsrfAndHardensCookie(): void
    {
        $init = file_get_contents(dirname(__DIR__) . '/html/nsfproject/assets/nsfproject/start/init.php');
        $this->assertStringContainsString('csrf::enforce()', $init);
        $this->assertStringContainsString("'samesite' => 'Lax'", $init);
        $this->assertStringContainsString("'httponly' => true", $init);
    }

    public function testUserDeleteIsNoLongerAGetLink(): void
    {
        $root = dirname(__DIR__);
        $view = file_get_contents("$root/includes/nsfproject/helper/views/display_users.php");
        $this->assertStringNotContainsString('/manage/user/delete/', $view);
        $router = file_get_contents("$root/html/nsfproject/manage/index.php");
        $this->assertStringNotContainsString("fetchUser(\$_GET['id']", $router);
    }
}
