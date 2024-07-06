<?php
require 'vendor/autoload.php';
require('vendor/fpdf/fpdf/src/Fpdf/Fpdf.php');
use setasign\Fpdi\Tcpdf\Fpdi;

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

include '../admin/connect.php';

// Start a log file
$log = fopen("merge_receipts_log.txt", "w") or die("Unable to open log file!");

if(isset($_GET['business_id']) && isset($_GET['location_id'])) {
    $business_id = $_GET['business_id'];
    $location_id = $_GET['location_id'];
    
    fwrite($log, "Business ID: $business_id, Location ID: $location_id\n");
    
    $pdf = new Fpdi();

    $sql = "SELECT `id`, `file` FROM `expenses` WHERE `business_id` = ? AND `location_id` = ?";
    $params = [$business_id, $location_id];
    $types = "ii";

    // Filter by day
    if(isset($_GET['day'])) {
        $sql .= " AND DATE(created_at) = ?";
        $params[] = $_GET['day'];
        $types .= "s";
    }

    // Filter by month
    if(isset($_GET['month'])) {
        $sql .= " AND MONTH(created_at) = ?";
        $params[] = $_GET['month'];
        $types .= "i";
    }

    // Filter by week
    if(isset($_GET['week_start']) && isset($_GET['week_end'])) {
        $sql .= " AND created_at BETWEEN ? AND ?";
        $params[] = $_GET['week_start'];
        $params[] = $_GET['week_end'];
        $types .= "ss";
    }

    // Filter by year
    if(isset($_GET['year'])) {
        $sql .= " AND YEAR(created_at) = ?";
        $params[] = $_GET['year'];
        $types .= "i";
    }

    // Filter by expense type (0 or 1)
    if(isset($_GET['type'])) {
        $sql .= " AND type = ?";
        $params[] = $_GET['type'];
        $types .= "i";
    }

    $stmt = $connect->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while($row = $result->fetch_assoc()) {
        $id = $row['id'];
        $filePath = $uri . $row['file'];
        fwrite($log, "Processing file for ID: $id, File path: $filePath\n");
        
        $fileType = pathinfo($filePath, PATHINFO_EXTENSION);
        fwrite($log, "File type: $fileType\n");
        
        if($fileType == 'pdf') {
            try {
                $pageCount = $pdf->setSourceFile($filePath);
                fwrite($log, "PDF page count: $pageCount\n");
                for ($pageNo = 1; $pageNo <= $pageCount; $pageNo++) {
                    $templateId = $pdf->importPage($pageNo);
                    $pdf->AddPage();
                    $pdf->useTemplate($templateId);
                }
            } catch (Exception $e) {
                fwrite($log, "Error processing PDF: " . $e->getMessage() . "\n");
            }
        } elseif(in_array($fileType, ['jpg', 'jpeg', 'png'])) {
            try {
                $pdf->AddPage();
                $pdf->Image($filePath, 10, 10, 190);
                fwrite($log, "Image added successfully\n");
            } catch (Exception $e) {
                fwrite($log, "Error processing image: " . $e->getMessage() . "\n");
            }
        } else {
            fwrite($log, "Unsupported file type: $fileType\n");
        }
    }

    try {
        // Set headers for PDF download
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="merged_expenses.pdf"');
        
        $pdf->Output('merged_expenses.pdf', 'I');
        fwrite($log, "PDF generated successfully and sent to browser\n");
    } catch (Exception $e) {
        fwrite($log, "Error generating PDF: " . $e->getMessage() . "\n");
        echo "Error generating PDF. Please check the log file.";
    }
} else {
    fwrite($log, "Missing business_id or location_id parameters.\n");
    echo "Missing business_id or location_id parameters.";
}

fclose($log);