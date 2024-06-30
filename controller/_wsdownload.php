<?php
// connect to the database
include ("../admin/connect.php");
include ("../admin/session.php");

// retrieve the data
$workshop_id = $_GET['workshop_id'];
$query = "SELECT users.name, users.email, users.mobile, payments.workshop_id, workshops.name AS workshop_name
          FROM payments
          INNER JOIN users ON payments.user_id = users.id
          INNER JOIN workshops ON payments.workshop_id = workshops.id
          WHERE payments.workshop_id = $workshop_id AND payments.payment_status = 1";
$result = mysqli_query($connect, $query);

// export the data as CSV
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="Workshop_enrolled.csv"');
$output = fopen('php://output', 'w');
fputcsv($output, array('Name', 'Email', 'Mobile', 'Workshop ID', 'Workshop Name'));
while ($row = mysqli_fetch_assoc($result)) {
    fputcsv($output, $row);
}
fclose($output);
mysqli_close($connect);
// echo "<script>
//         window.location.href='../workshop-view.php';
//         </script>";
?>