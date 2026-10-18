<p class="small"><a href="<?= e(url('/kb')) ?>"><?= t('← Knowledge base') ?></a></p>
<div class="page-head"><h1><?= e($cat['name']) ?></h1></div>
<?php if ($cat['description']): ?><p class="muted"><?= e($cat['description']) ?></p><?php endif; ?>
<div class="card"><div class="card-body">
  <?php if (!$articles): ?><p class="muted"><?= t('No articles in this category yet.') ?></p><?php endif; ?>
  <ul class="list-plain">
    <?php foreach ($articles as $a): ?>
      <li><a href="<?= e(url('/kb/' . $a['id'])) ?>"><strong><?= e($a['question']) ?></strong></a><br><span class="small muted"><?= e(\Bakkbone\HelpCentre\Core\Html::excerpt($a['answer'], 160)) ?></span></li>
    <?php endforeach; ?>
  </ul>
</div></div>
