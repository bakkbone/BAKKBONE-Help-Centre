<?php /** @var array $ticket @var array $notes @var string $colour @var \Bakkbone\HelpCentre\Core\Addon $addon */ ?>
<div class="card hello-notes hello-<?= e($colour) ?>">
  <div class="card-head"><h3><?= t('Private notes') ?></h3><span class="small muted"><?= t('Hello World') ?></span></div>
  <div class="card-body">
    <?php foreach ($notes as $n): ?>
      <div class="hello-note">
        <p><?= nl2br(e($n['body'])) ?></p>
        <span class="small muted"><?= e(\Bakkbone\HelpCentre\Services\Staff::nameById((int)$n['staff_id'])) ?> · <?= e(time_ago($n['created_at'])) ?></span>
      </div>
    <?php endforeach; ?>
    <form method="post" action="<?= e($addon->url('/notes/' . $ticket['id'])) ?>">
      <?= csrf_field() ?>
      <div class="field"><label for="hello-note"><?= t('Add a note') ?></label><textarea id="hello-note" name="body" rows="2" maxlength="2000" required></textarea></div>
      <button class="btn btn-sm" type="submit"><?= t('Save note') ?></button>
    </form>
  </div>
</div>
