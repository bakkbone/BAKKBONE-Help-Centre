<div class="page-head"><h1><?= t('API keys') ?></h1></div>
<?php if ($newKey): ?>
  <div class="alert alert-success">
    <?= t('<strong>Copy your new API key now - it won\'t be shown again.</strong>') ?>

    <pre id="newkey" style="margin:8px 0"><?= e($newKey) ?></pre>
    <button class="btn btn-sm" type="button" data-copy="newkey"><?= t('Copy') ?></button>
  </div>
<?php endif; ?>
<div class="grid grid-2">
  <form class="card" method="post" action="<?= e(url('/panel/admin/apikeys')) ?>">
    <div class="card-head"><h2><?= t('Create a key') ?></h2></div>
    <div class="card-body">
      <?= csrf_field() ?>
      <div class="field"><label class="req" for="name"><?= t('Name') ?></label><input id="name" type="text" name="name" required placeholder="<?= t('e.g. Website contact form') ?>"></div>
      <div class="field"><label for="ip_allow"><?= t('Restrict to IP addresses (optional)') ?></label><textarea id="ip_allow" name="ip_allow" style="min-height:60px" placeholder="203.0.113.10&#10;198.51.100.0/24"></textarea></div>
      <label class="check"><input type="checkbox" name="can_create_tickets" value="1" checked> <?= t('Can create tickets') ?></label>
      <label class="check"><input type="checkbox" name="can_pipe" value="1"> <?= t('Can pipe raw email') ?></label>
      <button class="btn btn-primary" type="submit"><?= t('Generate key') ?></button>
    </div>
  </form>
  <div class="card">
    <div class="card-head"><h2><?= t('Usage') ?></h2></div>
    <div class="card-body small">
      <p><?= t('<strong>Create a ticket</strong> (osTicket-compatible JSON):') ?></p>
<pre>curl -X POST <?= e($apiUrl) ?> \
  -H "X-API-Key: bkhc_…" -H "Content-Type: application/json" \
  -d '{"name":"Jane Citizen","email":"jane@example.com",
       "subject":"Website change","message":"data:text/html,&lt;p&gt;Please update…&lt;/p&gt;",
       "topicId":3,"attachments":[{"logo.png":"data:image/png;base64,iVBOR…"}]}'</pre>
      <p><?= t('Returns <code>201</code> with <code>{"id":…,"number":"…","url":"…"}</code> (send <code>Accept: text/plain</code> for just the number). Custom fields can be sent using their variable names.') ?></p>
      <p><?= t('<strong>Pipe a raw email</strong>: {request} with the RFC 822 message as the body.', ['request' => raw('<code>POST ' . e($pipeUrl) . '</code>')]) ?></p>
    </div>
  </div>
</div>
<div class="card card-flush" style="margin-top:16px"><div class="card-body">
<?php if (!$rows): ?><div class="empty-state small"><?= t('No API keys.') ?></div><?php else: ?>
  <table class="table">
    <thead><tr><th><?= t('Name') ?></th><th><?= t('Key') ?></th><th><?= t('Permissions') ?></th><th><?= t('IP restriction') ?></th><th><?= t('Last used') ?></th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $k): ?>
      <tr>
        <td class="primary"><?= e($k['name']) ?> <?= $k['active'] ? '' : '<span class="badge">' . t('Disabled') . '</span>' ?></td>
        <td data-label="<?= t('Key') ?>" class="mono small"><?= e($k['key_prefix']) ?>…</td>
        <td data-label="<?= t('Permissions') ?>" class="small"><?= e(implode(', ', array_filter([$k['can_create_tickets'] ? 'tickets' : '', $k['can_pipe'] ? 'pipe' : '']))) ?></td>
        <td data-label="<?= t('IPs') ?>" class="small mono"><?= nl2br(e($k['ip_allow'] ?: __('Any'))) ?></td>
        <td data-label="<?= t('Last used') ?>" class="small"><?= e($k['last_used_at'] ? time_ago($k['last_used_at']) : __('Never')) ?></td>
        <td class="nowrap">
          <form class="inline" method="post" action="<?= e(url('/panel/admin/apikeys/' . $k['id'] . '/toggle')) ?>"><?= csrf_field() ?><button class="btn btn-sm" type="submit"><?= $k['active'] ? t('Disable') : t('Enable') ?></button></form>
          <form class="inline" method="post" action="<?= e(url('/panel/admin/apikeys/' . $k['id'] . '/delete')) ?>" data-confirm="<?= t('Revoke this key?') ?>"><?= csrf_field() ?><button class="btn btn-sm btn-danger" type="submit"><?= t('Revoke') ?></button></form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
</div></div>
