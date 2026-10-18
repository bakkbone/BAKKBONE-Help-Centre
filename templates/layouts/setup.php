<!doctype html>
<html<?= lang_attr() ?><?= theme_attr() ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title ?? __('Install')) ?> · BAKKBONE Help Centre</title>
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<?php if ($hcI18n = \Bakkbone\HelpCentre\Core\I18n::jsStrings()): ?><script type="application/json" id="hc-i18n"><?= json_encode($hcI18n, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?></script><?php endif; ?>
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body class="staff-ui">
<div class="corner-actions"><?= \Bakkbone\HelpCentre\Core\View::partial('partials/theme_toggle') ?></div>
<main>
  <div class="container container-narrow">
    <div class="page-head"><div class="brand"><span class="brand-mark">BB</span><span><?= t('BAKKBONE Help Centre · Setup') ?></span></div></div>
    <?= \Bakkbone\HelpCentre\Core\View::partial('partials/flashes') ?>
    <?= $content ?>
  </div>
</main>
<script src="<?= e(asset('js/app.js')) ?>" defer></script>
</body>
</html>
