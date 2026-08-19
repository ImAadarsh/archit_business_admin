<?php
/**
 * GST Filing Center sub-navigation. Expects $gst_nav (current key).
 */
$gst_nav = isset($gst_nav) ? $gst_nav : 'hub';
$gst_nav_items = [
    'hub' => ['gst-filing.php', 'Filing Center'],
    'gstr1' => ['gst-gstr1.php', 'GSTR-1'],
    'gstr2b' => ['gst-gstr2b.php', 'GSTR-2B'],
    'gstr3b' => ['gst-gstr3b.php', 'GSTR-3B'],
    'carry' => ['gst-carry-forward.php', 'Carry-forward'],
    'status' => ['gst-return-status.php', 'Return status'],
    'logs' => ['gst-api-logs.php', 'API logs'],
    'credentials' => ['gst-credentials.php', 'GST credentials'],
];
?>
<div class="card shadow mb-4">
    <div class="card-body py-2">
        <ul class="nav nav-pills flex-wrap mb-0">
            <?php foreach ($gst_nav_items as $key => $item): ?>
                <li class="nav-item mr-1 mb-1">
                    <a class="nav-link py-1 px-3 <?php echo $gst_nav === $key ? 'active' : ''; ?>"
                       href="<?php echo gst_h(gst_url($item[0])); ?>">
                        <?php echo gst_h($item[1]); ?>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
</div>
