<?php
use Bakkbone\HelpCentre\Core\Request;
use Bakkbone\HelpCentre\Core\View;
use Bakkbone\HelpCentre\Services\Auth;
use Bakkbone\HelpCentre\Services\Staff;

$me = Auth::staff();
$p = Request::path();
$isActive = fn(string $prefix, bool $exact = false) => $exact ? $p === $prefix : str_starts_with($p, $prefix);
$navItem = function (string $href, string $icon, string $label, string $short, bool $on): string {
    return '<a href="' . e(url($href)) . '"' . ($on ? ' class="active" aria-current="page"' : '') . '>'
        . '<span class="nav-ico">' . icon($icon, 'i-out') . icon($icon . '-fill', 'i-fill') . '</span>'
        . '<span class="nav-label">' . e($label) . '</span><span class="nav-short" aria-hidden="true">' . e($short) . '</span></a>';
};
?>
<!doctype html>
<html<?= lang_attr() ?><?= theme_attr() ?>>
<head><?= View::partial('partials/head', ['title' => $title ?? '']) ?></head>
<body class="staff-ui app<?= $me ? '' : ' no-drawer' ?>">
<?php if ($me): ?>
<input type="checkbox" id="nav-toggle" class="nav-toggle-box" aria-hidden="true" tabindex="-1">
<aside class="drawer" aria-label="<?= t('Agent navigation') ?>">
  <div class="drawer-head"><?= View::partial('partials/brand', ['href' => '/panel']) ?></div>
  <?php if (Staff::can($me, 'ticket.create')): ?>
    <a class="fab" href="<?= e(url('/panel/tickets/new')) ?>"><?= icon('edit_square') ?><span><?= t('New ticket') ?></span></a>
  <?php endif; ?>
  <nav class="drawer-nav">
    <?= $navItem('/panel', 'dashboard', __('Dashboard'), __('Home'), $isActive('/panel', true)) ?>
    <?= $navItem('/panel/tickets', 'inbox', __('Tickets'), __('Tickets'), $isActive('/panel/tickets') && !$isActive('/panel/tickets/new', true)) ?>
    <?= $navItem('/panel/tasks', 'task_alt', __('Tasks'), __('Tasks'), $isActive('/panel/tasks')) ?>
    <?= $navItem('/panel/users', 'group', __('Users'), __('Users'), $isActive('/panel/users')) ?>
    <?= $navItem('/panel/orgs', 'domain', __('Organisations'), __('Orgs'), $isActive('/panel/orgs')) ?>
    <?= $navItem('/panel/kb', 'menu_book', __('Knowledge base'), __('Articles'), $isActive('/panel/kb')) ?>
    <?= $navItem('/panel/canned', 'quickreply', __('Canned replies'), __('Replies'), $isActive('/panel/canned')) ?>
    <?php foreach (\Bakkbone\HelpCentre\Core\Addons::navItems($me) as $ni):
        $ico = (string)$ni['icon'];
        $on = $isActive($ni['href']);
        $glyph = str_contains($ico, '/')
            ? '<img class="icon i-out" src="' . e($ico) . '" alt=""><img class="icon i-fill" src="' . e($ico) . '" alt="">'
            : icon(icon_exists($ico) ? $ico : 'extension', 'i-out') . icon(icon_exists($ico . '-fill') ? $ico . '-fill' : (icon_exists($ico) ? $ico : 'extension-fill'), 'i-fill'); ?>
      <a href="<?= e(url($ni['href'])) ?>"<?= $on ? ' class="active" aria-current="page"' : '' ?>><span class="nav-ico"><?= $glyph ?></span><span class="nav-label"><?= e($ni['label']) ?></span><span class="nav-short" aria-hidden="true"><?= e(mb_strimwidth($ni['label'], 0, 10, '…')) ?></span></a>
    <?php endforeach; ?>
    <?php if ($me['is_admin']): ?>
      <div class="drawer-divider" role="separator"></div>
      <?= $navItem('/panel/admin', 'admin_panel_settings', __('Admin'), __('Admin'), $isActive('/panel/admin')) ?>
    <?php endif; ?>
  </nav>
  <div class="drawer-foot">
    <a href="<?= e(url('/')) ?>"><?= icon('open_in_new') ?><span class="nav-label"><?= t('End-user portal') ?></span></a>
  </div>
</aside>
<label class="scrim" for="nav-toggle" aria-hidden="true"></label>
<?php endif; ?>
<div class="app-main">
  <header class="appbar">
    <?php if ($me): ?>
      <label class="icon-btn appbar-menu" for="nav-toggle" aria-label="<?= t('Open navigation') ?>" title="<?= t('Menu') ?>"><?= icon('menu') ?></label>
      <?php if ($p !== '/panel/tickets'): /* the ticket list has its own search and filters */ ?>
      <form class="appbar-search" action="<?= e(url('/panel/tickets')) ?>" method="get" role="search">
        <?= icon('search') ?>
        <input type="search" name="q" placeholder="<?= t('Search tickets') ?>" aria-label="<?= t('Search tickets') ?>">
      </form>
      <?php endif; ?>
    <?php else: ?>
      <?= View::partial('partials/brand', ['href' => '/panel']) ?>
    <?php endif; ?>
    <span class="spacer"></span>
    <?= View::partial('partials/theme_toggle') ?>
    <?php if ($me): ?>
      <a class="appbar-user<?= $isActive('/panel/profile') ? ' active' : '' ?>" href="<?= e(url('/panel/profile')) ?>" title="<?= t('My profile') ?>">
        <span class="avatar"><?= e(initials(Staff::name($me))) ?></span><span class="appbar-name"><?= e($me['first_name']) ?></span>
      </a>
      <a class="icon-btn" href="<?= e(url('/panel/logout')) ?>" aria-label="<?= t('Sign out') ?>" title="<?= t('Sign out') ?>"><?= icon('logout') ?></a>
    <?php endif; ?>
  </header>
  <main>
    <div class="container">
      <?php if ($me && $me['is_admin'] && (int)setting('schema_version') < HC_SCHEMA_VERSION): ?><div class="alert alert-error"><strong><?= t('The database needs an update that couldn\'t be applied automatically.') ?></strong>
        <?php if (setting('schema_upgrade_error')): ?><br><span class="small"><?= e(setting('schema_upgrade_error')) ?></span><?php endif; ?>
        <br><?= t('Check that the database user is allowed to CREATE and ALTER tables, then {link}apply database upgrades{/link}.', ['link' => tag('<a href="' . e(url('/panel/admin/system')) . '">')]) ?></div><?php endif; ?>
      <?php if ($me && $me['onvacation']): ?><div class="alert alert-warning"><?= t('You are in vacation mode and will not receive alerts. {link}Change{/link}', ['link' => tag('<a href="' . e(url('/panel/profile')) . '">')]) ?></div><?php endif; ?>
      <?php if (isset($adminSection)): ?>
        <div class="admin-layout">
          <?= View::partial('admin/_nav', ['section' => $adminSection]) ?>
          <div class="admin-main">
            <?= View::partial('partials/flashes') ?>
            <?= $content ?>
          </div>
        </div>
      <?php else: ?>
        <?= View::partial('partials/flashes') ?>
        <?= $content ?>
      <?php endif; ?>
    </div>
  </main>
  <footer class="footer">
    <div class="container">
      <span><?= e(setting('company_name')) ?> · <?= t('BAKKBONE Help Centre v{version}', ['version' => HC_VERSION]) ?></span>
    </div>
  </footer>
</div>
<script src="<?= e(asset('js/qrcode.js')) ?>" defer></script>
<script src="<?= e(asset('js/app.js')) ?>" defer></script>
<?php if (is_file(HC_ROOT . '/custom/custom.js')): ?><script src="<?= e(url('/custom/custom.js')) ?>?v=<?= (int)filemtime(HC_ROOT . '/custom/custom.js') ?>" defer></script><?php endif; ?>
<?= \Bakkbone\HelpCentre\Core\Hooks::render('panel.body_end') ?>
</body>
</html>
