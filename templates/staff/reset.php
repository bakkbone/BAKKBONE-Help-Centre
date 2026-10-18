<h1 style="font-size:1.3rem"><?= t('Reset password') ?></h1>
<p class="muted small"><?= t('Enter your username or email and we\'ll email you a reset code.') ?></p>
<form method="post" action="<?= e(url('/panel/reset')) ?>">
  <?= csrf_field() ?>
  <div class="field"><label for="login"><?= t('Username or email') ?></label><input id="login" type="text" name="login" required autofocus></div>
  <button class="btn btn-primary btn-block" type="submit"><?= t('Send reset code') ?></button>
</form>
<p class="small center" style="margin-top:12px"><a href="<?= e(url('/panel/login')) ?>"><?= t('Back to sign in') ?></a></p>
