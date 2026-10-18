<?php
/** @var list<array> $fields @var array<int,string> $values */
use Bakkbone\HelpCentre\Services\Tickets;

$values = $values ?? [];
foreach ($fields as $f):
    $id = (int)$f['id'];
    $name = 'field[' . $id . ']';
    $val = (string)(old('field')[$id] ?? $values[$id] ?? '');
    $req = $f['required'] ? ' required' : '';
    $topics = trim((string)$f['topic_ids']);
?>
<div class="field" <?= $topics !== '' ? 'data-topics="' . e($topics) . '"' : '' ?>>
  <?php if ($f['type'] === 'checkbox'): ?>
    <input type="hidden" name="<?= e($name) ?>" value="0">
    <label class="check"><input type="checkbox" name="<?= e($name) ?>" value="1"<?= checked($val === '1') ?><?= $req ?>> <span class="<?= $f['required'] ? 'req' : '' ?>"><?= e($f['label']) ?></span></label>
  <?php else: ?>
    <label for="cf<?= $id ?>" class="<?= $f['required'] ? 'req' : '' ?>"><?= e($f['label']) ?></label>
    <?php if ($f['type'] === 'textarea'): ?>
      <textarea id="cf<?= $id ?>" name="<?= e($name) ?>"<?= $req ?>><?= e($val) ?></textarea>
    <?php elseif ($f['type'] === 'select'): ?>
      <select id="cf<?= $id ?>" name="<?= e($name) ?>"<?= $req ?>>
        <option value=""><?= t('— Select —') ?></option>
        <?php foreach (Tickets::fieldOptions($f) as $o): ?><option value="<?= e($o) ?>"<?= selected($o, $val) ?>><?= e($o) ?></option><?php endforeach; ?>
      </select>
    <?php elseif ($f['type'] === 'radio'): ?>
      <?php foreach (Tickets::fieldOptions($f) as $i => $o): ?>
        <label class="check"><input type="radio" name="<?= e($name) ?>" value="<?= e($o) ?>"<?= checked($o === $val) ?><?= $req && $i === 0 ? ' required' : '' ?>> <?= e($o) ?></label>
      <?php endforeach; ?>
    <?php else:
        $type = match ($f['type']) { 'date' => 'date', 'number' => 'number', 'email' => 'email', 'phone' => 'tel', 'url' => 'url', default => 'text' }; ?>
      <input id="cf<?= $id ?>" type="<?= $type ?>" name="<?= e($name) ?>" value="<?= e($val) ?>"<?= $req ?><?= $type === 'number' ? ' step="any"' : '' ?>>
    <?php endif; ?>
  <?php endif; ?>
  <?php if ($f['hint']): ?><div class="hint"><?= e($f['hint']) ?></div><?php endif; ?>
</div>
<?php endforeach; ?>
