<?php
use Bakkbone\HelpCentre\Core\Request;
use Bakkbone\HelpCentre\Services\Auth;

$cu = Auth::user();
$guest = Auth::guest();
$p = Request::path();
$active = fn(string $prefix) => ($prefix === '/' ? $p === '/' : str_starts_with($p, $prefix)) ? ' class="active"' : '';
?>
<!doctype html>
<html<?= lang_attr() ?><?= theme_attr() ?>>
<head><?= \Bakkbone\HelpCentre\Core\View::partial('partials/head', ['title' => $title ?? '']) ?></head>
<body class="client-ui">
<header class="topbar">
  <div class="container">
    <?= \Bakkbone\HelpCentre\Core\View::partial('partials/brand', ['href' => '/']) ?>
    <?= \Bakkbone\HelpCentre\Core\View::partial('partials/theme_toggle') ?>
    <input type="checkbox" id="nav-toggle" class="nav-toggle-box">
    <?= \Bakkbone\HelpCentre\Core\View::partial('partials/menu_icon') ?>
    <nav class="nav" aria-label="<?= t('Main') ?>">
      <a href="<?= e(url('/')) ?>"<?= $active('/') ?>><?= t('Home') ?></a>
      <?php if (setting('kb_enabled') === '1'): ?><a href="<?= e(url('/kb')) ?>"<?= $active('/kb') ?>><?= t('Knowledge base') ?></a><?php endif; ?>
      <a href="<?= e(url('/open')) ?>"<?= $active('/open') ?>><?= t('New ticket') ?></a>
      <?php if ($cu): ?>
        <a href="<?= e(url('/tickets')) ?>"<?= $active('/tickets') ?>><?= t('My tickets') ?></a>
      <?php else: ?>
        <a href="<?= e(url('/status')) ?>"<?= $active('/status') ?>><?= t('Track a ticket') ?></a>
      <?php endif; ?>
      <?php foreach (\Bakkbone\HelpCentre\Core\Addons::portalNavItems((bool)$cu) as $ni): ?>
        <a href="<?= e(url($ni['href'])) ?>"<?= $active($ni['href']) ?>><?= e($ni['label']) ?></a>
      <?php endforeach; ?>
      <span class="spacer"></span>
      <?php if ($cu): ?>
        <a href="<?= e(url('/profile')) ?>"<?= $active('/profile') ?>><span class="avatar"><?= e(initials($cu['name'])) ?></span><?= e($cu['name']) ?></a>
        <a href="<?= e(url('/logout')) ?>"><?= t('Sign out') ?></a>
      <?php else: ?>
        <a href="<?= e(url('/login')) ?>"<?= $active('/login') ?>><?= t('Sign in') ?></a>
        <?php if (setting('client_registration') === 'public'): ?><a href="<?= e(url('/register')) ?>"<?= $active('/register') ?>><?= t('Create account') ?></a><?php endif; ?>
      <?php endif; ?>
    </nav>
  </div>
</header>
<?php if (!empty($hero)): ?><?= $hero ?><?php endif; ?>
<main<?= !empty($hero) ? ' class="has-hero"' : '' ?>>
  <div class="container<?= !empty($narrow) ? ' container-narrow' : '' ?>">
    <?= \Bakkbone\HelpCentre\Core\View::partial('partials/flashes') ?>
    <?= $content ?>
  </div>
</main>
<footer class="footer">
  <div class="container">
    <span>&copy; <?= date('Y') ?> <?= e(setting('company_name')) ?><?= setting('footer_text') ? ' · ' . e(setting('footer_text')) : '' ?></span>
    <span><a href="<?= e(url('/panel')) ?>"><?= t('Agent sign in') ?></a></span>
  </div>
</footer>
<script src="<?= e(asset('js/app.js')) ?>" defer></script>
<?php if (is_file(HC_ROOT . '/custom/custom.js')): ?><script src="<?= e(url('/custom/custom.js')) ?>?v=<?= (int)filemtime(HC_ROOT . '/custom/custom.js') ?>" defer></script><?php endif; ?>
<?= \Bakkbone\HelpCentre\Core\Hooks::render('portal.body_end') ?>
</body>
</html>
