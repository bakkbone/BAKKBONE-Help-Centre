<?php use Bakkbone\HelpCentre\Services\Auth; use Bakkbone\HelpCentre\Services\Staff; ?>
<div class="page-head">
  <h1><?= t('Organisations') ?></h1>
  <div class="actions"><?php if (Staff::can(Auth::staff(), 'org.manage')): ?><a class="btn btn-primary" href="<?= e(url('/panel/orgs/new')) ?>"><?= t('Add organisation') ?></a><?php endif; ?></div>
</div>
<form class="toolbar" method="get" action="<?= e(url('/panel/orgs')) ?>">
  <input class="search" type="search" name="q" value="<?= e($q) ?>" placeholder="<?= t('Search name or domain') ?>" aria-label="<?= t('Search organisations') ?>">
  <button class="btn" type="submit"><?= t('Search') ?></button>
</form>
<div class="card card-flush"><div class="card-body">
<?php if (!$orgs): ?><div class="empty-state"><h3><?= t('No organisations yet') ?></h3><p><?= t('Group users by company. Users are added automatically when their email matches an organisation\'s domain.') ?></p></div><?php else: ?>
  <table class="table">
    <thead><tr><th><?= t('Name') ?></th><th><?= t('Email domains') ?></th><th><?= t('Users') ?></th><th><?= t('Ticket sharing') ?></th></tr></thead>
    <tbody>
    <?php foreach ($orgs as $o): ?>
      <tr data-href="<?= e(url('/panel/orgs/' . $o['id'])) ?>">
        <td class="primary"><a class="subject" href="<?= e(url('/panel/orgs/' . $o['id'])) ?>"><?= e($o['name']) ?></a></td>
        <td data-label="<?= t('Domains') ?>"><?= e($o['domains'] ?? '') ?></td>
        <td data-label="<?= t('Users') ?>"><?= (int)$o['user_count'] ?></td>
        <td data-label="<?= t('Sharing') ?>"><?= $o['sharing'] ? '<span class="badge badge-ok">' . t('Shared') . '</span>' : '<span class="faint">' . t('Private') . '</span>' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
</div></div>
