<?php use Bakkbone\HelpCentre\Services\Templates; ?>
<p class="small"><a href="<?= e(url('/panel/canned')) ?>"><?= t('← Canned responses') ?></a></p>
<div class="page-head"><h1><?= e($title) ?></h1>
  <?php if ($c): ?><div class="actions"><form method="post" action="<?= e(url('/panel/canned/' . $c['id'] . '/delete')) ?>" data-confirm="<?= t('Delete this canned response?') ?>"><?= csrf_field() ?><button class="btn btn-sm btn-danger" type="submit"><?= t('Delete') ?></button></form></div><?php endif; ?>
</div>
<div class="ticket-layout">
  <form class="card" method="post" action="<?= e(url($c ? '/panel/canned/' . $c['id'] : '/panel/canned/new')) ?>">
    <div class="card-body">
      <?= csrf_field() ?>
      <div class="field"><label class="req" for="title"><?= t('Title') ?></label><input id="title" type="text" name="title" value="<?= e($c['title'] ?? '') ?>" required></div>
      <div class="field"><label for="dept_id"><?= t('Available in') ?></label><select id="dept_id" name="dept_id"><option value=""><?= t('All departments') ?></option><?php foreach ($depts as $d): ?><option value="<?= (int)$d['id'] ?>"<?= selected($d['id'], $c['dept_id'] ?? '') ?>><?= e($d['name']) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label for="body"><?= t('Response') ?></label><textarea id="body" name="body" data-editor style="min-height:240px"><?= e($c['body'] ?? '') ?></textarea></div>
      <label class="check"><input type="checkbox" name="active" value="1"<?= checked($c['active'] ?? 1) ?>> <?= t('Active') ?></label>
      <div class="form-actions"><button class="btn btn-primary" type="submit"><?= t('Save') ?></button></div>
    </div>
  </form>
  <aside class="side">
    <div class="card"><div class="card-head"><h3><?= t('Merge tags') ?></h3></div><div class="card-body small" data-insert-target="body">
      <p class="muted"><?= t('Click a tag to insert it. Tags are filled in when the response is inserted into a ticket.') ?></p>
      <?php foreach ([__('Ticket'), __('User (ticket owner)'), __('Agent (actor)'), __('Help desk')] as $g): ?>
        <p><strong><?= e($g) ?></strong><br><?php foreach (Templates::variables()[$g] as $v): if (str_contains($v, '<')) { continue; } ?><code>%{<?= e($v) ?>}</code> <?php endforeach; ?></p>
      <?php endforeach; ?>
    </div></div>
  </aside>
</div>
