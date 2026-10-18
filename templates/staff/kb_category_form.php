<p class="small"><a href="<?= e(url('/panel/kb')) ?>"><?= t('← Knowledge base') ?></a></p>
<div class="page-head"><h1><?= e($title) ?></h1>
  <?php if ($cat): ?><div class="actions"><form method="post" action="<?= e(url('/panel/kb/categories/' . $cat['id'] . '/delete')) ?>" data-confirm="<?= t('Delete this category?') ?>"><?= csrf_field() ?><button class="btn btn-sm btn-danger" type="submit"><?= t('Delete') ?></button></form></div><?php endif; ?>
</div>
<form class="card" method="post" action="<?= e(url($cat ? '/panel/kb/categories/' . $cat['id'] : '/panel/kb/categories/new')) ?>" style="max-width:640px">
  <div class="card-body">
    <?= csrf_field() ?>
    <div class="field"><label class="req" for="name"><?= t('Name') ?></label><input id="name" type="text" name="name" value="<?= e($cat['name'] ?? '') ?>" required></div>
    <div class="field"><label for="description"><?= t('Description') ?></label><textarea id="description" name="description" style="min-height:80px"><?= e($cat['description'] ?? '') ?></textarea></div>
    <div class="field" style="max-width:160px"><label for="sort"><?= t('Sort order') ?></label><input id="sort" type="number" name="sort" value="<?= (int)($cat['sort'] ?? 0) ?>"></div>
    <label class="check"><input type="checkbox" name="public" value="1"<?= checked($cat['public'] ?? 1) ?>> <?= t('Public (visible in the end-user portal)') ?></label>
    <div class="form-actions"><button class="btn btn-primary" type="submit"><?= t('Save') ?></button></div>
  </div>
</form>
