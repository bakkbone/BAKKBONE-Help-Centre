<div class="page-head"><h1><?= t('My profile') ?></h1></div>
<form class="card" method="post" action="<?= e(url('/profile')) ?>" style="max-width:640px">
  <div class="card-body">
    <?= csrf_field() ?>
    <div class="field"><label class="req" for="name"><?= t('Name') ?></label><input id="name" type="text" name="name" value="<?= e($user['name']) ?>" required></div>
    <div class="field"><label><?= t('Email') ?></label><input type="email" value="<?= e($user['email']) ?>" disabled><div class="hint"><?= t('Contact us to change your email address.') ?></div></div>
    <div class="field"><label for="phone"><?= t('Phone') ?></label><input id="phone" type="tel" name="phone" value="<?= e($user['phone']) ?>"></div>
    <?php if ($org): ?><div class="field"><label><?= t('Organisation') ?></label><input type="text" value="<?= e($org['name']) ?>" disabled></div><?php endif; ?>
    <?php if (setting('client_password_login') === '1'): ?>
    <fieldset>
      <legend><?= $user['password_hash'] ? t('Change password') : t('Set a password') ?></legend>
      <?php if (!$user['password_hash']): ?><p class="small muted"><?= t('You currently sign in with emailed codes. You can optionally set a password too.') ?></p><?php endif; ?>
      <?php if ($user['password_hash']): ?><div class="field"><label for="current_password"><?= t('Current password') ?></label><input id="current_password" type="password" name="current_password" autocomplete="current-password"></div><?php endif; ?>
      <div class="field"><label for="password"><?= t('New password') ?></label><input id="password" type="password" name="password" minlength="10" autocomplete="new-password"></div>
      <div class="field"><label for="password_confirm"><?= t('Confirm new password') ?></label><input id="password_confirm" type="password" name="password_confirm" autocomplete="new-password"></div>
    </fieldset>
    <?php endif; ?>
    <div class="form-actions"><button class="btn btn-primary" type="submit"><?= t('Save') ?></button></div>
  </div>
</form>
