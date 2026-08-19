<?php
/**
 * Shared chrome for GST sibling pages.
 * Set $gst_nav, $gst_page_title, $gst_page_lead before including.
 * Optional:
 *   $gst_page_full     — skip the placeholder card; render $gst_page_extra as the body
 *   $gst_page_extra    — callable extra HTML (inside the card, or full body when $gst_page_full)
 *   $gst_page_toolbar  — callable right-side header actions
 *   $gst_page_scripts  — callable after footer (modals / page JS)
 */
require_once __DIR__ . '/gst-init.php';
include dirname(__DIR__) . '/admin/header.php';

$gst_nav = isset($gst_nav) ? $gst_nav : 'hub';
$gst_page_title = isset($gst_page_title) ? $gst_page_title : 'GST Filing';
$gst_page_lead = isset($gst_page_lead) ? $gst_page_lead : '';
$gst_page_full = !empty($gst_page_full);
?>
<body class="vertical light">
    <div class="wrapper">
        <?php
        include dirname(__DIR__) . '/admin/navbar.php';
        include dirname(__DIR__) . '/admin/aside.php';
        ?>
        <main role="main" class="main-content">
            <div class="container-fluid pb-5">
                <div class="row justify-content-center">
                    <div class="col-12">
                        <div class="row align-items-center mb-3">
                            <div class="col">
                                <h2 class="h3 page-title mb-0"><?php echo gst_h($gst_page_title); ?></h2>
                                <p class="small text-muted mb-0">
                                    Period <strong><?php echo gst_h(gst_period_label($gst_period)); ?></strong>
                                    (<?php echo gst_h($gst_period); ?>)
                                    · <a href="<?php echo gst_h(gst_url('gst-filing.php')); ?>">Back to Filing Center</a>
                                </p>
                            </div>
                            <?php if (!empty($gst_page_toolbar) && is_callable($gst_page_toolbar)): ?>
                                <div class="col-auto"><?php $gst_page_toolbar(); ?></div>
                            <?php endif; ?>
                        </div>
                        <?php include __DIR__ . '/gst-subnav.php'; ?>
                        <?php if (!empty($gst_flash['success'])): ?>
                            <div class="alert alert-success alert-dismissible fade show" role="alert">
                                <?php echo gst_h($gst_flash['success']); ?>
                                <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                                    <span aria-hidden="true">&times;</span>
                                </button>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($gst_flash['error'])): ?>
                            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                <?php echo gst_h($gst_flash['error']); ?>
                                <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                                    <span aria-hidden="true">&times;</span>
                                </button>
                            </div>
                        <?php endif; ?>
                        <?php if ($gst_page_full): ?>
                            <?php if (!empty($gst_page_extra) && is_callable($gst_page_extra)) { $gst_page_extra(); } ?>
                        <?php else: ?>
                            <div class="card shadow mb-4">
                                <div class="card-body">
                                    <?php if ($gst_page_lead !== ''): ?>
                                        <p class="mb-3"><?php echo gst_h($gst_page_lead); ?></p>
                                    <?php endif; ?>
                                    <?php if (!empty($gst_page_extra) && is_callable($gst_page_extra)) { $gst_page_extra(); } ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php include dirname(__DIR__) . '/admin/footer.php'; ?>
            <?php if (!empty($gst_page_scripts) && is_callable($gst_page_scripts)) { $gst_page_scripts(); } ?>
