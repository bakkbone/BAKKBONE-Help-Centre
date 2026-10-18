<?php
use Bakkbone\HelpCentre\Controllers\Staff\TicketController;
use Bakkbone\HelpCentre\Services\Auth;
use Bakkbone\HelpCentre\Services\Staff;
use Bakkbone\HelpCentre\Services\Tickets;

$me = Auth::staff();
$query = array_merge($f, ['queue' => $queue, 'sort' => $sort !== 'updated' ? $sort : null]);
$queryString = http_build_query(array_filter($query));
$assigneeOptions = function (string $current = '') use ($agents, $teams): string {
    $h = '<option value="">' . t('— Anyone —') . '</option><option value="none"' . selected('none', $current) . '>' . t('Unassigned') . '</option><optgroup label="' . t('Agents') . '">';
    foreach ($agents as $a) {
        $h .= '<option value="s:' . (int)$a['id'] . '"' . selected('s:' . $a['id'], $current) . '>' . e(Staff::name($a)) . '</option>';
    }
    $h .= '</optgroup>';
    if ($teams) {
        $h .= '<optgroup label="' . t('Teams') . '">';
        foreach ($teams as $t) {
            $h .= '<option value="t:' . (int)$t['id'] . '"' . selected('t:' . $t['id'], $current) . '>' . e($t['name']) . '</option>';
        }
        $h .= '</optgroup>';
    }
    return $h;
};
?>
<div class="page-head">
  <h1><?= e($title) ?> <span class="faint" style="font-weight:500;font-size:1rem">(<?= (int)$total ?>)</span></h1>
  <div class="actions">
    <?php if (Staff::can($me, 'ticket.export')): ?><a class="btn" href="<?= e(url('/panel/tickets/export') . '?' . $queryString) ?>"><?= t('Export CSV') ?></a><?php endif; ?>
    <?php if (Staff::can($me, 'ticket.create')): ?><a class="btn btn-primary" href="<?= e(url('/panel/tickets/new')) ?>"><?= t('New ticket') ?></a><?php endif; ?>
  </div>
</div>

<div class="pills">
  <?php foreach (TicketController::QUEUES as $k => $label): ?>
    <a href="<?= e(url('/panel/tickets', ['queue' => $k])) ?>" class="<?= $queue === $k ? 'active' : '' ?>"><?= e(__($label)) ?><?php if (isset($counts[$k])): ?><span class="n"><?= (int)$counts[$k] ?></span><?php endif; ?></a>
  <?php endforeach; ?>
  <?php foreach ($searches as $s): ?>
    <a href="<?= e(url('/panel/tickets') . '?' . $s['query']) ?>" title="<?= t('Saved search') ?>">★ <?= e($s['name']) ?></a>
  <?php endforeach; ?>
</div>
<?php if ($searches): ?>
<details class="filters small"><summary><?= t('Manage saved searches') ?></summary>
  <?php foreach ($searches as $s): ?>
    <form class="inline" method="post" action="<?= e(url('/panel/searches/' . $s['id'] . '/delete')) ?>" data-confirm="Delete saved search &quot;<?= e($s['name']) ?>&quot;?"><?= csrf_field() ?><button class="btn btn-sm" type="submit">✕ <?= e($s['name']) ?></button></form>
  <?php endforeach; ?>
</details>
<?php endif; ?>

<form method="get" action="<?= e(url('/panel/tickets')) ?>">
  <input type="hidden" name="queue" value="<?= e($queue) ?>">
  <div class="toolbar">
    <input class="search" type="search" name="q" value="<?= e($f['q'] ?? '') ?>" placeholder="<?= t('Search number, subject, name or email') ?>" aria-label="<?= t('Search') ?>">
    <select name="sort" aria-label="<?= t('Sort') ?>" style="width:auto" data-autosubmit>
      <?php foreach (['updated' => __('Recently updated'), 'created' => __('Newest'), 'oldest' => __('Oldest'), 'due' => __('Due date'), 'priority' => __('Priority'), 'number' => __('Number')] as $k => $l): ?>
        <option value="<?= $k ?>"<?= selected($k, $sort) ?>><?= e($l) ?></option>
      <?php endforeach; ?>
    </select>
    <button class="btn" type="submit"><?= t('Search') ?></button>
  </div>
  <details class="filters"<?= array_diff_key($f, ['q' => 1]) ? ' open' : '' ?>>
    <summary><?= t('▸ Advanced filters') ?></summary>
    <div class="card"><div class="card-body">
      <div class="grid grid-4">
        <div class="field"><label><?= t('Department') ?></label><select name="dept_id"><option value=""><?= t('Any') ?></option><?php foreach ($depts as $d): ?><option value="<?= (int)$d['id'] ?>"<?= selected($d['id'], $f['dept_id'] ?? '') ?>><?= e($d['name']) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label><?= t('Status') ?></label><select name="status_id"><option value=""><?= t('Any') ?></option><?php foreach ($statuses as $s): ?><option value="<?= (int)$s['id'] ?>"<?= selected($s['id'], $f['status_id'] ?? '') ?>><?= e($s['name']) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label><?= t('Priority') ?></label><select name="priority_id"><option value=""><?= t('Any') ?></option><?php foreach ($priorities as $p): ?><option value="<?= (int)$p['id'] ?>"<?= selected($p['id'], $f['priority_id'] ?? '') ?>><?= e($p['name']) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label><?= t('Assigned to') ?></label><select name="assignee"><?= $assigneeOptions($f['assignee'] ?? '') ?></select></div>
        <div class="field"><label><?= t('Help topic') ?></label><select name="topic_id"><option value=""><?= t('Any') ?></option><?php foreach ($topics as $t): ?><option value="<?= (int)$t['id'] ?>"<?= selected($t['id'], $f['topic_id'] ?? '') ?>><?= e($t['name']) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label><?= t('Source') ?></label><select name="source"><option value=""><?= t('Any') ?></option><?php foreach (Tickets::SOURCES as $k => $l): ?><option value="<?= $k ?>"<?= selected($k, $f['source'] ?? '') ?>><?= e(__($l)) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label><?= t('Created from') ?></label><input type="date" name="from" value="<?= e($f['from'] ?? '') ?>"></div>
        <div class="field"><label><?= t('Created to') ?></label><input type="date" name="to" value="<?= e($f['to'] ?? '') ?>"></div>
      </div>
      <label class="check"><input type="checkbox" name="deep" value="1"<?= checked(!empty($f['deep'])) ?>> <?= t('Also search message text (slower)') ?></label>
      <div class="form-actions"><button class="btn btn-primary" type="submit"><?= t('Apply filters') ?></button><a class="btn btn-ghost" href="<?= e(url('/panel/tickets', ['queue' => $queue])) ?>"><?= t('Reset') ?></a></div>
    </div></div>
  </details>
</form>
<?php if ($f): ?>
<form class="toolbar" method="post" action="<?= e(url('/panel/searches')) ?>">
  <?= csrf_field() ?><input type="hidden" name="query" value="<?= e($queryString) ?>">
  <input type="text" name="name" placeholder="<?= t('Name this search') ?>" style="max-width:240px" required>
  <button class="btn btn-sm" type="submit"><?= t('★ Save search') ?></button>
</form>
<?php endif; ?>

<?php
$canAssign = Staff::can($me, 'ticket.assign'); $canClose = Staff::can($me, 'ticket.close');
$closedId = (int)setting('closed_status_id');
$statusChoices = array_values(array_filter($statuses, fn($s) => $s['state'] !== 'closed' || $canClose));
?>
<form id="bulk-form" method="post" action="<?= e(url('/panel/tickets/bulk')) ?>" data-bulk>
  <?= csrf_field() ?>
  <div class="card card-flush">
    <div class="bulkbar" role="toolbar" aria-label="<?= t('Actions for selected tickets') ?>">
      <strong data-count><?= tn('{n} selected', '{n} selected', 0) ?></strong>
      <div class="bulk-actions">
        <button class="btn btn-sm" type="submit" name="action" value="claim" title="<?= t('Assign the selected tickets to yourself') ?>"><?= icon('person') ?><?= t('Claim') ?></button>
        <?php if ($canAssign): ?><button class="btn btn-sm" type="button" data-open-dialog="bulk-assign"><?= icon('person_add') ?><?= t('Assign…') ?></button><?php endif; ?>
        <?php if ($statusChoices): ?>
        <div class="menu-wrap split">
          <?php if ($canClose && $closedId): ?>
            <button class="btn btn-sm" type="submit" name="action" value="close"><?= icon('check_circle') ?><?= t('Close') ?></button>
            <button class="btn btn-sm split-toggle" type="button" data-menu="bulk-status-menu" aria-label="<?= t('Change status') ?>" title="<?= t('Change status') ?>"><?= icon('arrow_drop_down') ?></button>
          <?php else: ?>
            <button class="btn btn-sm" type="button" data-menu="bulk-status-menu"><?= t('Status') ?><?= icon('arrow_drop_down') ?></button>
          <?php endif; ?>
          <div class="menu" id="bulk-status-menu" role="menu" hidden>
            <div class="menu-label"><?= t('Set status') ?></div>
            <?php foreach ($statusChoices as $st): ?>
              <button type="submit" role="menuitem" name="action" value="status:<?= (int)$st['id'] ?>"><span class="dot" style="--c:<?= $st['state'] === 'closed' ? 'var(--md-outline)' : 'var(--md-primary)' ?>"></span><?= e($st['name']) ?></button>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>
        <?php if (Staff::can($me, 'ticket.transfer')): ?><button class="btn btn-sm" type="button" data-open-dialog="bulk-transfer"><?= icon('swap_horiz') ?><?= t('Transfer…') ?></button><?php endif; ?>
        <?php if (Staff::can($me, 'ticket.edit') && $priorities): ?>
        <div class="menu-wrap">
          <button class="btn btn-sm" type="button" data-menu="bulk-priority-menu"><?= icon('flag') ?><?= t('Priority') ?><?= icon('arrow_drop_down') ?></button>
          <div class="menu" id="bulk-priority-menu" role="menu" hidden>
            <div class="menu-label"><?= t('Set priority') ?></div>
            <?php foreach ($priorities as $pr): ?>
              <button type="submit" role="menuitem" name="action" value="priority:<?= (int)$pr['id'] ?>"><span class="dot" style="--c:<?= e(valid_hex_color((string)$pr['color']) ?: '#94a3b8') ?>"></span><?= e($pr['name']) ?></button>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>
        <?php if (Staff::can($me, 'ticket.merge')): ?><button class="btn btn-sm" type="submit" name="action" value="merge" data-min="2" data-confirm="<?= t('Merge the {tickets} into the oldest one? Their threads move into it and the others are closed.') ?>" title="<?= t('Merge the selected tickets into the oldest one') ?>"><?= icon('call_merge') ?><?= t('Merge (into oldest)') ?></button><?php endif; ?>
        <?php if (Staff::can($me, 'ticket.delete')): ?><button class="btn btn-sm btn-danger" type="submit" name="action" value="delete" data-confirm="<?= t('Permanently delete {tickets}? Replies and attachments are deleted too. This can\'t be undone.') ?>"><?= icon('delete') ?><?= t('Delete') ?></button><?php endif; ?>
      </div>
    </div>

    <?php if ($canAssign): ?>
    <dialog id="bulk-assign" class="md-dialog" aria-labelledby="bulk-assign-title">
      <div class="dialog-body">
        <h2 id="bulk-assign-title"><?= t('Assign') ?> <span data-count-text><?= t('tickets') ?></span></h2>
        <div class="field"><label for="bulk-assignee"><?= t('Assign to') ?></label>
          <select id="bulk-assignee" name="assignee">
            <optgroup label="<?= t('Agents') ?>"><?php foreach ($agents as $a): ?><option value="s:<?= (int)$a['id'] ?>"<?= selected((string)$a['id'], (string)$me['id']) ?>><?= e(Staff::name($a)) ?><?= (int)$a['id'] === (int)$me['id'] ? ' ' . t('(me)') : '' ?></option><?php endforeach; ?></optgroup>
            <?php if ($teams): ?><optgroup label="<?= t('Teams') ?>"><?php foreach ($teams as $tm): ?><option value="t:<?= (int)$tm['id'] ?>"><?= e($tm['name']) ?></option><?php endforeach; ?></optgroup><?php endif; ?>
            <option value="none"><?= t('— Unassign —') ?></option>
          </select>
        </div>
        <div class="dialog-actions">
          <button class="btn btn-ghost" type="button" data-close-dialog><?= t('Cancel') ?></button>
          <button class="btn btn-primary" type="submit" name="action" value="assign"><?= t('Assign') ?></button>
        </div>
      </div>
    </dialog>
    <?php endif; ?>
    <?php if (Staff::can($me, 'ticket.transfer')): ?>
    <dialog id="bulk-transfer" class="md-dialog" aria-labelledby="bulk-transfer-title">
      <div class="dialog-body">
        <h2 id="bulk-transfer-title"><?= t('Transfer') ?> <span data-count-text><?= t('tickets') ?></span></h2>
        <div class="field"><label for="bulk-dept"><?= t('Move to department') ?></label>
          <select id="bulk-dept" name="dept_id"><?php foreach ($depts as $d): ?><option value="<?= (int)$d['id'] ?>"><?= e($d['name']) ?></option><?php endforeach; ?></select>
        </div>
        <div class="dialog-actions">
          <button class="btn btn-ghost" type="button" data-close-dialog><?= t('Cancel') ?></button>
          <button class="btn btn-primary" type="submit" name="action" value="transfer"><?= t('Transfer') ?></button>
        </div>
      </div>
    </dialog>
    <?php endif; ?>
    <div class="card-body">
    <?php if (!$tickets): ?>
      <div class="empty-state"><h3><?= t('No tickets') ?></h3><p><?= t('Nothing matches this view.') ?></p></div>
    <?php else: ?>
      <div class="inbox-head">
        <label class="check inbox-check-all"><input type="checkbox" data-check-all> <span><?= t('Select all') ?></span></label>
        <span class="small muted"><?= tn('{n} shown', '{n} shown', count($tickets)) ?></span>
      </div>
      <ul class="inbox">
        <?php foreach ($tickets as $t):
            $state = Tickets::lookup('ticket_statuses', $t['status_id']);
            $isOpenState = ($state['state'] ?? '') === 'open';
            $assigned = $t['staff_id'] ? Staff::nameById((int)$t['staff_id']) : ($t['team_id'] ? Tickets::lookupName('teams', $t['team_id']) : '');
            $href = url('/panel/tickets/' . $t['id']); ?>
          <li class="inbox-row<?= !$t['is_answered'] && $isOpenState ? ' unread' : '' ?><?= $t['is_overdue'] ? ' overdue' : '' ?>" data-href="<?= e($href) ?>">
            <label class="inbox-check"><input type="checkbox" name="ids[]" value="<?= (int)$t['id'] ?>" aria-label="Select #<?= e($t['number']) ?>"></label>
            <span class="avatar" aria-hidden="true"><?= e(initials((string)$t['user_name'])) ?></span>
            <div class="inbox-main">
              <div class="inbox-top">
                <span class="inbox-from" title="<?= e($t['user_email']) ?>"><?= e($t['user_name']) ?></span>
                <span class="inbox-num">#<?= e($t['number']) ?></span>
                <time class="inbox-time" datetime="<?= e($t['updated_at']) ?>Z" title="<?= e(fmt_dt($t['updated_at'])) ?>"><?= e(time_ago($t['updated_at'])) ?></time>
              </div>
              <a class="subject" href="<?= e($href) ?>"><?= e($t['subject']) ?></a>
              <div class="inbox-meta">
                <span class="badge <?= $isOpenState ? 'badge-open' : 'badge-closed' ?>"><?= e($state['name'] ?? '') ?></span>
                <?php if ($t['is_overdue']): ?><span class="badge badge-overdue"><?= t('Overdue') ?></span><?php endif; ?>
                <span class="meta-item"><span class="dot" style="--c:<?= e($t['priority_color'] ?: '#94a3b8') ?>"></span><?= e($t['priority_name']) ?></span>
                <span class="meta-item"><?= e(Tickets::lookupName('departments', $t['dept_id'])) ?></span>
                <span class="meta-item"><?= icon('person') ?><?= $assigned !== '' ? e($assigned) : t('Unassigned') ?></span>
                <?php if ((int)$t['entry_count'] > 1): ?><span class="meta-item" title="<?= t('Messages') ?>"><?= icon('chat') ?><?= (int)$t['entry_count'] ?></span><?php endif; ?>
                <?php if ((int)$t['att_count'] > 0): ?><span class="meta-item" title="<?= t('Attachments') ?>"><?= icon('attach_file') ?><?= (int)$t['att_count'] ?></span><?php endif; ?>
              </div>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
    </div>
  </div>
</form>
<?= pager($page, $pages, '/panel/tickets', array_filter($query)) ?>
