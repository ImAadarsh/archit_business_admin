<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('memory_limit', '512M');
set_time_limit(240);

// Include database connection
require_once('admin/connect.php');
require_once('admin/ai_generation_log.php');

// CLI support: php generate_product_mockups_v2.php GEMINI_API_KEY=xxx IMAGE_MODEL=yyy
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
$GEMINI_API_KEY = $_GET['GEMINI_API_KEY'] ?? '';
if (!$GEMINI_API_KEY) {
    die("GEMINI_API_KEY is required\n");
}

// Cheapest Gemini image model (Nano Banana 2 Lite). Override with ?IMAGE_MODEL=gemini-2.5-flash-image
$GEMINI_IMAGE_MODEL = preg_replace('/[^a-zA-Z0-9._-]/', '', (string) ($_GET['IMAGE_MODEL'] ?? 'gemini-3.1-flash-lite-image'));
if ($GEMINI_IMAGE_MODEL === '') {
    $GEMINI_IMAGE_MODEL = 'gemini-3.1-flash-lite-image';
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
    __DIR__ . '/temp/product_mockups/',  // Project directory (preferred)
    __DIR__ . '/tmp/product_mockups/',   // Alternative project location
    __DIR__ . '/product_mockups_temp/',  // Direct in business folder
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
        "  - " . __DIR__ . "/temp/product_mockups/\n" .
        "  - " . __DIR__ . "/tmp/product_mockups/\n" .
        "  - " . __DIR__ . "/product_mockups_temp/\n" .
        "Error: " . ($error ? $error['message'] : 'Unknown error') . "\n\n" .
        "SOLUTION: Please run this command in terminal:\n" .
        "  mkdir -p " . __DIR__ . "/temp/product_mockups\n" .
        "  chmod -R 777 " . __DIR__ . "/temp\n");
}

// Set log file location (same directory as temp)
$LOG_FILE = dirname($TEMP_DIR) . '/mockup_generations_log.txt';

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

// Skip AI naming when product already has a useful descriptive name
function needsAIProductNaming($name) {
    $name = trim((string) $name);
    if ($name === '') {
        return true;
    }

    $genericNames = [
        'devotional', 'landscapes', 'landscape', 'animal', 'animals', 'figurative',
        'modern art', 'abstract', 'portrait', 'portraits', 'nature', 'floral',
        'religious', 'spiritual', 'cityscape', 'still life', 'product', 'untitled',
        'art', 'painting', 'print', 'canvas'
    ];

    // Only spend a naming call on empty/generic placeholders
    if (in_array(strtolower($name), $genericNames, true)) {
        return true;
    }

    $wordCount = preg_match_all('/\S+/u', $name);
    // Single-word non-listed names are still treated as placeholders
    return $wordCount < 2;
}

// Defaults + parse helpers for room selection
function getMockupRoomDefaults($orientation) {
    $isVertical = (strtolower((string) $orientation) === 'vertical');
    $allowed = $isVertical
        ? ['corridor', 'staircase', 'entryway']
        : ['living_room', 'dining_room', 'office', 'bedroom'];
    $fallback = $isVertical
        ? ['corridor', 'staircase']
        : ['living_room', 'dining_room'];
    return [$allowed, $fallback, $isVertical];
}

function parseMockupRoomsFromText($text, $allowed, $fallback) {
    $selected = [];
    if (preg_match('/ROOMS:\s*(.+)$/is', (string) $text, $m)) {
        $parts = preg_split('/[\s,]+/', strtolower(trim($m[1])));
        foreach ($parts as $part) {
            $part = trim($part);
            $part = str_replace(['-', ' '], '_', $part);
            if (in_array($part, $allowed, true) && !in_array($part, $selected, true)) {
                $selected[] = $part;
            }
            if (count($selected) >= 2) {
                break;
            }
        }
    }
    foreach ($fallback as $room) {
        if (count($selected) >= 2) {
            break;
        }
        if (!in_array($room, $selected, true)) {
            $selected[] = $room;
        }
    }
    if (count($selected) < 2) {
        return $fallback;
    }
    return array_slice($selected, 0, 2);
}

/**
 * Single Gemini text call: room selection always; name/description only when requested.
 * Returns: name?, description?, suitable_for?, rooms[], naming_ok?
 */
function analyzeArtworkForMockups($apiKey, $artworkData, $orientation, $includeNaming = false) {
    list($allowed, $fallback, $isVertical) = getMockupRoomDefaults($orientation);
    $result = [
        'name' => null,
        'description' => null,
        'suitable_for' => null,
        'rooms' => $fallback,
        'naming_ok' => false,
    ];

    if (!$artworkData) {
        logMessage("  WARNING: No artwork data for AI analysis — using room defaults");
        return $result;
    }

    $allowedList = implode(', ', $allowed);
    $orientLabel = $isVertical ? 'VERTICAL' : 'HORIZONTAL';

    if ($includeNaming) {
        $prompt = "Analyze this artwork (orientation: {$orientLabel}). Reply EXACTLY in this format:
NAME: [3-5 word product name, no word PRINT]
DESCRIPTION: [1 sentence style/subject]
• [feature 1]
• [feature 2]
• [feature 3]
SUITABLE_FOR: [comma-separated rooms]
ROOMS: room1, room2

For ROOMS pick the 2 best mockup settings from ONLY: {$allowedList}";
        $maxTokens = 450;
        $temperature = 0.5;
    } else {
        $prompt = "Analyze this artwork (orientation: {$orientLabel}).
Pick the 2 best room mockup settings for displaying this art.
Allowed values ONLY: {$allowedList}
Reply EXACTLY:
ROOMS: room1, room2";
        $maxTokens = 80;
        $temperature = 0.3;
    }

    $requestBody = [
        'contents' => [
            [
                'parts' => [
                    ['text' => $prompt],
                    [
                        'inline_data' => [
                            'mime_type' => 'image/jpeg',
                            'data' => base64_encode($artworkData)
                        ]
                    ]
                ]
            ]
        ],
        'generationConfig' => [
            'temperature' => $temperature,
            'maxOutputTokens' => $maxTokens
        ]
    ];

    $ch = curl_init("https://generativelanguage.googleapis.com/v1beta/models/gemini-3.1-flash-lite:generateContent?key={$apiKey}");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($requestBody));
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !$response) {
        logMessage("  WARNING: Combined AI analysis failed (HTTP {$httpCode}) — using room defaults");
        return $result;
    }

    $decoded = json_decode($response, true);
    $generatedText = trim($decoded['candidates'][0]['content']['parts'][0]['text'] ?? '');
    if ($generatedText === '') {
        logMessage("  WARNING: Empty AI analysis response — using room defaults");
        return $result;
    }

    logMessage("  AI analysis raw: " . str_replace("\n", ' | ', $generatedText));

    $result['rooms'] = parseMockupRoomsFromText($generatedText, $allowed, $fallback);

    if ($includeNaming) {
        $name = null;
        $description = null;
        $suitableFor = null;

        if (preg_match('/NAME:\s*(.+?)(?:\n|DESCRIPTION:)/is', $generatedText, $nameMatch)) {
            $name = trim($nameMatch[1], " \t\"'");
        }
        if (preg_match('/DESCRIPTION:\s*(.+?)(?:\nSUITABLE_FOR:|\nROOMS:|$)/is', $generatedText, $descMatch)) {
            $description = trim($descMatch[1]);
        }
        if (preg_match('/SUITABLE_FOR:\s*(.+?)(?:\nROOMS:|$)/is', $generatedText, $suitableMatch)) {
            $suitableFor = trim($suitableMatch[1], " \t\"'");
        }

        if ($name && $description) {
            $result['name'] = $name;
            $result['description'] = $description;
            $result['naming_ok'] = true;
            if ($suitableFor) {
                $result['suitable_for'] = $suitableFor;
            }
        }
    }

    return $result;
}

// Shared tight prompts for all supported mockup rooms
// Keep wording mild — aggressive prompts often trigger IMAGE_SAFETY on product art.
function getMockupRoomPrompts($productInfo) {
    $dimensions = '';
    if (!empty($productInfo['width']) && !empty($productInfo['height'])) {
        $dimensions = "Artwork size about {$productInfo['width']} x {$productInfo['height']}.";
    }

    $isFramed = (isset($productInfo['is_framed']) && $productInfo['is_framed'] == 1);
    $frameInfo = $isFramed
        ? "Keep the existing frame visible; clean only black corner artifacts on the frame."
        : "Frameless piece; do not crop or change orientation.";

    $isVertical = (isset($productInfo['orientation']) && strtolower($productInfo['orientation']) === 'vertical');
    $orientLabel = $isVertical ? 'vertical' : 'horizontal';
    $orientRule = "Keep the artwork {$orientLabel}; do not rotate or flip.";

    $base = "Interior design visualization. Wall art placement only. Use the provided painting as-is. {$dimensions} {$frameInfo} {$orientRule} Soft natural light. Neutral interior. Photorealistic.";

    return [
        'corridor' => "{$base} Place it on a corridor / hallway wall at eye level.",
        'staircase' => "{$base} Place it on a wall beside a staircase at eye level.",
        'entryway' => "{$base} Place it on an entryway wall at eye level.",
        'living_room' => "{$base} Place it on a living room wall above a sofa at eye level.",
        'dining_room' => "{$base} Place it on a dining room wall near a table at eye level.",
        'office' => "{$base} Place it on an office wall above a desk at eye level.",
        'bedroom' => "{$base} Place it on a bedroom wall above a headboard at eye level.",
    ];
}

/** Extract base64 image bytes from a Gemini generateContent JSON response. */
function extractGeminiImageBase64($responseJson) {
    if (!is_array($responseJson)) {
        return [null, 'invalid_json', ''];
    }
    if (isset($responseJson['error']['message'])) {
        return [null, 'api_error', (string) $responseJson['error']['message']];
    }
    $candidate = $responseJson['candidates'][0] ?? null;
    if (!$candidate) {
        return [null, 'no_candidate', ''];
    }
    $finish = (string) ($candidate['finishReason'] ?? $candidate['finish_reason'] ?? '');
    $finishMessage = (string) ($candidate['finishMessage'] ?? $candidate['finish_message'] ?? '');
    if ($finish !== '' && strtoupper($finish) !== 'STOP') {
        return [null, $finish, $finishMessage !== '' ? $finishMessage : $finish];
    }

    $content = $candidate['content'] ?? null;
    $parts = [];
    if (is_array($content) && isset($content['parts']) && is_array($content['parts'])) {
        $parts = $content['parts'];
    }

    foreach ($parts as $part) {
        if (isset($part['inlineData']['data'])) {
            return [$part['inlineData']['data'], 'ok', ''];
        }
        if (isset($part['inline_data']['data'])) {
            return [$part['inline_data']['data'], 'ok', ''];
        }
    }
    return [null, 'no_image_parts', $finishMessage];
}

function buildGeminiImageRequestBody($imageBase64, $prompt) {
    return [
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
}

// Function to create multiple mockups in parallel (faster wall-clock; avoids gateway timeout)
function createMockupsParallel($apiKey, $artworkPath, $mockupTypes, $productInfo, $productId, $artworkData = null) {
    global $TEMP_DIR, $GEMINI_IMAGE_MODEL;

    if ($artworkData === null) {
        $artworkData = file_get_contents($artworkPath);
    }

    if (!$artworkData) {
        logMessage("  ERROR: Could not load artwork data");
        return [];
    }

    $imageBase64 = base64_encode($artworkData);
    $isVertical = (isset($productInfo['orientation']) && strtolower($productInfo['orientation']) === 'vertical');
    $prompts = getMockupRoomPrompts($productInfo);
    $model = $GEMINI_IMAGE_MODEL ?: 'gemini-3.1-flash-lite-image';
    logMessage("  Image model: {$model}");
    $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=" . $apiKey;

    $multiHandle = curl_multi_init();
    $curlHandles = [];

    foreach ($mockupTypes as $mockupType) {
        $defaultPrompt = $isVertical ? ($prompts['corridor'] ?? '') : ($prompts['living_room'] ?? '');
        $prompt = $prompts[$mockupType] ?? $defaultPrompt;
        $outputPath = $TEMP_DIR . "mockup_{$productId}_{$mockupType}_" . time() . "_" . uniqid() . ".jpg";

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(buildGeminiImageRequestBody($imageBase64, $prompt)));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 55);

        curl_multi_add_handle($multiHandle, $ch);
        $curlHandles[$mockupType] = [
            'handle' => $ch,
            'outputPath' => $outputPath,
        ];
    }

    $running = null;
    do {
        curl_multi_exec($multiHandle, $running);
        curl_multi_select($multiHandle);
    } while ($running > 0);

    $results = [];
    foreach ($curlHandles as $mockupType => $data) {
        $ch = $data['handle'];
        $outputPath = $data['outputPath'];
        $result = curl_multi_getcontent($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_multi_remove_handle($multiHandle, $ch);
        curl_close($ch);

        $saved = false;
        $lastReason = 'unknown';
        if ($httpCode === 200) {
            $response = json_decode($result, true);
            list($imageB64, $reason, $detail) = extractGeminiImageBase64($response);
            if ($imageB64 !== null && file_put_contents($outputPath, base64_decode($imageB64))) {
                logMessage("  ✓ {$mockupType} mockup generated successfully");
                $results[] = ['type' => $mockupType, 'path' => $outputPath, 'success' => true];
                $saved = true;
            } else {
                logMessage("  ✗ {$mockupType}: {$reason}" . ($detail ? " — " . substr($detail, 0, 180) : ''));
                $lastReason = $reason;
                if ($reason === 'IMAGE_SAFETY') {
                    $safePrompt = "Interior design visualization. Wall art placement only. Use the provided painting as-is on a {$mockupType} wall. Do not alter the painting.";
                    logMessage("  → Retrying {$mockupType} with safer prompt...");
                    $ch2 = curl_init($url);
                    curl_setopt($ch2, CURLOPT_POST, 1);
                    curl_setopt($ch2, CURLOPT_POSTFIELDS, json_encode(buildGeminiImageRequestBody($imageBase64, $safePrompt)));
                    curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch2, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
                    curl_setopt($ch2, CURLOPT_TIMEOUT, 55);
                    $result2 = curl_exec($ch2);
                    $http2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
                    curl_close($ch2);
                    if ($http2 === 200) {
                        list($imageB64b, $reason2, $detail2) = extractGeminiImageBase64(json_decode($result2, true));
                        $lastReason = $reason2;
                        if ($imageB64b !== null && file_put_contents($outputPath, base64_decode($imageB64b))) {
                            logMessage("  ✓ {$mockupType} mockup generated on safer retry");
                            $results[] = ['type' => $mockupType, 'path' => $outputPath, 'success' => true];
                            $saved = true;
                        } else {
                            logMessage("  ✗ {$mockupType} retry failed: {$reason2}" . ($detail2 ? " — " . substr($detail2, 0, 120) : ''));
                        }
                    } else {
                        logMessage("  ✗ {$mockupType} retry HTTP {$http2}");
                    }
                }
            }
        } else {
            $lastReason = 'http_' . $httpCode;
            if ($httpCode === 429) {
                logMessage("  ✗ {$mockupType} rate-limited (HTTP 429) — failing fast (no wait)");
            } else {
                logMessage("  ✗ {$mockupType} failed (HTTP {$httpCode})");
            }
            if ($result) {
                logMessage("  ===== {$mockupType} Error Response =====");
                logMessage("  " . substr($result, 0, 800));
                logMessage("  ===== END Error Response =====");
            }
        }

        if (!$saved) {
            $results[] = ['type' => $mockupType, 'success' => false, 'reason' => $lastReason ?? 'unknown'];
        }
    }

    curl_multi_close($multiHandle);
    return $results;
}

// Function to create mockup using Gemini Image Generation API
function createMockupWithGeminiAPI($apiKey, $artworkPath, $mockupType, $outputPath, $productInfo, $aiAnalysis = null, $artworkData = null) {
    global $GEMINI_IMAGE_MODEL;

    if ($artworkData === null) {
        $artworkData = file_get_contents($artworkPath);
    }

    if (!$artworkData) {
        logMessage("  ERROR: Could not load artwork data");
        return false;
    }

    $imageBase64 = base64_encode($artworkData);
    $isVertical = (isset($productInfo['orientation']) && strtolower($productInfo['orientation']) === 'vertical');
    $prompts = getMockupRoomPrompts($productInfo);

    $defaultPrompt = $isVertical ? ($prompts['corridor'] ?? '') : ($prompts['living_room'] ?? '');
    $prompt = $prompts[$mockupType] ?? $defaultPrompt;

    $model = $GEMINI_IMAGE_MODEL ?: 'gemini-3.1-flash-lite-image';
    $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=" . $apiKey;

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(buildGeminiImageRequestBody($imageBase64, $prompt)));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 55);

    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($httpCode !== 200) {
        logMessage("  ✗ {$mockupType} failed (HTTP {$httpCode})");
        if (!empty($curlError)) {
            logMessage("  CURL Error: {$curlError}");
        }
        if ($result) {
            logMessage("  " . substr($result, 0, 800));
        }
        return false;
    }

    list($imageB64, $reason, $detail) = extractGeminiImageBase64(json_decode($result, true));
    if ($imageB64 === null) {
        logMessage("  ✗ {$mockupType}: {$reason}" . ($detail ? " — " . substr($detail, 0, 180) : ''));
        return false;
    }

    if (file_put_contents($outputPath, base64_decode($imageB64))) {
        logMessage("  ✓ {$mockupType} mockup generated successfully");
        return true;
    }

    logMessage("  ✗ {$mockupType}: Failed to save generated image");
    return false;
}


// Function to upload image using the API with metadata
function uploadImageToProduct($productId, $imagePath, $mockupType, $productInfo, $token = '') {
    if (!file_exists($imagePath)) {
        return ['success' => false, 'error' => 'Image file not found'];
    }
    
    // Use the correct API endpoint
    $apiUrl = 'https://api.invoicemate.in/public/api/v2/products/images/add';
    
    // Create CURLFile for the image
    $cFile = new CURLFile($imagePath, 'image/jpeg', basename($imagePath));
    
    // Build mockup description with metadata
    $mockupDescriptions = [
        'living_room' => 'Living Room Setting',
        'dining_room' => 'Dining Room Setting',
        'office' => 'Office Environment',
        'bedroom' => 'Bedroom Decor',
        'corridor' => 'Modern Corridor Setting',
        'staircase' => 'Elegant Staircase Setting',
        'entryway' => 'Stylish Entryway Setting'
    ];
    
    $mockupDesc = $mockupDescriptions[$mockupType] ?? 'Room Mockup';
    
    // Create comprehensive metadata description
    $metadataArray = [
        'mockup_type' => $mockupDesc,
        'product_name' => $productInfo['name'] ?? '',
        'description' => $productInfo['description'] ?? '',
        'suitable_for' => $productInfo['suitable_for'] ?? '',
        'artist_name' => $productInfo['artist_name'] ?? '',
        'dimensions' => ($productInfo['width'] && $productInfo['height']) 
            ? "{$productInfo['width']} x {$productInfo['height']}" 
            : ''
    ];
    
    // Clean up empty values
    $metadataArray = array_filter($metadataArray);
    
    // Create comprehensive mockup description
    $fullMockupDescription = $mockupDesc . ' | ' . ($productInfo['name'] ?? '');
    
    // Prepare POST data - key must match what the API expects
    $postData = [
        'product_id' => $productId,
        'images[]' => $cFile,
        'name' => $productInfo['name'] ?? '',
        'description' => $productInfo['description'] ?? '',
        'suitable_for' => $productInfo['suitable_for'] ?? '',
        'mockup_description' => $fullMockupDescription,
        'is_mockup' => 1,
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
            logMessage("  → ✓ Uploaded successfully via API to server!");
            logMessage("  → Metadata: {$mockupDesc} | {$productInfo['name']}");
            
            // Also store metadata in a JSON log file
            $metadataLog = __DIR__ . '/mockup_metadata_log.json';
            $logData = [];
            if (file_exists($metadataLog)) {
                $logData = json_decode(file_get_contents($metadataLog), true) ?: [];
            }
            
            $logData[] = [
                'timestamp' => date('Y-m-d H:i:s'),
                'product_id' => $productId,
                'mockup_type' => $mockupDesc,
                'metadata' => $metadataArray,
                'image_path' => $response['data'][0]['image'] ?? ''
            ];
            
            @file_put_contents($metadataLog, json_encode($logData, JSON_PRETTY_PRINT));
            
            return ['success' => true, 'method' => 'api', 'response' => $response, 'metadata' => $metadataArray];
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
echo "<!DOCTYPE html><html><head><title>Mockup Generator</title>";
echo "<style>body{font-family:monospace;padding:20px;background:#f5f5f5;}";
echo ".success{color:green;}.error{color:red;}.info{color:blue;}</style></head><body>";
echo "<h2>Product Mockup Generator V2</h2>";
echo "<pre>";

logMessage("=== Product Mockup Generator V2 Started ===");
logMessage("Temp Directory: " . $TEMP_DIR);
logMessage("Log File: " . $LOG_FILE);
logMessage("");

// Release stale claims: claimed but never got a mockup, and product still has source images
// Use id-list UPDATE to avoid MySQL "Record has changed since last read" on multi-table UPDATE.
$staleIds = [];
$staleSelect = mysqli_query($connect, "SELECT p.id
    FROM products p
    WHERE p.is_temp = 0
      AND p.is_processed = 1
      AND p.updated_at < (NOW() - INTERVAL 15 MINUTE)
      AND EXISTS (SELECT 1 FROM product_images pi WHERE pi.product_id = p.id)
      AND NOT EXISTS (SELECT 1 FROM product_images pi WHERE pi.product_id = p.id AND pi.is_mockup = 1)
    LIMIT 50");
if ($staleSelect) {
    while ($row = mysqli_fetch_assoc($staleSelect)) {
        $staleIds[] = (int) $row['id'];
    }
}
if (!empty($staleIds)) {
    $idList = implode(',', $staleIds);
    try {
        if (mysqli_query($connect, "UPDATE products SET is_processed = 0, updated_at = NOW() WHERE id IN ({$idList})")) {
            $staleCount = (int) mysqli_affected_rows($connect);
            if ($staleCount > 0) {
                logMessage("Released {$staleCount} stale mockup claim(s) for retry");
                logMessage("");
            }
        }
    } catch (Throwable $e) {
        logMessage("Stale claim release skipped: " . $e->getMessage());
        logMessage("");
    }
}

// How many products still need mockups (must have at least one source image)
$remainingProducts = 0;
$countResult = mysqli_query($connect, "SELECT COUNT(*) AS total FROM products p
    WHERE p.is_temp = 0 AND p.is_processed = 0
      AND EXISTS (SELECT 1 FROM product_images pi WHERE pi.product_id = p.id)");
if ($countResult) {
    $remainingProducts = (int) (mysqli_fetch_assoc($countResult)['total'] ?? 0);
    logMessage("Products remaining to process (mockups): {$remainingProducts}");
    logMessage("");
}

// Step 1: Get one unprocessed product that has images
$query = "SELECT p.* FROM products p
          WHERE p.is_temp = 0 AND p.is_processed = 0
            AND EXISTS (SELECT 1 FROM product_images pi WHERE pi.product_id = p.id)
          ORDER BY p.id ASC
          LIMIT 1";
$result = mysqli_query($connect, $query);

if (!$result || mysqli_num_rows($result) === 0) {
    logMessage("No unprocessed products found.");
    echo "</pre>";
    echo "<p class='info'>No more products to process. Remaining: 0</p>";
    echo "</body></html>";
    exit;
}

$product = mysqli_fetch_assoc($result);
logMessage("Processing Product ID: {$product['id']}");
logMessage("Product Name: {$product['name']}");
logMessage("Description: " . ($product['description'] ?? 'N/A'));
logMessage("");

// Claim immediately so overlapping runs cannot process the same product twice
if (!claimProductFlag($connect, $product['id'], 'is_processed')) {
    logMessage("SKIPPING: Product #{$product['id']} was already claimed by another run.");
    echo "</pre>";
    echo "<p class='info'>Product already in progress or processed. Trying next…</p>";
    $nextUrl = 'generate_product_mockups_v2.php?GEMINI_API_KEY=' . urlencode($GEMINI_API_KEY);
    echo "<meta http-equiv='refresh' content='1;url={$nextUrl}'>";
    echo "<p><a href='{$nextUrl}'>Process Next Product</a></p>";
    echo "</body></html>";
    exit;
}
logMessage("✓ Product claimed for this run (prevents duplicate generations)");
logMessage("");

// Step 2: Get the first image for this product
$imageQuery = "SELECT * FROM product_images WHERE product_id = ? ORDER BY id ASC LIMIT 1";
$stmt = mysqli_prepare($connect, $imageQuery);

if ($stmt === false) {
    logMessage("ERROR: Failed to prepare image query: " . mysqli_error($connect));
    releaseProductFlag($connect, $product['id'], 'is_processed');
    exit;
}

mysqli_stmt_bind_param($stmt, "i", $product['id']);
mysqli_stmt_execute($stmt);
$imageResult = mysqli_stmt_get_result($stmt);

if (!$imageResult || mysqli_num_rows($imageResult) === 0) {
    logMessage("ERROR: No images found for this product. Skipping permanently...");
    mysqli_stmt_close($stmt);
    // Keep is_processed = 1 (already claimed) so this product is not selected again
    logMessage("✓ Product #{$product['id']} marked processed (no source images)");
    echo "</pre>";
    echo "<p class='error'>No images for product #{$product['id']} — skipped permanently.</p>";
    $nextUrl = 'generate_product_mockups_v2.php?GEMINI_API_KEY=' . urlencode($GEMINI_API_KEY);
    echo "<meta http-equiv='refresh' content='1;url={$nextUrl}'>";
    echo "<p><a href='{$nextUrl}'>Process Next Product</a></p>";
    echo "</body></html>";
    exit;
}

$productImage = mysqli_fetch_assoc($imageResult);
mysqli_stmt_close($stmt);

// Construct full image URL
$imageUrl = $uri . $productImage['image'];
logMessage("Product Image URL: {$imageUrl}");
logMessage("");

// Step 3: Download the original artwork
$originalImagePath = $TEMP_DIR . 'original_' . $product['id'] . '.jpg';
logMessage("Downloading artwork...");

if (!downloadImage($imageUrl, $originalImagePath)) {
    logMessage("ERROR: Failed to download image from: {$imageUrl}");
    releaseProductFlag($connect, $product['id'], 'is_processed');
    logMessage("✓ Claim released — product available for retry");
    exit;
}

logMessage("Artwork downloaded successfully to: {$originalImagePath}");
logMessage("");

// Step 3.25: Resize artwork once for mockup image calls (1024px)
logMessage("Optimizing artwork for AI payload...");
$optimizedArtworkData = resizeImageForAI($originalImagePath, 1024);
if (!$optimizedArtworkData) {
    logMessage("ERROR: Failed to optimize artwork for AI usage");
    releaseProductFlag($connect, $product['id'], 'is_processed');
    logMessage("✓ Claim released — product available for retry");
    exit;
}
logMessage("Artwork optimized (max dimension 1024px) for AI requests.");
logMessage("");

// Step 3.5: One Gemini text call — rooms always; naming only when needed
$aiProductName = $product['name'];
$aiProductDescription = $product['description'] ?? '';
$aiSuitableFor = null;

$namingArtworkData = resizeImageForAI($originalImagePath, 512);
if (!$namingArtworkData) {
    $namingArtworkData = $optimizedArtworkData;
}

$needsNaming = needsAIProductNaming($product['name']);
if ($needsNaming) {
    logMessage("AI analysis (name + description + 2 rooms) in one call...");
} else {
    logMessage("AI analysis (2 rooms only; name already set: {$product['name']})...");
}

$aiAnalysis = analyzeArtworkForMockups(
    $GEMINI_API_KEY,
    $namingArtworkData,
    $product['orientation'] ?? '',
    $needsNaming
);

if ($needsNaming) {
    if (!empty($aiAnalysis['naming_ok'])) {
        $aiProductName = $aiAnalysis['name'];
        $aiProductDescription = $aiAnalysis['description'];
        logMessage("✓ AI Product Name Generated: {$aiProductName}");
        logMessage("✓ AI Product Description Generated:");
        foreach (explode("\n", $aiProductDescription) as $line) {
            logMessage("   " . $line);
        }
        if (!empty($aiAnalysis['suitable_for'])) {
            $aiSuitableFor = $aiAnalysis['suitable_for'];
            logMessage("✓ AI Suitable For Generated: {$aiSuitableFor}");
        }
        logAIImageGeneration($connect, [
            'product_id' => $product['id'],
            'generation_type' => 'name_description',
            'prompt_text' => "NAME: {$aiProductName} | ROOMS: " . implode(', ', $aiAnalysis['rooms']),
            'model_name' => 'gemini-3.1-flash-lite',
            'images_count' => 0,
            'status' => 'success',
            'source' => 'live',
        ]);
    } else {
        logMessage("⚠ Naming parse failed — using original product data; rooms still applied");
        logAIImageGeneration($connect, [
            'product_id' => $product['id'],
            'generation_type' => 'name_description',
            'model_name' => 'gemini-3.1-flash-lite',
            'images_count' => 0,
            'status' => 'failed',
            'source' => 'live',
        ]);
    }
} else {
    logMessage("Skipping AI naming — product already has a descriptive name: {$product['name']}");
    logAIImageGeneration($connect, [
        'product_id' => $product['id'],
        'generation_type' => 'name_description',
        'prompt_text' => 'skipped — existing name: ' . $product['name'],
        'model_name' => 'gemini-3.1-flash-lite',
        'images_count' => 0,
        'status' => 'skipped',
        'source' => 'live',
    ]);
}

$mockupTypes = $aiAnalysis['rooms'];
logMessage("AI selected mockup types: " . implode(', ', $mockupTypes));
logAIImageGeneration($connect, [
    'product_id' => $product['id'],
    'generation_type' => 'room_selection',
    'prompt_text' => 'AI selected: ' . implode(', ', $mockupTypes),
    'model_name' => 'gemini-3.1-flash-lite',
    'images_count' => 0,
    'status' => 'success',
    'source' => 'live',
]);
logMessage("");

// Step 4: Prepare product info
$productInfo = [
    'name' => $aiProductName,
    'original_name' => $product['name'],
    'description' => $aiProductDescription,
    'suitable_for' => $aiSuitableFor ?? $product['suitable_for'] ?? 'home, office, dining, bedroom',
    'width' => $product['width'] ?? null,
    'height' => $product['height'] ?? null,
    'orientation' => $product['orientation'] ?? null,
    'is_framed' => $product['is_framed'] ?? 0,
    'artist_name' => $product['artist_name'] ?? null,
    'category_id' => $product['category_id'] ?? null,
    'art_category_id' => $product['art_category_id'] ?? null
];

logMessage("Product Info:");
logMessage("  - AI Generated Name: {$productInfo['name']}");
logMessage("  - Original Name: {$product['name']}");
logMessage("  - Dimensions: " . ($productInfo['width'] ? "{$productInfo['width']} x {$productInfo['height']}" : "Not specified"));
logMessage("  - Framed: " . ($productInfo['is_framed'] == 1 ? "Yes" : "No"));
logMessage("  - AI Description: " . substr($productInfo['description'] ?? 'N/A', 0, 100) . "...");
logMessage("  - Suitable For: {$productInfo['suitable_for']}");
logMessage("  - Orientation: " . ($productInfo['orientation'] ?? 'Not specified'));
logMessage("");

// Step 5: Generate mockup images (ALL IN PARALLEL!)
$mockupCount = count($mockupTypes);
logMessage("Generating all {$mockupCount} mockup images in parallel...");
$startTime = time();

$results = createMockupsParallel($GEMINI_API_KEY, $originalImagePath, $mockupTypes, $productInfo, $product['id'], $optimizedArtworkData);

$elapsedTime = time() - $startTime;
logMessage("");
logMessage("✓ All mockups generated in {$elapsedTime} seconds!");

// Process results
$mockupFiles = [];
$mockupsGenerated = 0;
foreach ($results as $result) {
    if ($result['success']) {
        $mockupFiles[] = [
            'type' => $result['type'],
            'path' => $result['path']
        ];
        $mockupsGenerated++;
    }
}

logMessage("");
logMessage("Total mockups generated: {$mockupsGenerated}");
logMessage("");

// Reconnect before logging/DB work — long Gemini waits often exceed MySQL wait_timeout
if (!ensureMysqliConnection($connect)) {
    logMessage("⚠ Database reconnect failed after image generation — will retry logging/claims");
}

// Step 6: Upload mockups to product with metadata
$uploadedCount = 0;
if ($mockupsGenerated > 0) {
    logMessage("Uploading mockups to product with metadata...");
    logMessage("Product Name: {$productInfo['name']}");
    logMessage("Description: " . substr($productInfo['description'] ?? 'N/A', 0, 50) . "...");
    logMessage("Suitable For: {$productInfo['suitable_for']}");
    logMessage("");
    
    foreach ($mockupFiles as $mockupFile) {
        logMessage("Uploading {$mockupFile['type']} mockup...");
        
        $uploadResult = uploadImageToProduct(
            $product['id'], 
            $mockupFile['path'], 
            $mockupFile['type'],
            $productInfo
        );
        
        if ($uploadResult['success']) {
            logMessage("  ✓ Upload successful!");
            $uploadedCount++;
            $uploadedImagePath = $uploadResult['response']['data'][0]['image'] ?? '';
            logAIImageGeneration($connect, [
                'product_id' => $product['id'],
                'generation_type' => 'mockup',
                'mockup_type' => $mockupFile['type'],
                'model_name' => $GEMINI_IMAGE_MODEL,
                'image_path' => $uploadedImagePath,
                'images_count' => 1,
                'status' => 'success',
                'source' => 'live',
            ]);
        } else {
            logMessage("  ✗ Upload failed: " . ($uploadResult['error'] ?? 'Unknown error'));
            logAIImageGeneration($connect, [
                'product_id' => $product['id'],
                'generation_type' => 'mockup',
                'mockup_type' => $mockupFile['type'],
                'model_name' => $GEMINI_IMAGE_MODEL,
                'images_count' => 0,
                'status' => 'failed',
                'source' => 'live',
            ]);
        }
        
        // Clean up temporary file
        if (file_exists($mockupFile['path'])) {
            @unlink($mockupFile['path']);
        }
    }
    
    logMessage("");
    logMessage("Successfully uploaded {$uploadedCount} out of {$mockupsGenerated} mockups");
    logMessage("Metadata log saved to: mockup_metadata_log.json");
} else {
    logMessage("No mockups were generated to upload.");
}

// Step 7: Clean up original image
if (file_exists($originalImagePath)) {
    @unlink($originalImagePath);
}

// Step 8: Keep claim on success OR IMAGE_SAFETY skip (so queue advances). Release on retryable failures.
logMessage("");
$safetySkip = false;
if ($uploadedCount === 0 && $mockupsGenerated === 0 && !empty($results)) {
    $safetySkip = true;
    foreach ($results as $r) {
        if (($r['reason'] ?? '') !== 'IMAGE_SAFETY') {
            $safetySkip = false;
            break;
        }
    }
}

if ($uploadedCount > 0) {
    logMessage("✓ Product remains marked as processed (claimed at start)");
} elseif ($safetySkip) {
    logMessage("⚠ All mockups blocked by Gemini IMAGE_SAFETY — skipping product permanently so queue can continue");
    logAIImageGeneration($connect, [
        'product_id' => $product['id'],
        'generation_type' => 'mockup',
        'model_name' => $GEMINI_IMAGE_MODEL,
        'images_count' => 0,
        'status' => 'failed',
        'source' => 'live',
        'prompt_text' => 'IMAGE_SAFETY blocked all mockup rooms',
    ]);
} else {
    logMessage("⚠ No mockups uploaded — releasing claim so product can retry");
    
    if (!ensureMysqliConnection($connect)) {
        logMessage("✗ Failed to reconnect to database");
    } else if (releaseProductFlag($connect, $product['id'], 'is_processed')) {
        logMessage("✓ Claim released — product available for retry");
    } else {
        logMessage("✗ Failed to release claim");
    }
}

// Summary
$consumed = ($uploadedCount > 0 || $safetySkip) ? 1 : 0;
$remainingAfter = max(0, $remainingProducts - $consumed);
logMessage("");
logMessage("=== Processing Complete ===");
logMessage("Product ID: {$product['id']}");
logMessage("Product Name: {$product['name']}");
logMessage("Mockups Generated: {$mockupsGenerated}");
logMessage("Mockups Uploaded: {$uploadedCount}");
$statusLabel = $uploadedCount > 0 ? "Marked as processed" : ($safetySkip ? "Skipped (IMAGE_SAFETY)" : "NOT processed - will retry in next run");
logMessage("Status: {$statusLabel}");
logMessage("Products remaining after this run: {$remainingAfter}");
logMessage("");
logMessage("Temp Directory Location: {$TEMP_DIR}");

mysqli_close($connect);

echo "</pre>";
echo "<hr>";
echo "<p><strong>Summary:</strong></p>";
echo "<ul>";
echo "<li>Product ID: {$product['id']}</li>";
echo "<li>Product Name: {$product['name']}</li>";
echo "<li>Mockups Generated: <span class='" . ($mockupsGenerated > 0 ? "success" : "error") . "'>{$mockupsGenerated}</span></li>";
echo "<li>Mockups Uploaded: <span class='" . ($uploadedCount > 0 ? "success" : "error") . "'>{$uploadedCount}</span></li>";
echo "<li>Status: <span class='" . (($uploadedCount > 0 || !empty($safetySkip)) ? "success" : "error") . "'>" . htmlspecialchars($statusLabel) . "</span></li>";
echo "<li>Products remaining: <span class='info'>{$remainingAfter}</span> (was {$remainingProducts})</li>";
echo "</ul>";
$nextUrl = 'generate_product_mockups_v2.php?GEMINI_API_KEY=' . urlencode($GEMINI_API_KEY);
if (!empty($GEMINI_IMAGE_MODEL)) {
    $nextUrl .= '&IMAGE_MODEL=' . urlencode($GEMINI_IMAGE_MODEL);
}
echo "<p><a href='{$nextUrl}'>Process Next Product</a></p>";
echo "</body></html>";
?>

