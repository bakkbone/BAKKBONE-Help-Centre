<div class="page-head">
  <h1><?= t('Canned responses') ?></h1>
  <?php if ($canManage): ?><div class="actions"><a class="btn btn-primary" href="<?= e(url('/panel/canned/new')) ?>"><?= t('New canned response') ?></a></div><?php endif; ?>
</div>
<div class="card card-flush"><div class="card-body">
<?php if (!$rows): ?><div class="empty-state"><h3><?= t('No canned responses') ?></h3><p><?= t('Save replies you send often. They can include merge tags like %{user.first_name}.') ?></p></div><?php else: ?>
  <table class="table">
    <thead><tr><th><?= t('Title') ?></th><th><?= t('Department') ?></th><th><?= t('Preview') ?></th><th><?= t('Status') ?></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $c): ?>
      <tr <?= $canManage ? 'data-href="' . e(url('/panel/canned/' . $c['id'])) . '"' : '' ?>>
        <td class="primary"><?= $canManage ? '<a class="subject" href="' . e(url('/panel/canned/' . $c['id'])) . '">' . e($c['title']) . '</a>' : e($c['title']) ?></td>
        <td data-label="<?= t('Department') ?>"><?= e($c['dept_name'] ?? __('All departments')) ?></td>
        <td data-label="<?= t('Preview') ?>" class="small muted"><?= e(\Bakkbone\HelpCentre\Core\Html::excerpt((string)$c['body'], 90)) ?></td>
        <td data-label="<?= t('Status') ?>"><?= $c['active'] ? '<span class="badge badge-ok">' . t('Active') . '</span>' : '<span class="badge">' . t('Disabled') . '</span>' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
</div></div>
