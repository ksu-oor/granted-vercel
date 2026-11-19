<?php
/**
 * CSRF protection for every state-changing form.
 *
 * Forms include csrf::field(); assets/nsfproject/start/init.php calls
 * csrf::enforce() so any POST without this session's token is rejected
 * before page code runs. Kept separate from helper because helper can't be
 * loaded until WEB_PATH is defined.
 *
 * @category
 * @package  Nsfproject\helper
 **/

namespace Nsfproject\helper;

class csrf
{
    public const FIELD = 'csrf_token';

    public static function token(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            throw new \RuntimeException('csrf::token() requires an active session.');
        }
        if (empty($_SESSION[self::FIELD]) || !is_string($_SESSION[self::FIELD])) {
            $_SESSION[self::FIELD] = bin2hex(random_bytes(32));
        }
        return $_SESSION[self::FIELD];
    }

    public static function field(): string
    {
        return '<input type="hidden" name="' . self::FIELD . '" value="' . htmlspecialchars(self::token(), ENT_QUOTES) . '">';
    }

    public static function isValid(mixed $token): bool
    {
        $expected = $_SESSION[self::FIELD] ?? null;
        return is_string($expected) && $expected !== '' && is_string($token) && hash_equals($expected, $token);
    }

    /**
     * Stop any POST that doesn't carry this session's CSRF token.
     */
    public static function enforce(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            return;
        }
        if (!self::isValid($_POST[self::FIELD] ?? null)) {
            http_response_code(403);
            error_log('Rejected POST to ' . ($_SERVER['REQUEST_URI'] ?? '?') . ': missing or invalid CSRF token');
            exit('Your form session has expired or the request was invalid. Please go back, reload the page, and try again.');
        }
    }
}
