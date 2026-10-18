<div class="page-head">
  <h1><?= t('Agents') ?></h1>
  <div class="actions"><a class="btn btn-primary" href="<?= e(url('/panel/admin/staff/new')) ?>"><?= t('Add agent') ?></a></div>
</div>
<div class="card card-flush"><div class="card-body">
  <table class="table">
    <thead><tr><th><?= t('Name') ?></th><th><?= t('Username') ?></th><th><?= t('Department') ?></th><th><?= t('Role') ?></th><th><?= t('2FA') ?></th><th><?= t('Last sign-in') ?></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $s): ?>
      <tr data-href="<?= e(url('/panel/admin/staff/' . $s['id'])) ?>">
        <td class="primary"><a class="subject" href="<?= e(url('/panel/admin/staff/' . $s['id'])) ?>"><?= e(trim($s['first_name'] . ' ' . $s['last_name'])) ?></a>
          <?= $s['is_admin'] ? ' <span class="badge badge-open">' . t('Admin') . '</span>' : '' ?><?= $s['active'] ? '' : ' <span class="badge">' . t('Inactive') . '</span>' ?><?= $s['onvacation'] ? ' <span class="badge badge-warn">' . t('Away') . '</span>' : '' ?>
          <br><span class="small faint"><?= e($s['email']) ?></span></td>
        <td data-label="<?= t('Username') ?>" class="mono small"><?= e($s['username']) ?></td>
        <td data-label="<?= t('Department') ?>"><?= e($s['dept_name'] ?? '—') ?></td>
        <td data-label="<?= t('Role') ?>"><?= e($s['is_admin'] ? __('Administrator') : ($s['role_name'] ?? '—')) ?></td>
        <td data-label="<?= t('2FA') ?>"><?= $s['totp_enabled'] ? '<span class="badge badge-ok">' . t('On') . '</span>' : '<span class="faint">' . t('Off') . '</span>' ?></td>
        <td data-label="<?= t('Last sign-in') ?>" class="small muted"><?= e($s['last_login_at'] ? time_ago($s['last_login_at']) : __('Never')) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div></div>
