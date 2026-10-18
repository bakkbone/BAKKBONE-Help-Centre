<h1 style="font-size:1.3rem"><?= e($title) ?></h1>
<p class="muted small"><?= tn('If {login} is a valid agent account, a 6-digit code has been emailed to it. It expires in {n} minute.', 'If {login} is a valid agent account, a 6-digit code has been emailed to it. It expires in {n} minutes.', (int)setting('otp_ttl_minutes'), ['login' => raw('<strong>' . e($login) . '</strong>')]) ?></p>
<form method="post" action="<?= e(url($action)) ?>">
  <?= csrf_field() ?>
  <div class="field"><label for="code"><?= t('Code') ?></label><input id="code" class="code-input" type="text" name="code" inputmode="numeric" maxlength="7" autocomplete="one-time-code" required autofocus></div>
  <?php if ($reset): ?>
    <div class="field"><label for="password"><?= t('New password') ?></label><input id="password" type="password" name="password" minlength="10" required autocomplete="new-password"></div>
    <div class="field"><label for="password_confirm"><?= t('Confirm new password') ?></label><input id="password_confirm" type="password" name="password_confirm" required autocomplete="new-password"></div>
  <?php endif; ?>
  <button class="btn btn-primary btn-block" type="submit"><?= $reset ? t('Update password') : t('Continue') ?></button>
</form>
<p class="small center" style="margin-top:12px"><a href="<?= e(url('/panel/login')) ?>"><?= t('Start again') ?></a></p>
