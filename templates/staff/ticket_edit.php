<?php use Bakkbone\HelpCentre\Core\View; use Bakkbone\HelpCentre\Services\Tickets; ?>
<p class="small"><a href="<?= e(url('/panel/tickets/' . $t['id'])) ?>"><?= t('← Back to ticket #{number}', ['number' => $t['number']]) ?></a></p>
<div class="page-head"><h1><?= t('Edit ticket #{number}', ['number' => $t['number']]) ?></h1></div>
<form class="card" method="post" action="<?= e(url('/panel/tickets/' . $t['id'] . '/edit')) ?>" style="max-width:860px">
  <div class="card-body">
    <?= csrf_field() ?>
    <div class="field"><label class="req" for="subject"><?= t('Subject') ?></label><input id="subject" type="text" name="subject" value="<?= e($t['subject']) ?>" required maxlength="250"></div>
    <div class="form-grid">
      <div class="field"><label for="topic_id"><?= t('Help topic') ?></label>
        <select id="topic_id" name="topic_id" data-topic-select><option value=""><?= t('— None —') ?></option><?php foreach ($topics as $tp): ?><option value="<?= (int)$tp['id'] ?>"<?= selected($tp['id'], $t['topic_id']) ?>><?= e($tp['name']) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label for="priority_id"><?= t('Priority') ?></label>
        <select id="priority_id" name="priority_id"><?php foreach ($priorities as $p): ?><option value="<?= (int)$p['id'] ?>"<?= selected($p['id'], $t['priority_id']) ?>><?= e($p['name']) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label for="sla_id"><?= t('SLA plan') ?></label>
        <select id="sla_id" name="sla_id"><option value=""><?= t('— None —') ?></option><?php foreach ($slas as $s): ?><option value="<?= (int)$s['id'] ?>"<?= selected($s['id'], $t['sla_id']) ?>><?= e($s['name']) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label for="source"><?= t('Source') ?></label>
        <select id="source" name="source"><?php foreach (Tickets::SOURCES as $k => $l): ?><option value="<?= $k ?>"<?= selected($k, $t['source']) ?>><?= e(__($l)) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label for="due_at"><?= t('Due date (override)') ?></label><input id="due_at" type="datetime-local" name="due_at" value="<?= e(input_dt($t['due_at'])) ?>">
        <label class="check small" style="margin-top:6px"><input type="checkbox" name="clear_due" value="1"> <?= t('Clear due date') ?></label></div>
    </div>
    <fieldset>
      <legend><?= t('Ticket owner') ?></legend>
      <p class="small muted"><?= t('Currently {name} <{email}>. Enter a different email to change the owner.', ['name' => $user['name'] ?? '', 'email' => $user['email'] ?? '']) ?></p>
      <div class="form-grid">
        <div class="field"><label for="owner_email"><?= t('New owner email') ?></label><input id="owner_email" type="email" name="owner_email"></div>
        <div class="field"><label for="owner_name"><?= t('Name (if new user)') ?></label><input id="owner_name" type="text" name="owner_name"></div>
      </div>
    </fieldset>
    <?= View::partial('partials/custom_fields', ['fields' => $fields, 'values' => $values]) ?>
    <div class="form-actions"><button class="btn btn-primary" type="submit"><?= t('Save changes') ?></button><a class="btn btn-ghost" href="<?= e(url('/panel/tickets/' . $t['id'])) ?>"><?= t('Cancel') ?></a></div>
  </div>
</form>
