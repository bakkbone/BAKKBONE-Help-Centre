<?php
use Bakkbone\HelpCentre\Core\View;
use Bakkbone\HelpCentre\Services\Staff;
use Bakkbone\HelpCentre\Services\Tickets;
?>
<div class="page-head"><h1><?= t('New ticket') ?></h1></div>
<form class="card" method="post" action="<?= e(url('/panel/tickets/new')) ?>" enctype="multipart/form-data">
  <div class="card-body">
    <?= csrf_field() ?>
    <fieldset>
      <legend><?= t('User') ?></legend>
      <div class="form-grid">
        <div class="field"><label class="req" for="email"><?= t('Email') ?></label>
          <input id="email" type="email" name="email" list="user-list" value="<?= e(old('email', $prefill['email'] ?? '')) ?>" required autocomplete="off" data-user-lookup="<?= e(url('/panel/users/lookup')) ?>">
          <datalist id="user-list"></datalist>
          <div class="hint"><?= t('Start typing to find an existing user, or enter a new address.') ?></div></div>
        <div class="field"><label for="name"><?= t('Name') ?></label><input id="name" type="text" name="name" value="<?= e(old('name', $prefill['name'] ?? '')) ?>"></div>
        <div class="field"><label for="phone"><?= t('Phone') ?></label><input id="phone" type="tel" name="phone" value="<?= e(old('phone', $prefill['phone'] ?? '')) ?>"></div>
        <div class="field"><label class="check" style="margin-top:28px"><input type="checkbox" name="notify_user" value="1"<?= checked(setting('notify_user_staff_ticket') === '1') ?>> <?= t('Email the user a ticket notice') ?></label></div>
      </div>
    </fieldset>
    <fieldset>
      <legend><?= t('Ticket') ?></legend>
      <div class="form-grid">
        <div class="field"><label for="source"><?= t('Source') ?></label><select id="source" name="source"><?php foreach (Tickets::SOURCES as $k => $l): ?><option value="<?= $k ?>"<?= selected($k, old('source', 'phone')) ?>><?= e(__($l)) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label for="topic_id"><?= t('Help topic') ?></label><select id="topic_id" name="topic_id" data-topic-select><option value=""><?= t('— None —') ?></option><?php foreach ($topics as $tp): ?><option value="<?= (int)$tp['id'] ?>"<?= selected($tp['id'], old('topic_id')) ?>><?= e($tp['name']) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label for="dept_id"><?= t('Department') ?></label><select id="dept_id" name="dept_id"><option value=""><?= t('— From help topic / default —') ?></option><?php foreach ($depts as $d): ?><option value="<?= (int)$d['id'] ?>"<?= selected($d['id'], old('dept_id')) ?>><?= e($d['name']) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label for="priority_id"><?= t('Priority') ?></label><select id="priority_id" name="priority_id"><option value=""><?= t('— Default —') ?></option><?php foreach ($priorities as $p): ?><option value="<?= (int)$p['id'] ?>"<?= selected($p['id'], old('priority_id')) ?>><?= e($p['name']) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label for="sla_id"><?= t('SLA plan') ?></label><select id="sla_id" name="sla_id"><option value=""><?= t('— Default —') ?></option><?php foreach ($slas as $s): ?><option value="<?= (int)$s['id'] ?>"<?= selected($s['id'], old('sla_id')) ?>><?= e($s['name']) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label for="due_at"><?= t('Due date') ?></label><input id="due_at" type="datetime-local" name="due_at" value="<?= e(old('due_at')) ?>"></div>
        <div class="field"><label for="assignee"><?= t('Assign to') ?></label><select id="assignee" name="assignee"><option value=""><?= t('— Unassigned —') ?></option>
          <optgroup label="<?= t('Agents') ?>"><?php foreach ($agents as $a): ?><option value="s:<?= (int)$a['id'] ?>"<?= selected('s:' . $a['id'], old('assignee')) ?>><?= e(Staff::name($a)) ?></option><?php endforeach; ?></optgroup>
          <?php if ($teams): ?><optgroup label="<?= t('Teams') ?>"><?php foreach ($teams as $tm): ?><option value="t:<?= (int)$tm['id'] ?>"<?= selected('t:' . $tm['id'], old('assignee')) ?>><?= e($tm['name']) ?></option><?php endforeach; ?></optgroup><?php endif; ?>
        </select></div>
        <div class="field"><label for="status_id"><?= t('Status') ?></label><select id="status_id" name="status_id"><option value=""><?= t('— Default —') ?></option><?php foreach ($statuses as $s): ?><option value="<?= (int)$s['id'] ?>"><?= e($s['name']) ?></option><?php endforeach; ?></select></div>
      </div>
      <?= View::partial('partials/custom_fields', ['fields' => $fields]) ?>
      <div class="field"><label class="req" for="subject"><?= t('Subject') ?></label><input id="subject" type="text" name="subject" value="<?= e(old('subject')) ?>" required maxlength="250"></div>
      <div class="field"><label class="req" for="message"><?= t('Issue details') ?></label><textarea id="message" name="message" data-editor placeholder="<?= t('Describe the user\'s issue…') ?>"><?= e(old('message')) ?></textarea></div>
      <div class="field"><label for="attachments"><?= t('Attachments') ?></label><input id="attachments" type="file" name="attachments[]" multiple></div>
    </fieldset>
    <fieldset>
      <legend><?= t('Response (optional)') ?></legend>
      <?php if ($canned): ?>
      <div class="field" style="max-width:360px"><select data-canned="<?= e(url('/panel/canned/__ID__/render')) ?>" data-target="response" aria-label="<?= t('Insert canned response') ?>"><option value=""><?= t('Insert canned response…') ?></option><?php foreach ($canned as $c): ?><option value="<?= (int)$c['id'] ?>"><?= e($c['title']) ?></option><?php endforeach; ?></select></div>
      <?php endif; ?>
      <div class="field"><textarea id="response" name="response" data-editor placeholder="<?= t('Optionally post a reply to the user straight away…') ?>"><?= e(old('response')) ?></textarea></div>
      <div class="field" style="max-width:300px"><label for="signature"><?= t('Signature') ?></label><?php $sigDefault = old('signature', \Bakkbone\HelpCentre\Services\Tickets::defaultSignature($me)); ?><select id="signature" name="signature"><option value="mine"<?= selected('mine', $sigDefault) ?>><?= t('My signature') ?></option><option value="dept"<?= selected('dept', $sigDefault) ?>><?= t('Department signature') ?></option><option value="none"<?= selected('none', $sigDefault) ?>><?= t('None') ?></option></select></div>
    </fieldset>
    <div class="form-actions"><button class="btn btn-primary" type="submit"><?= t('Create ticket') ?></button><a class="btn btn-ghost" href="<?= e(url('/panel/tickets')) ?>"><?= t('Cancel') ?></a></div>
  </div>
</form>
