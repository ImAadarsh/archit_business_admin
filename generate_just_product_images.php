<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('memory_limit', '512M');
set_time_limit(300);

// Include database connection
require_once('admin/connect.php');
require_once('admin/ai_generation_log.php');

// Fallback if production still has an older admin/connect.php without reconnect helper
if (!function_exists('ensureMysqliConnection')) {
    function ensureMysqliConnection(&$connect) {
        global $host, $user, $password, $dbname;
        if ($connect instanceof mysqli) {
            try {
                if (@mysqli_query($connect, 'SELECT 1')) {
                    return true;
                }
            } catch (Throwable $e) {
                // reconnect below
            }
            @mysqli_close($connect);
        }
        $connect = @mysqli_connect($host, $user, $password, $dbname);
        return $connect instanceof mysqli;
    }
}

// CLI support: php generate_just_product_images.php GEMINI_API_KEY=xxx
if (PHP_SAPI === 'cli' && !empty($argv)) {
    foreach (array_slice($argv, 1) as $arg) {
        if (strpos($arg, '=') === false) {
            continue;
        }
        [$k, $v] = explode('=', $arg, 2);
        $_GET[$k] = $v;
    }
}

// Configuration
$GEMINI_API_KEY = $_GET['GEMINI_API_KEY'] ?? getenv('GEMINI_API_KEY') ?: '';
if (!$GEMINI_API_KEY) {
    die("GEMINI_API_KEY is required\n");
}

// Cheapest Gemini image model (Nano Banana 2 Lite). Override with ?IMAGE_MODEL=gemini-2.5-flash-image
$GEMINI_IMAGE_MODEL = preg_replace('/[^a-zA-Z0-9._-]/', '', (string) ($_GET['IMAGE_MODEL'] ?? 'gemini-3.1-flash-lite-image'));
if ($GEMINI_IMAGE_MODEL === '') {
    $GEMINI_IMAGE_MODEL = 'gemini-3.1-flash-lite-image';
}
// Optional: force one product — php generate_just_product_images.php GEMINI_API_KEY=... PRODUCT_ID=1020
$FORCE_PRODUCT_ID = isset($_GET['PRODUCT_ID']) ? (int) $_GET['PRODUCT_ID'] : 0;

// OpenAI fallback when Gemini returns IMAGE_SAFETY (common on religious / classical art)
$OPENAI_API_KEY = $_GET['OPENAI_API_KEY'] ?? getenv('OPENAI_API_KEY') ?: '';
$OPENAI_IMAGE_MODEL = preg_replace('/[^a-zA-Z0-9._-]/', '', (string) ($_GET['OPENAI_IMAGE_MODEL'] ?? 'gpt-image-1'));
if ($OPENAI_IMAGE_MODEL === '') {
    $OPENAI_IMAGE_MODEL = 'gpt-image-1';
}
$OPENAI_IMAGE_QUALITY = preg_replace('/[^a-z]/', '', strtolower((string) ($_GET['IMAGE_QUALITY'] ?? 'medium')));
if (!in_array($OPENAI_IMAGE_QUALITY, ['low', 'medium', 'high'], true)) {
    $OPENAI_IMAGE_QUALITY = 'medium';
}
$OPENAI_INPUT_FIDELITY = 'high';
$OPENAI_MODERATION = 'low';

function justProductNextUrl() {
    global $GEMINI_API_KEY, $GEMINI_IMAGE_MODEL, $OPENAI_API_KEY, $OPENAI_IMAGE_MODEL;
    $url = 'generate_just_product_images.php?GEMINI_API_KEY=' . urlencode($GEMINI_API_KEY);
    if (!empty($GEMINI_IMAGE_MODEL)) {
        $url .= '&IMAGE_MODEL=' . urlencode($GEMINI_IMAGE_MODEL);
    }
    if (!empty($OPENAI_API_KEY)) {
        $url .= '&OPENAI_API_KEY=' . urlencode($OPENAI_API_KEY);
    }
    if (!empty($OPENAI_IMAGE_MODEL)) {
        $url .= '&OPENAI_IMAGE_MODEL=' . urlencode($OPENAI_IMAGE_MODEL);
    }
    return $url;
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

/**
 * Atomically claim a product flag. Uses plain query + affected_rows
 * (prepared-statement affected_rows is unreliable on some hosts).
 */
function claimProductFlag($connect, $productId, $flagColumn) {
    $productId = (int) $productId;
    $allowed = ['just_product_processed', 'is_processed'];
    if (!in_array($flagColumn, $allowed, true)) {
        return false;
    }
    $sql = "UPDATE products SET {$flagColumn} = 1, updated_at = NOW() WHERE id = {$productId} AND {$flagColumn} = 0";
    if (!mysqli_query($connect, $sql)) {
        return false;
    }
    return ((int) mysqli_affected_rows($connect)) === 1;
}

function releaseProductFlag($connect, $productId, $flagColumn) {
    $productId = (int) $productId;
    $allowed = ['just_product_processed', 'is_processed'];
    if (!in_array($flagColumn, $allowed, true)) {
        return false;
    }
    $sql = "UPDATE products SET {$flagColumn} = 0, updated_at = NOW() WHERE id = {$productId}";
    return (bool) mysqli_query($connect, $sql);
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
function resizeImageForAI($sourcePath, $maxDim = 768) {
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

// Function to generate clean product image using Gemini, then OpenAI if Gemini blocks
// Returns [ok(bool), modelUsed(string), lastReason(string)]
function generateJustProductImage($apiKey, $artworkPath, $outputPath, $productInfo, $artworkData = null) {
    global $GEMINI_IMAGE_MODEL, $OPENAI_API_KEY, $TEMP_DIR;

    if ($artworkData === null) {
        $artworkData = file_get_contents($artworkPath);
    }

    if (!$artworkData) {
        logMessage("  ERROR: Could not load artwork data");
        return [false, '', 'missing_artwork'];
    }

    $imageBase64 = base64_encode($artworkData);

    $dimensions = "";
    if (!empty($productInfo['width']) && !empty($productInfo['height'])) {
        $dimensions = "The artwork dimensions are {$productInfo['width']} x {$productInfo['height']}.";
    }

    $isFramed = (isset($productInfo['is_framed']) && $productInfo['is_framed'] == 1);
    $orientation = $productInfo['orientation'] ?? 'horizontal';
    $orientationInfo = strtoupper($orientation);

    // Soft commercial photo prompt — keep wording minimal (verbose prompts can trigger IMAGE_SAFETY)
    $prompt = "Clean product photo of this framed artwork only. Remove background clutter. Keep painting and frame identical. Soft studio lighting. White/neutral background. Orientation {$orientationInfo} — do not rotate.";
    if ($isFramed) {
        $prompt .= " Keep the full frame; remove black corner protectors only.";
    }
    if ($dimensions !== '') {
        $prompt .= " {$dimensions}";
    }

    $safePrompt = "Clean product photo of this framed artwork only. Remove background clutter. Keep painting and frame identical. Soft studio lighting. White/neutral background.";

    $primaryModel = $GEMINI_IMAGE_MODEL ?: 'gemini-3.1-flash-lite-image';
    $fallbackModel = 'gemini-2.5-flash-image';
    $attempts = [
        ['model' => $primaryModel, 'prompt' => $prompt, 'label' => 'primary'],
    ];
    // Lite often blocks religious art; one 2.5 retry then OpenAI (skip extra Gemini burn)
    if (stripos($primaryModel, 'lite') !== false) {
        $attempts[] = ['model' => $fallbackModel, 'prompt' => $safePrompt, 'label' => 'fallback-model'];
    } else {
        $attempts[] = ['model' => $primaryModel, 'prompt' => $safePrompt, 'label' => 'safer-prompt'];
    }

    $lastReason = '';
    $sawSafety = false;

    foreach ($attempts as $i => $attempt) {
        if ($i > 0) {
            logMessage("  → Retry ({$attempt['label']}) with model {$attempt['model']}...");
        } else {
            logMessage("  Image model: {$attempt['model']}");
        }

        list($ok, $reason, $detail) = callGeminiJustProductEdit(
            $apiKey,
            $attempt['model'],
            $imageBase64,
            $attempt['prompt'],
            $outputPath
        );
        if ($ok) {
            if ($i > 0) {
                logMessage("  ✓ Generated on {$attempt['label']} retry");
            }
            return [true, $attempt['model'], 'ok'];
        }

        $lastReason = $reason;
        if (strtoupper($reason) === 'IMAGE_SAFETY' || stripos($reason, 'SAFETY') !== false) {
            $sawSafety = true;
        }

        logMessage("  ✗ {$attempt['label']}: {$reason}" . ($detail ? " — " . substr($detail, 0, 180) : ''));

        // Non-retryable hard errors — still try OpenAI below unless auth failed on our side only
        if (in_array($reason, ['http_401', 'http_403'], true)) {
            break;
        }
    }

    // OpenAI fallback (preserves painting; works when Gemini IMAGE_SAFETY blocks)
    if (empty($OPENAI_API_KEY)) {
        logMessage("  ⚠ No OPENAI_API_KEY — cannot fallback after Gemini failure");
        return [false, '', $lastReason ?: 'gemini_failed'];
    }

    logMessage("  → OpenAI fallback (gpt-image-1, input_fidelity=high)...");
    $editSrc = $artworkPath;
    $tmpSrc = null;
    if ($artworkData) {
        $tmpSrc = rtrim($TEMP_DIR, '/') . '/openai_src_' . uniqid() . '.jpg';
        file_put_contents($tmpSrc, $artworkData);
        $editSrc = $tmpSrc;
    }

    $openaiPrompt = "PRODUCT CATALOG PHOTO ONLY. Keep the painting canvas IDENTICALLY — same figures, poses, colors, jewelry, objects. Do NOT redraw or reinterpret the art. Only place that same framed artwork on a plain white studio background. Remove shop/floor clutter. Soft studio light.";
    list($okOai, $reasonOai, $detailOai) = callOpenAIJustProductEdit(
        $OPENAI_API_KEY,
        $editSrc,
        $openaiPrompt,
        $outputPath,
        $orientation
    );
    if ($tmpSrc && file_exists($tmpSrc)) {
        @unlink($tmpSrc);
    }

    if ($okOai) {
        global $OPENAI_IMAGE_MODEL;
        logMessage("  ✓ Generated via OpenAI fallback");
        return [true, $OPENAI_IMAGE_MODEL ?: 'gpt-image-1', 'ok'];
    }

    logMessage("  ✗ OpenAI fallback: {$reasonOai}" . ($detailOai ? " — " . substr($detailOai, 0, 180) : ''));
    if (strtoupper($reasonOai) === 'IMAGE_SAFETY' || stripos((string) $reasonOai, 'SAFETY') !== false || stripos((string) $reasonOai, 'moderation') !== false) {
        $sawSafety = true;
        $lastReason = 'IMAGE_SAFETY';
    } else {
        $lastReason = $reasonOai ?: $lastReason;
    }

    return [false, '', $sawSafety ? 'IMAGE_SAFETY' : ($lastReason ?: 'failed')];
}

function openaiJustProductSize($orientation) {
    return (strtolower((string) $orientation) === 'vertical') ? '1024x1536' : '1536x1024';
}

/**
 * OpenAI Images Edits — just-product (white background).
 * @return array [ok(bool), reason(string), detail(string)]
 */
function callOpenAIJustProductEdit($apiKey, $artworkPath, $prompt, $outputPath, $orientation = '') {
    global $OPENAI_IMAGE_MODEL, $OPENAI_IMAGE_QUALITY, $OPENAI_INPUT_FIDELITY, $OPENAI_MODERATION;

    $model = $OPENAI_IMAGE_MODEL ?: 'gpt-image-1';
    $quality = $OPENAI_IMAGE_QUALITY ?: 'medium';
    $size = openaiJustProductSize($orientation);

    if (!file_exists($artworkPath)) {
        return [false, 'missing_artwork', $artworkPath];
    }

    $postFields = [
        'model' => $model,
        'prompt' => $prompt,
        'image' => new CURLFile($artworkPath, 'image/jpeg', basename($artworkPath)),
        'quality' => $quality,
        'size' => $size,
        'n' => '1',
        'moderation' => ($OPENAI_MODERATION ?: 'low'),
    ];

    // gpt-image-1 supports high fidelity; mini does not
    if (stripos($model, 'mini') === false && stripos($model, 'gpt-image-1') === 0) {
        $postFields['input_fidelity'] = $OPENAI_INPUT_FIDELITY ?: 'high';
    }

    $ch = curl_init('https://api.openai.com/v1/images/edits');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $apiKey,
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 180);

    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($httpCode !== 200) {
        $decoded = json_decode($result, true);
        $code = $decoded['error']['code'] ?? '';
        $msg = $decoded['error']['message'] ?? ($curlError ?: substr((string) $result, 0, 300));
        if ($code === 'moderation_blocked' || stripos($msg, 'safety') !== false || stripos($msg, 'moderation') !== false) {
            return [false, 'IMAGE_SAFETY', $msg];
        }
        if ($httpCode === 429) {
            return [false, 'http_429', $msg];
        }
        return [false, 'http_' . $httpCode, $msg];
    }

    $decoded = json_decode($result, true);
    $b64 = $decoded['data'][0]['b64_json'] ?? null;
    if (!$b64) {
        return [false, 'no_image_parts', substr((string) $result, 0, 300)];
    }
    if (!file_put_contents($outputPath, base64_decode($b64))) {
        return [false, 'save_failed', $outputPath];
    }
    return [true, 'ok', ''];
}

/**
 * Single Gemini image edit call.
 * @return array [ok(bool), reason(string), detail(string)]
 */
function callGeminiJustProductEdit($apiKey, $model, $imageBase64, $prompt, $outputPath) {
    $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=" . $apiKey;

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
            'temperature' => 0.3,
            'responseModalities' => ['IMAGE']
        ]
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($requestBody));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 120);

    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($result === false || $result === '') {
        return [false, 'curl_empty', $curlError ?: 'empty response'];
    }

    if ($httpCode !== 200) {
        $decoded = json_decode($result, true);
        $msg = $decoded['error']['message'] ?? ($curlError ?: substr((string) $result, 0, 300));
        if ($httpCode === 429) {
            return [false, 'http_429', $msg];
        }
        return [false, 'http_' . $httpCode, $msg];
    }

    $response = json_decode($result, true);
    if (!is_array($response)) {
        return [false, 'invalid_json', 'json_error=' . json_last_error_msg() . ' len=' . strlen($result)];
    }
    if (isset($response['error']['message'])) {
        return [false, 'api_error', (string) $response['error']['message']];
    }

    $candidate = $response['candidates'][0] ?? null;
    if (!$candidate) {
        return [false, 'no_candidate', ''];
    }

    $finish = (string) ($candidate['finishReason'] ?? $candidate['finish_reason'] ?? '');
    $finishMessage = (string) ($candidate['finishMessage'] ?? $candidate['finish_message'] ?? '');
    if ($finish !== '' && strtoupper($finish) !== 'STOP') {
        return [false, $finish, $finishMessage !== '' ? $finishMessage : $finish];
    }

    $parts = [];
    if (isset($candidate['content']['parts']) && is_array($candidate['content']['parts'])) {
        $parts = $candidate['content']['parts'];
    }

    $imageB64 = null;
    foreach ($parts as $part) {
        if (isset($part['inlineData']['data'])) {
            $imageB64 = $part['inlineData']['data'];
            break;
        }
        if (isset($part['inline_data']['data'])) {
            $imageB64 = $part['inline_data']['data'];
            break;
        }
    }

    if ($imageB64 === null) {
        return [false, 'no_image_parts', $finishMessage];
    }

    if (!file_put_contents($outputPath, base64_decode($imageB64))) {
        return [false, 'save_failed', $outputPath];
    }
    return [true, 'ok', ''];
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

// Release stale claims: marked processed but never got a just_product image
// Use id-list UPDATE to avoid MySQL "Record has changed since last read"
$staleIds = [];
$staleSelect = mysqli_query($connect, "SELECT p.id
    FROM products p
    WHERE p.is_temp = 0
      AND p.just_product_processed = 1
      AND p.updated_at < (NOW() - INTERVAL 10 MINUTE)
      AND NOT EXISTS (
          SELECT 1 FROM product_images pi
          WHERE pi.product_id = p.id AND pi.just_product = 1
      )
    LIMIT 50");
if ($staleSelect) {
    while ($row = mysqli_fetch_assoc($staleSelect)) {
        $staleIds[] = (int) $row['id'];
    }
}
if (!empty($staleIds)) {
    $idList = implode(',', $staleIds);
    try {
        if (mysqli_query($connect, "UPDATE products SET just_product_processed = 0, updated_at = NOW() WHERE id IN ({$idList})")) {
            $staleCount = (int) mysqli_affected_rows($connect);
            if ($staleCount > 0) {
                logMessage("Released {$staleCount} stale just_product claim(s) for retry");
                logMessage("");
            }
        }
    } catch (Throwable $e) {
        logMessage("Stale claim release skipped: " . $e->getMessage());
        logMessage("");
    }
}

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
if ($FORCE_PRODUCT_ID > 0) {
    $query = "SELECT p.* FROM products p WHERE p.id = " . (int) $FORCE_PRODUCT_ID . " LIMIT 1";
    logMessage("Forced PRODUCT_ID={$FORCE_PRODUCT_ID}");
} else {
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
}
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

// Claim immediately so overlapping "Process Next" tabs cannot generate the same product twice
if (!claimProductFlag($connect, $product['id'], 'just_product_processed')) {
    logMessage("SKIPPING: Product #{$product['id']} was already claimed by another run.");
    echo "</pre>";
    echo "<p class='info'>Product already in progress or processed. Trying next…</p>";
    $nextUrl = justProductNextUrl();
    echo "<meta http-equiv='refresh' content='1;url={$nextUrl}'>";
    echo "<p><a href='{$nextUrl}'>Process Next Product</a></p>";
    echo "</body></html>";
    exit;
}
logMessage("✓ Product claimed for this run (prevents duplicate generations)");
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
    releaseProductFlag($connect, $product['id'], 'just_product_processed');
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
    echo "<p><a href='" . justProductNextUrl() . "'>Process Next Product</a></p>";
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
    echo "<p><a href='" . justProductNextUrl() . "'>Process Next Product</a></p>";
    echo "</body></html>";
    exit;
}

logMessage("Artwork downloaded successfully to: {$originalImagePath}");
logMessage("");

// Step 4: Resize artwork for AI
logMessage("Optimizing artwork for AI payload...");
$optimizedArtworkData = resizeImageForAI($originalImagePath, 768);
if (!$optimizedArtworkData) {
    logMessage("ERROR: Failed to optimize artwork for AI usage");
    releaseProductFlag($connect, $product['id'], 'just_product_processed');
    logMessage("✓ Claim released — product available for retry");
    exit;
}
logMessage("Artwork optimized (max dimension 768px) for AI request.");
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

$success = false;
$usedModel = $GEMINI_IMAGE_MODEL;
$failReason = '';
list($success, $usedModel, $failReason) = generateJustProductImage(
    $GEMINI_API_KEY,
    $originalImagePath,
    $justProductPath,
    $productInfo,
    $optimizedArtworkData
);
if (!$usedModel) {
    $usedModel = $GEMINI_IMAGE_MODEL;
}

$elapsedTime = time() - $startTime;

if (!$success) {
    logMessage("✗ Failed to generate clean product image");
    logMessage("ERROR: Image generation failed after {$elapsedTime} seconds");
    
    // Clean up
    if (file_exists($originalImagePath)) {
        @unlink($originalImagePath);
    }

    $safetySkip = (strtoupper((string) $failReason) === 'IMAGE_SAFETY');
    if ($safetySkip) {
        // Keep claim so queue advances — Gemini+OpenAI both blocked this artwork
        logMessage("⚠ IMAGE_SAFETY on Gemini and OpenAI — skipping product permanently so queue can continue");
        if (function_exists('ensureMysqliConnection')) {
            ensureMysqliConnection($connect);
        }
        logAIImageGeneration($connect, [
            'product_id' => $product['id'],
            'generation_type' => 'just_product',
            'model_name' => $usedModel,
            'images_count' => 0,
            'status' => 'skipped',
            'source' => 'live',
            'prompt_text' => 'IMAGE_SAFETY blocked Gemini + OpenAI just_product',
        ]);
        echo "</pre>";
        echo "<p class='error'>Skipped (IMAGE_SAFETY). <a href='" . justProductNextUrl() . "'>Try Next Product</a></p>";
        echo "</body></html>";
        exit;
    }

    releaseProductFlag($connect, $product['id'], 'just_product_processed');
    logMessage("✓ Claim released — product available for retry");
    
    echo "</pre>";
    echo "<p class='error'>Failed to generate image. See log above for details.</p>";
    echo "<p><a href='" . justProductNextUrl() . "'>Try Next Product</a></p>";
    echo "</body></html>";
    exit;
}

logMessage("✓ Clean product image generated in {$elapsedTime} seconds! (model: {$usedModel})");
logMessage("");

// Reconnect before logging/DB work — long Gemini waits often exceed MySQL wait_timeout
if (!ensureMysqliConnection($connect)) {
    logMessage("⚠ Database reconnect failed after image generation — will retry logging/claims");
}

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
    $uploadedImagePath = $uploadResult['response']['data'][0]['image'] ?? '';
    logAIImageGeneration($connect, [
        'product_id' => $product['id'],
        'generation_type' => 'just_product',
        'model_name' => $usedModel,
        'image_path' => $uploadedImagePath,
        'images_count' => 1,
        'status' => 'success',
        'source' => 'live',
    ]);
} else {
    logMessage("✗ Upload failed: " . ($uploadResult['error'] ?? 'Unknown error'));
    logAIImageGeneration($connect, [
        'product_id' => $product['id'],
        'generation_type' => 'just_product',
        'model_name' => $usedModel,
        'images_count' => 0,
        'status' => 'failed',
        'source' => 'live',
    ]);
}

// Step 8: Clean up temporary files
if (file_exists($originalImagePath)) {
    @unlink($originalImagePath);
}
if (file_exists($justProductPath)) {
    @unlink($justProductPath);
}

// Step 9: Keep claim on success; release on upload failure so it can retry
logMessage("");
if ($uploadSuccess) {
    logMessage("✓ Product remains marked as just_product_processed (claimed at start)");
} else {
    logMessage("⚠ Upload failed — releasing claim so product can retry");
    
    if (!ensureMysqliConnection($connect)) {
        logMessage("✗ Failed to reconnect to database");
    } else if (releaseProductFlag($connect, $product['id'], 'just_product_processed')) {
        logMessage("✓ Claim released — product available for retry");
    } else {
        logMessage("✗ Failed to release claim");
    }
}

// Summary
$remainingAfter = max(0, ($remainingProducts ?? 0) - ($uploadSuccess ? 1 : 0));
logMessage("");
logMessage("=== Processing Complete ===");
logMessage("Product ID: {$product['id']}");
logMessage("Product Name: {$product['name']}");
logMessage("Image Generated: " . ($success ? "Yes" : "No"));
logMessage("Image Uploaded: " . ($uploadSuccess ? "Yes" : "No"));
logMessage("Status: " . ($uploadSuccess ? "Marked as processed" : "NOT processed - will retry in next run"));
logMessage("Products remaining after this run: {$remainingAfter}");
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
echo "<li>Products remaining: <span class='info'>{$remainingAfter}</span> (was " . ($remainingProducts ?? '?') . ")</li>";
echo "</ul>";
echo "<p><a href='" . justProductNextUrl() . "'>Process Next Product</a></p>";
echo "</body></html>";
?>

