<p class="small"><a href="<?= e(url('/panel/admin/addons')) ?>"><?= t('← Add-ons') ?></a></p>
<div class="page-head"><h1><?= t('{name} settings', ['name' => __($info['name'])]) ?></h1></div>
<?php if (!$isOn): ?><div class="alert alert-warning"><?= t('This add-on is switched off. You can still change its settings; they take effect when it\'s switched on.') ?></div><?php endif; ?>
<?php if (!$defs): ?>
  <div class="card"><div class="card-body"><p class="muted" style="margin:0"><?= t('This add-on has no settings.') ?></p></div></div>
<?php else: ?>
<form class="card" method="post" action="<?= e(url('/panel/admin/addons/' . $info['slug'])) ?>">
  <div class="card-body">
    <?= csrf_field() ?>
    <?php foreach ($defs as $key => $d):
        $type = (string)($d['type'] ?? 'text');
        $id = 'set-' . $key;
        $val = old($key, $values[$key]);
        $label = __((string)($d['label'] ?? $key));
        $hint = isset($d['hint']) ? __((string)$d['hint']) : '';
        $req = !empty($d['required']); ?>
      <?php if ($type === 'checkbox'): ?>
        <label class="check"><input type="checkbox" name="<?= e($key) ?>" value="1"<?= checked($val === true || $val === '1') ?>> <?= e($label) ?></label>
        <?php if ($hint !== ''): ?><div class="hint" style="margin-block:-8px 16px;margin-inline-start:30px"><?= e($hint) ?></div><?php endif; ?>
      <?php else: ?>
        <div class="field">
          <label for="<?= e($id) ?>"<?= $req ? ' class="req"' : '' ?>><?= e($label) ?></label>
          <?php if ($type === 'textarea'): ?>
            <textarea id="<?= e($id) ?>" name="<?= e($key) ?>" rows="5"<?= $req ? ' required' : '' ?>><?= e((string)$val) ?></textarea>
          <?php elseif ($type === 'select'):
              $opts = (array)($d['options'] ?? []);
              if (array_is_list($opts)) { $opts = array_combine(array_map('strval', $opts), array_map('strval', $opts)); } ?>
            <select id="<?= e($id) ?>" name="<?= e($key) ?>">
              <?php foreach ($opts as $ov => $ol): ?><option value="<?= e((string)$ov) ?>"<?= selected((string)$ov, (string)$val) ?>><?= e(__((string)$ol)) ?></option><?php endforeach; ?>
            </select>
          <?php elseif ($type === 'password'): ?>
            <input id="<?= e($id) ?>" type="password" name="<?= e($key) ?>" autocomplete="new-password" placeholder="<?= !empty($hasPassword[$key]) ? t('Saved - leave blank to keep') : '' ?>">
            <?php if (!empty($hasPassword[$key])): ?><label class="check small" style="margin-top:6px"><input type="checkbox" name="<?= e($key) ?>__clear" value="1"> <?= t('Clear the saved value') ?></label><?php endif; ?>
          <?php else:
              $inputType = ['number' => 'number', 'email' => 'email', 'url' => 'url', 'color' => 'color'][$type] ?? 'text'; ?>
            <input id="<?= e($id) ?>" type="<?= $inputType ?>" name="<?= e($key) ?>" value="<?= e((string)$val) ?>"<?= $req ? ' required' : '' ?><?= $inputType === 'number' ? ' step="any"' : '' ?>>
          <?php endif; ?>
          <?php if ($hint !== ''): ?><div class="hint"><?= e($hint) ?></div><?php endif; ?>
        </div>
      <?php endif; ?>
    <?php endforeach; ?>
    <div class="form-actions"><button class="btn btn-primary" type="submit"><?= t('Save settings') ?></button></div>
  </div>
</form>
<?php endif; ?>
