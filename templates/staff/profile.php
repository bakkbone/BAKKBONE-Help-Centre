<div class="page-head"><h1><?= t('My profile') ?></h1></div>
<div class="grid grid-2">
  <form class="card" method="post" action="<?= e(url('/panel/profile')) ?>">
    <div class="card-head"><h2><?= t('Details & signature') ?></h2></div>
    <div class="card-body">
      <?= csrf_field() ?>
      <div class="form-grid">
        <div class="field"><label class="req" for="first_name"><?= t('First name') ?></label><input id="first_name" type="text" name="first_name" value="<?= e($me['first_name']) ?>" required></div>
        <div class="field"><label for="last_name"><?= t('Last name') ?></label><input id="last_name" type="text" name="last_name" value="<?= e($me['last_name']) ?>"></div>
        <div class="field"><label><?= t('Username') ?></label><input type="text" value="<?= e($me['username']) ?>" disabled></div>
        <div class="field"><label><?= t('Email') ?></label><input type="email" value="<?= e($me['email']) ?>" disabled></div>
        <div class="field"><label for="phone"><?= t('Phone') ?></label><input id="phone" type="tel" name="phone" value="<?= e($me['phone']) ?>"></div>
        <div class="field"><label for="reply_redirect"><?= t('After replying, go to') ?></label><select id="reply_redirect" name="reply_redirect"><option value="ticket"<?= selected('ticket', $me['reply_redirect']) ?>><?= t('The ticket') ?></option><option value="queue"<?= selected('queue', $me['reply_redirect']) ?>><?= t('The ticket queue') ?></option></select></div>
        <div class="field"><label for="default_signature"><?= t('Default signature when replying') ?></label><select id="default_signature" name="default_signature"><?php $ds = (string)($me['default_signature'] ?? ''); ?><option value=""<?= selected('', $ds) ?>><?= t('Help desk default ({choice})', ['choice' => ['mine' => __('own signature'), 'dept' => __('department signature'), 'none' => __('no signature')][setting('default_signature')] ?? __('own signature')]) ?></option><option value="mine"<?= selected('mine', $ds) ?>><?= t('My signature') ?></option><option value="dept"<?= selected('dept', $ds) ?>><?= t('Department signature') ?></option><option value="none"<?= selected('none', $ds) ?>><?= t('No signature') ?></option></select></div>
      </div>
      <div class="field">
        <label for="signature"><?= t('Email signature (HTML)') ?></label>
        <textarea id="signature" name="signature" data-editor style="min-height:140px"><?= e($me['signature']) ?></textarea>
        <div class="hint"><?= t('Use the </> button to paste raw HTML (e.g. a designed signature with logo). Images must be hosted at an https:// URL.') ?></div>
      </div>
      <label class="check"><input type="checkbox" name="onvacation" value="1"<?= checked($me['onvacation']) ?>> <?= t('Vacation mode (stop receiving alerts)') ?></label>
      <div class="form-actions"><button class="btn btn-primary" type="submit"><?= t('Save profile') ?></button></div>
    </div>
  </form>

  <div>
    <form class="card" method="post" action="<?= e(url('/panel/profile/password')) ?>">
      <div class="card-head"><h2><?= $me['password_hash'] ? t('Change password') : t('Set a password') ?></h2></div>
      <div class="card-body">
        <?= csrf_field() ?>
        <?php if ($me['password_hash']): ?><div class="field"><label for="current_password"><?= t('Current password') ?></label><input id="current_password" type="password" name="current_password" required autocomplete="current-password"></div><?php endif; ?>
        <div class="field"><label for="password"><?= t('New password') ?></label><input id="password" type="password" name="password" minlength="10" required autocomplete="new-password"></div>
        <div class="field"><label for="password_confirm"><?= t('Confirm new password') ?></label><input id="password_confirm" type="password" name="password_confirm" required autocomplete="new-password"></div>
        <button class="btn" type="submit"><?= t('Update password') ?></button>
      </div>
    </form>

    <div class="card" id="two-factor">
      <div class="card-head"><h2><?= t('Two-factor authentication') ?></h2><?= $me['totp_enabled'] ? '<span class="badge badge-ok">' . t('On') . '</span>' : '<span class="badge">' . t('Off') . '</span>' ?></div>
      <div class="card-body">
        <?php if ($me['totp_enabled']): ?>
          <p class="muted"><?= t('Your account is protected with an authenticator app. You\'ll be asked for a code after signing in.') ?></p>
          <?php if (setting('staff_require_2fa') !== '1'): ?>
          <form method="post" action="<?= e(url('/panel/profile/2fa/disable')) ?>">
            <?= csrf_field() ?>
            <div class="inline-form"><input type="text" name="code" inputmode="numeric" maxlength="7" placeholder="<?= t('Current 6-digit code') ?>" required autocomplete="one-time-code"><button class="btn btn-danger" type="submit"><?= t('Turn off') ?></button></div>
          </form>
          <?php endif; ?>
        <?php elseif ($totpSecret): ?>
          <p><?= t('1. Scan this QR code with Google Authenticator, Microsoft Authenticator, 1Password, Authy or similar:') ?></p>
          <div class="qr" data-qr="<?= e($totpUri) ?>"></div>
          <p class="small muted" style="margin-top:8px"><?= t('Can\'t scan? Enter this key manually:') ?> <code><?= e(trim(chunk_split($totpSecret, 4, ' '))) ?></code></p>
          <p><?= t('2. Enter the 6-digit code from the app to confirm:') ?></p>
          <form method="post" action="<?= e(url('/panel/profile/2fa/enable')) ?>">
            <?= csrf_field() ?>
            <div class="inline-form"><input type="text" name="code" inputmode="numeric" maxlength="7" class="code-input" required autocomplete="one-time-code"><button class="btn btn-primary" type="submit"><?= t('Turn on') ?></button></div>
          </form>
        <?php else: ?>
          <p class="muted"><?= t('Add a second step to sign-in using a time-based code from an authenticator app.') ?></p>
          <form method="post" action="<?= e(url('/panel/profile/2fa/start')) ?>"><?= csrf_field() ?><button class="btn btn-primary" type="submit"><?= t('Set up two-factor authentication') ?></button></form>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
