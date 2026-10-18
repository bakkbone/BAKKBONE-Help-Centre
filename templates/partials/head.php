<?php $brand = valid_hex_color((string)setting('brand_color')); ?>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light dark">
<title><?= e(!empty($title) ? $title . ' · ' : '') ?><?= e(setting('helpdesk_name')) ?></title>
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<?php if ($hcI18n = \Bakkbone\HelpCentre\Core\I18n::jsStrings()): ?><script type="application/json" id="hc-i18n"><?= json_encode($hcI18n, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?></script><?php endif; ?>
<meta name="theme-color" content="<?= e($brand) ?>">
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
<style>:root{--brand:<?= e($brand) ?>;--brand-ink:<?= e(contrast_color($brand)) ?>}</style>
<?php if (is_file(HC_ROOT . '/custom/custom.css')): ?><link rel="stylesheet" href="<?= e(url('/custom/custom.css')) ?>?v=<?= (int)filemtime(HC_ROOT . '/custom/custom.css') ?>"><?php endif; ?>
<?= \Bakkbone\HelpCentre\Core\Hooks::render(str_starts_with(\Bakkbone\HelpCentre\Core\Request::path(), '/panel') ? 'panel.head' : 'portal.head') ?>
