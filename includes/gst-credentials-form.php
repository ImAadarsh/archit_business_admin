<?php
/**
 * GSTIN / portal username / email only. Never render client_id, client_secret, or tokens.
 * Expects $gst_cred_public (array|null) and optional $gst_cred_action (form action URL).
 */
$gst_cred_public = isset($gst_cred_public) && is_array($gst_cred_public) ? $gst_cred_public : [];
$gst_cred_action = isset($gst_cred_action) ? $gst_cred_action : gst_url('gst-credentials.php');
?>
<form method="POST" action="<?php echo gst_h($gst_cred_action); ?>" class="row">
    <input type="hidden" name="gst_action" value="save_credentials">
    <input type="hidden" name="period" value="<?php echo gst_h($gst_period); ?>">
    <?php if ($gst_location_id > 0): ?>
        <input type="hidden" name="location_id" value="<?php echo (int) $gst_location_id; ?>">
    <?php endif; ?>
    <div class="form-group col-md-4">
        <label>Business GSTIN</label>
        <input type="text" class="form-control text-uppercase" name="gstin" maxlength="15"
               value="<?php echo gst_h($gst_cred_public['gstin'] ?? ''); ?>" required>
    </div>
    <div class="form-group col-md-4">
        <label>GST portal username</label>
        <input type="text" class="form-control" name="gst_username" maxlength="64"
               value="<?php echo gst_h($gst_cred_public['gst_username'] ?? ''); ?>" required>
    </div>
    <div class="form-group col-md-4">
        <label>Registered email</label>
        <input type="email" class="form-control" name="gst_email" maxlength="191"
               value="<?php echo gst_h($gst_cred_public['gst_email'] ?? ''); ?>">
    </div>
    <div class="form-group col-md-4">
        <label>IP address <span class="text-muted small">(optional)</span></label>
        <input type="text" class="form-control" name="ip_address" maxlength="64"
               value="<?php echo gst_h($gst_cred_public['ip_address'] ?? 'auto'); ?>"
               placeholder="auto">
        <small class="form-text text-muted">Use <code>auto</code> to send this server’s IP to the GST API.</small>
    </div>
    <div class="col-12">
        <button type="submit" class="btn btn-primary">Save GST details</button>
        <span class="text-muted small ml-2">Portal API keys stay on the server and are never shown here.</span>
    </div>
</form>
