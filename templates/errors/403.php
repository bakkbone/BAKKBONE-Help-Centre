<div class="card"><div class="card-body empty-state">
  <h1><?= t('Access denied') ?></h1>
  <p><?= e($message ?? __('You do not have permission to view this page.')) ?></p>
  <p><a class="btn" href="<?= e(url(str_starts_with(\Bakkbone\HelpCentre\Core\Request::path(), '/panel') ? '/panel' : '/')) ?>"><?= t('Go to the home page') ?></a></p>
</div></div>
