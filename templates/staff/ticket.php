<?php
use Bakkbone\HelpCentre\Core\View;
use Bakkbone\HelpCentre\Services\Staff;
use Bakkbone\HelpCentre\Services\Tickets;

$status = Tickets::lookup('ticket_statuses', $t['status_id']);
$priority = Tickets::lookup('priorities', $t['priority_id']);
$assignedName = $t['staff_id'] ? Staff::nameById((int)$t['staff_id']) : ($t['team_id'] ? __('{name} (team)', ['name' => Tickets::lookupName('teams', $t['team_id'])]) : '');
$currentAssignee = $t['staff_id'] ? 's:' . $t['staff_id'] : ($t['team_id'] ? 't:' . $t['team_id'] : '');

$describe = function (array $ev): string {
    $d = json_decode((string)$ev['data'], true) ?: [];
    $who = $ev['staff_id'] ? Staff::nameById((int)$ev['staff_id']) : ($ev['user_id'] ? (\Bakkbone\HelpCentre\Services\Users::find((int)$ev['user_id'])['name'] ?? __('User')) : __('System'));
    $vars = ['who' => $who];
    return match ($ev['event']) {
        'assigned' => (!empty($d['claim']) ? __('{who} claimed the ticket', $vars) : (!empty($d['staff_id']) ? __('{who} assigned the ticket to {name}', $vars + ['name' => Staff::nameById((int)$d['staff_id'])]) : (!empty($d['team_id']) ? __('{who} assigned the ticket to team {name}', $vars + ['name' => Tickets::lookupName('teams', $d['team_id'])]) : __('{who} assigned the ticket', $vars)))) . (!empty($d['auto']) ? ' ' . __('(automatic)') : ''),
        'unassigned' => __('{who} unassigned the ticket', $vars),
        'transferred' => __('{who} transferred the ticket from {from} to {to}', $vars + ['from' => Tickets::lookupName('departments', $d['from'] ?? 0, '?'), 'to' => Tickets::lookupName('departments', $d['to'] ?? 0, '?')]),
        'status' => __('{who} changed the status to {status}', $vars + ['status' => $d['status'] ?? '']),
        'closed' => __('{who} closed the ticket ({status})', $vars + ['status' => $d['status'] ?? __('closed')]),
        'closed_by_user' => __('{who} marked the ticket as resolved', $vars),
        'reopened' => __('Ticket reopened by {who}', $vars),
        'overdue' => __('Ticket marked overdue'),
        'auto_closed' => isset($d['days']) ? _n('Ticket auto-resolved after {n} day without a reply', 'Ticket auto-resolved after {n} days without a reply', (int)$d['days']) : __('Ticket auto-resolved after ? days without a reply'),
        'edited' => __('{who} edited {fields}', $vars + ['fields' => implode(', ', array_map(fn($k) => str_replace('_id', '', $k), array_keys($d)))]),
        'merged' => isset($d['into']) ? __('{who} merged this ticket into #{number}', $vars + ['number' => $d['into']]) : __('{who} merged #{number} into this ticket', $vars + ['number' => $d['from'] ?? '']),
        'collaborator_added' => __('{who} added collaborator {email}', $vars + ['email' => $d['email'] ?? '']),
        'collaborator_removed' => __('{who} removed a collaborator', $vars),
        'task_created' => __('{who} created task "{title}"', $vars + ['title' => $d['title'] ?? '']),
        'task_closed' => __('{who} completed task "{title}"', $vars + ['title' => $d['title'] ?? '']),
        'imported' => isset($d['number']) ? __('Imported from {source} (was #{number})', ['source' => $d['from'] ?? __('another system'), 'number' => $d['number']]) : __('Imported from {source}', ['source' => $d['from'] ?? __('another system')]),
        'task_reopened' => __('{who} reopened task "{title}"', $vars + ['title' => $d['title'] ?? '']),
        default => $who . ' ' . str_replace('_', ' ', $ev['event']),
    };
};
$assigneeSelect = function (string $current) use ($agents, $teams): string {
    $h = '<option value="">' . t('— Unassigned —') . '</option><optgroup label="' . t('Agents') . '">';
    foreach ($agents as $a) {
        $h .= '<option value="s:' . (int)$a['id'] . '"' . selected('s:' . $a['id'], $current) . '>' . e(Staff::name($a)) . ($a['onvacation'] ? ' ' . t('(away)') : '') . '</option>';
    }
    $h .= '</optgroup>';
    if ($teams) {
        $h .= '<optgroup label="' . t('Teams') . '">';
        foreach ($teams as $tm) {
            $h .= '<option value="t:' . (int)$tm['id'] . '"' . selected('t:' . $tm['id'], $current) . '>' . e($tm['name']) . '</option>';
        }
        $h .= '</optgroup>';
    }
    return $h;
};
?>
<p class="small no-print"><a href="<?= e(url('/panel/tickets')) ?>"><?= t('← Tickets') ?></a></p>
<div class="page-head" style="margin-bottom:6px">
  <div class="ticket-title"><h1><?= e($t['subject']) ?></h1></div>
  <div class="actions no-print">
    <a class="btn btn-sm" href="<?= e(url('/panel/tickets/' . $t['id'] . '/print')) ?>" target="_blank" rel="noopener"><?= t('Print') ?></a>
    <?php if ($perm('ticket.edit')): ?><a class="btn btn-sm" href="<?= e(url('/panel/tickets/' . $t['id'] . '/edit')) ?>"><?= t('Edit') ?></a><?php endif; ?>
    <?php if ($perm('task.manage')): ?><a class="btn btn-sm" href="<?= e(url('/panel/tasks/new', ['ticket_id' => $t['id']])) ?>"><?= t('Add task') ?></a><?php endif; ?>
  </div>
</div>
<div class="ticket-meta">
  <strong>#<?= e($t['number']) ?></strong>
  <span class="badge <?= $isOpen ? 'badge-open' : 'badge-closed' ?>"><?= e($status['name'] ?? '') ?></span>
  <?php if ($t['is_overdue']): ?><span class="badge badge-overdue"><?= t('Overdue') ?></span><?php endif; ?>
  <?php if ($priority): ?><span><span class="dot" style="--c:<?= e($priority['color']) ?>"></span> <?= e($priority['name']) ?></span><?php endif; ?>
  <span><?= e(Tickets::lookupName('departments', $t['dept_id'])) ?></span>
  <span><?= t('via {source}', ['source' => __(Tickets::SOURCES[$t['source']] ?? $t['source'])]) ?></span>
  <span title="<?= e(fmt_dt($t['created_at'])) ?>"><?= t('opened {when}', ['when' => time_ago($t['created_at'])]) ?></span>
  <?php if ($t['merged_into']): $parent = Tickets::find((int)$t['merged_into']); ?><span class="badge badge-warn"><?= t('Merged into {link}#{number}{/link}', ['link' => tag('<a href="' . e(url('/panel/tickets/' . $t['merged_into'])) . '">'), 'number' => $parent['number'] ?? '']) ?></span><?php endif; ?>
</div>

<div class="alert alert-warning lock-warning <?= $lockedBy ? '' : 'hidden' ?>" data-lock-warning data-lock-url="<?= e(url('/panel/tickets/' . $t['id'] . '/lock')) ?>">
  <strong data-who><?= e($lockedBy ? Staff::name($lockedBy) : '') ?></strong> <?= t('is currently viewing / replying to this ticket.') ?>

</div>

<div class="alert alert-info remote-images-bar no-print" data-remote-images-bar hidden>
  <span><?= t('Images from other websites are hidden, so the sender can\'t tell when this ticket was opened.') ?></span>
  <button class="btn btn-sm" type="button" data-show-remote-images><?= t('Show images') ?></button>
</div>
<div class="ticket-layout">
  <div>
    <div class="thread">
      <?php foreach ($timeline as $item): ?>
        <?php if ($item['kind'] === 'entry'): ?>
          <?= View::partial('partials/entry', ['e' => $item['data'], 'staffView' => true]) ?>
        <?php else: ?>
          <div class="event" title="<?= e(fmt_dt($item['at'])) ?>"><?= e($describe($item['data'])) ?> · <?= e(time_ago($item['at'])) ?></div>
        <?php endif; ?>
      <?php endforeach; ?>
    </div>

    <form class="card reply-card" style="margin-top:18px" method="post" action="<?= e(url('/panel/tickets/' . $t['id'] . '/post')) ?>" enctype="multipart/form-data" data-tabs data-default="<?= $perm('ticket.reply') ? 'reply' : 'note' ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="type" value="reply">
      <div class="tabs" role="tablist">
        <?php if ($perm('ticket.reply')): ?><button type="button" data-tab="reply" role="tab"><?= t('Reply to user') ?></button><?php endif; ?>
        <button type="button" data-tab="note" role="tab"><?= t('Internal note') ?></button>
      </div>
      <div class="card-body">
        <div data-pane="reply" class="small muted" style="margin-bottom:8px">
          <?= t('To: {name} <{email}>', ['name' => raw('<strong>' . e($user['name'] ?? '') . '</strong>'), 'email' => $user['email'] ?? '']) ?><?php
          $activeCollabs = array_filter($collaborators, fn($c) => $c['collab_active']);
          if ($activeCollabs): ?> · <?= t('CC: {addresses}', ['addresses' => implode(', ', array_column($activeCollabs, 'email'))]) ?><?php endif; ?>
        </div>
        <div data-pane="note" class="small muted hidden" style="margin-bottom:8px"><?= t('Internal notes are only visible to agents.') ?></div>
        <?php if ($canned): ?>
        <div class="field" style="max-width:360px">
          <select data-canned="<?= e(url('/panel/canned/__ID__/render', ['ticket' => $t['id']])) ?>" data-target="reply-message" aria-label="<?= t('Insert canned response') ?>">
            <option value=""><?= t('Insert canned response…') ?></option>
            <?php foreach ($canned as $c): ?><option value="<?= (int)$c['id'] ?>"><?= e($c['title']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>
        <div class="field"><textarea id="reply-message" name="message" data-editor placeholder="<?= t('Write your message…') ?>"><?= e(old('message')) ?></textarea></div>
        <div class="form-grid">
          <div class="field" data-pane="reply">
            <label for="signature"><?= t('Signature') ?></label>
            <?php $sigDefault = old('signature', \Bakkbone\HelpCentre\Services\Tickets::defaultSignature($me, $t)); ?>
            <select id="signature" name="signature">
              <option value="mine"<?= selected('mine', $sigDefault) ?>><?= t('My signature') ?></option>
              <option value="dept"<?= selected('dept', $sigDefault) ?>><?= t('Department signature') ?></option>
              <option value="none"<?= selected('none', $sigDefault) ?>><?= t('No signature') ?></option>
            </select>
          </div>
          <div class="field">
            <label for="status_after"><?= t('Set status') ?></label>
            <select id="status_after" name="status_id">
              <option value=""><?= t('— Keep: {status} —', ['status' => $status['name'] ?? '']) ?></option>
              <?php foreach ($statuses as $s): if ((int)$s['id'] === (int)$t['status_id']) { continue; } if ($s['state'] === 'closed' && !$perm('ticket.close')) { continue; } ?>
                <option value="<?= (int)$s['id'] ?>"><?= e($s['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="field"><label for="att"><?= t('Attachments') ?></label><input id="att" type="file" name="attachments[]" multiple></div>
        <div data-pane="reply">
          <label class="check"><input type="checkbox" name="no_email" value="1"> <?= t('Don\'t email the user (record only)') ?></label>
          <?php if ($activeCollabs): ?><label class="check"><input type="checkbox" name="no_collaborators" value="1"> <?= t('Don\'t email collaborators') ?></label><?php endif; ?>
          <?php if ($lockedBy): ?><label class="check"><input type="checkbox" name="force" value="1"> <?= t('Post anyway (another agent has this ticket open)') ?></label><?php endif; ?>
        </div>
        <div class="form-actions">
          <button class="btn btn-primary" type="submit"><?= t('Post') ?></button>
        </div>
      </div>
    </form>
    <?= \Bakkbone\HelpCentre\Core\Hooks::render('panel.ticket.main', ['ticket' => $t, 'user' => $user, 'staff' => $me]) ?>
  </div>

  <aside class="side">
    <div class="card">
      <div class="card-head"><h3><?= t('User') ?></h3><?php if ($user): ?><a class="small" href="<?= e(url('/panel/users/' . $user['id'])) ?>"><?= t('Profile') ?></a><?php endif; ?></div>
      <div class="card-body">
        <?php if ($user): ?>
        <div style="display:flex;gap:10px;align-items:center;margin-bottom:10px">
          <span class="avatar avatar-lg"><?= e(initials($user['name'])) ?></span>
          <div style="min-width:0"><strong><?= e($user['name']) ?></strong><br><a class="small" href="mailto:<?= e($user['email']) ?>"><?= e($user['email']) ?></a></div>
        </div>
        <dl class="dl">
          <?php if ($user['phone']): ?><dt><?= t('Phone') ?></dt><dd><a href="tel:<?= e($user['phone']) ?>"><?= e($user['phone']) ?></a></dd><?php endif; ?>
          <?php if ($org): ?><dt><?= t('Organisation') ?></dt><dd><a href="<?= e(url('/panel/orgs/' . $org['id'])) ?>"><?= e($org['name']) ?></a></dd><?php endif; ?>
          <?php if ($user['status'] === 'locked'): ?><dt><?= t('Status') ?></dt><dd><span class="badge badge-bad"><?= t('Locked') ?></span></dd><?php endif; ?>
        </dl>
        <?php if ($user['notes']): ?><p class="small muted" style="margin-top:8px"><?= nl2br(e($user['notes'])) ?></p><?php endif; ?>
        <?php if ($userTickets): ?>
          <details style="margin-top:10px"><summary class="small"><?= tn('{n} other ticket', '{n} other tickets', count($userTickets)) ?></summary>
            <ul class="list-plain small"><?php foreach ($userTickets as $ut): ?><li><a href="<?= e(url('/panel/tickets/' . $ut['id'])) ?>">#<?= e($ut['number']) ?></a> <?= e($ut['subject']) ?> <span class="faint"><?= e(Tickets::lookupName('ticket_statuses', $ut['status_id'])) ?></span></li><?php endforeach; ?></ul>
          </details>
        <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>

    <div class="card">
      <div class="card-head"><h3><?= t('Ticket') ?></h3></div>
      <div class="card-body">
        <form method="post" action="<?= e(url('/panel/tickets/' . $t['id'] . '/status')) ?>">
          <?= csrf_field() ?>
          <label for="st"><?= t('Status') ?></label>
          <div class="inline-form">
            <select id="st" name="status_id">
              <?php foreach ($statuses as $s): if ($s['state'] === 'closed' && !$perm('ticket.close') && (int)$s['id'] !== (int)$t['status_id']) { continue; } ?>
                <option value="<?= (int)$s['id'] ?>"<?= selected($s['id'], $t['status_id']) ?>><?= e($s['name']) ?></option>
              <?php endforeach; ?>
            </select>
            <button class="btn btn-sm" type="submit"><?= t('Set') ?></button>
          </div>
          <label class="check small" style="margin-top:6px"><input type="checkbox" name="notify" value="1"> <?= t('Email the user if closing') ?></label>
        </form>

        <form method="post" action="<?= e(url('/panel/tickets/' . $t['id'] . '/assign')) ?>">
          <?= csrf_field() ?>
          <label for="as"><?= t('Assigned to') ?></label>
          <?php if ($perm('ticket.assign')): ?>
          <div class="inline-form">
            <select id="as" name="assignee"><?= $assigneeSelect($currentAssignee) ?></select>
            <button class="btn btn-sm" type="submit"><?= t('Assign') ?></button>
          </div>
          <?php else: ?>
            <p class="small"><?= $assignedName !== '' ? e($assignedName) : t('Unassigned') ?></p>
            <?php if (!$t['staff_id']): ?><input type="hidden" name="assignee" value="s:<?= (int)$me['id'] ?>"><button class="btn btn-sm" type="submit"><?= t('Claim') ?></button><?php endif; ?>
          <?php endif; ?>
          <?php if ($perm('ticket.assign') && (int)$t['staff_id'] !== (int)$me['id']): ?>
            <button class="link-btn small" type="submit" name="assignee" value="s:<?= (int)$me['id'] ?>" style="margin-top:4px"><?= t('Assign to me') ?></button>
          <?php endif; ?>
        </form>

        <?php if ($perm('ticket.transfer')): ?>
        <form method="post" action="<?= e(url('/panel/tickets/' . $t['id'] . '/transfer')) ?>">
          <?= csrf_field() ?>
          <label for="dp"><?= t('Department') ?></label>
          <div class="inline-form">
            <select id="dp" name="dept_id"><?php foreach ($depts as $d): ?><option value="<?= (int)$d['id'] ?>"<?= selected($d['id'], $t['dept_id']) ?>><?= e($d['name']) ?></option><?php endforeach; ?></select>
            <button class="btn btn-sm" type="submit"><?= t('Transfer') ?></button>
          </div>
        </form>
        <?php endif; ?>

        <dl class="dl" style="margin-top:14px">
          <dt><?= t('Help topic') ?></dt><dd><?= e(Tickets::lookupName('help_topics', $t['topic_id'], '—')) ?></dd>
          <dt><?= t('SLA') ?></dt><dd><?= e(Tickets::lookupName('sla', $t['sla_id'], '—')) ?></dd>
          <dt><?= t('Due') ?></dt><dd><?= $t['due_at'] ? e(fmt_dt($t['due_at'])) . ' <span class="faint small">(' . e(time_ago($t['due_at'])) . ')</span>' : '—' ?></dd>
          <dt><?= t('Last message') ?></dt><dd><?= e(fmt_dt($t['last_message_at']) ?: '—') ?></dd>
          <dt><?= t('Last response') ?></dt><dd><?= e(fmt_dt($t['last_response_at']) ?: '—') ?></dd>
          <?php if ($t['closed_at']): ?><dt><?= t('Closed') ?></dt><dd><?= e(fmt_dt($t['closed_at'])) ?></dd><?php endif; ?>
          <?php if ($t['ip']): ?><dt><?= t('IP address') ?></dt><dd class="mono"><?= e($t['ip']) ?></dd><?php endif; ?>
          <?php foreach ($fields as $f): ?><dt><?= e($f['label']) ?></dt><dd><?= e($f['display']) ?></dd><?php endforeach; ?>
        </dl>
      </div>
    </div>

    <div class="card">
      <div class="card-head"><h3><?= t('Collaborators (CC)') ?></h3></div>
      <div class="card-body">
        <?php if (!$collaborators): ?><p class="small muted"><?= t('None. Collaborators receive replies and can reply by email.') ?></p><?php endif; ?>
        <ul class="list-plain small">
          <?php foreach ($collaborators as $c): ?>
            <li style="display:flex;gap:8px;align-items:center">
              <span style="flex:1;min-width:0;<?= $c['collab_active'] ? '' : 'text-decoration:line-through;opacity:.6' ?>"><a href="<?= e(url('/panel/users/' . $c['id'])) ?>"><?= e($c['name']) ?></a><br><span class="faint"><?= e($c['email']) ?></span></span>
              <?php if ($perm('ticket.collaborators')): ?>
              <form class="inline" method="post" action="<?= e(url('/panel/tickets/' . $t['id'] . '/collaborators')) ?>"><?= csrf_field() ?><input type="hidden" name="toggle" value="<?= (int)$c['id'] ?>"><button class="link-btn small" type="submit"><?= $c['collab_active'] ? t('Pause') : t('Resume') ?></button></form>
              <form class="inline" method="post" action="<?= e(url('/panel/tickets/' . $t['id'] . '/collaborators')) ?>"><?= csrf_field() ?><input type="hidden" name="remove" value="<?= (int)$c['id'] ?>"><button class="link-btn danger small" type="submit" title="<?= t('Remove') ?>">✕</button></form>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
        <?php if ($perm('ticket.collaborators')): ?>
        <form method="post" action="<?= e(url('/panel/tickets/' . $t['id'] . '/collaborators')) ?>" style="margin-top:10px">
          <?= csrf_field() ?>
          <div class="inline-form"><input type="email" name="email" placeholder="<?= t('email@example.com') ?>" required aria-label="<?= t('Collaborator email') ?>"><button class="btn btn-sm" type="submit"><?= t('Add') ?></button></div>
        </form>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($tasks): ?>
    <div class="card">
      <div class="card-head"><h3><?= t('Tasks') ?></h3></div>
      <div class="card-body"><ul class="list-plain small">
        <?php foreach ($tasks as $task): ?><li><a href="<?= e(url('/panel/tasks/' . $task['id'])) ?>"><?= $task['status'] === 'closed' ? '✓ ' : '' ?><?= e($task['title']) ?></a><?php if ($task['staff_id']): ?> <span class="faint">· <?= e(Staff::nameById((int)$task['staff_id'])) ?></span><?php endif; ?></li><?php endforeach; ?>
      </ul></div>
    </div>
    <?php endif; ?>

    <?php if ($perm('ticket.merge') || $perm('ban.manage') || $perm('ticket.delete')): ?>
    <div class="card">
      <div class="card-head"><h3><?= t('More actions') ?></h3></div>
      <div class="card-body">
        <?php if ($perm('ticket.merge')): ?>
        <form method="post" action="<?= e(url('/panel/tickets/' . $t['id'] . '/merge')) ?>" data-confirm="<?= t('Merge those tickets into this one? Their threads move here and they are closed.') ?>">
          <?= csrf_field() ?>
          <label for="mg"><?= t('Merge other tickets into this one') ?></label>
          <div class="inline-form"><input id="mg" type="text" name="numbers" placeholder="<?= t('Ticket numbers, comma separated') ?>"><button class="btn btn-sm" type="submit"><?= t('Merge') ?></button></div>
        </form>
        <?php endif; ?>
        <div class="btn-row" style="margin-top:12px">
          <?php if ($perm('ban.manage') && $user): ?>
          <form class="inline" method="post" action="<?= e(url('/panel/tickets/' . $t['id'] . '/ban')) ?>" data-confirm="<?= t('Ban {email} and close this ticket?', ['email' => $user['email']]) ?>" data-dialog="ban-dialog"><?= csrf_field() ?><button class="btn btn-sm btn-danger" type="submit"><?= t('Ban sender') ?></button></form>
          <?php endif; ?>
          <?php if ($perm('ticket.delete')): ?>
          <form class="inline" method="post" action="<?= e(url('/panel/tickets/' . $t['id'] . '/delete')) ?>" data-confirm="<?= t('Permanently delete this ticket, its thread and attachments?') ?>"><?= csrf_field() ?><button class="btn btn-sm btn-danger" type="submit"><?= t('Delete ticket') ?></button></form>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <?php endif; ?>
    <?= \Bakkbone\HelpCentre\Core\Hooks::render('panel.ticket.sidebar', ['ticket' => $t, 'user' => $user, 'staff' => $me]) ?>
  </aside>
  <?php if ($banInfo && $user): $bt = (int)$banInfo['tickets']; $others = max(0, $bt - 1); ?>
  <dialog id="ban-dialog" class="md-dialog" aria-labelledby="ban-dialog-title">
    <form method="post" action="<?= e(url('/panel/tickets/' . $t['id'] . '/ban')) ?>">
      <?= csrf_field() ?>
      <h2 id="ban-dialog-title"><?= t('Ban {email}?', ['email' => $user['email']]) ?></h2>
      <p class="muted"><?= t('They won\'t be able to open new tickets by email or on the portal.') ?></p>
      <div class="option-list">
        <label class="option">
          <input type="radio" name="then" value="close" checked>
          <span><?= t('<strong>Ban and close this ticket</strong>') ?><span class="small muted"><?= t('Their user and tickets are kept.') ?></span></span>
        </label>
        <label class="option<?= $banInfo['canDelete'] ? '' : ' disabled' ?>">
          <input type="radio" name="then" value="delete"<?= $banInfo['canDelete'] ? '' : ' disabled' ?>>
          <span><strong><?= $bt === 1 ? t('Ban and delete the user and this ticket') : tn('Ban and delete the user and all {n} of their tickets', 'Ban and delete the user and all {n} of their tickets', $bt) ?></strong>
            <span class="small muted"><?php if ($banInfo['canDelete']): ?><?= $bt === 1 ? t('Permanently removes this ticket, with replies and attachments. This can\'t be undone.') : tn('Permanently removes this ticket and {n} other, with replies and attachments. This can\'t be undone.', 'Permanently removes this ticket and {n} others, with replies and attachments. This can\'t be undone.', $others) ?><?php elseif ($banInfo['hidden']): ?><?= tn('Not available: {n} of their tickets is in departments you can\'t access.', 'Not available: {n} of their tickets are in departments you can\'t access.', (int)$banInfo['hidden']) ?><?php else: ?><?= t('Not available: you need permission to delete tickets and users.') ?><?php endif; ?></span></span>
        </label>
      </div>
      <div class="dialog-actions">
        <button class="btn btn-ghost" type="submit" formmethod="dialog" formnovalidate data-no-disable><?= t('Cancel') ?></button>
        <button class="btn btn-danger-filled" type="submit"><?= t('Ban sender') ?></button>
      </div>
    </form>
  </dialog>
  <?php endif; ?>
</div>
