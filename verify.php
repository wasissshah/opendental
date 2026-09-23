<?php
require __DIR__ . '/lib/bootstrap.php';

if (current_user()) redirect('index.php');

$email = $_SESSION['login_email'] ?? '';
if ($email === '') redirect('login.php');

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    if (isset($_POST['resend'])) {
        request_login_code($email);
        flash('If this email is registered, a new code was sent. Codes can be resent once a minute.');
        redirect('verify.php');
    }
    $user = verify_login_code($email, $_POST['code'] ?? '');
    if ($user) {
        unset($_SESSION['login_email']);
        session_regenerate_id(true);
        redirect($user['role'] === 'admin' ? 'locations.php' : 'my_locations.php');
    }
    $error = 'That code is wrong or has expired. Check the latest email, or send a new code.';
}

render_header('Enter code', '', true);
?>
<div class="logo-mark"><?= e(APP_NAME) ?></div>
<div class="card">
  <h2>Enter your code</h2>
  <p>We sent a 6-digit code to <b><?= e($email) ?></b> if it's registered. The code works for 10 minutes.</p>
  <?php if ($error): ?><div class="flash bad"><?= e($error) ?></div><?php endif; ?>
  <form method="post">
    <?= csrf_field() ?>
    <label>Code
      <input type="text" name="code" class="codeinput" inputmode="numeric" pattern="[0-9 ]{6,7}" maxlength="7" required autofocus autocomplete="one-time-code">
    </label>
    <button class="btn" style="width:100%">Log in</button>
  </form>
  <form method="post" style="margin-top:14px" class="actions">
    <?= csrf_field() ?>
    <button class="btn ghost small" name="resend" value="1">Send a new code</button>
    <a href="login.php" class="btn ghost small">Use a different email</a>
  </form>
</div>
<?php render_footer();
