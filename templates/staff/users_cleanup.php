<?php
$registeredOnly = $unusedAll - $unusedGuests;
$guests = raw('<strong>' . tn('{n} guest user', '{n} guest users', $unusedGuests) . '</strong>');
?>
<p class="small"><a href="<?= e(url('/panel/users')) ?>"><?= t('← Users') ?></a></p>
<div class="page-head"><h1><?= t('Clean up users') ?></h1></div>
<div class="grid grid-2">
  <form class="card" method="post" action="<?= e(url('/panel/users/cleanup')) ?>"
        data-confirm="<?= t('Delete users with no tickets? This can\'t be undone.') ?>">
    <div class="card-head"><h2><?= t('Users with no tickets') ?></h2></div>
    <div class="card-body">
      <?= csrf_field() ?><input type="hidden" name="what" value="unused">
      <p class="muted"><?= t('Users who haven\'t opened a ticket, aren\'t CC\'d on any and have never posted. Users added in the last 24 hours are left alone.') ?></p>
      <p><?= $registeredOnly > 0
          ? t('{guests} can be removed, plus {registered} if you include them.', ['guests' => $guests, 'registered' => raw(tn('{n} registered account', '{n} registered accounts', $registeredOnly))])
          : t('{guests} can be removed.', ['guests' => $guests]) ?></p>
      <?php if ($registeredOnly > 0): ?>
        <label class="check"><input type="checkbox" name="include_registered" value="1"> <?= t('Also delete registered accounts (users who signed up or set a password)') ?></label>
      <?php endif; ?>
      <?php if ($unusedSample): ?>
        <details style="margin:12px 0">
          <summary class="small"><?= count($unusedSample) < $unusedAll ? t('Show the first {count}', ['count' => count($unusedSample)]) : t('Show them') ?></summary>
          <ul class="small" style="margin:8px 0 0;padding-inline-start:20px">
            <?php foreach ($unusedSample as $u): ?>
              <li><a href="<?= e(url('/panel/users/' . $u['id'])) ?>"><?= e($u['name'] ?: $u['email']) ?></a> <span class="muted">&lt;<?= e($u['email']) ?>&gt;<?= $u['verified'] ? ' · ' . t('registered') : '' ?></span></li>
            <?php endforeach; ?>
          </ul>
        </details>
      <?php endif; ?>
      <div class="form-actions">
        <button class="btn btn-danger" type="submit"<?= $unusedAll ? '' : ' disabled' ?>><?= icon('delete_sweep') ?><?= t('Delete users with no tickets') ?></button>
      </div>
    </div>
  </form>

  <form class="card" method="post" action="<?= e(url('/panel/users/cleanup')) ?>"
        data-confirm="<?= t('Delete every banned user AND all of their tickets? This can\'t be undone.') ?>">
    <div class="card-head"><h2><?= t('Banned users') ?></h2></div>
    <div class="card-body">
      <?= csrf_field() ?><input type="hidden" name="what" value="banned">
      <p class="muted"><?= tn('Users whose email address or domain is on the {link}ban list{/link} ({n} entry). Their tickets, replies and attachments are deleted with them.', 'Users whose email address or domain is on the {link}ban list{/link} ({n} entries). Their tickets, replies and attachments are deleted with them.', $banCount,
          ['link' => !empty(\Bakkbone\HelpCentre\Services\Auth::staff()['is_admin']) ? tag('<a href="' . e(url('/panel/admin/banlist')) . '">') : tag('<span>')]) ?></p>
      <?php if ($banned['users']): ?>
        <p><?= t('{users} with {tickets}.', ['users' => raw('<strong>' . tn('{n} banned user', '{n} banned users', $banned['users']) . '</strong>'), 'tickets' => raw('<strong>' . tn('{n} ticket', '{n} tickets', $banned['tickets']) . '</strong>')]) ?></p>
        <details style="margin:12px 0">
          <summary class="small"><?= count($banned['sample']) < $banned['users'] ? t('Show the first {count}', ['count' => count($banned['sample'])]) : t('Show them') ?></summary>
          <ul class="small" style="margin:8px 0 0;padding-inline-start:20px">
            <?php foreach ($banned['sample'] as $u): ?>
              <li><a href="<?= e(url('/panel/users/' . $u['id'])) ?>"><?= e($u['name'] ?: $u['email']) ?></a> <span class="muted">&lt;<?= e($u['email']) ?>&gt; · <?= tn('{n} ticket', '{n} tickets', (int)$u['ticket_count']) ?></span></li>
            <?php endforeach; ?>
          </ul>
        </details>
        <label class="check"><input type="checkbox" name="confirm" value="1" required> <?= t('I understand their tickets will be permanently deleted') ?></label>
      <?php else: ?>
        <p><?= t('No users match the ban list.') ?></p>
      <?php endif; ?>
      <div class="form-actions">
        <button class="btn btn-danger" type="submit"<?= $banned['users'] ? '' : ' disabled' ?>><?= icon('delete_sweep') ?><?= t('Delete banned users and their tickets') ?></button>
      </div>
    </div>
  </form>
</div>
