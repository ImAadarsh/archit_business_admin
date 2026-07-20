<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('memory_limit', '512M');
set_time_limit(300);

// Include database connection
require_once('admin/connect.php');
require_once('admin/ai_generation_log.php');

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

// Function to generate AI product name and description using Gemini
function generateAIProductNameAndDescription($apiKey, $artworkPath, $artworkData = null) {
    if ($artworkData === null) {
        $artworkData = file_get_contents($artworkPath);
    }

    if (!$artworkData) {
        logMessage("  ERROR: Could not load artwork data for name generation");
        return null;
    }

    $imageBase64 = base64_encode($artworkData);
    
    // Compact prompt for name, description, and suitable_for
    $prompt = "Analyze this artwork. Reply EXACTLY in this format:
NAME: [3-5 word product name, no word PRINT]
DESCRIPTION: [1 sentence style/subject]
• [feature 1]
• [feature 2]
• [feature 3]
SUITABLE_FOR: [comma-separated rooms]";
    
    // Prepare API request
    $requestBody = [
        'contents' => [
            [
                'parts' => [
                    ['text' => $prompt],
                    [
                        'inline_data' => [
                            'mime_type' => 'image/jpeg',
                            'data' => $imageBase64
                        ]
                    ]
                ]
            ]
        ],
        'generationConfig' => [
            'temperature' => 0.7,
            'maxOutputTokens' => 400
        ]
    ];
    
    $ch = curl_init("https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash-lite:generateContent?key={$apiKey}");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($requestBody));
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200) {
        $result = json_decode($response, true);
        if (isset($result['candidates'][0]['content']['parts'][0]['text'])) {
            $generatedText = trim($result['candidates'][0]['content']['parts'][0]['text']);
            
            // DEBUG: Log the raw generated text
            logMessage("  ===== DEBUG: AI Generated Response =====");
            logMessage($generatedText);
            logMessage("  ===== END DEBUG =====");
            
            // Parse the response
            $name = null;
            $description = null;
            $suitableFor = null;
            
            // Extract NAME
            if (preg_match('/NAME:\s*(.+?)(?:\n|DESCRIPTION:)/is', $generatedText, $nameMatch)) {
                $name = trim($nameMatch[1]);
                $name = trim($name, '"\'');
                logMessage("  DEBUG: Extracted Name: {$name}");
            } else {
                logMessage("  DEBUG: Failed to extract NAME from response");
            }
            
            // Extract DESCRIPTION (everything after "DESCRIPTION:" until "SUITABLE_FOR:")
            if (preg_match('/DESCRIPTION:\s*(.+?)(?:\nSUITABLE_FOR:|$)/is', $generatedText, $descMatch)) {
                $description = trim($descMatch[1]);
                logMessage("  DEBUG: Extracted Description: " . substr($description, 0, 100) . "...");
            } else {
                logMessage("  DEBUG: Failed to extract DESCRIPTION from response");
            }
            
            // Extract SUITABLE_FOR
            if (preg_match('/SUITABLE_FOR:\s*(.+?)$/is', $generatedText, $suitableMatch)) {
                $suitableFor = trim($suitableMatch[1]);
                $suitableFor = trim($suitableFor, '"\'');
                logMessage("  DEBUG: Extracted Suitable For: {$suitableFor}");
            } else {
                logMessage("  DEBUG: Failed to extract SUITABLE_FOR from response");
            }
            
            if ($name && $description) {
                $result = [
                    'name' => $name,
                    'description' => $description
                ];
                
                // Add suitable_for if extracted
                if ($suitableFor) {
                    $result['suitable_for'] = $suitableFor;
                }
                
                return $result;
            } else {
                logMessage("  DEBUG: Parsing failed - Name: " . ($name ? "OK" : "NULL") . ", Description: " . ($description ? "OK" : "NULL") . ", Suitable For: " . ($suitableFor ? "OK" : "NULL"));
            }
        } else {
            logMessage("  DEBUG: Response structure unexpected");
            logMessage("  DEBUG: Full response: " . json_encode($result, JSON_PRETTY_PRINT));
        }
    }
    
    // Log detailed error for debugging
    logMessage("  WARNING: Could not generate AI product name and description (HTTP {$httpCode})");
    if ($httpCode !== 200 && $response) {
        $errorData = json_decode($response, true);
        if ($errorData) {
            logMessage("  API Error: " . json_encode($errorData, JSON_PRETTY_PRINT));
        } else {
            logMessage("  Raw Response: " . substr($response, 0, 300));
        }
    }
    return null;
}

// Ask AI which 2 room mockups best fit this artwork (cheap text model + small image)
function selectMockupRoomsWithAI($apiKey, $artworkData, $orientation) {
    $isVertical = (strtolower((string) $orientation) === 'vertical');
    $allowed = $isVertical
        ? ['corridor', 'staircase', 'entryway']
        : ['living_room', 'dining_room', 'office', 'bedroom'];
    $fallback = $isVertical
        ? ['corridor', 'staircase']
        : ['living_room', 'dining_room'];

    if (!$artworkData) {
        logMessage("  WARNING: No artwork data for room selection — using defaults");
        return $fallback;
    }

    $allowedList = implode(', ', $allowed);
    $prompt = "Analyze this artwork (orientation: " . ($isVertical ? 'VERTICAL' : 'HORIZONTAL') . ").
Pick the 2 best room mockup settings for displaying this art.
Allowed values ONLY: {$allowedList}
Reply EXACTLY:
ROOMS: room1, room2";

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
            'temperature' => 0.3,
            'maxOutputTokens' => 80
        ]
    ];

    $ch = curl_init("https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash-lite:generateContent?key={$apiKey}");
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
        logMessage("  WARNING: Room selection API failed (HTTP {$httpCode}) — using defaults");
        return $fallback;
    }

    $result = json_decode($response, true);
    $text = trim($result['candidates'][0]['content']['parts'][0]['text'] ?? '');
    if ($text === '') {
        logMessage("  WARNING: Empty room selection response — using defaults");
        return $fallback;
    }

    logMessage("  AI room selection raw: " . str_replace("\n", ' ', $text));

    $selected = [];
    if (preg_match('/ROOMS:\s*(.+)$/is', $text, $m)) {
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

    // Fill missing slots from fallback order
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

// Shared tight prompts for all supported mockup rooms
function getMockupRoomPrompts($productInfo) {
    $dimensions = '';
    if (!empty($productInfo['width']) && !empty($productInfo['height'])) {
        $dimensions = "The artwork dimensions are {$productInfo['width']} x {$productInfo['height']}.";
    }

    $isFramed = (isset($productInfo['is_framed']) && $productInfo['is_framed'] == 1);
    $frameInfo = $isFramed
        ? "Framed artwork: keep the same frame visible; remove black corners from the frame."
        : "Frameless artwork: do not crop or change orientation.";

    $isVertical = (isset($productInfo['orientation']) && strtolower($productInfo['orientation']) === 'vertical');
    $orientLabel = $isVertical ? 'VERTICAL' : 'HORIZONTAL';
    $orientRule = $isVertical
        ? 'CRITICAL: Keep artwork VERTICAL — do not rotate or flip.'
        : 'CRITICAL: Keep artwork HORIZONTAL — do not rotate or flip.';

    return [
        'corridor' => "Create a photorealistic modern corridor mockup with this {$orientLabel} artwork on the wall. {$dimensions} {$frameInfo}
{$orientRule}
Neutral wall toned to the art, wood floor, minimal decor/plant, natural light, artwork centered at eye level with realistic shadows. Preserve artwork pixels exactly.",

        'staircase' => "Create a photorealistic elegant staircase mockup with this {$orientLabel} artwork on the wall. {$dimensions} {$frameInfo}
{$orientRule}
Warm neutral wall, wood or marble stairs, soft ambient light, subtle decor matching the palette. Hang artwork centered; preserve artwork exactly including orientation.",

        'entryway' => "Create a photorealistic stylish entryway mockup with this {$orientLabel} artwork on the wall. {$dimensions} {$frameInfo}
{$orientRule}
Light neutral walls, high ceilings, plant/lighting accents matching the palette. Artwork centered at eye level with realistic shadows. Preserve artwork exactly.",

        'living_room' => "Create a photorealistic living room mockup with this {$orientLabel} artwork on the wall. {$dimensions} {$frameInfo}
{$orientRule}
Warm neutral wall, wood floor, sofa and small decor echoing the palette, natural light, artwork centered at eye level with realistic shadows. Preserve artwork exactly.",

        'dining_room' => "Create a photorealistic dining room mockup with this {$orientLabel} artwork on the wall. {$dimensions} {$frameInfo}
{$orientRule}
Warm neutral wall, wood dining table with chairs, soft ambient light, decor matching the art hues. Hang artwork centered; preserve artwork exactly including orientation.",

        'office' => "Create a photorealistic contemporary office mockup with this {$orientLabel} artwork on the wall. {$dimensions} {$frameInfo}
{$orientRule}
Light neutral walls, modern desk, subtle accessories matching the palette. Artwork centered above desk with realistic shadows. Preserve artwork exactly.",

        'bedroom' => "Create a photorealistic serene bedroom mockup with this {$orientLabel} artwork on the wall. {$dimensions} {$frameInfo}
{$orientRule}
Soft wall tone, bed/headboard accents matching the art, warm or cool light as fits the mood. Artwork centered above headboard with realistic shadows. Preserve artwork exactly."
    ];
}

// Function to create multiple mockups in parallel using Gemini Image Generation API
function createMockupsParallel($apiKey, $artworkPath, $mockupTypes, $productInfo, $productId, $artworkData = null) {
    global $TEMP_DIR;
    
    // Read and encode the artwork image once
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
    
    $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash-image:generateContent?key=" . $apiKey;
    
    // Prepare all curl handles
    $multiHandle = curl_multi_init();
    $curlHandles = [];
    
    foreach ($mockupTypes as $mockupType) {
        $defaultPrompt = $isVertical ? ($prompts['corridor'] ?? '') : ($prompts['living_room'] ?? '');
        $prompt = $prompts[$mockupType] ?? $defaultPrompt;
        $outputPath = $TEMP_DIR . "mockup_{$productId}_{$mockupType}_" . time() . "_" . uniqid() . ".jpg";
        
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
                'temperature' => 0.4,
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
        
        curl_multi_add_handle($multiHandle, $ch);
        $curlHandles[$mockupType] = [
            'handle' => $ch,
            'outputPath' => $outputPath
        ];
    }
    
    // Execute all requests in parallel
    $running = null;
    do {
        curl_multi_exec($multiHandle, $running);
        curl_multi_select($multiHandle);
    } while ($running > 0);
    
    // Process results
    $results = [];
    foreach ($curlHandles as $mockupType => $data) {
        $ch = $data['handle'];
        $outputPath = $data['outputPath'];
        
        $result = curl_multi_getcontent($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if ($httpCode === 200) {
            $response = json_decode($result, true);
            
            if (isset($response['candidates'][0]['content']['parts'][0]['inlineData']['data'])) {
                $generatedImageData = base64_decode($response['candidates'][0]['content']['parts'][0]['inlineData']['data']);
                
                if (file_put_contents($outputPath, $generatedImageData)) {
                    logMessage("  ✓ {$mockupType} mockup generated successfully");
                    $results[] = [
                        'type' => $mockupType,
                        'path' => $outputPath,
                        'success' => true
                    ];
                } else {
                    logMessage("  ✗ Failed to save {$mockupType} mockup");
                    $results[] = ['type' => $mockupType, 'success' => false];
                }
            } else {
                logMessage("  ✗ No image data for {$mockupType}");
                $results[] = ['type' => $mockupType, 'success' => false];
            }
        } else {
            logMessage("  ✗ {$mockupType} failed (HTTP {$httpCode})");
            
            if ($result) {
                $errorResponse = json_decode($result, true);
                if ($errorResponse) {
                    logMessage("  ===== {$mockupType} Error Response =====");
                    logMessage("  " . json_encode($errorResponse, JSON_PRETTY_PRINT));
                    logMessage("  ===== END Error Response =====");
                } else {
                    logMessage("  Raw Response (first 500 chars): " . substr($result, 0, 500));
                }
            } else {
                $curlError = curl_error($ch);
                if ($curlError) {
                    logMessage("  CURL Error: {$curlError}");
                }
            }
            
            $results[] = ['type' => $mockupType, 'success' => false];
        }
        
        curl_multi_remove_handle($multiHandle, $ch);
        curl_close($ch);
    }
    
    curl_multi_close($multiHandle);
    
    return $results;
}

// Function to create mockup using Gemini Image Generation API
function createMockupWithGeminiAPI($apiKey, $artworkPath, $mockupType, $outputPath, $productInfo, $aiAnalysis = null, $artworkData = null) {
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
    
    // Call Gemini Image Generation API (using image generation model)
    // Note: Use gemini-2.5-flash-image for image generation capabilities
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
            'temperature' => 0.4,
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
        return false;
    }
    
    $response = json_decode($result, true);
    
    if (!isset($response['candidates'][0]['content']['parts'][0]['inlineData']['data'])) {
        logMessage("  ERROR: No image data in API response");
        return false;
    }
    
    $generatedImageData = base64_decode($response['candidates'][0]['content']['parts'][0]['inlineData']['data']);
    
    if (file_put_contents($outputPath, $generatedImageData)) {
        logMessage("  ✓ Mockup image generated successfully by Gemini AI");
        return true;
    }
    
    logMessage("  ERROR: Failed to save generated image");
    return false;
}


// Function to upload image using the API with metadata
function uploadImageToProduct($productId, $imagePath, $mockupType, $productInfo, $token = '') {
    if (!file_exists($imagePath)) {
        return ['success' => false, 'error' => 'Image file not found'];
    }
    
    // Use the correct API endpoint
    $apiUrl = 'https://api.invoicemate.in/public/api/products/images/add';
    
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

// How many products still need mockups
$remainingProducts = 0;
$countResult = mysqli_query($connect, "SELECT COUNT(*) AS total FROM products WHERE is_temp = 0 AND is_processed = 0");
if ($countResult) {
    $remainingProducts = (int) (mysqli_fetch_assoc($countResult)['total'] ?? 0);
    logMessage("Products remaining to process (mockups): {$remainingProducts}");
    logMessage("");
}

// Step 1: Get one unprocessed product
$query = "SELECT * FROM products WHERE is_temp = 0 AND is_processed = 0 LIMIT 1";
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

// Step 2: Get the first image for this product
$imageQuery = "SELECT * FROM product_images WHERE product_id = ? ORDER BY id ASC LIMIT 1";
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

// Step 3: Download the original artwork
$originalImagePath = $TEMP_DIR . 'original_' . $product['id'] . '.jpg';
logMessage("Downloading artwork...");

if (!downloadImage($imageUrl, $originalImagePath)) {
    logMessage("ERROR: Failed to download image from: {$imageUrl}");
    exit;
}

logMessage("Artwork downloaded successfully to: {$originalImagePath}");
logMessage("");

// Step 3.25: Resize artwork once for mockup image calls (1024px)
logMessage("Optimizing artwork for AI payload...");
$optimizedArtworkData = resizeImageForAI($originalImagePath, 1024);
if (!$optimizedArtworkData) {
    logMessage("ERROR: Failed to optimize artwork for AI usage");
    exit;
}
logMessage("Artwork optimized (max dimension 1024px) for AI requests.");
logMessage("");

// Step 3.5: Generate AI product name only when current name is generic/empty
$aiProductName = $product['name'];
$aiProductDescription = $product['description'] ?? '';
$aiSuitableFor = null;

// Shared small image for cheap text calls (naming + room selection)
$namingArtworkData = resizeImageForAI($originalImagePath, 512);
if (!$namingArtworkData) {
    $namingArtworkData = $optimizedArtworkData;
}

if (needsAIProductNaming($product['name'])) {
    logMessage("Generating AI-based product name, description, and suitable locations...");
    $aiGenerated = generateAIProductNameAndDescription($GEMINI_API_KEY, $originalImagePath, $namingArtworkData);
    if ($aiGenerated && isset($aiGenerated['name']) && isset($aiGenerated['description'])) {
        logMessage("✓ AI Product Name Generated: {$aiGenerated['name']}");
        logMessage("✓ AI Product Description Generated:");
        $descLines = explode("\n", $aiGenerated['description']);
        foreach ($descLines as $line) {
            logMessage("   " . $line);
        }
        $aiProductName = $aiGenerated['name'];
        $aiProductDescription = $aiGenerated['description'];
        
        if (isset($aiGenerated['suitable_for']) && !empty($aiGenerated['suitable_for'])) {
            $aiSuitableFor = $aiGenerated['suitable_for'];
            logMessage("✓ AI Suitable For Generated: {$aiSuitableFor}");
        } else {
            logMessage("⚠ AI did not generate suitable_for, will use database value or default");
        }
        logAIImageGeneration($connect, [
            'product_id' => $product['id'],
            'generation_type' => 'name_description',
            'prompt_text' => "NAME: {$aiProductName}",
            'model_name' => 'gemini-2.5-flash-lite',
            'images_count' => 0,
            'status' => 'success',
            'source' => 'live',
        ]);
    } else {
        logMessage("⚠ Using original product data");
        logAIImageGeneration($connect, [
            'product_id' => $product['id'],
            'generation_type' => 'name_description',
            'model_name' => 'gemini-2.5-flash-lite',
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
        'model_name' => 'gemini-2.5-flash-lite',
        'images_count' => 0,
        'status' => 'skipped',
        'source' => 'live',
    ]);
}
logMessage("");

// Step 4: Prepare product info (skip separate analysis call)
$productInfo = [
    'name' => $aiProductName,  // Use AI-generated name
    'original_name' => $product['name'],  // Keep original for reference
    'description' => $aiProductDescription,  // Use AI-generated description
    'suitable_for' => $aiSuitableFor ?? $product['suitable_for'] ?? 'home, office, dining, bedroom',  // Use AI-generated if available, else database value, else default
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

// AI picks the 2 best room mockups for this artwork
logMessage("Asking AI which 2 mockup rooms to generate...");
$mockupTypes = selectMockupRoomsWithAI($GEMINI_API_KEY, $namingArtworkData, $productInfo['orientation'] ?? '');
logMessage("AI selected mockup types: " . implode(', ', $mockupTypes));
logAIImageGeneration($connect, [
    'product_id' => $product['id'],
    'generation_type' => 'room_selection',
    'prompt_text' => 'AI selected: ' . implode(', ', $mockupTypes),
    'model_name' => 'gemini-2.5-flash-lite',
    'images_count' => 0,
    'status' => 'success',
    'source' => 'live',
]);
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
                'model_name' => 'gemini-2.5-flash-image',
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
                'model_name' => 'gemini-2.5-flash-image',
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

// Step 8: Mark product as processed ONLY if at least one mockup was successfully uploaded
logMessage("");
if ($uploadedCount > 0) {
    logMessage("Marking product as processed (at least one mockup uploaded successfully)...");
    
    // Reconnect to database (process takes ~2 mins, connection may have timed out)
    @mysqli_close($connect);
    $connect = mysqli_connect($host, $user, $password, $dbname);
    
    if (!$connect) {
        logMessage("✗ Failed to reconnect to database");
    } else {
        $updateQuery = "UPDATE products SET is_processed = 1, updated_at = NOW() WHERE id = ?";
        $stmt = mysqli_prepare($connect, $updateQuery);
        
        if ($stmt === false) {
            logMessage("✗ Failed to prepare statement: " . mysqli_error($connect));
        } else {
            mysqli_stmt_bind_param($stmt, "i", $product['id']);
            
            if (mysqli_stmt_execute($stmt)) {
                logMessage("✓ Product marked as processed successfully!");
            } else {
                logMessage("✗ Failed to execute update: " . mysqli_error($connect));
            }
            mysqli_stmt_close($stmt);
        }
    }
} else {
    logMessage("⚠ Product NOT marked as processed - no mockups were successfully uploaded");
    logMessage("⚠ Product will remain available for processing in the next run");
    
    // Reconnect to database to ensure connection is closed properly
    @mysqli_close($connect);
    $connect = mysqli_connect($host, $user, $password, $dbname);
}

// Summary
$remainingAfter = max(0, $remainingProducts - ($uploadedCount > 0 ? 1 : 0));
logMessage("");
logMessage("=== Processing Complete ===");
logMessage("Product ID: {$product['id']}");
logMessage("Product Name: {$product['name']}");
logMessage("Mockups Generated: {$mockupsGenerated}");
logMessage("Mockups Uploaded: {$uploadedCount}");
logMessage("Status: " . ($uploadedCount > 0 ? "Marked as processed" : "NOT processed - will retry in next run"));
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
echo "<li>Status: <span class='" . ($uploadedCount > 0 ? "success" : "error") . "'>" . ($uploadedCount > 0 ? "Processed" : "NOT Processed - Will Retry") . "</span></li>";
echo "<li>Products remaining: <span class='info'>{$remainingAfter}</span> (was {$remainingProducts})</li>";
echo "</ul>";
echo "<p><a href='generate_product_mockups_v2.php?GEMINI_API_KEY={$GEMINI_API_KEY}'>Process Next Product</a></p>";
echo "</body></html>";
?>

