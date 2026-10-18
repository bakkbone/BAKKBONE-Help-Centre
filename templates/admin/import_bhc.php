<?php
use Bakkbone\HelpCentre\Core\View;
/** @var array|null $state @var int $percent @var array $sources */
$status = $state['status'] ?? null;
?>
<?= View::partial('admin/_import_tabs', ['tab' => 'bhc']) ?>

<?php if ($status === null || $status === 'done' && !empty($fresh)): ?>
  <div class="card">
    <div class="card-body">
      <div class="import-intro">
        <span class="action-ico"><?= icon('sync') ?></span>
        <div>
          <p><?= t('Bring across tickets and replies that arrived on another BAKKBONE Help Centre, for example a staging site that kept running after this one was copied from it, or a second help desk you\'re merging into this one.') ?></p>
          <ul class="small muted tips">
            <li><?= t('New tickets, messages, notes, attachments, ticket history, users, organisations, tasks and KB articles are added. Anything that already exists here is matched up rather than duplicated.') ?></li>
            <li><?= t('If a ticket, user or article changed on both sides, the newer change wins.') ?></li>
            <li><?= t('Settings, email templates, filters, webhooks and API keys here aren\'t touched. New agents and mailboxes are added, and new mailboxes arrive switched off.') ?></li>
            <li><?= t('After the first run, only what changed since the previous run is fetched, so catch-ups are quick. Nothing is emailed during an import.') ?></li>
          </ul>
        </div>
      </div>
    </div>
  </div>

  <?php if ($sources): ?>
  <div class="card mt">
    <div class="card-head"><h2><?= t('Help desks this one has data from') ?></h2></div>
    <div class="card-flush"><ul class="list-plain">
      <?php foreach ($sources as $id => $src): ?>
        <li class="source-row">
          <div><strong><?= e($src['helpdesk'] ?: $src['base_url']) ?></strong> <span class="small muted"><?= e($src['base_url']) ?></span><br>
            <span class="small faint"><?= !empty($src['last_sync']) ? t('Last synced {when}', ['when' => fmt_dt((string)$src['last_sync'])]) : t('Last synced never') ?><?= !empty($src['how']) ? ' (' . e(__($src['how'])) . ')' : '' ?></span></div>
          <button class="btn btn-sm btn-tonal" type="button" data-fill-source="<?= e($src['base_url']) ?>"><?= t('Catch up') ?></button>
        </li>
      <?php endforeach; ?>
    </ul></div>
  </div>
  <?php endif; ?>

  <form class="card mt" method="post" action="<?= e(url('/panel/admin/import/bhc/start')) ?>" autocomplete="off">
    <div class="card-body">
      <?= csrf_field() ?>
      <ol class="steps small">
        <li><?= t('On the other help desk, go to <strong>Admin → System → Copy this help desk</strong> and click <strong>Create migration key</strong>.') ?></li>
        <li><?= t('Enter its address and the key here.') ?></li>
      </ol>
      <div class="form-grid">
        <div class="field"><label class="req" for="bhc_url"><?= t('Address of the other help desk') ?></label><input id="bhc_url" type="url" name="bhc_url" value="<?= e(old('bhc_url')) ?>" placeholder="<?= t('https://staging.help.example.com') ?>" required></div>
        <div class="field"><label class="req" for="bhc_key"><?= t('Migration key') ?></label><input id="bhc_key" type="password" name="bhc_key" placeholder="<?= t('bkhc_…') ?>" required></div>
      </div>
      <label class="check"><input type="checkbox" name="full" value="1"> <span><?= t('Check everything, not just what changed since the last run') ?><br><span class="small muted"><?= t('Slower. Use it if a previous run was interrupted for a long time or you think something was missed.') ?></span></span></label>
      <div class="form-actions"><button class="btn btn-primary" type="submit"><?= icon('sync') ?><?= t('Import changes') ?></button></div>
    </div>
  </form>

<?php else: ?>
  <div class="card import-progress"<?= $status === 'running' ? ' data-import-run="' . e(url('/panel/admin/import/bhc/step')) . '"' : '' ?>>
    <div class="card-body">
      <div class="import-status">
        <?php if ($status === 'running'): ?>
          <h2 data-import-title><?= t('Importing from {name}…', ['name' => $state['manifest']['helpdesk'] ?? __('the other help desk')]) ?></h2>
          <p class="muted small"><?= $state['since'] ? t('Changes since {when}.', ['when' => fmt_dt((string)$state['since'])]) : t('Checking everything.') ?> <?= t('Keep this page open; if it\'s closed, the import pauses and continues when you come back.') ?></p>
        <?php elseif ($status === 'done'): ?>
          <h2><?= t('Import complete') ?></h2>
          <p class="muted small"><?= t('From {address}, finished {when}.', ['address' => $state['manifest']['base_url'] ?? '', 'when' => fmt_dt((string)($state['finished_at'] ?? ''))]) ?></p>
        <?php else: ?>
          <h2><?= t('The import stopped') ?></h2>
          <div class="alert alert-error"><?= e((string)$state['error']) ?></div>
        <?php endif; ?>
        <div class="progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= (int)$percent ?>" aria-label="<?= t('Import progress') ?>"><span style="width:<?= (int)$percent ?>%"></span></div>
        <p class="small muted"><span data-import-percent><?= (int)$percent ?></span><?= t('% done') ?></p>
      </div>

      <?php if ($status === 'done'): ?>
        <?php $added = array_filter($state['added'] ?? []); $updated = array_filter($state['updated'] ?? []); ?>
        <?php if (!$added && !$updated): ?>
          <p><?= t('Everything was already up to date. Nothing new to bring across.') ?></p>
        <?php else: ?>
          <div class="import-counts">
            <?php foreach ($added as $t => $n): ?><div><strong><?= number_format($n) ?></strong><span><?= t('new {items}', ['items' => mb_strtolower(__($state['steps'][$t]['label'] ?? $t))]) ?></span></div><?php endforeach; ?>
            <?php foreach ($updated as $t => $n): ?><div><strong><?= number_format($n) ?></strong><span><?= t('updated {items}', ['items' => mb_strtolower(__($state['steps'][$t]['label'] ?? $t))]) ?></span></div><?php endforeach; ?>
          </div>
        <?php endif; ?>
        <?php if (!empty($state['missing_files'])): ?><div class="alert alert-warning mt"><?= tn('{n} attachment file was missing on the other help desk.', '{n} attachment files were missing on the other help desk.', (int)$state['missing_files']) ?></div><?php endif; ?>
      <?php else: ?>
        <ol class="import-steps" data-import-steps>
          <?php foreach ($state['steps'] as $key => $s): $cur = ($state['current'] ?? null) === $key; ?>
            <li class="import-step<?= $s['complete'] ? ' is-done' : '' ?><?= $cur ? ' is-current' : '' ?>">
              <?= $s['complete'] ? icon('check_circle', 'ok') : ($cur ? icon('sync', 'spin') : icon('schedule', 'faint')) ?>
              <span class="import-step-label"><?= e(__($s['label'])) ?></span>
              <span class="import-step-count"><?= (int)$s['done'] ? t('{count} checked', ['count' => fmt_num((int)$s['done'])]) : '' ?></span>
            </li>
          <?php endforeach; ?>
        </ol>
      <?php endif; ?>

      <div class="form-actions">
        <?php if ($status === 'running'): ?>
          <noscript><form method="post" action="<?= e(url('/panel/admin/import/bhc/step')) ?>"><?= csrf_field() ?><button class="btn btn-primary" type="submit"><?= t('Continue importing') ?></button></form></noscript>
        <?php elseif ($status === 'failed'): ?>
          <form method="post" action="<?= e(url('/panel/admin/import/bhc/step')) ?>"><?= csrf_field() ?><input type="hidden" name="resume" value="1"><button class="btn btn-primary" type="submit"><?= icon('restart_alt') ?><?= t('Try again from here') ?></button></form>
        <?php endif; ?>
        <?php if ($status !== 'running'): ?>
          <form method="post" action="<?= e(url('/panel/admin/import/bhc/reset')) ?>"><?= csrf_field() ?><button class="btn" type="submit"><?= $status === 'done' ? t('Done') : t('Cancel this import') ?></button></form>
        <?php endif; ?>
      </div>
    </div>
  </div>
<?php endif; ?>
