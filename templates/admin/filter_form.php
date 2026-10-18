<?php
use Bakkbone\HelpCentre\Services\FilterEngine;
$sources = array_filter(explode(',', (string)($f['sources'] ?? '')));
$ruleRow = function (array $r = []) {
    $h = '<div class="rule-row"><select name="rule_field[]" aria-label="' . t('Field') . '">';
    foreach (FilterEngine::FIELDS as $k => $l) {
        $h .= '<option value="' . e($k) . '"' . selected($k, $r['field'] ?? '') . '>' . e(__($l)) . '</option>';
    }
    $h .= '</select><select name="rule_op[]" aria-label="' . t('Condition') . '">';
    foreach (FilterEngine::OPS as $k => $l) {
        $h .= '<option value="' . e($k) . '"' . selected($k, $r['op'] ?? '') . '>' . e(__($l)) . '</option>';
    }
    $h .= '</select><input type="text" name="rule_value[]" value="' . e($r['value'] ?? '') . '" placeholder="' . t('Value') . '" aria-label="' . t('Value') . '">';
    return $h . '<button class="btn btn-sm btn-ghost" type="button" data-remove-rule title="' . t('Remove rule') . '">✕</button></div>';
};
?>
<p class="small"><a href="<?= e(url('/panel/admin/filters')) ?>"><?= t('← Filters') ?></a></p>
<div class="page-head"><h1><?= e($title) ?></h1>
  <?php if ($f): ?><div class="actions"><form method="post" action="<?= e(url('/panel/admin/filters/' . $f['id'] . '/delete')) ?>" data-confirm="<?= t('Delete this filter?') ?>"><?= csrf_field() ?><button class="btn btn-sm btn-danger" type="submit"><?= t('Delete') ?></button></form></div><?php endif; ?>
</div>
<form class="card" method="post" action="<?= e(url($f ? '/panel/admin/filters/' . $f['id'] : '/panel/admin/filters/new')) ?>">
  <div class="card-body">
    <?= csrf_field() ?>
    <div class="form-grid">
      <div class="field"><label class="req" for="name"><?= t('Name') ?></label><input id="name" type="text" name="name" value="<?= e($f['name'] ?? '') ?>" required></div>
      <div class="field"><label for="sort"><?= t('Execution order') ?></label><input id="sort" type="number" name="sort" value="<?= (int)($f['sort'] ?? 0) ?>"></div>
    </div>
    <label class="check"><input type="checkbox" name="active" value="1"<?= checked($f['active'] ?? 1) ?>> <?= t('Active') ?></label>
    <label class="check"><input type="checkbox" name="stop_on_match" value="1"<?= checked($f['stop_on_match'] ?? 0) ?>> <?= t('Stop processing further filters when this one matches') ?></label>

    <fieldset>
      <legend><?= t('Apply to tickets from') ?></legend>
      <div class="check-grid">
        <?php foreach (['email' => __('Email'), 'web' => __('Portal'), 'api' => 'API', 'phone' => __('Phone (agent)'), 'other' => __('Other (agent)')] as $k => $l): ?>
          <label class="check"><input type="checkbox" name="sources[]" value="<?= $k ?>"<?= checked(in_array($k, $sources, true)) ?>> <?= e($l) ?></label>
        <?php endforeach; ?>
      </div>
      <div class="hint"><?= t('None ticked = all sources.') ?></div>
    </fieldset>

    <fieldset data-rules>
      <legend><?= t('Rules') ?></legend>
      <div class="field" style="max-width:320px"><select name="match" aria-label="<?= t('Match') ?>"><option value="all"<?= selected(1, $f['match_all'] ?? 1) ?>><?= t('Match ALL rules') ?></option><option value="any"<?= selected(0, $f['match_all'] ?? 1) ?>><?= t('Match ANY rule') ?></option></select></div>
      <div data-rule-rows><?php foreach ($rules as $r): ?><?= $ruleRow($r) ?><?php endforeach; ?></div>
      <template><?= $ruleRow() ?></template>
      <button class="btn btn-sm" type="button" data-add-rule><?= t('+ Add rule') ?></button>
      <div class="hint"><?= t('Matching is case-insensitive. Regex example: <code>^(invoice|receipt)</code>') ?></div>
    </fieldset>

    <fieldset>
      <legend><?= t('Actions') ?></legend>
      <label class="check"><input type="checkbox" name="action_reject" value="1"<?= checked(!empty($actions['reject'])) ?>> <?= t('<strong>Reject</strong> the ticket (no ticket is created, nothing is sent)') ?></label>
      <label class="check"><input type="checkbox" name="action_disable_autoresponse" value="1"<?= checked(!empty($actions['disable_autoresponse'])) ?>> <?= t('Don\'t send the new-ticket auto-response') ?></label>
      <div class="form-grid" style="margin-top:8px">
        <?php foreach (['dept_id' => __('Set department'), 'topic_id' => __('Set help topic'), 'priority_id' => __('Set priority'), 'sla_id' => __('Set SLA plan'), 'status_id' => __('Set status'), 'staff_id' => __('Assign to agent'), 'team_id' => __('Assign to team')] as $k => $l): ?>
          <div class="field"><label for="a_<?= $k ?>"><?= e($l) ?></label>
            <select id="a_<?= $k ?>" name="action_<?= $k ?>"><option value=""><?= t('— No change —') ?></option><?php foreach ($lists[$k] as $id => $name): ?><option value="<?= (int)$id ?>"<?= selected($id, $actions[$k] ?? '') ?>><?= e($name) ?></option><?php endforeach; ?></select></div>
        <?php endforeach; ?>
      </div>
    </fieldset>
    <div class="field"><label for="notes"><?= t('Notes') ?></label><textarea id="notes" name="notes" style="min-height:70px"><?= e($f['notes'] ?? '') ?></textarea></div>
    <div class="form-actions"><button class="btn btn-primary" type="submit"><?= t('Save filter') ?></button></div>
  </div>
</form>
