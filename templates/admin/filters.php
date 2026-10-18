<?php use Bakkbone\HelpCentre\Services\FilterEngine; ?>
<div class="page-head">
  <h1><?= t('Ticket filters') ?></h1>
  <div class="actions"><a class="btn btn-primary" href="<?= e(url('/panel/admin/filters/new')) ?>"><?= t('New filter') ?></a></div>
</div>
<p class="muted"><?= t('Filters run on every new ticket (email, portal, API, agent) in order. They can route, prioritise, assign, suppress auto-replies or silently reject tickets.') ?></p>
<div class="card card-flush"><div class="card-body">
<?php if (!$rows): ?><div class="empty-state"><h3><?= t('No filters') ?></h3><p><?= t('Example: tickets from <code>@bigclient.com.au</code> → High priority, assign to Account Managers team.') ?></p></div><?php else: ?>
  <table class="table">
    <thead><tr><th><?= t('Order') ?></th><th><?= t('Name') ?></th><th><?= t('Rules') ?></th><th><?= t('Actions') ?></th><th><?= t('Status') ?></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $f): $rules = json_decode((string)$f['rules'], true) ?: []; $acts = json_decode((string)$f['actions'], true) ?: []; ?>
      <tr data-href="<?= e(url('/panel/admin/filters/' . $f['id'])) ?>">
        <td data-label="<?= t('Order') ?>"><?= (int)$f['sort'] ?></td>
        <td class="primary"><a class="subject" href="<?= e(url('/panel/admin/filters/' . $f['id'])) ?>"><?= e($f['name']) ?></a></td>
        <td data-label="<?= t('Rules') ?>" class="small"><?= e(implode($f['match_all'] ? ' ' . __('AND') . ' ' : ' ' . __('OR') . ' ', array_map(fn($r) => __('{field} {condition} "{value}"', ['field' => __(FilterEngine::FIELDS[$r['field']] ?? $r['field']), 'condition' => __(FilterEngine::OPS[$r['op']] ?? $r['op']), 'value' => $r['value']]), $rules))) ?></td>
        <td data-label="<?= t('Actions') ?>" class="small"><?= e(implode(', ', array_map(fn($k) => __(FilterEngine::ACTIONS[$k] ?? $k), array_keys($acts)))) ?></td>
        <td data-label="<?= t('Status') ?>"><?= $f['active'] ? '<span class="badge badge-ok">' . t('Active') . '</span>' : '<span class="badge">' . t('Disabled') . '</span>' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
</div></div>
