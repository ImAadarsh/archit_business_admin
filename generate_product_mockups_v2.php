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

// Use system temp directory which is always writable
$TEMP_DIR = sys_get_temp_dir() . '/product_mockups/';
$LOG_FILE = sys_get_temp_dir() . '/mockup_generations_log.txt';

// Create temp directory if it doesn't exist
if (!file_exists($TEMP_DIR)) {
    @mkdir($TEMP_DIR, 0777, true);
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
        @mkdir($dir, 0777, true);
    }
    
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
        $result = @file_put_contents($destinationPath, $imageData);
        if ($result !== false) {
            return true;
        }
        logMessage("ERROR: Failed to write image to: {$destinationPath}");
        return false;
    }
    
    logMessage("ERROR: Failed to download image. HTTP Code: {$httpCode}, Error: {$error}");
    return false;
}

// Function to generate AI product name and description using Gemini
function generateAIProductNameAndDescription($apiKey, $artworkPath) {
    // Read and encode the artwork image
    $imageData = file_get_contents($artworkPath);
    if (!$imageData) {
        logMessage("  ERROR: Could not read artwork file for name generation");
        return null;
    }
    
    $imageBase64 = base64_encode($imageData);
    
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
    
    $ch = curl_init("https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key={$apiKey}");
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
function createMockupsParallel($apiKey, $artworkPath, $mockupTypes, $productInfo, $productId) {
    global $TEMP_DIR;
    
    // Read and encode the artwork image once
    $imageData = file_get_contents($artworkPath);
    if (!$imageData) {
        logMessage("  ERROR: Could not read artwork file");
        return [];
    }
    
    $imageBase64 = base64_encode($imageData);
    
    // Get dimensions and frame info
    $dimensions = "";
    if (!empty($productInfo['width']) && !empty($productInfo['height'])) {
        $dimensions = "The artwork dimensions are {$productInfo['width']} x {$productInfo['height']}.";
    }
    
    $isFramed = (isset($productInfo['is_framed']) && $productInfo['is_framed'] == 1);
    $frameInfo = $isFramed ? "This artwork has a frame." : "This is a frameless artwork (canvas or unframed print).";
    
    // Build prompts for each room type
    $prompts = [
        'living_room' => "Create a photorealistic mockup of this artwork displayed in a modern living room setting. {$dimensions} {$frameInfo} The scene should include: a beige/cream colored wall, wooden flooring with visible planks, a comfortable gray sofa visible in the lower portion, a potted plant on the left side, and a coffee table edge. The artwork should be centered on the wall at eye level with realistic shadows. Ensure the artwork image is preserved exactly as shown - do not modify the artwork itself, only composite it into the room scene. Professional interior photography style with natural lighting.",
        
        'gallery' => "Create a photorealistic mockup of this artwork displayed in a minimalist art gallery. {$dimensions} {$frameInfo} The scene should include: pristine white walls, polished concrete gray floor, white baseboard trim, track lighting visible at the top, a modern gallery bench on the left side. The artwork should be centered on the wall with gallery-standard spacing and professional lighting creating a subtle glow. Preserve the artwork exactly as shown - only composite it into the gallery environment. Museum-quality presentation.",
        
        'office' => "Create a photorealistic mockup of this artwork displayed in a contemporary office environment. {$dimensions} {$frameInfo} The scene should include: light gray walls, gray carpet flooring, a wooden desk visible in the lower portion with a laptop on the left and monitor on the right, a modern desk lamp. The artwork should be centered on the wall above the desk at appropriate height with realistic shadows. Keep the original artwork unchanged - only place it in the office scene. Professional workspace photography with neutral lighting.",
        
        'bedroom' => "Create a photorealistic mockup of this artwork displayed in a serene bedroom. {$dimensions} {$frameInfo} The scene should include: soft beige/cream walls, carpeted floor, an upholstered headboard with tufting buttons visible below, a wooden nightstand on the left with a table lamp creating warm ambient lighting. The artwork should be centered above the headboard at proper height with realistic shadows. Preserve the artwork exactly - only integrate it into the bedroom scene. Cozy interior photography with warm, soft lighting."
    ];
    
    $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash-image:generateContent?key=" . $apiKey;
    
    // Prepare all curl handles
    $multiHandle = curl_multi_init();
    $curlHandles = [];
    $mockupData = [];
    
    foreach ($mockupTypes as $mockupType) {
        $prompt = $prompts[$mockupType] ?? $prompts['gallery'];
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
            $results[] = ['type' => $mockupType, 'success' => false];
        }
        
        curl_multi_remove_handle($multiHandle, $ch);
        curl_close($ch);
    }
    
    curl_multi_close($multiHandle);
    
    return $results;
}

// Function to create mockup using Gemini Image Generation API
function createMockupWithGeminiAPI($apiKey, $artworkPath, $mockupType, $outputPath, $productInfo, $aiAnalysis = null) {
    // Read and encode the artwork image
    $imageData = file_get_contents($artworkPath);
    if (!$imageData) {
        logMessage("  ERROR: Could not read artwork file");
        return false;
    }
    
    $imageBase64 = base64_encode($imageData);
    
    // Get dimensions
    $dimensions = "";
    if (!empty($productInfo['width']) && !empty($productInfo['height'])) {
        $dimensions = "The artwork dimensions are {$productInfo['width']} x {$productInfo['height']}.";
    }
    
    // Check if framed
    $isFramed = (isset($productInfo['is_framed']) && $productInfo['is_framed'] == 1);
    $frameInfo = $isFramed ? "This artwork has a frame." : "This is a frameless artwork (canvas or unframed print).";
    
    // Build comprehensive prompt for each room type
    $prompts = [
        'living_room' => "Create a photorealistic mockup of this artwork displayed in a modern living room setting. {$dimensions} {$frameInfo} The scene should include: a beige/cream colored wall, wooden flooring with visible planks, a comfortable gray sofa visible in the lower portion, a potted plant on the left side, and a coffee table edge. The artwork should be centered on the wall at eye level with realistic shadows. Ensure the artwork image is preserved exactly as shown - do not modify the artwork itself, only composite it into the room scene. Professional interior photography style with natural lighting.",
        
        'gallery' => "Create a photorealistic mockup of this artwork displayed in a minimalist art gallery. {$dimensions} {$frameInfo} The scene should include: pristine white walls, polished concrete gray floor, white baseboard trim, track lighting visible at the top, a modern gallery bench on the left side. The artwork should be centered on the wall with gallery-standard spacing and professional lighting creating a subtle glow. Preserve the artwork exactly as shown - only composite it into the gallery environment. Museum-quality presentation.",
        
        'office' => "Create a photorealistic mockup of this artwork displayed in a contemporary office environment. {$dimensions} {$frameInfo} The scene should include: light gray walls, gray carpet flooring, a wooden desk visible in the lower portion with a laptop on the left and monitor on the right, a modern desk lamp. The artwork should be centered on the wall above the desk at appropriate height with realistic shadows. Keep the original artwork unchanged - only place it in the office scene. Professional workspace photography with neutral lighting.",
        
        'bedroom' => "Create a photorealistic mockup of this artwork displayed in a serene bedroom. {$dimensions} {$frameInfo} The scene should include: soft beige/cream walls, carpeted floor, an upholstered headboard with tufting buttons visible below, a wooden nightstand on the left with a table lamp creating warm ambient lighting. The artwork should be centered above the headboard at proper height with realistic shadows. Preserve the artwork exactly - only integrate it into the bedroom scene. Cozy interior photography with warm, soft lighting."
    ];
    
    $prompt = $prompts[$mockupType] ?? $prompts['gallery'];
    
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
        'gallery' => 'Art Gallery Display',
        'office' => 'Office Environment',
        'bedroom' => 'Bedroom Decor'
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

// Step 3.5: Generate AI product name (3-5 words) and description
logMessage("Generating AI-based product name and description...");
$aiGenerated = generateAIProductNameAndDescription($GEMINI_API_KEY, $originalImagePath);
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
    'suitable_for' => $product['suitable_for'] ?? 'home, office, gallery',
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
logMessage("");

// Always generate all 4 standard mockup types
$mockupTypes = ['living_room', 'gallery', 'office', 'bedroom'];
logMessage("Generating mockup types: " . implode(', ', $mockupTypes));
logMessage("");

// Step 5: Generate mockup images (ALL IN PARALLEL!)
logMessage("Generating all 4 mockup images in parallel...");
$startTime = time();

$results = createMockupsParallel($GEMINI_API_KEY, $originalImagePath, $mockupTypes, $productInfo, $product['id']);

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

