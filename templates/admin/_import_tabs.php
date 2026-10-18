<?php /** @var string $tab */ ?>
<div class="page-head"><h1><?= t('Import') ?></h1></div>
<nav class="tabs" aria-label="<?= t('Import from') ?>">
  <a href="<?= e(url('/panel/admin/import')) ?>"<?= $tab === 'osticket' ? ' class="active" aria-current="page"' : '' ?>><?= t('From osTicket') ?></a>
  <a href="<?= e(url('/panel/admin/import/bhc')) ?>"<?= $tab === 'bhc' ? ' class="active" aria-current="page"' : '' ?>><?= t('From another BAKKBONE Help Centre') ?></a>
</nav>
