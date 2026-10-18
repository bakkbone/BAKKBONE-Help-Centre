<div class="page-head">
  <h1><?= t('Knowledge base') ?></h1>
  <?php if ($canManage): ?><div class="actions"><a class="btn" href="<?= e(url('/panel/kb/categories/new')) ?>"><?= t('New category') ?></a><a class="btn btn-primary" href="<?= e(url('/panel/kb/articles/new')) ?>"><?= t('New article') ?></a></div><?php endif; ?>
</div>
<?php if (!$cats): ?><div class="card"><div class="card-body empty-state"><h3><?= t('No categories yet') ?></h3><p><?= t('Create a category, then add articles to it.') ?></p></div></div><?php endif; ?>
<?php foreach ($cats as $c): ?>
<div class="card card-flush">
  <div class="card-head">
    <h2><?= e($c['name']) ?> <?= $c['public'] ? '' : '<span class="badge">' . t('Internal') . '</span>' ?></h2>
    <?php if ($canManage): ?><div class="btn-row"><a class="btn btn-sm" href="<?= e(url('/panel/kb/articles/new', ['category_id' => $c['id']])) ?>"><?= t('Add article') ?></a><a class="btn btn-sm btn-ghost" href="<?= e(url('/panel/kb/categories/' . $c['id'])) ?>"><?= t('Edit') ?></a></div><?php endif; ?>
  </div>
  <div class="card-body">
    <?php if (empty($byCat[(int)$c['id']])): ?><div class="empty-state small"><?= t('No articles.') ?></div><?php else: ?>
    <table class="table"><tbody>
      <?php foreach ($byCat[(int)$c['id']] as $a): ?>
        <tr data-href="<?= e(url($canManage ? '/panel/kb/articles/' . $a['id'] : '/kb/' . $a['id'])) ?>">
          <td class="primary"><a href="<?= e(url($canManage ? '/panel/kb/articles/' . $a['id'] : '/kb/' . $a['id'])) ?>"><?= e($a['question']) ?></a> <?= $a['public'] ? '' : '<span class="badge">' . t('Internal') . '</span>' ?></td>
          <td data-label="<?= t('Views') ?>" class="small muted nowrap"><?= tn('{n} view', '{n} views', (int)$a['views']) ?></td>
          <td data-label="<?= t('Updated') ?>" class="small muted nowrap"><?= e(fmt_date($a['updated_at'])) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody></table>
    <?php endif; ?>
  </div>
</div>
<?php endforeach; ?>
