<?php
// business/controller/EwayBillController.php

class EwayBillController
{
    private $connect;
    // Base URL for the 3rd party vendor API.
    private $api_base_url = "https://api.invoicemate.in/public/api/";
    private $auth_path = "ewaybillapi/v1.03/authenticate";
    private $generate_path = "ewaybillapi/v1.03/ewayapi/genewaybill";

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
            'ip_address: ' . $_SERVER['SERVER_ADDR'], // Server IP
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

        return ['status' => false, 'message' => 'Authentication failed: ' . ($result['message'] ?? 'Unknown error')];
    }

    /**
     * Map internal invoice data to e-Way bill API request format
     */
    public function prepareEwayBillData($invoice_id, $transport_details)
    {
        $sql = "SELECT i.*, b.business_name, b.gst as fromGstin, b.email, 
                       l.location_name, l.address as fromAddr1, l.state as fromState,
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
                "qtyUnit" => "NOS", // Defaulting to unit, should ideally be from product data
                "taxableAmount" => (float) ($row['price_of_all'] - ($row['igst'] ?: ($row['cgst'] + $row['dgst']))),
                "sgstRate" => $row['dgst'] > 0 ? (float) ($row['gst_rate'] / 2) : 0,
                "cgstRate" => $row['cgst'] > 0 ? (float) ($row['gst_rate'] / 2) : 0,
                "igstRate" => $row['igst'] > 0 ? (float) $row['gst_rate'] : 0,
                "cessRate" => 0
            ];
        }
        $stmt->close();

        // Map to API format (Simplified based on Postman collection)
        return [
            "supplyType" => "O",
            "subSupplyType" => "1",
            "docType" => "INV",
            "docNo" => (string) $invoice['serial_no'],
            "docDate" => date('d/m/Y', strtotime($invoice['invoice_date'])),
            "fromGstin" => $invoice['fromGstin'],
            "fromTrdName" => $invoice['business_name'],
            "fromAddr1" => $invoice['fromAddr1'],
            "fromPlace" => $invoice['location_name'],
            "fromPincode" => 110001, // Placeholder, should be from location
            "fromStateCode" => 7, // Placeholder for Delhi
            "toGstin" => $invoice['doc_no'] ?: "URP", // Using doc_no as GSTIN if customer_type is not retail
            "toTrdName" => $invoice['name'],
            "toAddr1" => $invoice['toAddr1'],
            "toPlace" => $invoice['toCity'],
            "toPincode" => (int) $invoice['toPincode'],
            "toStateCode" => 7, // Placeholder
            "transactionType" => 1,
            "totalValue" => (float) $invoice['total_amount'],
            "cgstValue" => (float) $invoice['total_cgst'],
            "sgstValue" => (float) $invoice['total_dgst'],
            "igstValue" => (float) $invoice['total_igst'],
            "totInvValue" => (float) $invoice['total_amount'],
            "transMode" => $transport_details['transMode'],
            "transDistance" => (string) $transport_details['transDistance'],
            "vehicleNo" => $transport_details['vehicleNo'],
            "transporterId" => $transport_details['transporterId'] ?? '',
            "transporterName" => $transport_details['transporterName'] ?? '',
            "itemList" => $items
        ];
    }

    /**
     * Get list of saved transporters for a business
     */
    public function getTransporters($business_id)
    {
        $sql = "SELECT * FROM transporters WHERE business_id = ? AND is_active = 1";
        $stmt = $this->connect->prepare($sql);
        if (!$stmt) {
            die("Error preparing statement (getTransporters): " . $this->connect->error);
        }
        $stmt->bind_param("i", $business_id);
        $stmt->execute();
        return $stmt->get_result();
    }

    /**
     * Generate e-Way bill by calling external API
     */
    public function generateEwayBill($invoice_id, $transport_details)
    {
        $business_id = $_SESSION['business_id'];
        $auth = $this->authenticate($business_id);

        if (!$auth['status']) {
            return $auth;
        }

        $token = $auth['token'];
        $payload = $this->prepareEwayBillData($invoice_id, $transport_details);

        if (!$payload) {
            return ['status' => false, 'message' => 'Failed to prepare e-Way Bill data. Check invoice details.'];
        }

        // Log the request locally before sending
        $this->logEWayBill($invoice_id, $transport_details, $payload);

        // Real API Call
        $settings = $this->getEwayBillSettings($business_id);
        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
            'ip_address: ' . $_SERVER['SERVER_ADDR'],
            'client_id: ' . $settings['client_id'],
            'client_secret: ' . $settings['client_secret']
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

        return [
            'status' => 'error',
            'message' => $result['message'] ?? 'API Error (Code: ' . $http_code . ')',
            'details' => $result
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
}
