# e-Way Bill Mobile API Documentation

This document provides technical details for integrating the e-Way Bill generation suite into the mobile application.

## Base URL
`https://dashboard.invoicemate.in/api/eway-bill/`

## Authentication & Security
- **No Session Required**: These APIs do not require a PHP login session.
- **Business Identity**: You must pass `business_id` in every request. 
- **Ownership Check**: For invoice-related calls, the system verifies that the provided `business_id` owns the `invoice_id`.

---

## 1. Get GST Details
Fetch legal name and address for any GSTIN.

- **Endpoint**: `get-gst-details.php`
- **Method**: `GET`
- **Parameters**:
  - `gstin` (Required): 15-digit GSTIN.
  - `business_id` (Required): Your business ID.

- **Success Response**:
```json
{
  "status": "success",
  "data": {
    "gstin": "...",
    "tradeName": "Company Name",
    "addr1": "Address Line 1",
    "place": "City",
    "pincode": 123456,
    "stateCode": 7,
    "state": "DELHI"
  }
}
```

---

## 2. Get Invoice Defaults & Master Codes
Use this to pre-fill the e-Way Bill generation form on the app. It returns the invoice data, business-specific settings, and dropdown options (Master Codes).

- **Endpoint**: `get-invoice-defaults.php`
- **Method**: `GET`
- **Parameters**:
  - `invoice_id` (Required): The ID of the invoice.
  - `business_id` (Required): Your business ID.

- **Sample Response**:
```json
{
  "status": "success",
  "invoice_data": {
    "supplyType": "O",
    "subSupplyType": "1",
    "subSupplyDesc": " ",
    "docType": "INV",
    "docNo": "11462",
    "docDate": "07/02/2026",
    "fromGstin": "07AADPA2039E1ZF",
    "fromTrdName": "Archit Art",
    "fromAddr1": "Shop No: 28 Kirti Nagar Furniture Block",
    "fromAddr2": "Kirti Nagar, New Delhi – 110015",
    "fromPlace": "AAG Kirti Nagar",
    "fromPincode": 110001,
    "fromStateCode": 7,
    "actFromStateCode": 7,
    "toGstin": "URP",
    "toTrdName": "Vikram Jaiswal",
    "toAddr1": "",
    "toAddr2": "",
    "toPlace": "",
    "toPincode": 110001,
    "toStateCode": 7,
    "actToStateCode": 7,
    "transactionType": 1,
    "totalValue": 10800,
    "cgstValue": 270,
    "sgstValue": 270,
    "igstValue": 0,
    "cessValue": 0,
    "cessNonAdvolValue": 0,
    "totInvValue": 11340,
    "transMode": "1",
    "transDistance": "0",
    "vehicleNo": "",
    "vehicleType": "R",
    "transporterId": "",
    "transporterName": "",
    "transDocNo": "",
    "transDocDate": "",
    "dispatchFromGSTIN": "",
    "dispatchFromTradeName": "",
    "shipToGSTIN": "",
    "shipToTradeName": "",
    "itemList": [
      {
        "productName": "Handmade Painting",
        "productDesc": "Handmade Painting",
        "hsnCode": 9701,
        "quantity": 6,
        "qtyUnit": "NOS",
        "taxableAmount": 10800,
        "sgstRate": 2.5,
        "cgstRate": 2.5,
        "igstRate": 0,
        "cessRate": 0
      }
    ]
  },
  "business_defaults": {
    "supplyType": "O",
    "transMode": "1"
  },
  "master_codes": {
    "supplyType": { ... },
    "subSupplyType": { ... },
    "docType": { ... },
    "transMode": { ... },
    "vehicleType": { ... },
    "transactionType": { ... },
    "stateCodes": { ... }
  }
}
```

> [!TIP]
> **GST Fetch for Ship-to**: The same `get-gst-details.php` endpoint can be used to fetch details for the `shipToGSTIN` field. Just pass the GSTIN to the API and use the returned `tradeName` and address for the ship-to section.

---

## 3. Generate e-Way Bill
Perform the final generation call.

- **Endpoint**: `generate-eway-bill.php`
- **Method**: `POST`
- **Format**: JSON (preferred) or Form-Data.
- **Body Parameters**:
  - `business_id` (Required)
  - `invoice_id` (Required)
  - `supplyType`, `subSupplyType`, `docType`, `docNo`, `docDate`, etc. (Full e-Way Bill payload)

- **Success Response**:
```json
{
  "status": "success",
  "ewayBillNo": 123456789012,
  "ewayBillDate": "09/02/2026 01:20:00 PM",
  "message": "e-Way Bill generated successfully."
}
```

- **Error Response**:
```json
{
  "status": "error",
  "message": "Detailed error description from API"
}
```

---

## Error Codes
| Code | Meaning |
|---|---|
| 401 | Missing or invalid `business_id` |
| 403 | `business_id` does not own the `invoice_id` |
| 400 | Missing required parameters (e.g. `gstin`) |
