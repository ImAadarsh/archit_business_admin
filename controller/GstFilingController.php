<?php
/**
 * GST filing: local invoices/expenses → snapshots → Perione GST portal APIs.
 * Response shape matches e-way: {status, message, data}.
 */
class GstFilingController
{
    private $connect;
    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    private const AUTH_VALIDITY_SECONDS = 21600;
    private static $tablesReady = false;
    private static $expenseCols = null;
    private static $invoiceCols = null;

    public function __construct($connect)
    {
        $this->connect = $connect;
        $this->ensureTables();
        $this->ensureGstr1Extras();
    }

    private function dbOk()
    {
        return isset($this->connect) && $this->connect instanceof mysqli;
    }

    /* ------------------------------------------------------------------ */
    /*  HTTP helpers                                                       */
    /* ------------------------------------------------------------------ */

    public function readInput()
    {
        $raw = file_get_contents('php://input');
        $in = json_decode($raw ?: '[]', true);
        if (!is_array($in) || empty($in)) {
            $in = array_merge($_GET, $_POST);
        }
        return $in;
    }

    public function ok($data = [], $message = 'OK')
    {
        return ['status' => 'success', 'message' => $message, 'data' => $data];
    }

    public function err($message, $extra = [])
    {
        $out = ['status' => 'error', 'message' => $message];
        if ($extra) {
            $out = array_merge($out, $extra);
        }
        return $out;
    }

    public function requireBusinessPeriod(array $in, $periodRequired = true)
    {
        $business_id = (int) ($in['business_id'] ?? 0);
        if ($business_id <= 0) {
            return [null, $this->err('business_id is required.')];
        }
        $period = trim((string) ($in['period'] ?? ''));
        if ($periodRequired) {
            if (!preg_match('/^\d{4}-\d{2}$/', $period)) {
                return [null, $this->err('period is required as YYYY-MM.')];
            }
        } elseif ($period !== '' && !preg_match('/^\d{4}-\d{2}$/', $period)) {
            return [null, $this->err('period must be YYYY-MM.')];
        }
        $location_id = isset($in['location_id']) && $in['location_id'] !== '' && $in['location_id'] !== null
            ? (int) $in['location_id'] : 0;
        return [[
            'business_id' => $business_id,
            'period' => $period,
            'location_id' => $location_id,
            'user_id' => (int) ($in['user_id'] ?? 0),
        ], null];
    }

    public function jsonOut(array $payload)
    {
        echo json_encode($payload, self::JSON_FLAGS);
    }

    /* ------------------------------------------------------------------ */
    /*  Schema                                                             */
    /* ------------------------------------------------------------------ */

    private function ensureTables()
    {
        if (self::$tablesReady || !$this->dbOk()) {
            return;
        }
        $chk = @$this->connect->query("SHOW TABLES LIKE 'gst_credentials'");
        if ($chk && $chk->num_rows > 0) {
            self::$tablesReady = true;
            return;
        }
        $sqlFile = dirname(__DIR__) . '/api/gst/gst_filing_tables.sql';
        if (!is_readable($sqlFile)) {
            return;
        }
        $sql = file_get_contents($sqlFile);
        if ($sql === false || $sql === '') {
            return;
        }
        if (@$this->connect->multi_query($sql)) {
            do {
                if ($res = $this->connect->store_result()) {
                    $res->free();
                }
            } while ($this->connect->more_results() && $this->connect->next_result());
        }
        self::$tablesReady = true;
    }

    /**
     * GSTR-1 include/exclude persists across prepare snapshots.
     * gst_gstr1_invoices is wiped on prepare, so exclusions live in their own table.
     */
    private function ensureGstr1Extras()
    {
        if (!$this->dbOk()) {
            return;
        }
        @$this->connect->query(
            "CREATE TABLE IF NOT EXISTS `gst_gstr1_exclusions` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `business_id` int(11) NOT NULL,
              `period` char(7) NOT NULL,
              `invoice_id` int(11) NOT NULL,
              `reason` varchar(255) DEFAULT NULL,
              `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
              PRIMARY KEY (`id`),
              UNIQUE KEY `uq_gstr1_excl` (`business_id`, `period`, `invoice_id`),
              KEY `idx_gstr1_excl_period` (`business_id`, `period`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $cols = $this->tableCols('gst_gstr1_invoices');
        if ($cols && !isset($cols['included'])) {
            @$this->connect->query(
                'ALTER TABLE `gst_gstr1_invoices`
                 ADD COLUMN `included` tinyint(1) NOT NULL DEFAULT 1 AFTER `gst_rate`'
            );
        }
    }

    private function tableCols($table)
    {
        $cols = [];
        if (!$this->dbOk()) {
            return $cols;
        }
        $r = @$this->connect->query('SHOW COLUMNS FROM `' . str_replace('`', '', $table) . '`');
        if ($r) {
            while ($row = $r->fetch_assoc()) {
                $cols[strtolower($row['Field'])] = true;
            }
        }
        return $cols;
    }

    private function expenseHas($col)
    {
        if (self::$expenseCols === null) {
            self::$expenseCols = $this->tableCols('expenses');
        }
        return isset(self::$expenseCols[strtolower($col)]);
    }

    private function invoiceHas($col)
    {
        if (self::$invoiceCols === null) {
            self::$invoiceCols = $this->tableCols('invoices');
        }
        return isset(self::$invoiceCols[strtolower($col)]);
    }

    /* ------------------------------------------------------------------ */
    /*  Period / GSTIN helpers                                             */
    /* ------------------------------------------------------------------ */

    public static function retPeriod($yyyyMm)
    {
        $p = explode('-', $yyyyMm);
        if (count($p) !== 2) {
            return '';
        }
        return $p[1] . $p[0];
    }

    public static function periodRange($yyyyMm)
    {
        $start = $yyyyMm . '-01';
        $end = date('Y-m-t', strtotime($start));
        return [$start, $end];
    }

    public static function periodLabel($yyyyMm)
    {
        $ts = strtotime($yyyyMm . '-01');
        return $ts ? date('F Y', $ts) : $yyyyMm;
    }

    public static function isGstin($value)
    {
        $s = strtoupper(preg_replace('/\s+/', '', (string) $value));
        return (bool) preg_match('/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/', $s);
    }

    public static function stateFromGstin($gstin)
    {
        $s = strtoupper(preg_replace('/\s+/', '', (string) $gstin));
        return strlen($s) >= 2 ? substr($s, 0, 2) : '';
    }

    private function detectServerIp()
    {
        $addr = isset($_SERVER['SERVER_ADDR']) ? trim((string) $_SERVER['SERVER_ADDR']) : '';
        if ($addr !== '' && filter_var($addr, FILTER_VALIDATE_IP)
            && strcasecmp($addr, '0.0.0.0') !== 0 && strcasecmp($addr, '::1') !== 0) {
            return $addr;
        }
        $hn = @gethostname();
        if ($hn) {
            $resolved = @gethostbyname($hn);
            if ($resolved && $resolved !== $hn && filter_var($resolved, FILTER_VALIDATE_IP)) {
                return $resolved;
            }
        }
        return $addr !== '' ? $addr : '0.0.0.0';
    }

    private function effectiveIp(array $cred)
    {
        $stored = isset($cred['ip_address']) ? trim((string) $cred['ip_address']) : '';
        if ($stored === '' || strcasecmp($stored, '0.0.0.0') === 0 || strcasecmp($stored, 'auto') === 0) {
            return $this->detectServerIp();
        }
        return $stored;
    }

    /* ------------------------------------------------------------------ */
    /*  Credentials                                                        */
    /* ------------------------------------------------------------------ */

    public function getCredentials($business_id)
    {
        if (!$this->dbOk()) {
            return null;
        }
        $stmt = $this->connect->prepare('SELECT * FROM gst_credentials WHERE business_id = ? LIMIT 1');
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('i', $business_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            $row = $this->seedCredentialsFromBusiness($business_id);
        }
        if (!$row) {
            return null;
        }
        if (empty($row['client_id']) && defined('GST_PERIONE_CLIENT_ID')) {
            $row['client_id'] = GST_PERIONE_CLIENT_ID;
        }
        if (empty($row['client_secret']) && defined('GST_PERIONE_CLIENT_SECRET')) {
            $row['client_secret'] = GST_PERIONE_CLIENT_SECRET;
        }
        if (empty($row['state_cd']) && !empty($row['gstin'])) {
            $row['state_cd'] = self::stateFromGstin($row['gstin']);
        }
        return $row;
    }

    private function seedCredentialsFromBusiness($business_id)
    {
        $gstin = '';
        $email = '';
        $stmt = $this->connect->prepare('SELECT gst, email FROM businessses WHERE id = ? LIMIT 1');
        if ($stmt) {
            $stmt->bind_param('i', $business_id);
            $stmt->execute();
            $b = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($b) {
                $gstin = strtoupper(preg_replace('/\s+/', '', (string) ($b['gst'] ?? '')));
                $email = (string) ($b['email'] ?? '');
            }
        }
        $username = '';
        $eway = $this->connect->prepare('SELECT gstin, api_email, api_username, ip_address FROM eway_bill_settings WHERE business_id = ? LIMIT 1');
        if ($eway) {
            $eway->bind_param('i', $business_id);
            $eway->execute();
            $ew = $eway->get_result()->fetch_assoc();
            $eway->close();
            if ($ew) {
                if ($gstin === '' && !empty($ew['gstin'])) {
                    $gstin = strtoupper(preg_replace('/\s+/', '', (string) $ew['gstin']));
                }
                if ($email === '' && !empty($ew['api_email'])) {
                    $email = (string) $ew['api_email'];
                }
                $username = (string) ($ew['api_username'] ?? '');
            }
        }
        $state = self::stateFromGstin($gstin);
        $ins = $this->connect->prepare(
            'INSERT INTO gst_credentials (business_id, gstin, gst_username, gst_email, state_cd, ip_address)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE gstin = IF(gstin = \'\', VALUES(gstin), gstin),
               gst_email = IF(gst_email = \'\', VALUES(gst_email), gst_email)'
        );
        if ($ins) {
            $ip = 'auto';
            $ins->bind_param('isssss', $business_id, $gstin, $username, $email, $state, $ip);
            $ins->execute();
            $ins->close();
        }
        $stmt = $this->connect->prepare('SELECT * FROM gst_credentials WHERE business_id = ? LIMIT 1');
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('i', $business_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }

    public function upsertCredentials($business_id, array $fields)
    {
        $this->getCredentials($business_id);
        $allowed = ['gstin', 'gst_username', 'gst_email', 'state_cd', 'ip_address', 'auth_token', 'token_expiry', 'txn', 'evc_txn'];
        $sets = [];
        $types = '';
        $vals = [];
        foreach ($allowed as $k) {
            if (!array_key_exists($k, $fields)) {
                continue;
            }
            $sets[] = "`$k` = ?";
            $types .= 's';
            $vals[] = (string) $fields[$k];
        }
        if (!$sets) {
            return true;
        }
        $types .= 'i';
        $vals[] = $business_id;
        $sql = 'UPDATE gst_credentials SET ' . implode(', ', $sets) . ' WHERE business_id = ?';
        $stmt = $this->connect->prepare($sql);
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param($types, ...$vals);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }

    /* ------------------------------------------------------------------ */
    /*  Period row                                                         */
    /* ------------------------------------------------------------------ */

    public function ensurePeriod($business_id, $period)
    {
        $ret = self::retPeriod($period);
        $sql = 'INSERT INTO gst_return_periods (business_id, period, ret_period)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE ret_period = VALUES(ret_period)';
        $stmt = $this->connect->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('iss', $business_id, $period, $ret);
            $stmt->execute();
            $stmt->close();
        }
        $stmt = $this->connect->prepare('SELECT * FROM gst_return_periods WHERE business_id = ? AND period = ? LIMIT 1');
        $stmt->bind_param('is', $business_id, $period);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row;
    }

    private function updatePeriodStatus($business_id, $period, array $fields)
    {
        $this->ensurePeriod($business_id, $period);
        $sets = [];
        $types = '';
        $vals = [];
        foreach ($fields as $k => $v) {
            $sets[] = "`$k` = ?";
            $types .= 's';
            $vals[] = (string) $v;
        }
        if (!$sets) {
            return;
        }
        $types .= 'is';
        $vals[] = $business_id;
        $vals[] = $period;
        $sql = 'UPDATE gst_return_periods SET ' . implode(', ', $sets) . ' WHERE business_id = ? AND period = ?';
        $stmt = $this->connect->prepare($sql);
        if ($stmt) {
            $stmt->bind_param($types, ...$vals);
            $stmt->execute();
            $stmt->close();
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Audit log                                                          */
    /* ------------------------------------------------------------------ */

    private function logApi($business_id, $period, $action, $endpoint, $method, $httpCode, $status, $request, $response)
    {
        if (!$this->dbOk()) {
            return;
        }
        $req = is_string($request) ? $request : json_encode($this->redact($request), self::JSON_FLAGS);
        $res = is_string($response) ? $response : json_encode($this->redact($response), self::JSON_FLAGS);
        if (strlen($req) > 65000) {
            $req = substr($req, 0, 65000);
        }
        if (strlen($res) > 65000) {
            $res = substr($res, 0, 65000);
        }
        $stmt = $this->connect->prepare(
            'INSERT INTO gst_api_logs (business_id, period, action, endpoint, method, http_code, status, request_json, response_json)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        if (!$stmt) {
            return;
        }
        $bid = (int) $business_id;
        $http = (int) $httpCode;
        $stmt->bind_param(
            'issssisss',
            $bid,
            $period,
            $action,
            $endpoint,
            $method,
            $http,
            $status,
            $req,
            $res
        );
        $stmt->execute();
        $stmt->close();
    }

    private function redact($value)
    {
        if (!is_array($value)) {
            return $value;
        }
        $out = [];
        foreach ($value as $k => $v) {
            $lk = strtolower((string) $k);
            if (in_array($lk, ['client_secret', 'client_id', 'password', 'auth_token', 'auth-token', 'otp'], true)) {
                $s = (string) $v;
                $out[$k] = strlen($s) > 8 ? substr($s, 0, 4) . '…***' : '***';
            } elseif (is_array($v)) {
                $out[$k] = $this->redact($v);
            } else {
                $out[$k] = $v;
            }
        }
        return $out;
    }

    /* ------------------------------------------------------------------ */
    /*  Perione HTTP                                                       */
    /* ------------------------------------------------------------------ */

    private function perioneHeaders(array $cred, $authToken = null)
    {
        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
            'ip_address: ' . $this->effectiveIp($cred),
            'client_id: ' . ($cred['client_id'] ?? (defined('GST_PERIONE_CLIENT_ID') ? GST_PERIONE_CLIENT_ID : '')),
            'client_secret: ' . ($cred['client_secret'] ?? (defined('GST_PERIONE_CLIENT_SECRET') ? GST_PERIONE_CLIENT_SECRET : '')),
            'gstin: ' . ($cred['gstin'] ?? ''),
            'username: ' . ($cred['gst_username'] ?? ''),
            'gst_username: ' . ($cred['gst_username'] ?? ''),
            'state_cd: ' . ($cred['state_cd'] ?? self::stateFromGstin($cred['gstin'] ?? '')),
        ];
        $token = $authToken !== null ? $authToken : ($cred['auth_token'] ?? '');
        if ($token !== '') {
            $headers[] = 'auth-token: ' . $token;
            $headers[] = 'authtoken: ' . $token;
        }
        if (!empty($cred['txn'])) {
            $headers[] = 'txn: ' . $cred['txn'];
        }
        return $headers;
    }

    private function perione($method, $path, array $cred, array $query = [], $body = null, $action = '')
    {
        $logPeriod = (string) ($query['period'] ?? $query['ret_period'] ?? '');
        unset($query['period']);
        $email = $cred['gst_email'] ?? '';
        if ($email !== '' && !isset($query['email'])) {
            $query['email'] = $email;
        }
        if (!empty($cred['gstin']) && !isset($query['gstin'])) {
            $query['gstin'] = $cred['gstin'];
        }
        $url = rtrim(defined('GST_PERIONE_BASE_URL') ? GST_PERIONE_BASE_URL : 'https://api.perione.in', '/') . '/' . ltrim($path, '/');
        if ($query) {
            $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }
        $headers = $this->perioneHeaders($cred);
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        $m = strtoupper($method);
        if ($m === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body === null ? '{}' : (is_string($body) ? $body : json_encode($body, self::JSON_FLAGS)));
        } elseif ($m === 'PUT') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body === null ? '{}' : (is_string($body) ? $body : json_encode($body, self::JSON_FLAGS)));
        }
        $raw = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr = curl_error($ch);
        curl_close($ch);

        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($decoded)) {
            $decoded = [];
        }
        $this->logApi(
            (int) ($cred['business_id'] ?? 0),
            $logPeriod,
            $action !== '' ? $action : $path,
            $this->maskUrl($url),
            $m,
            $http,
            ($http >= 200 && $http < 300) ? 'ok' : 'error',
            ['query' => $query, 'body' => $body],
            $raw !== false ? $raw : $cerr
        );
        return [
            'ok' => $http >= 200 && $http < 300,
            'http_code' => $http,
            'raw' => is_string($raw) ? $raw : '',
            'decoded' => $decoded,
            'curl_error' => $cerr,
        ];
    }

    private function maskUrl($url)
    {
        return preg_replace('/([?&](password|otp|client_secret)=)[^&]*/i', '$1***', (string) $url);
    }

    private function perioneOk(array $res)
    {
        if (!$res['ok']) {
            return false;
        }
        $d = $res['decoded'];
        if (isset($d['status_cd']) && (string) $d['status_cd'] === '0') {
            return false;
        }
        if (isset($d['status']) && is_string($d['status']) && strtolower($d['status']) === 'error') {
            return false;
        }
        return true;
    }

    private function perioneMessage(array $res, $fallback = 'Perione request failed')
    {
        $d = $res['decoded'];
        foreach (['message', 'status_desc', 'error_msg', 'error'] as $k) {
            if (!empty($d[$k]) && is_string($d[$k])) {
                return $d[$k];
            }
        }
        if (!empty($d['error']['message'])) {
            return (string) $d['error']['message'];
        }
        if ($res['curl_error']) {
            return $res['curl_error'];
        }
        if ($res['raw'] !== '') {
            return substr($res['raw'], 0, 400);
        }
        return $fallback . ' (HTTP ' . $res['http_code'] . ')';
    }

    private function tokenValid(array $cred)
    {
        if (empty($cred['auth_token']) || empty($cred['token_expiry'])) {
            return false;
        }
        return strtotime($cred['token_expiry']) > (time() + 120);
    }

    /* ------------------------------------------------------------------ */
    /*  Local sales / purchases                                            */
    /* ------------------------------------------------------------------ */

    public function loadSalesInvoices($business_id, $period, $location_id = 0)
    {
        [$start, $end] = self::periodRange($period);
        $where = 'i.business_id = ? AND i.is_completed = 1 AND DATE(i.invoice_date) BETWEEN ? AND ?';
        $types = 'iss';
        $params = [$business_id, $start, $end];
        if ($this->invoiceHas('type')) {
            $where .= " AND (i.type IS NULL OR i.type = '' OR LOWER(i.type) IN ('normal','credit','debit','credit_note','debit_note','cn','dn','return'))";
        }
        if ($this->invoiceHas('is_cancelled')) {
            $where .= ' AND IFNULL(i.is_cancelled, 0) = 0';
        }
        if ($location_id > 0 && $this->invoiceHas('location_id')) {
            $where .= ' AND i.location_id = ?';
            $types .= 'i';
            $params[] = $location_id;
        }
        $select = 'i.id, i.serial_no, i.name, i.invoice_date, i.type, i.doc_type, i.doc_no,
                   i.total_amount, i.total_cgst, i.total_dgst, i.total_igst, i.total_gst';
        if ($this->invoiceHas('location_id')) {
            $select .= ', i.location_id';
        }
        if ($this->invoiceHas('total_cess')) {
            $select .= ', i.total_cess';
        } elseif ($this->invoiceHas('cess')) {
            $select .= ', i.cess AS total_cess';
        }
        $join = '';
        if ($this->invoiceHas('location_id')) {
            $select .= ', l.location_name';
            $join = ' LEFT JOIN locations l ON l.id = i.location_id';
        }
        $sql = "SELECT $select FROM invoices i $join WHERE $where ORDER BY i.invoice_date ASC, i.id ASC";
        $stmt = $this->connect->prepare($sql);
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        $out = [];
        while ($r = $res->fetch_assoc()) {
            $out[] = $this->mapSalesRow($r, $business_id);
        }
        $stmt->close();
        return $out;
    }

    private function mapSalesRow(array $r, $business_id)
    {
        $cgst = (float) ($r['total_cgst'] ?? 0);
        $sgst = (float) ($r['total_dgst'] ?? 0);
        $igst = (float) ($r['total_igst'] ?? 0);
        $gst = $cgst + $sgst + $igst;
        if ($gst <= 0) {
            $gst = (float) ($r['total_gst'] ?? 0);
        }
        $total = (float) ($r['total_amount'] ?? 0);
        $taxable = round($total - $gst, 2);
        if ($taxable < 0) {
            $taxable = $total;
        }
        $gstin = '';
        if ((int) ($r['doc_type'] ?? 0) === 1 && self::isGstin($r['doc_no'] ?? '')) {
            $gstin = strtoupper(preg_replace('/\s+/', '', (string) $r['doc_no']));
        } elseif (self::isGstin($r['doc_no'] ?? '')) {
            $gstin = strtoupper(preg_replace('/\s+/', '', (string) $r['doc_no']));
        }
        $pos = $gstin !== '' ? self::stateFromGstin($gstin) : $this->homeState($business_id);
        $supply = 'B2CS';
        if ($gstin !== '') {
            $supply = 'B2B';
        } elseif ($igst > 0 && $total > 250000) {
            $supply = 'B2CL';
        }
        $rate = 0.0;
        if ($taxable > 0 && $gst > 0) {
            $rate = round(($gst / $taxable) * 100, 2);
        }
        $date = substr((string) ($r['invoice_date'] ?? ''), 0, 10);
        $cess = (float) ($r['total_cess'] ?? 0);
        $invType = $this->classifyGstr1Type($r, $gstin, $supply, $pos, $igst, $total);
        $posName = self::stateName($pos);
        return [
            'id' => (string) $r['id'],
            'invoice_id' => (int) $r['id'],
            'name' => (string) ($r['name'] ?? ''),
            'invoice_date' => $date,
            'invoiceDate' => $date,
            'type' => (string) ($r['type'] ?? 'normal'),
            'invoice_no' => (string) ($r['serial_no'] ?? $r['id']),
            'customer_gstin' => $gstin,
            'supply_type' => $invType === 'EXP' ? 'EXP' : $supply,
            'invoice_type' => $invType,
            'place_of_supply' => $pos,
            'place_of_supply_name' => $posName,
            'location_id' => (int) ($r['location_id'] ?? 0),
            'location_name' => (string) ($r['location_name'] ?? ''),
            'taxable' => $taxable,
            'amount_wgst' => $taxable,
            'cgst' => $cgst,
            'sgst' => $sgst,
            'igst' => $igst,
            'cess' => $cess,
            'gst' => $gst,
            'tgst' => $gst,
            'total' => $total,
            'total_amount' => $total,
            'gst_rate' => $rate,
            'included' => true,
            'items' => [],
        ];
    }

    private function classifyGstr1Type(array $r, $gstin, $supply, $pos, $igst, $total)
    {
        $type = strtolower(trim((string) ($r['type'] ?? 'normal')));
        $serial = strtoupper((string) ($r['serial_no'] ?? ''));
        $isNote = in_array($type, ['credit', 'debit', 'credit_note', 'debit_note', 'cn', 'dn', 'return'], true)
            || preg_match('/^(CN|DN|CRN|DBN)[-\\/]?/i', $serial);
        if ($isNote) {
            return $gstin !== '' ? 'CDNR' : 'CDNUR';
        }
        if ((string) $pos === '96' || stripos((string) ($r['name'] ?? ''), 'export') !== false) {
            return 'EXP';
        }
        return $supply;
    }

    public static function stateName($code)
    {
        $map = [
            '01' => 'Jammu & Kashmir', '02' => 'Himachal Pradesh', '03' => 'Punjab',
            '04' => 'Chandigarh', '05' => 'Uttarakhand', '06' => 'Haryana',
            '07' => 'Delhi', '08' => 'Rajasthan', '09' => 'Uttar Pradesh',
            '10' => 'Bihar', '11' => 'Sikkim', '12' => 'Arunachal Pradesh',
            '13' => 'Nagaland', '14' => 'Manipur', '15' => 'Mizoram',
            '16' => 'Tripura', '17' => 'Meghalaya', '18' => 'Assam',
            '19' => 'West Bengal', '20' => 'Jharkhand', '21' => 'Odisha',
            '22' => 'Chhattisgarh', '23' => 'Madhya Pradesh', '24' => 'Gujarat',
            '26' => 'Dadra & Nagar Haveli and Daman & Diu', '27' => 'Maharashtra',
            '29' => 'Karnataka', '30' => 'Goa', '31' => 'Lakshadweep',
            '32' => 'Kerala', '33' => 'Tamil Nadu', '34' => 'Puducherry',
            '35' => 'Andaman & Nicobar', '36' => 'Telangana', '37' => 'Andhra Pradesh',
            '38' => 'Ladakh', '97' => 'Other Territory', '96' => 'Foreign / Export',
        ];
        $c = str_pad((string) $code, 2, '0', STR_PAD_LEFT);
        return $map[$c] ?? $c;
    }

    private function homeState($business_id)
    {
        static $cache = [];
        if (isset($cache[$business_id])) {
            return $cache[$business_id];
        }
        $cred = $this->getCredentials($business_id);
        $st = $cred['state_cd'] ?? '';
        if ($st === '' && !empty($cred['gstin'])) {
            $st = self::stateFromGstin($cred['gstin']);
        }
        $cache[$business_id] = $st !== '' ? $st : '07';
        return $cache[$business_id];
    }

    public function loadPurchaseExpenses($business_id, $period, $location_id = 0)
    {
        [$start, $end] = self::periodRange($period);
        $dateExpr = $this->expenseHas('expense_date')
            ? 'COALESCE(e.expense_date, e.created_at)'
            : 'e.created_at';
        $where = "e.business_id = ? AND DATE($dateExpr) BETWEEN ? AND ?";
        $types = 'iss';
        $params = [$business_id, $start, $end];
        if ($location_id > 0) {
            $where .= ' AND e.location_id = ?';
            $types .= 'i';
            $params[] = $location_id;
        }
        $select = "e.id, e.name, e.amount, e.created_at, $dateExpr AS expense_date";
        foreach ([
            'paid_to', 'gst_number', 'taxable_amount', 'gst_amount',
            'invoice_no', 'bill_no', 'invoice_number',
            'cgst', 'sgst', 'igst', 'cgst_amount', 'sgst_amount', 'igst_amount',
        ] as $c) {
            if ($this->expenseHas($c)) {
                $select .= ", e.`$c`";
            }
        }
        $sql = "SELECT $select FROM expenses e WHERE $where ORDER BY expense_date ASC, e.id ASC";
        $stmt = $this->connect->prepare($sql);
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        $out = [];
        while ($r = $res->fetch_assoc()) {
            $mapped = $this->mapExpenseRow($r);
            if ($mapped === null) {
                continue;
            }
            $out[] = $mapped;
        }
        $stmt->close();
        return $out;
    }

    private function mapExpenseRow(array $r)
    {
        $gstAmt = (float) ($r['gst_amount'] ?? 0);
        $taxable = isset($r['taxable_amount']) && $r['taxable_amount'] !== null && $r['taxable_amount'] !== ''
            ? (float) $r['taxable_amount'] : 0.0;
        $amount = (float) ($r['amount'] ?? 0);
        $gstin = strtoupper(preg_replace('/\s+/', '', (string) ($r['gst_number'] ?? '')));
        if ($gstAmt <= 0 && $taxable <= 0 && $gstin === '') {
            return null;
        }
        if ($taxable <= 0 && $gstAmt > 0 && $amount > $gstAmt) {
            $taxable = round($amount - $gstAmt, 2);
        }
        $date = substr((string) ($r['expense_date'] ?? $r['created_at'] ?? ''), 0, 10);
        $invoiceNo = '';
        foreach (['invoice_no', 'bill_no', 'invoice_number'] as $k) {
            if (!empty($r[$k])) {
                $invoiceNo = trim((string) $r[$k]);
                break;
            }
        }
        if ($invoiceNo === '' && !empty($r['name'])) {
            $invoiceNo = trim((string) $r['name']);
        }
        $cgst = (float) ($r['cgst'] ?? $r['cgst_amount'] ?? 0);
        $sgst = (float) ($r['sgst'] ?? $r['sgst_amount'] ?? 0);
        $igst = (float) ($r['igst'] ?? $r['igst_amount'] ?? 0);
        if ($cgst + $sgst + $igst <= 0 && $gstAmt > 0) {
            $cgst = $sgst = $igst = 0.0;
        }
        return [
            'id' => (string) $r['id'],
            'expense_id' => (int) $r['id'],
            'name' => (string) ($r['name'] ?? ''),
            'paid_to' => (string) ($r['paid_to'] ?? ''),
            'paidTo' => (string) ($r['paid_to'] ?? ''),
            'gstin' => $gstin,
            'invoice_no' => $invoiceNo,
            'date' => $date,
            'taxable' => $taxable,
            'cgst' => round($cgst, 2),
            'sgst' => round($sgst, 2),
            'igst' => round($igst, 2),
            'gst_claimed' => $gstAmt,
            'gstClaimed' => $gstAmt,
            'amount' => $amount > 0 ? $amount : round($taxable + $gstAmt, 2),
            'status' => 'pending',
        ];
    }

    private function attachItcStatus($business_id, $period, array $rows)
    {
        $map = $this->itcStatusMap($business_id, $period);
        foreach ($rows as &$row) {
            $key = (string) $row['id'];
            if (isset($map['expense'][$key])) {
                $row['status'] = $map['expense'][$key]['status'];
                $row['notes'] = (string) ($map['expense'][$key]['notes'] ?? '');
                $row['gst_portal'] = (float) ($map['expense'][$key]['gst_portal'] ?? 0);
                $row['gstr2b_id'] = (int) ($map['expense'][$key]['gstr2b_id'] ?? 0);
            }
        }
        unset($row);
        foreach ($map['ghost'] ?? [] as $g) {
            if ($this->isDismissedGhost($g)) {
                continue;
            }
            $portalTax = (float) ($g['gst_portal'] ?: $g['gst_claimed']);
            $rows[] = [
                'id' => $g['source_ref'],
                'expense_id' => 0,
                'name' => $g['vendor_name'] ?: 'Unmatched portal credit',
                'paid_to' => $g['vendor_name'] ?: 'Unknown vendor',
                'paidTo' => $g['vendor_name'] ?: 'Unknown vendor',
                'gstin' => (string) ($g['vendor_gstin'] ?? ''),
                'invoice_no' => $this->ghostInvoiceNo($g),
                'date' => (string) ($g['doc_date'] ?? $period . '-01'),
                'taxable' => (float) $g['taxable'],
                'cgst' => 0.0,
                'sgst' => 0.0,
                'igst' => 0.0,
                'gst_claimed' => $portalTax,
                'gstClaimed' => $portalTax,
                'amount' => (float) $g['taxable'] + $portalTax,
                'status' => 'ghost',
                'notes' => (string) ($g['notes'] ?? ''),
                'gst_portal' => $portalTax,
            ];
        }
        return $rows;
    }

    private function isDismissedGhost(array $g)
    {
        $notes = strtoupper((string) ($g['notes'] ?? ''));
        return strpos($notes, 'DISMISSED') === 0;
    }

    private function ghostInvoiceNo(array $g)
    {
        $notes = (string) ($g['notes'] ?? '');
        if (preg_match('/INV:([^\s|]+)/', $notes, $m)) {
            return $m[1];
        }
        return '';
    }

    private function itcStatusMap($business_id, $period)
    {
        $out = ['expense' => [], 'ghost' => []];
        $stmt = $this->connect->prepare(
            'SELECT * FROM gst_itc_reconcile WHERE business_id = ? AND period = ?'
        );
        if (!$stmt) {
            return $out;
        }
        $stmt->bind_param('is', $business_id, $period);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) {
            if ($r['source'] === 'ghost' || $r['status'] === 'ghost') {
                $out['ghost'][] = $r;
            } else {
                $out['expense'][(string) $r['source_ref']] = $r;
            }
        }
        $stmt->close();
        return $out;
    }

    /* ------------------------------------------------------------------ */
    /*  Auth                                                               */
    /* ------------------------------------------------------------------ */

    public function authOtp(array $in)
    {
        [$ctx, $err] = $this->requireBusinessPeriod($in, false);
        if ($err) {
            return $err;
        }
        $business_id = $ctx['business_id'];
        $fields = [];
        if (!empty($in['gstin'])) {
            $fields['gstin'] = strtoupper(preg_replace('/\s+/', '', (string) $in['gstin']));
            $fields['state_cd'] = self::stateFromGstin($fields['gstin']);
        }
        if (!empty($in['gst_username'])) {
            $fields['gst_username'] = trim((string) $in['gst_username']);
        }
        if (!empty($in['email']) || !empty($in['gst_email'])) {
            $fields['gst_email'] = trim((string) ($in['email'] ?? $in['gst_email']));
        }
        if ($fields) {
            $this->upsertCredentials($business_id, $fields);
        }
        $cred = $this->getCredentials($business_id);
        if (!$cred || $cred['gstin'] === '' || $cred['gst_username'] === '') {
            return $this->err('GSTIN and gst_username are required. Pass them once to auth-otp.php or save gst_credentials.');
        }
        $res = $this->perione('POST', 'authentication/otprequest', $cred, [], [
            'gstin' => $cred['gstin'],
            'username' => $cred['gst_username'],
            'state_cd' => $cred['state_cd'],
        ], 'auth-otp');
        if (!empty($res['decoded']['header']['txn'])) {
            $this->upsertCredentials($business_id, ['txn' => $res['decoded']['header']['txn']]);
        } elseif (!empty($res['decoded']['txn'])) {
            $this->upsertCredentials($business_id, ['txn' => $res['decoded']['txn']]);
        }
        if (!$this->perioneOk($res)) {
            return $this->err($this->perioneMessage($res, 'OTP request failed'), ['portal' => $res['decoded']]);
        }
        return $this->ok([
            'otp_sent' => true,
            'gstin' => $cred['gstin'],
            'needs_otp' => true,
        ], 'OTP sent to GST-registered mobile/email.');
    }

    public function authVerify(array $in)
    {
        [$ctx, $err] = $this->requireBusinessPeriod($in, false);
        if ($err) {
            return $err;
        }
        $otp = trim((string) ($in['otp'] ?? ''));
        if ($otp === '') {
            return $this->err('otp is required.');
        }
        $business_id = $ctx['business_id'];
        $cred = $this->getCredentials($business_id);
        if (!$cred) {
            return $this->err('GST credentials not found. Call auth-otp.php first.');
        }
        $res = $this->perione('POST', 'authentication/authtoken', $cred, ['otp' => $otp], [
            'gstin' => $cred['gstin'],
            'username' => $cred['gst_username'],
            'otp' => $otp,
            'state_cd' => $cred['state_cd'],
        ], 'auth-verify');
        if (!$this->perioneOk($res)) {
            return $this->err($this->perioneMessage($res, 'OTP verification failed'), ['portal' => $res['decoded']]);
        }
        $d = $res['decoded'];
        $token = (string) ($d['auth_token'] ?? $d['token'] ?? $d['data']['auth_token'] ?? '');
        if ($token === '' && !empty($d['header']['auth-token'])) {
            $token = (string) $d['header']['auth-token'];
        }
        $expiry = date('Y-m-d H:i:s', time() + self::AUTH_VALIDITY_SECONDS);
        $upd = ['auth_token' => $token, 'token_expiry' => $expiry];
        if (!empty($d['txn']) || !empty($d['header']['txn'])) {
            $upd['txn'] = (string) ($d['txn'] ?? $d['header']['txn']);
        }
        $this->upsertCredentials($business_id, $upd);
        return $this->ok([
            'authenticated' => $token !== '',
            'token_expiry' => $expiry,
            'gstin' => $cred['gstin'],
        ], 'GST session authenticated.');
    }

    /* ------------------------------------------------------------------ */
    /*  Period summary                                                     */
    /* ------------------------------------------------------------------ */

    public function getPeriodSummary(array $in)
    {
        [$ctx, $err] = $this->requireBusinessPeriod($in);
        if ($err) {
            return $err;
        }
        $sales = $this->loadSalesInvoices($ctx['business_id'], $ctx['period'], $ctx['location_id']);
        $purch = $this->attachItcStatus(
            $ctx['business_id'],
            $ctx['period'],
            $this->loadPurchaseExpenses($ctx['business_id'], $ctx['period'], $ctx['location_id'])
        );
        $taxable = $salesGst = 0.0;
        foreach ($sales as $r) {
            $taxable += $r['taxable'];
            $salesGst += $r['gst'];
        }
        $claimed = $eligible = $pendingAmt = $carryAmt = 0.0;
        $countAll = $countPending = $countApproved = 0;
        $pendingRows = [];
        foreach ($purch as $r) {
            if (($r['status'] ?? '') === 'ghost') {
                continue;
            }
            $countAll++;
            $claimed += $r['gstClaimed'];
            if (($r['status'] ?? '') === 'matched') {
                $eligible += $r['gstClaimed'];
                $countApproved++;
            } elseif (($r['status'] ?? '') === 'carry') {
                $carryAmt += $r['gstClaimed'];
            } else {
                $pendingAmt += $r['gstClaimed'];
                $carryAmt += $r['gstClaimed'];
                $countPending++;
                if (count($pendingRows) < 3) {
                    $pendingRows[] = $r;
                }
            }
        }
        $net = max(0, round($salesGst - $eligible, 2));
        $periodRow = $this->ensurePeriod($ctx['business_id'], $ctx['period']);
        $unlocked = count($sales) > 0 && $countPending === 0;
        return $this->ok([
            'period' => $ctx['period'],
            'period_label' => self::periodLabel($ctx['period']),
            'ret_period' => self::retPeriod($ctx['period']),
            'invoice_count' => count($sales),
            'taxable' => round($taxable, 2),
            'sales_gst' => round($salesGst, 2),
            'itc_claimed' => round($claimed, 2),
            'itc_eligible' => round($eligible, 2),
            'itc_pending' => round($pendingAmt, 2),
            'itc_carry' => round($carryAmt, 2),
            'net_cash' => $net,
            'carry_forward' => round($carryAmt, 2),
            'counts' => [
                'all' => $countAll,
                'pending' => $countPending,
                'approved' => $countApproved,
            ],
            'pending_mini' => $pendingRows,
            'gstr1_status' => $periodRow['gstr1_status'] ?? 'pending',
            'gstr2b_status' => $periodRow['gstr2b_status'] ?? 'pending',
            'gstr3b_status' => $periodRow['gstr3b_status'] ?? 'pending',
            'last_synced_at' => $periodRow['last_synced_at'] ?? null,
            'filing_unlocked' => $unlocked,
            'auth_required' => !$this->tokenValid($this->getCredentials($ctx['business_id']) ?: []),
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  GSTR-1                                                             */
    /* ------------------------------------------------------------------ */

    public function getGstr1(array $in)
    {
        [$ctx, $err] = $this->requireBusinessPeriod($in);
        if ($err) {
            return $err;
        }
        $rows = $this->attachGstr1Details($ctx['business_id'], $ctx['period'], $ctx['location_id']);
        $included = array_values(array_filter($rows, function ($r) {
            return !empty($r['included']);
        }));
        $payload = $this->buildGstr1Payload($ctx['business_id'], $ctx['period'], $included);
        $periodRow = $this->ensurePeriod($ctx['business_id'], $ctx['period']);
        $cred = $this->getCredentials($ctx['business_id']);
        $gstin = $cred['gstin'] ?? '';
        return $this->ok([
            'period' => $ctx['period'],
            'period_label' => self::periodLabel($ctx['period']),
            'ret_period' => self::retPeriod($ctx['period']),
            'gstin' => $gstin,
            'invoices' => $rows,
            'totals' => $this->sumGstr1Rows($included),
            'totals_all' => $this->sumGstr1Rows($rows),
            'sections' => $this->gstr1SectionSummaries($rows),
            'hsn' => $this->buildHsnSummary($included),
            'docs_issued' => $this->buildDocsIssued($included),
            'payload' => $payload,
            'gstr1_status' => $periodRow['gstr1_status'] ?? 'pending',
            'gstr1_ref_id' => $periodRow['gstr1_ref_id'] ?? null,
            'gstr1_filed_at' => $periodRow['gstr1_filed_at'] ?? null,
            'last_synced_at' => $periodRow['last_synced_at'] ?? null,
            'auth_required' => !$this->tokenValid($cred ?: []),
            'source' => 'local',
            'excluded_count' => count($rows) - count($included),
        ]);
    }

    public function prepareGstr1(array $in)
    {
        [$ctx, $err] = $this->requireBusinessPeriod($in);
        if ($err) {
            return $err;
        }
        $all = $this->attachGstr1Details($ctx['business_id'], $ctx['period'], $ctx['location_id']);
        $rows = array_values(array_filter($all, function ($r) {
            return !empty($r['included']);
        }));
        $this->snapshotGstr1($ctx['business_id'], $ctx['period'], $all);
        $payload = $this->buildGstr1Payload($ctx['business_id'], $ctx['period'], $rows);
        $this->updatePeriodStatus($ctx['business_id'], $ctx['period'], [
            'gstr1_status' => 'prepared',
            'last_synced_at' => date('Y-m-d H:i:s'),
        ]);
        $push = !empty($in['push_portal']) || !empty($in['save_portal']);
        $portal = null;
        if ($push) {
            $cred = $this->getCredentials($ctx['business_id']);
            if (!$cred || !$this->tokenValid($cred)) {
                return $this->err('GST session expired. Call auth-otp.php then auth-verify.php.', [
                    'data' => [
                        'payload' => $payload,
                        'needs_auth' => true,
                        'auth_required' => true,
                    ],
                ]);
            }
            $res = $this->perione(
                'PUT',
                'gstr1/save',
                $cred,
                ['ret_period' => self::retPeriod($ctx['period']), 'period' => $ctx['period']],
                $payload,
                'prepare-gstr1'
            );
            $portal = $res['decoded'];
            $ref = $this->extractRef($res['decoded']);
            $errors = $this->extractPortalErrors($res['decoded']);
            $this->updatePeriodStatus($ctx['business_id'], $ctx['period'], [
                'gstr1_status' => $this->perioneOk($res) ? 'prepared' : 'error',
                'gstr1_ref_id' => $ref ?: ($this->ensurePeriod($ctx['business_id'], $ctx['period'])['gstr1_ref_id'] ?? ''),
            ]);
            if (!$this->perioneOk($res)) {
                return $this->err($this->perioneMessage($res, 'GSTR-1 save failed'), [
                    'data' => [
                        'payload' => $payload,
                        'portal' => $portal,
                        'errors' => $errors,
                    ],
                ]);
            }
        }
        return $this->ok([
            'period' => $ctx['period'],
            'invoice_count' => count($rows),
            'excluded_count' => count($all) - count($rows),
            'payload' => $payload,
            'portal' => $portal,
            'gstr1_status' => 'prepared',
            'totals' => $this->sumGstr1Rows($rows),
        ], $push
            ? 'GSTR-1 saved to GST portal.'
            : 'GSTR-1 snapshot prepared from sales invoices.');
    }

    public function fileGstr1(array $in)
    {
        [$ctx, $err] = $this->requireBusinessPeriod($in);
        if ($err) {
            return $err;
        }
        $cred = $this->getCredentials($ctx['business_id']);
        if (!$cred || !$this->tokenValid($cred)) {
            return $this->err('GST session expired. Authenticate first (auth-otp / auth-verify).', [
                'data' => ['auth_required' => true],
            ]);
        }
        $otp = trim((string) ($in['otp'] ?? ''));
        $ret = self::retPeriod($ctx['period']);
        if ($otp === '') {
            $res = $this->perione('GET', 'authentication/otpforevc', $cred, [
                'ret_period' => $ret,
                'form_type' => 'R1',
                'period' => $ctx['period'],
            ], null, 'gstr1-otp-evc');
            if (!$this->perioneOk($res)) {
                $errors = $this->extractPortalErrors($res['decoded']);
                $needsAuth = $this->portalNeedsAuth($res['decoded']);
                return $this->err($this->perioneMessage($res, 'EVC OTP failed'), [
                    'portal' => $res['decoded'],
                    'data' => [
                        'errors' => $errors,
                        'auth_required' => $needsAuth,
                        'needs_otp' => !$needsAuth,
                    ],
                ]);
            }
            return $this->ok([
                'needs_otp' => true,
                'form' => 'GSTR1',
            ], 'EVC OTP sent. Call file-gstr1.php again with otp.');
        }
        $body = [
            'gstin' => $cred['gstin'],
            'ret_period' => $ret,
            'otp' => $otp,
            'st' => $in['st'] ?? 'EVC',
            'sid' => $in['sid'] ?? ($cred['gstin'] ?? ''),
        ];
        if (!empty($in['isnil'])) {
            $body['isnil'] = $in['isnil'];
        }
        $res = $this->perione('POST', 'gstr1/retevcfile', $cred, [
            'ret_period' => $ret,
            'period' => $ctx['period'],
        ], $body, 'file-gstr1');
        if (!$this->perioneOk($res)) {
            $res2 = $this->perione('POST', 'gstr1/retfile', $cred, [
                'ret_period' => $ret,
                'period' => $ctx['period'],
            ], $body, 'file-gstr1-retfile');
            if (!$this->perioneOk($res2)) {
                $this->updatePeriodStatus($ctx['business_id'], $ctx['period'], ['gstr1_status' => 'error']);
                $decoded = $res2['decoded'] ?: $res['decoded'];
                return $this->err($this->perioneMessage($res2, $this->perioneMessage($res, 'GSTR-1 file failed')), [
                    'portal' => $decoded,
                    'data' => [
                        'errors' => $this->extractPortalErrors($decoded),
                        'auth_required' => $this->portalNeedsAuth($decoded),
                    ],
                ]);
            }
            $res = $res2;
        }
        $ref = $this->extractRef($res['decoded']);
        $arn = $this->extractArn($res['decoded']);
        $this->updatePeriodStatus($ctx['business_id'], $ctx['period'], [
            'gstr1_status' => 'filed',
            'gstr1_ref_id' => $ref ?: $arn,
            'gstr1_filed_at' => date('Y-m-d H:i:s'),
        ]);
        return $this->ok([
            'filed' => true,
            'ref_id' => $ref,
            'arn' => $arn,
            'portal' => $res['decoded'],
        ], 'GSTR-1 filed.');
    }

    private function snapshotGstr1($business_id, $period, array $rows)
    {
        $del = $this->connect->prepare('DELETE FROM gst_gstr1_invoices WHERE business_id = ? AND period = ?');
        if ($del) {
            $del->bind_param('is', $business_id, $period);
            $del->execute();
            $del->close();
        }
        $sql = 'INSERT INTO gst_gstr1_invoices
            (business_id, period, invoice_id, invoice_no, invoice_date, customer_name, customer_gstin,
             supply_type, place_of_supply, taxable, cgst, sgst, igst, cess, total, gst_rate, included, snapshot_json)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)';
        $stmt = $this->connect->prepare($sql);
        if (!$stmt) {
            $sql = 'INSERT INTO gst_gstr1_invoices
                (business_id, period, invoice_id, invoice_no, invoice_date, customer_name, customer_gstin,
                 supply_type, place_of_supply, taxable, cgst, sgst, igst, cess, total, gst_rate, snapshot_json)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)';
            $stmt = $this->connect->prepare($sql);
            if (!$stmt) {
                return;
            }
            $legacy = true;
        } else {
            $legacy = false;
        }
        foreach ($rows as $r) {
            $snap = json_encode($r, self::JSON_FLAGS);
            $cess = (float) ($r['cess'] ?? 0);
            $invId = (int) $r['invoice_id'];
            $invNo = (string) $r['invoice_no'];
            $invDate = $r['invoice_date'] ?: null;
            $name = $r['name'];
            $gstin = $r['customer_gstin'];
            $supply = $r['supply_type'];
            $pos = $r['place_of_supply'];
            $taxable = $r['taxable'];
            $cgst = $r['cgst'];
            $sgst = $r['sgst'];
            $igst = $r['igst'];
            $total = $r['total'];
            $rate = $r['gst_rate'];
            $included = !empty($r['included']) ? 1 : 0;
            if ($legacy) {
                $stmt->bind_param(
                    'isissssssddddddds',
                    $business_id,
                    $period,
                    $invId,
                    $invNo,
                    $invDate,
                    $name,
                    $gstin,
                    $supply,
                    $pos,
                    $taxable,
                    $cgst,
                    $sgst,
                    $igst,
                    $cess,
                    $total,
                    $rate,
                    $snap
                );
            } else {
                $stmt->bind_param(
                    'isissssssdddddddis',
                    $business_id,
                    $period,
                    $invId,
                    $invNo,
                    $invDate,
                    $name,
                    $gstin,
                    $supply,
                    $pos,
                    $taxable,
                    $cgst,
                    $sgst,
                    $igst,
                    $cess,
                    $total,
                    $rate,
                    $included,
                    $snap
                );
            }
            $stmt->execute();
        }
        $stmt->close();
    }

    private function buildGstr1Payload($business_id, $period, array $rows)
    {
        $cred = $this->getCredentials($business_id);
        $gstin = $cred['gstin'] ?? '';
        $fp = self::retPeriod($period);
        $b2bMap = [];
        $b2csMap = [];
        $b2cl = [];
        $cdnrMap = [];
        $cdnur = [];
        $exp = [];
        foreach ($rows as $r) {
            if (isset($r['included']) && empty($r['included'])) {
                continue;
            }
            $idt = $r['invoice_date'] ? date('d-m-Y', strtotime($r['invoice_date'])) : '';
            $item = [
                'num' => 1,
                'itm_det' => [
                    'rt' => (float) $r['gst_rate'],
                    'txval' => (float) $r['taxable'],
                    'iamt' => (float) $r['igst'],
                    'camt' => (float) $r['cgst'],
                    'samt' => (float) $r['sgst'],
                    'csamt' => (float) ($r['cess'] ?? 0),
                ],
            ];
            $section = $r['invoice_type'] ?? $r['supply_type'];
            if ($section === 'CDNR') {
                $ctin = $r['customer_gstin'];
                if (!isset($cdnrMap[$ctin])) {
                    $cdnrMap[$ctin] = ['ctin' => $ctin, 'nt' => []];
                }
                $cdnrMap[$ctin]['nt'][] = [
                    'ntty' => stripos((string) ($r['type'] ?? ''), 'd') === 0 || stripos((string) ($r['invoice_no'] ?? ''), 'DN') === 0 ? 'D' : 'C',
                    'nt_num' => (string) $r['invoice_no'],
                    'nt_dt' => $idt,
                    'val' => (float) $r['total'],
                    'pos' => (string) $r['place_of_supply'],
                    'rchrg' => 'N',
                    'inv_typ' => 'R',
                    'itms' => [$item],
                ];
                continue;
            }
            if ($section === 'CDNUR') {
                $cdnur[] = [
                    'ntty' => 'C',
                    'nt_num' => (string) $r['invoice_no'],
                    'nt_dt' => $idt,
                    'val' => (float) $r['total'],
                    'pos' => (string) $r['place_of_supply'],
                    'typ' => $r['igst'] > 0 ? 'B2CL' : 'B2CS',
                    'itms' => [$item],
                ];
                continue;
            }
            if ($section === 'EXP') {
                $exp[] = [
                    'exp_typ' => $r['igst'] > 0 ? 'WPAY' : 'WOPAY',
                    'inv' => [[
                        'inum' => (string) $r['invoice_no'],
                        'idt' => $idt,
                        'val' => (float) $r['total'],
                        'sbpcode' => '',
                        'itms' => [$item],
                    ]],
                ];
                continue;
            }
            if ($r['supply_type'] === 'B2B' || $section === 'B2B') {
                $ctin = $r['customer_gstin'];
                if (!isset($b2bMap[$ctin])) {
                    $b2bMap[$ctin] = ['ctin' => $ctin, 'inv' => []];
                }
                $b2bMap[$ctin]['inv'][] = [
                    'inum' => (string) $r['invoice_no'],
                    'idt' => $idt,
                    'val' => (float) $r['total'],
                    'pos' => (string) $r['place_of_supply'],
                    'rchrg' => 'N',
                    'inv_typ' => 'R',
                    'itms' => [$item],
                ];
            } elseif ($r['supply_type'] === 'B2CL') {
                $b2cl[] = [
                    'inum' => (string) $r['invoice_no'],
                    'idt' => $idt,
                    'val' => (float) $r['total'],
                    'pos' => (string) $r['place_of_supply'],
                    'itms' => [$item],
                ];
            } else {
                $key = $r['place_of_supply'] . '|' . $r['gst_rate'] . '|' . ($r['igst'] > 0 ? 'INTER' : 'INTRA');
                if (!isset($b2csMap[$key])) {
                    $b2csMap[$key] = [
                        'sply_ty' => $r['igst'] > 0 ? 'INTER' : 'INTRA',
                        'pos' => (string) $r['place_of_supply'],
                        'rt' => (float) $r['gst_rate'],
                        'txval' => 0,
                        'camt' => 0,
                        'samt' => 0,
                        'iamt' => 0,
                        'csamt' => 0,
                    ];
                }
                $b2csMap[$key]['txval'] += $r['taxable'];
                $b2csMap[$key]['camt'] += $r['cgst'];
                $b2csMap[$key]['samt'] += $r['sgst'];
                $b2csMap[$key]['iamt'] += $r['igst'];
            }
        }
        $payload = [
            'gstin' => $gstin,
            'fp' => $fp,
            'gt' => 0,
            'cur_gt' => 0,
        ];
        if ($b2bMap) {
            $payload['b2b'] = array_values($b2bMap);
        }
        if ($b2csMap) {
            $payload['b2cs'] = array_values($b2csMap);
        }
        if ($b2cl) {
            $payload['b2cl'] = $b2cl;
        }
        if ($cdnrMap) {
            $payload['cdnr'] = array_values($cdnrMap);
        }
        if ($cdnur) {
            $payload['cdnur'] = $cdnur;
        }
        if ($exp) {
            $payload['exp'] = $exp;
        }
        $hsn = $this->buildHsnSummary($rows);
        if ($hsn) {
            $payload['hsn'] = ['data' => $hsn];
        }
        $docs = $this->buildDocsIssued($rows);
        if ($docs) {
            $payload['doc_issue'] = ['doc_det' => $docs];
        }
        if (!$b2bMap && !$b2csMap && !$b2cl && !$cdnrMap && !$cdnur && !$exp) {
            $payload['nil'] = [
                'inv' => [
                    ['sply_ty' => 'INTRB2B', 'expt_amt' => 0, 'nil_amt' => 0, 'ngsup_amt' => 0],
                    ['sply_ty' => 'INTRB2C', 'expt_amt' => 0, 'nil_amt' => 0, 'ngsup_amt' => 0],
                    ['sply_ty' => 'INTRAB2B', 'expt_amt' => 0, 'nil_amt' => 0, 'ngsup_amt' => 0],
                    ['sply_ty' => 'INTRAB2C', 'expt_amt' => 0, 'nil_amt' => 0, 'ngsup_amt' => 0],
                ],
            ];
        }
        return $payload;
    }

    private function extractRef(array $decoded)
    {
        foreach (['reference_id', 'ref_id', 'referenceno', 'ReferenceId'] as $k) {
            if (!empty($decoded[$k])) {
                return (string) $decoded[$k];
            }
        }
        if (!empty($decoded['data']['reference_id'])) {
            return (string) $decoded['data']['reference_id'];
        }
        if (!empty($decoded['header']['txn'])) {
            return (string) $decoded['header']['txn'];
        }
        return '';
    }

    private function extractArn($decoded)
    {
        if (!is_array($decoded)) {
            return '';
        }
        return $this->findFirstValue($decoded, ['arn', 'ARN', 'ack_num', 'ackNum', 'ack_no', 'AckNum']);
    }

    private function portalNeedsAuth(array $decoded)
    {
        $blob = strtolower(json_encode($decoded));
        foreach (['invalid token', 'auth token', 'session expired', 'authtoken', 'unauthenticated', 'otp expired'] as $n) {
            if (strpos($blob, $n) !== false) {
                return true;
            }
        }
        return false;
    }

    private function extractPortalErrors($decoded)
    {
        $errors = [];
        if (!is_array($decoded)) {
            return $errors;
        }
        $push = function ($code, $msg) use (&$errors) {
            $msg = trim((string) $msg);
            if ($msg === '') {
                return;
            }
            $errors[] = [
                'code' => (string) $code,
                'message' => $msg,
            ];
        };
        if (!empty($decoded['error_cd']) || !empty($decoded['error_msg'])) {
            $push($decoded['error_cd'] ?? '', $decoded['error_msg'] ?? $decoded['message'] ?? '');
        }
        if (!empty($decoded['status_desc']) && (string) ($decoded['status_cd'] ?? '') === '0') {
            $push($decoded['error_cd'] ?? 'GSTN', $decoded['status_desc']);
        }
        foreach (['error_report', 'error', 'errors', 'error_list'] as $k) {
            if (empty($decoded[$k])) {
                continue;
            }
            $block = $decoded[$k];
            if (is_string($block)) {
                $push('', $block);
                continue;
            }
            if (!is_array($block)) {
                continue;
            }
            if (isset($block['message'])) {
                $push($block['error_cd'] ?? $block['code'] ?? '', $block['message']);
            }
            foreach ($block as $row) {
                if (is_string($row)) {
                    $push('', $row);
                } elseif (is_array($row)) {
                    $push(
                        $row['error_cd'] ?? $row['code'] ?? $row['error_code'] ?? '',
                        $row['error_msg'] ?? $row['message'] ?? $row['msg'] ?? json_encode($row)
                    );
                }
            }
        }
        if (!empty($decoded['data']) && is_array($decoded['data'])) {
            foreach ($this->extractPortalErrors($decoded['data']) as $e) {
                $errors[] = $e;
            }
        }
        $uniq = [];
        $out = [];
        foreach ($errors as $e) {
            $key = $e['code'] . '|' . $e['message'];
            if (isset($uniq[$key])) {
                continue;
            }
            $uniq[$key] = true;
            $out[] = $e;
        }
        return $out;
    }

    /* ------------------------------------------------------------------ */
    /*  GSTR-1 extras (include/exclude, items, HSN, docs)                  */
    /* ------------------------------------------------------------------ */

    public function toggleGstr1Invoice(array $in)
    {
        [$ctx, $err] = $this->requireBusinessPeriod($in);
        if ($err) {
            return $err;
        }
        $invoiceId = (int) ($in['invoice_id'] ?? $in['id'] ?? 0);
        if ($invoiceId <= 0) {
            return $this->err('invoice_id is required.');
        }
        $included = true;
        if (array_key_exists('included', $in)) {
            $v = $in['included'];
            $included = !($v === false || $v === 0 || $v === '0' || $v === 'false' || $v === 'exclude');
        } elseif (!empty($in['exclude']) || (isset($in['action']) && $in['action'] === 'exclude')) {
            $included = false;
        }
        $this->ensureGstr1Extras();
        if ($included) {
            $stmt = $this->connect->prepare(
                'DELETE FROM gst_gstr1_exclusions WHERE business_id = ? AND period = ? AND invoice_id = ?'
            );
            if ($stmt) {
                $stmt->bind_param('isi', $ctx['business_id'], $ctx['period'], $invoiceId);
                $stmt->execute();
                $stmt->close();
            }
        } else {
            $reason = trim((string) ($in['reason'] ?? ''));
            $stmt = $this->connect->prepare(
                'INSERT INTO gst_gstr1_exclusions (business_id, period, invoice_id, reason)
                 VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE reason = VALUES(reason)'
            );
            if ($stmt) {
                $stmt->bind_param('isis', $ctx['business_id'], $ctx['period'], $invoiceId, $reason);
                $stmt->execute();
                $stmt->close();
            }
        }
        $upd = $this->connect->prepare(
            'UPDATE gst_gstr1_invoices SET included = ? WHERE business_id = ? AND period = ? AND invoice_id = ?'
        );
        if ($upd) {
            $flag = $included ? 1 : 0;
            $upd->bind_param('iisi', $flag, $ctx['business_id'], $ctx['period'], $invoiceId);
            $upd->execute();
            $upd->close();
        }
        return $this->ok([
            'invoice_id' => $invoiceId,
            'included' => $included,
            'period' => $ctx['period'],
        ], $included ? 'Invoice included in this GSTR-1.' : 'Invoice excluded from this GSTR-1.');
    }

    public function getGstr1Items(array $in)
    {
        [$ctx, $err] = $this->requireBusinessPeriod($in, false);
        if ($err) {
            return $err;
        }
        $invoiceId = (int) ($in['invoice_id'] ?? $in['id'] ?? 0);
        if ($invoiceId <= 0) {
            return $this->err('invoice_id is required.');
        }
        if ($ctx['business_id'] > 0) {
            $chk = $this->connect->prepare('SELECT id FROM invoices WHERE id = ? AND business_id = ? LIMIT 1');
            if ($chk) {
                $chk->bind_param('ii', $invoiceId, $ctx['business_id']);
                $chk->execute();
                $ok = $chk->get_result()->num_rows > 0;
                $chk->close();
                if (!$ok) {
                    return $this->err('Invoice not found for this business.');
                }
            }
        }
        $map = $this->loadItemsByInvoiceIds([$invoiceId]);
        return $this->ok([
            'invoice_id' => $invoiceId,
            'items' => $map[$invoiceId] ?? [],
        ]);
    }

    public function exportGstr1(array $in)
    {
        $res = $this->getGstr1($in);
        if (($res['status'] ?? '') !== 'success') {
            return $res;
        }
        $data = $res['data'];
        $rows = [];
        $rows[] = [
            'Invoice No', 'Date', 'Customer', 'GSTIN', 'Place of Supply', 'POS Name',
            'Type', 'Location', 'Taxable', 'CGST', 'SGST', 'IGST', 'CESS', 'GST', 'Total', 'Included',
        ];
        foreach ($data['invoices'] ?? [] as $r) {
            $rows[] = [
                $r['invoice_no'] ?? '',
                $r['invoice_date'] ?? '',
                $r['name'] ?? '',
                $r['customer_gstin'] ?? '',
                $r['place_of_supply'] ?? '',
                $r['place_of_supply_name'] ?? '',
                $r['invoice_type'] ?? $r['supply_type'] ?? '',
                $r['location_name'] ?? '',
                $r['taxable'] ?? 0,
                $r['cgst'] ?? 0,
                $r['sgst'] ?? 0,
                $r['igst'] ?? 0,
                $r['cess'] ?? 0,
                $r['gst'] ?? 0,
                $r['total'] ?? 0,
                !empty($r['included']) ? 'Yes' : 'No',
            ];
        }
        return $this->ok([
            'period' => $data['period'] ?? '',
            'filename' => 'GSTR1_' . ($data['period'] ?? 'period') . '.csv',
            'headers' => $rows[0],
            'rows' => array_slice($rows, 1),
            'csv_rows' => $rows,
            'totals' => $data['totals'] ?? [],
        ]);
    }

    private function attachGstr1Details($business_id, $period, $location_id = 0)
    {
        $rows = $this->loadSalesInvoices($business_id, $period, $location_id);
        $excl = $this->gstr1ExclusionMap($business_id, $period);
        $ids = [];
        foreach ($rows as $r) {
            $ids[] = (int) $r['invoice_id'];
        }
        $itemsByInv = $this->loadItemsByInvoiceIds($ids);
        foreach ($rows as &$r) {
            $id = (int) $r['invoice_id'];
            $r['included'] = !isset($excl[$id]);
            $r['items'] = $itemsByInv[$id] ?? [];
            if ($r['items']) {
                $hsns = [];
                foreach ($r['items'] as $it) {
                    if (!empty($it['hsn_code'])) {
                        $hsns[$it['hsn_code']] = true;
                    }
                }
                $r['hsn_codes'] = implode(', ', array_keys($hsns));
            } else {
                $r['hsn_codes'] = '';
            }
        }
        unset($r);
        return $rows;
    }

    private function gstr1ExclusionMap($business_id, $period)
    {
        $out = [];
        $stmt = $this->connect->prepare(
            'SELECT invoice_id FROM gst_gstr1_exclusions WHERE business_id = ? AND period = ?'
        );
        if (!$stmt) {
            return $out;
        }
        $stmt->bind_param('is', $business_id, $period);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) {
            $out[(int) $r['invoice_id']] = true;
        }
        $stmt->close();
        return $out;
    }

    private function loadItemsByInvoiceIds(array $invoiceIds)
    {
        $out = [];
        $invoiceIds = array_values(array_unique(array_filter(array_map('intval', $invoiceIds))));
        if (!$invoiceIds) {
            return $out;
        }
        $chunkSize = 200;
        for ($i = 0; $i < count($invoiceIds); $i += $chunkSize) {
            $chunk = array_slice($invoiceIds, $i, $chunkSize);
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            $types = str_repeat('i', count($chunk));
            $sql = "SELECT it.id, it.invoice_id, it.product_id, it.quantity, it.price_of_one, it.price_of_all,
                           it.gst_rate, it.cgst, it.dgst, it.igst, p.name AS product_name, p.hsn_code
                    FROM items it
                    LEFT JOIN products p ON p.id = it.product_id
                    WHERE it.invoice_id IN ($ph)
                    ORDER BY it.id ASC";
            $stmt = $this->connect->prepare($sql);
            if (!$stmt) {
                continue;
            }
            $stmt->bind_param($types, ...$chunk);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $inv = (int) $row['invoice_id'];
                $cgst = (float) ($row['cgst'] ?? 0);
                $sgst = (float) ($row['dgst'] ?? 0);
                $igst = (float) ($row['igst'] ?? 0);
                $lineTotal = (float) ($row['price_of_all'] ?? 0);
                $gst = $cgst + $sgst + $igst;
                $taxable = round($lineTotal - $gst, 2);
                if ($taxable < 0) {
                    $taxable = $lineTotal;
                }
                $out[$inv][] = [
                    'id' => (int) $row['id'],
                    'product_id' => (int) ($row['product_id'] ?? 0),
                    'product_name' => (string) ($row['product_name'] ?? 'Item'),
                    'hsn_code' => (string) ($row['hsn_code'] ?? ''),
                    'quantity' => (float) ($row['quantity'] ?? 0),
                    'price_of_one' => (float) ($row['price_of_one'] ?? 0),
                    'taxable' => $taxable,
                    'gst_rate' => (float) ($row['gst_rate'] ?? 0),
                    'cgst' => $cgst,
                    'sgst' => $sgst,
                    'igst' => $igst,
                    'gst' => $gst,
                    'total' => $lineTotal,
                ];
            }
            $stmt->close();
        }
        return $out;
    }

    private function sumGstr1Rows(array $rows)
    {
        $out = [
            'count' => 0,
            'taxable' => 0.0,
            'cgst' => 0.0,
            'sgst' => 0.0,
            'igst' => 0.0,
            'cess' => 0.0,
            'gst' => 0.0,
            'total' => 0.0,
        ];
        foreach ($rows as $r) {
            $out['count']++;
            $out['taxable'] += (float) ($r['taxable'] ?? 0);
            $out['cgst'] += (float) ($r['cgst'] ?? 0);
            $out['sgst'] += (float) ($r['sgst'] ?? 0);
            $out['igst'] += (float) ($r['igst'] ?? 0);
            $out['cess'] += (float) ($r['cess'] ?? 0);
            $out['gst'] += (float) ($r['gst'] ?? 0);
            $out['total'] += (float) ($r['total'] ?? 0);
        }
        foreach (['taxable', 'cgst', 'sgst', 'igst', 'cess', 'gst', 'total'] as $k) {
            $out[$k] = round($out[$k], 2);
        }
        return $out;
    }

    private function gstr1SectionSummaries(array $rows)
    {
        $keys = ['B2B', 'B2CL', 'B2CS', 'CDNR', 'CDNUR', 'EXP'];
        $out = [];
        foreach ($keys as $k) {
            $out[$k] = $this->sumGstr1Rows([]);
        }
        foreach ($rows as $r) {
            $t = $r['invoice_type'] ?? $r['supply_type'] ?? 'B2CS';
            if (!isset($out[$t])) {
                $out[$t] = $this->sumGstr1Rows([]);
            }
            $out[$t]['count']++;
            $out[$t]['taxable'] += (float) $r['taxable'];
            $out[$t]['cgst'] += (float) $r['cgst'];
            $out[$t]['sgst'] += (float) $r['sgst'];
            $out[$t]['igst'] += (float) $r['igst'];
            $out[$t]['cess'] += (float) ($r['cess'] ?? 0);
            $out[$t]['gst'] += (float) $r['gst'];
            $out[$t]['total'] += (float) $r['total'];
        }
        foreach ($out as $k => $v) {
            foreach (['taxable', 'cgst', 'sgst', 'igst', 'cess', 'gst', 'total'] as $n) {
                $out[$k][$n] = round($v[$n], 2);
            }
        }
        return $out;
    }

    private function buildHsnSummary(array $rows)
    {
        $map = [];
        $num = 1;
        foreach ($rows as $r) {
            if (isset($r['included']) && empty($r['included'])) {
                continue;
            }
            $items = $r['items'] ?? [];
            if (!$items) {
                $hsn = (string) ($r['hsn_codes'] ?? '') ?: '00';
                $key = $hsn . '|' . (float) $r['gst_rate'];
                if (!isset($map[$key])) {
                    $map[$key] = [
                        'num' => $num++,
                        'hsn_sc' => $hsn,
                        'desc' => '',
                        'uqc' => 'NOS',
                        'qty' => 0,
                        'rt' => (float) $r['gst_rate'],
                        'txval' => 0,
                        'iamt' => 0,
                        'camt' => 0,
                        'samt' => 0,
                        'csamt' => 0,
                    ];
                }
                $map[$key]['qty'] += 1;
                $map[$key]['txval'] += (float) $r['taxable'];
                $map[$key]['iamt'] += (float) $r['igst'];
                $map[$key]['camt'] += (float) $r['cgst'];
                $map[$key]['samt'] += (float) $r['sgst'];
                $map[$key]['csamt'] += (float) ($r['cess'] ?? 0);
                continue;
            }
            foreach ($items as $it) {
                $hsn = (string) ($it['hsn_code'] ?? '') ?: '00';
                $key = $hsn . '|' . (float) $it['gst_rate'];
                if (!isset($map[$key])) {
                    $map[$key] = [
                        'num' => $num++,
                        'hsn_sc' => $hsn,
                        'desc' => (string) ($it['product_name'] ?? ''),
                        'uqc' => 'NOS',
                        'qty' => 0,
                        'rt' => (float) $it['gst_rate'],
                        'txval' => 0,
                        'iamt' => 0,
                        'camt' => 0,
                        'samt' => 0,
                        'csamt' => 0,
                    ];
                }
                $map[$key]['qty'] += (float) ($it['quantity'] ?? 0);
                $map[$key]['txval'] += (float) ($it['taxable'] ?? 0);
                $map[$key]['iamt'] += (float) ($it['igst'] ?? 0);
                $map[$key]['camt'] += (float) ($it['cgst'] ?? 0);
                $map[$key]['samt'] += (float) ($it['sgst'] ?? 0);
            }
        }
        $out = array_values($map);
        foreach ($out as &$h) {
            foreach (['qty', 'txval', 'iamt', 'camt', 'samt', 'csamt'] as $k) {
                $h[$k] = round((float) $h[$k], 2);
            }
        }
        unset($h);
        return $out;
    }

    private function buildDocsIssued(array $rows)
    {
        $groups = [
            'INV' => ['doc_num' => 1, 'doc_typ' => 'Invoices for outward supply', 'nos' => []],
            'CRN' => ['doc_num' => 4, 'doc_typ' => 'Credit Note', 'nos' => []],
            'DBN' => ['doc_num' => 5, 'doc_typ' => 'Debit Note', 'nos' => []],
        ];
        foreach ($rows as $r) {
            if (isset($r['included']) && empty($r['included'])) {
                continue;
            }
            $t = $r['invoice_type'] ?? $r['supply_type'] ?? '';
            $no = (string) ($r['invoice_no'] ?? '');
            if ($t === 'CDNR' || $t === 'CDNUR') {
                $lt = strtolower((string) ($r['type'] ?? ''));
                $bucket = (strpos($lt, 'd') === 0 || stripos($no, 'DN') === 0) ? 'DBN' : 'CRN';
            } else {
                $bucket = 'INV';
            }
            if ($no !== '') {
                $groups[$bucket]['nos'][] = $no;
            }
        }
        $out = [];
        foreach ($groups as $g) {
            $nos = $g['nos'];
            $tot = count($nos);
            if ($tot === 0) {
                continue;
            }
            sort($nos, SORT_NATURAL);
            $out[] = [
                'doc_num' => $g['doc_num'],
                'doc_typ' => $g['doc_typ'],
                'docs' => [[
                    'num' => 1,
                    'from' => $nos[0],
                    'to' => $nos[$tot - 1],
                    'totnum' => $tot,
                    'cancel' => 0,
                    'net_issue' => $tot,
                ]],
            ];
        }
        return $out;
    }

    /* ------------------------------------------------------------------ */
    /*  GSTR-2B / ITC                                                      */
    /* ------------------------------------------------------------------ */

    public function syncGstr2b(array $in)
    {
        [$ctx, $err] = $this->requireBusinessPeriod($in);
        if ($err) {
            return $err;
        }
        $local = $this->loadPurchaseExpenses($ctx['business_id'], $ctx['period'], $ctx['location_id']);
        $this->replaceLocal2b($ctx['business_id'], $ctx['period'], $local);

        $portalRows = [];
        $portalErr = null;
        $cred = $this->getCredentials($ctx['business_id']);
        if ($cred && $this->tokenValid($cred)) {
            $res = $this->perione('GET', 'gstr2b/all', $cred, [
                'ret_period' => self::retPeriod($ctx['period']),
                'rtnprd' => self::retPeriod($ctx['period']),
                'period' => $ctx['period'],
            ], null, 'sync-gstr2b');
            if ($this->perioneOk($res)) {
                $portalRows = $this->parseGstr2b($res['decoded']);
                $this->replacePortal2b($ctx['business_id'], $ctx['period'], $portalRows);
            } else {
                $portalErr = $this->perioneMessage($res, 'GSTR-2B portal sync failed');
            }
        } else {
            $portalErr = 'GST session not authenticated; synced local expenses only.';
        }

        $matched = $this->autoMatchItc($ctx['business_id'], $ctx['period'], $local, $portalRows);
        $this->updatePeriodStatus($ctx['business_id'], $ctx['period'], [
            'gstr2b_status' => $portalErr && $cred && $this->tokenValid($cred) ? 'error' : 'synced',
            'last_synced_at' => date('Y-m-d H:i:s'),
        ]);
        $rows = $this->attachItcStatus($ctx['business_id'], $ctx['period'], $local);
        $auth = $this->authPublic($ctx['business_id']);
        $out = [
            'period' => $ctx['period'],
            'local_count' => count($local),
            'portal_count' => count($portalRows),
            'auto_matched' => $matched,
            'invoices' => $rows,
            'auth_required' => $auth['auth_required'],
            'needs_otp' => !empty($auth['auth_required']),
            'gstin' => $auth['gstin'],
        ];
        if ($portalErr) {
            $out['portal_warning'] = $portalErr;
        }
        return $this->ok($out, 'GSTR-2B synced.');
    }

    public function getGstr2b(array $in)
    {
        [$ctx, $err] = $this->requireBusinessPeriod($in);
        if ($err) {
            return $err;
        }
        $local = $this->loadPurchaseExpenses($ctx['business_id'], $ctx['period'], $ctx['location_id']);
        $rows = $this->attachItcStatus($ctx['business_id'], $ctx['period'], $local);
        $filter = strtolower(trim((string) ($in['status'] ?? $in['filter'] ?? 'all')));
        if ($filter !== '' && $filter !== 'all') {
            $rows = array_values(array_filter($rows, function ($r) use ($filter) {
                return ($r['status'] ?? '') === $filter;
            }));
        }
        $sum = ['pending' => 0, 'matched' => 0, 'carry' => 0, 'ghost' => 0];
        $counts = ['all' => 0, 'pending' => 0, 'matched' => 0, 'carry' => 0, 'ghost' => 0];
        $all = $this->attachItcStatus(
            $ctx['business_id'],
            $ctx['period'],
            $this->loadPurchaseExpenses($ctx['business_id'], $ctx['period'], $ctx['location_id'])
        );
        foreach ($all as $r) {
            $st = $r['status'] ?? 'pending';
            if (!isset($sum[$st])) {
                $st = 'pending';
            }
            $sum[$st] += $r['gstClaimed'];
            $counts[$st] = ($counts[$st] ?? 0) + 1;
            if ($st !== 'ghost') {
                $counts['all']++;
            }
        }
        $home = $this->homeState($ctx['business_id']);
        $this->enrichGstSplit($all, $home);
        $this->enrichGstSplit($rows, $home);
        $matches = $this->buildTwoWayMatches($ctx['business_id'], $ctx['period'], $all);
        $ghostQueue = [];
        foreach ($matches as $m) {
            if (($m['status'] ?? '') === 'ghost' || ($m['match_quality'] ?? '') === 'ghost') {
                if (empty($m['dismissed'])) {
                    $ghostQueue[] = $m;
                }
            }
        }
        $auth = $this->authPublic($ctx['business_id']);
        $periodRow = $this->ensurePeriod($ctx['business_id'], $ctx['period']);
        return $this->ok([
            'period' => $ctx['period'],
            'period_label' => self::periodLabel($ctx['period']),
            'invoices' => array_values($rows),
            'matches' => $matches,
            'vendor_summary' => $this->vendorItcSummary($matches),
            'ghost_queue' => $ghostQueue,
            'ghost_dismissed' => $this->loadDismissedGhosts($ctx['business_id'], $ctx['period']),
            'eligible_notes' => $this->eligibleItcNotes(),
            'totals' => [
                'matched' => round($sum['matched'], 2),
                'pending' => round($sum['pending'], 2),
                'carry' => round($sum['carry'], 2),
                'ghost' => round($sum['ghost'], 2),
                'eligible' => round($sum['matched'], 2),
                'ineligible' => round($sum['ghost'] + $sum['pending'] + $sum['carry'], 2),
            ],
            'counts' => $counts,
            'gstr2b_status' => $periodRow['gstr2b_status'] ?? 'pending',
            'last_synced_at' => $periodRow['last_synced_at'] ?? null,
            'auth_required' => $auth['auth_required'],
            'gstin' => $auth['gstin'],
        ]);
    }

    public function reconcileItc(array $in)
    {
        [$ctx, $err] = $this->requireBusinessPeriod($in);
        if ($err) {
            return $err;
        }
        $allowed = ['pending', 'matched', 'carry', 'ghost'];
        $bulkAction = (string) ($in['bulk_action'] ?? '');
        $updated = 0;

        if ($bulkAction === 'approve_under' || $bulkAction === 'bulk_approve') {
            $max = isset($in['max_gst']) ? (float) $in['max_gst'] : 5000.0;
            $local = $this->loadPurchaseExpenses($ctx['business_id'], $ctx['period'], $ctx['location_id']);
            $local = $this->attachItcStatus($ctx['business_id'], $ctx['period'], $local);
            foreach ($local as $r) {
                if (($r['status'] ?? '') === 'ghost') {
                    continue;
                }
                if (($r['status'] ?? 'pending') === 'pending' && $r['gstClaimed'] <= $max) {
                    $this->upsertItcRow($ctx['business_id'], $ctx['period'], $r, 'matched');
                    $updated++;
                }
            }
        } elseif ($bulkAction === 'carry_pending' || $bulkAction === 'pending_to_carry') {
            $local = $this->loadPurchaseExpenses($ctx['business_id'], $ctx['period'], $ctx['location_id']);
            $local = $this->attachItcStatus($ctx['business_id'], $ctx['period'], $local);
            foreach ($local as $r) {
                if (($r['status'] ?? 'pending') === 'pending') {
                    $this->upsertItcRow($ctx['business_id'], $ctx['period'], $r, 'carry');
                    $this->upsertCarry($ctx['business_id'], $ctx['period'], $r);
                    $updated++;
                }
            }
        } elseif (in_array($bulkAction, ['approve_selected', 'carry_selected', 'mark_ghost'], true)) {
            $statusMap = [
                'approve_selected' => 'matched',
                'carry_selected' => 'carry',
                'mark_ghost' => 'ghost',
            ];
            $status = $statusMap[$bulkAction];
            $ids = isset($in['ids']) && is_array($in['ids']) ? $in['ids'] : [];
            if (!$ids && !empty($in['items']) && is_array($in['items'])) {
                foreach ($in['items'] as $item) {
                    $ids[] = $item['id'] ?? $item['expense_id'] ?? '';
                }
            }
            $notes = trim((string) ($in['notes'] ?? $in['reason'] ?? ''));
            foreach ($ids as $id) {
                if ($id === '' || $id === null) {
                    continue;
                }
                if ($this->applyItcStatus($ctx['business_id'], $ctx['period'], $ctx['location_id'], (string) $id, $status, $notes)) {
                    $updated++;
                }
            }
        } elseif (!empty($in['items']) && is_array($in['items'])) {
            foreach ($in['items'] as $item) {
                $status = strtolower((string) ($item['status'] ?? ''));
                if (!in_array($status, $allowed, true)) {
                    continue;
                }
                $eid = (string) ($item['expense_id'] ?? $item['id'] ?? '');
                $notes = trim((string) ($item['notes'] ?? $item['reason'] ?? $in['notes'] ?? $in['reason'] ?? ''));
                if ($eid !== '' && $this->applyItcStatus($ctx['business_id'], $ctx['period'], $ctx['location_id'], $eid, $status, $notes)) {
                    $updated++;
                }
            }
        } else {
            $status = strtolower((string) ($in['status'] ?? ''));
            $eid = (string) ($in['expense_id'] ?? $in['id'] ?? '');
            if ($eid === '' || !in_array($status, $allowed, true)) {
                return $this->err('Provide expense_id + status, or items[], or bulk_action.');
            }
            $notes = trim((string) ($in['notes'] ?? $in['reason'] ?? ''));
            if (!$this->applyItcStatus($ctx['business_id'], $ctx['period'], $ctx['location_id'], $eid, $status, $notes)) {
                return $this->err('Expense not found for this period.');
            }
            $updated = 1;
        }

        $summary = $this->getGstr2b($in);
        $data = $summary['data'] ?? [];
        $data['updated'] = $updated;
        return $this->ok($data, 'ITC status saved.');
    }

    private function findExpenseAsItc($business_id, $period, $location_id, $eid)
    {
        $local = $this->loadPurchaseExpenses($business_id, $period, $location_id);
        foreach ($local as $r) {
            if ((string) $r['id'] === (string) $eid) {
                return $r;
            }
        }
        return null;
    }

    private function applyItcStatus($business_id, $period, $location_id, $eid, $status, $notes = '')
    {
        if ($status === 'ghost' && strpos((string) $eid, 'ghost') === 0) {
            $this->dismissGhost($business_id, $period, $eid, $notes);
            return true;
        }
        $row = $this->findExpenseAsItc($business_id, $period, $location_id, $eid);
        if (!$row) {
            return false;
        }
        if ($notes !== '') {
            $row['notes'] = $notes;
        }
        $this->upsertItcRow($business_id, $period, $row, $status);
        if ($status === 'carry') {
            $this->upsertCarry($business_id, $period, $row);
        }
        return true;
    }

    private function upsertItcRow($business_id, $period, array $row, $status)
    {
        $sql = 'INSERT INTO gst_itc_reconcile
            (business_id, period, source, source_ref, expense_id, gstr2b_id, status, gst_claimed, gst_portal, taxable, vendor_name, vendor_gstin, doc_date, notes)
            VALUES (?, ?, \'expense\', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE status = VALUES(status), gst_claimed = VALUES(gst_claimed),
              taxable = VALUES(taxable), vendor_name = VALUES(vendor_name), vendor_gstin = VALUES(vendor_gstin),
              doc_date = VALUES(doc_date),
              notes = IF(VALUES(notes) = \'\', notes, VALUES(notes)),
              gstr2b_id = IF(VALUES(gstr2b_id) = 0, gstr2b_id, VALUES(gstr2b_id)),
              gst_portal = IF(VALUES(gst_portal) = 0, gst_portal, VALUES(gst_portal))';
        $stmt = $this->connect->prepare($sql);
        if (!$stmt) {
            return;
        }
        $ref = (string) $row['id'];
        $eid = (int) ($row['expense_id'] ?? $row['id']);
        $gstr2bId = (int) ($row['gstr2b_id'] ?? 0);
        $claimed = (float) $row['gstClaimed'];
        $portalAmt = (float) ($row['gst_portal'] ?? 0);
        $taxable = (float) $row['taxable'];
        $vname = (string) ($row['paid_to'] ?: $row['name']);
        $vgstin = (string) ($row['gstin'] ?? '');
        $dd = $row['date'] ?: null;
        $notes = (string) ($row['notes'] ?? '');
        $stmt->bind_param(
            'issiisdddssss',
            $business_id,
            $period,
            $ref,
            $eid,
            $gstr2bId,
            $status,
            $claimed,
            $portalAmt,
            $taxable,
            $vname,
            $vgstin,
            $dd,
            $notes
        );
        $stmt->execute();
        $stmt->close();
    }

    private function upsertCarry($business_id, $period, array $row)
    {
        $eid = (int) ($row['expense_id'] ?? $row['id']);
        $chk = $this->connect->prepare(
            'SELECT id FROM gst_carry_forward WHERE business_id = ? AND from_period = ? AND expense_id = ? AND status = \'open\' LIMIT 1'
        );
        if ($chk) {
            $chk->bind_param('isi', $business_id, $period, $eid);
            $chk->execute();
            $exists = $chk->get_result()->fetch_assoc();
            $chk->close();
            if ($exists) {
                return;
            }
        }
        $toPeriod = date('Y-m', strtotime($period . '-01 +1 month'));
        $stmt = $this->connect->prepare(
            'INSERT INTO gst_carry_forward (business_id, from_period, to_period, expense_id, vendor_name, vendor_gstin, itc_amount, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, \'open\')'
        );
        if (!$stmt) {
            return;
        }
        $vname = (string) ($row['paid_to'] ?: $row['name']);
        $vgstin = (string) ($row['gstin'] ?? '');
        $amt = (float) $row['gstClaimed'];
        $stmt->bind_param('ississd', $business_id, $period, $toPeriod, $eid, $vname, $vgstin, $amt);
        $stmt->execute();
        $stmt->close();
    }

    private function dismissGhost($business_id, $period, $ref, $reason = '')
    {
        $notes = $reason !== '' ? ('DISMISSED: ' . $reason) : 'DISMISSED';
        $stmt = $this->connect->prepare(
            'UPDATE gst_itc_reconcile SET notes = ? WHERE business_id = ? AND period = ? AND source = \'ghost\' AND source_ref = ?'
        );
        if ($stmt) {
            $stmt->bind_param('siss', $notes, $business_id, $period, $ref);
            $stmt->execute();
            $stmt->close();
        }
    }

    private function replaceLocal2b($business_id, $period, array $local)
    {
        $del = $this->connect->prepare("DELETE FROM gst_gstr2b_invoices WHERE business_id = ? AND period = ? AND source = 'local'");
        if ($del) {
            $del->bind_param('is', $business_id, $period);
            $del->execute();
            $del->close();
        }
        $sql = 'INSERT INTO gst_gstr2b_invoices
            (business_id, period, source, expense_id, invoice_no, invoice_date, vendor_name, vendor_gstin, taxable, cgst, sgst, igst, itc_eligible)
            VALUES (?, ?, \'local\', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
        $stmt = $this->connect->prepare($sql);
        if (!$stmt) {
            return;
        }
        foreach ($local as $r) {
            $eid = (int) $r['expense_id'];
            $ino = (string) (($r['invoice_no'] ?? '') !== '' ? $r['invoice_no'] : $r['id']);
            $idt = $r['date'] ?: null;
            $vn = (string) ($r['paid_to'] ?: $r['name']);
            $vg = (string) $r['gstin'];
            $tx = (float) $r['taxable'];
            $cg = (float) ($r['cgst'] ?? 0);
            $sg = (float) ($r['sgst'] ?? 0);
            $ig = (float) ($r['igst'] ?? 0);
            $itc = (float) $r['gstClaimed'];
            $stmt->bind_param('isissssddddd', $business_id, $period, $eid, $ino, $idt, $vn, $vg, $tx, $cg, $sg, $ig, $itc);
            $stmt->execute();
        }
        $stmt->close();
    }

    private function replacePortal2b($business_id, $period, array $portalRows)
    {
        $del = $this->connect->prepare("DELETE FROM gst_gstr2b_invoices WHERE business_id = ? AND period = ? AND source = 'portal'");
        if ($del) {
            $del->bind_param('is', $business_id, $period);
            $del->execute();
            $del->close();
        }
        $sql = 'INSERT INTO gst_gstr2b_invoices
            (business_id, period, source, invoice_no, invoice_date, vendor_name, vendor_gstin, taxable, cgst, sgst, igst, itc_eligible, portal_json)
            VALUES (?, ?, \'portal\', ?, ?, ?, ?, ?, ?, ?, ?, ?)';
        $stmt = $this->connect->prepare($sql);
        if (!$stmt) {
            return;
        }
        foreach ($portalRows as $r) {
            $json = json_encode($r, self::JSON_FLAGS);
            $ino = (string) ($r['invoice_no'] ?? '');
            $idt = $r['invoice_date'] ?? null;
            $vn = (string) ($r['vendor_name'] ?? '');
            $vg = (string) ($r['vendor_gstin'] ?? '');
            $tx = (float) ($r['taxable'] ?? 0);
            $cg = (float) ($r['cgst'] ?? 0);
            $sg = (float) ($r['sgst'] ?? 0);
            $ig = (float) ($r['igst'] ?? 0);
            $itc = (float) ($r['itc_eligible'] ?? ($cg + $sg + $ig));
            $stmt->bind_param('isssssddddds', $business_id, $period, $ino, $idt, $vn, $vg, $tx, $cg, $sg, $ig, $itc, $json);
            $stmt->execute();
        }
        $stmt->close();
    }

    private function parseGstr2b(array $decoded)
    {
        $rows = [];
        $data = $decoded['data'] ?? $decoded;
        $doc = $data['docdata'] ?? $data['data'] ?? $data;
        $b2b = $doc['b2b'] ?? [];
        if (!is_array($b2b)) {
            return $rows;
        }
        foreach ($b2b as $party) {
            $ctin = (string) ($party['ctin'] ?? $party['gstin'] ?? '');
            $trd = (string) ($party['trdnm'] ?? $party['tradeName'] ?? $party['ctin'] ?? '');
            foreach (($party['inv'] ?? []) as $inv) {
                $tx = $iamt = $camt = $samt = 0.0;
                foreach (($inv['itms'] ?? []) as $it) {
                    $d = $it['itm_det'] ?? $it;
                    $tx += (float) ($d['txval'] ?? 0);
                    $iamt += (float) ($d['iamt'] ?? 0);
                    $camt += (float) ($d['camt'] ?? 0);
                    $samt += (float) ($d['samt'] ?? 0);
                }
                $idt = (string) ($inv['dt'] ?? $inv['idt'] ?? '');
                $iso = $this->gstnDateToIso($idt);
                $rows[] = [
                    'invoice_no' => (string) ($inv['inum'] ?? $inv['inum'] ?? ''),
                    'invoice_date' => $iso,
                    'vendor_name' => $trd,
                    'vendor_gstin' => $ctin,
                    'taxable' => $tx,
                    'cgst' => $camt,
                    'sgst' => $samt,
                    'igst' => $iamt,
                    'itc_eligible' => $iamt + $camt + $samt,
                ];
            }
        }
        return $rows;
    }

    private function gstnDateToIso($dt)
    {
        $dt = trim((string) $dt);
        if ($dt === '') {
            return null;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $dt)) {
            return substr($dt, 0, 10);
        }
        $ts = strtotime(str_replace('/', '-', $dt));
        return $ts ? date('Y-m-d', $ts) : null;
    }

    private function autoMatchItc($business_id, $period, array $local, array $portal)
    {
        $matched = 0;
        $usedPortal = [];
        foreach ($local as $lr) {
            $bestIdx = null;
            $bestScore = 0;
            foreach ($portal as $i => $pr) {
                if (isset($usedPortal[$i])) {
                    continue;
                }
                $scored = $this->scoreItcPair($lr, $pr);
                if ($scored['score'] > $bestScore) {
                    $bestScore = $scored['score'];
                    $bestIdx = $i;
                }
            }
            if ($bestIdx !== null && $bestScore >= 40) {
                $pr = $portal[$bestIdx];
                $lr['gst_portal'] = (float) ($pr['itc_eligible'] ?? 0);
                $this->upsertItcRow($business_id, $period, $lr, 'pending');
                $usedPortal[$bestIdx] = true;
                $matched++;
            }
        }
        foreach ($portal as $i => $pr) {
            if (isset($usedPortal[$i])) {
                continue;
            }
            $ref = 'ghost_' . $period . '_' . md5(($pr['vendor_gstin'] ?? '') . '|' . ($pr['invoice_no'] ?? '') . '|' . $i);
            $invNote = 'INV:' . (string) ($pr['invoice_no'] ?? '');
            $sql = 'INSERT INTO gst_itc_reconcile
                (business_id, period, source, source_ref, status, gst_claimed, gst_portal, taxable, vendor_name, vendor_gstin, doc_date, notes)
                VALUES (?, ?, \'ghost\', ?, \'ghost\', ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE gst_portal = VALUES(gst_portal),
                  notes = IF(notes LIKE \'DISMISSED%\', notes, VALUES(notes))';
            $stmt = $this->connect->prepare($sql);
            if (!$stmt) {
                continue;
            }
            $claimed = (float) $pr['itc_eligible'];
            $portalAmt = (float) $pr['itc_eligible'];
            $tx = (float) $pr['taxable'];
            $vn = (string) $pr['vendor_name'];
            $vg = (string) $pr['vendor_gstin'];
            $dd = $pr['invoice_date'];
            $stmt->bind_param('issdddssss', $business_id, $period, $ref, $claimed, $portalAmt, $tx, $vn, $vg, $dd, $invNote);
            $stmt->execute();
            $stmt->close();
        }
        return $matched;
    }

    private function authPublic($business_id)
    {
        $cred = $this->getCredentials($business_id);
        return [
            'auth_required' => !$cred || !$this->tokenValid($cred),
            'gstin' => $cred['gstin'] ?? '',
        ];
    }

    private function enrichGstSplit(array &$rows, $homeState)
    {
        foreach ($rows as &$r) {
            $tax = (float) ($r['gstClaimed'] ?? $r['gst_claimed'] ?? 0);
            $cg = (float) ($r['cgst'] ?? 0);
            $sg = (float) ($r['sgst'] ?? 0);
            $ig = (float) ($r['igst'] ?? 0);
            if ($cg + $sg + $ig > 0 || $tax <= 0) {
                continue;
            }
            $vState = self::stateFromGstin($r['gstin'] ?? '');
            if ($vState !== '' && $homeState !== '' && $vState !== $homeState) {
                $r['igst'] = round($tax, 2);
                $r['cgst'] = 0.0;
                $r['sgst'] = 0.0;
            } else {
                $half = round($tax / 2, 2);
                $r['cgst'] = $half;
                $r['sgst'] = round($tax - $half, 2);
                $r['igst'] = 0.0;
            }
        }
        unset($r);
    }

    private function loadPortal2b($business_id, $period)
    {
        $rows = [];
        $stmt = $this->connect->prepare(
            "SELECT * FROM gst_gstr2b_invoices WHERE business_id = ? AND period = ? AND source = 'portal' ORDER BY id ASC"
        );
        if (!$stmt) {
            return $rows;
        }
        $stmt->bind_param('is', $business_id, $period);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) {
            $rows[] = [
                'id' => (int) $r['id'],
                'invoice_no' => (string) ($r['invoice_no'] ?? ''),
                'invoice_date' => (string) ($r['invoice_date'] ?? ''),
                'vendor_name' => (string) ($r['vendor_name'] ?? ''),
                'vendor_gstin' => strtoupper((string) ($r['vendor_gstin'] ?? '')),
                'taxable' => (float) $r['taxable'],
                'cgst' => (float) $r['cgst'],
                'sgst' => (float) $r['sgst'],
                'igst' => (float) $r['igst'],
                'itc_eligible' => (float) $r['itc_eligible'],
            ];
        }
        $stmt->close();
        return $rows;
    }

    private function normalizeInvNo($s)
    {
        return strtoupper(preg_replace('/[^A-Z0-9]/', '', (string) $s));
    }

    private function scoreItcPair(array $book, array $portal)
    {
        $mismatches = [];
        $reasons = [];
        $score = 0;
        $g1 = strtoupper((string) ($book['gstin'] ?? ''));
        $g2 = strtoupper((string) ($portal['vendor_gstin'] ?? ''));
        $gstinExact = false;
        $gstinTypo = false;
        if ($g1 !== '' && $g2 !== '' && $g1 === $g2) {
            $score += 50;
            $gstinExact = true;
        } elseif ($g1 !== '' && $g2 !== '') {
            $d = levenshtein($g1, $g2);
            if ($d > 0 && $d <= 2) {
                $score += 25;
                $gstinTypo = true;
                $mismatches[] = 'gstin_typo';
                $reasons[] = 'GSTIN looks like a typo (' . $d . ' character' . ($d === 1 ? '' : 's') . ' off)';
            } else {
                $mismatches[] = 'gstin';
                $reasons[] = 'GSTIN differs (books ' . $g1 . ' vs 2B ' . $g2 . ')';
            }
        } else {
            $mismatches[] = 'gstin_missing';
            $reasons[] = 'GSTIN missing on books or GSTR-2B';
        }

        $inv1 = $this->normalizeInvNo($book['invoice_no'] ?? '');
        $inv2 = $this->normalizeInvNo($portal['invoice_no'] ?? '');
        $invExact = false;
        if ($inv1 !== '' && $inv1 === $inv2) {
            $score += 30;
            $invExact = true;
        } elseif ($inv1 !== '' && $inv2 !== '') {
            $d = levenshtein(substr($inv1, 0, 255), substr($inv2, 0, 255));
            if ($d > 0 && $d <= 2) {
                $score += 18;
                $mismatches[] = 'invoice_typo';
                $reasons[] = 'Invoice number looks like a typo (books ' . ($book['invoice_no'] ?? '') . ' vs 2B ' . ($portal['invoice_no'] ?? '') . ')';
            } else {
                $mismatches[] = 'invoice_no';
                $reasons[] = 'Invoice number differs (books ' . ($book['invoice_no'] ?? '') . ' vs 2B ' . ($portal['invoice_no'] ?? '') . ')';
            }
        }

        $d1 = (string) ($book['date'] ?? '');
        $d2 = (string) ($portal['invoice_date'] ?? '');
        if ($d1 !== '' && $d2 !== '' && $d1 === $d2) {
            $score += 10;
        } elseif ($d1 !== '' && $d2 !== '') {
            $diff = abs(strtotime($d1) - strtotime($d2)) / 86400;
            if ($diff <= 3) {
                $score += 5;
                $mismatches[] = 'date';
                $reasons[] = 'Date off by ' . (int) $diff . ' day(s)';
            } else {
                $mismatches[] = 'date';
                $reasons[] = 'Date differs (books ' . $d1 . ' vs 2B ' . $d2 . ')';
            }
        }

        $tx1 = (float) ($book['taxable'] ?? 0);
        $tx2 = (float) ($portal['taxable'] ?? 0);
        if ($tx1 > 0 && $tx2 > 0) {
            $gap = abs($tx1 - $tx2);
            if ($gap < 1) {
                $score += 10;
            } elseif ($gap <= max(10, $tx1 * 0.01)) {
                $score += 4;
                $mismatches[] = 'taxable';
                $reasons[] = 'Taxable value mismatch (₹' . number_format($gap, 2) . ')';
            } else {
                $mismatches[] = 'taxable';
                $reasons[] = 'Taxable value differs (books ₹' . number_format($tx1, 2) . ' vs 2B ₹' . number_format($tx2, 2) . ')';
            }
        }

        $tax1 = (float) ($book['gstClaimed'] ?? $book['gst_claimed'] ?? 0);
        $tax2 = (float) ($portal['itc_eligible'] ?? 0);
        if ($tax1 > 0 && $tax2 > 0) {
            $gap = abs($tax1 - $tax2);
            if ($gap < 1) {
                $score += 8;
            } else {
                $mismatches[] = 'tax';
                $reasons[] = 'Tax / ITC differs (books ₹' . number_format($tax1, 2) . ' vs 2B ₹' . number_format($tax2, 2) . ')';
            }
        }

        $quality = 'mismatch';
        if ($gstinExact && $invExact && !$mismatches) {
            $quality = 'exact';
        } elseif ($gstinTypo || in_array('invoice_typo', $mismatches, true)) {
            $quality = 'typo';
        } elseif ($gstinExact && ($invExact || ($score >= 70))) {
            $quality = empty($mismatches) ? 'matched' : 'mismatch';
        } elseif ($score >= 50) {
            $quality = empty($mismatches) ? 'matched' : 'mismatch';
        }

        return [
            'score' => $score,
            'mismatches' => $mismatches,
            'reasons' => $reasons,
            'quality' => $quality,
        ];
    }

    private function buildTwoWayMatches($business_id, $period, array $books)
    {
        $portal = $this->loadPortal2b($business_id, $period);
        $usedPortal = [];
        $out = [];
        foreach ($books as $b) {
            if (($b['status'] ?? '') === 'ghost') {
                continue;
            }
            $bestIdx = null;
            $best = null;
            $bestScore = 0;
            foreach ($portal as $i => $pr) {
                if (isset($usedPortal[$i])) {
                    continue;
                }
                $scored = $this->scoreItcPair($b, $pr);
                if ($scored['score'] > $bestScore) {
                    $bestScore = $scored['score'];
                    $bestIdx = $i;
                    $best = $scored;
                }
            }
            $paired = ($bestIdx !== null && $bestScore >= 40);
            if ($paired) {
                $usedPortal[$bestIdx] = true;
                $pr = $portal[$bestIdx];
                $out[] = $this->matchRow($b, $pr, $best, false);
            } else {
                $out[] = $this->matchRow($b, null, [
                    'score' => 0,
                    'mismatches' => ['books_only'],
                    'reasons' => ['No GSTR-2B portal invoice for this purchase yet'],
                    'quality' => 'books_only',
                ], false);
            }
        }
        foreach ($portal as $i => $pr) {
            if (isset($usedPortal[$i])) {
                continue;
            }
            $ghostId = 'ghost_' . $period . '_' . md5(($pr['vendor_gstin'] ?? '') . '|' . ($pr['invoice_no'] ?? '') . '|' . $i);
            $synthetic = [
                'id' => $ghostId,
                'expense_id' => 0,
                'name' => $pr['vendor_name'] ?: 'Unmatched portal credit',
                'paid_to' => $pr['vendor_name'] ?: 'Unknown vendor',
                'gstin' => $pr['vendor_gstin'],
                'invoice_no' => $pr['invoice_no'],
                'date' => $pr['invoice_date'],
                'taxable' => $pr['taxable'],
                'cgst' => $pr['cgst'],
                'sgst' => $pr['sgst'],
                'igst' => $pr['igst'],
                'gstClaimed' => $pr['itc_eligible'],
                'status' => 'ghost',
            ];
            foreach ($books as $b) {
                if (($b['status'] ?? '') === 'ghost' && strcasecmp((string) $b['gstin'], (string) $pr['vendor_gstin']) === 0
                    && $this->normalizeInvNo($b['invoice_no'] ?? '') === $this->normalizeInvNo($pr['invoice_no'])) {
                    $synthetic['id'] = $b['id'];
                    $synthetic['notes'] = $b['notes'] ?? '';
                    break;
                }
                if (($b['status'] ?? '') === 'ghost' && (string) $b['id'] === $ghostId) {
                    $synthetic['id'] = $b['id'];
                    $synthetic['notes'] = $b['notes'] ?? '';
                    break;
                }
            }
            $out[] = $this->matchRow($synthetic, $pr, [
                'score' => 0,
                'mismatches' => ['ghost'],
                'reasons' => ['Portal credit with no matching purchase in books — typo, wrong GSTIN, or unearned credit'],
                'quality' => 'ghost',
            ], $this->isDismissedGhost(['notes' => $synthetic['notes'] ?? '']));
        }
        foreach ($books as $b) {
            if (($b['status'] ?? '') !== 'ghost') {
                continue;
            }
            $already = false;
            foreach ($out as $row) {
                if ((string) $row['id'] === (string) $b['id']) {
                    $already = true;
                    break;
                }
            }
            if ($already) {
                continue;
            }
            $out[] = $this->matchRow($b, [
                'invoice_no' => $b['invoice_no'] ?? '',
                'invoice_date' => $b['date'] ?? '',
                'vendor_name' => $b['paid_to'] ?? $b['name'] ?? '',
                'vendor_gstin' => $b['gstin'] ?? '',
                'taxable' => $b['taxable'] ?? 0,
                'cgst' => $b['cgst'] ?? 0,
                'sgst' => $b['sgst'] ?? 0,
                'igst' => $b['igst'] ?? 0,
                'itc_eligible' => $b['gstClaimed'] ?? 0,
            ], [
                'score' => 0,
                'mismatches' => ['ghost'],
                'reasons' => ['Portal credit with no matching purchase in books'],
                'quality' => 'ghost',
            ], false);
        }
        return $out;
    }

    private function matchRow(array $book, $portal, array $scored, $dismissed)
    {
        $status = $book['status'] ?? 'pending';
        $vendor = (string) ($book['paid_to'] ?: ($book['name'] ?? ''));
        if ($portal && ($vendor === '' || $vendor === 'Unknown vendor')) {
            $vendor = (string) ($portal['vendor_name'] ?? $vendor);
        }
        $reason = implode('; ', $scored['reasons'] ?? []);
        $eligible = $status === 'matched';
        $ineligibleNote = '';
        if ($status === 'ghost' || ($scored['quality'] ?? '') === 'ghost') {
            $ineligibleNote = 'Ghost / unearned — never include in eligible ITC.';
        } elseif ($status === 'pending') {
            $ineligibleNote = 'Pending vendor handshake — does not reduce GSTR-3B until Approved.';
        } elseif ($status === 'carry') {
            $ineligibleNote = 'Carried to a later period — not eligible in this return.';
        } elseif ($eligible) {
            $ineligibleNote = 'Approved (matched) — counts toward eligible ITC.';
        }
        if (($book['gstin'] ?? '') === '' && $status !== 'ghost') {
            $ineligibleNote = 'Missing vendor GSTIN — treat as ineligible until GSTIN is captured.';
        }
        $booksTax = (float) ($book['gstClaimed'] ?? 0);
        $portalTax = $portal ? (float) ($portal['itc_eligible'] ?? 0) : 0.0;
        return [
            'id' => (string) $book['id'],
            'expense_id' => (int) ($book['expense_id'] ?? 0),
            'vendor' => $vendor,
            'gstin' => (string) ($book['gstin'] ?? ''),
            'invoice_no' => (string) ($book['invoice_no'] ?? ($portal['invoice_no'] ?? '')),
            'date' => (string) ($book['date'] ?? ''),
            'taxable' => (float) ($book['taxable'] ?? 0),
            'cgst' => (float) ($book['cgst'] ?? 0),
            'sgst' => (float) ($book['sgst'] ?? 0),
            'igst' => (float) ($book['igst'] ?? 0),
            'tax' => $booksTax,
            'status' => $status,
            'match_quality' => $scored['quality'] ?? 'books_only',
            'match_score' => (int) ($scored['score'] ?? 0),
            'mismatch_keys' => $scored['mismatches'] ?? [],
            'mismatch_reason' => $reason,
            'eligible' => $eligible,
            'ineligible_note' => $ineligibleNote,
            'dismissed' => $dismissed ? true : false,
            'notes' => (string) ($book['notes'] ?? ''),
            'books' => [
                'vendor' => (string) ($book['paid_to'] ?: ($book['name'] ?? '')),
                'gstin' => (string) ($book['gstin'] ?? ''),
                'invoice_no' => (string) ($book['invoice_no'] ?? ''),
                'date' => (string) ($book['date'] ?? ''),
                'taxable' => (float) ($book['taxable'] ?? 0),
                'cgst' => (float) ($book['cgst'] ?? 0),
                'sgst' => (float) ($book['sgst'] ?? 0),
                'igst' => (float) ($book['igst'] ?? 0),
                'tax' => $booksTax,
            ],
            'portal' => $portal ? [
                'id' => (int) ($portal['id'] ?? 0),
                'vendor' => (string) ($portal['vendor_name'] ?? ''),
                'gstin' => (string) ($portal['vendor_gstin'] ?? ''),
                'invoice_no' => (string) ($portal['invoice_no'] ?? ''),
                'date' => (string) ($portal['invoice_date'] ?? ''),
                'taxable' => (float) ($portal['taxable'] ?? 0),
                'cgst' => (float) ($portal['cgst'] ?? 0),
                'sgst' => (float) ($portal['sgst'] ?? 0),
                'igst' => (float) ($portal['igst'] ?? 0),
                'tax' => $portalTax,
            ] : null,
        ];
    }

    private function vendorItcSummary(array $matches)
    {
        $map = [];
        foreach ($matches as $m) {
            $key = strtoupper((string) ($m['gstin'] ?: $m['vendor'] ?: 'UNKNOWN'));
            if (!isset($map[$key])) {
                $map[$key] = [
                    'vendor' => $m['vendor'] ?: 'Unknown',
                    'gstin' => (string) ($m['gstin'] ?? ''),
                    'rows' => 0,
                    'claimed' => 0.0,
                    'eligible' => 0.0,
                    'pending' => 0.0,
                    'carry' => 0.0,
                    'ghost' => 0.0,
                    'mismatches' => 0,
                ];
            }
            $map[$key]['rows']++;
            $tax = (float) ($m['tax'] ?? 0);
            $st = $m['status'] ?? 'pending';
            if ($st !== 'ghost') {
                $map[$key]['claimed'] += $tax;
            }
            if ($st === 'matched') {
                $map[$key]['eligible'] += $tax;
            } elseif ($st === 'carry') {
                $map[$key]['carry'] += $tax;
            } elseif ($st === 'ghost') {
                $map[$key]['ghost'] += $tax;
            } else {
                $map[$key]['pending'] += $tax;
            }
            if (!empty($m['mismatch_keys']) && ($m['match_quality'] ?? '') !== 'books_only') {
                $map[$key]['mismatches']++;
            }
        }
        $out = array_values($map);
        usort($out, function ($a, $b) {
            return $b['claimed'] <=> $a['claimed'];
        });
        foreach ($out as &$v) {
            $v['claimed'] = round($v['claimed'], 2);
            $v['eligible'] = round($v['eligible'], 2);
            $v['pending'] = round($v['pending'], 2);
            $v['carry'] = round($v['carry'], 2);
            $v['ghost'] = round($v['ghost'], 2);
        }
        unset($v);
        return $out;
    }

    private function eligibleItcNotes()
    {
        return [
            [
                'kind' => 'eligible',
                'title' => 'Eligible ITC (Approved)',
                'body' => 'Only Approved (matched) purchases reduce net tax in GSTR-3B. The vendor handshake is treated as present in your GSTR-2B.',
            ],
            [
                'kind' => 'ineligible',
                'title' => 'Pending',
                'body' => 'Claimed from expenses but not confirmed in GSTR-2B yet. Kept out of eligible ITC until you Approve.',
            ],
            [
                'kind' => 'ineligible',
                'title' => 'Carry-forward',
                'body' => 'Not approved this period. Available next month when the vendor files late.',
            ],
            [
                'kind' => 'ineligible',
                'title' => 'Ghost / unearned',
                'body' => 'Credits under your GSTIN with no matching books purchase — typos, wrong GSTIN, or fraud. Dismiss so they never enter eligible ITC.',
            ],
            [
                'kind' => 'ineligible',
                'title' => 'Missing GSTIN',
                'body' => 'Purchases without a vendor GSTIN cannot be matched to GSTR-2B. Capture GSTIN on the expense before approving.',
            ],
        ];
    }

    private function loadDismissedGhosts($business_id, $period)
    {
        $out = [];
        $stmt = $this->connect->prepare(
            "SELECT * FROM gst_itc_reconcile WHERE business_id = ? AND period = ? AND source = 'ghost' AND notes LIKE 'DISMISSED%' ORDER BY updated_at DESC"
        );
        if (!$stmt) {
            return $out;
        }
        $stmt->bind_param('is', $business_id, $period);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) {
            $out[] = [
                'id' => $r['source_ref'],
                'vendor' => $r['vendor_name'],
                'gstin' => $r['vendor_gstin'],
                'invoice_no' => $this->ghostInvoiceNo($r),
                'date' => $r['doc_date'],
                'taxable' => (float) $r['taxable'],
                'tax' => (float) ($r['gst_portal'] ?: $r['gst_claimed']),
                'reason' => $r['notes'],
            ];
        }
        $stmt->close();
        return $out;
    }

    private function carryHistory($business_id)
    {
        $out = [];
        $stmt = $this->connect->prepare(
            "SELECT period,
                SUM(CASE WHEN status <> 'ghost' THEN gst_claimed ELSE 0 END) AS claimed,
                SUM(CASE WHEN status = 'matched' THEN gst_claimed ELSE 0 END) AS approved,
                SUM(CASE WHEN status IN ('pending','carry') THEN gst_claimed ELSE 0 END) AS remainder,
                SUM(CASE WHEN status = 'carry' THEN gst_claimed ELSE 0 END) AS carry_amt,
                SUM(CASE WHEN status = 'pending' THEN gst_claimed ELSE 0 END) AS pending_amt
             FROM gst_itc_reconcile
             WHERE business_id = ?
             GROUP BY period
             ORDER BY period DESC"
        );
        if ($stmt) {
            $stmt->bind_param('i', $business_id);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($r = $res->fetch_assoc()) {
                $claimed = (float) $r['claimed'];
                $approved = (float) $r['approved'];
                $out[] = [
                    'period' => $r['period'],
                    'period_label' => self::periodLabel($r['period']),
                    'claimed' => round($claimed, 2),
                    'approved' => round($approved, 2),
                    'remainder' => round((float) $r['remainder'], 2),
                    'carry' => round((float) $r['carry_amt'], 2),
                    'pending' => round((float) $r['pending_amt'], 2),
                ];
            }
            $stmt->close();
        }
        return $out;
    }

    /* ------------------------------------------------------------------ */
    /*  GSTR-3B                                                            */
    /* ------------------------------------------------------------------ */

    public function getGstr3b(array $in)
    {
        [$ctx, $err] = $this->requireBusinessPeriod($in);
        if ($err) {
            return $err;
        }
        $computed = $this->compute3b($ctx['business_id'], $ctx['period'], $ctx['location_id']);
        $this->save3bSummary($ctx['business_id'], $ctx['period'], $computed, $computed['status'] ?? 'computed');
        $this->updatePeriodStatus($ctx['business_id'], $ctx['period'], [
            'gstr3b_status' => $computed['status'] ?? 'computed',
        ]);
        return $this->ok($computed);
    }

    public function offsetLiability(array $in)
    {
        [$ctx, $err] = $this->requireBusinessPeriod($in);
        if ($err) {
            return $err;
        }
        $computed = $this->compute3b($ctx['business_id'], $ctx['period'], $ctx['location_id']);
        $cred = $this->getCredentials($ctx['business_id']);
        $payload = $this->buildOffsetPayload($cred, $ctx['period'], $computed, $in);
        $portal = null;
        if ($cred && $this->tokenValid($cred) && empty($in['local_only'])) {
            $res = $this->perione(
                'PUT',
                'gstr3b/offset',
                $cred,
                ['ret_period' => self::retPeriod($ctx['period']), 'period' => $ctx['period']],
                $payload,
                'offset-liability'
            );
            $portal = $res['decoded'];
            if (!$this->perioneOk($res)) {
                $this->save3bSummary($ctx['business_id'], $ctx['period'], $computed, 'error', $payload, $this->extractRef($res['decoded']));
                return $this->err($this->perioneMessage($res, 'Offset failed'), [
                    'data' => ['computation' => $computed, 'payload' => $payload, 'portal' => $portal],
                ]);
            }
            $computed['offset_ref_id'] = $this->extractRef($res['decoded']);
            $offCharges = $this->extractCharges($res['decoded']);
            $computed['interest'] = $offCharges['interest'];
            $computed['late_fee'] = $offCharges['late_fee'];
            $computed['charges_source'] = $offCharges['source'];
            $computed['arn'] = $this->extractArn($res['decoded']);
        }
        $computed['status'] = 'offset';
        $this->save3bSummary($ctx['business_id'], $ctx['period'], $computed, 'offset', $payload, $computed['offset_ref_id'] ?? '');
        $this->updatePeriodStatus($ctx['business_id'], $ctx['period'], [
            'gstr3b_status' => 'offset',
            'gstr3b_ref_id' => $computed['offset_ref_id'] ?? '',
        ]);
        $computed['payload'] = $payload;
        $computed['portal'] = $portal;
        $computed['table_6_1'] = $this->buildTable61($payload, [
            'interest' => $computed['interest'] ?? $this->emptyTaxHead(),
            'late_fee' => $computed['late_fee'] ?? $this->emptyTaxHead(),
        ]);
        return $this->ok($computed, 'Liability offset prepared.');
    }

    public function fileGstr3b(array $in)
    {
        [$ctx, $err] = $this->requireBusinessPeriod($in);
        if ($err) {
            return $err;
        }
        $cred = $this->getCredentials($ctx['business_id']);
        if (!$cred || !$this->tokenValid($cred)) {
            return $this->err('GST session expired. Authenticate first.');
        }
        $otp = trim((string) ($in['otp'] ?? ''));
        $ret = self::retPeriod($ctx['period']);
        if ($otp === '') {
            $res = $this->perione('GET', 'authentication/otpforevc', $cred, [
                'ret_period' => $ret,
                'form_type' => 'R3B',
                'period' => $ctx['period'],
            ], null, 'gstr3b-otp-evc');
            if (!$this->perioneOk($res)) {
                return $this->err($this->perioneMessage($res, 'EVC OTP failed'), ['portal' => $res['decoded']]);
            }
            return $this->ok(['needs_otp' => true, 'form' => 'GSTR3B'], 'EVC OTP sent. Call file-gstr3b.php again with otp.');
        }
        $computed = $this->compute3b($ctx['business_id'], $ctx['period'], $ctx['location_id']);
        $body = [
            'gstin' => $cred['gstin'],
            'ret_period' => $ret,
            'otp' => $otp,
            'st' => $in['st'] ?? 'EVC',
        ];
        $res = $this->perione('POST', 'gstr3b/retfile', $cred, [
            'ret_period' => $ret,
            'period' => $ctx['period'],
        ], $body, 'file-gstr3b');
        if (!$this->perioneOk($res)) {
            $this->updatePeriodStatus($ctx['business_id'], $ctx['period'], ['gstr3b_status' => 'error']);
            return $this->err($this->perioneMessage($res, 'GSTR-3B file failed'), ['portal' => $res['decoded']]);
        }
        $ref = $this->extractRef($res['decoded']);
        $computed['file_ref_id'] = $ref;
        $computed['status'] = 'filed';
        $this->save3bSummary($ctx['business_id'], $ctx['period'], $computed, 'filed', $body, $ref);
        $this->updatePeriodStatus($ctx['business_id'], $ctx['period'], [
            'gstr3b_status' => 'filed',
            'gstr3b_ref_id' => $ref,
            'gstr3b_filed_at' => date('Y-m-d H:i:s'),
        ]);
        $arn = $this->extractArn($res['decoded']);
        $charges = $this->extractCharges($res['decoded']);
        return $this->ok([
            'filed' => true,
            'ref_id' => $ref,
            'arn' => $arn,
            'interest' => $charges['interest'],
            'late_fee' => $charges['late_fee'],
            'computation' => $computed,
            'portal' => $res['decoded'],
        ], 'GSTR-3B filed.');
    }

    private function compute3b($business_id, $period, $location_id)
    {
        $sales = $this->loadSalesInvoices($business_id, $period, $location_id);
        $purch = $this->attachItcStatus($business_id, $period, $this->loadPurchaseExpenses($business_id, $period, $location_id));
        $outTx = $outC = $outS = $outI = 0.0;
        $row31a = $this->emptyTaxHead();
        $row31b = $this->emptyTaxHead();
        $row31c = $this->emptyTaxHead();
        $row31d = $this->emptyTaxHead();
        $row31e = $this->emptyTaxHead();
        $bySupply = [
            'B2B' => $this->emptyTaxHead(),
            'B2CS' => $this->emptyTaxHead(),
            'B2CL' => $this->emptyTaxHead(),
        ];
        foreach ($sales as $r) {
            $outTx += $r['taxable'];
            $outC += $r['cgst'];
            $outS += $r['sgst'];
            $outI += $r['igst'];
            $head = [
                'txval' => (float) $r['taxable'],
                'iamt' => (float) $r['igst'],
                'camt' => (float) $r['cgst'],
                'samt' => (float) $r['sgst'],
                'csamt' => 0.0,
            ];
            $st = (string) ($r['supply_type'] ?? 'B2CS');
            if (!isset($bySupply[$st])) {
                $bySupply[$st] = $this->emptyTaxHead();
            }
            $bySupply[$st] = $this->addTaxHead($bySupply[$st], $head);
            if ((float) $r['gst'] <= 0) {
                $row31c = $this->addTaxHead($row31c, $head);
            } else {
                $row31a = $this->addTaxHead($row31a, $head);
            }
        }
        $itcC = $itcS = $itcI = $pending = $carry = 0.0;
        foreach ($purch as $r) {
            if (($r['status'] ?? '') === 'ghost') {
                continue;
            }
            $gst = (float) $r['gstClaimed'];
            if (($r['status'] ?? '') === 'matched') {
                if ($r['gstin'] !== '' && self::stateFromGstin($r['gstin']) !== $this->homeState($business_id)) {
                    $itcI += $gst;
                } else {
                    $half = round($gst / 2, 2);
                    $itcC += $half;
                    $itcS += round($gst - $half, 2);
                }
            } elseif (($r['status'] ?? '') === 'carry') {
                $carry += $gst;
            } else {
                $pending += $gst;
            }
        }
        $netC = round($outC - $itcC, 2);
        $netS = round($outS - $itcS, 2);
        $netI = round($outI - $itcI, 2);
        $netTot = round($netC + $netS + $netI, 2);
        $cash = max(0, $netTot);
        $itcHead = [
            'txval' => 0.0,
            'iamt' => round($itcI, 2),
            'camt' => round($itcC, 2),
            'samt' => round($itcS, 2),
            'csamt' => 0.0,
        ];
        $zero = $this->emptyTaxHead();
        $netItc = $itcHead;
        $cred = $this->getCredentials($business_id) ?: [];
        $stored = $this->load3bStored($business_id, $period);
        $status = 'computed';
        if (!empty($stored['status']) && in_array($stored['status'], ['offset', 'filed', 'error'], true)) {
            $status = $stored['status'];
        }
        $charges = $this->chargesForPeriod($business_id, $period);
        $base = [
            'period' => $period,
            'period_label' => self::periodLabel($period),
            'ret_period' => self::retPeriod($period),
            'output_taxable' => round($outTx, 2),
            'output_cgst' => round($outC, 2),
            'output_sgst' => round($outS, 2),
            'output_igst' => round($outI, 2),
            'output_cess' => 0,
            'output_gst' => round($outC + $outS + $outI, 2),
            'itc_cgst' => round($itcC, 2),
            'itc_sgst' => round($itcS, 2),
            'itc_igst' => round($itcI, 2),
            'itc_cess' => 0,
            'itc_eligible' => round($itcC + $itcS + $itcI, 2),
            'itc_pending' => round($pending, 2),
            'itc_carry' => round($carry, 2),
            'net_cgst' => $netC,
            'net_sgst' => $netS,
            'net_igst' => $netI,
            'net_total' => $netTot,
            'cash_to_pay' => $cash,
            'gross_output' => round($outC + $outS + $outI, 2),
            'invoice_count' => count($sales),
            'status' => $status,
            'offset_ref_id' => (string) ($stored['offset_ref_id'] ?? ''),
            'file_ref_id' => (string) ($stored['file_ref_id'] ?? ''),
            'auth_required' => !$this->tokenValid($cred),
            'eco_dtls' => [
                'txval' => 0,
                'iamt' => 0,
                'camt' => 0,
                'samt' => 0,
                'csamt' => 0,
                'required' => true,
                'note' => 'Perione / GSTN require eco_dtls (supplies through e-commerce operators) on every GSTR-3B offset, even when all values are zero.',
            ],
            'table_3_1' => [
                ['code' => '3.1(a)', 'nature' => 'Outward taxable supplies (other than zero rated, nil rated and exempted)', 'note' => 'Mapped from completed sales invoices with GST > 0 (B2B / B2CS / B2CL).'] + $row31a,
                ['code' => '3.1(b)', 'nature' => 'Outward taxable supplies (zero rated)', 'note' => 'Not tracked separately in books — placeholder zero.'] + $row31b,
                ['code' => '3.1(c)', 'nature' => 'Other outward supplies (nil rated, exempted)', 'note' => 'Mapped from invoices with GST = 0 in this period.'] + $row31c,
                ['code' => '3.1(d)', 'nature' => 'Inward supplies (liable to reverse charge)', 'note' => 'RCM not captured on sales invoices — placeholder zero.'] + $row31d,
                ['code' => '3.1(e)', 'nature' => 'Non-GST outward supplies', 'note' => 'Non-GST supplies not captured — placeholder zero.'] + $row31e,
            ],
            'table_3_1_by_supply' => [
                ['code' => 'B2B', 'nature' => 'Registered customers (GSTIN on invoice)'] + $bySupply['B2B'],
                ['code' => 'B2CS', 'nature' => 'Unregistered / consumer supplies'] + $bySupply['B2CS'],
                ['code' => 'B2CL', 'nature' => 'Interstate unregistered above ₹2.5 lakh'] + $bySupply['B2CL'],
            ],
            'table_4' => [
                ['code' => '4(A)(1)', 'nature' => 'Import of goods', 'note' => 'Not mapped from expenses.'] + $zero,
                ['code' => '4(A)(2)', 'nature' => 'Import of services', 'note' => 'Not mapped from expenses.'] + $zero,
                ['code' => '4(A)(3)', 'nature' => 'Inward supplies liable to reverse charge', 'note' => 'Not mapped.'] + $zero,
                ['code' => '4(A)(4)', 'nature' => 'Inward supplies from ISD', 'note' => 'Not mapped.'] + $zero,
                ['code' => '4(A)(5)', 'nature' => 'All other ITC', 'note' => 'Matched / approved purchase ITC for the period.'] + $itcHead,
                ['code' => '4(B)(1)', 'nature' => 'ITC reversed as per rules 42 & 43', 'note' => 'Placeholder.'] + $zero,
                ['code' => '4(B)(2)', 'nature' => 'Others reversed', 'note' => 'Placeholder.'] + $zero,
                ['code' => '4(C)', 'nature' => 'Net ITC available (A) − (B)', 'note' => 'Equals approved ITC used in computation.'] + $netItc,
                ['code' => '4(D)(1)', 'nature' => 'Ineligible ITC as per section 17(5)', 'note' => 'Placeholder.'] + $zero,
                ['code' => '4(D)(2)', 'nature' => 'Others ineligible', 'note' => 'Placeholder.'] + $zero,
            ],
            'interest' => $charges['interest'],
            'late_fee' => $charges['late_fee'],
            'charges_source' => $charges['source'],
            'charges_note' => $charges['note'],
        ];
        $preview = $this->buildOffsetPayload($cred, $period, $base, []);
        $base['offset_preview'] = $preview;
        $base['table_6_1'] = $this->buildTable61($preview, $charges);
        $base['stored'] = $stored;
        return $base;
    }

    private function emptyTaxHead()
    {
        return ['txval' => 0.0, 'iamt' => 0.0, 'camt' => 0.0, 'samt' => 0.0, 'csamt' => 0.0];
    }

    private function addTaxHead(array $a, array $b)
    {
        foreach (['txval', 'iamt', 'camt', 'samt', 'csamt'] as $k) {
            $a[$k] = round(((float) ($a[$k] ?? 0)) + ((float) ($b[$k] ?? 0)), 2);
        }
        return $a;
    }

    private function moneyHeads($src, array $fallback)
    {
        if (!is_array($src)) {
            $src = [];
        }
        $out = [];
        foreach (['iamt', 'camt', 'samt', 'csamt'] as $k) {
            if (array_key_exists($k, $src) && $src[$k] !== '' && $src[$k] !== null) {
                $out[$k] = round((float) $src[$k], 2);
            } else {
                $out[$k] = round((float) ($fallback[$k] ?? 0), 2);
            }
        }
        return $out;
    }

    private function buildOffsetPayload($cred, $period, array $c, array $in)
    {
        $netI = max(0, (float) ($c['net_igst'] ?? 0));
        $netC = max(0, (float) ($c['net_cgst'] ?? 0));
        $netS = max(0, (float) ($c['net_sgst'] ?? 0));
        $useRaw = $in['use_itc'] ?? true;
        $useItc = !($useRaw === false || $useRaw === '0' || $useRaw === 0 || $useRaw === 'false');
        $defaultNet = ['iamt' => $netI, 'camt' => $netC, 'samt' => $netS, 'csamt' => 0.0];
        $defaultPditc = $useItc ? [
            'iamt' => min((float) ($c['itc_igst'] ?? 0), $netI + $netC + $netS),
            'camt' => min((float) ($c['itc_cgst'] ?? 0), $netC),
            'samt' => min((float) ($c['itc_sgst'] ?? 0), $netS),
            'csamt' => 0.0,
        ] : ['iamt' => 0.0, 'camt' => 0.0, 'samt' => 0.0, 'csamt' => 0.0];
        $netSrc = $in['Nettaxpay'] ?? $in['net_tax_pay'] ?? $in['nettaxpay'] ?? null;
        $pditcSrc = $in['pditc'] ?? null;
        $pdcashSrc = $in['pdcash'] ?? null;
        $ecoSrc = $in['ecodtls'] ?? $in['eco_dtls'] ?? null;
        $net = $this->moneyHeads($netSrc, $defaultNet);
        $pditc = $this->moneyHeads($pditcSrc, $defaultPditc);
        $defaultCash = [
            'iamt' => max(0, round($net['iamt'] - $pditc['iamt'], 2)),
            'camt' => max(0, round($net['camt'] - $pditc['camt'], 2)),
            'samt' => max(0, round($net['samt'] - $pditc['samt'], 2)),
            'csamt' => max(0, round($net['csamt'] - $pditc['csamt'], 2)),
        ];
        $pdcash = $this->moneyHeads($pdcashSrc, $defaultCash);
        $eco = $this->moneyHeads($ecoSrc, ['iamt' => 0, 'camt' => 0, 'samt' => 0, 'csamt' => 0]);
        $ecoTx = 0.0;
        if (is_array($ecoSrc) && isset($ecoSrc['txval']) && $ecoSrc['txval'] !== '') {
            $ecoTx = round((float) $ecoSrc['txval'], 2);
        }
        return [
            'gstin' => is_array($cred) ? (string) ($cred['gstin'] ?? '') : '',
            'ret_period' => self::retPeriod($period),
            'Nettaxpay' => $net,
            'pditc' => $pditc,
            'pdcash' => $pdcash,
            'ecodtls' => [
                'txval' => $ecoTx,
                'iamt' => $eco['iamt'],
                'camt' => $eco['camt'],
                'samt' => $eco['samt'],
                'csamt' => $eco['csamt'],
            ],
        ];
    }

    private function buildTable61(array $payload, array $charges)
    {
        $net = $payload['Nettaxpay'] ?? $this->emptyTaxHead();
        $itc = $payload['pditc'] ?? $this->emptyTaxHead();
        $cash = $payload['pdcash'] ?? $this->emptyTaxHead();
        $int = $charges['interest'] ?? $this->emptyTaxHead();
        $fee = $charges['late_fee'] ?? $this->emptyTaxHead();
        $map = [
            ['head' => 'IGST', 'k' => 'iamt'],
            ['head' => 'CGST', 'k' => 'camt'],
            ['head' => 'SGST / UTGST', 'k' => 'samt'],
            ['head' => 'Cess', 'k' => 'csamt'],
        ];
        $rows = [];
        foreach ($map as $m) {
            $k = $m['k'];
            $rows[] = [
                'head' => $m['head'],
                'tax_payable' => (float) ($net[$k] ?? 0),
                'paid_itc' => (float) ($itc[$k] ?? 0),
                'paid_cash' => (float) ($cash[$k] ?? 0),
                'interest' => (float) ($int[$k] ?? 0),
                'late_fee' => (float) ($fee[$k] ?? 0),
            ];
        }
        return $rows;
    }

    private function load3bStored($business_id, $period)
    {
        if (!$this->dbOk()) {
            return null;
        }
        $stmt = $this->connect->prepare(
            'SELECT * FROM gst_gstr3b_summary WHERE business_id = ? AND period = ? LIMIT 1'
        );
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('is', $business_id, $period);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }

    private function save3bSummary($business_id, $period, array $c, $status, $payload = null, $ref = '')
    {
        $json = $payload ? json_encode($payload, self::JSON_FLAGS) : null;
        $sql = 'INSERT INTO gst_gstr3b_summary
            (business_id, period, output_taxable, output_cgst, output_sgst, output_igst, output_cess,
             itc_cgst, itc_sgst, itc_igst, itc_cess, net_cgst, net_sgst, net_igst, net_total,
             cash_to_pay, itc_pending, itc_carry, offset_ref_id, file_ref_id, status, payload_json)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE
              output_taxable=VALUES(output_taxable), output_cgst=VALUES(output_cgst), output_sgst=VALUES(output_sgst),
              output_igst=VALUES(output_igst), itc_cgst=VALUES(itc_cgst), itc_sgst=VALUES(itc_sgst),
              itc_igst=VALUES(itc_igst), net_cgst=VALUES(net_cgst), net_sgst=VALUES(net_sgst),
              net_igst=VALUES(net_igst), net_total=VALUES(net_total), cash_to_pay=VALUES(cash_to_pay),
              itc_pending=VALUES(itc_pending), itc_carry=VALUES(itc_carry), status=VALUES(status),
              payload_json=IF(VALUES(payload_json) IS NULL, payload_json, VALUES(payload_json)),
              offset_ref_id=IF(VALUES(offset_ref_id)=\'\', offset_ref_id, VALUES(offset_ref_id)),
              file_ref_id=IF(VALUES(file_ref_id)=\'\', file_ref_id, VALUES(file_ref_id))';
        $stmt = $this->connect->prepare($sql);
        if (!$stmt) {
            return;
        }
        $ot = $c['output_taxable'];
        $oc = $c['output_cgst'];
        $os = $c['output_sgst'];
        $oi = $c['output_igst'];
        $oce = 0.0;
        $ic = $c['itc_cgst'];
        $is = $c['itc_sgst'];
        $ii = $c['itc_igst'];
        $ice = 0.0;
        $nc = $c['net_cgst'];
        $ns = $c['net_sgst'];
        $ni = $c['net_igst'];
        $nt = $c['net_total'];
        $cash = $c['cash_to_pay'];
        $pend = $c['itc_pending'];
        $carry = $c['itc_carry'];
        $off = (string) ($c['offset_ref_id'] ?? $ref);
        $file = (string) ($c['file_ref_id'] ?? '');
        $stmt->bind_param(
            'isddddddddddddddddssss',
            $business_id,
            $period,
            $ot, $oc, $os, $oi, $oce,
            $ic, $is, $ii, $ice,
            $nc, $ns, $ni, $nt,
            $cash, $pend, $carry,
            $off, $file, $status, $json
        );
        $stmt->execute();
        $stmt->close();
    }

    /* ------------------------------------------------------------------ */
    /*  Carry-forward / return status                                      */
    /* ------------------------------------------------------------------ */

    public function getCarryForward(array $in)
    {
        [$ctx, $err] = $this->requireBusinessPeriod($in);
        if ($err) {
            return $err;
        }
        $purch = $this->attachItcStatus(
            $ctx['business_id'],
            $ctx['period'],
            $this->loadPurchaseExpenses($ctx['business_id'], $ctx['period'], $ctx['location_id'])
        );
        $rows = [];
        $sum = 0.0;
        foreach ($purch as $r) {
            $st = $r['status'] ?? 'pending';
            if ($st === 'carry' || $st === 'pending') {
                $rows[] = $r;
                $sum += $r['gstClaimed'];
            }
        }
        $stored = [];
        $stmt = $this->connect->prepare(
            'SELECT * FROM gst_carry_forward WHERE business_id = ? AND from_period = ? AND status = \'open\' ORDER BY id DESC'
        );
        if ($stmt) {
            $stmt->bind_param('is', $ctx['business_id'], $ctx['period']);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($r = $res->fetch_assoc()) {
                $stored[] = $r;
            }
            $stmt->close();
        }
        return $this->ok([
            'period' => $ctx['period'],
            'period_label' => self::periodLabel($ctx['period']),
            'invoices' => $rows,
            'stored' => $stored,
            'history' => $this->carryHistory($ctx['business_id']),
            'totals' => [
                'outstanding' => round($sum, 2),
                'pending' => round(array_sum(array_map(function ($r) {
                    return ($r['status'] ?? '') === 'pending' ? $r['gstClaimed'] : 0;
                }, $rows)), 2),
                'carry' => round(array_sum(array_map(function ($r) {
                    return ($r['status'] ?? '') === 'carry' ? $r['gstClaimed'] : 0;
                }, $rows)), 2),
            ],
            'total' => round($sum, 2),
            'count' => count($rows),
        ]);
    }

    public function getReturnStatus(array $in)
    {
        [$ctx, $err] = $this->requireBusinessPeriod($in);
        if ($err) {
            return $err;
        }
        $local = $this->ensurePeriod($ctx['business_id'], $ctx['period']);
        $portal = null;
        $cred = $this->getCredentials($ctx['business_id']);
        if ($cred && $this->tokenValid($cred)) {
            $res = $this->perione('GET', 'all/newretstatus', $cred, [
                'ret_period' => self::retPeriod($ctx['period']),
                'ref_id' => $in['ref_id'] ?? ($local['gstr1_ref_id'] ?? $local['gstr3b_ref_id'] ?? ''),
                'period' => $ctx['period'],
            ], null, 'get-return-status');
            $portal = $res['decoded'];
            if (!$this->perioneOk($res)) {
                $res2 = $this->perione('GET', 'all/returnFillingStatus', $cred, [
                    'gstin' => $cred['gstin'],
                    'fy' => $this->fyFromPeriod($ctx['period']),
                ], null, 'return-filing-status');
                $portal = $res2['decoded'];
            }
        }
        $portalArr = is_array($portal) ? $portal : [];
        $charges = $this->extractCharges($portalArr);
        $arn = $this->extractArn($portalArr);
        $ref = $this->extractRef($portalArr);
        if ($ref === '') {
            $ref = (string) ($in['ref_id'] ?? $local['gstr3b_ref_id'] ?? $local['gstr1_ref_id'] ?? '');
        }
        $out = [
            'period' => $ctx['period'],
            'period_label' => self::periodLabel($ctx['period']),
            'ret_period' => self::retPeriod($ctx['period']),
            'arn' => $arn,
            'ref_id' => $ref,
            'interest' => $charges['interest'],
            'late_fee' => $charges['late_fee'],
            'charges_source' => $charges['source'],
            'charges_note' => $charges['note'],
            'local' => [
                'gstr1_status' => $local['gstr1_status'] ?? 'pending',
                'gstr2b_status' => $local['gstr2b_status'] ?? 'pending',
                'gstr3b_status' => $local['gstr3b_status'] ?? 'pending',
                'gstr1_ref_id' => $local['gstr1_ref_id'] ?? null,
                'gstr3b_ref_id' => $local['gstr3b_ref_id'] ?? null,
                'gstr1_filed_at' => $local['gstr1_filed_at'] ?? null,
                'gstr3b_filed_at' => $local['gstr3b_filed_at'] ?? null,
                'last_synced_at' => $local['last_synced_at'] ?? null,
            ],
            'portal' => $portal,
            'auth_required' => !$cred || !$this->tokenValid($cred),
        ];
        if (!empty($in['include_history'])) {
            $hist = $this->listFilingHistory([
                'business_id' => $ctx['business_id'],
                'limit' => (int) ($in['history_limit'] ?? 24),
            ]);
            $out['history'] = $hist['data']['rows'] ?? [];
        }
        return $this->ok($out);
    }

    /* ------------------------------------------------------------------ */
    /*  Admin extras (logs, credentials, comparison, filing history)       */
    /* ------------------------------------------------------------------ */

    public function listApiLogs(array $in)
    {
        [$ctx, $err] = $this->requireBusinessPeriod($in, false);
        if ($err) {
            return $err;
        }
        $business_id = $ctx['business_id'];
        $period = $ctx['period'];
        $endpoint = trim((string) ($in['endpoint'] ?? ''));
        $action = trim((string) ($in['action'] ?? ''));
        $outcome = strtolower(trim((string) ($in['outcome'] ?? $in['success'] ?? '')));
        if ($outcome === 'fail' || $outcome === 'failed' || $outcome === 'error') {
            $outcome = 'fail';
        } elseif ($outcome === 'ok' || $outcome === 'success' || $outcome === '1' || $outcome === 'true') {
            $outcome = 'success';
        } else {
            $outcome = '';
        }
        $limit = (int) ($in['limit'] ?? 50);
        if ($limit < 1) {
            $limit = 50;
        }
        if ($limit > 200) {
            $limit = 200;
        }
        $offset = max(0, (int) ($in['offset'] ?? 0));
        $where = ['business_id = ?'];
        $types = 'i';
        $params = [$business_id];
        if ($period !== '') {
            $where[] = 'period = ?';
            $types .= 's';
            $params[] = $period;
        }
        if ($action !== '') {
            $where[] = 'action = ?';
            $types .= 's';
            $params[] = $action;
        }
        if ($endpoint !== '') {
            $where[] = '(endpoint LIKE ? OR action LIKE ?)';
            $types .= 'ss';
            $like = '%' . $endpoint . '%';
            $params[] = $like;
            $params[] = $like;
        }
        if ($outcome === 'success') {
            $where[] = "(status = 'ok' OR (http_code >= 200 AND http_code < 300))";
        } elseif ($outcome === 'fail') {
            $where[] = "(IFNULL(status,'') <> 'ok' AND (http_code IS NULL OR http_code < 200 OR http_code >= 300))";
        }
        $sqlWhere = implode(' AND ', $where);
        $total = 0;
        $cnt = $this->connect->prepare("SELECT COUNT(*) AS c FROM gst_api_logs WHERE $sqlWhere");
        if ($cnt) {
            $cnt->bind_param($types, ...$params);
            $cnt->execute();
            $cr = $cnt->get_result()->fetch_assoc();
            $total = (int) ($cr['c'] ?? 0);
            $cnt->close();
        }
        $sql = "SELECT id, business_id, period, action, endpoint, method, http_code, status,
                       request_json, response_json, created_at
                FROM gst_api_logs WHERE $sqlWhere
                ORDER BY id DESC LIMIT ? OFFSET ?";
        $types2 = $types . 'ii';
        $params2 = $params;
        $params2[] = $limit;
        $params2[] = $offset;
        $stmt = $this->connect->prepare($sql);
        $rows = [];
        if ($stmt) {
            $stmt->bind_param($types2, ...$params2);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($r = $res->fetch_assoc()) {
                $r['ok'] = ((string) ($r['status'] ?? '') === 'ok')
                    || ((int) $r['http_code'] >= 200 && (int) $r['http_code'] < 300);
                $rows[] = $r;
            }
            $stmt->close();
        }
        $actions = [];
        $actStmt = $this->connect->prepare(
            'SELECT DISTINCT action FROM gst_api_logs WHERE business_id = ? AND action <> \'\' ORDER BY action ASC'
        );
        if ($actStmt) {
            $actStmt->bind_param('i', $business_id);
            $actStmt->execute();
            $ar = $actStmt->get_result();
            while ($a = $ar->fetch_assoc()) {
                $actions[] = $a['action'];
            }
            $actStmt->close();
        }
        return $this->ok([
            'rows' => $rows,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'actions' => $actions,
        ]);
    }

    public function getSafeCredentials(array $in)
    {
        [$ctx, $err] = $this->requireBusinessPeriod($in, false);
        if ($err) {
            return $err;
        }
        $row = $this->getCredentials($ctx['business_id']);
        if (!$row) {
            return $this->ok([
                'gstin' => '',
                'gst_username' => '',
                'gst_email' => '',
                'state_cd' => '',
                'session_valid' => false,
                'last_auth_at' => null,
                'token_expiry' => null,
            ], 'No GST credentials saved yet.');
        }
        $expiry = $row['token_expiry'] ?? null;
        $lastAuth = null;
        if (!empty($expiry) && strtotime((string) $expiry)) {
            $lastAuth = date('Y-m-d H:i:s', strtotime((string) $expiry) - self::AUTH_VALIDITY_SECONDS);
        }
        return $this->ok([
            'gstin' => (string) ($row['gstin'] ?? ''),
            'gst_username' => (string) ($row['gst_username'] ?? ''),
            'gst_email' => (string) ($row['gst_email'] ?? ''),
            'state_cd' => (string) ($row['state_cd'] ?? ''),
            'ip_address' => (string) ($row['ip_address'] ?? ''),
            'is_active' => (int) ($row['is_active'] ?? 1),
            'token_expiry' => $expiry,
            'last_auth_at' => $lastAuth,
            'updated_at' => $row['updated_at'] ?? null,
            'session_valid' => $this->tokenValid($row),
        ]);
    }

    public function saveSafeCredentials(array $in)
    {
        [$ctx, $err] = $this->requireBusinessPeriod($in, false);
        if ($err) {
            return $err;
        }
        $fields = [];
        if (isset($in['gstin'])) {
            $fields['gstin'] = strtoupper(preg_replace('/\s+/', '', (string) $in['gstin']));
            $fields['state_cd'] = self::stateFromGstin($fields['gstin']);
        }
        if (isset($in['gst_username'])) {
            $fields['gst_username'] = trim((string) $in['gst_username']);
        }
        if (isset($in['gst_email']) || isset($in['email'])) {
            $fields['gst_email'] = trim((string) ($in['gst_email'] ?? $in['email']));
        }
        if (isset($in['ip_address'])) {
            $fields['ip_address'] = trim((string) $in['ip_address']);
        }
        if (!$fields) {
            return $this->err('Nothing to save.');
        }
        $this->upsertCredentials($ctx['business_id'], $fields);
        return $this->getSafeCredentials($in);
    }

    public function comparePeriods(array $in)
    {
        [$ctx, $err] = $this->requireBusinessPeriod($in, false);
        if ($err) {
            return $err;
        }
        $end = $ctx['period'] !== '' ? $ctx['period'] : date('Y-m');
        $months = (int) ($in['months'] ?? 6);
        if ($months < 2) {
            $months = 6;
        }
        if ($months > 12) {
            $months = 12;
        }
        $rows = [];
        $ts = strtotime($end . '-01');
        if ($ts === false) {
            $ts = time();
        }
        for ($i = $months - 1; $i >= 0; $i--) {
            $p = date('Y-m', strtotime("-{$i} months", $ts));
            $c = $this->compute3b($ctx['business_id'], $p, $ctx['location_id']);
            $rows[] = [
                'period' => $p,
                'period_label' => self::periodLabel($p),
                'invoice_count' => (int) ($c['invoice_count'] ?? 0),
                'output_gst' => (float) ($c['output_gst'] ?? 0),
                'itc_eligible' => (float) ($c['itc_eligible'] ?? 0),
                'itc_pending' => (float) ($c['itc_pending'] ?? 0),
                'net_total' => (float) ($c['net_total'] ?? 0),
                'cash_to_pay' => (float) ($c['cash_to_pay'] ?? 0),
                'status' => (string) ($c['status'] ?? ''),
            ];
        }
        return $this->ok([
            'end_period' => $end,
            'months' => $months,
            'rows' => $rows,
        ]);
    }

    public function listFilingHistory(array $in)
    {
        [$ctx, $err] = $this->requireBusinessPeriod($in, false);
        if ($err) {
            return $err;
        }
        $limit = (int) ($in['limit'] ?? 24);
        if ($limit < 1) {
            $limit = 24;
        }
        if ($limit > 60) {
            $limit = 60;
        }
        $rows = [];
        $stmt = $this->connect->prepare(
            'SELECT period, ret_period, gstr1_status, gstr2b_status, gstr3b_status,
                    gstr1_ref_id, gstr3b_ref_id, gstr1_filed_at, gstr3b_filed_at,
                    last_synced_at, notes, updated_at
             FROM gst_return_periods
             WHERE business_id = ?
             ORDER BY period DESC
             LIMIT ?'
        );
        if ($stmt) {
            $stmt->bind_param('ii', $ctx['business_id'], $limit);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($r = $res->fetch_assoc()) {
                $r['period_label'] = self::periodLabel($r['period']);
                $r['r1'] = [
                    'form' => 'R1',
                    'status' => $r['gstr1_status'],
                    'ref_id' => $r['gstr1_ref_id'],
                    'filed_at' => $r['gstr1_filed_at'],
                ];
                $r['r3b'] = [
                    'form' => 'R3B',
                    'status' => $r['gstr3b_status'],
                    'ref_id' => $r['gstr3b_ref_id'],
                    'filed_at' => $r['gstr3b_filed_at'],
                ];
                $rows[] = $r;
            }
            $stmt->close();
        }
        return $this->ok(['rows' => $rows]);
    }

    private function findFirstValue($data, array $keys, $depth = 0)
    {
        if (!is_array($data) || $depth > 8) {
            return '';
        }
        foreach ($keys as $k) {
            if (!empty($data[$k]) && !is_array($data[$k])) {
                return (string) $data[$k];
            }
        }
        foreach ($data as $v) {
            if (is_array($v)) {
                $found = $this->findFirstValue($v, $keys, $depth + 1);
                if ($found !== '') {
                    return $found;
                }
            }
        }
        return '';
    }

    private function extractCharges($decoded)
    {
        $empty = $this->emptyTaxHead();
        $interest = $empty;
        $late = $empty;
        $source = 'placeholder';
        $found = false;
        if (is_array($decoded)) {
            $intSrc = $this->findNamedArray($decoded, ['interest', 'int_pmt', 'intr', 'Interest']);
            $feeSrc = $this->findNamedArray($decoded, ['late_fee', 'latefee', 'lfee', 'lateFee', 'Latefee']);
            if ($intSrc) {
                $interest = $this->moneyHeads($intSrc, $empty);
                if (isset($intSrc['txval'])) {
                    $interest['txval'] = round((float) $intSrc['txval'], 2);
                }
                $found = true;
            } else {
                $n = $this->findFirstValue($decoded, ['interest', 'int_pmt', 'intramt']);
                if ($n !== '' && is_numeric($n) && (float) $n != 0.0) {
                    $interest['iamt'] = round((float) $n, 2);
                    $found = true;
                }
            }
            if ($feeSrc) {
                $late = $this->moneyHeads($feeSrc, $empty);
                $found = true;
            } else {
                $n = $this->findFirstValue($decoded, ['late_fee', 'latefee', 'lfee', 'late_fee_amt']);
                if ($n !== '' && is_numeric($n) && (float) $n != 0.0) {
                    $late['iamt'] = round((float) $n, 2);
                    $found = true;
                }
            }
        }
        if ($found) {
            $source = 'portal';
        }
        return [
            'interest' => $interest,
            'late_fee' => $late,
            'source' => $source,
            'note' => $found
                ? 'Interest / late fee taken from the latest GST portal response.'
                : 'Interest and late fee are placeholders (₹0) until the portal returns them on offset, file, or return-status.',
        ];
    }

    private function findNamedArray($data, array $names, $depth = 0)
    {
        if (!is_array($data) || $depth > 8) {
            return null;
        }
        $want = [];
        foreach ($names as $n) {
            $want[strtolower((string) $n)] = true;
        }
        foreach ($data as $k => $v) {
            if (is_array($v) && isset($want[strtolower((string) $k)])) {
                return $v;
            }
        }
        foreach ($data as $v) {
            if (is_array($v)) {
                $found = $this->findNamedArray($v, $names, $depth + 1);
                if ($found) {
                    return $found;
                }
            }
        }
        return null;
    }

    private function chargesForPeriod($business_id, $period)
    {
        $empty = [
            'interest' => $this->emptyTaxHead(),
            'late_fee' => $this->emptyTaxHead(),
            'source' => 'placeholder',
            'note' => 'Interest and late fee are placeholders (₹0) until the portal returns them on offset, file, or return-status.',
        ];
        if (!$this->dbOk()) {
            return $empty;
        }
        $stmt = $this->connect->prepare(
            "SELECT response_json FROM gst_api_logs
             WHERE business_id = ? AND (period = ? OR period = ?)
               AND action IN ('offset-liability','file-gstr3b','get-return-status','return-filing-status','gstr3b-otp-evc')
             ORDER BY id DESC LIMIT 8"
        );
        if (!$stmt) {
            return $empty;
        }
        $ret = self::retPeriod($period);
        $stmt->bind_param('iss', $business_id, $period, $ret);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) {
            $decoded = json_decode((string) $r['response_json'], true);
            $ch = $this->extractCharges(is_array($decoded) ? $decoded : []);
            if ($ch['source'] === 'portal') {
                $stmt->close();
                return $ch;
            }
        }
        $stmt->close();
        return $empty;
    }

    private function fyFromPeriod($yyyyMm)
    {
        $y = (int) substr($yyyyMm, 0, 4);
        $m = (int) substr($yyyyMm, 5, 2);
        if ($m < 4) {
            $start = $y - 1;
        } else {
            $start = $y;
        }
        return $start . '-' . substr((string) ($start + 1), 2);
    }
}
