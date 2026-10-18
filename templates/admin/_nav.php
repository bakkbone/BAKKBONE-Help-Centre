<?php
$groups = [
    __('Settings') => [
        'settings/general' => __('General'), 'settings/tickets' => __('Tickets'), 'settings/email' => __('Email & alerts'),
        'settings/auth' => __('Portals & sign-in'), 'settings/attachments' => __('Attachments'), 'settings/pages' => __('Portal pages'),
    ],
    __('Email') => ['emails' => __('Mailboxes'), 'templates' => __('Email templates'), 'mailqueue' => __('Outgoing queue')],
    __('Tickets') => [
        'departments' => __('Departments'), 'topics' => __('Help topics'), 'fields' => __('Custom fields'), 'filters' => __('Filters'),
        'sla' => __('SLA plans'), 'statuses' => __('Statuses'), 'priorities' => __('Priorities'), 'banlist' => __('Ban list'),
    ],
    __('Agents') => ['staff' => __('Agents'), 'teams' => __('Teams'), 'roles' => __('Roles')],
    __('Integrations') => ['webhooks' => __('Webhooks'), 'apikeys' => __('API keys'), 'addons' => __('Add-ons')],
    __('System') => ['system' => __('System & cron'), 'import' => __('Import'), 'logs' => __('System log'), 'audit' => __('Audit log')],
];
$path = \Bakkbone\HelpCentre\Core\Request::path();
?>
<nav class="admin-nav" aria-label="<?= t('Administration') ?>">
<?php foreach ($groups as $label => $links): ?>
  <h4><?= e($label) ?></h4>
  <?php foreach ($links as $slug => $text):
      $href = '/panel/admin/' . $slug;
      $isActive = $path === $href || str_starts_with($path, $href . '/') || ($section === $slug); ?>
    <a href="<?= e(url($href)) ?>" class="<?= $isActive ? 'active' : '' ?>"><?= e($text) ?></a>
  <?php endforeach; ?>
<?php endforeach; ?>
</nav>
