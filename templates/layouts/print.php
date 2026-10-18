<!doctype html>
<html<?= lang_attr() ?> data-theme="light">
<head><?= \Bakkbone\HelpCentre\Core\View::partial('partials/head', ['title' => $title ?? '']) ?></head>
<body style="background:#fff">
<main><div class="container container-narrow"><?= $content ?></div></main>
<script src="<?= e(asset('js/app.js')) ?>" defer></script>
</body>
</html>
