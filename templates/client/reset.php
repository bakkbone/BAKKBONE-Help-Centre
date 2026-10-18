<div class="card auth-card">
  <div class="card-body">
    <h1><?= t('Reset your password') ?></h1>
    <p class="muted"><?= t('Enter your email address and we\'ll send you a reset code.') ?></p>
    <form method="post" action="<?= e(url('/reset')) ?>">
      <?= csrf_field() ?>
      <div class="field"><label for="email"><?= t('Email address') ?></label><input id="email" type="email" name="email" required autocomplete="email"></div>
      <button class="btn btn-primary btn-block" type="submit"><?= t('Send reset code') ?></button>
    </form>
    <p class="small center" style="margin-top:12px"><a href="<?= e(url('/login')) ?>"><?= t('Back to sign in') ?></a></p>
  </div>
</div>
