<?php use Bakkbone\HelpCentre\Services\Tickets; use Bakkbone\HelpCentre\Core\View; ?>
<div class="no-print" style="margin-bottom:12px"><button class="btn" type="button" data-print><?= t('Print') ?></button></div>
<h1>#<?= e($t['number']) ?> — <?= e($t['subject']) ?></h1>
<dl class="dl" style="margin-bottom:18px">
  <dt><?= t('From') ?></dt><dd><?= e(($user['name'] ?? '') . ' <' . ($user['email'] ?? '') . '>') ?></dd>
  <dt><?= t('Status') ?></dt><dd><?= e(Tickets::lookupName('ticket_statuses', $t['status_id'])) ?></dd>
  <dt><?= t('Department') ?></dt><dd><?= e(Tickets::lookupName('departments', $t['dept_id'])) ?></dd>
  <dt><?= t('Priority') ?></dt><dd><?= e(Tickets::lookupName('priorities', $t['priority_id'])) ?></dd>
  <dt><?= t('Created') ?></dt><dd><?= e(fmt_dt($t['created_at'])) ?></dd>
  <?php foreach ($fields as $f): ?><dt><?= e($f['label']) ?></dt><dd><?= e($f['display']) ?></dd><?php endforeach; ?>
</dl>
<div class="alert alert-info remote-images-bar no-print" data-remote-images-bar hidden>
  <span><?= t('Images from other websites are hidden, so the sender can\'t tell when this ticket was opened.') ?></span>
  <button class="btn btn-sm" type="button" data-show-remote-images><?= t('Show images') ?></button>
</div>
<div class="thread">
  <?php foreach ($entries as $e): ?><?= View::partial('partials/entry', ['e' => $e, 'staffView' => true]) ?><?php endforeach; ?>
</div>
