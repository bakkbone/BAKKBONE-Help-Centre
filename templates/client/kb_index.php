<div class="page-head"><h1><?= t('Knowledge base') ?></h1></div>
<form class="toolbar" method="get" action="<?= e(url('/kb')) ?>" role="search">
  <input class="search" type="search" name="q" value="<?= e($q) ?>" placeholder="<?= t('Search articles…') ?>" aria-label="<?= t('Search articles') ?>">
  <button class="btn btn-primary" type="submit"><?= t('Search') ?></button>
</form>
<?php if ($results !== null): ?>
  <div class="card">
    <div class="card-head"><h2><?= tn('{n} result for "{query}"', '{n} results for "{query}"', count($results), ['query' => $q]) ?></h2><a class="small" href="<?= e(url('/kb')) ?>"><?= t('Clear') ?></a></div>
    <div class="card-body">
      <?php if (!$results): ?>
        <p class="muted"><?= t('Nothing matched. {link}Open a ticket{/link} and we\'ll help.', ['link' => tag('<a href="' . e(url('/open')) . '">')]) ?></p>
      <?php else: ?>
        <ul class="list-plain">
          <?php foreach ($results as $r): ?>
            <li><a href="<?= e(url('/kb/' . $r['id'])) ?>"><strong><?= e($r['question']) ?></strong></a><br><span class="small muted"><?= e($r['category']) ?> · <?= e(\Bakkbone\HelpCentre\Core\Html::excerpt($r['answer'], 140)) ?></span></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
  </div>
<?php else: ?>
  <div class="grid grid-3">
    <?php foreach ($cats as $c): ?>
      <a class="card tile" href="<?= e(url('/kb/category/' . $c['id'])) ?>">
        <h3><?= e($c['name']) ?></h3>
        <p><?= $c['description'] !== '' && $c['description'] !== null ? e($c['description']) : tn('{n} article', '{n} articles', (int)$c['n']) ?></p>
      </a>
    <?php endforeach; ?>
    <?php if (!$cats): ?><p class="muted"><?= t('No articles have been published yet.') ?></p><?php endif; ?>
  </div>
<?php endif; ?>
