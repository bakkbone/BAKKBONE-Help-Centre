<?php
/** @var array|null $state @var int $percent @var array|null $preview */
$status = $state['status'] ?? null;
$labels = ['priorities' => __('Priorities'), 'statuses' => __('Ticket statuses'), 'sla' => __('SLA plans'), 'departments' => __('Departments'), 'staff' => __('Agents'),
    'teams' => __('Teams'), 'topics' => __('Help topics'), 'orgs' => __('Organisations'), 'users' => __('Users'), 'tickets' => __('Tickets'), 'tasks' => __('Tasks'),
    'canned' => __('Canned replies'), 'kb_categories' => __('KB categories'), 'kb_articles' => __('KB articles'), 'fields' => __('Custom fields'),
    'filters' => __('Filters'), 'mailboxes' => __('Mailboxes'), 'apikeys' => __('API keys')];
$stepRow = function (array $s, string $key, ?string $current): string {
    $icon = $s['complete'] ? icon('check_circle', 'ok') : ($key === $current ? icon('sync', 'spin') : icon('schedule', 'faint'));
    $count = $s['total'] ? number_format(min((int)$s['done'], (int)$s['total'])) . ' / ' . number_format((int)$s['total']) : ($s['complete'] ? '' : '—');
    return '<li class="import-step' . ($s['complete'] ? ' is-done' : '') . ($key === $current ? ' is-current' : '') . '" data-step="' . e($key) . '">'
        . $icon . '<span class="import-step-label">' . e(__($s['label'])) . '</span><span class="import-step-count">' . e($key === 'finish' ? '' : $count) . '</span></li>';
};
?>
<?= \Bakkbone\HelpCentre\Core\View::partial('admin/_import_tabs', ['tab' => 'osticket']) ?>

<?php if ($status === null): ?>
  <div class="card">
    <div class="card-body">
      <div class="import-intro">
        <span class="action-ico"><?= icon('cloud_download') ?></span>
        <div>
          <p><?= t('Copy everything from an existing osTicket help desk (version 1.10 or newer) into this one: tickets with their full conversations, internal notes, attachments, inline images, custom fields and activity history, plus users, organisations, agents, teams, departments, help topics, SLA plans, statuses, priorities, tasks, canned replies, the knowledge base, filters, the ban list, API keys, mailbox settings and your ticket number format.') ?></p>
          <ul class="small muted tips">
            <li><?= t('osTicket is only <strong>read</strong>, never changed, so you can keep using it until you switch over.') ?></li>
            <li><?= t('Ticket numbers are kept, so customers\' replies to old osTicket emails still land on the right ticket.') ?></li>
            <li><?= t('Agents and users can sign in with their existing osTicket passwords, or with an emailed code.') ?></li>
            <li><?= t('Website forms that use osTicket\'s API keep working: the keys are copied, and osTicket\'s API address works here too. Only the domain needs changing.') ?></li>
            <li><?= t('Mailboxes and filters arrive switched off, so nothing fetches or acts on email until you\'ve checked it.') ?></li>
            <li><?= t('Nobody is emailed and no webhooks fire during the import. It\'s safe to run again: anything already imported is skipped, and anything new (including the extras added since your last import) is filled in.') ?></li>
          </ul>
        </div>
      </div>
    </div>
  </div>

  <?php if ($preview): $c = $preview['counts']; ?>
  <div class="card mt">
    <div class="card-head"><h2><?= $preview['helpdesk'] !== '' ? t('Ready to import from "{name}"', ['name' => $preview['helpdesk']]) : t('Ready to import') ?></h2></div>
    <div class="card-body">
      <div class="import-counts">
        <?php foreach (['tickets', 'users', 'orgs', 'staff', 'departments', 'topics', 'fields', 'kb_articles', 'canned', 'filters', 'mailboxes', 'apikeys'] as $k): ?>
          <div><strong><?= number_format((int)($c[$k] ?? 0)) ?></strong><span><?= e($labels[$k]) ?></span></div>
        <?php endforeach; ?>
      </div>
      <?php $att = array_sum($preview['storage'] ?? []); if ($att): ?>
        <p class="small muted mt"><?= tn('{n} attachment:', '{n} attachments:', $att) ?>
          <?= isset($preview['storage']['D']) ? t('{count} in the database', ['count' => fmt_num($preview['storage']['D'])]) : '' ?>
          <?= isset($preview['storage']['F']) ? (isset($preview['storage']['D']) ? t(', ') : '') . ($preview['files'] ? t('{count} on disk in {folder}', ['count' => fmt_num($preview['storage']['F']), 'folder' => raw('<code>' . e($preview['files']) . '</code>')]) . (is_dir((string)$preview['files']) ? '' : ' <span class="badge badge-warn">' . t('folder not readable from here') . '</span>') : t('{count} on disk', ['count' => fmt_num($preview['storage']['F'])])) : '' ?>
        </p>
      <?php endif; ?>
      <p class="small faint"><?= t('Dates are converted from osTicket\'s database time zone ({zone}).', ['zone' => $preview['db_timezone']]) ?></p>
    </div>
  </div>
  <?php endif; ?>

  <form class="card mt" method="post" action="<?= e(url('/panel/admin/import/start')) ?>" autocomplete="off">
    <div class="card-body">
      <?= csrf_field() ?>
      <div class="field" style="max-width:420px"><label for="mode"><?= t('Where is osTicket?') ?></label>
        <select id="mode" name="mode">
          <option value="path"<?= selected('path', old('mode', 'path')) ?>><?= t('On this server: I\'ll give its folder') ?></option>
          <option value="db"<?= selected('db', old('mode')) ?>><?= t('Somewhere else: I\'ll enter its database details') ?></option>
        </select></div>

      <div data-show-when="mode=path">
        <div class="field"><label for="ost_path"><?= t('osTicket folder on this server') ?></label>
          <input id="ost_path" type="text" name="ost_path" value="<?= e(old('ost_path')) ?>" placeholder="<?= t('/home/account/public_html/support') ?>">
          <div class="hint"><?= t('The folder that contains osTicket\'s <code>include/ost-config.php</code>. The database details are read from that file.') ?></div></div>
      </div>

      <div data-show-when="mode=db">
        <div class="form-grid">
          <div class="field"><label for="db_host"><?= t('Database host') ?></label><input id="db_host" type="text" name="db_host" value="<?= e(old('db_host', 'localhost')) ?>"></div>
          <div class="field"><label for="db_port"><?= t('Port') ?></label><input id="db_port" type="number" name="db_port" value="<?= e(old('db_port', '3306')) ?>"></div>
          <div class="field"><label for="db_name"><?= t('Database name') ?></label><input id="db_name" type="text" name="db_name" value="<?= e(old('db_name')) ?>"></div>
          <div class="field"><label for="db_prefix"><?= t('Table prefix') ?></label><input id="db_prefix" type="text" name="db_prefix" value="<?= e(old('db_prefix', 'ost_')) ?>"></div>
          <div class="field"><label for="db_user"><?= t('Username') ?></label><input id="db_user" type="text" name="db_user" value="<?= e(old('db_user')) ?>"></div>
          <div class="field"><label for="db_pass"><?= t('Password') ?></label><input id="db_pass" type="password" name="db_pass" autocomplete="new-password" placeholder="<?= $preview ? t('Leave blank to use the one you just checked') : '' ?>"></div>
        </div>
        <p class="small muted"><?= t('These are in osTicket\'s <code>include/ost-config.php</code> (<code>DBHOST</code>, <code>DBNAME</code>, <code>DBUSER</code>, <code>DBPASS</code>, <code>TABLE_PREFIX</code>). A read-only database user is enough.') ?></p>
      </div>

      <details class="guide">
        <summary><?= t('Attachments stored on disk') ?></summary>
        <p class="small muted"><?= t('osTicket normally keeps attachments in its database, and they\'re copied automatically. If you used osTicket\'s "Attachments on the Filesystem" plugin, the folder is detected for you. Enter it here only if it has moved or isn\'t on this server\'s usual path.') ?></p>
        <div class="field"><label for="files_path"><?= t('osTicket attachments folder') ?></label><input id="files_path" type="text" name="files_path" value="<?= e(old('files_path')) ?>" placeholder="<?= t('Detected automatically') ?>"></div>
      </details>

      <label class="check"><input type="checkbox" name="import_templates" value="1"<?= checked(old('import_templates')) ?>>
        <span><?= t('Also replace my email templates with osTicket\'s') ?><br><span class="small muted"><?= t('Only if you customised them in osTicket. Variables like <code>%{ticket.dept.name}</code> are translated; any without an equivalent here are listed afterwards.') ?></span></span></label>

      <div class="form-actions">
        <button class="btn btn-primary" type="submit"><?= icon('cloud_download') ?><?= t('Import from osTicket') ?></button>
        <button class="btn" type="submit" formaction="<?= e(url('/panel/admin/import/check')) ?>"><?= t('Check connection first') ?></button>
      </div>
    </div>
  </form>

<?php else: $steps = $state['steps']; ?>
  <div class="card import-progress"<?= $status === 'running' ? ' data-import-run="' . e(url('/panel/admin/import/step')) . '"' : '' ?>>
    <div class="card-body">
      <div class="import-status">
        <?php if ($status === 'running'): ?>
          <h2 data-import-title><?= t('Importing from osTicket…') ?></h2>
          <p class="muted small" data-import-note><?= t('Keep this page open until it finishes. If you close it, the import pauses and picks up where it left off when you come back.') ?></p>
        <?php elseif ($status === 'done'): ?>
          <h2><?= t('Import complete') ?></h2>
          <p class="muted small"><?= t('Finished {when}.', ['when' => fmt_dt((string)$state['finished_at'])]) ?></p>
        <?php else: ?>
          <h2><?= t('The import stopped') ?></h2>
          <div class="alert alert-error"><?= e((string)$state['error']) ?></div>
        <?php endif; ?>
        <div class="progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= (int)$percent ?>" aria-label="<?= t('Import progress') ?>">
          <span style="width:<?= (int)$percent ?>%"></span>
        </div>
        <p class="small muted"><span data-import-percent><?= (int)$percent ?></span><?= t('% done') ?></p>
      </div>
      <ol class="import-steps" data-import-steps>
        <?php foreach ($steps as $key => $s): ?><?= $stepRow($s, $key, $state['current'] ?? null) ?><?php endforeach; ?>
      </ol>

      <div class="form-actions">
        <?php if ($status === 'running'): ?>
          <noscript><form method="post" action="<?= e(url('/panel/admin/import/step')) ?>"><?= csrf_field() ?><button class="btn btn-primary" type="submit"><?= t('Continue importing') ?></button></form></noscript>
        <?php elseif ($status === 'failed'): ?>
          <form method="post" action="<?= e(url('/panel/admin/import/step')) ?>"><?= csrf_field() ?><input type="hidden" name="resume" value="1"><button class="btn btn-primary" type="submit"><?= icon('restart_alt') ?><?= t('Try again from here') ?></button></form>
        <?php endif; ?>
        <?php if ($status !== 'running'): ?>
          <form method="post" action="<?= e(url('/panel/admin/import/reset')) ?>" data-confirm="<?= t('Clear this import? Anything already imported stays, and running it again skips it.') ?>"><?= csrf_field() ?><button class="btn" type="submit"><?= t('Start a new import') ?></button></form>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <?php if (!empty($state['warnings'])): ?>
    <?php foreach ($state['warnings'] as $w): ?><div class="alert alert-warning mt"><?= e($w) ?></div><?php endforeach; ?>
  <?php endif; ?>

  <?php if ($status === 'done'): ?>
  <div class="card mt">
    <div class="card-head"><h2><?= t('Before you switch over') ?></h2></div>
    <div class="card-body">
      <ol class="steps">
        <li><?= t('<strong>Check your agents</strong> under {link}Admin → Agents{/link}. Roles were matched by name ("All access", "Limited access" and so on); review anyone you gave custom permissions in osTicket.', ['link' => tag('<a href="' . e(url('/panel/admin/staff')) . '">')]) ?></li>
        <li><?= t('<strong>Switch your mailboxes over</strong>: turn off email fetching in osTicket, then under {link}Admin → Mailboxes{/link} add each mailbox\'s password (or Microsoft 365 app details), test it and switch it on.', ['link' => tag('<a href="' . e(url('/panel/admin/emails')) . '">')]) ?></li>
        <li><?= t('<strong>Review the imported filters</strong> under {link}Admin → Filters{/link}. Each one says in its notes if part of it couldn\'t be copied. Switch them on when you\'re happy.', ['link' => tag('<a href="' . e(url('/panel/admin/filters')) . '">')]) ?></li>
        <li><?= t('<strong>Point your website\'s ticket form</strong> at this help desk\'s domain. The API keys and osTicket\'s <code>/api/http.php/tickets.json</code> address work unchanged.') ?></li>
      </ol>
      <p class="small muted"><?= empty($state['options']['templates']) ? t('Not copied: saved searches and custom queues, mailbox passwords, file-upload custom fields, and filter actions with no equivalent here (they\'re listed in each filter\'s notes). Email templates were left as they were.') : t('Not copied: saved searches and custom queues, mailbox passwords, file-upload custom fields, and filter actions with no equivalent here (they\'re listed in each filter\'s notes).') ?></p>
    </div>
  </div>
  <?php endif; ?>

  <?php if (!empty($state['skipped'])): ?>
  <details class="card mt guide-card">
    <summary class="card-head"><h2><?= tn('{n} item skipped', '{n} items skipped', (int)($state['skipped_count'] ?? count($state['skipped']))) ?></h2></summary>
    <div class="card-body">
      <ul class="small tips"><?php foreach ($state['skipped'] as $sk): ?><li><?= e($sk) ?></li><?php endforeach; ?></ul>
      <?php if (($state['skipped_count'] ?? 0) > count($state['skipped'])): ?><p class="small faint"><?= t('Showing the first {count}.', ['count' => count($state['skipped'])]) ?></p><?php endif; ?>
    </div>
  </details>
  <?php endif; ?>
<?php endif; ?>
