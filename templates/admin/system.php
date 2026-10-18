<div class="page-head"><h1><?= t('System & cron') ?></h1></div>
<div class="grid grid-2">
  <div class="card">
    <div class="card-head"><h2><?= t('Scheduled tasks (cron)') ?></h2>
      <?php $ok = $cronLast !== '' && strtotime($cronLast . ' UTC') > time() - 900; ?>
      <span class="badge <?= $ok ? 'badge-ok' : 'badge-bad' ?>"><?= $cronLast === '' ? t('Never run') : t('Last run {when}', ['when' => time_ago($cronLast)]) ?></span></div>
    <div class="card-body">
      <p><?= t('Cron fetches mail, sends queued email, retries webhooks, flags overdue tickets and auto-resolves stale ones. Run it every 1–5 minutes using <strong>one</strong> of these:') ?></p>
      <p class="small"><?= t('<strong>Linux crontab</strong>') ?></p>
      <pre id="cron1">*/2 * * * * <?= e(PHP_BINARY) ?> <?= e(HC_ROOT) ?>/bin/cron.php &gt;/dev/null 2&gt;&amp;1</pre>
      <p class="small"><?= t('<strong>Windows Task Scheduler</strong> (every 2 minutes)') ?></p>
      <pre><?= e(PHP_BINARY) ?> "<?= e(HC_ROOT) ?>\bin\cron.php"</pre>
      <p class="small"><?= t('<strong>Web cron</strong> (for hosts without cron access - keep the key secret)') ?></p>
      <p class="small muted"><?= t('Send the key in an {header} header if your web-cron service allows custom headers, so it stays out of web server logs:', ['header' => raw('<code>X-Cron-Key</code>')]) ?></p>
      <pre id="cronheader">curl -fsS -H "X-Cron-Key: <?= e($cronKey) ?>" "<?= e($cronEndpoint) ?>"</pre>
      <p class="small muted"><?= t('Otherwise use this URL, which has the key in it:') ?></p>
      <pre id="cronurl"><?= e($cronUrl) ?></pre>
      <div class="btn-row">
        <button class="btn btn-sm" type="button" data-copy="cronheader"><?= t('Copy command') ?></button>
        <button class="btn btn-sm" type="button" data-copy="cronurl"><?= t('Copy URL') ?></button>
        <form method="post" action="<?= e(url('/panel/admin/system/cron')) ?>" class="inline"><?= csrf_field() ?><button class="btn btn-sm btn-primary" type="submit"><?= t('Run cron now') ?></button></form>
      </div>
    </div>
  </div>
  <div class="card">
    <div class="card-head"><h2><?= t('Environment') ?></h2></div>
    <div class="card-body">
      <dl class="dl">
        <dt><?= t('Version') ?></dt><dd><?= t('BAKKBONE Help Centre {version} (schema {schema})', ['version' => HC_VERSION, 'schema' => $schemaVersion]) ?></dd>
        <?php foreach ($checks as $label => [$value, $pass]): ?>
          <dt><?= e($label) ?></dt><dd><?= $pass ? '' : '<span class="badge badge-bad">' . t('Check') . '</span> ' ?><span class="small"><?= e($value) ?></span></dd>
        <?php endforeach; ?>
      </dl>
      <form method="post" action="<?= e(url('/panel/admin/system/upgrade')) ?>" style="margin-top:14px"><?= csrf_field() ?>
        <button class="btn btn-sm" type="submit"><?= t('Check / apply database upgrades') ?></button>
        <div class="hint"><?= t('Run after updating the code. Safe to run at any time.') ?></div>
      </form>
    </div>
  </div>
  <div class="card">
    <div class="card-head"><h2><?= t('Totals') ?></h2></div>
    <div class="card-body"><dl class="dl"><?php foreach ($counts as $k => $v): ?><dt><?= e($k) ?></dt><dd><?= number_format($v) ?></dd><?php endforeach; ?></dl></div>
  </div>
</div>

<section class="card mt" id="copy">
  <div class="card-head"><h2 class="with-icon"><?= icon('cloud_download') ?><?= t('Copy this help desk') ?></h2>
    <?php if ($migrationExpires): ?><span class="badge badge-warn"><?= t('Key active until {when}', ['when' => fmt_dt(gmdate('Y-m-d H:i:s', $migrationExpires))]) ?></span><?php endif; ?></div>
  <div class="card-body">
    <p><?= t('Move everything to another server, or between staging and production. Install BAKKBONE Help Centre on the new server, choose <strong>A copy of another BAKKBONE Help Centre</strong> during setup, and give it a migration key from here. Tickets, attachments, agents (with their passwords and 2FA), users, settings, mailboxes and webhooks are all copied. This help desk isn\'t changed.') ?></p>
    <?php if (!empty($newMigrationKey)): ?>
      <div class="alert alert-success">
        <p><?= t('<strong>Your migration key</strong>. Copy it now; it won\'t be shown again. It works for 24 hours, and anyone who has it can copy this help desk, so treat it like a password.') ?></p>
        <pre class="cmd" id="mkey"><?= e($newMigrationKey) ?></pre>
        <button class="btn btn-sm" type="button" data-copy="mkey"><?= t('Copy key') ?></button>
      </div>
      <form method="post" action="<?= e(url('/panel/admin/system/export')) ?>" class="mt">
        <?= csrf_field() ?><input type="hidden" name="migration_key" value="<?= e($newMigrationKey) ?>">
        <p class="small muted"><?= t('If the new server can\'t reach this one over the internet (for example, a copy on your own computer), download an export file instead and upload it to the new server\'s <code>storage/import</code> folder. The file is encrypted with this key.') ?></p>
        <button class="btn btn-sm" type="submit"><?= t('Download export file') ?></button>
      </form>
    <?php else: ?>
      <div class="btn-row">
        <form method="post" action="<?= e(url('/panel/admin/system/migration-key')) ?>" class="inline"<?= $migrationExpires ? ' data-confirm="' . t('This replaces the current key. A copy that is still running with the old key will stop.') . '"' : '' ?>><?= csrf_field() ?>
          <button class="btn btn-primary btn-sm" type="submit"><?= $migrationExpires ? t('Create a new migration key') : t('Create migration key') ?></button></form>
        <?php if ($migrationExpires): ?>
          <form method="post" action="<?= e(url('/panel/admin/system/migration-key/revoke')) ?>" class="inline"><?= csrf_field() ?><button class="btn btn-sm btn-danger" type="submit"><?= t('Remove key') ?></button></form>
        <?php endif; ?>
      </div>
      <?php if ($migrationExpires): ?>
        <details class="guide"><summary><?= t('Download an export file') ?></summary>
          <form method="post" action="<?= e(url('/panel/admin/system/export')) ?>" class="inline-form mt"><?= csrf_field() ?>
            <input type="password" name="migration_key" placeholder="<?= t('Paste the migration key') ?>" autocomplete="off" required><button class="btn btn-sm" type="submit"><?= t('Download') ?></button></form>
        </details>
      <?php endif; ?>
    <?php endif; ?>
    <p class="small faint mt"><?= t('From the command line: {command} creates a key and an export file in one go.', ['command' => raw('<code>php bin/export.php --out=helpcentre.' . e(\Bakkbone\HelpCentre\Services\Migration::FILE_EXT) . '</code>')]) ?></p>
  </div>
</section>
