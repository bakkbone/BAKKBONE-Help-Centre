<?php use Bakkbone\HelpCentre\Services\Staff; use Bakkbone\HelpCentre\Services\Tickets; ?>
<div class="page-head">
  <h1><?= t('Tasks') ?></h1>
  <div class="actions"><a class="btn btn-primary" href="<?= e(url('/panel/tasks/new')) ?>"><?= t('New task') ?></a></div>
</div>
<div class="pills">
  <?php foreach (['mine' => __('My open tasks'), 'open' => __('All open'), 'overdue' => __('Overdue'), 'closed' => __('Completed')] as $k => $l): ?>
    <a href="<?= e(url('/panel/tasks', ['filter' => $k])) ?>" class="<?= $filter === $k ? 'active' : '' ?>"><?= e($l) ?></a>
  <?php endforeach; ?>
</div>
<div class="card card-flush"><div class="card-body">
<?php if (!$tasks): ?><div class="empty-state"><h3><?= t('No tasks') ?></h3><p><?= t('Tasks are internal to-dos, optionally linked to a ticket.') ?></p></div><?php else: ?>
  <table class="table">
    <thead><tr><th><?= t('Task') ?></th><th><?= t('Ticket') ?></th><th><?= t('Assigned') ?></th><th><?= t('Department') ?></th><th><?= t('Due') ?></th></tr></thead>
    <tbody>
    <?php foreach ($tasks as $t): $overdue = $t['status'] === 'open' && $t['due_at'] && $t['due_at'] < now_utc(); ?>
      <tr data-href="<?= e(url('/panel/tasks/' . $t['id'])) ?>">
        <td class="primary"><a class="subject" href="<?= e(url('/panel/tasks/' . $t['id'])) ?>"><?= $t['status'] === 'closed' ? '✓ ' : '' ?><?= e($t['title']) ?></a></td>
        <td data-label="<?= t('Ticket') ?>"><?php if ($t['ticket_id']): ?><a href="<?= e(url('/panel/tickets/' . $t['ticket_id'])) ?>">#<?= e($t['ticket_number']) ?></a><?php endif; ?></td>
        <td data-label="<?= t('Assigned') ?>"><?= e($t['staff_id'] ? Staff::nameById((int)$t['staff_id']) : '—') ?></td>
        <td data-label="<?= t('Department') ?>"><?= e(Tickets::lookupName('departments', $t['dept_id'], '—')) ?></td>
        <td data-label="<?= t('Due') ?>" class="nowrap"><?= $t['due_at'] ? ($overdue ? '<span class="badge badge-overdue">' . e(fmt_dt($t['due_at'])) . '</span>' : e(fmt_dt($t['due_at']))) : '—' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
</div></div>
