<?php
// Prevent multiple inclusions
if (defined('CONNECT_LOADED')) {
    return;
}
define('CONNECT_LOADED', true);

$host = "82.25.121.184";
$user = "u262009927_invoicemate";
$password = "1@Endeavour07791";
$dbname = "u262009927_invoicemate";
$connect = mysqli_connect($host, $user, $password, $dbname);
if ($connect === false) {
    error_log('business/admin/connect.php: mysqli_connect failed — ' . mysqli_connect_error());
}

/**
 * Ping and reconnect if the remote MySQL session dropped
 * (common after long OpenAI/Gemini waits — "MySQL server has gone away").
 * Pass $connect by reference so callers keep the live handle.
 */
if (!function_exists('ensureMysqliConnection')) {
function ensureMysqliConnection(&$connect) {
    global $host, $user, $password, $dbname;

    if ($connect instanceof mysqli) {
        try {
            // Avoid mysqli_ping() — deprecated in PHP 8.4
            if (@mysqli_query($connect, 'SELECT 1')) {
                return true;
            }
        } catch (Throwable $e) {
            // fall through to reconnect
        }
        @mysqli_close($connect);
    }

    $connect = @mysqli_connect($host, $user, $password, $dbname);
    if ($connect === false) {
        error_log('ensureMysqliConnection: reconnect failed — ' . mysqli_connect_error());
        return false;
    }
    return true;
}
}

$uri = 'https://api.invoicemate.in/storage/app/';
if (!function_exists('callAPI')) {
function callAPI($method, $urlpoint, $data, $token){
    if (!isset($token)) {
        $token = "";
    }
    
    $url = 'https://api.invoicemate.in/public/api/'.$urlpoint.'';
    $curl = curl_init($url);
    switch ($method){
       case "POST":
          curl_setopt($curl, CURLOPT_POST, 1);
          if ($data)
             curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
             
          break;
       case "PUT":
          curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "PUT");
          if ($data)
             curl_setopt($curl, CURLOPT_POSTFIELDS, $data);			 					
          break;
       default:
          if ($data)
             $url = sprintf("%s?%s", $url, http_build_query($data));
    }
    
    // OPTIONS:
    curl_setopt($curl, CURLOPT_URL, $url);
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, FALSE);
    curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, FALSE);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    
    curl_setopt($curl, CURLOPT_HTTPHEADER, array(
       'Content-Type: application/json',
       $token
    ));
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, TRUE);
    curl_setopt($curl, CURLOPT_BINARYTRANSFER,TRUE);
    curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
    // EXECUTE:
    $result = curl_exec($curl);
     echo $result;
    if(!$result){echo curl_error($curl);}
    curl_close($curl);
    return $result;
 }
}
if (!function_exists('callAPI1')) {
function callAPI1($method, $urlpoint, $data, $token){
    if (!isset($token)) {
        $token = "";
    }
    
    $url = 'https://api.invoicemate.in/public/api/'.$urlpoint.'';
    $curl = curl_init($url);
    switch ($method){
       case "POST":
          curl_setopt($curl, CURLOPT_POST, 1);
          if ($data)
             curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
             
          break;
       case "PUT":
          curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "PUT");
          if ($data)
             curl_setopt($curl, CURLOPT_POSTFIELDS, $data);			 					
          break;
       default:
          if ($data)
             $url = sprintf("%s?%s", $url, http_build_query($data));
    }
    
    // OPTIONS:
    curl_setopt($curl, CURLOPT_URL, $url);
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, FALSE);
    curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, FALSE);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    
    // Don't set Content-Type for multipart form data - let cURL set it automatically
    curl_setopt($curl, CURLOPT_HTTPHEADER, array(
       $token
    ));
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, TRUE);
    curl_setopt($curl, CURLOPT_BINARYTRANSFER,TRUE);
    curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
    // EXECUTE:
    $result = curl_exec($curl);
    // Remove the echo statement for debugging
    // echo $result;
    if(!$result){echo curl_error($curl);}
    curl_close($curl);
    return $result;
 }
}

?>