<?php $val = fn(string $k, array $f) => $row[$k] ?? old($k, $f['default'] ?? ''); ?>
<p class="small"><a href="<?= e(url('/panel/admin/' . $e['key'])) ?>"><?= t('← {name}', ['name' => $e['title']]) ?></a></p>
<div class="page-head"><h1><?= e($title) ?></h1>
  <?php if ($row): ?><div class="actions"><form method="post" action="<?= e(url('/panel/admin/' . $e['key'] . '/' . $row['id'] . '/delete')) ?>" data-confirm="<?= e($e['words']['confirm']) ?>"><?= csrf_field() ?><button class="btn btn-sm btn-danger" type="submit"><?= t('Delete') ?></button></form></div><?php endif; ?>
</div>
<form class="card" method="post" action="<?= e(url('/panel/admin/' . $e['key'] . ($row ? '/' . $row['id'] : '/new'))) ?>" style="max-width:860px">
  <div class="card-body">
    <?= csrf_field() ?>
    <?php foreach ($e['fields'] as $k => $f): $v = $val($k, $f); $id = 'f_' . $k; ?>
      <?php if ($f['type'] === 'checkbox'): ?>
        <label class="check"><input type="checkbox" name="<?= e($k) ?>" value="1"<?= checked($v) ?>> <?= e($f['label']) ?></label>
      <?php else: ?>
      <div class="field">
        <label for="<?= e($id) ?>" class="<?= !empty($f['required']) ? 'req' : '' ?>"><?= e($f['label']) ?></label>
        <?php switch ($f['type']):
            case 'select': ?>
            <select id="<?= e($id) ?>" name="<?= e($k) ?>"><?php if (empty($f['required'])): ?><option value=""><?= t('— None —') ?></option><?php endif; ?>
              <?php foreach ($f['options'] as $ov => $ol): ?><option value="<?= e($ov) ?>"<?= selected($ov, $v) ?>><?= e($ol) ?></option><?php endforeach; ?></select>
            <?php break; case 'multiselect': $cur = array_filter(explode(',', (string)$v)); ?>
            <div class="check-grid"><?php foreach ($f['options'] as $ov => $ol): ?><label class="check"><input type="checkbox" name="<?= e($k) ?>[]" value="<?= e($ov) ?>"<?= checked(in_array((string)$ov, $cur, true)) ?>> <?= e($ol) ?></label><?php endforeach; ?></div>
            <?php break; case 'relation': ?>
            <div class="check-grid"><?php foreach ($f['options'] as $ov => $ol): ?><label class="check"><input type="checkbox" name="<?= e($k) ?>[]" value="<?= e($ov) ?>"<?= checked(in_array((int)$ov, $f['value'] ?? [], true)) ?>> <?= e($ol) ?></label><?php endforeach; ?></div>
            <?php break; case 'checkboxes': $cur = json_decode((string)$v, true) ?: []; ?>
            <div class="check-grid"><?php foreach ($f['options'] as $ov => $ol): ?><label class="check"><input type="checkbox" name="<?= e($k) ?>[]" value="<?= e($ov) ?>"<?= checked(in_array($ov, $cur, true)) ?>> <?= e($ol) ?></label><?php endforeach; ?></div>
            <?php break; case 'textarea': ?>
            <textarea id="<?= e($id) ?>" name="<?= e($k) ?>" style="min-height:90px"><?= e($v) ?></textarea>
            <?php break; case 'html': ?>
            <textarea id="<?= e($id) ?>" name="<?= e($k) ?>" data-editor style="min-height:120px"><?= e($v) ?></textarea>
            <?php break; case 'number': ?>
            <input id="<?= e($id) ?>" type="number" name="<?= e($k) ?>" value="<?= e($v) ?>" style="max-width:180px"<?= !empty($f['required']) ? ' required' : '' ?>>
            <?php break; case 'color': ?>
            <input id="<?= e($id) ?>" type="color" name="<?= e($k) ?>" value="<?= e(valid_hex_color((string)$v)) ?>">
            <?php break; default: ?>
            <input id="<?= e($id) ?>" type="text" name="<?= e($k) ?>" value="<?= e($v) ?>"<?= !empty($f['required']) ? ' required' : '' ?>>
        <?php endswitch; ?>
        <?php if (!empty($f['hint'])): ?><div class="hint"><?= e($f['hint']) ?></div><?php endif; ?>
      </div>
      <?php endif; ?>
    <?php endforeach; ?>
    <div class="form-actions"><button class="btn btn-primary" type="submit"><?= t('Save') ?></button><a class="btn btn-ghost" href="<?= e(url('/panel/admin/' . $e['key'])) ?>"><?= t('Cancel') ?></a></div>
  </div>
</form>
