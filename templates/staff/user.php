<?php
use Bakkbone\HelpCentre\Services\Auth;
use Bakkbone\HelpCentre\Services\Staff;
use Bakkbone\HelpCentre\Services\Tickets;
$me = Auth::staff();
$canEdit = Staff::can($me, 'user.manage');
?>
<p class="small"><a href="<?= e(url('/panel/users')) ?>"><?= t('← Users') ?></a></p>
<div class="page-head">
  <div style="display:flex;gap:12px;align-items:center"><span class="avatar avatar-lg"><?= e(initials($u['name'])) ?></span><div><h1 style="margin:0"><?= e($u['name']) ?></h1><span class="muted"><?= e($u['email']) ?></span></div></div>
  <div class="actions">
    <?php if (Staff::can($me, 'ticket.create')): ?><a class="btn btn-primary" href="<?= e(url('/panel/tickets/new', ['user_id' => $u['id']])) ?>"><?= t('New ticket for user') ?></a><?php endif; ?>
  </div>
</div>
<?php if ($banned): ?><div class="alert alert-error"><?= t('This email address is on the ban list.') ?></div><?php endif; ?>
<div class="ticket-layout">
  <div class="card card-flush">
    <div class="card-head"><h2><?= t('Tickets') ?></h2></div>
    <div class="card-body">
      <?php if (!$tickets): ?><div class="empty-state small"><?= t('No tickets.') ?></div><?php else: ?>
      <table class="table"><thead><tr><th><?= t('Ticket') ?></th><th><?= t('Subject') ?></th><th><?= t('Status') ?></th><th><?= t('Created') ?></th></tr></thead><tbody>
        <?php foreach ($tickets as $t): $st = Tickets::lookup('ticket_statuses', $t['status_id']); ?>
          <tr data-href="<?= e(url('/panel/tickets/' . $t['id'])) ?>">
            <td data-label="<?= t('Ticket') ?>"><a href="<?= e(url('/panel/tickets/' . $t['id'])) ?>">#<?= e($t['number']) ?></a></td>
            <td class="primary"><?= e($t['subject']) ?><?= (int)$t['user_id'] !== (int)$u['id'] ? ' <span class="badge">' . t('CC') . '</span>' : '' ?></td>
            <td data-label="<?= t('Status') ?>"><span class="badge <?= ($st['state'] ?? '') === 'open' ? 'badge-open' : 'badge-closed' ?>"><?= e($st['name'] ?? '') ?></span></td>
            <td data-label="<?= t('Created') ?>" class="small muted"><?= e(fmt_date($t['created_at'])) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody></table>
      <?php endif; ?>
    </div>
  </div>
  <aside class="side">
    <form class="card" method="post" action="<?= e(url('/panel/users/' . $u['id'])) ?>">
      <div class="card-head"><h3><?= t('Details') ?></h3></div>
      <div class="card-body">
        <?= csrf_field() ?>
        <fieldset <?= $canEdit ? '' : 'disabled' ?> style="border:0;padding:0;margin:0">
          <div class="field"><label for="name"><?= t('Name') ?></label><input id="name" type="text" name="name" value="<?= e($u['name']) ?>"></div>
          <div class="field"><label for="email"><?= t('Email') ?></label><input id="email" type="email" name="email" value="<?= e($u['email']) ?>" required<?= empty(\Bakkbone\HelpCentre\Services\Auth::staff()['is_admin']) ? ' readonly' : '' ?>></div>
          <div class="field"><label for="phone"><?= t('Phone') ?></label><input id="phone" type="tel" name="phone" value="<?= e($u['phone']) ?>"></div>
          <div class="field"><label for="org_id"><?= t('Organisation') ?></label><select id="org_id" name="org_id"><option value=""><?= t('— None —') ?></option><?php foreach ($orgs as $o): ?><option value="<?= (int)$o['id'] ?>"<?= selected($o['id'], $u['org_id']) ?>><?= e($o['name']) ?></option><?php endforeach; ?></select></div>
          <div class="field"><label for="status"><?= t('Status') ?></label><select id="status" name="status"><option value="active"<?= selected('active', $u['status']) ?>><?= t('Active') ?></option><option value="locked"<?= selected('locked', $u['status']) ?>><?= t('Locked (cannot sign in or open tickets)') ?></option></select></div>
          <div class="field"><label for="notes"><?= t('Internal notes') ?></label><textarea id="notes" name="notes" style="min-height:80px"><?= e($u['notes']) ?></textarea></div>
          <?php if ($u['password_hash']): ?><label class="check small"><input type="checkbox" name="clear_password" value="1"> <?= t('Remove password (user signs in with emailed codes)') ?></label><?php endif; ?>
          <?php if ($canEdit): ?><button class="btn btn-primary btn-block" type="submit"><?= t('Save') ?></button><?php endif; ?>
        </fieldset>
        <dl class="dl small" style="margin-top:14px">
          <dt><?= t('Registered') ?></dt><dd><?= $u['verified'] ? t('Yes') : t('No (guest)') ?></dd>
          <dt><?= t('Password') ?></dt><dd><?= $u['password_hash'] ? t('Set') : t('None') ?></dd>
          <dt><?= t('Last sign-in') ?></dt><dd><?= e(fmt_dt($u['last_login_at']) ?: __('Never')) ?></dd>
          <dt><?= t('Created') ?></dt><dd><?= e(fmt_dt($u['created_at'])) ?></dd>
        </dl>
      </div>
    </form>
    <?php if ($canEdit): ?>
    <div class="card"><div class="card-body">
      <form method="post" action="<?= e(url('/panel/users/' . $u['id'] . '/send-code')) ?>"><?= csrf_field() ?><button class="btn btn-block" type="submit"><?= t('Email user a sign-in code') ?></button></form>
      <?php if (Staff::can($me, 'user.delete')): ?>
      <form method="post" action="<?= e(url('/panel/users/' . $u['id'] . '/delete')) ?>" data-confirm="<?= t('Delete this user?') ?>" style="margin-top:10px">
        <?= csrf_field() ?>
        <label class="check small"><input type="checkbox" name="delete_tickets" value="1"> <?= t('Also delete their tickets') ?></label>
        <button class="btn btn-danger btn-block" type="submit"><?= t('Delete user') ?></button>
      </form>
      <?php endif; ?>
    </div></div>
    <?php endif; ?>
    <?= \Bakkbone\HelpCentre\Core\Hooks::render('panel.user.sidebar', ['user' => $u]) ?>
  </aside>
</div>
