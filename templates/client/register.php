<?php
use Bakkbone\HelpCentre\Services\Captcha;
$pw = setting('client_password_login') === '1';
$optional = setting('client_passwordless') === '1';
?>
<div class="card auth-card">
  <div class="card-body">
    <h1><?= t('Create an account') ?></h1>
    <p class="muted"><?= t('An account lets you see all of your tickets in one place. We\'ll email you a code to confirm your address.') ?></p>
    <form method="post" action="<?= e(url('/register')) ?>">
      <?= csrf_field() ?>
      <div class="field"><label class="req" for="name"><?= t('Full name') ?></label><input id="name" type="text" name="name" value="<?= e(old('name')) ?>" required autocomplete="name"></div>
      <div class="field"><label class="req" for="email"><?= t('Email address') ?></label><input id="email" type="email" name="email" value="<?= e(old('email')) ?>" required autocomplete="email"></div>
      <div class="field"><label for="phone"><?= t('Phone') ?></label><input id="phone" type="tel" name="phone" value="<?= e(old('phone')) ?>" autocomplete="tel"></div>
      <?php if ($pw): ?>
        <div class="field"><label class="<?= $optional ? '' : 'req' ?>" for="password"><?= $optional ? t('Password (optional)') : t('Password') ?></label>
          <input id="password" type="password" name="password" minlength="10" autocomplete="new-password" <?= $optional ? '' : 'required' ?>>
          <div class="hint"><?= $optional ? t('At least 10 characters. Leave blank to always sign in with an emailed code.') : t('At least 10 characters.') ?></div></div>
        <div class="field"><label for="password_confirm"><?= t('Confirm password') ?></label><input id="password_confirm" type="password" name="password_confirm" autocomplete="new-password"></div>
      <?php endif; ?>
      <?= Captcha::widget() ?>
      <button class="btn btn-primary btn-block" type="submit"><?= t('Create account') ?></button>
    </form>
    <p class="small center muted" style="margin-top:14px"><?= t('Already registered? {link}Sign in{/link}', ['link' => tag('<a href="' . e(url('/login')) . '">')]) ?></p>
  </div>
</div>
