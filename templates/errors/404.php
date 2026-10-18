<div class="card"><div class="card-body empty-state">
  <h1><?= t('Page not found') ?></h1>
  <p><?= t('The page you\'re looking for doesn\'t exist or has moved.') ?></p>
  <p><a class="btn btn-primary" href="<?= e(url(str_starts_with(\Bakkbone\HelpCentre\Core\Request::path(), '/panel') ? '/panel' : '/')) ?>"><?= t('Go home') ?></a></p>
</div></div>
