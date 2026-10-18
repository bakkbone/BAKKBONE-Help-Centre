<div class="card auth-card" style="max-width:640px">
  <div class="card-body">
    <h1><?= t('Ticket #{number} submitted', ['number' => $number]) ?></h1>
    <?= setting('thank_you_html') ?>
    <p class="muted small"><?= t('Keep your ticket number handy. You can reply to our emails to add information, or check the status any time.') ?></p>
    <div class="btn-row">
      <a class="btn btn-primary" href="<?= e(url('/status')) ?>"><?= t('Track your ticket') ?></a>
      <a class="btn" href="<?= e(url('/')) ?>"><?= t('Back to home') ?></a>
    </div>
  </div>
</div>
