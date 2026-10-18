<?php
$a = $acc ?? [];
$v = fn(string $k, $d = '') => $a[$k] ?? $d;
$saved = fn(string $k) => !empty($a[$k]) ? __('•••••••• (saved - leave blank to keep)') : '';
?>
<p class="small"><a href="<?= e(url('/panel/admin/emails')) ?>"><?= t('← Mailboxes') ?></a></p>
<div class="page-head"><h1><?= e($acc ? $acc['email'] : __('Add mailbox')) ?></h1>
  <?php if ($acc): ?><div class="actions">
    <form method="post" action="<?= e(url('/panel/admin/emails/' . $acc['id'] . '/delete')) ?>" data-confirm="<?= t('Remove this mailbox?') ?>"><?= csrf_field() ?><button class="btn btn-sm btn-danger" type="submit"><?= t('Delete') ?></button></form>
  </div><?php endif; ?>
</div>

<?php if ($acc): ?>
<div class="card">
  <div class="card-head"><h2><?= t('Test & fetch') ?></h2><?php if ($acc['last_error']): ?><span class="badge badge-bad"><?= t('Last fetch failed') ?></span><?php endif; ?></div>
  <div class="card-body">
    <?php if ($acc['last_error']): ?><div class="alert alert-error small"><?= e($acc['last_error']) ?></div><?php endif; ?>
    <div class="btn-row">
      <?php if ($acc['inbound_protocol'] !== 'none'): ?>
        <form class="inline" method="post" action="<?= e(url('/panel/admin/emails/' . $acc['id'] . '/test-inbound')) ?>"><?= csrf_field() ?><button class="btn btn-sm" type="submit"><?= t('Test incoming connection') ?></button></form>
        <form class="inline" method="post" action="<?= e(url('/panel/admin/emails/' . $acc['id'] . '/fetch')) ?>"><?= csrf_field() ?><button class="btn btn-sm" type="submit"><?= t('Fetch now') ?></button></form>
      <?php endif; ?>
      <form class="inline-form" method="post" action="<?= e(url('/panel/admin/emails/' . $acc['id'] . '/test-outbound')) ?>" style="flex:1 1 320px"><?= csrf_field() ?>
        <input type="email" name="to" placeholder="<?= t('Send test to (default: you)') ?>"><button class="btn btn-sm" type="submit"><?= t('Send test email') ?></button></form>
    </div>
    <?php if ($acc['last_fetch_at']): ?><p class="small faint" style="margin-top:10px"><?= t('Last fetched {when}.', ['when' => time_ago($acc['last_fetch_at'])]) ?></p><?php endif; ?>
  </div>
</div>
<?php endif; ?>

<?= \Bakkbone\HelpCentre\Core\View::partial('admin/_ms365_guide', ['a' => $a]) ?>

<form class="card" method="post" action="<?= e(url($acc ? '/panel/admin/emails/' . $acc['id'] : '/panel/admin/emails/new')) ?>" autocomplete="off">
  <div class="card-body">
    <?= csrf_field() ?>
    <fieldset>
      <legend><?= t('Mailbox') ?></legend>
      <div class="form-grid">
        <div class="field"><label class="req" for="email"><?= t('Email address') ?></label><input id="email" type="email" name="email" value="<?= e($v('email')) ?>" required></div>
        <div class="field"><label for="name"><?= t('Internal name') ?></label><input id="name" type="text" name="name" value="<?= e($v('name')) ?>" placeholder="<?= t('e.g. Support') ?>"></div>
        <div class="field"><label for="from_name"><?= t('"From" name on outgoing email') ?></label><input id="from_name" type="text" name="from_name" value="<?= e($v('from_name')) ?>" placeholder="<?= e(setting('helpdesk_name')) ?>"></div>
        <div class="field"><label for="auth_type"><?= t('Authentication') ?></label>
          <select id="auth_type" name="auth_type">
            <option value="password"<?= selected('password', $v('auth_type', 'password')) ?>><?= t('Username & password (IMAP/POP/SMTP)') ?></option>
            <option value="oauth_ms"<?= selected('oauth_ms', $v('auth_type')) ?>><?= t('Microsoft 365 OAuth 2.0 (IMAP/POP/SMTP with XOAUTH2)') ?></option>
          </select>
          <div class="hint" data-show-when="auth_type=password"><?= t('Microsoft 365 mailboxes can\'t use a password. Use OAuth or the Graph API ({link}guide{/link})', ['link' => tag('<a href="#ms365">')]) ?>.</div>
          <div class="hint" data-show-when="auth_type=oauth_ms"><?= t('Fill in the Microsoft 365 app credentials below ({link}guide{/link})', ['link' => tag('<a href="#ms365">')]) ?>.</div></div>
      </div>
      <label class="check"><input type="checkbox" name="active" value="1"<?= checked($v('active', 1)) ?>> <?= t('Active') ?></label>
      <label class="check"><input type="checkbox" name="make_default" value="1"<?= checked($acc && (int)setting('default_email_account_id') === (int)$acc['id']) ?>> <?= t('Default outgoing mailbox') ?></label>
    </fieldset>

    <fieldset>
      <legend><?= t('Microsoft 365 app credentials') ?></legend>
      <p class="small muted"><?= t('Only needed for Microsoft 365 mailboxes (Graph API or OAuth). These come from steps 2–3 of the {link}Microsoft 365 setup guide{/link}.', ['link' => tag('<a href="#ms365">')]) ?></p>
      <div class="form-grid">
        <div class="field"><label for="ms_tenant"><?= t('Tenant (directory) ID') ?></label><input id="ms_tenant" type="text" name="ms_tenant" value="<?= e($v('ms_tenant')) ?>" placeholder="<?= t('xxxxxxxx-xxxx-… or contoso.onmicrosoft.com') ?>"></div>
        <div class="field"><label for="ms_client_id"><?= t('Application (client) ID') ?></label><input id="ms_client_id" type="text" name="ms_client_id" value="<?= e($v('ms_client_id')) ?>"></div>
        <div class="field span-2"><label for="ms_client_secret"><?= t('Client secret value') ?></label><input id="ms_client_secret" type="password" name="ms_client_secret" placeholder="<?= e($saved('ms_client_secret')) ?>" autocomplete="new-password"></div>
      </div>
    </fieldset>

    <fieldset>
      <legend><?= t('Incoming mail') ?></legend>
      <div class="form-grid">
        <div class="field"><label for="inbound_protocol"><?= t('Fetch method') ?></label>
          <select id="inbound_protocol" name="inbound_protocol">
            <option value="none"<?= selected('none', $v('inbound_protocol', 'none')) ?>><?= t('None (use piping, or outgoing only)') ?></option>
            <option value="imap"<?= selected('imap', $v('inbound_protocol')) ?>>IMAP</option>
            <option value="pop3"<?= selected('pop3', $v('inbound_protocol')) ?>>POP3</option>
            <option value="graph"<?= selected('graph', $v('inbound_protocol')) ?>><?= t('Microsoft 365 / Exchange Online (Graph API)') ?></option>
          </select></div>
        <div class="field" data-show-when="inbound_protocol=imap|pop3|graph"><label for="in_post_action"><?= t('After fetching a message') ?></label>
          <select id="in_post_action" name="in_post_action">
            <option value="seen"<?= selected('seen', $v('in_post_action', 'seen')) ?>><?= t('Mark as read (POP3: remember it)') ?></option>
            <option value="move"<?= selected('move', $v('in_post_action')) ?>><?= t('Move to archive folder (IMAP/Graph)') ?></option>
            <option value="delete"<?= selected('delete', $v('in_post_action')) ?>><?= t('Delete from server') ?></option>
          </select></div>
      </div>
      <div class="field" data-show-when="inbound_protocol=imap|pop3|graph" style="max-width:420px"><label for="in_username"><?= t('Username / mailbox') ?></label><input id="in_username" type="text" name="in_username" value="<?= e($v('in_username')) ?>" placeholder="<?= t('Defaults to the email address') ?>" autocomplete="off">
        <div class="hint"><?= t('For Microsoft 365, enter the mailbox address, e.g. support@yourdomain.com. Shared mailboxes work.') ?></div></div>
      <div class="form-grid" data-show-when="inbound_protocol=imap|pop3">
        <div class="field"><label for="in_host"><?= t('Server host') ?></label><input id="in_host" type="text" name="in_host" value="<?= e($v('in_host')) ?>" placeholder="<?= t('outlook.office365.com / imap.gmail.com') ?>"></div>
        <div class="field"><label for="in_port"><?= t('Port') ?></label><input id="in_port" type="number" name="in_port" value="<?= e($v('in_port')) ?>" placeholder="<?= t('993 (IMAP SSL) / 995 (POP3 SSL)') ?>"></div>
        <div class="field"><label for="in_encryption"><?= t('Encryption') ?></label>
          <select id="in_encryption" name="in_encryption"><option value="ssl"<?= selected('ssl', $v('in_encryption', 'ssl')) ?>><?= t('SSL/TLS (implicit)') ?></option><option value="tls"<?= selected('tls', $v('in_encryption')) ?>><?= t('STARTTLS') ?></option><option value="none"<?= selected('none', $v('in_encryption')) ?>><?= t('None (not recommended)') ?></option></select></div>
        <div class="field span-2" data-show-when="auth_type=password"><label for="in_password"><?= t('Password / app password') ?></label><input id="in_password" type="password" name="in_password" placeholder="<?= e($saved('in_password')) ?>" autocomplete="new-password"></div>
      </div>
      <div class="form-grid" data-show-when="inbound_protocol=imap|graph">
        <div class="field"><label for="in_folder"><?= t('Folder to fetch') ?></label><input id="in_folder" type="text" name="in_folder" value="<?= e($v('in_folder', 'INBOX')) ?>"></div>
        <div class="field"><label for="in_archive_folder"><?= t('Archive folder (for "move")') ?></label><input id="in_archive_folder" type="text" name="in_archive_folder" value="<?= e($v('in_archive_folder')) ?>" placeholder="<?= t('e.g. Processed') ?>"></div>
      </div>
    </fieldset>

    <fieldset>
      <legend><?= t('Outgoing mail') ?></legend>
      <div class="field" style="max-width:420px"><label for="outbound_protocol"><?= t('Send using') ?></label>
        <select id="outbound_protocol" name="outbound_protocol">
          <option value="smtp"<?= selected('smtp', $v('outbound_protocol', 'smtp')) ?>>SMTP</option>
          <option value="graph"<?= selected('graph', $v('outbound_protocol')) ?>><?= t('Microsoft 365 / Exchange Online (Graph API)') ?></option>
          <option value="mail"<?= selected('mail', $v('outbound_protocol')) ?>><?= t('PHP mail() (server\'s local mailer)') ?></option>
        </select></div>
      <div class="form-grid" data-show-when="outbound_protocol=smtp">
        <div class="field"><label for="smtp_host"><?= t('SMTP host') ?></label><input id="smtp_host" type="text" name="smtp_host" value="<?= e($v('smtp_host')) ?>" placeholder="<?= t('smtp.office365.com / smtp.gmail.com') ?>"></div>
        <div class="field"><label for="smtp_port"><?= t('Port') ?></label><input id="smtp_port" type="number" name="smtp_port" value="<?= e($v('smtp_port')) ?>" placeholder="<?= t('587 (STARTTLS) / 465 (SSL)') ?>"></div>
        <div class="field"><label for="smtp_encryption"><?= t('Encryption') ?></label>
          <select id="smtp_encryption" name="smtp_encryption"><option value="tls"<?= selected('tls', $v('smtp_encryption', 'tls')) ?>><?= t('STARTTLS') ?></option><option value="ssl"<?= selected('ssl', $v('smtp_encryption')) ?>><?= t('SSL/TLS (implicit)') ?></option><option value="none"<?= selected('none', $v('smtp_encryption')) ?>><?= t('None') ?></option></select></div>
        <div class="field"><label for="smtp_username"><?= t('SMTP username') ?></label><input id="smtp_username" type="text" name="smtp_username" value="<?= e($v('smtp_username')) ?>" placeholder="<?= t('Defaults to the email address') ?>" autocomplete="off"></div>
        <div class="field" data-show-when="auth_type=password"><label for="smtp_password"><?= t('SMTP password / app password') ?></label><input id="smtp_password" type="password" name="smtp_password" placeholder="<?= e($saved('smtp_password')) ?>" autocomplete="new-password"></div>
        <div class="field"><label class="check" style="margin-top:28px"><input type="checkbox" name="smtp_auth" value="1"<?= checked($v('smtp_auth', 1)) ?>> <?= t('SMTP requires authentication') ?></label></div>
      </div>
    </fieldset>

    <fieldset>
      <legend><?= t('New tickets from this mailbox') ?></legend>
      <div class="form-grid">
        <div class="field"><label for="dept_id"><?= t('Department') ?></label><select id="dept_id" name="dept_id"><option value=""><?= t('— Default —') ?></option><?php foreach ($depts as $d): ?><option value="<?= (int)$d['id'] ?>"<?= selected($d['id'], $v('dept_id')) ?>><?= e($d['name']) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label for="priority_id"><?= t('Priority') ?></label><select id="priority_id" name="priority_id"><option value=""><?= t('— Default —') ?></option><?php foreach ($priorities as $p): ?><option value="<?= (int)$p['id'] ?>"<?= selected($p['id'], $v('priority_id')) ?>><?= e($p['name']) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label for="topic_id"><?= t('Help topic') ?></label><select id="topic_id" name="topic_id"><option value=""><?= t('— None —') ?></option><?php foreach ($topics as $tp): ?><option value="<?= (int)$tp['id'] ?>"<?= selected($tp['id'], $v('topic_id')) ?>><?= e($tp['name']) ?></option><?php endforeach; ?></select></div>
      </div>
    </fieldset>
    <div class="form-actions"><button class="btn btn-primary" type="submit"><?= t('Save mailbox') ?></button></div>
  </div>
</form>
