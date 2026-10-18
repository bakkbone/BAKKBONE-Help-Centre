<p class="small"><a href="<?= e(url('/panel/kb')) ?>"><?= t('← Knowledge base') ?></a></p>
<div class="page-head"><h1><?= e($title) ?></h1>
  <?php if ($a): ?><div class="actions">
    <?php if ($a['public']): ?><a class="btn btn-sm" href="<?= e(url('/kb/' . $a['id'])) ?>" target="_blank" rel="noopener"><?= t('View in portal') ?></a><?php endif; ?>
    <form method="post" action="<?= e(url('/panel/kb/articles/' . $a['id'] . '/delete')) ?>" data-confirm="<?= t('Delete this article?') ?>"><?= csrf_field() ?><button class="btn btn-sm btn-danger" type="submit"><?= t('Delete') ?></button></form>
  </div><?php endif; ?>
</div>
<form class="card" method="post" action="<?= e(url($a ? '/panel/kb/articles/' . $a['id'] : '/panel/kb/articles/new')) ?>">
  <div class="card-body">
    <?= csrf_field() ?>
    <div class="form-grid">
      <div class="field span-2"><label class="req" for="question"><?= t('Title / question') ?></label><input id="question" type="text" name="question" value="<?= e($a['question'] ?? '') ?>" required></div>
      <div class="field"><label class="req" for="category_id"><?= t('Category') ?></label><select id="category_id" name="category_id" required><?php foreach ($cats as $c): ?><option value="<?= (int)$c['id'] ?>"<?= selected($c['id'], $a['category_id'] ?? $catId) ?>><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label for="keywords"><?= t('Search keywords') ?></label><input id="keywords" type="text" name="keywords" value="<?= e($a['keywords'] ?? '') ?>"></div>
    </div>
    <div class="field"><label for="answer"><?= t('Answer') ?></label><textarea id="answer" name="answer" data-editor style="min-height:300px"><?= e($a['answer'] ?? '') ?></textarea></div>
    <label class="check"><input type="checkbox" name="public" value="1"<?= checked($a['public'] ?? 1) ?>> <?= t('Public') ?></label>
    <div class="form-actions"><button class="btn btn-primary" type="submit"><?= t('Save article') ?></button></div>
  </div>
</form>
