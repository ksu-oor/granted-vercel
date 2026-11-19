<?php
/**
 * H1: the password pepper comes from configuration, never source code;
 * password operations fail closed without it; hashes made with the old
 * pepper are migrated on successful login.
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

class PepperTest extends TestCase
{
    private const CURRENT = 'current-pepper-0123456789abcdef0123456789';
    private const LEGACY = 'legacy-pepper-0123456789abcdef0123456789ab';

    private PDO $pdo;

    protected function setUp(): void
    {
        putenv('APP_PEPPER');
        putenv('APP_LEGACY_PEPPER');
        $this->pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT UNIQUE NOT NULL,
            first TEXT NOT NULL, last TEXT NOT NULL, level TEXT NOT NULL, passwordhash TEXT)');
    }

    protected function tearDown(): void
    {
        putenv('APP_PEPPER');
        putenv('APP_LEGACY_PEPPER');
        helper::setPepper(str_repeat('test-pepper-', 4));
    }

    private function userModel(): user
    {
        $db = $this->createMock(dbModel::class);
        $db->method('getDB')->willReturn($this->pdo);
        return new user($db);
    }

    private function hashWith(string $pepper, string $password): string
    {
        return password_hash(hash_hmac('sha256', $password, $pepper), PASSWORD_DEFAULT);
    }

    public function testSourceNoLongerContainsTheOldPepper(): void
    {
        $source = file_get_contents(dirname(__DIR__) . '/includes/nsfproject/helper/helper.php');
        // No string literal may be assigned to the pepper in source.
        $this->assertDoesNotMatchRegularExpression('/\\$(legacyP|p)epper\\s*=\\s*[\'"]/', $source);
    }

    public function testHashingFailsClosedWithoutPepper(): void
    {
        helper::setPepper(null);
        $this->expectException(\RuntimeException::class);
        helper::hashPassword('whatever');
    }

    public function testShortPepperIsRejected(): void
    {
        helper::setPepper('too-short');
        $this->expectException(\RuntimeException::class);
        helper::hashPassword('whatever');
    }

    public function testLoginFailsClosedWithoutPepper(): void
    {
        helper::setPepper(self::CURRENT);
        $this->pdo->prepare("INSERT INTO users (email, first, last, level, passwordhash) VALUES ('a@x.edu','A','B','user',?)")
            ->execute([helper::hashPassword('pw-123456789')]);
        helper::setPepper(null);
        $this->assertFalse($this->userModel()->validateUser('a@x.edu', 'pw-123456789'));
    }

    public function testPepperLoadedFromIniAndEnvOverridesIni(): void
    {
        helper::loadSecurityConfig(['security' => ['pepper' => self::CURRENT]]);
        $hash = helper::hashPassword('pw');
        $this->assertTrue(password_verify(hash_hmac('sha256', 'pw', self::CURRENT), $hash));

        putenv('APP_PEPPER=' . self::LEGACY);
        helper::loadSecurityConfig(['security' => ['pepper' => self::CURRENT]]);
        $this->assertTrue(password_verify(hash_hmac('sha256', 'pw', self::LEGACY), helper::hashPassword('pw')));
    }

    public function testPrepareIniFileGeneratesAStrongPepper(): void
    {
        $a = helper::prepareIniFile(['siteTitle' => '', 'logo' => '', 'schoolName' => '', 'emailSender' => '', 'copyright' => '',
            'resourceLink' => [], 'host' => '', 'port' => '', 'dbname' => '', 'user' => '', 'password' => '', 'override' => '', 'includesdir' => '']);
        $b = helper::prepareIniFile(['siteTitle' => '', 'logo' => '', 'schoolName' => '', 'emailSender' => '', 'copyright' => '',
            'resourceLink' => [], 'host' => '', 'port' => '', 'dbname' => '', 'user' => '', 'password' => '', 'override' => '', 'includesdir' => '']);
        $this->assertGreaterThanOrEqual(helper::MIN_PEPPER_LENGTH, strlen($a['security']['pepper']));
        $this->assertNotSame($a['security']['pepper'], $b['security']['pepper']);
    }

    public function testLegacyHashIsAcceptedAndMigratedOnLogin(): void
    {
        helper::setPepper(self::CURRENT, self::LEGACY);
        $legacyHash = $this->hashWith(self::LEGACY, 'old-password-1');
        $this->pdo->prepare("INSERT INTO users (email, first, last, level, passwordhash) VALUES ('old@x.edu','O','U','user',?)")
            ->execute([$legacyHash]);

        $this->assertTrue($this->userModel()->validateUser('old@x.edu', 'old-password-1'));

        $newHash = $this->pdo->query("SELECT passwordhash FROM users WHERE email='old@x.edu'")->fetchColumn();
        $this->assertNotSame($legacyHash, $newHash);
        $this->assertTrue(password_verify(hash_hmac('sha256', 'old-password-1', self::CURRENT), $newHash));

        // After migration the legacy pepper is no longer needed.
        helper::setPepper(self::CURRENT);
        $this->assertTrue($this->userModel()->validateUser('old@x.edu', 'old-password-1'));
        $this->assertFalse($this->userModel()->validateUser('old@x.edu', 'wrong'));
    }

    public function testLegacyHashRejectedWithoutLegacyPepper(): void
    {
        helper::setPepper(self::CURRENT);
        $this->pdo->prepare("INSERT INTO users (email, first, last, level, passwordhash) VALUES ('old@x.edu','O','U','user',?)")
            ->execute([$this->hashWith(self::LEGACY, 'old-password-1')]);
        $this->assertFalse($this->userModel()->validateUser('old@x.edu', 'old-password-1'));
    }
}
