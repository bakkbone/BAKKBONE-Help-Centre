<?php use Bakkbone\HelpCentre\Services\Tickets; ?>
<div class="page-head">
  <h1><?= t('Dashboard') ?></h1>
  <div class="actions"><a class="btn" href="<?= e(url('/panel/tickets', ['queue' => 'mine'])) ?>"><?= t('My tickets') ?></a><a class="btn btn-primary" href="<?= e(url('/panel/tickets')) ?>"><?= t('Open queue') ?></a></div>
</div>
<?php if ($cronLast === '' || strtotime($cronLast . ' UTC') < time() - 1800): ?>
  <div class="alert alert-warning"><?= $cronLast === '' ? t('Scheduled tasks (cron) have never run. Email fetching, SLA overdue checks and retries depend on it.') : t('Scheduled tasks (cron) last ran {when}. Email fetching, SLA overdue checks and retries depend on it.', ['when' => time_ago($cronLast)]) ?><?php if (\Bakkbone\HelpCentre\Services\Auth::staff()['is_admin']): ?> <a href="<?= e(url('/panel/admin/system')) ?>"><?= t('Set up cron') ?></a><?php endif; ?></div>
<?php endif; ?>
<?= \Bakkbone\HelpCentre\Core\Hooks::render('panel.dashboard', ['staff' => \Bakkbone\HelpCentre\Services\Auth::staff()]) ?>
<div class="grid grid-auto">
  <?php
  $tiles = [
      ['open', __('Open tickets'), ['queue' => 'open'], false],
      ['mine', __('Assigned to me'), ['queue' => 'mine'], false],
      ['unassigned', __('Unassigned'), ['queue' => 'unassigned'], false],
      ['overdue', __('Overdue'), ['queue' => 'overdue'], $stats['overdue'] > 0],
      ['new_today', __('New today'), ['queue' => 'all', 'sort' => 'created'], false],
      ['closed_today', __('Closed today'), ['queue' => 'closed'], false],
  ];
  foreach ($tiles as [$k, $label, $href, $bad]): ?>
    <a class="card stat<?= $bad ? ' alert-bad' : '' ?>" href="<?= e(url('/panel/tickets', $href)) ?>">
      <div class="stat-num"><?= (int)$stats[$k] ?></div>
      <div class="stat-label"><?= e($label) ?></div>
    </a>
  <?php endforeach; ?>
</div>

<div class="grid grid-2 mt">
  <div class="card">
    <div class="card-head"><h2><?= t('Last 14 days') ?></h2>
      <div class="legend"><span><i class="c"></i><?= t('Created') ?></span><span><i class="x"></i><?= t('Closed') ?></span></div></div>
    <div class="card-body">
      <?php $max = max(1, ...array_values(array_map(fn($d) => max($d["created"], $d["closed"]), $days))); ?>
      <div class="chart" role="img" aria-label="<?= t('Tickets created and closed per day') ?>">
        <?php foreach ($days as $date => $d): ?>
          <div class="day" title="<?= t('{date}: {created} created, {closed} closed', ['date' => $date, 'created' => $d['created'], 'closed' => $d['closed']]) ?>">
            <div class="bars">
              <div class="bar c" style="height:<?= round($d['created'] / $max * 100) ?>%"></div>
              <div class="bar x" style="height:<?= round($d['closed'] / $max * 100) ?>%"></div>
            </div>
            <div class="lbl"><?= e(date('j', strtotime($date))) ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
  <div class="card">
    <div class="card-head"><h2><?= t('Open by department') ?></h2></div>
    <div class="card-body">
      <?php if (!$byDept): ?><p class="muted"><?= t('No open tickets. 🎉') ?></p><?php endif; ?>
      <?php $dmax = max(1, ...array_map(fn($r) => (int)$r['n'], $byDept ?: [['n' => 1]])); ?>
      <?php foreach ($byDept as $r): ?>
        <div class="meter-row">
          <div class="meter-label"><span><?= e($r['name']) ?></span><strong><?= (int)$r['n'] ?></strong></div>
          <div class="meter"><span style="width:<?= round((int)$r['n'] / $dmax * 100) ?>%"></span></div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<div class="grid grid-2 mt">
  <div class="card card-flush">
    <div class="card-head"><h2><?= t('My open tickets') ?></h2><a class="small" href="<?= e(url('/panel/tickets', ['queue' => 'mine'])) ?>"><?= t('View all') ?></a></div>
    <div class="card-body">
      <?php if (!$myQueue): ?><div class="empty-state small"><?= t('Nothing assigned to you right now.') ?></div><?php else: ?>
      <table class="table">
        <tbody>
        <?php foreach ($myQueue as $t): ?>
          <tr data-href="<?= e(url('/panel/tickets/' . $t['id'])) ?>">
            <td class="primary"><a class="subject" href="<?= e(url('/panel/tickets/' . $t['id'])) ?>"><?= e($t['subject']) ?></a><br><span class="small faint">#<?= e($t['number']) ?> · <?= e($t['user_name']) ?></span></td>
            <td data-label="<?= t('Due') ?>" class="nowrap small"><?php if ($t['is_overdue']): ?><span class="badge badge-overdue"><?= t('Overdue') ?></span><?php elseif ($t['due_at']): ?><?= e(time_ago($t['due_at'])) ?><?php endif; ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  </div>
  <div class="card card-flush">
    <div class="card-head"><h2><?= t('My tasks') ?></h2><a class="small" href="<?= e(url('/panel/tasks')) ?>"><?= t('View all') ?></a></div>
    <div class="card-body">
      <?php if (!$myTasks): ?><div class="empty-state small"><?= t('No open tasks.') ?></div><?php else: ?>
      <table class="table"><tbody>
        <?php foreach ($myTasks as $task): ?>
          <tr data-href="<?= e(url('/panel/tasks/' . $task['id'])) ?>">
            <td class="primary"><a href="<?= e(url('/panel/tasks/' . $task['id'])) ?>"><?= e($task['title']) ?></a></td>
            <td data-label="<?= t('Due') ?>" class="nowrap small <?= $task['due_at'] && $task['due_at'] < now_utc() ? 'badge-bad' : 'muted' ?>"><?= $task['due_at'] ? e(fmt_dt($task['due_at'])) : '' ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody></table>
      <?php endif; ?>
    </div>
  </div>
</div>
