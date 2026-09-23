<?php
require __DIR__ . '/lib/bootstrap.php';

if (current_user()) redirect('index.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $email = strtolower(trim($_POST['email'] ?? ''));
    request_login_code($email);
    $_SESSION['login_email'] = $email;
    // Same message whether or not the email is registered
    flash('If this email is registered, a code was sent. It may take a minute to arrive.');
    redirect('verify.php');
}

render_header('Log in', '', true);
?>
<div class="logo-mark"><?= e(APP_NAME) ?></div>
<div class="card">
  <h2>Log in</h2>
  <p>Enter your email and we'll send you a 6-digit code. No password needed.</p>
  <form method="post">
    <?= csrf_field() ?>
    <label>Email
      <input type="email" name="email" required autofocus autocomplete="email">
    </label>
    <button class="btn" style="width:100%">Send code</button>
  </form>
</div>
<?php render_footer();
