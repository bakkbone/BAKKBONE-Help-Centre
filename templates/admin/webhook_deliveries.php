<p class="small"><a href="<?= e(url('/panel/admin/webhooks/' . $w['id'])) ?>"><?= t('← {name}', ['name' => $w['name']]) ?></a></p>
<div class="page-head"><h1><?= t('Delivery log') ?></h1>
  <div class="actions"><form method="post" action="<?= e(url('/panel/admin/webhooks/' . $w['id'] . '/test')) ?>"><?= csrf_field() ?><button class="btn btn-sm" type="submit"><?= t('Send test') ?></button></form></div>
</div>
<div class="card card-flush"><div class="card-body">
<?php if (!$rows): ?><div class="empty-state"><?= t('No deliveries yet.') ?></div><?php else: ?>
  <table class="table">
    <thead><tr><th><?= t('When') ?></th><th><?= t('Event') ?></th><th><?= t('Ticket') ?></th><th><?= t('Result') ?></th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $d): ?>
      <tr>
        <td data-label="<?= t('When') ?>" class="small nowrap"><?= e(fmt_dt($d['created_at'])) ?></td>
        <td data-label="<?= t('Event') ?>"><code><?= e($d['event']) ?></code></td>
        <td data-label="<?= t('Ticket') ?>"><?= $d['ticket_id'] && $d['number'] ? '<a href="' . e(url('/panel/tickets/' . $d['ticket_id'])) . '">#' . e($d['number']) . '</a>' : '—' ?></td>
        <td class="primary">
          <?php if ($d['success']): ?><span class="badge badge-ok"><?= t('HTTP {code}', ['code' => (int)$d['status_code']]) ?></span>
          <?php elseif ($d['next_attempt_at']): ?><span class="badge badge-warn"><?= t('Retrying (attempt {count})', ['count' => (int)$d['attempts']]) ?></span>
          <?php else: ?><span class="badge badge-bad"><?= $d['status_code'] ? t('HTTP {code}', ['code' => (int)$d['status_code']]) : t('Failed') ?></span><?php endif; ?>
          <details><summary class="small muted"><?= t('Request / response') ?></summary>
            <p class="small"><?= t('<strong>Headers</strong>') ?></p><pre class="small"><?= e(json_encode(json_decode((string)$d['request_headers'], true), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>
            <p class="small"><?= t('<strong>Body</strong>') ?></p><pre class="small"><?= e($d['request_body']) ?></pre>
            <p class="small"><?= t('<strong>Response</strong>') ?></p><pre class="small"><?= e($d['response_body']) ?></pre>
          </details>
        </td>
        <td><?php if (!$d['success']): ?><form method="post" action="<?= e(url('/panel/admin/webhooks/deliveries/' . $d['id'] . '/retry')) ?>"><?= csrf_field() ?><button class="btn btn-sm" type="submit"><?= t('Retry') ?></button></form><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
</div></div>
