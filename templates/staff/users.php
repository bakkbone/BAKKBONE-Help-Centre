<?php
use Bakkbone\HelpCentre\Controllers\Staff\UserController;
use Bakkbone\HelpCentre\Services\Auth;
use Bakkbone\HelpCentre\Services\Staff;

$base = array_filter(['q' => $q, 'org_id' => $org]);
$isDefault = fn(string $col, string $d) => $col === 'name' && $d === 'asc';
$sortQuery = fn(string $col, string $d) => $base + ($isDefault($col, $d) ? [] : ['sort' => $col, 'dir' => $d]);
$th = function (string $col, string $label) use ($sort, $dir, $sortQuery): string {
    $active = $sort === $col;
    $first = in_array($col, UserController::SORT_DESC_FIRST, true) ? 'desc' : 'asc';
    $next = $active ? ($dir === 'asc' ? 'desc' : 'asc') : $first;
    $shown = $active ? $dir : $first;
    $title = $next === 'asc' ? __('Sort by {column}, ascending', ['column' => mb_strtolower($label)]) : __('Sort by {column}, descending', ['column' => mb_strtolower($label)]);
    return '<th' . ($active ? ' aria-sort="' . ($dir === 'asc' ? 'ascending' : 'descending') . '"' : '') . '>'
        . '<a class="th-sort" href="' . e(url('/panel/users', $sortQuery($col, $next))) . '" title="' . e($title) . '">'
        . e($label) . icon($shown === 'asc' ? 'arrow_upward' : 'arrow_downward') . '</a></th>';
};
$sortOptions = [
    'name:asc' => __('Name (A–Z)'), 'name:desc' => __('Name (Z–A)'), 'email:asc' => __('Email (A–Z)'), 'email:desc' => __('Email (Z–A)'),
    'org:asc' => __('Organisation (A–Z)'), 'org:desc' => __('Organisation (Z–A)'), 'tickets:desc' => __('Most tickets'), 'tickets:asc' => __('Fewest tickets'),
    'status:asc' => __('Status'), 'created:desc' => __('Newest first'), 'created:asc' => __('Oldest first'),
];
?>
<div class="page-head">
  <h1><?= t('Users') ?> <span class="faint" style="font-weight:500;font-size:1rem">(<?= (int)$total ?>)</span></h1>
  <div class="actions">
    <?php if ($canDelete): ?><a class="btn" href="<?= e(url('/panel/users/cleanup')) ?>"><?= icon('delete_sweep') ?><?= t('Clean up') ?></a><?php endif; ?>
    <?php if (Staff::can(Auth::staff(), 'user.manage')): ?><a class="btn btn-primary" href="<?= e(url('/panel/users/new')) ?>"><?= t('Add user') ?></a><?php endif; ?>
  </div>
</div>
<form class="toolbar" method="get" action="<?= e(url('/panel/users')) ?>">
  <input class="search" type="search" name="q" value="<?= e($q) ?>" placeholder="<?= t('Search name, email, phone or organisation') ?>" aria-label="<?= t('Search users') ?>">
  <?php if ($org): ?><input type="hidden" name="org_id" value="<?= (int)$org ?>"><?php endif; ?>
  <select name="sort" class="only-narrow" aria-label="<?= t('Sort users') ?>" data-autosubmit>
    <?php foreach ($sortOptions as $val => $label): ?><option value="<?= e($val) ?>"<?= selected($val, $sort . ':' . $dir) ?>><?= e($label) ?></option><?php endforeach; ?>
  </select>
  <?php if (!isset($sortOptions[$sort . ':' . $dir])): ?><input type="hidden" name="dir" value="<?= e($dir) ?>"><?php endif; ?>
  <button class="btn" type="submit"><?= t('Search') ?></button>
</form>
<div class="card card-flush"><div class="card-body">
<?php if (!$users): ?><div class="empty-state"><h3><?= t('No users found') ?></h3></div><?php else: ?>
  <table class="table">
    <thead><tr><?= $th('name', __('Name')) . $th('email', __('Email')) . $th('org', __('Organisation')) . $th('tickets', __('Tickets')) . $th('status', __('Status')) . $th('created', __('Created')) ?></tr></thead>
    <tbody>
    <?php foreach ($users as $u): ?>
      <tr data-href="<?= e(url('/panel/users/' . $u['id'])) ?>">
        <td class="primary"><a href="<?= e(url('/panel/users/' . $u['id'])) ?>" class="subject"><?= e($u['name']) ?></a></td>
        <td data-label="<?= t('Email') ?>"><?= e($u['email']) ?></td>
        <td data-label="<?= t('Organisation') ?>"><?= e($u['org_name'] ?? '') ?></td>
        <td data-label="<?= t('Tickets') ?>"><?= (int)$u['ticket_count'] ?></td>
        <td data-label="<?= t('Status') ?>"><?php if ($u['status'] === 'locked'): ?><span class="badge badge-bad"><?= t('Locked') ?></span><?php elseif ($u['verified']): ?><span class="badge badge-ok"><?= t('Registered') ?></span><?php else: ?><span class="badge"><?= t('Guest') ?></span><?php endif; ?></td>
        <td data-label="<?= t('Created') ?>" class="small muted nowrap"><?= e(fmt_date($u['created_at'])) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
</div></div>
<?= pager($page, $pages, '/panel/users', $sortQuery($sort, $dir)) ?>
