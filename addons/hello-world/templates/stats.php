<?php /** @var int $notes @var int $open @var int $today @var array $recent @var \Bakkbone\HelpCentre\Core\Addon $addon */ ?>
<div class="page-head"><h1><?= t('Hello stats') ?></h1></div>
<p class="muted"><?= t('A page added by the {name} add-on.', ['name' => raw('<strong>' . e(__($addon->name())) . '</strong>')]) ?></p>
<div class="grid grid-auto">
  <div class="card"><div class="card-body"><div class="stat-num"><?= $open ?></div><div class="muted"><?= t('Open tickets') ?></div></div></div>
  <div class="card"><div class="card-body"><div class="stat-num"><?= $today ?></div><div class="muted"><?= t('New today') ?></div></div></div>
  <div class="card"><div class="card-body"><div class="stat-num"><?= $notes ?></div><div class="muted"><?= t('Private notes') ?></div></div></div>
</div>
<div class="card card-flush" style="margin-top:16px">
  <div class="card-head"><h2><?= t('Latest notes') ?></h2></div>
  <div class="card-body">
  <?php if (!$recent): ?><div class="empty-state"><p><?= t('No notes yet. Add one from any ticket\'s sidebar.') ?></p></div><?php else: ?>
    <table class="table">
      <thead><tr><th><?= t('Ticket') ?></th><th><?= t('Note') ?></th><th><?= t('When') ?></th></tr></thead>
      <tbody>
      <?php foreach ($recent as $r): ?>
        <tr data-href="<?= e(url('/panel/tickets/' . $r['ticket_id'])) ?>">
          <td class="primary"><a class="subject" href="<?= e(url('/panel/tickets/' . $r['ticket_id'])) ?>">#<?= e($r['number']) ?> <?= e($r['subject']) ?></a></td>
          <td data-label="<?= t('Note') ?>"><?= e(mb_strimwidth($r['body'], 0, 120, '…')) ?></td>
          <td data-label="<?= t('When') ?>" class="small muted nowrap"><?= e(time_ago($r['created_at'])) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
  </div>
</div>
