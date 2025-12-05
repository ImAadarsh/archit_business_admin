<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('memory_limit', '512M');
set_time_limit(300);

// Include database connection
require_once('admin/connect.php');

// Configuration
$GEMINI_API_KEY = $_GET['GEMINI_API_KEY'];
if (!$GEMINI_API_KEY) {
    die("GEMINI_API_KEY is required");
}

// Helper function to create directory with proper permissions
function createWritableDir($path) {
    $parentDir = dirname($path);
    
    // Check if parent is writable
    if (!is_writable($parentDir) && file_exists($parentDir)) {
        return false;
    }
    
    // Create parent directories if needed
    if (!file_exists($parentDir)) {
        $oldUmask = umask(0);
        $result = @mkdir($parentDir, 0777, true);
        umask($oldUmask);
        if (!$result) {
            return false;
        }
        // Try to set permissions explicitly
        @chmod($parentDir, 0777);
    }
    
    // Create the final directory
    if (!file_exists($path)) {
        $oldUmask = umask(0);
        $result = @mkdir($path, 0777, true);
        umask($oldUmask);
        if (!$result) {
            return false;
        }
        // Try to set permissions explicitly
        @chmod($path, 0777);
    }
    
    // Verify it's writable
    return is_writable($path);
}

// Try multiple temp directory locations (project directory preferred)
$tempDirOptions = [
    __DIR__ . '/temp/just_product/',  // Project directory (preferred)
    __DIR__ . '/tmp/just_product/',   // Alternative project location
    __DIR__ . '/just_product_temp/',  // Direct in business folder
];

$TEMP_DIR = null;
foreach ($tempDirOptions as $tempOption) {
    if (createWritableDir($tempOption)) {
        $TEMP_DIR = $tempOption;
        break;
    }
}

// Final check - if still no directory, die with helpful message
if (!$TEMP_DIR) {
    $error = error_get_last();
    $currentUser = function_exists('posix_getpwuid') && function_exists('posix_geteuid') 
        ? posix_getpwuid(posix_geteuid())['name'] 
        : 'unknown';
    
    die("FATAL ERROR: Cannot create or write to temp directory.\n" .
        "Current user: {$currentUser}\n" .
        "Tried locations:\n" .
        "  - " . __DIR__ . "/temp/just_product/\n" .
        "  - " . __DIR__ . "/tmp/just_product/\n" .
        "  - " . __DIR__ . "/just_product_temp/\n" .
        "Error: " . ($error ? $error['message'] : 'Unknown error') . "\n\n" .
        "SOLUTION: Please run this command in terminal:\n" .
        "  mkdir -p " . __DIR__ . "/temp/just_product\n" .
        "  chmod -R 777 " . __DIR__ . "/temp\n");
}

// Set log file location (same directory as temp)
$LOG_FILE = dirname($TEMP_DIR) . '/just_product_generations_log.txt';

// Final verification
if (!is_writable($TEMP_DIR)) {
    // Try one more time to fix permissions
    @chmod($TEMP_DIR, 0777);
    @chmod(dirname($TEMP_DIR), 0777);
    
    if (!is_writable($TEMP_DIR)) {
        die("FATAL ERROR: Temp directory is not writable: {$TEMP_DIR}\n" .
            "Permissions: " . substr(sprintf('%o', fileperms($TEMP_DIR)), -4) . "\n" .
            "Parent permissions: " . substr(sprintf('%o', fileperms(dirname($TEMP_DIR))), -4) . "\n\n" .
            "SOLUTION: Run this command:\n" .
            "  chmod -R 777 " . dirname($TEMP_DIR) . "\n");
    }
}

// Function to log messages
function logMessage($message) {
    global $LOG_FILE;
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[{$timestamp}] {$message}\n";
    
    // Try to write to log file, but don't fail if we can't
    @file_put_contents($LOG_FILE, $logEntry, FILE_APPEND);
    
    // Always output to browser/console
    echo $logEntry;
}

// Function to download image
function downloadImage($imageUrl, $destinationPath) {
    // Ensure directory exists
    $dir = dirname($destinationPath);
    if (!file_exists($dir)) {
        $mkdirResult = @mkdir($dir, 0777, true);
        if (!$mkdirResult) {
            $error = error_get_last();
            logMessage("ERROR: Failed to create directory: {$dir}");
            logMessage("ERROR: Directory error: " . ($error ? $error['message'] : 'Unknown error'));
            return false;
        }
        logMessage("  Created directory: {$dir}");
    }
    
    // Check if directory is writable
    if (!is_writable($dir)) {
        logMessage("ERROR: Directory is not writable: {$dir}");
        logMessage("ERROR: Directory permissions: " . substr(sprintf('%o', fileperms($dir)), -4));
        return false;
    }
    
    logMessage("  Downloading from: {$imageUrl}");
    logMessage("  Saving to: {$destinationPath}");
    
    $ch = curl_init($imageUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $imageData = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($httpCode === 200 && $imageData && strlen($imageData) > 0) {
        logMessage("  Downloaded " . strlen($imageData) . " bytes");
        
        // Try to write the file
        $result = file_put_contents($destinationPath, $imageData);
        if ($result !== false) {
            logMessage("  Successfully wrote {$result} bytes to file");
            return true;
        }
        
        // Get detailed error information
        $error = error_get_last();
        logMessage("ERROR: Failed to write image to: {$destinationPath}");
        logMessage("ERROR: Write error: " . ($error ? $error['message'] : 'Unknown error'));
        logMessage("ERROR: File exists: " . (file_exists($destinationPath) ? 'Yes' : 'No'));
        logMessage("ERROR: Directory writable: " . (is_writable($dir) ? 'Yes' : 'No'));
        logMessage("ERROR: Disk free space: " . (disk_free_space($dir) !== false ? number_format(disk_free_space($dir)) . ' bytes' : 'Unknown'));
        return false;
    }
    
    logMessage("ERROR: Failed to download image. HTTP Code: {$httpCode}, Error: {$error}");
    if ($httpCode !== 200) {
        logMessage("ERROR: Response data: " . substr($imageData, 0, 200));
    }
    return false;
}

// Resize image before sending to AI to reduce payload/cost
function resizeImageForAI($sourcePath, $maxDim = 1024) {
    if (!file_exists($sourcePath)) {
        logMessage("  ERROR: resizeImageForAI missing file {$sourcePath}");
        return false;
    }

    $imageInfo = @getimagesize($sourcePath);
    if (!$imageInfo) {
        logMessage("  ERROR: Unable to read image info for {$sourcePath}");
        return false;
    }

    list($width, $height, $type) = $imageInfo;

    if ($width <= $maxDim && $height <= $maxDim) {
        return file_get_contents($sourcePath);
    }

    switch ($type) {
        case IMAGETYPE_JPEG:
            $src = imagecreatefromjpeg($sourcePath);
            break;
        case IMAGETYPE_PNG:
            if (!function_exists('imagecreatefrompng')) {
                logMessage("  ERROR: PNG support missing in GD");
                return false;
            }
            $src = imagecreatefrompng($sourcePath);
            break;
        case IMAGETYPE_WEBP:
            if (!function_exists('imagecreatefromwebp')) {
                logMessage("  ERROR: WEBP support missing in GD");
                return false;
            }
            $src = imagecreatefromwebp($sourcePath);
            break;
        default:
            logMessage("  WARNING: Unsupported image type ({$type}), using original data");
            return file_get_contents($sourcePath);
    }

    if (!$src) {
        logMessage("  ERROR: Failed to create GD resource for {$sourcePath}");
        return false;
    }

    $ratio = $width / $height;
    if ($ratio > 1) {
        $newWidth = $maxDim;
        $newHeight = (int) round($maxDim / $ratio);
    } else {
        $newWidth = (int) round($maxDim * $ratio);
        $newHeight = $maxDim;
    }

    $dst = imagecreatetruecolor($newWidth, $newHeight);
    if ($type === IMAGETYPE_PNG || $type === IMAGETYPE_WEBP) {
        $white = imagecolorallocate($dst, 255, 255, 255);
        imagefill($dst, 0, 0, $white);
    }

    imagecopyresampled($dst, $src, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

    ob_start();
    imagejpeg($dst, null, 85);
    $data = ob_get_clean();

    imagedestroy($src);
    imagedestroy($dst);

    return $data;
}

// Function to generate clean product image using Gemini
function generateJustProductImage($apiKey, $artworkPath, $outputPath, $productInfo, $artworkData = null) {
    if ($artworkData === null) {
        $artworkData = file_get_contents($artworkPath);
    }

    if (!$artworkData) {
        logMessage("  ERROR: Could not load artwork data");
        return false;
    }
    
    $imageBase64 = base64_encode($artworkData);
    
    // Get dimensions
    $dimensions = "";
    if (!empty($productInfo['width']) && !empty($productInfo['height'])) {
        $dimensions = "The artwork dimensions are {$productInfo['width']} x {$productInfo['height']}.";
    }
    
    // Check if framed
    $isFramed = (isset($productInfo['is_framed']) && $productInfo['is_framed'] == 1);
    $frameInfo = $isFramed 
        ? "This artwork HAS A FRAME. You must keep the frame intact and visible in the output. Remove only the background behind the frame. IMPORTANT: Remove any black corners or shadows from the frame edges." 
        : "This artwork is FRAMELESS (canvas or unframed print). Remove the background but keep the artwork edges clean.";
    
    // Check orientation
    $orientation = $productInfo['orientation'] ?? 'horizontal';
    $orientationInfo = strtoupper($orientation);
    
    // Build comprehensive prompt for clean product image
    $prompt = "Create a clean, professional product image of this artwork by removing the background. {$dimensions} {$frameInfo}

CRITICAL REQUIREMENTS:
1. ORIENTATION: The artwork is {$orientationInfo}. DO NOT change, rotate, or flip the orientation under any circumstances.
2. FRAME: " . ($isFramed ? "This artwork HAS A FRAME - keep the entire frame visible and intact. CRITICAL: Remove any black corners, shadows, or dark artifacts from the frame edges. The frame should have clean, crisp edges against the white background. Only remove the background behind the framed artwork." : "This artwork is FRAMELESS - remove the background but preserve the artwork edges exactly as they are.") . "
3. BLACK CORNERS: " . ($isFramed ? "If the frame has any black corners or dark shadows at the edges, remove them completely. The frame edges must be clean and sharp." : "Remove any dark corners or shadows from the artwork edges.") . "
4. BACKGROUND: Remove all background elements. Replace with a clean pure white background (RGB 255,255,255).
5. NO MOCKUP: Do NOT place the artwork in any room, wall, or scene. This is just a product shot.
6. NO MODIFICATIONS: Do not crop, resize, add effects, or modify the artwork itself in any way. Only remove the background and clean up the edges.
7. CENTERING: Center the artwork in the frame with appropriate padding.
8. QUALITY: Maintain the highest quality and color accuracy of the original artwork.

The output should look like a professional e-commerce product photo - just the artwork (with frame if applicable) on a clean pure white background with no shadows, black corners, or artifacts, ready for an online store listing.";
    
    // Call Gemini Image Generation API
    $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash-image:generateContent?key=" . $apiKey;
    
    $requestBody = [
        'contents' => [
            [
                'parts' => [
                    [
                        'inline_data' => [
                            'mime_type' => 'image/jpeg',
                            'data' => $imageBase64
                        ]
                    ],
                    [
                        'text' => $prompt
                    ]
                ]
            ]
        ],
        'generationConfig' => [
            'temperature' => 0.2,  // Lower temperature for more consistent output
            'topK' => 40,
            'topP' => 0.95,
            'maxOutputTokens' => 8192,
            'responseModalities' => ['Image']
        ]
    ];
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($requestBody));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    
    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        logMessage("  ERROR: Gemini API returned HTTP {$httpCode}");
        if (!empty($curlError)) {
            logMessage("  CURL Error: {$curlError}");
        }
        
        // Log full response for debugging
        if ($result) {
            $errorResponse = json_decode($result, true);
            if ($errorResponse) {
                logMessage("  ===== API Error Response =====");
                logMessage("  " . json_encode($errorResponse, JSON_PRETTY_PRINT));
                logMessage("  ===== END Error Response =====");
            } else {
                logMessage("  Raw Response (first 500 chars): " . substr($result, 0, 500));
            }
        }
        
        return false;
    }
    
    // Parse response and extract image
    $response = json_decode($result, true);
    
    if (!isset($response['candidates'][0]['content']['parts'][0]['inlineData']['data'])) {
        logMessage("  ERROR: No image data in API response");
        logMessage("  Response structure: " . json_encode(array_keys($response), JSON_PRETTY_PRINT));
        return false;
    }
    
    // Decode and save the generated image
    $generatedImageData = base64_decode($response['candidates'][0]['content']['parts'][0]['inlineData']['data']);
    
    if (file_put_contents($outputPath, $generatedImageData)) {
        logMessage("  ✓ Clean product image generated successfully by Gemini AI");
        return true;
    } else {
        logMessage("  ERROR: Failed to save generated image");
        return false;
    }
}

// Function to upload image using the API
function uploadJustProductImage($productId, $imagePath, $productInfo, $token = '') {
    if (!file_exists($imagePath)) {
        return ['success' => false, 'error' => 'Image file not found'];
    }
    
    // Use the correct API endpoint
    $apiUrl = 'https://api.invoicemate.in/public/api/products/images/add';
    
    // Create CURLFile for the image
    $cFile = new CURLFile($imagePath, 'image/jpeg', basename($imagePath));
    
    // Prepare POST data - key must match what the API expects
    $postData = [
        'product_id' => $productId,
        'images[]' => $cFile,
        'name' => $productInfo['name'] ?? '',
        'description' => $productInfo['description'] ?? '',
        'suitable_for' => $productInfo['suitable_for'] ?? '',
        'just_product' => 1,  // Mark as just_product image
    ];
    
    $ch = curl_init($apiUrl);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    // Add authorization header if token provided
    if (!empty($token)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $token]);
    }
    
    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    logMessage("  → API Response (HTTP {$httpCode}): " . substr($result, 0, 200));
    
    if ($httpCode === 200 || $httpCode === 201) {
        $response = json_decode($result, true);
        if ($response && isset($response['status']) && $response['status'] === true) {
            logMessage("  → ✓ Uploaded successfully via API!");
            return ['success' => true, 'response' => $response];
        }
    }
    
    // If API fails, log the error
    if (!empty($curlError)) {
        logMessage("  → CURL Error: {$curlError}");
    }
    
    logMessage("  → ✗ API upload failed (HTTP {$httpCode})");
    return ['success' => false, 'error' => 'API upload failed', 'httpCode' => $httpCode, 'response' => substr($result, 0, 500)];
}

// ==================== MAIN EXECUTION ====================

// Output as HTML for better readability in browser
echo "<!DOCTYPE html><html><head><title>Just Product Image Generator</title>";
echo "<style>body{font-family:monospace;padding:20px;background:#f5f5f5;}";
echo ".success{color:green;}.error{color:red;}.info{color:blue;}</style></head><body>";
echo "<h2>Just Product Image Generator</h2>";
echo "<pre>";

logMessage("=== Just Product Image Generator Started ===");
logMessage("Temp Directory: " . $TEMP_DIR);
logMessage("Log File: " . $LOG_FILE);
logMessage("");

// Step 0: Check how many products remain to process
$countQuery = "SELECT COUNT(DISTINCT p.id) as total FROM products p 
               INNER JOIN product_images pi ON p.id = pi.product_id 
               WHERE p.is_temp = 0 
               AND p.just_product_processed = 0 
               AND NOT EXISTS (
                   SELECT 1 FROM product_images pi2 
                   WHERE pi2.product_id = p.id 
                   AND pi2.just_product = 1
               )";
$countResult = mysqli_query($connect, $countQuery);
if ($countResult) {
    $countRow = mysqli_fetch_assoc($countResult);
    $remainingProducts = $countRow['total'];
    logMessage("Products remaining to process: {$remainingProducts}");
    logMessage("");
}

// Step 1: Get one unprocessed product (must have images, but NOT already have a just_product image)
$query = "SELECT p.* FROM products p 
          INNER JOIN product_images pi ON p.id = pi.product_id 
          WHERE p.is_temp = 0 
          AND p.just_product_processed = 0 
          AND NOT EXISTS (
              SELECT 1 FROM product_images pi2 
              WHERE pi2.product_id = p.id 
              AND pi2.just_product = 1
          )
          GROUP BY p.id 
          LIMIT 1";
$result = mysqli_query($connect, $query);

if (!$result || mysqli_num_rows($result) === 0) {
    logMessage("No unprocessed products found with images.");
    echo "</pre>";
    echo "<p class='info'>No more products to process.</p>";
    echo "</body></html>";
    exit;
}

$product = mysqli_fetch_assoc($result);
logMessage("Processing Product ID: {$product['id']}");
logMessage("Product Name: {$product['name']}");
logMessage("Orientation: " . ($product['orientation'] ?? 'Not specified'));
logMessage("Framed: " . ($product['is_framed'] == 1 ? 'Yes' : 'No'));
logMessage("");

// Step 2: Get the first NON-MOCKUP image for this product (to use the original artwork, not a mockup)
$imageQuery = "SELECT * FROM product_images 
               WHERE product_id = ? 
               AND (is_mockup = 0 OR is_mockup IS NULL)
               AND (just_product = 0 OR just_product IS NULL)
               ORDER BY id ASC 
               LIMIT 1";
$stmt = mysqli_prepare($connect, $imageQuery);

if ($stmt === false) {
    logMessage("ERROR: Failed to prepare image query: " . mysqli_error($connect));
    exit;
}

mysqli_stmt_bind_param($stmt, "i", $product['id']);
mysqli_stmt_execute($stmt);
$imageResult = mysqli_stmt_get_result($stmt);

if (!$imageResult || mysqli_num_rows($imageResult) === 0) {
    logMessage("ERROR: No images found for this product. Skipping...");
    mysqli_stmt_close($stmt);
    exit;
}

$productImage = mysqli_fetch_assoc($imageResult);
mysqli_stmt_close($stmt);

// Construct full image URL
$imageUrl = $uri . $productImage['image'];
logMessage("Product Image URL: {$imageUrl}");
logMessage("");

// Step 2.5: Check if image URL exists before downloading
logMessage("Validating image URL...");
$ch = curl_init($imageUrl);
curl_setopt($ch, CURLOPT_NOBODY, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200) {
    logMessage("ERROR: Image URL is not accessible (HTTP {$httpCode})");
    logMessage("ERROR: Image does not exist at: {$imageUrl}");
    logMessage("SKIPPING: This product will be skipped. Please check the image URL or upload a valid image.");
    logMessage("");
    
    // Mark this product as processed with a note so it doesn't keep trying
    $updateQuery = "UPDATE products SET just_product_processed = 1, updated_at = NOW() WHERE id = ?";
    $stmt = mysqli_prepare($connect, $updateQuery);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "i", $product['id']);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        logMessage("✓ Product marked as processed (skipped due to missing image)");
    }
    
    mysqli_close($connect);
    
    echo "</pre>";
    echo "<hr>";
    echo "<p class='error'><strong>Image not found (404)</strong></p>";
    echo "<p>Product ID {$product['id']} has been skipped because the image URL is not accessible.</p>";
    echo "<p>Image URL: <code>{$imageUrl}</code></p>";
    echo "<p><a href='generate_just_product_images.php?GEMINI_API_KEY={$GEMINI_API_KEY}'>Process Next Product</a></p>";
    echo "</body></html>";
    exit;
}

logMessage("✓ Image URL is valid and accessible");
logMessage("");

// Step 3: Download the original artwork
$originalImagePath = $TEMP_DIR . 'original_' . $product['id'] . '.jpg';
logMessage("Downloading artwork...");

if (!downloadImage($imageUrl, $originalImagePath)) {
    logMessage("ERROR: Failed to download image from: {$imageUrl}");
    logMessage("SKIPPING: This product will be marked as processed to avoid retrying.");
    
    // Mark as processed to avoid infinite retries
    $updateQuery = "UPDATE products SET just_product_processed = 1, updated_at = NOW() WHERE id = ?";
    $stmt = mysqli_prepare($connect, $updateQuery);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "i", $product['id']);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        logMessage("✓ Product marked as processed (skipped due to download failure)");
    }
    
    mysqli_close($connect);
    
    echo "</pre>";
    echo "<hr>";
    echo "<p class='error'><strong>Download failed</strong></p>";
    echo "<p>Product ID {$product['id']} has been skipped due to download failure.</p>";
    echo "<p><a href='generate_just_product_images.php?GEMINI_API_KEY={$GEMINI_API_KEY}'>Process Next Product</a></p>";
    echo "</body></html>";
    exit;
}

logMessage("Artwork downloaded successfully to: {$originalImagePath}");
logMessage("");

// Step 4: Resize artwork for AI
logMessage("Optimizing artwork for AI payload...");
$optimizedArtworkData = resizeImageForAI($originalImagePath, 1024);
if (!$optimizedArtworkData) {
    logMessage("ERROR: Failed to optimize artwork for AI usage");
    exit;
}
logMessage("Artwork optimized (max dimension 1024px) for AI request.");
logMessage("");

// Step 5: Prepare product info
$productInfo = [
    'name' => $product['name'],
    'description' => $product['description'] ?? '',
    'suitable_for' => $product['suitable_for'] ?? 'home, office',
    'width' => $product['width'] ?? null,
    'height' => $product['height'] ?? null,
    'orientation' => $product['orientation'] ?? 'horizontal',
    'is_framed' => $product['is_framed'] ?? 0,
    'artist_name' => $product['artist_name'] ?? null,
];

logMessage("Product Info:");
logMessage("  - Name: {$productInfo['name']}");
logMessage("  - Dimensions: " . ($productInfo['width'] ? "{$productInfo['width']} x {$productInfo['height']}" : "Not specified"));
logMessage("  - Orientation: {$productInfo['orientation']}");
logMessage("  - Framed: " . ($productInfo['is_framed'] == 1 ? "Yes" : "No"));
logMessage("");

// Step 6: Generate just product image
$justProductPath = $TEMP_DIR . "just_product_{$product['id']}_" . time() . ".jpg";
logMessage("Generating clean product image (background removed)...");
$startTime = time();

$success = generateJustProductImage($GEMINI_API_KEY, $originalImagePath, $justProductPath, $productInfo, $optimizedArtworkData);

$elapsedTime = time() - $startTime;

if (!$success) {
    logMessage("✗ Failed to generate clean product image");
    logMessage("ERROR: Image generation failed after {$elapsedTime} seconds");
    
    // Clean up
    if (file_exists($originalImagePath)) {
        @unlink($originalImagePath);
    }
    
    echo "</pre>";
    echo "<p class='error'>Failed to generate image. See log above for details.</p>";
    echo "<p><a href='generate_just_product_images.php?GEMINI_API_KEY={$GEMINI_API_KEY}'>Try Next Product</a></p>";
    echo "</body></html>";
    exit;
}

logMessage("✓ Clean product image generated in {$elapsedTime} seconds!");
logMessage("");

// Step 7: Upload image to product
logMessage("Uploading clean product image...");

$uploadResult = uploadJustProductImage(
    $product['id'], 
    $justProductPath, 
    $productInfo
);

$uploadSuccess = false;
if ($uploadResult['success']) {
    logMessage("✓ Upload successful!");
    $uploadSuccess = true;
} else {
    logMessage("✗ Upload failed: " . ($uploadResult['error'] ?? 'Unknown error'));
}

// Step 8: Clean up temporary files
if (file_exists($originalImagePath)) {
    @unlink($originalImagePath);
}
if (file_exists($justProductPath)) {
    @unlink($justProductPath);
}

// Step 9: Mark product as processed ONLY if upload was successful
logMessage("");
if ($uploadSuccess) {
    logMessage("Marking product as just_product_processed...");
    
    // Reconnect to database (process may have taken time)
    @mysqli_close($connect);
    $connect = mysqli_connect($host, $user, $password, $dbname);
    
    if (!$connect) {
        logMessage("✗ Failed to reconnect to database");
    } else {
        $updateQuery = "UPDATE products SET just_product_processed = 1, updated_at = NOW() WHERE id = ?";
        $stmt = mysqli_prepare($connect, $updateQuery);
        
        if ($stmt === false) {
            logMessage("✗ Failed to prepare statement: " . mysqli_error($connect));
        } else {
            mysqli_stmt_bind_param($stmt, "i", $product['id']);
            
            if (mysqli_stmt_execute($stmt)) {
                logMessage("✓ Product marked as just_product_processed successfully!");
            } else {
                logMessage("✗ Failed to execute update: " . mysqli_error($connect));
            }
            mysqli_stmt_close($stmt);
        }
    }
} else {
    logMessage("⚠ Product NOT marked as processed - upload failed");
    logMessage("⚠ Product will remain available for processing in the next run");
    
    // Reconnect to database to ensure connection is closed properly
    @mysqli_close($connect);
    $connect = mysqli_connect($host, $user, $password, $dbname);
}

// Summary
logMessage("");
logMessage("=== Processing Complete ===");
logMessage("Product ID: {$product['id']}");
logMessage("Product Name: {$product['name']}");
logMessage("Image Generated: " . ($success ? "Yes" : "No"));
logMessage("Image Uploaded: " . ($uploadSuccess ? "Yes" : "No"));
logMessage("Status: " . ($uploadSuccess ? "Marked as processed" : "NOT processed - will retry in next run"));
logMessage("");

mysqli_close($connect);

echo "</pre>";
echo "<hr>";
echo "<p><strong>Summary:</strong></p>";
echo "<ul>";
echo "<li>Product ID: {$product['id']}</li>";
echo "<li>Product Name: {$product['name']}</li>";
echo "<li>Image Generated: <span class='" . ($success ? "success" : "error") . "'>" . ($success ? "Yes" : "No") . "</span></li>";
echo "<li>Image Uploaded: <span class='" . ($uploadSuccess ? "success" : "error") . "'>" . ($uploadSuccess ? "Yes" : "No") . "</span></li>";
echo "<li>Status: <span class='" . ($uploadSuccess ? "success" : "error") . "'>" . ($uploadSuccess ? "Processed" : "NOT Processed - Will Retry") . "</span></li>";
echo "</ul>";
echo "<p><a href='generate_just_product_images.php?GEMINI_API_KEY={$GEMINI_API_KEY}'>Process Next Product</a></p>";
echo "</body></html>";
?>

