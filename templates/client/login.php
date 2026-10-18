<?php
use Bakkbone\HelpCentre\Services\Captcha;
$pw = setting('client_password_login') === '1';
$code = setting('client_passwordless') === '1';
$cap = setting('captcha_on_login') === '1';
?>
<div class="card auth-card">
  <div class="card-body">
    <h1><?= t('Sign in') ?></h1>
    <?php if ($code): ?>
      <p class="muted"><?= $pw ? t('Enter your email and we\'ll send you a one-time sign-in code, or use your password.') : t('Enter your email and we\'ll send you a one-time sign-in code.') ?></p>
      <form method="post" action="<?= e(url('/login/code')) ?>">
        <?= csrf_field() ?>
        <div class="field"><label for="code-email"><?= t('Email address') ?></label><input id="code-email" type="email" name="email" value="<?= e(old('email')) ?>" required autocomplete="email"></div>
        <?php if ($cap): ?><?= Captcha::widget() ?><?php endif; ?>
        <button class="btn btn-primary btn-block" type="submit"><?= t('Email me a sign-in code') ?></button>
      </form>
    <?php endif; ?>
    <?php if ($pw && $code): ?><div class="or"><?= t('or sign in with a password') ?></div><?php endif; ?>
    <?php if ($pw): ?>
      <form method="post" action="<?= e(url('/login')) ?>">
        <?= csrf_field() ?>
        <div class="field"><label for="email"><?= t('Email address') ?></label><input id="email" type="email" name="email" value="<?= e(old('email')) ?>" required autocomplete="username"></div>
        <div class="field"><label for="password"><?= t('Password') ?></label><input id="password" type="password" name="password" required autocomplete="current-password"></div>
        <?php if ($cap && !$code): ?><?= Captcha::widget() ?><?php endif; ?>
        <button class="btn <?= $code ? '' : 'btn-primary' ?> btn-block" type="submit"><?= t('Sign in') ?></button>
        <p class="small center" style="margin-top:12px"><a href="<?= e(url('/reset')) ?>"><?= t('Forgot your password?') ?></a></p>
      </form>
    <?php endif; ?>
    <?php if (setting('client_registration') === 'public'): ?>
      <hr><p class="small center muted"><?= t('New here? {link}Create an account{/link} or just {link2}open a ticket{/link2}.', ['link' => tag('<a href="' . e(url('/register')) . '">'), 'link2' => tag('<a href="' . e(url('/open')) . '">')]) ?></p>
    <?php endif; ?>
  </div>
</div>
