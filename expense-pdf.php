<?php
require 'vendor/autoload.php'; // Include autoload file for TCPDF and FPDI
include 'admin/connect.php' ;

use setasign\Fpdi\Tcpdf\Fpdi;

if (isset($_POST['selected_rows'])) {
    $selectedRows = explode(',', $_POST['selected_rows']);
    $business_id = $_SESSION['business_id'];

    // Connect to the database


    if ($connect->connect_error) {
        die("Connection failed: " . $connect->connect_error);
    }

    $sql = "SELECT file FROM expenses WHERE id IN (" . implode(',', $selectedRows) . ") AND business_id = '$business_id'";
    $results = $connect->query($sql);

    if ($results->num_rows > 0) {
        $pdf = new Fpdi();
        
        foreach ($results as $row) {
            $file = $row['file'];
            $pageCount = $pdf->setSourceFile($file);
            for ($i = 1; $i <= $pageCount; $i++) {
                $tplIdx = $pdf->importPage($i);
                $pdf->AddPage();
                $pdf->useTemplate($tplIdx);
            }
        }

        $outputFileName = 'selected_expenses.pdf';
        $pdf->Output($outputFileName, 'D');
    } else {
        echo "No files found for the selected expenses.";
    }

    $connect->close();
} else {
    echo "No rows selected.";
}
?>
