<div class="page-head">
  <h1><?= t('Webhooks') ?></h1>
  <div class="actions"><a class="btn btn-primary" href="<?= e(url('/panel/admin/webhooks/new')) ?>"><?= t('New webhook') ?></a></div>
</div>
<p class="muted"><?= t('Send ticket data to other systems (Slack, Teams, Zapier, Make, n8n, your own app) when tickets are created or updated.') ?></p>
<div class="card card-flush"><div class="card-body">
<?php if (!$rows): ?><div class="empty-state"><h3><?= t('No webhooks yet') ?></h3><p><?= t('Create one to POST new-ticket data, with your own merge-tag template, to any URL.') ?></p></div><?php else: ?>
  <table class="table">
    <thead><tr><th><?= t('Name') ?></th><th>URL</th><th><?= t('Events') ?></th><th><?= t('Last delivery') ?></th><th><?= t('Status') ?></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $w): ?>
      <tr data-href="<?= e(url('/panel/admin/webhooks/' . $w['id'])) ?>">
        <td class="primary"><a class="subject" href="<?= e(url('/panel/admin/webhooks/' . $w['id'])) ?>"><?= e($w['name']) ?></a></td>
        <td data-label="URL" class="small mono" style="overflow-wrap:anywhere"><?= e($w['method']) ?> <?= e($w['url']) ?></td>
        <td data-label="<?= t('Events') ?>" class="small"><?= e(str_replace(',', ', ', $w['events'])) ?></td>
        <td data-label="<?= t('Last delivery') ?>" class="small"><?= e($w['last_at'] ? time_ago($w['last_at']) : '—') ?><?= $w['failed_count'] ? ' <span class="badge badge-bad">' . (int)$w['failed_count'] . ' failed</span>' : '' ?></td>
        <td data-label="<?= t('Status') ?>"><?= $w['active'] ? '<span class="badge badge-ok">' . t('Active') . '</span>' : '<span class="badge">' . t('Disabled') . '</span>' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
</div></div>
