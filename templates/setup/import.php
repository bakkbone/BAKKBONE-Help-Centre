<?php
/** @var array $state @var int $percent @var bool $owner */
$status = $state['status'] ?? 'running';
?>
<?php if (!$owner): ?>
  <div class="card"><div class="card-body">
    <h1><?= t('An import is in progress') ?></h1>
    <p class="muted"><?= t('This help desk is being set up from osTicket. Sign in with the administrator account created for it to see the progress or continue.') ?></p>
    <form method="post" action="<?= e(url('/setup/import')) ?>">
      <?= csrf_field() ?><input type="hidden" name="action" value="unlock">
      <div class="field"><label for="login"><?= t('Username or email') ?></label><input id="login" type="text" name="login" required></div>
      <div class="field"><label for="password"><?= t('Password') ?></label><input id="password" type="password" name="password" required></div>
      <button class="btn btn-primary" type="submit"><?= t('Continue') ?></button>
    </form>
  </div></div>
<?php return; endif; ?>

<div class="card import-progress"<?= $status === 'running' ? ' data-import-run="' . e(url('/setup/import')) . '"' : '' ?>>
  <div class="card-body">
    <div class="import-status">
      <?php if ($status === 'done'): ?>
        <h1><?= t('Your help desk is ready') ?></h1>
        <p class="muted"><?= t('Everything from osTicket has been imported.') ?></p>
      <?php elseif ($status === 'failed'): ?>
        <h1><?= t('The import stopped') ?></h1>
        <div class="alert alert-error"><?= e((string)$state['error']) ?></div>
      <?php else: ?>
        <h1 data-import-title><?= t('Importing from osTicket…') ?></h1>
        <p class="muted small"><?= t('Keep this page open; if it\'s closed, the import pauses and continues when you come back.') ?></p>
      <?php endif; ?>
      <div class="progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= (int)$percent ?>" aria-label="<?= t('Import progress') ?>"><span style="width:<?= (int)$percent ?>%"></span></div>
      <p class="small muted"><span data-import-percent><?= (int)$percent ?></span><?= t('% done') ?></p>
    </div>
    <ol class="import-steps" data-import-steps>
      <?php foreach ($state['steps'] ?? [] as $key => $s): $cur = ($state['current'] ?? null) === $key; ?>
        <li class="import-step<?= $s['complete'] ? ' is-done' : '' ?><?= $cur ? ' is-current' : '' ?>">
          <?= $s['complete'] ? icon('check_circle', 'ok') : ($cur ? icon('sync', 'spin') : icon('schedule', 'faint')) ?>
          <span class="import-step-label"><?= e($s['label']) ?></span>
          <span class="import-step-count"><?= $key === 'finish' || !$s['total'] ? '' : number_format(min((int)$s['done'], (int)$s['total'])) . ' / ' . number_format((int)$s['total']) ?></span>
        </li>
      <?php endforeach; ?>
    </ol>
    <div class="form-actions">
      <?php if ($status === 'done'): ?>
        <a class="btn btn-primary" href="<?= e(url('/panel')) ?>"><?= t('Sign in to the agent panel') ?></a>
      <?php elseif ($status === 'failed'): ?>
        <form method="post" action="<?= e(url('/setup/import')) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="resume"><button class="btn btn-primary" type="submit"><?= icon('restart_alt') ?><?= t('Try again from here') ?></button></form>
      <?php else: ?>
        <noscript><form method="post" action="<?= e(url('/setup/import')) ?>"><?= csrf_field() ?><button class="btn btn-primary" type="submit"><?= t('Continue importing') ?></button></form></noscript>
      <?php endif; ?>
      <?php if ($status !== 'done'): ?>
        <form method="post" action="<?= e(url('/setup/import')) ?>" data-confirm="<?= t('Cancel setup? Everything imported so far is removed from this database and you go back to the setup page. osTicket isn\'t affected.') ?>"><?= csrf_field() ?><input type="hidden" name="action" value="abandon"><button class="btn btn-ghost" type="submit"><?= t('Cancel and start again') ?></button></form>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php if ($status === 'done'): ?>
<div class="card mt">
  <div class="card-body">
    <p><?= t('Sign in with the administrator account you just created. The full import report (anything skipped, mailboxes and filters to review, and a switch-over checklist) is under <strong>Admin → Import</strong>.') ?></p>
    <p class="small muted"><?= t('Until you switch over, keep using osTicket as normal, then run <strong>Admin → Import</strong> once more to bring across anything new. Already-imported items are skipped.') ?></p>
  </div>
</div>
<?php endif; ?>
