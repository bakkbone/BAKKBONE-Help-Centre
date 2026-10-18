<?php $o = $o ?? null; $action = $o ? '/panel/orgs/' . $o['id'] : '/panel/orgs/new'; ?>
<?php if (empty($embedded)): ?>
<p class="small"><a href="<?= e(url('/panel/orgs')) ?>"><?= t('← Organisations') ?></a></p>
<div class="page-head"><h1><?= t('Add organisation') ?></h1></div>
<?php endif; ?>
<form class="card" method="post" action="<?= e(url($action)) ?>" <?= empty($embedded) ? 'style="max-width:700px"' : '' ?>>
  <?php if (!empty($embedded)): ?><div class="card-head"><h3><?= t('Details') ?></h3></div><?php endif; ?>
  <div class="card-body">
    <?= csrf_field() ?>
    <fieldset <?= !empty($readonly) ? 'disabled' : '' ?> style="border:0;padding:0;margin:0">
    <div class="field"><label class="req" for="name"><?= t('Name') ?></label><input id="name" type="text" name="name" value="<?= e($o['name'] ?? old('name')) ?>" required></div>
    <div class="field"><label for="domains"><?= t('Email domains') ?></label><input id="domains" type="text" name="domains" value="<?= e($o['domains'] ?? old('domains')) ?>" placeholder="<?= t('example.com, example.com.au') ?>">
      <div class="hint"><?= t('New users with these email domains are added to this organisation automatically.') ?></div></div>
    <label class="check"><input type="checkbox" name="link_existing" value="1"> <?= t('Also link existing users with these domains') ?></label>
    <label class="check"><input type="checkbox" name="sharing" value="1"<?= checked($o['sharing'] ?? 0) ?>> <?= t('Ticket sharing: members can see each other\'s tickets in the portal') ?></label>
    <div class="field"><label for="phone"><?= t('Phone') ?></label><input id="phone" type="tel" name="phone" value="<?= e($o['phone'] ?? '') ?>"></div>
    <div class="field"><label for="website"><?= t('Website') ?></label><input id="website" type="url" name="website" value="<?= e($o['website'] ?? '') ?>"></div>
    <div class="field"><label for="notes"><?= t('Internal notes') ?></label><textarea id="notes" name="notes" style="min-height:80px"><?= e($o['notes'] ?? '') ?></textarea></div>
    <?php if (empty($readonly)): ?><div class="form-actions"><button class="btn btn-primary" type="submit"><?= t('Save') ?></button></div><?php endif; ?>
    </fieldset>
  </div>
</form>
