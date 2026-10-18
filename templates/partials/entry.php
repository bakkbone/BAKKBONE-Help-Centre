<?php
/** @var array $e */
$isStaffView = !empty($staffView);
$body = (string)$e['body'];
if ($isStaffView && setting('block_remote_images') !== '0') {
    // Off-site images can tell the sender when, and from which IP, an agent opened the ticket.
    [$body] = \Bakkbone\HelpCentre\Core\Html::hideRemoteImages($body);
}
?>
<article class="entry <?= e($e['type']) ?>" id="entry-<?= (int)$e['id'] ?>">
  <span class="avatar entry-avatar" aria-hidden="true"><?= $e['type'] === 'N' ? icon('lock') : e(initials((string)$e['poster'])) ?></span>
  <div class="entry-bubble">
    <header class="entry-head">
      <span class="who"><?= e($e['poster'] ?: __('Unknown')) ?></span>
      <?php if ($e['type'] === 'N'): ?><span class="badge badge-note"><?= t('Internal note') ?></span><?php elseif ($e['type'] === 'R' && $isStaffView): ?><span class="badge badge-reply"><?= t('Reply') ?></span><?php endif; ?>
      <?php if ($isStaffView && $e['source'] && $e['source'] !== 'web'): ?><span class="badge" title="<?= t('Source') ?>"><?= e($e['source']) ?></span><?php endif; ?>
      <?php if ($isStaffView && $e['type'] === 'M' && !empty($e['recipients'])):
          $rc = json_decode((string)$e['recipients'], true);
          if (!empty($rc['cc'])): ?><span class="small faint"><?= t('cc: {addresses}', ['addresses' => implode(', ', array_column($rc['cc'], 'email'))]) ?></span><?php endif; endif; ?>
      <time class="when" datetime="<?= e($e['created_at']) ?>Z" title="<?= e(fmt_dt($e['created_at'])) ?>"><?= e(fmt_dt($e['created_at'])) ?></time>
    </header>
    <div class="entry-body"><?= $body /* sanitised with HTMLPurifier when stored */ ?></div>
    <?php if (!empty($e['attachments'])): ?>
    <div class="entry-files">
      <?php foreach ($e['attachments'] as $a): ?>
        <a class="file-chip" href="<?= e(url('/file/' . $a['id'])) ?>" title="<?= e($a['filename']) ?>"><?= icon('attach_file') ?><span><?= e($a['filename']) ?></span> <span class="faint"><?= e(human_size((int)$a['size'])) ?></span></a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</article>
