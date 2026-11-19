<?php
/**
 * Created by PHPStorm
 * User: bteague
 * Date: 4/17/26
 * Time: 4:01 PM
 * PHP Version: 7.4+
 *
 * @category
 * @package
 * @author   Bryan Teague <bryant@sandiego.edu>
 * @license  https://github.sandiego.edu.com/ GPL
 * @link     https://github.sandiego.edu.com/
 **/
?>
<table>
<tr>
        <th scope="col">Email</th>
    <th scope="col">First</th>
    <th scope="col">Last</th>
    <th scope="col">Level</th>
    <th scope="col">Action</th>
</tr>
<?php foreach ($users as $user): ?>
    <tr>
        <td><?php echo htmlspecialchars((string)$user['email']); ?></td>
        <td><?php echo htmlspecialchars((string)$user['first']); ?></td>
        <td><?php echo htmlspecialchars((string)$user['last']); ?></td>
        <td><?php echo htmlspecialchars((string)$user['level']); ?></td>
        <td><a href="<?php echo WEB_PATH;?>/manage/user/edit/<?= (int)$user['id'] ?>">Edit</a>&nbsp;
            <?php /* Deleting changes state, so it is a CSRF-protected POST, never a GET link. */ ?>
            <form method="post" action="<?php echo WEB_PATH;?>/manage/?action=user&amp;ref=delete" style="display:inline"
                  onsubmit="return confirm('Delete <?php echo htmlspecialchars(addslashes((string)$user['email']), ENT_QUOTES); ?>?');">
                <?php echo \Nsfproject\helper\csrf::field(); ?>
                <input type="hidden" name="id" value="<?= (int)$user['id'] ?>">
                <button type="submit">Delete</button>
            </form>
        </td>
    </tr>
<?php endforeach; ?>
    </table>
