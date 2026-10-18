<?php
use Bakkbone\HelpCentre\Services\Webhooks;
$events = array_filter(explode(',', (string)($w['events'] ?? 'ticket.created')));
$deptIds = array_filter(array_map('intval', explode(',', (string)($w['dept_ids'] ?? ''))));
$exampleJson = "{\n  \"text\": \"New ticket #%{ticket.number} from %{user.name}: %{ticket.subject}\",\n  \"ticket_url\": \"%{ticket.staff_url}\",\n  \"priority\": \"%{ticket.priority}\",\n  \"department\": \"%{ticket.dept}\"\n}";
?>
<p class="small"><a href="<?= e(url('/panel/admin/webhooks')) ?>"><?= t('← Webhooks') ?></a></p>
<div class="page-head"><h1><?= e($title) ?></h1>
  <?php if ($w): ?><div class="actions">
    <a class="btn btn-sm" href="<?= e(url('/panel/admin/webhooks/' . $w['id'] . '/deliveries')) ?>"><?= t('Delivery log') ?></a>
    <form method="post" action="<?= e(url('/panel/admin/webhooks/' . $w['id'] . '/test')) ?>"><?= csrf_field() ?><button class="btn btn-sm" type="submit"><?= t('Send test') ?></button></form>
    <form method="post" action="<?= e(url('/panel/admin/webhooks/' . $w['id'] . '/delete')) ?>" data-confirm="<?= t('Delete this webhook?') ?>"><?= csrf_field() ?><button class="btn btn-sm btn-danger" type="submit"><?= t('Delete') ?></button></form>
  </div><?php endif; ?>
</div>
<div class="ticket-layout">
  <form class="card" method="post" action="<?= e(url($w ? '/panel/admin/webhooks/' . $w['id'] : '/panel/admin/webhooks/new')) ?>">
    <div class="card-body">
      <?= csrf_field() ?>
      <div class="form-grid">
        <div class="field"><label for="name"><?= t('Name') ?></label><input id="name" type="text" name="name" value="<?= e($w['name'] ?? '') ?>" placeholder="<?= t('e.g. Slack #support') ?>"></div>
        <div class="field"><label for="method"><?= t('Method') ?></label><select id="method" name="method"><?php foreach (['POST', 'PUT', 'PATCH', 'GET'] as $m): ?><option<?= selected($m, $w['method'] ?? 'POST') ?>><?= $m ?></option><?php endforeach; ?></select></div>
        <div class="field span-2"><label class="req" for="url">URL</label><input id="url" type="url" name="url" value="<?= e($w['url'] ?? '') ?>" required placeholder="<?= t('https://hooks.example.com/…') ?>"></div>
      </div>
      <fieldset>
        <legend><?= t('Send when') ?></legend>
        <div class="check-grid"><?php foreach (Webhooks::EVENTS as $k => $l): ?><label class="check"><input type="checkbox" name="events[]" value="<?= e($k) ?>"<?= checked(in_array($k, $events, true)) ?>> <?= e(__($l)) ?> <code class="faint small"><?= e($k) ?></code></label><?php endforeach; ?></div>
      </fieldset>
      <div class="field"><label for="dept_ids"><?= t('Only for departments') ?></label>
        <select id="dept_ids" name="dept_ids[]" multiple size="<?= min(6, max(2, count($depts))) ?>"><?php foreach ($depts as $d): ?><option value="<?= (int)$d['id'] ?>"<?= selected($d['id'], $deptIds) ?>><?= e($d['name']) ?></option><?php endforeach; ?></select>
        <div class="hint"><?= t('None selected = all departments. Ctrl/Cmd-click to select several.') ?></div></div>
      <fieldset>
        <legend><?= t('Payload') ?></legend>
        <div class="field" style="max-width:420px"><label for="content_type"><?= t('Format') ?></label>
          <select id="content_type" name="content_type">
            <option value="json"<?= selected('json', $w['content_type'] ?? 'json') ?>><?= t('JSON (application/json)') ?></option>
            <option value="form"<?= selected('form', $w['content_type'] ?? '') ?>><?= t('Form (application/x-www-form-urlencoded)') ?></option>
            <option value="text"<?= selected('text', $w['content_type'] ?? '') ?>><?= t('Plain text') ?></option>
          </select></div>
        <div class="field"><label for="body_template"><?= t('Body template (optional)') ?></label>
          <textarea id="body_template" class="code" name="body_template" spellcheck="false" style="min-height:200px" placeholder="<?= t('Leave blank to send the full default JSON payload (see right).') ?>"><?= e($w['body_template'] ?? '') ?></textarea>
          <div class="hint"><?= t('Use merge tags such as <code>%{ticket.number}</code>. Values are escaped automatically for the chosen format (JSON-string escaped / URL-encoded). For form format write <code>key=%{tag}&key2=%{tag2}</code>.') ?></div></div>
        <div class="field"><label for="headers"><?= t('Extra headers (one per line)') ?></label><textarea id="headers" class="code" name="headers" style="min-height:70px" placeholder="<?= t('Authorization: Bearer abc123 X-Source: helpdesk') ?>"><?= e($w['headers'] ?? '') ?></textarea></div>
        <div class="field"><label for="secret"><?= t('Signing secret') ?></label><input id="secret" type="password" name="secret" placeholder="<?= !empty($w['secret']) ? t('•••••••• (saved - leave blank to keep)') : t('Optional') ?>" autocomplete="new-password">
          <div class="hint"><?= t('If set, requests carry <code>X-HelpCentre-Timestamp</code> and <code>X-HelpCentre-Signature: sha256=HMAC(timestamp + "." + body, secret)</code>.') ?></div>
          <?php if (!empty($w['secret'])): ?><label class="check small" style="margin-top:6px"><input type="checkbox" name="clear_secret" value="1"> <?= t('Remove secret') ?></label><?php endif; ?></div>
      </fieldset>
      <label class="check"><input type="checkbox" name="active" value="1"<?= checked($w['active'] ?? 1) ?>> <?= t('Active') ?></label>
      <div class="form-actions"><button class="btn btn-primary" type="submit"><?= t('Save webhook') ?></button></div>
    </div>
  </form>
  <aside class="side">
    <div class="card"><div class="card-head"><h3><?= t('Merge tags') ?></h3></div>
      <div class="card-body small" data-insert-target="body_template">
        <?php foreach ($vars as $group => $list): if ($group === 'Auth emails' || $group === 'Recipient') { continue; } ?>
          <p><strong><?= e(__($group)) ?></strong><br><?php foreach ($list as $v): ?><code>%{<?= e($v) ?>}</code> <?php endforeach; ?></p>
        <?php endforeach; ?>
        <p><?= t('<strong>Event</strong><br><code>%{event}</code>') ?></p>
      </div>
    </div>
    <div class="card"><div class="card-head"><h3><?= t('Example custom JSON') ?></h3></div><div class="card-body"><pre class="small"><?= e($exampleJson) ?></pre></div></div>
    <div class="card"><div class="card-head"><h3><?= t('Default payload') ?></h3></div><div class="card-body"><pre class="small" style="max-height:340px"><?= e($sample) ?></pre></div></div>
  </aside>
</div>
