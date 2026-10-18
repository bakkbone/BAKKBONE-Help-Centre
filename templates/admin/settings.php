<?php use Bakkbone\HelpCentre\Core\Settings; $def = $tabs[$tab]; ?>
<div class="page-head"><h1><?= t('Settings') ?></h1></div>
<div class="tabs">
  <?php foreach ($tabs as $k => $t): ?><a href="<?= e(url('/panel/admin/settings/' . $k)) ?>" class="<?= $k === $tab ? 'active' : '' ?>"><?= e($t['label']) ?></a><?php endforeach; ?>
</div>
<form class="card" method="post" action="<?= e(url('/panel/admin/settings/' . $tab)) ?>">
  <div class="card-body">
    <?= csrf_field() ?>
    <?php foreach ($def['fields'] as $key => $f):
        [$label, $type] = $f;
        $opts = $f[2] ?? null;
        $hint = is_string($opts) ? $opts : ($f[3] ?? null);
        $val = (string)Settings::get($key);
        $id = 's_' . $key; ?>
      <?php if ($type === 'bool'): ?>
        <label class="check"><input type="checkbox" name="<?= e($key) ?>" value="1"<?= checked($val === '1') ?>> <?= e($label) ?></label>
        <?php if ($hint): ?><div class="hint" style="margin-block:-6px 12px;margin-inline-start:26px"><?= e($hint) ?></div><?php endif; ?>
      <?php else: ?>
        <div class="field" style="<?= in_array($type, ['html', 'textarea', 'multi'], true) ? '' : 'max-width:560px' ?>">
          <label for="<?= e($id) ?>"><?= e($label) ?></label>
          <?php if ($type === 'select'): ?>
            <select id="<?= e($id) ?>" name="<?= e($key) ?>"><?php foreach ($opts as $v => $l): ?><option value="<?= e($v) ?>"<?= selected($v, $val) ?>><?= e($l) ?></option><?php endforeach; ?></select>
          <?php elseif ($type === 'multi'): $cur = Settings::list($key); ?>
            <div class="check-grid"><?php foreach ($opts as $v => $l): ?><label class="check"><input type="checkbox" name="<?= e($key) ?>[]" value="<?= e($v) ?>"<?= checked(in_array($v, $cur, true)) ?>> <?= e($l) ?></label><?php endforeach; ?></div>
          <?php elseif ($type === 'textarea'): ?>
            <textarea id="<?= e($id) ?>" name="<?= e($key) ?>" style="min-height:80px"><?= e($val) ?></textarea>
          <?php elseif ($type === 'html'): ?>
            <textarea id="<?= e($id) ?>" name="<?= e($key) ?>" data-editor><?= e($val) ?></textarea>
          <?php elseif ($type === 'number'): ?>
            <input id="<?= e($id) ?>" type="number" min="0" name="<?= e($key) ?>" value="<?= e($val) ?>" style="max-width:160px">
          <?php elseif ($type === 'color'): ?>
            <input id="<?= e($id) ?>" type="color" name="<?= e($key) ?>" value="<?= e(valid_hex_color($val)) ?>">
          <?php elseif ($type === 'secret'): ?>
            <input id="<?= e($id) ?>" type="password" name="<?= e($key) ?>" value="" placeholder="<?= $val !== '' ? t('•••••••• (saved - leave blank to keep)') : '' ?>" autocomplete="new-password">
          <?php else: ?>
            <input id="<?= e($id) ?>" type="text" name="<?= e($key) ?>" value="<?= e($val) ?>">
          <?php endif; ?>
          <?php if ($hint): ?><div class="hint"><?= e($hint) ?></div><?php endif; ?>
        </div>
      <?php endif; ?>
    <?php endforeach; ?>
    <div class="form-actions"><button class="btn btn-primary" type="submit"><?= t('Save settings') ?></button></div>
  </div>
</form>
