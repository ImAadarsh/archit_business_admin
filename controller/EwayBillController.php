<?php
// business/controller/EwayBillController.php

class EwayBillController
{
    private $connect;
    // Base URL for the 3rd party vendor API.
    private $api_base_url = "https://apisandbox.whitebooks.in/";
    private $auth_path = "ewaybillapi/v1.03/authenticate";
    private $generate_path = "ewaybillapi/v1.03/ewayapi/genewaybill";
    private $get_gstin_details_path = "ewaybillapi/v1.03/ewayapi/getgstindetails";

    public function __construct($connect)
    {
        $this->connect = $connect;
    }

    /**
     * Get e-Way bill settings for a business
     */
    public function getEwayBillSettings($business_id)
    {
        $sql = "SELECT * FROM eway_bill_settings WHERE business_id = ?";
        $stmt = $this->connect->prepare($sql);
        if (!$stmt)
            return null;
        $stmt->bind_param("i", $business_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }

    /**
     * Authenticate with vendor API and get token
     */
    public function authenticate($business_id)
    {
        $settings = $this->getEwayBillSettings($business_id);
        if (!$settings) {
            return ['status' => false, 'message' => 'e-Way Bill settings not found for this business.'];
        }

        // Check if token is still valid (expire minus 5 mins buffer)
        if ($settings['auth_token'] && $settings['token_expiry'] && strtotime($settings['token_expiry']) > (time() + 300)) {
            return ['status' => true, 'token' => $settings['auth_token']];
        }

        // Real API call for authentication (GET request based on postman)
        $params = [
            'email' => $settings['api_email'],
            'username' => $settings['api_username'],
            'password' => $settings['api_password']
        ];

        $headers = [
            'ip_address: ' . ($settings['ip_address'] ?? $_SERVER['SERVER_ADDR']),
            'client_id: ' . $settings['client_id'],
            'client_secret: ' . $settings['client_secret'],
            'gstin: ' . $settings['gstin']
        ];

        $url = rtrim($this->api_base_url, '/') . '/' . ltrim($this->auth_path, '/') . '?' . http_build_query($params);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $result = json_decode($response, true);

        if ($http_code === 200 && isset($result['token'])) {
            // Update token in DB (Assuming token is valid for 24h if not specified)
            $token = $result['token'];
            $expiry = date('Y-m-d H:i:s', time() + 24 * 3600);

            $update_sql = "UPDATE eway_bill_settings SET auth_token = ?, token_expiry = ? WHERE business_id = ?";
            $update_stmt = $this->connect->prepare($update_sql);
            $update_stmt->bind_param("ssi", $token, $expiry, $business_id);
            $update_stmt->execute();
            $update_stmt->close();

            return ['status' => true, 'token' => $token];
        }

        $msg = $result['message'] ?? $result['error'] ?? 'Unknown error';
        return [
            'status' => false,
            'message' => 'Authentication failed: ' . $msg,
            'api_response' => $response,
            'api_http_code' => $http_code,
            'api_decoded' => $result
        ];
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
            die("Error preparing statement (prepareEwayBillData): " . $this->connect->error);
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
            die("Error preparing statement (items_sql): " . $this->connect->error);
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
                "cessRate" => 0
            ];
        }
        $stmt->close();

        $transMode = $transport_details['transMode'] ?? '1';
        $vehicleType = ($transMode === '1') ? 'R' : (($transMode === '2') ? 'R' : (($transMode === '3') ? 'A' : 'S'));

        $totalTaxable = 0;
        foreach ($items as $it) {
            $totalTaxable += (float) $it['taxableAmount'];
        }

        return [
            "supplyType" => "O",
            "subSupplyType" => "1",
            "subSupplyDesc" => " ",
            "docType" => "INV",
            "docNo" => (string) $invoice['serial_no'],
            "docDate" => date('d/m/Y', strtotime($invoice['invoice_date'])),
            "fromGstin" => $invoice['fromGstin'] ?: "URP",
            "fromTrdName" => $invoice['business_name'],
            "fromAddr1" => $fromAddrLines[0] ?: $invoice['location_name'],
            "fromAddr2" => $fromAddrLines[1],
            "fromPlace" => $invoice['location_name'],
            "fromPincode" => $fromPincode,
            "fromStateCode" => $fromStateCode,
            "actFromStateCode" => $fromStateCode,
            "toGstin" => !empty($invoice['doc_no']) ? $invoice['doc_no'] : "URP",
            "toTrdName" => $invoice['name'],
            "toAddr1" => $invoice['toAddr1'] ?? '',
            "toAddr2" => $invoice['toAddr2'] ?? '',
            "toPlace" => $invoice['toCity'] ?? '',
            "toPincode" => $toPincode,
            "toStateCode" => $toStateCode,
            "actToStateCode" => $toStateCode,
            "transactionType" => 1,
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
            "transporterId" => $transport_details['transporterId'] ?? '',
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
    /**
     * Generate e-Way bill by calling external API
     */
    public function generateEwayBill($invoice_id, $transport_details, $payload_override = null)
    {
        $business_id = $_SESSION['business_id'];
        $auth = $this->authenticate($business_id);

        if (!$auth['status']) {
            return $auth;
        }

        $token = $auth['token'];
        $payload = $payload_override !== null ? $payload_override : $this->prepareEwayBillData($invoice_id, $transport_details);

        if (!$payload) {
            return ['status' => false, 'message' => 'Failed to prepare e-Way Bill data. Check invoice details.'];
        }

        $log_details = [
            'transMode' => $payload['transMode'] ?? '1',
            'transDistance' => $payload['transDistance'] ?? '',
            'vehicleNo' => $payload['vehicleNo'] ?? '',
            'transporterId' => $payload['transporterId'] ?? '',
            'transporterName' => $payload['transporterName'] ?? '',
        ];
        $this->logEWayBill($invoice_id, $log_details, $payload);

        // Real API Call
        $settings = $this->getEwayBillSettings($business_id);
        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
            'ip_address: ' . ($settings['ip_address'] ?? $_SERVER['SERVER_ADDR']),
            'client_id: ' . $settings['client_id'],
            'client_secret: ' . $settings['client_secret'],
            'gstin: ' . $settings['gstin']
        ];

        $url = rtrim($this->api_base_url, '/') . '/' . ltrim($this->generate_path, '/') . '?email=' . urlencode($settings['api_email']);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $result = json_decode($response, true);

        if ($http_code === 200 && isset($result['status']) && $result['status'] === 'success') {
            $this->updateEwayBillStatus($invoice_id, $result);
            return [
                'status' => 'success',
                'ewayBillNo' => $result['ewayBillNo'],
                'ewayBillDate' => $result['ewayBillDate'],
                'validUpto' => $result['validUpto']
            ];
        }

        $msg = $result['message'] ?? $result['error'] ?? 'API Error (Code: ' . $http_code . ')';
        return [
            'status' => 'error',
            'message' => $msg,
            'api_response' => $response,
            'api_http_code' => $http_code,
            'api_decoded' => $result
        ];
    }

    private function logEWayBill($invoice_id, $details, $payload)
    {
        $business_id = $_SESSION['business_id'];
        $sql = "INSERT INTO eway_bills (business_id, invoice_id, trans_mode, trans_distance, vehicle_no, transporter_id, request_payload, status) 
                VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')";
        $stmt = $this->connect->prepare($sql);
        if (!$stmt) {
            die("Error preparing statement (logEWayBill): " . $this->connect->error);
        }
        $payload_json = json_encode($payload);
        $stmt->bind_param("iiissss", $business_id, $invoice_id, $details['transMode'], $details['transDistance'], $details['vehicleNo'], $details['transporterId'], $payload_json);
        $stmt->execute();
        $stmt->close();
    }

    private function updateEwayBillStatus($invoice_id, $response)
    {
        $sql = "UPDATE eway_bills SET eway_bill_no = ?, eway_bill_date = ?, valid_until = ?, response_payload = ?, status = 'generated' 
                WHERE invoice_id = ? AND status = 'pending' ORDER BY id DESC LIMIT 1";
        $stmt = $this->connect->prepare($sql);
        if (!$stmt) {
            die("Error preparing statement (updateEwayBillStatus): " . $this->connect->error);
        }
        $res_json = json_encode($response);
        $stmt->bind_param("ssssi", $response['ewayBillNo'], $response['ewayBillDate'], $response['validUpto'], $res_json, $invoice_id);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Get defaults for a specific form and business
     */
    public function getFormDefaults($business_id, $form_key)
    {
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
     * Fetch GST details for a given GSTIN
     */
    public function getGstDetails($business_id, $targetGstin)
    {
        $settings = $this->getEwayBillSettings($business_id);
        if (!$settings) {
            return ['status' => 'error', 'message' => 'e-Way Bill settings not found for this business.'];
        }

        $params = [
            'email' => $settings['api_email'],
            'GSTIN' => $targetGstin
        ];

        $headers = [
            'ip_address: ' . ($settings['ip_address'] ?? $_SERVER['SERVER_ADDR']),
            'client_id: ' . $settings['client_id'],
            'client_secret: ' . $settings['client_secret'],
            'gstin: ' . $settings['gstin']
        ];

        // Using user provided URL base if needed, but standardizing on controller base
        $url = rtrim($this->api_base_url, '/') . '/' . ltrim($this->get_gstin_details_path, '/') . '?' . http_build_query($params);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $result = json_decode($response, true);

        if ($http_code === 200 && isset($result['status_cd']) && $result['status_cd'] === '1') {
            return [
                'status' => 'success',
                'data' => $result['data']
            ];
        }

        $msg = $result['status_desc'] ?? $result['message'] ?? $result['error'] ?? 'API Error (Code: ' . $http_code . ')';
        return [
            'status' => 'error',
            'message' => $msg,
            'api_response' => $response,
            'api_http_code' => $http_code,
            'api_decoded' => $result
        ];
    }
}
