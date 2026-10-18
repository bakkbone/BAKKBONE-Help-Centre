<h1 style="font-size:1.3rem"><?= t('Two-factor authentication') ?></h1>
<p class="muted small"><?= t('Open your authenticator app and enter the 6-digit code for {helpdesk}.', ['helpdesk' => setting('helpdesk_name')]) ?></p>
<form method="post" action="<?= e(url('/panel/login/2fa')) ?>">
  <?= csrf_field() ?>
  <div class="field"><label for="code"><?= t('Authentication code') ?></label><input id="code" class="code-input" type="text" name="code" inputmode="numeric" maxlength="7" autocomplete="one-time-code" required autofocus></div>
  <button class="btn btn-primary btn-block" type="submit"><?= t('Verify') ?></button>
</form>
<p class="small center muted" style="margin-top:12px"><?= t('Lost your device? Ask an administrator to reset your two-factor authentication.') ?></p>
