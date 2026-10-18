<?php use Bakkbone\HelpCentre\Services\Tickets; ?>
<div class="page-head">
  <h1><?= e($e['title']) ?></h1>
  <div class="actions"><a class="btn btn-primary" href="<?= e(url('/panel/admin/' . $e['key'] . '/new')) ?>"><?= e($e['words']['add']) ?></a></div>
</div>
<div class="card card-flush"><div class="card-body">
<?php if (!$rows): ?><div class="empty-state small"><?= t('Nothing here yet.') ?></div><?php else: ?>
  <table class="table">
    <thead><tr><?php foreach ($e['columns'] as $label): ?><th><?= e($label) ?></th><?php endforeach; ?></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr data-href="<?= e(url('/panel/admin/' . $e['key'] . '/' . $r['id'])) ?>">
        <?php $first = true; foreach ($e['columns'] as $col => $label):
            $val = $r[$col] ?? '';
            $type = $e['fields'][$col]['type'] ?? 'text';
            if ($type === 'checkbox') { $out = $val ? '✓' : '<span class="faint">—</span>'; }
            elseif ($type === 'color') { $out = '<span class="dot" style="--c:' . e($val) . '"></span> <span class="mono small">' . e($val) . '</span>'; }
            elseif ($col === 'dept_id') { $out = e(Tickets::lookupName('departments', $val, '—')); }
            else { $out = e($val); } ?>
          <td <?= $first ? 'class="primary"' : 'data-label="' . e($label) . '"' ?>><?= $first ? '<a class="subject" href="' . e(url('/panel/admin/' . $e['key'] . '/' . $r['id'])) . '">' . $out . '</a>' : $out ?></td>
        <?php $first = false; endforeach; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
</div></div>
