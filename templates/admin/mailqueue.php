<?php use Bakkbone\HelpCentre\Services\EmailAccounts; ?>
<div class="page-head"><h1><?= t('Outgoing mail queue') ?></h1></div>
<p class="muted"><?= t('Every outgoing email is recorded here. Failed sends are retried automatically by cron (5 min, 15 min, 1 h, 4 h, 12 h) before being marked failed.') ?></p>
<div class="card card-flush"><div class="card-body">
<?php if (!$rows): ?><div class="empty-state"><?= t('No emails yet.') ?></div><?php else: ?>
  <table class="table">
    <thead><tr><th><?= t('Queued') ?></th><th><?= t('To') ?></th><th><?= t('Subject') ?></th><th><?= t('Via') ?></th><th><?= t('Status') ?></th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $p = json_decode((string)$r['payload'], true) ?: []; $acc = EmailAccounts::find($r['account_id'] ? (int)$r['account_id'] : null); ?>
      <tr>
        <td data-label="<?= t('Queued') ?>" class="nowrap small"><?= e(fmt_dt($r['created_at'])) ?></td>
        <td data-label="<?= t('To') ?>" class="small"><?= e(implode(', ', array_column($p['to'] ?? [], 'email'))) ?></td>
        <td class="primary"><?= e($p['subject'] ?? '') ?><?php if ($r['last_error'] && !$r['sent_at']): ?><div class="small" style="color:var(--bad)"><?= e(mb_substr((string)$r['last_error'], 0, 300)) ?></div><?php endif; ?></td>
        <td data-label="<?= t('Via') ?>" class="small"><?= e($acc['email'] ?? __('PHP mail()')) ?></td>
        <td data-label="<?= t('Status') ?>">
          <?php if ($r['sent_at']): ?><span class="badge badge-ok"><?= t('Sent') ?></span>
          <?php elseif ($r['failed']): ?><span class="badge badge-bad"><?= t('Failed') ?></span>
          <?php else: ?><span class="badge badge-warn"><?= t('Retrying ({count})', ['count' => (int)$r['attempts']]) ?></span><?php endif; ?>
        </td>
        <td><?php if (!$r['sent_at']): ?><form method="post" action="<?= e(url('/panel/admin/mailqueue/' . $r['id'] . '/retry')) ?>"><?= csrf_field() ?><button class="btn btn-sm" type="submit"><?= t('Retry now') ?></button></form><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
</div></div>
