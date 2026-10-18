<p class="small"><a href="<?= e(url('/kb')) ?>"><?= t('Knowledge base') ?></a> / <a href="<?= e(url('/kb/category/' . $a['category_id'])) ?>"><?= e($a['category']) ?></a></p>
<div class="ticket-layout">
  <article class="card">
    <div class="card-body article-body">
      <h1><?= e($a['question']) ?></h1>
      <p class="small faint"><?= t('Updated {when}', ['when' => fmt_date($a['updated_at'])]) ?></p>
      <?= $a['answer'] ?>
    </div>
  </article>
  <aside>
    <div class="card">
      <div class="card-head"><h3><?= t('Still need help?') ?></h3></div>
      <div class="card-body"><p class="small muted"><?= t('Our team is happy to help.') ?></p><a class="btn btn-primary btn-block" href="<?= e(url('/open')) ?>"><?= t('Open a ticket') ?></a></div>
    </div>
    <?php if ($related): ?>
    <div class="card">
      <div class="card-head"><h3><?= t('Related articles') ?></h3></div>
      <div class="card-body"><ul class="list-plain"><?php foreach ($related as $r): ?><li><a href="<?= e(url('/kb/' . $r['id'])) ?>"><?= e($r['question']) ?></a></li><?php endforeach; ?></ul></div>
    </div>
    <?php endif; ?>
  </aside>
</div>
