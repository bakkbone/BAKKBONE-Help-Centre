<?php use Bakkbone\HelpCentre\Services\Tickets; ?>
<div class="page-head">
  <h1><?= t('My tickets') ?></h1>
  <div class="actions"><a class="btn btn-primary" href="<?= e(url('/open')) ?>"><?= t('New ticket') ?></a></div>
</div>
<div class="pills">
  <?php foreach (['open' => __('Open'), 'closed' => __('Closed'), 'all' => __('All')] as $k => $label): ?>
    <a href="<?= e(url('/tickets', ['state' => $k, 'q' => $q ?: null])) ?>" class="<?= $state === $k ? 'active' : '' ?>"><?= e($label) ?></a>
  <?php endforeach; ?>
</div>
<form class="toolbar" method="get" action="<?= e(url('/tickets')) ?>">
  <input type="hidden" name="state" value="<?= e($state) ?>">
  <input class="search" type="search" name="q" value="<?= e($q) ?>" placeholder="<?= t('Search by number or subject') ?>" aria-label="<?= t('Search tickets') ?>">
  <button class="btn" type="submit"><?= t('Search') ?></button>
</form>
<div class="card card-flush">
  <div class="card-body">
  <?php if (!$tickets): ?>
    <div class="empty-state"><h3><?= t('No tickets here') ?></h3><p><?= t('When you contact us, your requests will appear here.') ?></p></div>
  <?php else: ?>
    <table class="table">
      <thead><tr><th><?= t('Ticket') ?></th><th><?= t('Subject') ?></th><th><?= t('Status') ?></th><th><?= t('Last update') ?></th></tr></thead>
      <tbody>
      <?php foreach ($tickets as $t):
          $st = Tickets::lookup('ticket_statuses', $t['status_id']);
          $open = ($st['state'] ?? 'open') === 'open'; ?>
        <tr data-href="<?= e(url('/tickets/' . $t['number'])) ?>">
          <td data-label="<?= t('Ticket') ?>" class="nowrap"><a href="<?= e(url('/tickets/' . $t['number'])) ?>">#<?= e($t['number']) ?></a></td>
          <td data-label="<?= t('Subject') ?>" class="primary"><span class="subject"><?= e($t['subject']) ?></span>
            <?php if ((int)$t['user_id'] !== (int)$user['id']): ?><br><span class="small faint"><?= t('Opened by {name}', ['name' => $t['owner_name']]) ?></span><?php endif; ?></td>
          <td data-label="<?= t('Status') ?>"><span class="badge <?= $open ? 'badge-open' : 'badge-closed' ?>"><?= e($st['client_name'] ?? $st['name'] ?? __('Open')) ?></span></td>
          <td data-label="<?= t('Updated') ?>" class="nowrap muted"><?= e(time_ago($t['updated_at'])) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
  </div>
</div>
<?= pager($page, $pages, '/tickets', array_filter(['state' => $state, 'q' => $q])) ?>
