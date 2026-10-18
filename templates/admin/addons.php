<?php use Bakkbone\HelpCentre\Core\Addons; ?>
<div class="page-head">
  <h1><?= t('Add-ons') ?></h1>
</div>
<p class="muted"><?= t('Add-ons add features without changing the help desk\'s own files, so updating never overwrites them. Each one is a folder in <code>addons/</code> on the server. See <code>ADDONS.md</code> to write your own.') ?></p>

<?php if ($safeMode): ?>
  <div class="alert alert-warning"><?= t('<strong>Safe mode is on.</strong> No add-ons are loaded. Remove <code>\'safe_mode\' => true</code> from <code>config/config.php</code> to load them again.') ?></div>
<?php endif; ?>
<?php foreach ($missing as $slug): ?>
  <div class="alert alert-warning"><?= t('The add-on') ?> <strong><?= e($slug) ?></strong> <?= t('is switched on but its folder is missing from <code>addons/</code>. Put it back, or') ?>

    <form class="inline" method="post" action="<?= e(url('/panel/admin/addons/' . $slug . '/disable')) ?>"><?= csrf_field() ?><button class="link-btn" type="submit"><?= t('switch it off') ?></button></form>.</div>
<?php endforeach; ?>

<div class="card card-flush"><div class="card-body">
<?php if (!$addons): ?>
  <div class="empty-state"><h3><?= t('No add-ons installed') ?></h3><p><?= t('Copy an add-on\'s folder into <code>addons/</code> on the server and it will appear here.') ?></p></div>
<?php else: ?>
  <ul class="addon-list">
  <?php foreach ($addons as $slug => $a):
      $on = in_array($slug, $enabled, true) && !$safeMode;
      $err = $errors[$slug] ?? null;
      $hasSettings = (bool)Addons::settingDefinitions($a); ?>
    <li class="addon-row<?= $on ? ' is-on' : '' ?>">
      <span class="addon-icon" aria-hidden="true"><?= icon('extension') ?></span>
      <div class="addon-main">
        <div class="addon-title">
          <strong><?= e(__($a['name'])) ?></strong>
          <?php if ($a['version'] !== ''): ?><span class="small muted">v<?= e($a['version']) ?></span><?php endif; ?>
          <?php if ($on): ?><span class="badge badge-ok"><?= t('On') ?></span><?php elseif ($a['problems']): ?><span class="badge badge-bad"><?= t('Can\'t load') ?></span><?php else: ?><span class="badge"><?= t('Off') ?></span><?php endif; ?>
        </div>
        <?php if ($a['description'] !== ''): ?><p class="addon-desc"><?= e(__($a['description'])) ?></p><?php endif; ?>
        <p class="small muted addon-meta">
          <code><?= e($slug) ?></code>
          <?php if ($a['author'] !== ''): ?> · <?= t('by {author}', ['author' => $a['author']]) ?><?php endif; ?>
          <?php if ($a['url'] !== '' && preg_match('#^https?://#i', $a['url'])): ?> · <a href="<?= e($a['url']) ?>" target="_blank" rel="noopener noreferrer"><?= t('Website') ?></a><?php endif; ?>
        </p>
        <?php foreach ($a['problems'] as $p): ?><div class="alert alert-error small"><?= e($p) ?></div><?php endforeach; ?>
        <?php if ($err): ?><div class="alert alert-warning small"><strong><?= t('Last problem ({when}):', ['when' => time_ago($err['at'])]) ?></strong> <?= e($err['message']) ?></div><?php endif; ?>
      </div>
      <div class="addon-actions">
        <?php if ($hasSettings): ?><a class="btn btn-sm" href="<?= e(url('/panel/admin/addons/' . $slug)) ?>"><?= t('Settings') ?></a><?php endif; ?>
        <?php if (in_array($slug, $enabled, true)): ?>
          <form method="post" action="<?= e(url('/panel/admin/addons/' . $slug . '/disable')) ?>"><?= csrf_field() ?><button class="btn btn-sm" type="submit"><?= t('Switch off') ?></button></form>
        <?php elseif (!$a['problems'] && !$safeMode): ?>
          <form method="post" action="<?= e(url('/panel/admin/addons/' . $slug . '/enable')) ?>" data-confirm="<?= t('Switch on {name}? Add-ons run with full access to the help desk, so only use ones you trust.', ['name' => __($a['name'])]) ?>"><?= csrf_field() ?><button class="btn btn-sm btn-primary" type="submit"><?= t('Switch on') ?></button></form>
        <?php endif; ?>
      </div>
    </li>
  <?php endforeach; ?>
  </ul>
<?php endif; ?>
</div></div>

<div class="grid grid-2" style="margin-top:16px">
  <div class="card">
    <div class="card-head"><h2><?= t('Install an add-on') ?></h2></div>
    <div class="card-body">
      <?php if ($canUpload): ?>
        <form method="post" action="<?= e(url('/panel/admin/addons/upload')) ?>" enctype="multipart/form-data">
          <?= csrf_field() ?>
          <div class="field"><label for="zip"><?= t('Add-on .zip file') ?></label><input id="zip" type="file" name="zip" accept=".zip,application/zip" required></div>
          <label class="check"><input type="checkbox" name="replace" value="1"> <?= t('Replace it if it\'s already installed (to update it)') ?></label>
          <div class="form-actions"><button class="btn btn-primary" type="submit"><?= icon('upload_file') ?><?= t('Upload') ?></button></div>
        </form>
      <?php else: ?>
        <p><?= t('Copy the add-on\'s folder into <code>addons/</code> on the server (for example with FTP or your hosting file manager). It appears in the list above, ready to switch on.') ?></p>
        <?php if (!$uploadsConfigured): ?>
          <p class="small muted"><?= t('To upload .zip files here instead, add <code>\'addon_uploads\' => true,</code> to <code>config/config.php</code>. It\'s off by default because an add-on can run any code on the server.') ?></p>
        <?php else: ?>
          <p class="small muted"><?= t('Uploading needs PHP\'s <code>zip</code> extension, which this server doesn\'t have.') ?></p>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>
  <div class="card">
    <div class="card-head"><h2><?= t('Your own styles and templates') ?></h2></div>
    <div class="card-body">
      <p><?= t('For smaller changes you don\'t need an add-on. Files in <code>custom/</code> are never touched by updates:') ?></p>
      <ul class="small" style="padding-inline-start:20px;margin:0">
        <li><?= t('<code>custom/custom.css</code> is added to every page (portal and agent panel).') ?></li>
        <li><?= t('<code>custom/custom.js</code> is loaded on every page.') ?></li>
        <li><?= t('<code>custom/templates/…</code> replaces the matching file in <code>templates/</code>.') ?></li>
      </ul>
      <p class="small muted" style="margin-bottom:0">
        <?= is_file(HC_ROOT . '/custom/custom.css') ? t('custom.css is in use.') : '' ?>
        <?= is_file(HC_ROOT . '/custom/custom.js') ? t('custom.js is in use.') : '' ?>
        <?php $ov = is_dir(HC_ROOT . '/custom/templates') ? count(array_filter(iterator_to_array(new RecursiveIteratorIterator(new RecursiveDirectoryIterator(HC_ROOT . '/custom/templates', FilesystemIterator::SKIP_DOTS))), fn($f) => $f->isFile() && str_ends_with($f->getFilename(), '.php'))) : 0; ?>
        <?= $ov ? tn('{n} template override in use.', '{n} template overrides in use.', $ov) : '' ?>
      </p>
    </div>
  </div>
</div>
