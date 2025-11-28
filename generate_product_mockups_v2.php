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
    
    // Prompt for generating product name and description
    $prompt = "Analyze this artwork and generate:
1. A short, descriptive product name (3-5 words maximum)
2. A product description (2 lines with bullet points)

Format your response EXACTLY as follows:
NAME: [product name here]
DESCRIPTION: [First line describing the artwork style and subject]
• [Key feature or characteristic 1]
• [Key feature or characteristic 2]
• [Key feature or characteristic 3]

Example:
NAME: Modern Abstract Wall Art
DESCRIPTION: A striking contemporary piece featuring bold geometric shapes and vibrant colors that add energy to any space.
• Perfect for modern and minimalist interiors
• High-quality print with vivid color reproduction
• Ideal for living rooms, offices, and galleries";
    
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
            'maxOutputTokens' => 1000
        ]
    ];
    
    $ch = curl_init("https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash-lite-001:generateContent?key={$apiKey}");
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
            
            // Extract NAME
            if (preg_match('/NAME:\s*(.+?)(?:\n|DESCRIPTION:)/is', $generatedText, $nameMatch)) {
                $name = trim($nameMatch[1]);
                $name = trim($name, '"\'');
                logMessage("  DEBUG: Extracted Name: {$name}");
            } else {
                logMessage("  DEBUG: Failed to extract NAME from response");
            }
            
            // Extract DESCRIPTION (everything after "DESCRIPTION:")
            if (preg_match('/DESCRIPTION:\s*(.+?)$/is', $generatedText, $descMatch)) {
                $description = trim($descMatch[1]);
                logMessage("  DEBUG: Extracted Description: " . substr($description, 0, 100) . "...");
            } else {
                logMessage("  DEBUG: Failed to extract DESCRIPTION from response");
            }
            
            if ($name && $description) {
                return [
                    'name' => $name,
                    'description' => $description
                ];
            } else {
                logMessage("  DEBUG: Parsing failed - Name: " . ($name ? "OK" : "NULL") . ", Description: " . ($description ? "OK" : "NULL"));
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
    
    // Get dimensions and frame info
    $dimensions = "";
    if (!empty($productInfo['width']) && !empty($productInfo['height'])) {
        $dimensions = "The artwork dimensions are {$productInfo['width']} x {$productInfo['height']}.";
    }
    
    $isFramed = (isset($productInfo['is_framed']) && $productInfo['is_framed'] == 1);
    $frameInfo = $isFramed ? "This artwork has a frame. Make sure the Same frame is visible in the mockup. and also remove the black croners from the frame." : "This is a frameless artwork (canvas or unframed print).";
    
    // Check if vertical orientation
    $isVertical = (isset($productInfo['orientation']) && strtolower($productInfo['orientation']) === 'vertical');
    
    // Build prompts for each room type - different prompts for vertical images
    if ($isVertical) {
        // Vertical-specific prompts with corridor/staircase/entryway themes
        $prompts = [
            'corridor' => "Analyze the provided artwork's colors, subject, mood, and style. Create a photorealistic mockup featuring a modern corridor with clean lines and minimalistic decor, featuring a tall vertical painting on the wall. {$dimensions} {$frameInfo} The scene should include: a beige or warm neutral wall whose tone harmonizes with the dominant colors of the artwork, wooden flooring with visible planks, curated decor such as a potted plant or minimalist furniture that echoes the palette. Keep lighting natural and directional to highlight the vertical artwork. Place the artwork centered on the wall at eye level with realistic shadows. Preserve the artwork exactly as provided and compose the corridor so all styling decisions feel intentionally inspired by the artwork.",
            
            'staircase' => "Analyze the artwork's palette, mood, and visual style. Create a photorealistic mockup featuring an elegant staircase area with warm lighting, showcasing a vertical painting that complements the ambiance. {$dimensions} {$frameInfo} Compose the scene with a warm neutral wall tuned to complement the art, elegant staircase with wooden or marble steps, and warm ambient lighting that highlights the vertical artwork. Include decorative elements such as plants or artwork accessories that mirror the artwork's hues. Hang the vertical artwork centered on the wall at proper height, taking advantage of the vertical space. Use soft ambient lighting with gentle shadows. The artwork itself must remain unchanged—only integrate it seamlessly into this tailored staircase environment.",
            
            'entryway' => "Study the artwork's palette and atmosphere. Design a contemporary mockup featuring a stylish entryway with high ceilings, where a vertical painting adds character to the space. {$dimensions} {$frameInfo} The scene should include light neutral walls whose undertone complements the artwork, a modern entryway with high ceilings, and accessories such as plants, lighting fixtures, or decorative elements whose colors and materials mirror elements from the artwork. Ensure the vertical artwork is centered on the wall at an ergonomic viewing height with realistic shadowing, taking advantage of the vertical space. Preserve the artwork exactly—only style the entryway environment to look professionally curated around it with cohesive color accents and balanced lighting."
        ];
    } else {
        // Standard horizontal/landscape prompts
        $prompts = [
            'living_room' => "Analyze the provided artwork's colors, subject, mood, and style. Create a photorealistic living room mockup that complements the artwork. {$dimensions} {$frameInfo} The scene should include: a beige or warm neutral wall whose tone harmonizes with the dominant colors of the artwork, wooden flooring with visible planks, a contemporary sofa whose upholstery reflects one of the accent colors from the artwork, curated decor such as a potted plant, coffee table edge, or throw blanket that echoes the palette. Keep lighting natural and directional to highlight the artwork. Place the artwork centered on the wall at eye level with realistic shadows. Preserve the artwork exactly as provided and compose the room so all styling decisions feel intentionally inspired by the artwork.",
            
            'dining_room' => "Analyze the artwork's palette, mood, and visual style. Create a photorealistic dining room mockup that feels custom-designed around the artwork. {$dimensions} {$frameInfo} Compose the scene with a warm neutral wall tuned to complement the art, a natural wood dining table with at least four upholstered chairs whose fabrics pick up secondary colors from the artwork, and a contemporary pendant light or chandelier centered above the table. Style the tabletop with dinnerware or minimalist centerpieces that mirror the artwork's hues. Include glimpses of a sideboard or cabinet styled with accessories influenced by the art. Hang the artwork centered above the sideboard or table at proper height. Use soft ambient lighting with gentle shadows. The artwork itself must remain unchanged—only integrate it seamlessly into this tailored dining environment.",
                    
            'office' => "Study the artwork's palette and atmosphere. Design a contemporary office mockup that integrates the piece as a focal point. {$dimensions} {$frameInfo} The scene should include light neutral walls whose undertone complements the artwork, a modern desk with technology (laptop, monitor) arranged neatly, and accessories such as notebooks, lamp, or plant whose colors and materials mirror elements from the artwork. Ensure the artwork is centered above the desk at an ergonomic viewing height with realistic shadowing. Preserve the artwork exactly—only style the office environment to look professionally curated around it with cohesive color accents and balanced lighting.",
            
            'bedroom' => "Evaluate the artwork's color story, mood, and texture. Create a photorealistic serene bedroom mockup inspired by these qualities. {$dimensions} {$frameInfo} Feature a softly toned wall that harmonizes with the art, an upholstered headboard or bed linens that pick up secondary colors from the piece, and a wooden nightstand with lighting that reinforces the artwork's ambiance (warm for cozy scenes, cooler for calm minimalism). Include decor elements—pillows, throws, plants—that subtly reference the artwork. Position the artwork centered above the headboard with realistic shadows. Keep the artwork untouched and ensure the entire bedroom styling feels intentionally derived from the artwork's design language."
        ];
    }
    
    $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash-image:generateContent?key=" . $apiKey;
    
    // Prepare all curl handles
    $multiHandle = curl_multi_init();
    $curlHandles = [];
    $mockupData = [];
    
    foreach ($mockupTypes as $mockupType) {
        // Get default prompt based on orientation
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
            
            // Log full response for debugging (especially for 429 rate limit errors)
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
    
    // Get dimensions
    $dimensions = "";
    if (!empty($productInfo['width']) && !empty($productInfo['height'])) {
        $dimensions = "The artwork dimensions are {$productInfo['width']} x {$productInfo['height']}.";
    }
    
    // Check if framed
    $isFramed = (isset($productInfo['is_framed']) && $productInfo['is_framed'] == 1);
    $frameInfo = $isFramed ? "This artwork has a frame. Make sure the Same frame is visible in the mockup. and also remove the black croners from the frame. Also Check the orientation of the artwork is according to the correct viewing angle of the artwork." : "This is a frameless artwork (canvas or unframed print).";
    
    // Check if vertical orientation
    $isVertical = (isset($productInfo['orientation']) && strtolower($productInfo['orientation']) === 'vertical');
    
    // Build comprehensive prompt for each room type - different prompts for vertical images
    if ($isVertical) {
        // Vertical-specific prompts with corridor/staircase/entryway themes
        $prompts = [
            'corridor' => "Analyze the provided artwork's colors, subject, mood, and style. Create a photorealistic mockup featuring a modern corridor with clean lines and minimalistic decor, featuring a tall vertical painting on the wall. {$dimensions} {$frameInfo} The scene should include: a beige or warm neutral wall whose tone harmonizes with the dominant colors of the artwork, wooden flooring with visible planks, curated decor such as a potted plant or minimalist furniture that echoes the palette. Keep lighting natural and directional to highlight the vertical artwork. Place the artwork centered on the wall at eye level with realistic shadows. Preserve the artwork exactly as provided and compose the corridor so all styling decisions feel intentionally inspired by the artwork.",
            
            'staircase' => "Analyze the artwork's palette, mood, and visual style. Create a photorealistic mockup featuring an elegant staircase area with warm lighting, showcasing a vertical painting that complements the ambiance. {$dimensions} {$frameInfo} Compose the scene with a warm neutral wall tuned to complement the art, elegant staircase with wooden or marble steps, and warm ambient lighting that highlights the vertical artwork. Include decorative elements such as plants or artwork accessories that mirror the artwork's hues. Hang the vertical artwork centered on the wall at proper height, taking advantage of the vertical space. Use soft ambient lighting with gentle shadows. The artwork itself must remain unchanged—only integrate it seamlessly into this tailored staircase environment.",
            
            'entryway' => "Study the artwork's palette and atmosphere. Design a contemporary mockup featuring a stylish entryway with high ceilings, where a vertical painting adds character to the space. {$dimensions} {$frameInfo} The scene should include light neutral walls whose undertone complements the artwork, a modern entryway with high ceilings, and accessories such as plants, lighting fixtures, or decorative elements whose colors and materials mirror elements from the artwork. Ensure the vertical artwork is centered on the wall at an ergonomic viewing height with realistic shadowing, taking advantage of the vertical space. Preserve the artwork exactly—only style the entryway environment to look professionally curated around it with cohesive color accents and balanced lighting."
        ];
    } else {
        // Standard horizontal/landscape prompts
        $prompts = [
            'living_room' => "Analyze the provided artwork's colors, subject, mood, and style. Create a photorealistic living room mockup that complements the artwork. {$dimensions} {$frameInfo} The scene should include: a beige or warm neutral wall whose tone harmonizes with the dominant colors of the artwork, wooden flooring with visible planks, a contemporary sofa whose upholstery reflects one of the accent colors from the artwork, curated decor such as a potted plant, coffee table edge, or throw blanket that echoes the palette. Keep lighting natural and directional to highlight the artwork. Place the artwork centered on the wall at eye level with realistic shadows. Preserve the artwork exactly as provided and compose the room so all styling decisions feel intentionally inspired by the artwork.",
            
            'dining_room' => "Analyze the artwork's palette, mood, and visual style. Create a photorealistic dining room mockup that feels custom-designed around the artwork. {$dimensions} {$frameInfo} Compose the scene with a warm neutral wall tuned to complement the art, a natural wood dining table with at least four upholstered chairs whose fabrics pick up secondary colors from the artwork, and a contemporary pendant light or chandelier centered above the table. Style the tabletop with dinnerware or minimalist centerpieces that mirror the artwork's hues. Include glimpses of a sideboard or cabinet styled with accessories influenced by the art. Hang the artwork centered above the sideboard or table at proper height. Use soft ambient lighting with gentle shadows. The artwork itself must remain unchanged—only integrate it seamlessly into this tailored dining environment.",
            
            'office' => "Study the artwork's palette and atmosphere. Design a contemporary office mockup that integrates the piece as a focal point. {$dimensions} {$frameInfo} The scene should include light neutral walls whose undertone complements the artwork, a modern desk with technology (laptop, monitor) arranged neatly, and accessories such as notebooks, lamp, or plant whose colors and materials mirror elements from the artwork. Ensure the artwork is centered above the desk at an ergonomic viewing height with realistic shadowing. Preserve the artwork exactly—only style the office environment to look professionally curated around it with cohesive color accents and balanced lighting.",
            
            'bedroom' => "Evaluate the artwork's color story, mood, and texture. Create a photorealistic serene bedroom mockup inspired by these qualities. {$dimensions} {$frameInfo} Feature a softly toned wall that harmonizes with the art, an upholstered headboard or bed linens that pick up secondary colors from the piece, and a wooden nightstand with lighting that reinforces the artwork's ambiance (warm for cozy scenes, cooler for calm minimalism). Include decor elements—pillows, throws, plants—that subtly reference the artwork. Position the artwork centered above the headboard with realistic shadows. Keep the artwork untouched and ensure the entire bedroom styling feels intentionally derived from the artwork's design language."
        ];
    }
    
    // Get default prompt based on orientation
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
        logMessage("  Response: " . substr($result, 0, 500));
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
        logMessage("  ✓ Mockup image generated successfully by Gemini AI");
        return true;
    } else {
        logMessage("  ERROR: Failed to save generated image");
        return false;
    }
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

// Step 1: Get one unprocessed product
$query = "SELECT * FROM products WHERE is_temp = 0 AND is_processed = 0 LIMIT 1";
$result = mysqli_query($connect, $query);

if (!$result || mysqli_num_rows($result) === 0) {
    logMessage("No unprocessed products found.");
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

// Step 3.25: Resize artwork once for all AI calls
logMessage("Optimizing artwork for AI payload...");
$optimizedArtworkData = resizeImageForAI($originalImagePath, 1024);
if (!$optimizedArtworkData) {
    logMessage("ERROR: Failed to optimize artwork for AI usage");
    exit;
}
logMessage("Artwork optimized (max dimension 1024px) for AI requests.");
logMessage("");

// Step 3.5: Generate AI product name (3-5 words) and description
logMessage("Generating AI-based product name and description...");
$aiGenerated = generateAIProductNameAndDescription($GEMINI_API_KEY, $originalImagePath, $optimizedArtworkData);
if ($aiGenerated && isset($aiGenerated['name']) && isset($aiGenerated['description'])) {
    logMessage("✓ AI Product Name Generated: {$aiGenerated['name']}");
    logMessage("✓ AI Product Description Generated:");
    // Log description with proper formatting
    $descLines = explode("\n", $aiGenerated['description']);
    foreach ($descLines as $line) {
        logMessage("   " . $line);
    }
    $aiProductName = $aiGenerated['name'];
    $aiProductDescription = $aiGenerated['description'];
} else {
    logMessage("⚠ Using original product data");
    $aiProductName = $product['name'];
    $aiProductDescription = $product['description'] ?? '';
}
logMessage("");

// Step 4: Prepare product info (skip separate analysis call)
$productInfo = [
    'name' => $aiProductName,  // Use AI-generated name
    'original_name' => $product['name'],  // Keep original for reference
    'description' => $aiProductDescription,  // Use AI-generated description
    'suitable_for' => $product['suitable_for'] ?? 'home, office, dining, bedroom',
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

// Determine mockup types based on orientation
$isVertical = (isset($productInfo['orientation']) && strtolower($productInfo['orientation']) === 'vertical');
if ($isVertical) {
    $mockupTypes = ['corridor', 'staircase', 'entryway'];
    logMessage("Vertical artwork detected - generating vertical-specific mockups");
} else {
    $mockupTypes = ['living_room', 'dining_room', 'office', 'bedroom'];
    logMessage("Horizontal/landscape artwork - generating standard room mockups");
}
logMessage("Generating mockup types: " . implode(', ', $mockupTypes));
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
        } else {
            logMessage("  ✗ Upload failed: " . ($uploadResult['error'] ?? 'Unknown error'));
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

// Step 8: Mark product as processed
logMessage("");
logMessage("Marking product as processed...");

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

// Summary
logMessage("");
logMessage("=== Processing Complete ===");
logMessage("Product ID: {$product['id']}");
logMessage("Product Name: {$product['name']}");
logMessage("Mockups Generated: {$mockupsGenerated}");
logMessage("Mockups Uploaded: {$uploadedCount}");
logMessage("Status: Marked as processed");
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
echo "</ul>";
echo "<p><a href='generate_product_mockups_v2.php'>Process Next Product</a></p>";
echo "</body></html>";
?>

