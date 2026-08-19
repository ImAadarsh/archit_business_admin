# GST Filing API contract (InvoiceMate / Archit)

Mobile APIs for GSTR-1 / GSTR-2B / GSTR-3B. Same public URL pattern as e-Way Bill.

## Base URL

`https://dashboard.invoicemate.in/api/gst/`

Local folder (deployed like `api/eway-bill/`):

`/Applications/XAMPP/xamppfiles/htdocs/archit_2026/business/api/gst/`

## Auth & identity

- **No PHP login session.** Pass `business_id` on every request (same as e-Way).
- Optional `location_id` filters invoices/expenses to that branch.
- Optional `user_id` is accepted and ignored for now.
- **Period** is calendar month `YYYY-MM` (Android `GstFilingApi` already uses this).
- GST portal OTP is **separate**: `auth-otp.php` then `auth-verify.php`. Required only for Perione file/sync/offset — local reads work without it.
- Perione **client_id / client_secret** stay on the server (`config.php` + optional `gst_credentials` overrides). **Never send them to Android.**

## Envelope

JSON `{ "status": "success"|"error", "message": "...", "data": { ... } }`

GET query string **or** POST JSON body are both accepted.

---

## Endpoints

### 1. Request GST OTP — `auth-otp.php`

- **Method**: POST JSON
- **Body**:
  - `business_id` (required)
  - `gstin`, `gst_username`, `email` (optional; saved on first use)
- **Success `data`**: `{ otp_sent, gstin, needs_otp }`

### 2. Verify GST OTP — `auth-verify.php`

- **Method**: POST JSON
- **Body**: `business_id`, `otp`
- **Success `data`**: `{ authenticated, token_expiry, gstin }`

### 3. Period summary — `get-period-summary.php`

Replaces the home screen’s dual `getInvoiceListReport` + `getExpenseReport` calls.

- **Method**: GET or POST
- **Params**: `business_id`, `period`, optional `location_id`
- **Success `data`**:

```json
{
  "period": "2026-07",
  "period_label": "July 2026",
  "invoice_count": 12,
  "taxable": 100000.0,
  "sales_gst": 18000.0,
  "itc_claimed": 5000.0,
  "itc_eligible": 3200.0,
  "itc_pending": 1800.0,
  "itc_carry": 1800.0,
  "net_cash": 14800.0,
  "carry_forward": 1800.0,
  "counts": { "all": 8, "pending": 3, "approved": 5 },
  "pending_mini": [],
  "gstr1_status": "ready",
  "gstr2b_status": "synced",
  "gstr3b_status": "pending",
  "last_synced_at": "2026-08-18 12:00:00",
  "filing_unlocked": false,
  "auth_required": true
}
```

### 4. GSTR-1 list — `get-gstr1.php`

Built from completed **normal** sales invoices in the period.

- **Params**: `business_id`, `period`, optional `location_id`
- **`data.invoices[]`** (Android `GstSalesInvoiceRow` aliases included):

| Field | Notes |
|---|---|
| `id` / `invoice_id` | Invoice PK |
| `name` | Customer |
| `invoice_date` / `invoiceDate` | `YYYY-MM-DD` |
| `type` | `normal` |
| `taxable` / `amount_wgst` | Taxable value |
| `gst` / `tgst` | CGST+SGST+IGST |
| `total` / `total_amount` | Invoice total |
| `supply_type` | `B2B` / `B2CS` / `B2CL` / `EXP` |
| `invoice_type` | `B2B` / `B2CL` / `B2CS` / `EXP` / `CDNR` / `CDNUR` |
| `customer_gstin` | From `doc_no` when GSTIN |
| `place_of_supply` | 2-digit state code |
| `cgst` / `sgst` / `igst` / `cess` | Tax split |
| `included` | `false` if excluded from this return (`gst_gstr1_exclusions`) |
| `items[]` | Line items (product, HSN, qty, taxable, tax) |

Also returns `payload` (GSTN save JSON), `hsn`, `docs_issued`, `sections`, `auth_required`.

### 5. Prepare GSTR-1 — `prepare-gstr1.php`

Snapshots rows into `gst_gstr1_invoices` and builds GSTN JSON (`b2b` / `b2cs` / `b2cl` / `cdnr` / `cdnur` / `exp` / `hsn` / `doc_issue`). Excluded invoices are omitted from the payload.

- **Body**: `business_id`, `period`, optional `location_id`, `push_portal` (bool, default false)
- Set `push_portal: true` to PUT Perione `/gstr1/save` (needs valid GST session).
- Portal validation failures return `data.errors[]` (`code`, `message`).

### 6. File GSTR-1 — `file-gstr1.php`

- **Body**: `business_id`, `period`
- First call **without** `otp` → EVC OTP (`data.needs_otp = true`)
- Second call **with** `otp` → Perione `/gstr1/retevcfile`

### 7. Sync GSTR-2B — `sync-gstr2b.php`

Loads local expenses with GST, optionally GET Perione `/gstr2b/all`, auto-matches by vendor GSTIN + amount/date. Unmatched portal invoices become `ghost` rows.

- **Body**: `business_id`, `period`, optional `location_id`

### 8. GSTR-2B list — `get-gstr2b.php`

- **Params**: `business_id`, `period`, optional `location_id`, `status` (`all`\|`pending`\|`matched`\|`carry`\|`ghost`)
- **`data.invoices[]`** (Android `GstItcRow`):

| Field | Notes |
|---|---|
| `id` | Expense id or `ghost_…` |
| `name`, `paid_to` / `paidTo` | |
| `gstin` | Vendor GSTIN |
| `date` | Expense / portal date |
| `taxable`, `amount` | |
| `gst_claimed` / `gstClaimed` | ITC amount |
| `status` | `pending` \| `matched` \| `carry` \| `ghost` |

### 9. Reconcile ITC — `reconcile-itc.php`

Persists handshake (replaces `GstFilingPrefs` local map).

- **Single**: `{ business_id, period, expense_id, status }`
- **Bulk list**: `{ business_id, period, items: [{ "id": "12", "status": "matched" }] }`
- **Bulk actions**:
  - `bulk_action: "approve_under"` + optional `max_gst` (default 5000)
  - `bulk_action: "carry_pending"`

### 10. GSTR-3B compute — `get-gstr3b.php`

Output tax from sales minus **matched** ITC. Writes `gst_gstr3b_summary`.

- **`data`**: `output_gst`, `itc_eligible`, `itc_pending`, `itc_carry`, `net_total`, `cash_to_pay`, split CGST/SGST/IGST

### 11. Offset liability — `offset-liability.php`

- **Body**: `business_id`, `period`, optional `local_only` (skip Perione), `use_itc`
- Calls Perione PUT `/gstr3b/offset` when session is valid. `ecodtls` always sent (zeros if none).

### 12. File GSTR-3B — `file-gstr3b.php`

Same OTP two-step as GSTR-1 (`form_type` R3B).

### 13. Carry-forward — `get-carry-forward.php`

Pending + carry ITC for the period (`GstCarryForwardActivity`).

### 14. Return status — `get-return-status.php`

Local `gst_return_periods` plus Perione `/all/newretstatus` when authenticated.

- **Params**: `business_id`, `period`, optional `ref_id`

### 15. Toggle GSTR-1 invoice — `toggle-gstr1-invoice.php`

Include / exclude a sales invoice from this period’s return. Stored in `gst_gstr1_exclusions` so it survives `prepare-gstr1` snapshot wipes.

- **Body**: `business_id`, `period`, `invoice_id`, `included` (bool). Optional `reason`.

### 16. GSTR-1 line items — `get-gstr1-items.php`

- **Params**: `business_id`, `invoice_id`
- **`data.items[]`**: product, HSN, qty, taxable, CGST/SGST/IGST, total

### 17. Export GSTR-1 — `export-gstr1.php`

- **Params**: `business_id`, `period`, optional `location_id`, `format` (`json`\|`csv`)
- CSV download when `format=csv`.

---

## Android wiring

Point GST calls at the dashboard host (same as `EwayBillApi.BASE`), **not** `api.invoicemate.in/public`.

```java
public static final String GST_BASE = "https://dashboard.invoicemate.in/api/gst/";

// Home KPIs
GET GST_BASE + "get-period-summary.php?business_id=" + id + "&period=" + period
    + (locationId != null ? "&location_id=" + locationId : "");

// GSTR-1
GET .../get-gstr1.php?business_id=&period=&location_id=
POST .../prepare-gstr1.php   JSON {business_id, period, push_portal:false}

// GSTR-2B
GET  .../get-gstr2b.php?business_id=&period=
POST .../sync-gstr2b.php     JSON {business_id, period}
POST .../reconcile-itc.php   JSON {business_id, period, expense_id, status:"matched"}

// Compute / file
GET  .../get-gstr3b.php?business_id=&period=
POST .../offset-liability.php JSON {business_id, period}
POST .../file-gstr3b.php      JSON {business_id, period}          // then again with otp
```

Parse `status === "success"` then read `data`. On `data.needs_otp` prompt the user and retry with `otp`. On `data.auth_required` run auth-otp → auth-verify before portal actions.

Suggested Volley: copy `EwayBillApi` (JSON POST + `apiFailureMessage`) rather than the current `GstFilingApi.get()` StringRequest against Laravel.

---

## Data sources

| GST piece | Source |
|---|---|
| GSTR-1 outward | `invoices` (`is_completed=1`, type normal) + tax columns |
| ITC / 2B local | `expenses` (`gst_amount`, `gst_number`, `taxable_amount`, `paid_to`, `expense_date`) |
| GSTIN / email seed | `businessses.gst` + `eway_bill_settings` |
| Portal file/sync | Perione `https://api.perione.in` |

## Tables

`gst_credentials`, `gst_return_periods`, `gst_gstr1_invoices`, `gst_gstr1_exclusions`, `gst_gstr2b_invoices`, `gst_itc_reconcile`, `gst_gstr3b_summary`, `gst_carry_forward`, `gst_api_logs`

Apply `gst_filing_tables.sql` (also auto-created on first API hit if missing).
