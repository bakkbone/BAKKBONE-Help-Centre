<div class="page-head"><h1><?= t('Audit log') ?></h1></div>
<div class="card card-flush"><div class="card-body">
<?php if (!$rows): ?><div class="empty-state"><?= t('No activity recorded yet.') ?></div><?php else: ?>
  <table class="table">
    <thead><tr><th><?= t('When') ?></th><th><?= t('Who') ?></th><th><?= t('Action') ?></th><th><?= t('Object') ?></th><th><?= t('IP') ?></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r):
        $who = $r['staff_id'] ? trim($r['first_name'] . ' ' . $r['last_name']) ?: $r['username'] : ($r['user_email'] ?? __('System'));
        $obj = $r['object_type'] ? $r['object_type'] . ($r['object_id'] ? ' #' . $r['object_id'] : '') : '';
        $link = $r['object_type'] === 'ticket' && $r['object_id'] ? url('/panel/tickets/' . $r['object_id']) : null; ?>
      <tr>
        <td data-label="<?= t('When') ?>" class="nowrap small"><?= e(fmt_dt($r['created_at'])) ?></td>
        <td data-label="<?= t('Who') ?>"><?= e($who ?: '—') ?></td>
        <td class="primary"><code><?= e($r['action']) ?></code><?php if ($r['details'] && $r['details'] !== '[]'): ?><div class="small faint" style="overflow-wrap:anywhere"><?= e(mb_substr((string)$r['details'], 0, 200)) ?></div><?php endif; ?></td>
        <td data-label="<?= t('Object') ?>"><?= $link ? '<a href="' . e($link) . '">' . e($obj) . '</a>' : e($obj) ?></td>
        <td data-label="<?= t('IP') ?>" class="small mono"><?= e($r['ip']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
</div></div>
<?= pager($page, $pages, '/panel/admin/audit') ?>
