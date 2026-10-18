<?php
use Bakkbone\HelpCentre\Services\Captcha;
$attach = setting('attachments_enabled') === '1' && setting('client_attachments') === '1';
?>
<div class="page-head"><h1><?= t('New support ticket') ?></h1></div>
<div class="ticket-layout">
  <form class="card" method="post" action="<?= e(url('/open')) ?>" enctype="multipart/form-data">
    <div class="card-body">
      <?= csrf_field() ?>
      <?php if ($user): ?>
        <p class="muted"><?= t('Submitting as') ?> <strong><?= e($user['name']) ?></strong> (<?= e($user['email']) ?>).</p>
      <?php else: ?>
        <div class="form-grid">
          <div class="field"><label class="req" for="name"><?= t('Your name') ?></label><input id="name" type="text" name="name" value="<?= e(old('name')) ?>" required autocomplete="name"></div>
          <div class="field"><label class="req" for="email"><?= t('Email address') ?></label><input id="email" type="email" name="email" value="<?= e(old('email')) ?>" required autocomplete="email"></div>
          <div class="field"><label for="phone"><?= t('Phone (optional)') ?></label><input id="phone" type="tel" name="phone" value="<?= e(old('phone')) ?>" autocomplete="tel"></div>
        </div>
      <?php endif; ?>

      <?php if ($topics): ?>
      <div class="field">
        <label class="req" for="topic"><?= t('What do you need help with?') ?></label>
        <select id="topic" name="topic_id" required data-topic-select>
          <option value=""><?= t('— Choose a help topic —') ?></option>
          <?php foreach ($topics as $t): ?><option value="<?= (int)$t['id'] ?>"<?= selected($t['id'], old('topic_id')) ?>><?= e($t['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>

      <?= \Bakkbone\HelpCentre\Core\View::partial('partials/custom_fields', ['fields' => $fields]) ?>

      <div class="field"><label class="req" for="subject"><?= t('Subject') ?></label><input id="subject" type="text" name="subject" value="<?= e(old('subject')) ?>" maxlength="250" required></div>
      <div class="field">
        <label class="req" for="message"><?= t('Message') ?></label>
        <textarea id="message" name="message" data-editor placeholder="<?= t('Please give as much detail as you can…') ?>"><?= e(old('message')) ?></textarea>
      </div>
      <?php if ($attach): ?>
      <div class="field">
        <label for="attachments"><?= t('Attachments') ?></label>
        <input id="attachments" type="file" name="attachments[]" multiple>
        <div class="hint"><?= tn('Up to {n} file, {size} MB each.', 'Up to {n} files, {size} MB each.', (int)setting('max_files'), ['size' => (int)setting('max_file_mb')]) ?></div>
      </div>
      <?php endif; ?>
      <?php if (!$user): ?><?= Captcha::widget() ?><?php endif; ?>
      <div class="form-actions">
        <button class="btn btn-primary" type="submit"><?= t('Submit ticket') ?></button>
        <a class="btn btn-ghost" href="<?= e(url('/')) ?>"><?= t('Cancel') ?></a>
      </div>
    </div>
  </form>
  <aside>
    <?php if (!$user): ?>
    <div class="card"><div class="card-body small">
      <p><?= t('<strong>Already have an account?</strong>') ?></p>
      <p class="muted"><?= t('Sign in to track all your tickets in one place.') ?></p>
      <a class="btn btn-block" href="<?= e(url('/login')) ?>"><?= t('Sign in') ?></a>
    </div></div>
    <?php endif; ?>
    <?php if (setting('kb_enabled') === '1'): ?>
    <div class="card"><div class="card-body small">
      <p><?= t('<strong>Looking for a quick answer?</strong>') ?></p>
      <p class="muted"><?= t('Many common questions are answered in our knowledge base.') ?></p>
      <a class="btn btn-block" href="<?= e(url('/kb')) ?>"><?= t('Browse articles') ?></a>
    </div></div>
    <?php endif; ?>
  </aside>
</div>
