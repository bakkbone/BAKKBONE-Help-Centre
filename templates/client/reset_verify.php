<div class="card auth-card">
  <div class="card-body">
    <h1><?= t('Choose a new password') ?></h1>
    <p class="muted"><?= t('Enter the code we emailed to') ?> <strong><?= e($email) ?></strong> <?= t('and your new password.') ?></p>
    <form method="post" action="<?= e(url('/reset/verify')) ?>">
      <?= csrf_field() ?>
      <div class="field"><label for="code"><?= t('Code') ?></label><input id="code" class="code-input" type="text" name="code" inputmode="numeric" maxlength="7" autocomplete="one-time-code" required></div>
      <div class="field"><label for="password"><?= t('New password') ?></label><input id="password" type="password" name="password" minlength="10" autocomplete="new-password" required><div class="hint"><?= t('At least 10 characters.') ?></div></div>
      <div class="field"><label for="password_confirm"><?= t('Confirm new password') ?></label><input id="password_confirm" type="password" name="password_confirm" autocomplete="new-password" required></div>
      <button class="btn btn-primary btn-block" type="submit"><?= t('Update password') ?></button>
    </form>
  </div>
</div>
