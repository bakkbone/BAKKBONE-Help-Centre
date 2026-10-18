<?php $v = fn(string $k, $d = '') => $s[$k] ?? old($k, $d); ?>
<p class="small"><a href="<?= e(url('/panel/admin/staff')) ?>"><?= t('← Agents') ?></a></p>
<div class="page-head"><h1><?= e($title) ?></h1>
  <?php if ($s): ?><div class="actions">
    <?php if ($s['totp_enabled']): ?><form method="post" action="<?= e(url('/panel/admin/staff/' . $s['id'] . '/reset-2fa')) ?>" data-confirm="<?= t('Reset two-factor authentication for this agent?') ?>"><?= csrf_field() ?><button class="btn btn-sm" type="submit"><?= t('Reset 2FA') ?></button></form><?php endif; ?>
    <form method="post" action="<?= e(url('/panel/admin/staff/' . $s['id'] . '/delete')) ?>" data-confirm="<?= t('Delete this agent? Their tickets become unassigned.') ?>"><?= csrf_field() ?><button class="btn btn-sm btn-danger" type="submit"><?= t('Delete') ?></button></form>
  </div><?php endif; ?>
</div>
<form class="card" method="post" action="<?= e(url($s ? '/panel/admin/staff/' . $s['id'] : '/panel/admin/staff/new')) ?>" autocomplete="off">
  <div class="card-body">
    <?= csrf_field() ?>
    <fieldset><legend><?= t('Account') ?></legend>
      <div class="form-grid">
        <div class="field"><label class="req" for="first_name"><?= t('First name') ?></label><input id="first_name" type="text" name="first_name" value="<?= e($v('first_name')) ?>" required></div>
        <div class="field"><label for="last_name"><?= t('Last name') ?></label><input id="last_name" type="text" name="last_name" value="<?= e($v('last_name')) ?>"></div>
        <div class="field"><label class="req" for="username"><?= t('Username') ?></label><input id="username" type="text" name="username" value="<?= e($v('username')) ?>" required pattern="[A-Za-z0-9._\-]{2,64}"></div>
        <div class="field"><label class="req" for="email"><?= t('Email') ?></label><input id="email" type="email" name="email" value="<?= e($v('email')) ?>" required></div>
        <div class="field"><label for="phone"><?= t('Phone') ?></label><input id="phone" type="tel" name="phone" value="<?= e($v('phone')) ?>"></div>
      </div>
      <div class="form-grid">
        <div class="field"><label for="password"><?= $s ? t('New password (leave blank to keep)') : t('Password (optional)') ?></label><input id="password" type="password" name="password" minlength="10" autocomplete="new-password"></div>
        <div class="field"><label for="password_confirm"><?= t('Confirm password') ?></label><input id="password_confirm" type="password" name="password_confirm" autocomplete="new-password"></div>
      </div>
      <p class="hint"><?= t('Agents without a password can sign in with an emailed code (if enabled in Portals & sign-in).') ?></p>
      <label class="check"><input type="checkbox" name="send_welcome" value="1"<?= checked(!$s) ?>> <?= t('Email the agent a sign-in code now') ?></label>
      <label class="check"><input type="checkbox" name="active" value="1"<?= checked($v('active', 1)) ?>> <?= t('Active') ?></label>
      <label class="check"><input type="checkbox" name="onvacation" value="1"<?= checked($v('onvacation', 0)) ?>> <?= t('On vacation (no alerts)') ?></label>
      <label class="check"><input type="checkbox" name="is_admin" value="1"<?= checked($v('is_admin', 0)) ?>> <?= t('Administrator (full access to everything, including this admin panel)') ?></label>
    </fieldset>
    <fieldset><legend><?= t('Access') ?></legend>
      <div class="form-grid">
        <div class="field"><label for="dept_id"><?= t('Primary department') ?></label><select id="dept_id" name="dept_id"><?php foreach ($depts as $d): ?><option value="<?= (int)$d['id'] ?>"<?= selected($d['id'], $v('dept_id')) ?>><?= e($d['name']) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label for="role_id"><?= t('Role') ?></label><select id="role_id" name="role_id"><?php foreach ($roles as $r): ?><option value="<?= (int)$r['id'] ?>"<?= selected($r['id'], $v('role_id')) ?>><?= e($r['name']) ?></option><?php endforeach; ?></select></div>
      </div>
      <p><strong class="small"><?= t('Additional departments') ?></strong></p>
      <div class="check-grid"><?php foreach ($depts as $d): ?><label class="check"><input type="checkbox" name="access[]" value="<?= (int)$d['id'] ?>"<?= checked(in_array((int)$d['id'], $access, true)) ?>> <?= e($d['name']) ?></label><?php endforeach; ?></div>
      <?php if ($teams): ?>
      <p><strong class="small"><?= t('Teams') ?></strong></p>
      <div class="check-grid"><?php foreach ($teams as $t): ?><label class="check"><input type="checkbox" name="teams[]" value="<?= (int)$t['id'] ?>"<?= checked(in_array((int)$t['id'], $memberOf, true)) ?>> <?= e($t['name']) ?></label><?php endforeach; ?></div>
      <?php endif; ?>
    </fieldset>
    <div class="field" style="max-width:360px"><label for="default_signature"><?= t('Default signature when replying') ?></label><select id="default_signature" name="default_signature"><?php $ds = (string)($v('default_signature')); ?><option value=""<?= selected('', $ds) ?>><?= t('Help desk default ({choice})', ['choice' => ['mine' => __('own signature'), 'dept' => __('department signature'), 'none' => __('no signature')][setting('default_signature')] ?? __('own signature')]) ?></option><option value="mine"<?= selected('mine', $ds) ?>><?= t('My signature') ?></option><option value="dept"<?= selected('dept', $ds) ?>><?= t('Department signature') ?></option><option value="none"<?= selected('none', $ds) ?>><?= t('No signature') ?></option></select></div>
    <div class="field"><label for="signature"><?= t('Signature (HTML)') ?></label><textarea id="signature" name="signature" data-editor style="min-height:120px"><?= e($v('signature')) ?></textarea></div>
    <div class="form-actions"><button class="btn btn-primary" type="submit"><?= t('Save agent') ?></button></div>
  </div>
</form>
