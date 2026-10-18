<?php use Bakkbone\HelpCentre\Services\Staff; $isNew = !$task; ?>
<p class="small"><a href="<?= e(url('/panel/tasks')) ?>"><?= t('← Tasks') ?></a></p>
<div class="page-head">
  <h1><?= $isNew ? t('New task') : e($task['title']) ?></h1>
  <?php if (!$isNew): ?><div class="actions">
    <span class="badge <?= $task['status'] === 'open' ? 'badge-open' : 'badge-ok' ?>"><?= $task['status'] === 'open' ? t('Open') : t('Completed') ?></span>
    <form method="post" action="<?= e(url('/panel/tasks/' . $task['id'] . '/delete')) ?>" data-confirm="<?= t('Delete this task?') ?>"><?= csrf_field() ?><button class="btn btn-sm btn-danger" type="submit"><?= t('Delete') ?></button></form>
  </div><?php endif; ?>
</div>
<form class="card" method="post" action="<?= e(url($isNew ? '/panel/tasks/new' : '/panel/tasks/' . $task['id'])) ?>" style="max-width:860px">
  <div class="card-body">
    <?= csrf_field() ?>
    <?php if ($ticket): ?>
      <input type="hidden" name="ticket_id" value="<?= (int)$ticket['id'] ?>">
      <p class="muted"><?= t('Linked to ticket {link}#{number} {subject}{/link}', ['link' => tag('<a href="' . e(url('/panel/tickets/' . $ticket['id'])) . '">'), 'number' => $ticket['number'], 'subject' => $ticket['subject']]) ?></p>
      <?php if ($isNew): ?><input type="hidden" name="back_to_ticket" value="1"><?php endif; ?>
    <?php endif; ?>
    <div class="field"><label class="req" for="title"><?= t('Title') ?></label><input id="title" type="text" name="title" value="<?= e($task['title'] ?? old('title')) ?>" required maxlength="250"></div>
    <div class="field"><label for="description"><?= t('Description') ?></label><textarea id="description" name="description" data-editor><?= e($task['description'] ?? old('description')) ?></textarea></div>
    <div class="form-grid">
      <div class="field"><label for="staff_id"><?= t('Assign to') ?></label><select id="staff_id" name="staff_id"><option value=""><?= t('— Unassigned —') ?></option><?php foreach ($agents as $a): ?><option value="<?= (int)$a['id'] ?>"<?= selected($a['id'], $task['staff_id'] ?? '') ?>><?= e(Staff::name($a)) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label for="dept_id"><?= t('Department') ?></label><select id="dept_id" name="dept_id"><option value=""><?= t('— None —') ?></option><?php foreach ($depts as $d): ?><option value="<?= (int)$d['id'] ?>"<?= selected($d['id'], $task['dept_id'] ?? ($ticket['dept_id'] ?? '')) ?>><?= e($d['name']) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label for="due_at"><?= t('Due') ?></label><input id="due_at" type="datetime-local" name="due_at" value="<?= e(input_dt($task['due_at'] ?? null)) ?>"></div>
      <?php if (!$isNew): ?>
      <div class="field"><label for="status"><?= t('Status') ?></label><select id="status" name="status"><option value="open"<?= selected('open', $task['status']) ?>><?= t('Open') ?></option><option value="closed"<?= selected('closed', $task['status']) ?>><?= t('Completed') ?></option></select></div>
      <?php endif; ?>
    </div>
    <div class="form-actions"><button class="btn btn-primary" type="submit"><?= $isNew ? t('Create task') : t('Save task') ?></button></div>
    <?php if (!$isNew): ?><p class="small faint" style="margin-top:12px"><?= t('Created {when} by {name}', ['when' => fmt_dt($task['created_at']), 'name' => Staff::nameById((int)$task['created_by'])]) ?><?= $task['closed_at'] ? ' · ' . t('completed {when}', ['when' => fmt_dt($task['closed_at'])]) : '' ?></p><?php endif; ?>
  </div>
</form>
