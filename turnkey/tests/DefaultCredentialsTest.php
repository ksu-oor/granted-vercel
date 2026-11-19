<?php
/**
 * Regression tests for the default-credential fixes:
 *  - the previously published admin@admin.com seed hash can never log in
 *  - users added without a password don't get a guessable one
 *  - bootstrapAdmin() creates the first admin exactly once
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

class DefaultCredentialsTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->pdo->exec('CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email VARCHAR(100) NOT NULL UNIQUE,
            first VARCHAR(100) NOT NULL,
            last VARCHAR(100) NOT NULL,
            level VARCHAR(50) NOT NULL,
            passwordhash VARCHAR(255)
        )');
        $_POST = [];
    }

    protected function tearDown(): void
    {
        $_POST = [];
    }

    private function userModel(): user
    {
        $db = $this->createMock(dbModel::class);
        $db->method('getDB')->willReturn($this->pdo);
        return new user($db);
    }

    public function testSeedFilesNoLongerContainDefaultAdmin(): void
    {
        $conf = dirname(__DIR__) . '/includes/nsfproject/conf/';
        foreach (['tables.sql', 'tables_postgres.sql', 'tables.sql.old'] as $file) {
            $sql = file_get_contents($conf . $file);
            $this->assertStringNotContainsString('admin@admin.com', $sql, $file);
            $this->assertStringNotContainsString(helper::LEAKED_DEFAULT_ADMIN_HASH, $sql, $file);
        }
    }

    public function testHashAndVerifyRoundTrip(): void
    {
        $hash = helper::hashPassword('correct horse battery staple');
        $this->assertTrue(helper::verifyPassword('correct horse battery staple', $hash));
        $this->assertFalse(helper::verifyPassword('wrong', $hash));
        $this->assertFalse(helper::verifyPassword('', null));
    }

    public function testLeakedSeedHashIsRefusedAtLogin(): void
    {
        $this->pdo->prepare("INSERT INTO users (email, first, last, level, passwordhash) VALUES (?, ?, ?, 'admin', ?)")
            ->execute(['admin@admin.com', 'delete once', 'you have an account', helper::LEAKED_DEFAULT_ADMIN_HASH]);

        // The published password isn't needed here: no password may unlock
        // an account that still carries the leaked hash.
        $this->assertFalse(helper::verifyPassword('anything', helper::LEAKED_DEFAULT_ADMIN_HASH));
        $this->assertFalse($this->userModel()->validateUser('admin@admin.com', 'anything'));
    }

    public function testValidLoginStillWorks(): void
    {
        $this->pdo->prepare("INSERT INTO users (email, first, last, level, passwordhash) VALUES (?, ?, ?, 'admin', ?)")
            ->execute(['real@example.edu', 'Real', 'Admin', helper::hashPassword('a-strong-password')]);

        $model = $this->userModel();
        $this->assertTrue($model->validateUser('real@example.edu', 'a-strong-password'));
        $this->assertSame('admin', $model->getLevel());
        $this->assertFalse($this->userModel()->validateUser('real@example.edu', 'nope'));
    }

    public function testUserAddedWithoutPasswordCannotLogInWithTemppassword(): void
    {
        $_POST = ['email' => 'new@example.edu', 'first' => 'New', 'last' => 'User', 'level' => 'admin'];
        $this->expectOutputRegex('/.*/');
        $this->userModel()->useradd();

        $this->assertFalse($this->userModel()->validateUser('new@example.edu', 'temppassword'));
        $this->assertFalse($this->userModel()->validateUser('new@example.edu', ''));
    }

    public function testBootstrapAdminCreatesFirstAdminOnce(): void
    {
        $error = null;
        $this->assertTrue(helper::bootstrapAdmin($this->pdo, 'first@example.edu', 'long-enough-password', 'First', 'Admin', $error));
        $this->assertNull($error);
        $this->assertTrue($this->userModel()->validateUser('first@example.edu', 'long-enough-password'));

        $this->assertFalse(helper::bootstrapAdmin($this->pdo, 'second@example.edu', 'another-long-password', 'Second', 'Admin', $error));
        $this->assertNotNull($error);
        $this->assertSame(1, (int)$this->pdo->query("SELECT COUNT(*) FROM users WHERE level = 'admin'")->fetchColumn());
    }

    public function testBootstrapAdminIgnoresLeakedSeedAdmin(): void
    {
        $this->pdo->prepare("INSERT INTO users (email, first, last, level, passwordhash) VALUES (?, ?, ?, 'admin', ?)")
            ->execute(['admin@admin.com', 'delete once', 'you have an account', helper::LEAKED_DEFAULT_ADMIN_HASH]);

        $this->assertTrue(helper::bootstrapAdmin($this->pdo, 'owner@example.edu', 'long-enough-password'));
    }

    public function testBootstrapAdminRejectsWeakInput(): void
    {
        $error = null;
        $this->assertFalse(helper::bootstrapAdmin($this->pdo, 'not-an-email', 'long-enough-password', 'a', 'b', $error));
        $this->assertNotNull($error);
        $this->assertFalse(helper::bootstrapAdmin($this->pdo, 'ok@example.edu', 'short', 'a', 'b', $error));
        $this->assertNotNull($error);
        $this->assertSame(0, (int)$this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());
    }
}
