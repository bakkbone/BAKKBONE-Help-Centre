<?php use Bakkbone\HelpCentre\Services\Captcha; ?>
<div class="card auth-card">
  <div class="card-body">
    <h1><?= t('Track a ticket') ?></h1>
    <p class="muted"><?= t('Enter your email and ticket number. We\'ll email you a code to open the ticket securely.') ?></p>
    <form method="post" action="<?= e(url('/status')) ?>">
      <?= csrf_field() ?>
      <div class="field"><label for="email"><?= t('Email address') ?></label><input id="email" type="email" name="email" value="<?= e(old('email')) ?>" required autocomplete="email"></div>
      <div class="field"><label for="number"><?= t('Ticket number') ?></label><input id="number" type="text" name="number" value="<?= e(old('number')) ?>" required placeholder="e.g. 482915"></div>
      <?= Captcha::widget() ?>
      <button class="btn btn-primary btn-block" type="submit"><?= t('Email me an access code') ?></button>
    </form>
    <hr>
    <p class="small center muted"><?= t('Have an account? {link}Sign in{/link} to see all your tickets.', ['link' => tag('<a href="' . e(url('/login')) . '">')]) ?></p>
  </div>
</div>
