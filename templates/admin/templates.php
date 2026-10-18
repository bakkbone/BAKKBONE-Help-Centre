<div class="page-head"><h1><?= t('Email templates') ?></h1></div>
<p class="muted"><?= t('Templates use merge tags such as <code>%{ticket.number}</code>. Every email is wrapped in a branded layout using your brand colour.') ?></p>
<?php $groups = []; foreach ($defaults as $code => $d) { $groups[$d['group']][$code] = $d; } ?>
<?php foreach ($groups as $g => $items): ?>
<div class="card card-flush">
  <div class="card-head"><h2><?= e($g) ?></h2></div>
  <div class="card-body">
    <table class="table"><tbody>
      <?php foreach ($items as $code => $d): ?>
        <tr data-href="<?= e(url('/panel/admin/templates/' . $code)) ?>">
          <td class="primary"><a class="subject" href="<?= e(url('/panel/admin/templates/' . $code)) ?>"><?= e($d['name']) ?></a></td>
          <td data-label="<?= t('Code') ?>" class="small mono muted"><?= e($code) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody></table>
  </div>
</div>
<?php endforeach; ?>
