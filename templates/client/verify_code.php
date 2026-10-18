<div class="card auth-card">
  <div class="card-body">
    <h1><?= e($title) ?></h1>
    <p class="muted"><?= tn('We sent a 6-digit code to {email}. It expires in {n} minute.', 'We sent a 6-digit code to {email}. It expires in {n} minutes.', (int)setting('otp_ttl_minutes'), ['email' => raw('<strong>' . e($email) . '</strong>')]) ?></p>
    <form method="post" action="<?= e(url($action)) ?>">
      <?= csrf_field() ?>
      <div class="field"><label for="code"><?= t('Code') ?></label>
        <input id="code" class="code-input" type="text" name="code" inputmode="numeric" pattern="[0-9 ]{6,7}" maxlength="7" autocomplete="one-time-code" required autofocus></div>
      <button class="btn btn-primary btn-block" type="submit"><?= t('Continue') ?></button>
    </form>
    <?php if (!empty($resend)): ?>
    <form method="post" action="<?= e(url($resend)) ?>" style="margin-top:12px" class="center">
      <?= csrf_field() ?><input type="hidden" name="email" value="<?= e($email) ?>">
      <button class="link-btn small" type="submit"><?= t('Didn\'t get it? Send a new code') ?></button>
    </form>
    <?php endif; ?>
    <p class="small center muted" style="margin-top:12px"><?= t('Check your spam folder if it hasn\'t arrived after a minute.') ?></p>
  </div>
</div>
