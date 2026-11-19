<?php
/**
 * Created by PHPStorm
 * User: bteague
 * Date: 4/16/26
 * Time: 4:12 PM
 * PHP Version: 7.4+
 *
 * @category
 * @package
 * @author   Bryan Teague <bryant@sandiego.edu>
 * @license  https://github.sandiego.edu.com/ GPL
 * @link     https://github.sandiego.edu.com/
 **/
include 'assets/nsfproject/start/init.php';
include 'assets/nsfproject/start/noLoginNeeded.php';
if (!isset($_SESSION['reset_token'], $_SESSION['reset_email'])) {
    header(header: "location:". WEB_PATH . "/login.php");
    exit;
}
use Matomo\Ini\IniReader;
use Nsfproject\helper\helper;
use Nsfproject\helper\logger;
use Nsfproject\helper\dbModel;
use Nsfproject\models\user;
$logger=logger::getInstance();
$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if (empty($password)) {
        $errors[] = 'Password is required.';
        $logger->logUserMessage('Password is required.');
    }
    if (empty($confirm_password)) {
        $errors[] = 'Confirm password is required.';
        $logger->logUserMessage('Confirm password is required.');
    }
    if ($password !== '' && strlen($password) < \Nsfproject\helper\helper::MIN_PASSWORD_LENGTH) {
        $errors[] = 'Password too short.';
        $logger->logUserMessage('Password must be at least ' . \Nsfproject\helper\helper::MIN_PASSWORD_LENGTH . ' characters.');
    }
    if ($password !== $confirm_password) {
        $errors[] = 'Passwords do not match.';
        $logger->logUserMessage('Passwords do not match.');
    }

    if (empty($errors)) {
        $dbh = \Nsfproject\helper\dbModel::getInstance();
        $user=new \Nsfproject\models\user($dbh);
        $resetEmail = $_SESSION['reset_email'];
        // The link must still be valid (unexpired, not already used) at the
        // moment the password is changed, not just when the form was opened.
        if ($user->checkToken($_SESSION['reset_token']) !== $resetEmail) {
            unset($_SESSION['reset_token'], $_SESSION['reset_email']);
            $logger->logUserMessage('This password link has expired or was already used. Please request a new one.');
            $logger->displayUserMessage();
            echo helper::getFooter($ini);
            exit;
        }
        $success = true;
        if ($user->updatePassword($resetEmail, $password)) {
            $user->consumeResetTokens($resetEmail);
            unset($_SESSION['reset_token'], $_SESSION['reset_email']);
            if ($user->validateUser($resetEmail, $password)) {
                $user->setSession();
            }

            $logger->displaySuccessMessage();

            ?>
            <p>You will be redirected in 10 seconds.</p>
            <script>
                setTimeout(function() {
                    window.location.href = "<?php echo WEB_PATH; ?>/";
                }, 10000);
            </script>
            <?php
            echo helper::getFooter($ini);
            exit;
        }
    }else {
        helper::updatePasswordform();
    }
} else {
    helper::updatePasswordform();
}
echo helper::getFooter($ini);
?>