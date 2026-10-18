<?php
// The data-bind-from spans are filled live from the form fields by app.js.
/** @var array $a */
$mbx = (string)(($a['in_username'] ?? '') ?: ($a['email'] ?? ''));
$bind = fn(string $field, string $fallback, string $current = '') => '<span class="bind" data-bind-from="' . e($field) . '" data-fallback="' . e($fallback) . '">' . e($current !== '' ? $current : $fallback) . '</span>';
$mailbox = $bind('email', 'support@yourdomain.com', $mbx);
$appId = $bind('ms_client_id', '<application-client-id>', (string)($a['ms_client_id'] ?? ''));
$isMs = ($a['auth_type'] ?? '') === 'oauth_ms' || ($a['inbound_protocol'] ?? '') === 'graph' || ($a['outbound_protocol'] ?? '') === 'graph';
?>
<section class="card ms-guide" id="ms365">
  <div class="card-head">
    <h2 class="with-icon"><span class="ms-logo" aria-hidden="true"><i></i><i></i><i></i><i></i></span><?= t('Using a Microsoft 365 mailbox?') ?></h2>
  </div>
  <div class="card-body">
    <p class="muted"><?= t('Microsoft 365 (Exchange Online) no longer accepts plain passwords for IMAP and POP, so a Microsoft mailbox needs a free <strong>app registration</strong>, set up once by a Microsoft 365 administrator. It takes about 10 minutes. Shared mailboxes work too and don\'t need a licence. Personal Outlook.com or Hotmail addresses can\'t be connected this way; use a business mailbox.') ?></p>
    <div class="btn-row">
      <button class="btn btn-tonal btn-sm" type="button" data-ms-preset="graph"><?= icon('bolt') ?><?= t('Fill in for Graph API (recommended)') ?></button>
      <button class="btn btn-sm" type="button" data-ms-preset="imap"><?= t('Fill in for IMAP + SMTP (OAuth)') ?></button>
    </div>
    <p class="small faint mt"><?= t('These buttons only set the server settings below; you still need the IDs and secret from steps 1–3.') ?></p>

    <details class="guide"<?= $isMs ? ' open' : '' ?>>
      <summary><?= t('Step-by-step setup guide') ?></summary>

      <div data-tabs>
      <div class="tabs" role="tablist">
        <button type="button" data-tab="ms-graph" class="active" role="tab"><?= t('Graph API (recommended)') ?></button>
        <button type="button" data-tab="ms-imap" role="tab"><?= t('IMAP / POP + SMTP') ?></button>
      </div>

      <div data-pane="ms-graph">
        <p class="small muted"><?= t('Best choice for most people: fetching and sending both use HTTPS, so no mail ports need to be open on your server.') ?></p>
        <ol class="steps">
          <li><?= t('<strong>Register the app.</strong> Sign in to the {link}Microsoft Entra admin centre{/link} → <em>Identity</em> → <em>Applications</em> → <em>App registrations</em> → <em>New registration</em>. Name it something like "Help Centre", choose <em>Accounts in this organizational directory only</em>, leave the redirect URI empty and click <em>Register</em>.', ['link' => tag('<a href="https://entra.microsoft.com" target="_blank" rel="noopener">')]) ?></li>
          <li><?= t('<strong>Copy the IDs.</strong> On the app\'s <em>Overview</em> page, copy the <em>Application (client) ID</em> and the <em>Directory (tenant) ID</em> into the {link}Microsoft 365 app credentials{/link} fields below.', ['link' => tag('<a href="#ms_tenant">')]) ?></li>
          <li><?= t('<strong>Create a secret.</strong> Open <em>Certificates & secrets</em> → <em>Client secrets</em> → <em>New client secret</em>. Copy the <strong>Value</strong> (not the Secret ID) straight away into <em>Client secret value</em>; Microsoft only shows it once. Put a reminder in your calendar for its expiry date, because fetching stops when it expires.') ?></li>
          <li><?= t('<strong>Grant permissions.</strong> Open <em>API permissions</em> → <em>Add a permission</em> → <em>Microsoft Graph</em> → <em>Application permissions</em>. Tick <code>Mail.ReadWrite</code> and <code>Mail.Send</code>, click <em>Add permissions</em>, then click <em>Grant admin consent</em>. Both should show a green tick.') ?></li>
          <li><?= t('<strong>Lock the app to this mailbox (strongly recommended).</strong> Without this step the app can read every mailbox in your organisation. In {link}Exchange Online PowerShell{/link}, run:', ['link' => tag('<a href="https://learn.microsoft.com/powershell/exchange/connect-to-exchange-online-powershell" target="_blank" rel="noopener">')]) ?>

            <pre class="cmd" id="ms-cmd-policy">New-ApplicationAccessPolicy -AppId <?= $appId ?> -PolicyScopeGroupId <?= $mailbox ?> -AccessRight RestrictAccess -Description "Help Centre mailbox only"</pre>
            <button class="btn btn-ghost btn-sm" type="button" data-copy="ms-cmd-policy"><?= t('Copy command') ?></button>
            <span class="small faint"><?= t('Check it with {command}. Changes can take up to an hour to apply.', ['command' => raw('<code>Test-ApplicationAccessPolicy -Identity ' . $mailbox . ' -AppId ' . $appId . '</code>')]) ?></span></li>
          <li><?= t('<strong>Fill in this form.</strong> Click <em>Fill in for Graph API</em> above, or set <em>Fetch method</em> and <em>Send using</em> to <em>Microsoft 365 / Exchange Online (Graph API)</em>. Put the mailbox address (e.g. {mailbox}) in <em>Username / mailbox</em>, save, then use <em>Test incoming connection</em> and <em>Send test email</em>.', ['mailbox' => raw($mailbox)]) ?></li>
        </ol>
      </div>

      <div data-pane="ms-imap" class="hidden">
        <p class="small muted"><?= t('Use this if you prefer IMAP or POP3. The app signs in with OAuth (XOAUTH2) instead of a password. Your server must be able to reach Microsoft on ports 993/995 and 587.') ?></p>
        <ol class="steps">
          <li><?= t('<strong>Register the app, copy the IDs and create a secret</strong>, as in steps 1–3 of the Graph guide.') ?></li>
          <li><?= t('<strong>Grant permissions.</strong> <em>API permissions</em> → <em>Add a permission</em> → <em>APIs my organization uses</em> → search for <em>Office 365 Exchange Online</em> → <em>Application permissions</em>. Tick <code>IMAP.AccessAsApp</code> (or <code>POP.AccessAsApp</code>) and <code>SMTP.SendAsApp</code>, add them, then click <em>Grant admin consent</em>.') ?></li>
          <li><?= t('<strong>Find the right Object ID.</strong> Go to <em>Enterprise applications</em> (not App registrations), open your app and copy its <em>Object ID</em>. Using the Object ID from App registrations is the most common reason this fails.') ?></li>
          <li><?= t('<strong>Give the app access to the mailbox</strong> in Exchange Online PowerShell:') ?>

            <pre class="cmd" id="ms-cmd-sp">New-ServicePrincipal -AppId <?= $appId ?> -ObjectId &lt;enterprise-application-object-id&gt;
Add-MailboxPermission -Identity <?= $mailbox ?> -User &lt;enterprise-application-object-id&gt; -AccessRights FullAccess
Set-CASMailbox -Identity <?= $mailbox ?> -SmtpClientAuthenticationDisabled $false</pre>
            <button class="btn btn-ghost btn-sm" type="button" data-copy="ms-cmd-sp"><?= t('Copy commands') ?></button>
            <span class="small faint"><?= t('The last line turns on SMTP sending for this mailbox. Some organisations turn it off by default.') ?></span></li>
          <li><?= t('<strong>Fill in this form.</strong> Click <em>Fill in for IMAP + SMTP (OAuth)</em> above. That sets <em>Authentication</em> to <em>Microsoft 365 OAuth 2.0</em>, incoming to <code>outlook.office365.com</code> port 993 (SSL), and outgoing to <code>smtp.office365.com</code> port 587 (STARTTLS). Use the mailbox address as both usernames, save, then test.') ?></li>
        </ol>
      </div>

      </div>

      <h3 class="small-head"><?= t('If something doesn\'t work') ?></h3>
      <ul class="small tips">
        <li><?= t('<strong>"AADSTS7000215: Invalid client secret"</strong>: you copied the Secret ID instead of the Value, or the secret has expired. Create a new one.') ?></li>
        <li><?= t('<strong>"401 / 403" or "Access is denied"</strong> right after setup: admin consent wasn\'t granted, or the access policy or mailbox permission hasn\'t applied yet. Wait up to an hour and test again.') ?></li>
        <li><?= t('<strong>IMAP says "AUTHENTICATE failed"</strong>: check you used the Enterprise application Object ID in <code>New-ServicePrincipal</code>, and that the username is the mailbox address.') ?></li>
        <li><?= t('<strong>A shared mailbox:</strong> enter the shared address as the mailbox or username. No password or licence is needed.') ?></li>
      </ul>
    </details>
  </div>
</section>
