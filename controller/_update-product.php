<?php
include '../admin/connect.php';
include '../admin/session.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Prepare data array for API call
    $data_array = array(
        "id" => $_POST['id'],
        "business_id" => $_POST['business_id'],
        "location_id" => $_POST['location_id'],
        "name" => $_POST['name'],

        "hsn_code" => $_POST['hsn_code'],
        "price" => $_POST['price'],
        "category_id" => isset($_POST['category_id']) && $_POST['category_id'] !== '' ? $_POST['category_id'] : null,
        "art_category_id" => isset($_POST['art_category_id']) && $_POST['art_category_id'] !== '' ? $_POST['art_category_id'] : null,
        "item_code" => isset($_POST['item_code']) ? $_POST['item_code'] : null,
        "height" => isset($_POST['height']) && $_POST['height'] !== '' ? $_POST['height'] : null,
        "width" => isset($_POST['width']) && $_POST['width'] !== '' ? $_POST['width'] : null,
        "orientation" => isset($_POST['orientation']) && $_POST['orientation'] !== '' ? $_POST['orientation'] : null,
        "artist_name" => isset($_POST['artist_name']) ? $_POST['artist_name'] : null,
        "quantity" => isset($_POST['quantity']) && $_POST['quantity'] !== '' ? $_POST['quantity'] : null,
        "is_framed" => isset($_POST['is_framed']) ? 1 : 0,
        "is_include_gst" => isset($_POST['is_include_gst']) ? 1 : 0,
        "is_customizable" => isset($_POST['is_customizable']) ? (int)$_POST['is_customizable'] : 0,
    );

    // Add image deletion IDs if any
    if (isset($_POST['delete_image_ids']) && is_array($_POST['delete_image_ids'])) {
        foreach ($_POST['delete_image_ids'] as $index => $image_id) {
            $data_array['delete_image_ids[' . $index . ']'] = $image_id;
        }
    }

    // Add new images to the data array
    if (!empty($_FILES['images']['name'][0])) {
        $files = $_FILES['images'];
        for ($i = 0; $i < count($files['name']); $i++) {
            if ($files['error'][$i] === UPLOAD_ERR_OK) {
                $data_array['images[' . $i . ']'] = curl_file_create(
                    $files['tmp_name'][$i], 
                    $files['type'][$i], 
                    $files['name'][$i]
                );
            }
        }
    }

    // Make API call
    $make_call = callAPI1('POST', 'products/update', $data_array, null);
    
    // Extract JSON from the response (remove debug output)
    $jsonStart = strpos($make_call, '{"status"');
    if ($jsonStart !== false) {
        $jsonResponse = substr($make_call, $jsonStart);
        $response = json_decode($jsonResponse, true);
    } else {
        $response = json_decode($make_call, true);
    }
    
    if ($response && isset($response['status']) && $response['status']) {
        $_SESSION['success'] = $response['message'] ?: "Product updated successfully!";
        header("Location: ../products.php");
        exit();
    } else {
        $_SESSION['error'] = isset($response['message']) ? $response['message'] : "Error updating product.";
        header("Location: ../edit-product.php?id=" . $_POST['id']);
        exit();
    }
} else {
    // If not POST request, redirect to products page
    header("Location: ../products.php");
    exit();
}
?> 