<?php /** @var string $greeting */ ?>
<div class="page-head"><h1><?= t('{greeting}!', ['greeting' => $greeting]) ?></h1></div>
<div class="card"><div class="card-body">
  <p><?= t('This page comes from the Hello World example add-on. Add-ons can add pages like this to the end-user portal.') ?></p>
  <p><a class="btn btn-primary" href="<?= e(url('/open')) ?>"><?= t('Open a ticket') ?></a></p>
</div></div>
