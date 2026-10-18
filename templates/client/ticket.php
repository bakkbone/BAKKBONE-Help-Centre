<?php
use Bakkbone\HelpCentre\Core\View;
use Bakkbone\HelpCentre\Services\Tickets;
$attach = setting('attachments_enabled') === '1' && setting('client_attachments') === '1';
$canReply = $isOpen || setting('reopen_closed') === '1';
?>
<p class="small"><a href="<?= e(url($isGuest ? '/' : '/tickets')) ?>">&larr; <?= $isGuest ? t('Home') : t('My tickets') ?></a></p>
<div class="ticket-title">
  <h1><?= e($t['subject']) ?></h1>
</div>
<div class="ticket-meta">
  <span>#<?= e($t['number']) ?></span>
  <span class="badge <?= $isOpen ? 'badge-open' : 'badge-closed' ?>"><?= e($status['client_name'] ?? $status['name'] ?? __('Open')) ?></span>
  <span><?= t('Opened {when}', ['when' => fmt_dt($t['created_at'])]) ?></span>
  <?php if ($t['dept_id']): ?><span><?= e(Tickets::lookupName('departments', $t['dept_id'])) ?></span><?php endif; ?>
</div>
<?php if ($isGuest): ?>
  <div class="alert alert-info"><?= t('You\'re viewing this ticket from an email link. {link}Sign in{/link} to see all your tickets.', ['link' => tag('<a href="' . e(url('/login')) . '">')]) ?></div>
<?php endif; ?>

<div class="ticket-layout">
  <div>
    <div class="thread">
      <?php foreach ($entries as $e): ?>
        <?= View::partial('partials/entry', ['e' => $e, 'staffView' => false]) ?>
      <?php endforeach; ?>
    </div>

    <?php if ($canReply): ?>
    <form class="card" style="margin-top:18px" method="post" action="<?= e(url('/tickets/' . $t['number'] . '/reply')) ?>" enctype="multipart/form-data">
      <div class="card-head"><h2><?= t('Post a reply') ?></h2></div>
      <div class="card-body">
        <?= csrf_field() ?>
        <?php if (!$isOpen): ?><div class="alert alert-info"><?= t('This ticket is closed. Replying will reopen it.') ?></div><?php endif; ?>
        <div class="field"><label class="sr-only" for="message"><?= t('Message') ?></label><textarea id="message" name="message" data-editor placeholder="<?= t('Type your reply…') ?>"><?= e(old('message')) ?></textarea></div>
        <?php if ($attach): ?>
        <div class="field"><label for="attachments"><?= t('Attachments') ?></label><input id="attachments" type="file" name="attachments[]" multiple></div>
        <?php endif; ?>
        <div class="form-actions"><button class="btn btn-primary" type="submit"><?= t('Send reply') ?></button></div>
      </div>
    </form>
    <?php endif; ?>
  </div>

  <aside>
    <div class="card">
      <div class="card-head"><h3><?= t('Details') ?></h3></div>
      <div class="card-body">
        <dl class="dl">
          <dt><?= t('Ticket') ?></dt><dd>#<?= e($t['number']) ?></dd>
          <dt><?= t('Status') ?></dt><dd><?= e($status['client_name'] ?? $status['name'] ?? '') ?></dd>
          <?php if ($t['topic_id']): ?><dt><?= t('Topic') ?></dt><dd><?= e(Tickets::lookupName('help_topics', $t['topic_id'])) ?></dd><?php endif; ?>
          <dt><?= t('Created by') ?></dt><dd><?= e($owner['name'] ?? '') ?></dd>
          <dt><?= t('Last update') ?></dt><dd><?= e(time_ago($t['updated_at'])) ?></dd>
          <?php foreach ($fields as $f): ?><dt><?= e($f['label']) ?></dt><dd><?= e($f['display']) ?></dd><?php endforeach; ?>
        </dl>
      </div>
    </div>
    <?php if ($isOpen && setting('allow_client_close') === '1' && (int)$t['user_id'] === (int)$viewer['id']): ?>
    <form class="card" method="post" action="<?= e(url('/tickets/' . $t['number'] . '/close')) ?>" data-confirm="<?= t('Mark this ticket as resolved?') ?>">
      <div class="card-body"><?= csrf_field() ?>
        <p class="small muted"><?= t('Problem solved? Let us know so we can close the ticket.') ?></p>
        <button class="btn btn-block" type="submit"><?= t('Mark as resolved') ?></button>
      </div>
    </form>
    <?php endif; ?>
    <?= \Bakkbone\HelpCentre\Core\Hooks::render('portal.ticket.sidebar', ['ticket' => $t]) ?>
  </aside>
</div>
