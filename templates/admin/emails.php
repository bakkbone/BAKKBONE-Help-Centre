<div class="page-head">
  <h1><?= t('Mailboxes') ?></h1>
  <div class="actions"><a class="btn btn-primary" href="<?= e(url('/panel/admin/emails/new')) ?>"><?= t('Add mailbox') ?></a></div>
</div>
<div class="card card-flush"><div class="card-body">
<?php if (!$rows): ?>
  <div class="empty-state"><h3><?= t('No mailboxes configured') ?></h3><p><?= t('Add your support address (e.g. support@bakkbone.au) to receive tickets by email and send replies from it.') ?></p></div>
<?php else: ?>
  <table class="table">
    <thead><tr><th><?= t('Address') ?></th><th><?= t('Incoming') ?></th><th><?= t('Outgoing') ?></th><th><?= t('Department') ?></th><th><?= t('Last fetch') ?></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr data-href="<?= e(url('/panel/admin/emails/' . $r['id'])) ?>">
        <td class="primary"><a class="subject" href="<?= e(url('/panel/admin/emails/' . $r['id'])) ?>"><?= e($r['email']) ?></a>
          <?= (int)$r['id'] === $defaultId ? ' <span class="badge badge-open">' . t('Default') . '</span>' : '' ?><?= $r['active'] ? '' : ' <span class="badge">' . t('Disabled') . '</span>' ?>
          <br><span class="small faint"><?= e($r['name']) ?></span></td>
        <td data-label="<?= t('Incoming') ?>"><?= e(['none' => '—', 'imap' => 'IMAP', 'pop3' => 'POP3', 'graph' => 'Microsoft 365 (Graph)'][$r['inbound_protocol']] ?? $r['inbound_protocol']) ?></td>
        <td data-label="<?= t('Outgoing') ?>"><?= e(['smtp' => 'SMTP', 'graph' => 'Microsoft 365 (Graph)', 'mail' => 'PHP mail()'][$r['outbound_protocol']] ?? $r['outbound_protocol']) ?></td>
        <td data-label="<?= t('Department') ?>"><?= e($r['dept_name'] ?? __('Default')) ?></td>
        <td data-label="<?= t('Last fetch') ?>" class="small"><?php if ($r['last_error']): ?><span class="badge badge-bad" title="<?= e($r['last_error']) ?>"><?= t('Error') ?></span> <?php endif; ?><?= e($r['last_fetch_at'] ? time_ago($r['last_fetch_at']) : '—') ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
</div></div>

<div class="card">
  <div class="card-head"><h2><?= t('Email piping') ?></h2></div>
  <div class="card-body">
    <p><?= t('As an alternative (or in addition) to fetching, your mail server can <strong>pipe</strong> each incoming message straight into the help desk - instant, and no mailbox polling.') ?></p>
    <p class="small"><?= t('<strong>Local pipe</strong> (cPanel "Pipe to a Program", Postfix/Exim alias):') ?></p>
    <pre>| <?= e($pipeCmd) ?></pre>
    <p class="small"><?= t('<strong>Remote pipe over HTTPS</strong> (mail server on another machine) - create an API key with "Can pipe email" in {link}API keys{/link}, then pipe to:', ['link' => tag('<a href="' . e(url('/panel/admin/apikeys')) . '">')]) ?></p>
    <pre>curl -sS -H "X-API-Key: YOUR_KEY" -H "Content-Type: message/rfc822" --data-binary @- <?= e($pipeUrl) ?></pre>
  </div>
</div>
