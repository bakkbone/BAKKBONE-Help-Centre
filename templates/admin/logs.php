<div class="page-head"><h1><?= t('System log') ?></h1></div>
<div class="pills">
  <?php foreach (['' => __('All'), 'error' => __('Errors'), 'warning' => __('Warnings'), 'info' => __('Info')] as $k => $l): ?>
    <a href="<?= e(url('/panel/admin/logs', array_filter(['level' => $k]))) ?>" class="<?= $level === $k ? 'active' : '' ?>"><?= e($l) ?></a>
  <?php endforeach; ?>
</div>
<div class="card card-flush"><div class="card-body">
<?php if (!$rows): ?><div class="empty-state"><?= t('Nothing logged.') ?></div><?php else: ?>
  <table class="table">
    <thead><tr><th><?= t('When') ?></th><th><?= t('Level') ?></th><th><?= t('Event') ?></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td data-label="<?= t('When') ?>" class="nowrap small"><?= e(fmt_dt($r['created_at'])) ?></td>
        <td data-label="<?= t('Level') ?>"><span class="badge <?= $r['level'] === 'error' ? 'badge-bad' : ($r['level'] === 'warning' ? 'badge-warn' : '') ?>"><?= e($r['level']) ?></span></td>
        <td class="primary"><strong><?= e($r['title']) ?></strong><?php if ($r['message']): ?><details><summary class="small muted"><?= t('Details') ?></summary><pre><?= e($r['message']) ?></pre></details><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
</div></div>
<?= pager($page, $pages, '/panel/admin/logs', array_filter(['level' => $level])) ?>
