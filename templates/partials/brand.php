<?php
$logo = (string)setting('logo_url');
$logoDark = (string)setting('logo_dark_url');
$name = (string)setting('helpdesk_name');
?>
<a class="brand" href="<?= e(url($href ?? '/')) ?>">
  <?php if ($logo !== '' && $logoDark !== '' && $logoDark !== $logo): ?>
    <img class="when-light" src="<?= e($logo) ?>" alt="<?= e($name) ?>">
    <img class="when-dark" src="<?= e($logoDark) ?>" alt="<?= e($name) ?>">
  <?php elseif ($logo !== '' || $logoDark !== ''): ?>
    <img src="<?= e($logo !== '' ? $logo : $logoDark) ?>" alt="<?= e($name) ?>">
  <?php else: ?>
    <span class="brand-mark" aria-hidden="true"><?= e(initials($name)) ?></span><span><?= e($name) ?></span>
  <?php endif; ?>
  <?php if (!empty($suffix)): ?><span class="badge"><?= e($suffix) ?></span><?php endif; ?>
</a>
