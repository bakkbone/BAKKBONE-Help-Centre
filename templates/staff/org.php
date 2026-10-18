<?php
use Bakkbone\HelpCentre\Core\View;
use Bakkbone\HelpCentre\Services\Auth;
use Bakkbone\HelpCentre\Services\Staff;
use Bakkbone\HelpCentre\Services\Tickets;
$canEdit = Staff::can(Auth::staff(), 'org.manage');
?>
<p class="small"><a href="<?= e(url('/panel/orgs')) ?>"><?= t('← Organisations') ?></a></p>
<div class="page-head"><h1><?= e($o['name']) ?></h1>
  <?php if ($canEdit): ?><div class="actions"><form method="post" action="<?= e(url('/panel/orgs/' . $o['id'] . '/delete')) ?>" data-confirm="<?= t('Delete this organisation? Users are kept.') ?>"><?= csrf_field() ?><button class="btn btn-danger btn-sm" type="submit"><?= t('Delete') ?></button></form></div><?php endif; ?>
</div>
<div class="ticket-layout">
  <div>
    <div class="card card-flush">
      <div class="card-head"><h2><?= t('Users ({count})', ['count' => count($users)]) ?></h2></div>
      <div class="card-body">
        <?php if (!$users): ?><div class="empty-state small"><?= t('No users yet.') ?></div><?php else: ?>
        <table class="table"><tbody>
          <?php foreach ($users as $u): ?><tr data-href="<?= e(url('/panel/users/' . $u['id'])) ?>"><td class="primary"><a href="<?= e(url('/panel/users/' . $u['id'])) ?>"><?= e($u['name']) ?></a></td><td data-label="<?= t('Email') ?>"><?= e($u['email']) ?></td></tr><?php endforeach; ?>
        </tbody></table>
        <?php endif; ?>
      </div>
    </div>
    <div class="card card-flush">
      <div class="card-head"><h2><?= t('Recent tickets') ?></h2></div>
      <div class="card-body">
        <?php if (!$tickets): ?><div class="empty-state small"><?= t('No tickets.') ?></div><?php else: ?>
        <table class="table"><tbody>
          <?php foreach ($tickets as $t): ?>
            <tr data-href="<?= e(url('/panel/tickets/' . $t['id'])) ?>">
              <td data-label="<?= t('Ticket') ?>"><a href="<?= e(url('/panel/tickets/' . $t['id'])) ?>">#<?= e($t['number']) ?></a></td>
              <td class="primary"><?= e($t['subject']) ?><br><span class="small faint"><?= e($t['user_name']) ?></span></td>
              <td data-label="<?= t('Status') ?>"><?= e(Tickets::lookupName('ticket_statuses', $t['status_id'])) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody></table>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <aside class="side"><?= View::partial('staff/org_form', ['o' => $o, 'embedded' => true, 'readonly' => !$canEdit]) ?></aside>
</div>
