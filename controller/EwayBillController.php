<?php
/**
 * E-way bill generation (NIC API v1.03–style JSON via Perione).
 *
 * Inner request body matches the official sample / schema:
 * @link https://docs.ewaybillgst.gov.in/apidocs/version1.03/generate-eway-bill.html#requestSampleJSON
 */
// business/controller/EwayBillController.php

class EwayBillController
{
    private $connect;
    /** Perione e-way bill API base (no trailing slash required). */
    private $api_base_url = 'https://api.perione.in';
    private $auth_path = 'ewaybillapi/v1.03/authenticate';
    private $generate_path = 'ewaybillapi/v1.03/ewayapi/genewaybill';
    private $get_gstin_details_path = 'ewaybillapi/v1.03/ewayapi/getgstindetails';
    private $get_eway_path = 'ewaybillapi/v1.03/ewayapi/getewaybill';
    private $cancel_eway_path = 'ewaybillapi/v1.03/ewayapi/canewb';
    private $update_partb_path = 'ewaybillapi/v1.03/ewayapi/vehewb';
    private $update_transporter_path = 'ewaybillapi/v1.03/ewayapi/updatetransporter';

    /** Session hint from authenticate(); Perione may not return a bearer token. */
    private const AUTH_VALIDITY_SECONDS = 21600; // 6 hours

    /** NIC dates use slashes — avoid JSON `\/` so logs and payloads read as 03/04/2026. */
    private const JSON_FLAGS_EWAY = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    /** When true, Perione HTTP calls append structured entries to {@see $ewayApiDebugLog}. */
    private $ewayApiDebugCollecting = false;
    /** @var array<int, array<string, mixed>> */
    private $ewayApiDebugLog = [];

    public function __construct($connect)
    {
        $this->connect = $connect;
    }

    private function dbOk()
    {
        return isset($this->connect) && $this->connect instanceof mysqli;
    }

    /**
     * IP of this PHP host for Perione `ip_address` header when settings use auto mode.
     * Uses SERVER_ADDR, then hostname resolution; not the browser's REMOTE_ADDR.
     */
    public static function detectServerBindIp()
    {
        $addr = isset($_SERVER['SERVER_ADDR']) ? trim((string) $_SERVER['SERVER_ADDR']) : '';
        if ($addr !== '' && strcasecmp($addr, '0.0.0.0') !== 0 && strcasecmp($addr, '::1') !== 0 && filter_var($addr, FILTER_VALIDATE_IP)) {
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

    /**
     * `eway_bill_settings.ip_address` or auto-detected server IP when stored value is 0.0.0.0 / auto / empty.
     */
    private function effectiveIpForEway(array $settings)
    {
        $stored = isset($settings['ip_address']) ? trim((string) $settings['ip_address']) : '';
        if ($stored === '' || strcasecmp($stored, '0.0.0.0') === 0 || strcasecmp($stored, 'auto') === 0) {
            return self::detectServerBindIp();
        }
        return $stored;
    }

    /** Redact `password` query parameter in URLs for safe display. */
    private static function maskSensitiveUrl($url)
    {
        $u = (string) $url;
        $u = preg_replace('/([?&]password=)[^&]*/i', '$1***REDACTED***', $u);

        return $u !== null && $u !== '' ? $u : (string) $url;
    }

    /**
     * Mask secrets in curl-style header lines ("Name: value").
     *
     * @param string[] $lines
     * @return string[]
     */
    private static function maskHeaderLinesForLog(array $lines)
    {
        $sensitive = ['client_secret', 'client-secret', 'auth-token', 'authorization', 'password'];
        $out = [];
        foreach ($lines as $line) {
            $line = (string) $line;
            if (!preg_match('/^([^:]+):\s*(.*)$/s', $line, $m)) {
                $out[] = $line;
                continue;
            }
            $name = trim($m[1]);
            $val = $m[2];
            $hit = false;
            foreach ($sensitive as $sn) {
                if (strcasecmp($name, $sn) === 0) {
                    $v = trim((string) $val);
                    $show = strlen($v) > 10 ? substr($v, 0, 4) . '…***…' . substr($v, -4) : '***';
                    $out[] = $name . ': ' . $show;
                    $hit = true;
                    break;
                }
            }
            if (!$hit) {
                $out[] = $line;
            }
        }

        return $out;
    }

    private function ewayApiDebugPush(array $entry)
    {
        if (!$this->ewayApiDebugCollecting) {
            return;
        }
        $entry['at'] = date('c');
        $this->ewayApiDebugLog[] = $entry;
    }

    /** @return array<int, array<string, mixed>> */
    private function ewayApiDebugSnapshot()
    {
        return $this->ewayApiDebugLog;
    }

    /** Attach current API debug log to a controller response array. */
    private function withApiCommunicationLog(array $response)
    {
        $response['api_communication_log'] = $this->ewayApiDebugSnapshot();

        return $response;
    }

    /**
     * Reads Perione credentials from table `eway_bill_settings` (per business_id).
     */
    public function getEwayBillSettings($business_id)
    {
        if (!$this->dbOk()) {
            return null;
        }
        $sql = "SELECT * FROM eway_bill_settings WHERE business_id = ?";
        $stmt = $this->connect->prepare($sql);
        if (!$stmt)
            return null;
        $stmt->bind_param("i", $business_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }

    /**
     * Perione authenticate: GET with email, username, password as query params;
     * ip_address, client_id, client_secret, gstin as headers.
     *
     * @param bool $force If false, skip HTTP call when a recent successful auth is still within validity window.
     */
    public function authenticate($business_id, $force = false)
    {
        $settings = $this->getEwayBillSettings($business_id);
        if (!$settings) {
            return ['status' => false, 'message' => 'e-Way Bill settings not found for this business.'];
        }

        $buffer = 300;
        if (
            !$force
            && !empty($settings['token_expiry'])
            && strtotime($settings['token_expiry']) > (time() + $buffer)
        ) {
            $this->ewayApiDebugPush([
                'step' => 'GET authenticate',
                'skipped' => true,
                'reason' => 'Cached auth token still valid',
                'token_expiry' => (string) $settings['token_expiry'],
            ]);
            return ['status' => true, 'token' => $settings['auth_token'] ?? ''];
        }

        $ip = $this->effectiveIpForEway($settings);
        $query = http_build_query([
            'email' => $settings['api_email'],
            'username' => $settings['api_username'],
            'password' => $settings['api_password'],
        ], '', '&', PHP_QUERY_RFC3986);

        $headers = [
            'Accept: application/json',
            'ip_address: ' . $ip,
            'client_id: ' . $settings['client_id'],
            'client_secret: ' . $settings['client_secret'],
            'gstin: ' . $settings['gstin'],
        ];

        $url = rtrim($this->api_base_url, '/') . '/' . ltrim($this->auth_path, '/') . '?' . $query;

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $response = curl_exec($ch);
        $http_code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $result = is_string($response) ? json_decode($response, true) : null;
        if (!is_array($result)) {
            $result = [];
        }

        $this->ewayApiDebugPush([
            'step' => 'GET authenticate',
            'description' => 'Perione e-way authenticate (query: email, username, password; headers: ip_address, client_id, client_secret, gstin)',
            'request' => [
                'method' => 'GET',
                'url_masked' => self::maskSensitiveUrl($url),
                'headers_masked' => self::maskHeaderLinesForLog($headers),
            ],
            'response' => [
                'http_status' => $http_code,
                'body_raw' => is_string($response) ? $response : '',
                'body_json' => $result,
            ],
        ]);

        $ok = $http_code === 200
            && (isset($result['status_cd']) && (string) $result['status_cd'] === '1');

        if ($ok) {
            $token = '';
            if (!empty($result['token'])) {
                $token = (string) $result['token'];
            } elseif (!empty($result['auth_token'])) {
                $token = (string) $result['auth_token'];
            } elseif (!empty($result['header']['txn'])) {
                $token = (string) $result['header']['txn'];
            }

            $expiry = date('Y-m-d H:i:s', time() + self::AUTH_VALIDITY_SECONDS);
            if ($this->dbOk()) {
                $update_sql = 'UPDATE eway_bill_settings SET auth_token = ?, token_expiry = ? WHERE business_id = ?';
                $update_stmt = $this->connect->prepare($update_sql);
                if ($update_stmt) {
                    $update_stmt->bind_param('ssi', $token, $expiry, $business_id);
                    $update_stmt->execute();
                    $update_stmt->close();
                }
            }

            return ['status' => true, 'token' => $token];
        }

        $msg = self::formatNicGatewayError($result, is_string($response) ? $response : '');
        return [
            'status' => false,
            'message' => 'Authentication failed: ' . $msg,
            'api_response' => is_string($response) ? $response : '',
            'api_http_code' => $http_code,
            'api_decoded' => $result,
        ];
    }

    /**
     * Keys allowed on the generate payload (extras from our form are dropped).
     * Shapes types like NIC sample JSON (int pincodes/state codes, string subSupplyType, etc.).
     */
    private function sanitizeEwayGeneratePayload(array $payload)
    {
        $allowed = [
            'supplyType', 'subSupplyType', 'subSupplyDesc', 'docType', 'docNo', 'docDate',
            'fromGstin', 'fromTrdName', 'fromAddr1', 'fromAddr2', 'fromPlace', 'fromPincode',
            'actFromStateCode', 'fromStateCode',
            'toGstin', 'toTrdName', 'toAddr1', 'toAddr2', 'toPlace', 'toPincode',
            'actToStateCode', 'toStateCode',
            'transactionType', 'otherValue', 'totalValue', 'cgstValue', 'sgstValue', 'igstValue',
            'cessValue', 'cessNonAdvolValue', 'totInvValue',
            'transporterId', 'transporterName', 'transDocNo', 'transMode', 'transDistance',
            'transDocDate', 'vehicleNo', 'vehicleType', 'itemList',
            'dispatchFromGSTIN', 'dispatchFromTradeName', 'shipToGSTIN', 'shipToTradeName',
        ];
        $out = [];
        foreach ($allowed as $k) {
            if (!array_key_exists($k, $payload)) {
                continue;
            }
            $out[$k] = $payload[$k];
        }
        if (!array_key_exists('otherValue', $out)) {
            $out['otherValue'] = 0;
        }
        if (isset($out['transDistance'])) {
            $out['transDistance'] = (string) $out['transDistance'];
        }
        if (!empty($out['itemList']) && is_array($out['itemList'])) {
            foreach ($out['itemList'] as $i => $row) {
                if (!is_array($row)) {
                    continue;
                }
                if (!array_key_exists('cessNonadvol', $row)) {
                    $out['itemList'][$i]['cessNonadvol'] = 0;
                }
                if (isset($out['itemList'][$i]['hsnCode'])) {
                    $out['itemList'][$i]['hsnCode'] = (int) $out['itemList'][$i]['hsnCode'];
                }
                if (isset($out['itemList'][$i]['quantity'])) {
                    $out['itemList'][$i]['quantity'] = (float) $out['itemList'][$i]['quantity'];
                }
                foreach (['taxableAmount', 'sgstRate', 'cgstRate', 'igstRate', 'cessRate', 'cessNonadvol'] as $k) {
                    if (isset($out['itemList'][$i][$k])) {
                        $out['itemList'][$i][$k] = (float) $out['itemList'][$i][$k];
                    }
                }
                if (isset($out['itemList'][$i]['qtyUnit'])) {
                    $out['itemList'][$i]['qtyUnit'] = strtoupper(substr((string) $out['itemList'][$i]['qtyUnit'], 0, 3));
                }
            }
        }
        if (isset($out['docDate'])) {
            $out['docDate'] = $this->normalizeEwayDateString($out['docDate']);
        }
        if (isset($out['transDocDate']) && (string) $out['transDocDate'] !== '') {
            $out['transDocDate'] = $this->normalizeEwayDateString($out['transDocDate']);
        }
        $this->enforceTransDocDateNotBeforeDocDate($out);
        $textKeys = [
            'subSupplyDesc', 'fromTrdName', 'fromAddr1', 'fromAddr2', 'fromPlace',
            'toTrdName', 'toAddr1', 'toAddr2', 'toPlace',
            'transporterName', 'transDocNo', 'vehicleNo',
            'dispatchFromGSTIN', 'dispatchFromTradeName', 'shipToGSTIN', 'shipToTradeName',
        ];
        foreach ($textKeys as $tk) {
            if (array_key_exists($tk, $out)) {
                $out[$tk] = $this->sanitizeNullishText($out[$tk]);
            }
        }
        if (isset($out['toGstin'])) {
            $out['toGstin'] = strtoupper(preg_replace('/\s+/', '', trim((string) $out['toGstin'])));
        }
        if (isset($out['subSupplyType'])) {
            $out['subSupplyType'] = (string) $out['subSupplyType'];
        }
        if (!isset($out['subSupplyDesc']) || trim((string) $out['subSupplyDesc']) === '') {
            $out['subSupplyDesc'] = '';
        }
        foreach (['fromPincode', 'toPincode', 'actFromStateCode', 'fromStateCode', 'actToStateCode', 'toStateCode', 'transactionType'] as $intKey) {
            if (isset($out[$intKey]) && $out[$intKey] !== '' && $out[$intKey] !== null) {
                $out[$intKey] = (int) $out[$intKey];
            }
        }
        foreach (['otherValue', 'totalValue', 'cgstValue', 'sgstValue', 'igstValue', 'cessValue', 'cessNonAdvolValue', 'totInvValue'] as $fltKey) {
            if (isset($out[$fltKey]) && $out[$fltKey] !== '' && $out[$fltKey] !== null) {
                $out[$fltKey] = (float) $out[$fltKey];
            }
        }
        $tt = (int) ($out['transactionType'] ?? 1);
        if ($tt === 1) {
            foreach (['dispatchFromGSTIN', 'dispatchFromTradeName', 'shipToGSTIN', 'shipToTradeName'] as $omit) {
                unset($out[$omit]);
            }
        }

        // NIC error 619: Transporter ID is mandatory. Default to own (from) GSTIN when blank.
        $transporterId = isset($out['transporterId'])
            ? strtoupper(preg_replace('/\s+/', '', (string) $out['transporterId']))
            : '';
        if ($transporterId === '' && !empty($out['fromGstin'])) {
            $transporterId = strtoupper(preg_replace('/\s+/', '', (string) $out['fromGstin']));
        }
        if ($transporterId !== '') {
            $out['transporterId'] = $transporterId;
        } else {
            unset($out['transporterId']);
        }

        // NIC error 303: when transMode is set, vehicle/trans-doc details become mandatory.
        // Drop transMode (and dependents) when transMode is blank/unset and no vehicle / trans doc data is provided.
        $transMode = isset($out['transMode']) ? trim((string) $out['transMode']) : '';
        $vehicleNo = isset($out['vehicleNo']) ? trim((string) $out['vehicleNo']) : '';
        $transDocNo = isset($out['transDocNo']) ? trim((string) $out['transDocNo']) : '';
        $transDocDate = isset($out['transDocDate']) ? trim((string) $out['transDocDate']) : '';

        if ($transMode === '' && $vehicleNo === '' && $transDocNo === '' && $transDocDate === '') {
            unset($out['transMode'], $out['vehicleNo'], $out['vehicleType'], $out['transDocNo'], $out['transDocDate']);
        }

        return $out;
    }

    /** Strip JSON/form noise like literal "null" from optional text fields. */
    private function sanitizeNullishText($v)
    {
        if ($v === null) {
            return '';
        }
        $s = trim((string) $v);
        return strcasecmp($s, 'null') === 0 ? '' : $s;
    }

    /** NIC error 362: transporter document date cannot be earlier than invoice (doc) date. */
    private function enforceTransDocDateNotBeforeDocDate(array &$out)
    {
        if (empty($out['transDocDate']) || empty($out['docDate'])) {
            return;
        }
        $d = \DateTime::createFromFormat('d/m/Y', $out['docDate']);
        $t = \DateTime::createFromFormat('d/m/Y', $out['transDocDate']);
        if ($d && $t && $t < $d) {
            $out['transDocDate'] = $out['docDate'];
        }
    }

    /**
     * NIC rejects docDate outside allowed window (often error 207; also 819 / 820).
     *
     * @return string|null Human-readable error, or null if OK.
     */
    private function validateNicDocDateWindow($docDateDdMmYyyy)
    {
        $tz = new \DateTimeZone('Asia/Kolkata');
        $dt = \DateTime::createFromFormat('d/m/Y', trim((string) $docDateDdMmYyyy), $tz);
        if (!$dt) {
            return 'Doc date must be a valid calendar date in DD/MM/YYYY format.';
        }
        $dt->setTime(0, 0, 0);
        $today = new \DateTime('today', $tz);
        $today->setTime(0, 0, 0);
        $minNic = new \DateTime('2017-07-01', $tz);
        $min180 = (clone $today)->modify('-180 days');
        if ($dt < $minNic) {
            return 'Document date cannot be before 01/07/2017 (NIC).';
        }
        if ($dt > $today) {
            return 'Document date cannot be after today in India (Asia/Kolkata; NIC error 207).';
        }
        if ($dt < $min180) {
            return 'Document date is more than 180 days in the past — NIC blocks e-way generation (errors 207 / 820). Set Doc date to the same date as on the tax invoice (and within the last 180 days).';
        }
        return null;
    }

    /**
     * Catch common NIC rule violations before calling the gateway (avoids misleading 207 responses).
     *
     * @return string|null Error message or null if OK.
     */
    private function validateNicPayloadBusinessRules(array $p)
    {
        $tt = (int) ($p['transactionType'] ?? 1);

        if ($tt === 2 || $tt === 4) {
            $ship = strtoupper(preg_replace('/\s+/', '', (string) ($p['shipToGSTIN'] ?? '')));
            if ($ship === '') {
                return 'Transaction type is Bill-to / Ship-to (or combined): NIC requires Ship-to GSTIN (error 608 / 616). Fill Ship To GSTIN and Ship To Trade Name, or set Transaction type to Regular (1).';
            }
        }
        if ($tt === 3 || $tt === 4) {
            $disp = strtoupper(preg_replace('/\s+/', '', (string) ($p['dispatchFromGSTIN'] ?? '')));
            if ($disp === '') {
                return 'Transaction type is Bill-from / Dispatch-from (or combined): NIC requires Dispatch-from GSTIN (error 607). Fill those fields or use Transaction type Regular (1).';
            }
        }

        $supplyType = strtoupper((string) ($p['supplyType'] ?? ''));
        $subSupplyType = (string) ($p['subSupplyType'] ?? '');
        $toGstin = strtoupper(preg_replace('/\s+/', '', (string) ($p['toGstin'] ?? '')));
        $fromGstin = strtoupper(preg_replace('/\s+/', '', (string) ($p['fromGstin'] ?? '')));
        $toState = (int) ($p['toStateCode'] ?? 0);
        $actToState = (int) ($p['actToStateCode'] ?? 0);
        $toPin = (int) ($p['toPincode'] ?? 0);

        // EXPORT (sub_supply 3): NIC requires URP/SEZ to-GSTIN, state 96 (Other Country) and pincode 999999.
        // NIC error catalog: 450 (To GSTIN must be URP/SEZ); often surfaces as 207 in vendor gateways.
        if ($supplyType === 'O' && $subSupplyType === '3') {
            $isUrpOrSez = ($toGstin === 'URP') || (strlen($toGstin) === 15 && substr($toGstin, 0, 2) === '99');
            if (!$isUrpOrSez) {
                return 'Sub Supply Type is Export (3) but To GSTIN is "' . ($toGstin !== '' ? $toGstin : '(blank)')
                    . '". NIC error 450 / 207: for outward Export, To GSTIN must be URP or an SEZ GSTIN (state 96/Other Country, pincode 999999). '
                    . 'Either change Sub Supply Type to Supply (1) for a domestic sale, or set To GSTIN = URP, To State = 96, To Pincode = 999999.';
            }
            if ($toState !== 96 || $actToState !== 96) {
                return 'Sub Supply Type is Export (3): To State and Actual To State must be 96 (Other Country). Currently '
                    . $toState . ' / ' . $actToState . '.';
            }
            if ($toPin !== 999999) {
                return 'Sub Supply Type is Export (3): To Pincode must be 999999. Currently ' . $toPin . '.';
            }
        }

        // IMPORT (sub_supply 2): NIC requires URP/SEZ from-GSTIN with state 96.
        if ($supplyType === 'I' && $subSupplyType === '2') {
            $isUrpOrSez = ($fromGstin === 'URP') || (strlen($fromGstin) === 15 && substr($fromGstin, 0, 2) === '99');
            if (!$isUrpOrSez) {
                return 'Sub Supply Type is Import (2): From GSTIN must be URP or an SEZ GSTIN (NIC error 450 family).';
            }
        }

        // Common consumer-error guard: outward + sub_supply Supply (1) + URP toGstin must come with valid Indian state and pincode.
        if ($supplyType === 'O' && $subSupplyType === '1' && $toGstin !== 'URP' && $toGstin !== '') {
            if (strlen($toGstin) !== 15 || !preg_match('/^[0-9A-Z]{15}$/', $toGstin)) {
                return 'To GSTIN "' . $toGstin . '" is not a valid 15-character GSTIN (NIC error 212). Use URP for unregistered party.';
            }
        }

        // NIC error 619 — Transporter ID is mandatory.
        $transporterId = strtoupper(preg_replace('/\s+/', '', (string) ($p['transporterId'] ?? '')));
        if ($transporterId === '') {
            return 'Transporter ID (GSTIN/TRANSIN) is mandatory (NIC error 619). '
                . 'Enter the transporter, or use your own GSTIN if you arrange transport yourself.';
        }

        // NIC error 303 — when transMode is set, vehicle / trans doc must be present.
        $transMode = trim((string) ($p['transMode'] ?? ''));
        $vehicleNo = trim((string) ($p['vehicleNo'] ?? ''));
        $transDocNo = trim((string) ($p['transDocNo'] ?? ''));
        if ($transMode !== '' && $vehicleNo === '' && $transDocNo === '') {
            return 'Transport Mode "' . $transMode . '" is selected but neither Vehicle No nor Trans Doc No is provided. '
                . 'NIC error 303: provide vehicle/trans doc, or leave Transport Mode unselected.';
        }

        return null;
    }

    /**
     * NIC / Perione dates must be DD/MM/YYYY (strict two-digit day and month where applicable).
     */
    private function normalizeEwayDateString($value)
    {
        $v = trim((string) $value);
        if ($v === '') {
            return $v;
        }
        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $v, $m)) {
            $candidate = sprintf('%02d/%02d/%04d', (int) $m[1], (int) $m[2], (int) $m[3]);
            $dt = \DateTime::createFromFormat('d/m/Y', $candidate);
            return $dt ? $dt->format('d/m/Y') : $v;
        }
        if (preg_match('#^(\d{4})-(\d{2})-(\d{2})$#', $v)) {
            $dt = \DateTime::createFromFormat('Y-m-d', $v);
            return $dt ? $dt->format('d/m/Y') : $v;
        }
        return $v;
    }

    /**
     * docDate for e-way from invoices.invoice_date — avoids NIC 207 when MySQL date is null/0000-00-00
     * or strtotime fails (would otherwise become 01/01/1970).
     */
    private function invoiceRowToEwayDocDate(array $invoice)
    {
        $raw = $invoice['invoice_date'] ?? null;
        if ($raw === null || $raw === '' || $raw === '0000-00-00' || $raw === '0000-00-00 00:00:00') {
            return $this->normalizeEwayDateString(date('d/m/Y'));
        }
        $s = trim((string) $raw);
        $dt = \DateTime::createFromFormat('Y-m-d H:i:s', $s)
            ?: \DateTime::createFromFormat('Y-m-d H:i', $s)
            ?: \DateTime::createFromFormat('Y-m-d', substr($s, 0, 10));
        if (!$dt) {
            $ts = strtotime($s);
            if ($ts !== false) {
                $dt = (new \DateTime())->setTimestamp($ts);
            }
        }
        if ($dt instanceof \DateTimeInterface) {
            $y = (int) $dt->format('Y');
            if ($y < 2017 || $y > (int) date('Y') + 1) {
                return $this->normalizeEwayDateString(date('d/m/Y'));
            }
            return $this->normalizeEwayDateString($dt->format('d/m/Y'));
        }
        return $this->normalizeEwayDateString(date('d/m/Y'));
    }

    /**
     * Consignee GSTIN: use invoices.doc_no only when doc_type is GST (1) and value looks like a 15-char GSTIN;
     * otherwise URP (unregistered). Using non-GST doc_no as toGstin breaks NIC validation.
     */
    private function resolveToGstinFromInvoice(array $invoice)
    {
        $docType = isset($invoice['doc_type']) ? (int) $invoice['doc_type'] : -1;
        $docNo = isset($invoice['doc_no']) ? strtoupper(preg_replace('/\s+/', '', trim((string) $invoice['doc_no']))) : '';
        if ($docNo === '') {
            return 'URP';
        }
        if ($docType === 1 && strlen($docNo) === 15 && preg_match('/^[0-9]{2}[0-9A-Z]{13}$/', $docNo)) {
            return $docNo;
        }
        return 'URP';
    }

    private static function nicErrorCatalogPath()
    {
        return dirname(__DIR__) . '/eway_bills_doc/eway_api_error_codes.php';
    }

    private static function nicErrorDescription($code)
    {
        if ($code === null || $code === '') {
            return '';
        }
        $k = preg_replace('/\D/', '', (string) $code);
        if ($k === '') {
            return '';
        }
        static $map = null;
        if ($map === null) {
            $path = self::nicErrorCatalogPath();
            $map = is_readable($path) ? include $path : [];
        }
        return isset($map[$k]) ? (string) $map[$k] : '';
    }

    /**
     * Reads nested NIC error blocks (status_cd / status failures).
     */
    private static function extractNicErrorCode(array $decoded)
    {
        if (!empty($decoded['error']) && is_array($decoded['error'])) {
            $e = $decoded['error'];
            if (isset($e['errorCodes']) && $e['errorCodes'] !== '' && $e['errorCodes'] !== null) {
                return (string) $e['errorCodes'];
            }
            if (!empty($e['message']) && is_string($e['message'])) {
                $j = json_decode($e['message'], true);
                if (is_array($j) && isset($j['errorCodes']) && $j['errorCodes'] !== '') {
                    return (string) $j['errorCodes'];
                }
            }
        }
        return null;
    }

    private static function formatNicGatewayError(array $decoded, $raw = '')
    {
        $code = self::extractNicErrorCode($decoded);
        $desc = self::nicErrorDescription($code);
        $extra = '';
        if (isset($decoded['status_desc']) && (string) $decoded['status_desc'] !== '') {
            $extra = trim((string) $decoded['status_desc']);
        }
        if ($code !== null && $code !== '') {
            $out = 'Error ' . $code . ($desc !== '' ? ': ' . $desc : '');
            if ($extra !== '' && $extra !== '0' && stripos($out, $extra) === false) {
                $out .= ' (' . $extra . ')';
            }
            return $out;
        }
        if (!empty($decoded['message']) && is_string($decoded['message']) && strpos($decoded['message'], '{') === false) {
            return trim($decoded['message']);
        }
        $raw = (string) $raw;
        return $raw !== '' ? substr($raw, 0, 500) : 'Request failed';
    }

    private static function isNicAuthRelatedErrorCode($code)
    {
        if ($code === null || $code === '') {
            return false;
        }
        return in_array((int) $code, [105, 106, 110, 237, 238], true);
    }

    private function buildGenerateHeaders(array $settings, $authToken)
    {
        $ip = $this->effectiveIpForEway($settings);
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'ip_address: ' . $ip,
            'client_id: ' . $settings['client_id'],
            'client_secret: ' . $settings['client_secret'],
            'gstin: ' . $settings['gstin'],
            'username: ' . $settings['api_username'],
        ];
        if ($authToken !== null && $authToken !== '') {
            $headers[] = 'auth-token: ' . $authToken;
        }
        return $headers;
    }

    /**
     * POST generate. Perione expects the **raw NIC JSON** as body
     * (NOT a {"action":"GENEWAYBILL","data": base64(JSON)} envelope — that wrapping returned 207).
     */
    private function requestGenerateEwayBill(array $settings, array $apiPayload, $authToken)
    {
        $body = json_encode($apiPayload, self::JSON_FLAGS_EWAY);
        if ($body === false) {
            $this->ewayApiDebugPush([
                'step' => 'POST genewaybill',
                'error' => 'Failed to json_encode e-Way payload (invalid UTF-8 or data)',
                'inner_eway_json_attempted' => $apiPayload,
            ]);
            return [
                'ok' => false,
                'http_code' => 0,
                'raw' => '',
                'decoded' => null,
                'message' => 'Failed to encode e-Way Bill JSON',
            ];
        }

        $url = rtrim($this->api_base_url, '/') . '/' . ltrim($this->generate_path, '/')
            . '?email=' . rawurlencode($settings['api_email']);

        $headerLines = $this->buildGenerateHeaders($settings, $authToken);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headerLines);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $response = curl_exec($ch);
        $http_code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $decoded = is_string($response) ? json_decode($response, true) : null;

        $this->ewayApiDebugPush([
            'step' => 'POST genewaybill',
            'description' => 'Perione GENEWAYBILL — POST body is the raw NIC JSON (no action/data wrapper).',
            'request' => [
                'method' => 'POST',
                'url' => $url,
                'headers_masked' => self::maskHeaderLinesForLog($headerLines),
                'body_json_sent_to_gateway' => $apiPayload,
            ],
            'response' => [
                'http_status' => $http_code,
                'body_raw' => is_string($response) ? $response : '',
                'body_json' => is_array($decoded) ? $decoded : null,
            ],
        ]);

        return [
            'ok' => false,
            'http_code' => $http_code,
            'raw' => is_string($response) ? $response : '',
            'decoded' => is_array($decoded) ? $decoded : null,
            'message' => null,
        ];
    }

    /**
     * Whether the first generate failure is worth retrying after authenticate().
     */
    private function shouldRetryGenerateAfterAuth($http_code, $decoded)
    {
        if ($http_code === 401 || $http_code === 403) {
            return true;
        }
        if (is_array($decoded)) {
            $nic = self::extractNicErrorCode($decoded);
            if ($nic !== null && self::isNicAuthRelatedErrorCode($nic)) {
                return true;
            }
        }
        $msg = '';
        if (is_array($decoded)) {
            $msg = strtolower(
                (string) ($decoded['message'] ?? $decoded['status_desc'] ?? $decoded['info'] ?? '')
            );
            $blob = strtolower(json_encode($decoded));
            if (
                $msg !== ''
                && preg_match('/auth|token|session|expired|unauthori|invalid\s+access|not\s+authenticated/i', $msg)
            ) {
                return true;
            }
            if (preg_match('/invalid\s+session|authentication\s+fail|invalid\s+token|session\s+expired/i', $blob)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Parse Perione generate success.
     *
     * Working response shape (raw JSON path):
     *   { "status_cd": "1", "status_desc": "EWAYBILL request succeeds",
     *     "data": { "ewayBillNo": 781630401011, "ewayBillDate": "03/05/2026 04:27:00 PM",
     *               "validUpto": null, "alert": "Distance ..." }, "header": {...} }
     *
     * Some legacy gateways return base64 data; keep that as a fallback.
     */
    private function parseGenerateSuccessResponse(array $decoded)
    {
        $statusOk =
            (isset($decoded['status_cd']) && (string) $decoded['status_cd'] === '1')
            || (isset($decoded['status']) && (string) $decoded['status'] === '1');
        if (!$statusOk || empty($decoded['data'])) {
            return null;
        }

        if (is_array($decoded['data'])) {
            return $decoded['data'];
        }

        if (is_string($decoded['data'])) {
            $bin = base64_decode($decoded['data'], true);
            if ($bin !== false) {
                $inner = json_decode($bin, true);
                if (is_array($inner)) {
                    return $inner;
                }
            }
            $inner = json_decode($decoded['data'], true);
            if (is_array($inner)) {
                return $inner;
            }
        }
        return null;
    }

    private function parseGenerateErrorMessage($http_code, $decoded, $raw)
    {
        if (is_array($decoded)) {
            if (!empty($decoded['error']) && is_array($decoded['error'])) {
                return self::formatNicGatewayError($decoded, $raw);
            }
            if ((isset($decoded['status_cd']) && (string) $decoded['status_cd'] === '0')
                || (isset($decoded['status']) && (string) $decoded['status'] === '0')) {
                return self::formatNicGatewayError($decoded, $raw);
            }
            if (!empty($decoded['message'])) {
                return (string) $decoded['message'];
            }
            if (!empty($decoded['status_desc'])) {
                return (string) $decoded['status_desc'];
            }
        }
        return 'API error (HTTP ' . $http_code . ')' . ($raw !== '' ? ': ' . substr($raw, 0, 500) : '');
    }

    /**
     * Indian state name to GST state code (first 2 digits of GSTIN)
     */
    public function stateNameToCode($stateName)
    {
        if (empty($stateName))
            return 7;
        $map = [
            'Jammu' => 1,
            'Kashmir' => 1,
            'Himachal' => 2,
            'Punjab' => 3,
            'Chandigarh' => 4,
            'Uttarakhand' => 5,
            'Haryana' => 6,
            'Delhi' => 7,
            'Rajasthan' => 8,
            'Uttar Pradesh' => 9,
            'Bihar' => 10,
            'Sikkim' => 11,
            'Arunachal' => 12,
            'Nagaland' => 13,
            'Manipur' => 14,
            'Mizoram' => 15,
            'Tripura' => 16,
            'Meghalaya' => 17,
            'Assam' => 18,
            'West Bengal' => 19,
            'Jharkhand' => 20,
            'Odisha' => 21,
            'Chhattisgarh' => 22,
            'Madhya Pradesh' => 23,
            'Gujarat' => 24,
            'Daman' => 25,
            'Dadra' => 26,
            'Maharashtra' => 27,
            'Maharastra' => 27,
            'Karnataka' => 29,
            'Goa' => 30,
            'Lakshadweep' => 31,
            'Kerala' => 32,
            'Tamil Nadu' => 33,
            'Puducherry' => 34,
            'Andaman' => 35,
            'Telangana' => 36,
            'Andhra Pradesh' => 37,
            'Ladakh' => 38,
        ];
        $key = trim($stateName);
        foreach ($map as $name => $code) {
            if (stripos($key, $name) !== false || stripos($name, $key) !== false)
                return $code;
        }
        return 7;
    }

    /**
     * Parse location address into line1 and line2 (strip HTML)
     */
    private function parseAddressLines($address)
    {
        if (empty($address))
            return ['', ''];
        $text = trim(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $address)));
        $lines = preg_split('/\n+/', $text, 2);
        return [
            trim($lines[0] ?? ''),
            trim($lines[1] ?? '')
        ];
    }

    /**
     * Map internal invoice data to e-Way bill API request format (full API spec)
     */
    public function prepareEwayBillData($invoice_id, $transport_details)
    {
        if (!$this->dbOk()) {
            return null;
        }
        $sql = "SELECT i.*, b.business_name, b.gst as fromGstin, b.email, 
                       l.location_name, l.address as fromAddrRaw, l.state as fromState,
                       a.address_1 as toAddr1, a.address_2 as toAddr2, a.city as toCity, a.state as toState, a.pincode as toPincode
                FROM invoices i
                JOIN businessses b ON i.business_id = b.id
                JOIN locations l ON i.location_id = l.id
                LEFT JOIN addres a ON i.billing_address_id = a.id
                WHERE i.id = ?";

        $stmt = $this->connect->prepare($sql);
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param("i", $invoice_id);
        $stmt->execute();
        $invoice = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$invoice)
            return null;

        $fromAddrLines = $this->parseAddressLines($invoice['fromAddrRaw'] ?? '');
        $fromStateCode = $this->stateNameToCode($invoice['fromState'] ?? '');
        $toStateCode = $this->stateNameToCode($invoice['toState'] ?? '');
        $toPincode = (int) ($invoice['toPincode'] ?? 0);
        if ($toPincode < 100000)
            $toPincode = 110001;
        $fromPincode = 110001; // locations table has no pincode; override in form if needed

        // Fetch items
        $items_sql = "SELECT it.*, p.name as productName, p.hsn_code 
                      FROM items it 
                      JOIN products p ON it.product_id = p.id 
                      WHERE it.invoice_id = ?";
        $stmt = $this->connect->prepare($items_sql);
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param("i", $invoice_id);
        $stmt->execute();
        $items_result = $stmt->get_result();
        $items = [];
        while ($row = $items_result->fetch_assoc()) {
            $items[] = [
                "productName" => $row['productName'],
                "productDesc" => $row['productName'],
                "hsnCode" => (int) $row['hsn_code'],
                "quantity" => (float) $row['quantity'],
                "qtyUnit" => "NOS",
                "taxableAmount" => (float) ($row['price_of_all'] - ($row['igst'] ?: ($row['cgst'] + $row['dgst']))),
                "sgstRate" => $row['dgst'] > 0 ? (float) ($row['gst_rate'] / 2) : 0,
                "cgstRate" => $row['cgst'] > 0 ? (float) ($row['gst_rate'] / 2) : 0,
                "igstRate" => $row['igst'] > 0 ? (float) $row['gst_rate'] : 0,
                "cessRate" => 0,
                "cessNonadvol" => 0,
            ];
        }
        $stmt->close();

        $transMode = $transport_details['transMode'] ?? '';
        $vehicleType = ($transMode === '1' || $transMode === '2') ? 'R'
            : (($transMode === '3') ? 'A' : (($transMode === '4') ? 'S' : 'R'));

        $totalTaxable = 0;
        foreach ($items as $it) {
            $totalTaxable += (float) $it['taxableAmount'];
        }

        return [
            "supplyType" => "O",
            "subSupplyType" => "1",
            "subSupplyDesc" => "",
            "docType" => "INV",
            "docNo" => (string) $invoice['serial_no'],
            "docDate" => $this->invoiceRowToEwayDocDate($invoice),
            "fromGstin" => $invoice['fromGstin'] ?: "URP",
            "fromTrdName" => $invoice['business_name'],
            "fromAddr1" => $fromAddrLines[0] ?: $invoice['location_name'],
            "fromAddr2" => $fromAddrLines[1],
            "fromPlace" => $invoice['location_name'],
            "fromPincode" => $fromPincode,
            "fromStateCode" => $fromStateCode,
            "actFromStateCode" => $fromStateCode,
            "toGstin" => $this->resolveToGstinFromInvoice($invoice),
            "toTrdName" => $invoice['name'],
            "toAddr1" => $invoice['toAddr1'] ?? '',
            "toAddr2" => $invoice['toAddr2'] ?? '',
            "toPlace" => $invoice['toCity'] ?? '',
            "toPincode" => $toPincode,
            "toStateCode" => $toStateCode,
            "actToStateCode" => $toStateCode,
            "transactionType" => 1,
            "otherValue" => 0,
            "totalValue" => (float) $totalTaxable,
            "cgstValue" => (float) ($invoice['total_cgst'] ?? 0),
            "sgstValue" => (float) ($invoice['total_dgst'] ?? 0),
            "igstValue" => (float) ($invoice['total_igst'] ?? 0),
            "cessValue" => 0,
            "cessNonAdvolValue" => 0,
            "totInvValue" => (float) $invoice['total_amount'],
            "transMode" => $transMode,
            "transDistance" => (string) ($transport_details['transDistance'] ?? '0'),
            "vehicleNo" => $transport_details['vehicleNo'] ?? '',
            "vehicleType" => $vehicleType,
            "transporterId" => trim((string) ($transport_details['transporterId'] ?? '')) !== ''
                ? $transport_details['transporterId']
                : ($invoice['fromGstin'] ?: ''),
            "transporterName" => $transport_details['transporterName'] ?? '',
            "transDocNo" => $transport_details['transDocNo'] ?? '',
            "transDocDate" => $transport_details['transDocDate'] ?? '',
            "dispatchFromGSTIN" => "",
            "dispatchFromTradeName" => "",
            "shipToGSTIN" => "",
            "shipToTradeName" => "",
            "itemList" => $items
        ];
    }

    /**
     * Generate e-Way bill via Perione: POST GENEWAYBILL (base64-wrapped body).
     * Does not call the authenticate API first; on auth-like failure, calls authenticate then retries once.
     */
    public function generateEwayBill($invoice_id, $transport_details, $payload_override = null, ?int $business_id = null)
    {
        $bid = ($business_id !== null && (int) $business_id > 0) ? (int) $business_id : (int) ($_SESSION['business_id'] ?? 0);
        if ($bid <= 0) {
            return ['status' => 'error', 'message' => 'Business context missing.', 'api_communication_log' => []];
        }
        if (!$this->dbOk()) {
            return ['status' => 'error', 'message' => 'Database connection unavailable.', 'api_communication_log' => []];
        }
        $settings = $this->getEwayBillSettings($bid);
        if (!$settings) {
            return ['status' => 'error', 'message' => 'e-Way Bill settings not found for this business.', 'api_communication_log' => []];
        }

        $payload = $payload_override !== null ? $payload_override : $this->prepareEwayBillData($invoice_id, $transport_details);
        if (!$payload) {
            return ['status' => 'error', 'message' => 'Failed to prepare e-Way Bill data. Check invoice details.', 'api_communication_log' => []];
        }

        $log_details = [
            'transMode' => $payload['transMode'] ?? '1',
            'transDistance' => $payload['transDistance'] ?? '',
            'vehicleNo' => $payload['vehicleNo'] ?? '',
            'transporterId' => $payload['transporterId'] ?? '',
            'transporterName' => $payload['transporterName'] ?? '',
        ];
        $this->logEWayBill($invoice_id, $log_details, $payload, $bid);

        $apiPayload = $this->sanitizeEwayGeneratePayload($payload);

        $docDateErr = $this->validateNicDocDateWindow($apiPayload['docDate'] ?? '');
        if ($docDateErr !== null) {
            return [
                'status' => 'error',
                'message' => $docDateErr,
                'api_http_code' => 0,
                'api_decoded' => ['nic_precheck' => true, 'docDate' => $apiPayload['docDate'] ?? ''],
                'api_communication_log' => [
                    [
                        'step' => 'Validation (no HTTP call to Perione)',
                        'detail' => $docDateErr,
                        'docDate' => $apiPayload['docDate'] ?? '',
                        'at' => date('c'),
                    ],
                ],
            ];
        }

        $rulesErr = $this->validateNicPayloadBusinessRules($apiPayload);
        if ($rulesErr !== null) {
            return [
                'status' => 'error',
                'message' => $rulesErr,
                'api_http_code' => 0,
                'api_decoded' => ['nic_precheck' => true, 'transactionType' => $apiPayload['transactionType'] ?? null],
                'api_communication_log' => [
                    [
                        'step' => 'Validation (no HTTP call to Perione)',
                        'detail' => $rulesErr,
                        'transactionType' => $apiPayload['transactionType'] ?? null,
                        'at' => date('c'),
                    ],
                ],
            ];
        }

        $this->ewayApiDebugCollecting = true;
        $this->ewayApiDebugLog = [];
        try {
            // 1) Try generate without calling the authenticate endpoint (may still send stored auth-token header if present).
            $token = $settings['auth_token'] ?? '';
            $res = $this->requestGenerateEwayBill($settings, $apiPayload, $token);
            $inner = is_array($res['decoded']) ? $this->parseGenerateSuccessResponse($res['decoded']) : null;

            if ($inner !== null) {
                $this->ewayApiDebugPush([
                    'step' => 'Parsed GENEWAYBILL success',
                    'description' => 'Perione returned status=1 and base64 data decoded to NIC e-way fields',
                    'inner_decoded_data_field_json' => $inner,
                ]);
                $this->updateEwayBillStatus($invoice_id, $inner);
                return $this->withApiCommunicationLog([
                    'status' => 'success',
                    'ewayBillNo' => $inner['ewayBillNo'] ?? '',
                    'ewayBillDate' => $inner['ewayBillDate'] ?? '',
                    'validUpto' => $inner['validUpto'] ?? '',
                ]);
            }

            if ($this->shouldRetryGenerateAfterAuth($res['http_code'], $res['decoded'])) {
                $auth = $this->authenticate($bid, true);
                if (!$auth['status']) {
                    return $this->withApiCommunicationLog([
                        'status' => 'error',
                        'message' => $auth['message'] ?? 'Authentication failed',
                        'api_response' => $auth['api_response'] ?? '',
                        'api_http_code' => isset($auth['api_http_code']) ? (int) $auth['api_http_code'] : 0,
                        'api_decoded' => $auth['api_decoded'] ?? null,
                    ]);
                }
                $settings = $this->getEwayBillSettings($bid);
                if (!$settings) {
                    return $this->withApiCommunicationLog(['status' => 'error', 'message' => 'e-Way Bill settings lost after authentication.']);
                }
                $token = $settings['auth_token'] ?? '';
                $res = $this->requestGenerateEwayBill($settings, $apiPayload, $token);
                $inner = is_array($res['decoded']) ? $this->parseGenerateSuccessResponse($res['decoded']) : null;
                if ($inner !== null) {
                    $this->ewayApiDebugPush([
                        'step' => 'Parsed GENEWAYBILL success (after re-authenticate)',
                        'inner_decoded_data_field_json' => $inner,
                    ]);
                    $this->updateEwayBillStatus($invoice_id, $inner);
                    return $this->withApiCommunicationLog([
                        'status' => 'success',
                        'ewayBillNo' => $inner['ewayBillNo'] ?? '',
                        'ewayBillDate' => $inner['ewayBillDate'] ?? '',
                        'validUpto' => $inner['validUpto'] ?? '',
                    ]);
                }
            }

            $msg = $this->parseGenerateErrorMessage($res['http_code'], $res['decoded'], $res['raw']);
            return $this->withApiCommunicationLog([
                'status' => 'error',
                'message' => $msg,
                'api_response' => $res['raw'],
                'api_http_code' => $res['http_code'],
                'api_decoded' => $res['decoded'],
            ]);
        } finally {
            $this->ewayApiDebugCollecting = false;
        }
    }

    private function logEWayBill($invoice_id, $details, $payload, int $business_id)
    {
        if (!$this->dbOk()) {
            return;
        }
        $sql = "INSERT INTO eway_bills (business_id, invoice_id, trans_mode, trans_distance, vehicle_no, transporter_id, request_payload, status) 
                VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')";
        $stmt = $this->connect->prepare($sql);
        if (!$stmt) {
            error_log('EwayBillController::logEWayBill prepare failed: ' . $this->connect->error);
            return;
        }
        $payload_json = json_encode($payload, self::JSON_FLAGS_EWAY);
        $stmt->bind_param("iiissss", $business_id, $invoice_id, $details['transMode'], $details['transDistance'], $details['vehicleNo'], $details['transporterId'], $payload_json);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * NIC / Perione returns e-way timestamps like "03/05/2026 05:02:00 PM" (DD/MM/YYYY, 12h).
     * MySQL DATETIME needs Y-m-d H:i:s — raw NIC strings become 0000-00-00 00:00:00 if inserted as-is.
     *
     * @return string|null Y-m-d H:i:s in $tz, or null when empty / unparseable
     */
    private static function convertNicDatetimeToMysql(?string $value, string $tz = 'Asia/Kolkata'): ?string
    {
        if ($value === null) {
            return null;
        }
        $s = trim($value);
        if ($s === '' || strcasecmp($s, 'null') === 0 || preg_match('/^0000-00-00/', $s)) {
            return null;
        }
        $tzObj = new \DateTimeZone($tz);
        $formats = [
            'd/m/Y h:i:s A',
            'd/m/Y g:i:s A',
            'd/m/Y h:i A',
            'd/m/Y g:i A',
            'd/m/Y H:i:s',
            'd/m/Y H:i',
            'd/m/Y',
            'Y-m-d H:i:s',
            'Y-m-d H:i',
            'Y-m-d',
        ];
        foreach ($formats as $fmt) {
            $dt = \DateTimeImmutable::createFromFormat($fmt, $s, $tzObj);
            if ($dt !== false) {
                $errs = \DateTimeImmutable::getLastErrors();
                if ($errs && ($errs['warning_count'] > 0 || $errs['error_count'] > 0)) {
                    continue;
                }
                return $dt->format('Y-m-d H:i:s');
            }
        }
        $ts = strtotime($s);
        if ($ts !== false && $ts > 0) {
            return date('Y-m-d H:i:s', $ts);
        }
        return null;
    }

    /** Same as {@see convertNicDatetimeToMysql} but never null — falls back to "now" in Asia/Kolkata. */
    private static function convertNicDatetimeToMysqlOrNow(string $value, string $tz = 'Asia/Kolkata'): string
    {
        $parsed = self::convertNicDatetimeToMysql($value, $tz);
        if ($parsed !== null) {
            return $parsed;
        }
        if ($value !== '') {
            error_log('EwayBillController::convertNicDatetimeToMysqlOrNow: unparseable ewayBillDate: ' . substr($value, 0, 96));
        }
        return (new \DateTimeImmutable('now', new \DateTimeZone($tz)))->format('Y-m-d H:i:s');
    }

    private function updateEwayBillStatus($invoice_id, $response)
    {
        if (!$this->dbOk()) {
            return;
        }
        $ebn = (string) ($response['ewayBillNo'] ?? '');
        $ewbNicRaw = $response['ewayBillDate'] ?? '';
        $ewbNic = is_scalar($ewbNicRaw) ? (string) $ewbNicRaw : '';
        $ewbMysql = self::convertNicDatetimeToMysqlOrNow($ewbNic);

        $validRaw = $response['validUpto'] ?? null;
        $validStr = null;
        if ($validRaw !== null && is_scalar($validRaw)) {
            $validStr = trim((string) $validRaw);
            if ($validStr === '' || strcasecmp($validStr, 'null') === 0) {
                $validStr = null;
            }
        }
        $validMysql = $validStr !== null ? self::convertNicDatetimeToMysql($validStr) : null;

        $res_json = json_encode($response, self::JSON_FLAGS_EWAY);

        if ($validMysql === null) {
            $sql = "UPDATE eway_bills SET eway_bill_no = ?, eway_bill_date = ?, valid_until = NULL, response_payload = ?, status = 'generated' 
                WHERE invoice_id = ? AND status = 'pending' ORDER BY id DESC LIMIT 1";
            $stmt = $this->connect->prepare($sql);
            if (!$stmt) {
                error_log('EwayBillController::updateEwayBillStatus prepare failed: ' . $this->connect->error);
                return;
            }
            $stmt->bind_param('sssi', $ebn, $ewbMysql, $res_json, $invoice_id);
        } else {
            $sql = "UPDATE eway_bills SET eway_bill_no = ?, eway_bill_date = ?, valid_until = ?, response_payload = ?, status = 'generated' 
                WHERE invoice_id = ? AND status = 'pending' ORDER BY id DESC LIMIT 1";
            $stmt = $this->connect->prepare($sql);
            if (!$stmt) {
                error_log('EwayBillController::updateEwayBillStatus prepare failed: ' . $this->connect->error);
                return;
            }
            $stmt->bind_param('ssssi', $ebn, $ewbMysql, $validMysql, $res_json, $invoice_id);
        }
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Get defaults for a specific form and business
     */
    public function getFormDefaults($business_id, $form_key)
    {
        if (!$this->dbOk()) {
            return [];
        }
        $sql = "SELECT field_key, field_value FROM business_form_defaults WHERE business_id = ? AND form_key = ?";
        $stmt = $this->connect->prepare($sql);
        if (!$stmt)
            return [];
        $stmt->bind_param("is", $business_id, $form_key);
        $stmt->execute();
        $res = $stmt->get_result();
        $defaults = [];
        while ($row = $res->fetch_assoc()) {
            $defaults[$row['field_key']] = $row['field_value'];
        }
        $stmt->close();
        return $defaults;
    }

    /**
     * Save/Update a default value for a form field
     */
    public function saveFormDefault($business_id, $form_key, $field_key, $field_value)
    {
        if (!$this->dbOk()) {
            return false;
        }
        $sql = "INSERT INTO business_form_defaults (business_id, form_key, field_key, field_value) 
                VALUES (?, ?, ?, ?) 
                ON DUPLICATE KEY UPDATE field_value = VALUES(field_value)";
        $stmt = $this->connect->prepare($sql);
        if (!$stmt)
            return false;
        $stmt->bind_param("isss", $business_id, $form_key, $field_key, $field_value);
        $success = $stmt->execute();
        $stmt->close();
        return $success;
    }

    /**
     * GET getgstindetails — Perione OpenAPI: query `email`, `GSTIN`; headers ip_address, client_id, client_secret, gstin.
     * Optional `auth-token` after authenticate if the gateway requires a session.
     *
     * @return array{http_code:int,raw:string,decoded:?array,curl_errno:int,curl_error:string}
     */
    private function requestGetGstinDetails(array $settings, string $targetGstin, $authToken = '')
    {
        $ip = $this->effectiveIpForEway($settings);
        $query = http_build_query([
            'email' => $settings['api_email'],
            'GSTIN' => $targetGstin,
        ], '', '&', PHP_QUERY_RFC3986);

        $headers = [
            'Accept: application/json',
            'ip_address: ' . $ip,
            'client_id: ' . $settings['client_id'],
            'client_secret: ' . $settings['client_secret'],
            'gstin: ' . $settings['gstin'],
        ];
        if ($authToken !== null && $authToken !== '') {
            $headers[] = 'auth-token: ' . $authToken;
        }

        $url = rtrim($this->api_base_url, '/') . '/' . ltrim($this->get_gstin_details_path, '/') . '?' . $query;

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $response = curl_exec($ch);
        $curl_errno = (int) curl_errno($ch);
        $curl_error = (string) curl_error($ch);
        $http_code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $decoded = is_string($response) ? json_decode($response, true) : null;

        $this->ewayApiDebugPush([
            'step' => 'GET getgstindetails',
            'description' => 'Perione GSTIN master lookup (query: email, GSTIN; headers: ip_address, client_id, client_secret, gstin; optional auth-token)',
            'request' => [
                'method' => 'GET',
                'url' => $url,
                'headers_masked' => self::maskHeaderLinesForLog($headers),
                'auth_token_sent' => ($authToken !== null && $authToken !== '') ? 'yes (masked in headers above)' : 'no',
            ],
            'response' => [
                'http_status' => $http_code,
                'curl_errno' => $curl_errno,
                'curl_error' => $curl_error !== '' ? $curl_error : null,
                'body_raw' => is_string($response) ? $response : '',
                'body_json' => is_array($decoded) ? $decoded : null,
            ],
        ]);

        return [
            'http_code' => $http_code,
            'raw' => is_string($response) ? $response : '',
            'decoded' => is_array($decoded) ? $decoded : null,
            'curl_errno' => $curl_errno,
            'curl_error' => $curl_error,
        ];
    }

    /**
     * @return array|null Normalized data row or null if not a success payload.
     */
    private static function parseGetGstinDetailsSuccess(?array $decoded)
    {
        if (!is_array($decoded) || !isset($decoded['status_cd']) || (string) $decoded['status_cd'] !== '1') {
            return null;
        }
        $data = $decoded['data'] ?? null;
        if (!is_array($data)) {
            return null;
        }
        $g0 = trim((string) ($data['gstin'] ?? ''));
        $t0 = trim((string) ($data['tradeName'] ?? ''));
        $l0 = trim((string) ($data['legalName'] ?? ''));
        if ($g0 === '' && $t0 === '' && $l0 === '') {
            return null;
        }
        return [
            'gstin' => strtoupper(preg_replace('/\s+/', '', (string) ($data['gstin'] ?? ''))),
            'tradeName' => (string) ($data['tradeName'] ?? ''),
            'legalName' => (string) ($data['legalName'] ?? ''),
            'address1' => (string) ($data['address1'] ?? ''),
            'address2' => (string) ($data['address2'] ?? ''),
            'stateCode' => (string) ($data['stateCode'] ?? ''),
            'pinCode' => (string) ($data['pinCode'] ?? ''),
            'txpType' => (string) ($data['txpType'] ?? ''),
            'status' => (string) ($data['status'] ?? ''),
            'blkStatus' => (string) ($data['blkStatus'] ?? ''),
        ];
    }

    /**
     * Fetch GSTIN master details (consignee / transporter / ship-to) via Perione getgstindetails.
     * Tries without auth-token first (matches vendor sample), then stored token, then authenticate + one retry.
     */
    public function getGstDetails($business_id, $targetGstin)
    {
        if (!$this->dbOk()) {
            return ['status' => 'error', 'message' => 'Database connection unavailable.', 'api_communication_log' => []];
        }
        $settings = $this->getEwayBillSettings($business_id);
        if (!$settings) {
            return ['status' => 'error', 'message' => 'e-Way Bill settings not found for this business.', 'api_communication_log' => []];
        }

        $g = strtoupper(preg_replace('/\s+/', '', (string) $targetGstin));
        if (stripos($g, 'URP') !== false || strlen($g) !== 15 || !preg_match('/^[0-9A-Z]{15}$/', $g)) {
            return [
                'status' => 'error',
                'message' => 'Enter a valid 15-character GSTIN or TRANSIN. Unregistered party (URP) cannot be looked up.',
                'api_communication_log' => [],
            ];
        }

        $this->ewayApiDebugCollecting = true;
        $this->ewayApiDebugLog = [];
        try {
            $res = $this->requestGetGstinDetails($settings, $g, '');
            $row = self::parseGetGstinDetailsSuccess($res['decoded']);
            if ($row !== null) {
                return $this->withApiCommunicationLog(['status' => 'success', 'data' => $row]);
            }

            $storedToken = trim((string) ($settings['auth_token'] ?? ''));
            if ($storedToken !== '') {
                $res = $this->requestGetGstinDetails($settings, $g, $storedToken);
                $row = self::parseGetGstinDetailsSuccess($res['decoded']);
                if ($row !== null) {
                    return $this->withApiCommunicationLog(['status' => 'success', 'data' => $row]);
                }
            }

            $last = $res;
            if ($this->shouldRetryGenerateAfterAuth($last['http_code'], $last['decoded'])) {
                $auth = $this->authenticate($business_id, true);
                if (!$auth['status']) {
                    return $this->withApiCommunicationLog([
                        'status' => 'error',
                        'message' => 'GSTIN lookup failed and re-authentication did not succeed: ' . ($auth['message'] ?? ''),
                        'api_response' => $auth['api_response'] ?? '',
                        'api_http_code' => isset($auth['api_http_code']) ? (int) $auth['api_http_code'] : 0,
                        'api_decoded' => $auth['api_decoded'] ?? null,
                    ]);
                }
                $settings = $this->getEwayBillSettings($business_id);
                if (!$settings) {
                    return $this->withApiCommunicationLog(['status' => 'error', 'message' => 'e-Way Bill settings lost after authentication.']);
                }
                $tok = trim((string) ($settings['auth_token'] ?? ''));
                $res = $this->requestGetGstinDetails($settings, $g, $tok);
                $row = self::parseGetGstinDetailsSuccess($res['decoded']);
                if ($row !== null) {
                    return $this->withApiCommunicationLog(['status' => 'success', 'data' => $row]);
                }
                $last = $res;
            }

            if ($last['curl_errno'] !== 0) {
                return $this->withApiCommunicationLog([
                    'status' => 'error',
                    'message' => 'Network error calling GSTIN API: ' . $last['curl_error'],
                    'api_http_code' => $last['http_code'],
                ]);
            }

            $decoded = $last['decoded'];
            $msg = is_array($decoded)
                ? self::formatNicGatewayError($decoded, $last['raw'])
                : ('GSTIN lookup failed (HTTP ' . $last['http_code'] . ').');
            return $this->withApiCommunicationLog([
                'status' => 'error',
                'message' => $msg,
                'api_response' => $last['raw'],
                'api_http_code' => $last['http_code'],
                'api_decoded' => $decoded,
            ]);
        } finally {
            $this->ewayApiDebugCollecting = false;
        }
    }

    /**
     * GET /getewaybill — fetch full e-Way Bill record by EBN.
     *
     * @return array{http_code:int,raw:string,decoded:?array,curl_errno:int,curl_error:string}
     */
    private function requestGetEwayBill(array $settings, string $ebn, $authToken = '')
    {
        $ip = $this->effectiveIpForEway($settings);
        $query = http_build_query([
            'email' => $settings['api_email'],
            'ewbNo' => $ebn,
        ], '', '&', PHP_QUERY_RFC3986);

        $headers = [
            'Accept: application/json',
            'ip_address: ' . $ip,
            'client_id: ' . $settings['client_id'],
            'client_secret: ' . $settings['client_secret'],
            'gstin: ' . $settings['gstin'],
        ];
        if ($authToken !== '') {
            $headers[] = 'auth-token: ' . $authToken;
        }

        $url = rtrim($this->api_base_url, '/') . '/' . ltrim($this->get_eway_path, '/') . '?' . $query;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT => 30,
        ]);
        $response = curl_exec($ch);
        $errno = (int) curl_errno($ch);
        $err = (string) curl_error($ch);
        $http_code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $decoded = is_string($response) ? json_decode($response, true) : null;

        $this->ewayApiDebugPush([
            'step' => 'GET getewaybill',
            'description' => 'Perione fetch e-Way Bill by ewbNo (query: email, ewbNo; headers: ip_address, client_id, client_secret, gstin)',
            'request' => [
                'method' => 'GET',
                'url' => $url,
                'headers_masked' => self::maskHeaderLinesForLog($headers),
            ],
            'response' => [
                'http_status' => $http_code,
                'curl_errno' => $errno,
                'curl_error' => $err !== '' ? $err : null,
                'body_raw' => is_string($response) ? $response : '',
                'body_json' => is_array($decoded) ? $decoded : null,
            ],
        ]);

        return [
            'http_code' => $http_code,
            'raw' => is_string($response) ? $response : '',
            'decoded' => is_array($decoded) ? $decoded : null,
            'curl_errno' => $errno,
            'curl_error' => $err,
        ];
    }

    /**
     * Public: View e-Way Bill by EBN. Returns normalized data + full API log.
     */
    public function viewEwayBill($business_id, $ebn)
    {
        if (!$this->dbOk()) {
            return ['status' => 'error', 'message' => 'Database connection unavailable.', 'api_communication_log' => []];
        }
        $settings = $this->getEwayBillSettings($business_id);
        if (!$settings) {
            return ['status' => 'error', 'message' => 'e-Way Bill settings not found for this business.', 'api_communication_log' => []];
        }
        $ebnClean = preg_replace('/\D+/', '', (string) $ebn);
        if (strlen($ebnClean) < 8) {
            return ['status' => 'error', 'message' => 'A valid 12-digit e-Way Bill Number (EBN) is required.', 'api_communication_log' => []];
        }

        $this->ewayApiDebugCollecting = true;
        $this->ewayApiDebugLog = [];
        try {
            $res = $this->requestGetEwayBill($settings, $ebnClean, '');
            if ($this->shouldRetryGenerateAfterAuth($res['http_code'], $res['decoded'])) {
                $auth = $this->authenticate($business_id, true);
                if ($auth['status']) {
                    $settings = $this->getEwayBillSettings($business_id) ?: $settings;
                    $tok = trim((string) ($settings['auth_token'] ?? ''));
                    $res = $this->requestGetEwayBill($settings, $ebnClean, $tok);
                }
            }

            $decoded = $res['decoded'];
            if (is_array($decoded) && isset($decoded['status_cd']) && (string) $decoded['status_cd'] === '1' && is_array($decoded['data'] ?? null)) {
                return $this->withApiCommunicationLog([
                    'status' => 'success',
                    'data' => $decoded['data'],
                ]);
            }

            $msg = is_array($decoded)
                ? self::formatNicGatewayError($decoded, $res['raw'])
                : ($res['curl_errno'] ? ('Network error: ' . $res['curl_error']) : ('View failed (HTTP ' . $res['http_code'] . ').'));
            return $this->withApiCommunicationLog([
                'status' => 'error',
                'message' => $msg,
                'api_response' => $res['raw'],
                'api_http_code' => $res['http_code'],
                'api_decoded' => $decoded,
            ]);
        } finally {
            $this->ewayApiDebugCollecting = false;
        }
    }

    /**
     * POST /canewb — cancel an e-Way Bill within 24h of generation. NIC reason codes:
     *   1 = Duplicate, 2 = Order Cancelled, 3 = Data Entry Mistake, 4 = Others.
     */
    private function requestCancelEwayBill(array $settings, array $payload, $authToken = '')
    {
        $body = json_encode($payload, self::JSON_FLAGS_EWAY);
        $url = rtrim($this->api_base_url, '/') . '/' . ltrim($this->cancel_eway_path, '/')
            . '?email=' . rawurlencode($settings['api_email']);
        $headers = $this->buildGenerateHeaders($settings, $authToken);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT => 30,
        ]);
        $response = curl_exec($ch);
        $errno = (int) curl_errno($ch);
        $err = (string) curl_error($ch);
        $http_code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $decoded = is_string($response) ? json_decode($response, true) : null;

        $this->ewayApiDebugPush([
            'step' => 'POST canewb',
            'description' => 'Perione Cancel e-Way Bill — body is raw NIC JSON {ewbNo, cancelRsnCode, cancelRmrk}',
            'request' => [
                'method' => 'POST',
                'url' => $url,
                'headers_masked' => self::maskHeaderLinesForLog($headers),
                'body_json_sent_to_gateway' => $payload,
            ],
            'response' => [
                'http_status' => $http_code,
                'curl_errno' => $errno,
                'curl_error' => $err !== '' ? $err : null,
                'body_raw' => is_string($response) ? $response : '',
                'body_json' => is_array($decoded) ? $decoded : null,
            ],
        ]);

        return [
            'http_code' => $http_code,
            'raw' => is_string($response) ? $response : '',
            'decoded' => is_array($decoded) ? $decoded : null,
            'curl_errno' => $errno,
            'curl_error' => $err,
        ];
    }

    /**
     * Public: Cancel e-Way Bill. Updates eway_bills.status -> "cancelled" on success.
     */
    public function cancelEwayBill($business_id, $ebn, int $reasonCode, $remarks = '')
    {
        if (!$this->dbOk()) {
            return ['status' => 'error', 'message' => 'Database connection unavailable.', 'api_communication_log' => []];
        }
        $settings = $this->getEwayBillSettings($business_id);
        if (!$settings) {
            return ['status' => 'error', 'message' => 'e-Way Bill settings not found for this business.', 'api_communication_log' => []];
        }
        $ebnClean = preg_replace('/\D+/', '', (string) $ebn);
        if (strlen($ebnClean) < 8) {
            return ['status' => 'error', 'message' => 'A valid 12-digit e-Way Bill Number (EBN) is required.', 'api_communication_log' => []];
        }
        if (!in_array($reasonCode, [1, 2, 3, 4], true)) {
            return ['status' => 'error', 'message' => 'Cancel reason code must be 1 (Duplicate), 2 (Order Cancelled), 3 (Data Entry Mistake) or 4 (Others).', 'api_communication_log' => []];
        }
        $remarksClean = trim((string) $remarks);
        if ($remarksClean === '') {
            $remarksClean = 'Cancelled via Archit business dashboard';
        }

        $payload = [
            'ewbNo' => (int) $ebnClean,
            'cancelRsnCode' => $reasonCode,
            'cancelRmrk' => substr($remarksClean, 0, 100),
        ];

        $this->ewayApiDebugCollecting = true;
        $this->ewayApiDebugLog = [];
        try {
            $res = $this->requestCancelEwayBill($settings, $payload, '');
            if ($this->shouldRetryGenerateAfterAuth($res['http_code'], $res['decoded'])) {
                $auth = $this->authenticate($business_id, true);
                if ($auth['status']) {
                    $settings = $this->getEwayBillSettings($business_id) ?: $settings;
                    $tok = trim((string) ($settings['auth_token'] ?? ''));
                    $res = $this->requestCancelEwayBill($settings, $payload, $tok);
                }
            }

            $decoded = $res['decoded'];
            $ok = is_array($decoded) && isset($decoded['status_cd']) && (string) $decoded['status_cd'] === '1';
            if ($ok) {
                $this->markEwayBillCancelled($ebnClean, $payload, $decoded);
                return $this->withApiCommunicationLog([
                    'status' => 'success',
                    'ewbNo' => $ebnClean,
                    'cancelDate' => $decoded['data']['cancelDate'] ?? null,
                    'data' => $decoded['data'] ?? null,
                ]);
            }

            $msg = is_array($decoded)
                ? self::formatNicGatewayError($decoded, $res['raw'])
                : ($res['curl_errno'] ? ('Network error: ' . $res['curl_error']) : ('Cancel failed (HTTP ' . $res['http_code'] . ').'));
            return $this->withApiCommunicationLog([
                'status' => 'error',
                'message' => $msg,
                'api_response' => $res['raw'],
                'api_http_code' => $res['http_code'],
                'api_decoded' => $decoded,
            ]);
        } finally {
            $this->ewayApiDebugCollecting = false;
        }
    }

    /**
     * POST /vehewb — Update PART-B (vehicle / trans-doc) on an existing e-Way Bill.
     */
    private function requestUpdatePartB(array $settings, array $payload, $authToken = '')
    {
        $body = json_encode($payload, self::JSON_FLAGS_EWAY);
        $url = rtrim($this->api_base_url, '/') . '/' . ltrim($this->update_partb_path, '/')
            . '?email=' . rawurlencode($settings['api_email']);
        $headers = $this->buildGenerateHeaders($settings, $authToken);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT => 30,
        ]);
        $response = curl_exec($ch);
        $errno = (int) curl_errno($ch);
        $err = (string) curl_error($ch);
        $http_code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $decoded = is_string($response) ? json_decode($response, true) : null;

        $this->ewayApiDebugPush([
            'step' => 'POST vehewb',
            'description' => 'Perione Update PART-B / Vehicle — body is raw NIC JSON {ewbNo, vehicleNo, fromPlace, fromState, reasonCode, reasonRem, transDocNo, transDocDate, transMode}',
            'request' => [
                'method' => 'POST',
                'url' => $url,
                'headers_masked' => self::maskHeaderLinesForLog($headers),
                'body_json_sent_to_gateway' => $payload,
            ],
            'response' => [
                'http_status' => $http_code,
                'curl_errno' => $errno,
                'curl_error' => $err !== '' ? $err : null,
                'body_raw' => is_string($response) ? $response : '',
                'body_json' => is_array($decoded) ? $decoded : null,
            ],
        ]);

        return [
            'http_code' => $http_code,
            'raw' => is_string($response) ? $response : '',
            'decoded' => is_array($decoded) ? $decoded : null,
            'curl_errno' => $errno,
            'curl_error' => $err,
        ];
    }

    /**
     * Public: Update PART-B (vehicle/trans-doc).
     *
     * @param int|string $ebn
     * @param array $fields {vehicleNo, fromPlace, fromState, reasonCode, reasonRem, transDocNo, transDocDate, transMode}
     */
    public function updateEwayBillPartB($business_id, $ebn, array $fields)
    {
        if (!$this->dbOk()) {
            return ['status' => 'error', 'message' => 'Database connection unavailable.', 'api_communication_log' => []];
        }
        $settings = $this->getEwayBillSettings($business_id);
        if (!$settings) {
            return ['status' => 'error', 'message' => 'e-Way Bill settings not found for this business.', 'api_communication_log' => []];
        }
        $ebnClean = preg_replace('/\D+/', '', (string) $ebn);
        if (strlen($ebnClean) < 8) {
            return ['status' => 'error', 'message' => 'A valid e-Way Bill Number (EBN) is required.', 'api_communication_log' => []];
        }

        $reasonCode = (string) ($fields['reasonCode'] ?? '');
        if (!in_array($reasonCode, ['1', '2', '3', '4'], true)) {
            return ['status' => 'error', 'message' => 'Reason Code must be 1 Break Down, 2 Trans Shipment, 3 Others, or 4 First Time.', 'api_communication_log' => []];
        }
        $fromState = (int) ($fields['fromState'] ?? 0);
        if ($fromState <= 0 || $fromState > 99) {
            return ['status' => 'error', 'message' => 'From State (1–99) is required.', 'api_communication_log' => []];
        }
        $fromPlace = trim((string) ($fields['fromPlace'] ?? ''));
        if ($fromPlace === '') {
            return ['status' => 'error', 'message' => 'From Place is required.', 'api_communication_log' => []];
        }
        $transMode = trim((string) ($fields['transMode'] ?? ''));
        if (!in_array($transMode, ['1', '2', '3', '4', '5'], true)) {
            return ['status' => 'error', 'message' => 'Transport Mode (1 Road / 2 Rail / 3 Air / 4 Ship / 5 In Transit) is required.', 'api_communication_log' => []];
        }
        $vehicleNo = strtoupper(preg_replace('/\s+/', '', (string) ($fields['vehicleNo'] ?? '')));
        $transDocNo = trim((string) ($fields['transDocNo'] ?? ''));
        if ($transMode === '1' && $vehicleNo === '') {
            return ['status' => 'error', 'message' => 'Vehicle No is mandatory when Transport Mode is Road (1).', 'api_communication_log' => []];
        }
        if (in_array($transMode, ['2', '3', '4'], true) && $transDocNo === '') {
            return ['status' => 'error', 'message' => 'Trans Doc No is mandatory when Transport Mode is Rail / Air / Ship.', 'api_communication_log' => []];
        }
        $transDocDate = trim((string) ($fields['transDocDate'] ?? ''));
        if ($transDocDate !== '') {
            $transDocDate = $this->normalizeEwayDateString($transDocDate);
        }

        $payload = [
            'ewbNo' => (int) $ebnClean,
            'vehicleNo' => $vehicleNo,
            'fromPlace' => substr($fromPlace, 0, 50),
            'fromState' => $fromState,
            'reasonCode' => $reasonCode,
            'reasonRem' => substr(trim((string) ($fields['reasonRem'] ?? 'Updated via Archit dashboard')), 0, 50),
            'transDocNo' => substr($transDocNo, 0, 15),
            'transDocDate' => $transDocDate,
            'transMode' => $transMode,
        ];

        $this->ewayApiDebugCollecting = true;
        $this->ewayApiDebugLog = [];
        try {
            $res = $this->requestUpdatePartB($settings, $payload, '');
            if ($this->shouldRetryGenerateAfterAuth($res['http_code'], $res['decoded'])) {
                $auth = $this->authenticate($business_id, true);
                if ($auth['status']) {
                    $settings = $this->getEwayBillSettings($business_id) ?: $settings;
                    $tok = trim((string) ($settings['auth_token'] ?? ''));
                    $res = $this->requestUpdatePartB($settings, $payload, $tok);
                }
            }
            $decoded = $res['decoded'];
            $ok = is_array($decoded) && isset($decoded['status_cd']) && (string) $decoded['status_cd'] === '1';
            if ($ok) {
                $inner = is_array($decoded['data'] ?? null) ? $decoded['data'] : [];
                if (!empty($inner['validUpto'])) {
                    $this->updateEwayBillValidUpto($ebnClean, (string) $inner['validUpto']);
                }
                return $this->withApiCommunicationLog([
                    'status' => 'success',
                    'ewbNo' => $ebnClean,
                    'vehUpdDate' => $inner['vehUpdDate'] ?? null,
                    'validUpto' => $inner['validUpto'] ?? null,
                    'data' => $inner,
                ]);
            }
            $msg = is_array($decoded)
                ? self::formatNicGatewayError($decoded, $res['raw'])
                : ($res['curl_errno'] ? ('Network error: ' . $res['curl_error']) : ('PART-B update failed (HTTP ' . $res['http_code'] . ').'));
            return $this->withApiCommunicationLog([
                'status' => 'error',
                'message' => $msg,
                'api_response' => $res['raw'],
                'api_http_code' => $res['http_code'],
                'api_decoded' => $decoded,
            ]);
        } finally {
            $this->ewayApiDebugCollecting = false;
        }
    }

    /**
     * POST /updatetransporter — change the transporter assigned to an existing e-Way Bill.
     */
    private function requestUpdateTransporter(array $settings, array $payload, $authToken = '')
    {
        $body = json_encode($payload, self::JSON_FLAGS_EWAY);
        $url = rtrim($this->api_base_url, '/') . '/' . ltrim($this->update_transporter_path, '/')
            . '?email=' . rawurlencode($settings['api_email']);
        $headers = $this->buildGenerateHeaders($settings, $authToken);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT => 30,
        ]);
        $response = curl_exec($ch);
        $errno = (int) curl_errno($ch);
        $err = (string) curl_error($ch);
        $http_code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $decoded = is_string($response) ? json_decode($response, true) : null;

        $this->ewayApiDebugPush([
            'step' => 'POST updatetransporter',
            'description' => 'Perione Update Transporter — body is raw NIC JSON {ewbNo, transporterId}',
            'request' => [
                'method' => 'POST',
                'url' => $url,
                'headers_masked' => self::maskHeaderLinesForLog($headers),
                'body_json_sent_to_gateway' => $payload,
            ],
            'response' => [
                'http_status' => $http_code,
                'curl_errno' => $errno,
                'curl_error' => $err !== '' ? $err : null,
                'body_raw' => is_string($response) ? $response : '',
                'body_json' => is_array($decoded) ? $decoded : null,
            ],
        ]);

        return [
            'http_code' => $http_code,
            'raw' => is_string($response) ? $response : '',
            'decoded' => is_array($decoded) ? $decoded : null,
            'curl_errno' => $errno,
            'curl_error' => $err,
        ];
    }

    /** Public: Update Transporter ID assigned to an EBN. */
    public function updateEwayBillTransporter($business_id, $ebn, $transporterId)
    {
        if (!$this->dbOk()) {
            return ['status' => 'error', 'message' => 'Database connection unavailable.', 'api_communication_log' => []];
        }
        $settings = $this->getEwayBillSettings($business_id);
        if (!$settings) {
            return ['status' => 'error', 'message' => 'e-Way Bill settings not found for this business.', 'api_communication_log' => []];
        }
        $ebnClean = preg_replace('/\D+/', '', (string) $ebn);
        if (strlen($ebnClean) < 8) {
            return ['status' => 'error', 'message' => 'A valid e-Way Bill Number (EBN) is required.', 'api_communication_log' => []];
        }
        $tid = strtoupper(preg_replace('/\s+/', '', (string) $transporterId));
        if (strlen($tid) !== 15 || !preg_match('/^[0-9A-Z]{15}$/', $tid)) {
            return ['status' => 'error', 'message' => 'Transporter ID must be a valid 15-character GSTIN or TRANSIN.', 'api_communication_log' => []];
        }

        $payload = ['ewbNo' => (int) $ebnClean, 'transporterId' => $tid];

        $this->ewayApiDebugCollecting = true;
        $this->ewayApiDebugLog = [];
        try {
            $res = $this->requestUpdateTransporter($settings, $payload, '');
            if ($this->shouldRetryGenerateAfterAuth($res['http_code'], $res['decoded'])) {
                $auth = $this->authenticate($business_id, true);
                if ($auth['status']) {
                    $settings = $this->getEwayBillSettings($business_id) ?: $settings;
                    $tok = trim((string) ($settings['auth_token'] ?? ''));
                    $res = $this->requestUpdateTransporter($settings, $payload, $tok);
                }
            }
            $decoded = $res['decoded'];
            $ok = is_array($decoded) && isset($decoded['status_cd']) && (string) $decoded['status_cd'] === '1';
            if ($ok) {
                $this->updateEwayBillTransporterIdLocal($ebnClean, $tid);
                return $this->withApiCommunicationLog([
                    'status' => 'success',
                    'ewbNo' => $ebnClean,
                    'transporterId' => $tid,
                    'data' => $decoded['data'] ?? null,
                ]);
            }
            $msg = is_array($decoded)
                ? self::formatNicGatewayError($decoded, $res['raw'])
                : ($res['curl_errno'] ? ('Network error: ' . $res['curl_error']) : ('Update transporter failed (HTTP ' . $res['http_code'] . ').'));
            return $this->withApiCommunicationLog([
                'status' => 'error',
                'message' => $msg,
                'api_response' => $res['raw'],
                'api_http_code' => $res['http_code'],
                'api_decoded' => $decoded,
            ]);
        } finally {
            $this->ewayApiDebugCollecting = false;
        }
    }

    /** Local cache: store new validUpto returned after Part-B update. */
    private function updateEwayBillValidUpto(string $ebnClean, string $validUpto)
    {
        if (!$this->dbOk()) {
            return;
        }
        $mysql = self::convertNicDatetimeToMysql($validUpto);
        if ($mysql === null) {
            return;
        }
        $sql = "UPDATE eway_bills SET valid_until = ? WHERE eway_bill_no = ? ORDER BY id DESC LIMIT 1";
        $stmt = $this->connect->prepare($sql);
        if (!$stmt) {
            return;
        }
        $stmt->bind_param('ss', $mysql, $ebnClean);
        $stmt->execute();
        $stmt->close();
    }

    /** Local cache: update transporter_id column on the matching row. */
    private function updateEwayBillTransporterIdLocal(string $ebnClean, string $transporterId)
    {
        if (!$this->dbOk()) {
            return;
        }
        $sql = "UPDATE eway_bills SET transporter_id = ? WHERE eway_bill_no = ? ORDER BY id DESC LIMIT 1";
        $stmt = $this->connect->prepare($sql);
        if (!$stmt) {
            return;
        }
        $stmt->bind_param('ss', $transporterId, $ebnClean);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Mark eway_bills.status = 'cancelled' for the matching EBN (most recent row).
     */
    private function markEwayBillCancelled(string $ebnClean, array $cancelPayload, array $decodedResponse)
    {
        if (!$this->dbOk()) {
            return;
        }
        $sql = "UPDATE eway_bills
                   SET status = 'cancelled',
                       response_payload = ?
                 WHERE eway_bill_no = ?
                 ORDER BY id DESC
                 LIMIT 1";
        $stmt = $this->connect->prepare($sql);
        if (!$stmt) {
            error_log('EwayBillController::markEwayBillCancelled prepare failed: ' . $this->connect->error);
            return;
        }
        $payloadJson = json_encode([
            'cancel_request' => $cancelPayload,
            'cancel_response' => $decodedResponse,
        ], self::JSON_FLAGS_EWAY);
        $stmt->bind_param('ss', $payloadJson, $ebnClean);
        $stmt->execute();
        $stmt->close();
    }
}
