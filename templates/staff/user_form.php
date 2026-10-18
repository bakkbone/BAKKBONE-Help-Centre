<p class="small"><a href="<?= e(url('/panel/users')) ?>"><?= t('← Users') ?></a></p>
<div class="page-head"><h1><?= t('Add user') ?></h1></div>
<form class="card" method="post" action="<?= e(url('/panel/users/new')) ?>" style="max-width:640px">
  <div class="card-body">
    <?= csrf_field() ?>
    <div class="field"><label class="req" for="email"><?= t('Email') ?></label><input id="email" type="email" name="email" value="<?= e(old('email')) ?>" required></div>
    <div class="field"><label for="name"><?= t('Name') ?></label><input id="name" type="text" name="name" value="<?= e(old('name')) ?>"></div>
    <div class="field"><label for="phone"><?= t('Phone') ?></label><input id="phone" type="tel" name="phone" value="<?= e(old('phone')) ?>"></div>
    <div class="field"><label for="org_id"><?= t('Organisation') ?></label><select id="org_id" name="org_id"><option value=""><?= t('— Auto (by email domain) —') ?></option><?php foreach ($orgs as $o): ?><option value="<?= (int)$o['id'] ?>"><?= e($o['name']) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label for="notes"><?= t('Internal notes') ?></label><textarea id="notes" name="notes"><?= e(old('notes')) ?></textarea></div>
    <div class="form-actions"><button class="btn btn-primary" type="submit"><?= t('Create user') ?></button></div>
  </div>
</form>
