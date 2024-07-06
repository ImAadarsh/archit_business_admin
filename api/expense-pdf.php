<?php
require 'vendor/autoload.php';
require('vendor/fpdf/fpdf/src/Fpdf/Fpdf.php');
use setasign\Fpdi\Tcpdf\Fpdi;

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);


// require('api/vendor/fpdi2/src/autoload.php');
include '../admin/connect.php';

// Start a log file
$log = fopen("merge_receipts_log.txt", "w") or die("Unable to open log file!");

if(isset($_POST['selected_ids'])) {
    $selectedIds = explode(',', $_POST['selected_ids']);
    fwrite($log, "Selected IDs: " . implode(", ", $selectedIds) . "\n");
    
    $pdf = new Fpdi();

    foreach($selectedIds as $id) {
        $sql = "SELECT file FROM expenses WHERE id = ?";
        $stmt = $connect->prepare($sql);
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        
        if($row) {
            $filePath = $uri . $row['file'];
            fwrite($log, "File path: $filePath\n");
            
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
        } else {
            fwrite($log, "No file found for ID: $id\n");
        }
    }

    try {
        $pdf->Output('merged_receipts.pdf', 'I');
        fwrite($log, "PDF generated successfully and sent to browser\n");
    } catch (Exception $e) {
        fwrite($log, "Error generating PDF: " . $e->getMessage() . "\n");
        echo "Error generating PDF. Please check the log file.";
    }
} else {
    fwrite($log, "No receipts selected.\n");
    echo "No receipts selected.";
}

fclose($log);