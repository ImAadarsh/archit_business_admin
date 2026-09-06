<?php
/**
 * Shared bootstrap for GST Filing Center admin pages.
 * Reuses api/gst/config.php + GstFilingController (no second Perione client).
 */
if (defined('GST_ADMIN_INIT')) {
    return;
}
define('GST_ADMIN_INIT', true);

if (!defined('GST_ADMIN_BOOTSTRAP')) {
    define('GST_ADMIN_BOOTSTRAP', true);
}

require_once dirname(__DIR__) . '/admin/connect.php';
require_once dirname(__DIR__) . '/admin/session.php';
require_once dirname(__DIR__) . '/api/gst/config.php';

$gst_db_ok = isset($connect) && $connect instanceof mysqli;
$gst_business_id = (int) ($_SESSION['business_id'] ?? 0);
$gst = ($gst_db_ok && class_exists('GstFilingController'))
    ? new GstFilingController($connect)
    : null;

$gst_period = trim((string) ($_GET['period'] ?? $_POST['period'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}$/', $gst_period)) {
    $gst_period = date('Y-m');
}

$gst_location_id = 0;
if (isset($_POST['location_id']) && $_POST['location_id'] !== '') {
    $gst_location_id = (int) $_POST['location_id'];
} elseif (isset($_GET['location_id']) && $_GET['location_id'] !== '') {
    $gst_location_id = (int) $_GET['location_id'];
}

$gst_itc_filter = strtolower(trim((string) ($_GET['itc'] ?? 'pending')));
if (!in_array($gst_itc_filter, ['all', 'pending', 'approved'], true)) {
    $gst_itc_filter = 'pending';
}

if (!function_exists('gst_inr')) {
    function gst_inr($value)
    {
        return '₹' . number_format((float) $value, 2);
    }

    function gst_h($value)
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }

    function gst_period_label($yyyyMm)
    {
        $ts = strtotime($yyyyMm . '-01');
        return $ts ? date('F Y', $ts) : $yyyyMm;
    }

    function gst_shift_period($yyyyMm, $deltaMonths)
    {
        $ts = strtotime($yyyyMm . '-01');
        if (!$ts) {
            $ts = time();
        }
        return date('Y-m', strtotime((int) $deltaMonths . ' months', $ts));
    }

    function gst_quarter_start($yyyyMm)
    {
        $ts = strtotime($yyyyMm . '-01');
        if (!$ts) {
            $ts = time();
        }
        $month = (int) date('n', $ts);
        if ($month >= 4 && $month <= 6) {
            $q = 4;
        } elseif ($month >= 7 && $month <= 9) {
            $q = 7;
        } elseif ($month >= 10 && $month <= 12) {
            $q = 10;
        } else {
            $q = 1;
        }
        return date('Y', $ts) . '-' . str_pad((string) $q, 2, '0', STR_PAD_LEFT);
    }

    function gst_deadline_hint($yyyyMm)
    {
        $ts = strtotime($yyyyMm . '-01');
        if (!$ts) {
            return '';
        }
        $due = strtotime('first day of next month', $ts);
        $due = strtotime(date('Y-m', $due) . '-11');
        $today = strtotime(date('Y-m-d'));
        $days = (int) round(($due - $today) / 86400);
        $dueLabel = date('j M Y', $due);
        if ($days > 1) {
            return 'GSTR-1 due in ' . $days . ' days (' . $dueLabel . ')';
        }
        if ($days === 1) {
            return 'GSTR-1 due tomorrow (' . $dueLabel . ')';
        }
        if ($days === 0) {
            return 'GSTR-1 due today (' . $dueLabel . ')';
        }
        return 'GSTR-1 due date passed (' . $dueLabel . ') — file ASAP';
    }

    function gst_due_date($yyyyMm, $dayOfNextMonth)
    {
        $ts = strtotime($yyyyMm . '-01');
        if (!$ts) {
            return '';
        }
        $next = strtotime('first day of next month', $ts);
        $stamp = strtotime(date('Y-m', $next) . '-' . str_pad((string) $dayOfNextMonth, 2, '0', STR_PAD_LEFT));
        return $stamp ? date('j M Y', $stamp) : '';
    }

    function gst_format_synced($raw)
    {
        if ($raw === null || $raw === '' || $raw === '0000-00-00 00:00:00') {
            return 'Never synced';
        }
        $ts = strtotime($raw);
        if (!$ts) {
            return 'Never synced';
        }
        $sameDay = date('Y-m-d', $ts) === date('Y-m-d');
        return $sameDay
            ? 'Synced · ' . date('g:i A', $ts)
            : 'Synced · ' . date('j M Y, g:i A', $ts);
    }

    function gst_status_badge($status)
    {
        $s = strtolower(trim((string) $status));
        $map = [
            'filed' => 'badge-success',
            'offset' => 'badge-success',
            'computed' => 'badge-info',
            'prepared' => 'badge-info',
            'ready' => 'badge-info',
            'synced' => 'badge-info',
            'error' => 'badge-danger',
            'pending' => 'badge-warning',
        ];
        $cls = $map[$s] ?? 'badge-secondary';
        $label = $s === '' ? 'pending' : $s;
        return '<span class="badge ' . $cls . '">' . gst_h($label) . '</span>';
    }

    function gst_itc_label($status)
    {
        $s = strtolower(trim((string) $status));
        if ($s === 'matched') {
            return 'Approved';
        }
        if ($s === 'carry') {
            return 'Carry';
        }
        if ($s === 'ghost') {
            return 'Ghost';
        }
        return 'Pending';
    }

    function gst_itc_badge($status)
    {
        $s = strtolower(trim((string) $status));
        $map = [
            'matched' => 'badge-success',
            'carry' => 'badge-info',
            'ghost' => 'badge-danger',
            'pending' => 'badge-warning',
        ];
        $cls = $map[$s] ?? 'badge-secondary';
        return '<span class="badge ' . $cls . '">' . gst_h(gst_itc_label($s)) . '</span>';
    }

    function gst_public_credentials($row)
    {
        if (!is_array($row)) {
            return null;
        }
        unset($row['client_id'], $row['client_secret'], $row['auth_token'], $row['txn'], $row['evc_txn'], $row['gst_password']);
        return $row;
    }

    function gst_portal_state($cred)
    {
        $gstin = trim((string) ($cred['gstin'] ?? ''));
        $user = trim((string) ($cred['gst_username'] ?? ''));
        $expiry = (string) ($cred['token_expiry'] ?? '');
        $token = trim((string) ($cred['auth_token'] ?? ''));
        $connected = $gstin !== '' && $user !== '';
        $authed = $connected && $token !== '' && $expiry !== '' && strtotime($expiry) > (time() + 120);
        return [
            'connected' => $connected,
            'authenticated' => $authed,
            'needs_otp' => $connected && !$authed,
            'gstin' => $gstin,
            'gst_username' => $user,
            'gst_email' => (string) ($cred['gst_email'] ?? ''),
            'token_expiry' => $expiry,
        ];
    }

    function gst_url($page, $extra = [])
    {
        global $gst_period, $gst_location_id, $gst_itc_filter;
        $q = array_merge([
            'period' => $gst_period,
        ], $extra);
        if (!array_key_exists('location_id', $extra) && $gst_location_id > 0) {
            $q['location_id'] = $gst_location_id;
        }
        if ($page === 'gst-filing.php' && !array_key_exists('itc', $extra) && !empty($gst_itc_filter) && $gst_itc_filter !== 'pending') {
            $q['itc'] = $gst_itc_filter;
        }
        foreach ($q as $k => $v) {
            if ($v === '' || $v === null) {
                unset($q[$k]);
            }
        }
        return $page . ($q ? ('?' . http_build_query($q)) : '');
    }

    function gst_flash($ok, $message)
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if ($ok) {
            $_SESSION['gst_flash_success'] = $message;
            unset($_SESSION['gst_flash_error']);
        } else {
            $_SESSION['gst_flash_error'] = $message;
            unset($_SESSION['gst_flash_success']);
        }
    }

    function gst_take_flash()
    {
        $out = ['success' => '', 'error' => ''];
        if (!empty($_SESSION['gst_flash_success'])) {
            $out['success'] = (string) $_SESSION['gst_flash_success'];
            unset($_SESSION['gst_flash_success']);
        }
        if (!empty($_SESSION['gst_flash_error'])) {
            $out['error'] = (string) $_SESSION['gst_flash_error'];
            unset($_SESSION['gst_flash_error']);
        }
        return $out;
    }

    function gst_load_locations($connect, $business_id)
    {
        $rows = [];
        if (!$connect instanceof mysqli || $business_id <= 0) {
            return $rows;
        }
        $chk = @$connect->query("SHOW TABLES LIKE 'locations'");
        if (!$chk || $chk->num_rows === 0) {
            return $rows;
        }
        $stmt = $connect->prepare('SELECT id, location_name FROM locations WHERE business_id = ? ORDER BY location_name ASC');
        if (!$stmt) {
            return $rows;
        }
        $stmt->bind_param('i', $business_id);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $rows[] = $r;
            }
        }
        $stmt->close();
        return $rows;
    }

    function gst_load_api_logs($connect, $business_id, $limit = 10)
    {
        $rows = [];
        if (!$connect instanceof mysqli || $business_id <= 0) {
            return $rows;
        }
        $chk = @$connect->query("SHOW TABLES LIKE 'gst_api_logs'");
        if (!$chk || $chk->num_rows === 0) {
            return $rows;
        }
        $limit = max(1, min(100, (int) $limit));
        $sql = 'SELECT id, period, action, endpoint, method, http_code, status, created_at
                FROM gst_api_logs
                WHERE business_id = ?
                ORDER BY id DESC
                LIMIT ' . $limit;
        $stmt = $connect->prepare($sql);
        if (!$stmt) {
            return $rows;
        }
        $stmt->bind_param('i', $business_id);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $rows[] = $r;
            }
        }
        $stmt->close();
        return $rows;
    }

    function gst_load_return_map($connect, $business_id, array $periods)
    {
        $map = [];
        if (!$connect instanceof mysqli || $business_id <= 0 || !$periods) {
            return $map;
        }
        $chk = @$connect->query("SHOW TABLES LIKE 'gst_return_periods'");
        if (!$chk || $chk->num_rows === 0) {
            return $map;
        }
        $ph = implode(',', array_fill(0, count($periods), '?'));
        $types = 'i' . str_repeat('s', count($periods));
        $sql = "SELECT period, gstr1_status, gstr2b_status, gstr3b_status, last_synced_at
                FROM gst_return_periods
                WHERE business_id = ? AND period IN ($ph)";
        $stmt = $connect->prepare($sql);
        if (!$stmt) {
            return $map;
        }
        $params = array_merge([$business_id], array_values($periods));
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $map[$r['period']] = $r;
            }
        }
        $stmt->close();
        return $map;
    }

    function gst_summary_share($label, $invCount, $taxable, $salesGst, $itcEligible, $net, $carry)
    {
        return "GST Filing summary — {$label}\n"
            . "Sales invoices: {$invCount}\n"
            . 'Taxable: ' . gst_inr($taxable) . "\n"
            . 'Output GST: ' . gst_inr($salesGst) . "\n"
            . 'ITC approved: ' . gst_inr($itcEligible) . "\n"
            . 'Net cash to pay: ' . gst_inr($net) . "\n"
            . 'Carry / pending: ' . gst_inr($carry);
    }

    function gst_redirect_hub()
    {
        header('Location: ' . gst_url('gst-filing.php'));
        exit;
    }

    function gst_redirect($page, array $extra = [])
    {
        header('Location: ' . gst_url($page, $extra));
        exit;
    }

    function gst_ctx_fields()
    {
        global $gst_period, $gst_location_id;
        echo '<input type="hidden" name="period" value="' . gst_h($gst_period) . '">';
        if ($gst_location_id > 0) {
            echo '<input type="hidden" name="location_id" value="' . (int) $gst_location_id . '">';
        }
    }

    function gst_json_exit(array $payload)
    {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    function gst_needs_otp(array $payload)
    {
        if (!empty($payload['needs_otp']) || !empty($payload['otp_sent'])) {
            return true;
        }
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        return !empty($data['needs_otp']) || !empty($data['otp_sent']);
    }

    function gst_needs_auth(array $payload)
    {
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;
        if (!empty($data['auth_required'])) {
            return true;
        }
        $msg = strtolower((string) ($payload['message'] ?? ''));
        return strpos($msg, 'authenticate') !== false
            || strpos($msg, 'session expired') !== false
            || strpos($msg, 'auth token') !== false;
    }

    function gst_pretty_json($raw)
    {
        if (is_array($raw)) {
            return json_encode($raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        $s = (string) $raw;
        if ($s === '') {
            return '';
        }
        $decoded = json_decode($s, true);
        if (is_array($decoded)) {
            return json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        return $s;
    }
}

$gst_locations = $gst_db_ok ? gst_load_locations($connect, $gst_business_id) : [];
$gst_flash = gst_take_flash();
