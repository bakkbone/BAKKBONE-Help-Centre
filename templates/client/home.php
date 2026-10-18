<?php
use Bakkbone\HelpCentre\Core\View;
use Bakkbone\HelpCentre\Services\Auth;

$kb = setting('kb_enabled') === '1';
$signedIn = (bool)Auth::user();
ob_start(); ?>
<section class="hero">
  <div class="container">
    <div class="hero-card">
      <div class="landing"><?= setting('landing_html') ?></div>
      <?php if ($kb): ?>
      <form class="searchbox" action="<?= e(url('/kb')) ?>" method="get" role="search">
        <?= icon('search') ?>
        <label class="sr-only" for="kbq"><?= t('Search the knowledge base') ?></label>
        <input id="kbq" type="search" name="q" placeholder="<?= t('Search for answers…') ?>">
        <button class="btn btn-primary" type="submit"><?= t('Search') ?></button>
      </form>
      <?php endif; ?>
    </div>
  </div>
</section>
<?php View::$shared['hero'] = ob_get_clean(); ?>
<div class="action-grid">
  <a class="action-card" href="<?= e(url('/open')) ?>">
    <span class="action-ico"><?= icon('edit_square') ?></span>
    <span class="action-text"><?= t('<strong>Start a new ticket</strong>') ?><span><?= t('Tell us what you need and we\'ll get back to you by email.') ?></span></span>
    <?= icon('chevron_right', 'action-go') ?>
  </a>
  <a class="action-card" href="<?= e(url($signedIn ? '/tickets' : '/status')) ?>">
    <span class="action-ico"><?= icon($signedIn ? 'inbox' : 'manage_search') ?></span>
    <span class="action-text"><strong><?= $signedIn ? t('My tickets') : t('Track a ticket') ?></strong><span><?= t('See replies and updates on your existing requests.') ?></span></span>
    <?= icon('chevron_right', 'action-go') ?>
  </a>
  <?php if ($kb): ?>
  <a class="action-card" href="<?= e(url('/kb')) ?>">
    <span class="action-ico"><?= icon('menu_book') ?></span>
    <span class="action-text"><?= t('<strong>Help articles</strong>') ?><span><?= t('Browse guides and answers to common questions.') ?></span></span>
    <?= icon('chevron_right', 'action-go') ?>
  </a>
  <?php elseif (!$signedIn): ?>
  <a class="action-card" href="<?= e(url('/login')) ?>">
    <span class="action-ico"><?= icon('login') ?></span>
    <span class="action-text"><?= t('<strong>Sign in</strong>') ?><span><?= t('Use your email address and we\'ll send you a one-time code.') ?></span></span>
    <?= icon('chevron_right', 'action-go') ?>
  </a>
  <?php endif; ?>
</div>
<?php if ($featured): ?>
<section class="mt-lg">
  <div class="section-head"><h2><?= t('Popular articles') ?></h2><a class="btn btn-ghost btn-sm" href="<?= e(url('/kb')) ?>"><?= t('Browse all') ?> <?= icon('arrow_forward') ?></a></div>
  <div class="card card-list">
    <?php foreach ($featured as $a): ?>
      <a class="list-item" href="<?= e(url('/kb/' . $a['id'])) ?>"><?= icon('help') ?><span><?= e($a['question']) ?></span><?= icon('chevron_right', 'list-go') ?></a>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>
