<p class="small"><a href="<?= e(url('/panel/admin/templates')) ?>"><?= t('← Email templates') ?></a></p>
<div class="page-head"><h1><?= e($meta['name']) ?></h1>
  <div class="actions"><form method="post" action="<?= e(url('/panel/admin/templates/' . $code . '/reset')) ?>" data-confirm="<?= t('Restore the default template? Your changes will be lost.') ?>"><?= csrf_field() ?><button class="btn btn-sm" type="submit"><?= t('Restore default') ?></button></form></div>
</div>
<div class="ticket-layout">
  <form class="card" method="post" action="<?= e(url('/panel/admin/templates/' . $code)) ?>">
    <div class="card-body">
      <?= csrf_field() ?>
      <div class="field"><label for="subject"><?= t('Subject') ?></label><input id="subject" type="text" name="subject" value="<?= e($tpl['subject']) ?>"></div>
      <div class="field"><label for="tbody"><?= t('Body (HTML)') ?></label><textarea id="tbody" class="code" name="body" spellcheck="false"><?= e($tpl['body']) ?></textarea>
        <div class="hint"><?= t('Raw HTML. Inline styles work best in email clients.') ?></div></div>
      <div class="field"><label><?= t('Preview') ?> <span class="faint"><?= t('(merge tags shown as-is)') ?></span></label><div class="preview-box" data-preview-source="tbody"></div></div>
      <div class="form-actions"><button class="btn btn-primary" type="submit"><?= t('Save template') ?></button></div>
    </div>
  </form>
  <aside class="side">
    <div class="card"><div class="card-head"><h3><?= t('Merge tags') ?></h3></div>
      <div class="card-body small" data-insert-target="tbody">
        <p class="muted"><?= t('Click to insert at the cursor.') ?></p>
        <?php foreach ($vars as $group => $list): ?>
          <p><strong><?= e(__($group)) ?></strong><br><?php foreach ($list as $v): ?><code>%{<?= e($v) ?>}</code> <?php endforeach; ?></p>
        <?php endforeach; ?>
        <p class="muted"><?= t('<code>%{message}</code>, <code>%{response}</code>, <code>%{note}</code> and <code>%{signature}</code> insert HTML; everything else is escaped.') ?></p>
      </div>
    </div>
  </aside>
</div>
