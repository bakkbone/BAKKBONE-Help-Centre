<?php
/** @var array $state @var int $percent @var bool $owner */
$status = $state['status'] ?? 'running';
$m = $state['manifest'] ?? [];
$from = (string)($m['base_url'] ?? __('the other help desk'));
?>
<?php if (!$owner): ?>
  <div class="card"><div class="card-body">
    <h1><?= t('A copy is in progress') ?></h1>
    <p class="muted"><?= t('This help desk is being copied from {address}. To see the progress or continue it, enter the migration key it was started with.', ['address' => $from]) ?></p>
    <form method="post" action="<?= e(url('/setup/clone')) ?>">
      <?= csrf_field() ?><input type="hidden" name="action" value="unlock">
      <div class="field"><label for="clone_key"><?= t('Migration key') ?></label><input id="clone_key" type="password" name="clone_key" required autocomplete="off"></div>
      <button class="btn btn-primary" type="submit"><?= t('Continue') ?></button>
    </form>
  </div></div>
<?php return; endif; ?>

<div class="card import-progress"<?= $status === 'running' ? ' data-import-run="' . e(url('/setup/clone')) . '"' : '' ?>>
  <div class="card-body">
    <div class="import-status">
      <?php if ($status === 'done'): ?>
        <h1><?= t('Copy complete') ?></h1>
        <p class="muted"><?= t('Everything from {name} ({address}) is now on this server.', ['name' => raw('<strong>' . e($m['helpdesk'] ?? '') . '</strong>'), 'address' => $from]) ?></p>
      <?php elseif ($status === 'failed'): ?>
        <h1><?= t('The copy stopped') ?></h1>
        <div class="alert alert-error"><?= e((string)$state['error']) ?></div>
      <?php else: ?>
        <h1 data-import-title><?= t('Copying {name}…', ['name' => $m['helpdesk'] ?? __('your help desk')]) ?></h1>
        <p class="muted small"><?= t('From {address}. Keep this page open; if it\'s closed, the copy pauses and continues when you come back.', ['address' => $from]) ?></p>
      <?php endif; ?>
      <div class="progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= (int)$percent ?>" aria-label="<?= t('Copy progress') ?>"><span style="width:<?= (int)$percent ?>%"></span></div>
      <p class="small muted"><span data-import-percent><?= (int)$percent ?></span><?= t('% done') ?></p>
    </div>
    <ol class="import-steps" data-import-steps>
      <?php foreach ($state['steps'] ?? [] as $key => $s):
          if ((int)$s['total'] === 0 && $key !== 'finish' && $key !== 'files') { continue; }
          $cur = ($state['current'] ?? null) === $key; ?>
        <li class="import-step<?= $s['complete'] ? ' is-done' : '' ?><?= $cur ? ' is-current' : '' ?>">
          <?= $s['complete'] ? icon('check_circle', 'ok') : ($cur ? icon('sync', 'spin') : icon('schedule', 'faint')) ?>
          <span class="import-step-label"><?= e(__($s['label'])) ?></span>
          <span class="import-step-count"><?= $key === 'finish' ? '' : number_format((int)$s['done']) . ' / ' . number_format(max((int)$s['total'], (int)$s['done'])) ?></span>
        </li>
      <?php endforeach; ?>
    </ol>
    <div class="form-actions">
      <?php if ($status === 'done'): ?>
        <a class="btn btn-primary" href="<?= e(url('/panel')) ?>"><?= t('Sign in to the agent panel') ?></a>
      <?php elseif ($status === 'failed'): ?>
        <form method="post" action="<?= e(url('/setup/clone')) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="resume"><button class="btn btn-primary" type="submit"><?= icon('restart_alt') ?><?= t('Try again from here') ?></button></form>
      <?php else: ?>
        <noscript><form method="post" action="<?= e(url('/setup/clone')) ?>"><?= csrf_field() ?><button class="btn btn-primary" type="submit"><?= t('Continue copying') ?></button></form></noscript>
      <?php endif; ?>
      <?php if ($status !== 'done'): ?>
        <form method="post" action="<?= e(url('/setup/clone')) ?>" data-confirm="<?= t('Cancel the copy? Everything copied so far is removed from this database and you go back to the setup page.') ?>"><?= csrf_field() ?><input type="hidden" name="action" value="abandon"><button class="btn btn-ghost" type="submit"><?= t('Cancel and start again') ?></button></form>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php foreach ($state['warnings'] ?? [] as $w): ?><div class="alert alert-warning mt"><?= e($w) ?></div><?php endforeach; ?>

<?php if ($status === 'done'): ?>
<div class="card mt">
  <div class="card-head"><h2><?= t('Next steps') ?></h2></div>
  <div class="card-body">
    <ol class="steps">
      <li><?= t('<strong>Sign in</strong> with your usual agent account from {address}. Passwords and two-factor codes work as before.', ['address' => $from]) ?></li>
      <li><?= t('<strong>Set up cron</strong> on this server (see Admin → System). Scheduled tasks don\'t move with the data.') ?></li>
      <?php if (!empty($state['pause'])): ?>
      <li><?= t('<strong>Switch over email</strong>: turn off the mailboxes (or cron) on {address}, then switch them on here under Admin → Mailboxes. Webhooks are also paused here; switch them on under Admin → Webhooks.', ['address' => $from]) ?></li>
      <?php else: ?>
      <li><?= t('<strong>Turn off the original</strong>: its mailboxes and webhooks are still active, and so are these, so both would fetch the same email. Switch off the mailboxes or cron on {address}.', ['address' => $from]) ?></li>
      <?php endif; ?>
      <li><?= t('<strong>Check the address</strong>: links in tickets now point to this server. If website forms post to the API, change their address to this server; their API keys still work.') ?></li>
      <li><?= t('<strong>Remove the migration key</strong> on {address} (Admin → System), and delete any export file from <code>storage/import</code> here.', ['address' => $from]) ?></li>
    </ol>
  </div>
</div>
<?php endif; ?>
