<?php
$pw = setting('staff_password_login') === '1';
$code = setting('staff_passwordless') === '1';
?>
<h1 style="font-size:1.3rem"><?= t('Agent sign in') ?></h1>
<?php if ($pw): ?>
<form method="post" action="<?= e(url('/panel/login')) ?>">
  <?= csrf_field() ?>
  <div class="field"><label for="login"><?= t('Username or email') ?></label><input id="login" type="text" name="login" value="<?= e(old('login')) ?>" required autocomplete="username" autofocus></div>
  <div class="field"><label for="password"><?= t('Password') ?></label><input id="password" type="password" name="password" required autocomplete="current-password"></div>
  <button class="btn btn-primary btn-block" type="submit"><?= t('Sign in') ?></button>
  <p class="small center" style="margin-top:10px"><a href="<?= e(url('/panel/reset')) ?>"><?= t('Forgot password?') ?></a></p>
</form>
<?php endif; ?>
<?php if ($pw && $code): ?><div class="or"><?= t('or') ?></div><?php endif; ?>
<?php if ($code): ?>
<form method="post" action="<?= e(url('/panel/login/code')) ?>">
  <?= csrf_field() ?>
  <?php if (!$pw): ?><div class="field"><label for="login2"><?= t('Username or email') ?></label><input id="login2" type="text" name="login" required autocomplete="username" autofocus></div>
  <?php else: ?><input type="hidden" name="login" value="" data-mirror="login"><?php endif; ?>
  <button class="btn btn-block" type="submit" <?= $pw ? 'formnovalidate' : '' ?> id="code-btn"><?= t('Email me a sign-in code') ?></button>
  <?php if ($pw): ?><p class="hint center"><?= t('Uses the username or email entered above.') ?></p><?php endif; ?>
</form>
<?php endif; ?>
