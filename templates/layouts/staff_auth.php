<?php use Bakkbone\HelpCentre\Core\View; ?>
<!doctype html>
<html<?= lang_attr() ?><?= theme_attr() ?>>
<head><?= View::partial('partials/head', ['title' => $title ?? '']) ?></head>
<body class="staff-ui">
<div class="corner-actions"><?= View::partial('partials/theme_toggle') ?></div>
<main>
  <div class="container container-tight auth-shell">
    <div class="auth-brand"><?= View::partial('partials/brand', ['href' => '/panel', 'suffix' => __('Agent')]) ?></div>
    <?= View::partial('partials/flashes') ?>
    <div class="card"><div class="card-body"><?= $content ?></div></div>
    <p class="center small muted mt"><a href="<?= e(url('/')) ?>"><?= t('← End-user portal') ?></a></p>
  </div>
</main>
<script src="<?= e(asset('js/app.js')) ?>" defer></script>
</body>
</html>
