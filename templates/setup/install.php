<?php if ($result): ?>
  <div class="card"><div class="card-body">
    <h1><?= t('Installation complete 🎉') ?></h1>
    <?php if ($result['written']): ?>
      <div class="alert alert-success"><?= t('Configuration saved to <code>config/config.php</code>. Back up that file - it contains your encryption key.') ?></div>
    <?php else: ?>
      <div class="alert alert-warning"><?= t('The <code>config/</code> folder isn\'t writable. Create <code>config/config.php</code> with the contents below, then continue.') ?></div>
      <textarea class="code" readonly id="cfg"><?= e($result['config']) ?></textarea>
      <p><button class="btn btn-sm" type="button" data-copy="cfg"><?= t('Copy') ?></button></p>
    <?php endif; ?>
    <h2><?= t('Next steps') ?></h2>
    <ol>
      <li><?= t('Sign in to the {link}agent panel{/link} with the administrator account you created.', ['link' => tag('<a href="' . e(url('/panel')) . '">')]) ?></li>
      <li><?= t('Add your support mailbox under <strong>Admin → Mailboxes</strong> (IMAP, POP3 or Microsoft 365) and send a test email.') ?></li>
      <li><?= t('Set up cron to run <code>php bin/cron.php</code> every 2 minutes (see <strong>Admin → System</strong>).') ?></li>
      <li><?= t('Review departments, help topics, SLA plans and email templates.') ?></li>
    </ol>
    <p><a class="btn btn-primary" href="<?= e(url('/panel')) ?>"><?= t('Go to the agent panel') ?></a></p>
  </div></div>
<?php else: ?>
  <?php foreach ($warnings ?? [] as $w): ?><div class="alert alert-error"><?= e($w) ?></div><?php endforeach; ?>
  <?php if (count($languages ?? []) > 1): ?>
  <form class="card" method="get" action="<?= e(url('/setup')) ?>">
    <div class="card-body">
      <div class="field" style="max-width:360px"><label for="lang"><?= t('Language') ?></label>
        <select id="lang" name="lang"><?php foreach ($languages as $code => $l): ?><option value="<?= e($code) ?>"<?= selected($code, \Bakkbone\HelpCentre\Core\I18n::locale()) ?>><?= e($l['native'] !== $l['name'] ? $l['native'] . ' (' . $l['name'] . ')' : $l['name']) ?></option><?php endforeach; ?></select></div>
      <div class="hint"><?= t('The help desk is set up in this language. You can change it later under Admin → Settings.') ?></div>
      <div class="form-actions"><button class="btn btn-sm" type="submit"><?= t('Change language') ?></button></div>
    </div>
  </form>
  <?php endif; ?>
  <div class="card">
    <div class="card-head"><h2><?= t('Requirements') ?></h2></div>
    <div class="card-body">
      <dl class="dl"><?php foreach ($checks as $label => $ok): ?><dt><?= e($label) ?></dt><dd><?= $ok ? '<span class="badge badge-ok">' . t('OK') . '</span>' : '<span class="badge badge-bad">' . t('Missing') . '</span>' ?></dd><?php endforeach; ?>
        <dt><?= t('Components') ?></dt><dd><span class="badge badge-ok">OK</span> <span class="small muted"><?= e($components ?? '') ?></span></dd></dl>
    </div>
  </div>
  <form class="card" method="post" action="<?= e(url('/setup')) ?>">
    <div class="card-body">
      <?= csrf_field() ?>
      <fieldset class="mode-picker"><legend><?= t('How do you want to start?') ?></legend>
        <label class="mode-card"><input type="radio" name="setup_mode" value="fresh"<?= checked(old('setup_mode', 'fresh') === 'fresh' ? '1' : '') ?>>
          <span class="mode-ico"><?= icon('add') ?></span><span><?= t('<strong>A new, empty help desk</strong>') ?><span class="small muted"><?= t('Set up departments, agents and mailboxes from scratch, starting from sensible defaults.') ?></span></span></label>
        <label class="mode-card"><input type="radio" name="setup_mode" value="osticket"<?= checked(old('setup_mode') === 'osticket' ? '1' : '') ?>>
          <span class="mode-ico"><?= icon('upload_file') ?></span><span><?= t('<strong>Moving from osTicket</strong>') ?><span class="small muted"><?= t('Start with everything from your osTicket help desk: tickets, users, agents, departments, topics, custom fields, filters and more. osTicket isn\'t changed.') ?></span></span></label>
        <label class="mode-card"><input type="radio" name="setup_mode" value="clone"<?= checked(old('setup_mode') === 'clone' ? '1' : '') ?>>
          <span class="mode-ico"><?= icon('cloud_download') ?></span><span><?= t('<strong>A copy of another BAKKBONE Help Centre</strong>') ?><span class="small muted"><?= t('For example, moving from staging to production or to a new server. Everything is copied: tickets, attachments, agents and their passwords, settings and mailboxes.') ?></span></span></label>
      </fieldset>

      <fieldset data-show-when="setup_mode=clone" data-disable-hidden><legend><?= t('Copy from') ?></legend>
        <ol class="steps small">
          <li><?= t('On the help desk you\'re copying, go to <strong>Admin → System → Copy this help desk</strong> and click <strong>Create migration key</strong>.') ?></li>
          <li><?= t('Paste the key below. It works for 24 hours.') ?></li>
        </ol>
        <div class="field" style="max-width:420px"><label for="clone_mode"><?= t('Get the data') ?></label>
          <select id="clone_mode" name="clone_mode">
            <option value="url"<?= selected('url', old('clone_mode', 'url')) ?>><?= t('Directly from the other help desk over the internet') ?></option>
            <option value="file"<?= selected('file', old('clone_mode')) ?>><?= t('From an export file I\'ve uploaded') ?></option>
          </select></div>
        <div class="field" data-show-when="clone_mode=url" data-disable-hidden><label class="req" for="clone_url"><?= t('Address of the help desk to copy') ?></label>
          <input id="clone_url" type="url" name="clone_url" value="<?= e(old('clone_url')) ?>" placeholder="<?= t('https://staging.help.example.com') ?>" required>
          <div class="hint"><?= t('This server needs to be able to reach it. If it can\'t (for example, it\'s on your own computer), use an export file instead.') ?></div></div>
        <div class="field" data-show-when="clone_mode=file" data-disable-hidden><label class="req" for="clone_file"><?= t('Export file') ?></label>
          <?php if (!empty($importFiles)): ?>
            <select id="clone_file" name="clone_file" required><?php foreach ($importFiles as $f): ?><option<?= selected($f, old('clone_file')) ?>><?= e($f) ?></option><?php endforeach; ?></select>
          <?php else: ?>
            <input id="clone_file" type="text" name="clone_file" value="<?= e(old('clone_file')) ?>" placeholder="<?= t('helpcentre-export.bhcx') ?>" required>
          <?php endif; ?>
          <div class="hint"><?= t('Download the file from <strong>Admin → System</strong> on the other help desk, then upload it (by FTP or your host\'s file manager) into this install\'s <code>storage/import</code> folder and reload this page. That avoids upload size limits.') ?></div></div>
        <div class="field"><label class="req" for="clone_key"><?= t('Migration key') ?></label>
          <input id="clone_key" type="password" name="clone_key" autocomplete="off" placeholder="<?= t('bkhc_…') ?>" required></div>
        <label class="check"><input type="checkbox" name="pause_outbound" value="1"<?= checked(old('pause_outbound', '1')) ?>>
          <span><?= t('Pause mailboxes and webhooks on the copy until I switch them on') ?><br><span class="small muted"><?= t('Recommended, so the copy doesn\'t fetch the same email or send the same webhooks as the original while both are running.') ?></span></span></label>
      </fieldset>

      <fieldset data-show-when="setup_mode=osticket" data-disable-hidden><legend><?= t('Import from osTicket') ?></legend>
        <div class="field" style="max-width:420px"><label for="ost_mode"><?= t('Where is osTicket?') ?></label>
          <select id="ost_mode" name="ost_mode">
            <option value="path"<?= selected('path', old('ost_mode', 'path')) ?>><?= t('On this server: I\'ll give its folder') ?></option>
            <option value="db"<?= selected('db', old('ost_mode')) ?>><?= t('Somewhere else: I\'ll enter its database details') ?></option>
          </select></div>
        <div class="field" data-show-when="ost_mode=path" data-disable-hidden><label class="req" for="ost_path"><?= t('osTicket folder on this server') ?></label>
          <input id="ost_path" type="text" name="ost_path" value="<?= e(old('ost_path')) ?>" placeholder="<?= t('/home/account/public_html/support') ?>" required>
          <div class="hint"><?= t('The folder that contains osTicket\'s <code>include/ost-config.php</code>.') ?></div></div>
        <div class="form-grid" data-show-when="ost_mode=db" data-disable-hidden>
          <div class="field"><label for="ost_db_host"><?= t('osTicket database host') ?></label><input id="ost_db_host" type="text" name="ost_db_host" value="<?= e(old('ost_db_host', 'localhost')) ?>"></div>
          <div class="field"><label for="ost_db_port"><?= t('Port') ?></label><input id="ost_db_port" type="number" name="ost_db_port" value="<?= e(old('ost_db_port', '3306')) ?>"></div>
          <div class="field"><label class="req" for="ost_db_name"><?= t('Database name') ?></label><input id="ost_db_name" type="text" name="ost_db_name" value="<?= e(old('ost_db_name')) ?>" required></div>
          <div class="field"><label for="ost_db_prefix"><?= t('Table prefix') ?></label><input id="ost_db_prefix" type="text" name="ost_db_prefix" value="<?= e(old('ost_db_prefix', 'ost_')) ?>"></div>
          <div class="field"><label for="ost_db_user"><?= t('Username') ?></label><input id="ost_db_user" type="text" name="ost_db_user" value="<?= e(old('ost_db_user')) ?>" autocomplete="off"></div>
          <div class="field"><label for="ost_db_pass"><?= t('Password') ?></label><input id="ost_db_pass" type="password" name="ost_db_pass" autocomplete="new-password"></div>
        </div>
        <details class="guide"><summary><?= t('Attachments stored on disk') ?></summary>
          <p class="small muted"><?= t('Only needed if osTicket\'s "Attachments on the Filesystem" plugin used a folder that has moved; otherwise it\'s found automatically.') ?></p>
          <div class="field"><label for="ost_files"><?= t('osTicket attachments folder') ?></label><input id="ost_files" type="text" name="ost_files" value="<?= e(old('ost_files')) ?>" placeholder="<?= t('Detected automatically') ?>"></div>
        </details>
        <label class="check"><input type="checkbox" name="ost_templates" value="1"<?= checked(old('ost_templates')) ?>> <span><?= t('Use osTicket\'s email templates instead of the standard ones') ?><br><span class="small muted"><?= t('Only if you customised them in osTicket.') ?></span></span></label>
        <p class="small muted"><?= t('Your help desk name, company and time zone are taken from osTicket. Later, run <strong>Admin → Import</strong> again to bring across anything that arrives in osTicket before you switch over.') ?></p>
      </fieldset>

      <fieldset><legend><?= t('Address') ?></legend>
        <div class="field"><label class="req" for="base_url"><?= t('Help desk URL') ?></label><input id="base_url" type="url" name="base_url" value="<?= e(old('base_url', $baseUrl)) ?>" required><div class="hint"><?= t('The public address of this install, e.g. https://help.bakkbone.au. When copying, links in the copied tickets are updated to this address.') ?></div></div>
        <label class="check"><input type="checkbox" name="pretty_urls" value="1"<?= checked(old('pretty_urls', !empty($prettyDefault) ? '1' : '')) ?>> <span><?= t('Pretty URLs (e.g. <code>/panel/tickets</code> instead of <code>/index.php/panel/tickets</code>).') ?> <?= !empty($prettyDefault) ? t('URL rewriting was detected on this server.') : t('URL rewriting was not detected, so this is off.') ?></span></label>
      </fieldset>

      <fieldset data-show-when="setup_mode=fresh" data-disable-hidden><legend><?= t('Help desk') ?></legend>
        <div class="form-grid">
          <div class="field"><label for="helpdesk_name"><?= t('Help desk name') ?></label><input id="helpdesk_name" type="text" name="helpdesk_name" value="<?= e(old('helpdesk_name', 'BAKKBONE Help Centre')) ?>"></div>
          <div class="field"><label for="company_name"><?= t('Company name') ?></label><input id="company_name" type="text" name="company_name" value="<?= e(old('company_name', 'BAKKBONE Australia')) ?>"></div>
          <div class="field"><label for="timezone"><?= t('Time zone') ?></label><select id="timezone" name="timezone"><?php foreach (DateTimeZone::listIdentifiers() as $tz): ?><option<?= selected($tz, old('timezone', 'Australia/Sydney')) ?>><?= e($tz) ?></option><?php endforeach; ?></select></div>
        </div>
      </fieldset>
      <fieldset><legend><?= t('Database') ?></legend>
        <div class="field" style="max-width:320px"><label for="driver"><?= t('Type') ?></label>
          <select id="driver" name="driver"><option value="mysql"<?= selected('mysql', old('driver', 'mysql')) ?>><?= t('MySQL / MariaDB (recommended)') ?></option><option value="sqlite"<?= selected('sqlite', old('driver')) ?>><?= t('SQLite (small installs / testing)') ?></option></select></div>
        <div class="form-grid" data-show-when="driver=mysql">
          <div class="field"><label for="db_host"><?= t('Host') ?></label><input id="db_host" type="text" name="db_host" value="<?= e(old('db_host', 'localhost')) ?>"></div>
          <div class="field"><label for="db_port"><?= t('Port') ?></label><input id="db_port" type="number" name="db_port" value="<?= e(old('db_port', '3306')) ?>"></div>
          <div class="field"><label for="db_name"><?= t('Database name') ?></label><input id="db_name" type="text" name="db_name" value="<?= e(old('db_name', 'helpcentre')) ?>"></div>
          <div class="field"><label for="db_user"><?= t('Username') ?></label><input id="db_user" type="text" name="db_user" value="<?= e(old('db_user')) ?>" autocomplete="off"></div>
          <div class="field"><label for="db_pass"><?= t('Password') ?></label><input id="db_pass" type="password" name="db_pass" autocomplete="new-password"></div>
        </div>
        <div class="field" data-show-when="driver=sqlite"><label for="sqlite_path"><?= t('SQLite file path') ?></label><input id="sqlite_path" type="text" name="sqlite_path" value="<?= e(old('sqlite_path', \Bakkbone\HelpCentre\Core\App::storage('helpcentre.sqlite'))) ?>"><div class="hint"><?= t('Must be outside the web root (the default storage/ folder is).') ?></div></div>
      </fieldset>
      <fieldset data-show-when="setup_mode=fresh|osticket" data-disable-hidden><legend><?= t('Administrator account') ?></legend>
        <p class="small muted" data-show-when="setup_mode=osticket"><?= t('Use the same email as your osTicket administrator account to keep its tickets and settings, with this new password.') ?></p>
        <div class="form-grid">
          <div class="field"><label class="req" for="first_name"><?= t('First name') ?></label><input id="first_name" type="text" name="first_name" value="<?= e(old('first_name')) ?>" required></div>
          <div class="field"><label for="last_name"><?= t('Last name') ?></label><input id="last_name" type="text" name="last_name" value="<?= e(old('last_name')) ?>"></div>
          <div class="field"><label class="req" for="email"><?= t('Email') ?></label><input id="email" type="email" name="email" value="<?= e(old('email')) ?>" required></div>
          <div class="field"><label class="req" for="username"><?= t('Username') ?></label><input id="username" type="text" name="username" value="<?= e(old('username', 'admin')) ?>" required></div>
          <div class="field"><label class="req" for="password"><?= t('Password') ?></label><input id="password" type="password" name="password" minlength="10" required autocomplete="new-password"></div>
          <div class="field"><label class="req" for="password_confirm"><?= t('Confirm password') ?></label><input id="password_confirm" type="password" name="password_confirm" required autocomplete="new-password"></div>
        </div>
      </fieldset>
      <div class="form-actions"><button class="btn btn-primary" type="submit"><?= t('Install') ?></button>
        <span class="small muted" data-show-when="setup_mode=clone"><?= t('When copying, the database you choose must be empty. You\'ll sign in with an agent account from the other help desk.') ?></span>
        <span class="small muted" data-show-when="setup_mode=osticket"><?= t('The import starts straight after setup, with a progress bar.') ?></span></div>
    </div>
  </form>
<?php endif; ?>
