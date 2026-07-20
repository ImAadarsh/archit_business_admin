<?php
/**
 * One-time: create ai_image_generations + backfill from product_images.
 * Usage: php backfill_ai_image_generations.php
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/admin/ai_generation_log.php';

header('Content-Type: text/plain; charset=utf-8');

// Prefer business connect when available; fall back to shop-app DB credentials
$connect = null;
$connectFile = __DIR__ . '/admin/connect.php';
if (file_exists($connectFile)) {
    require_once $connectFile;
}
if (!$connect) {
    // Live shop / invoicemate DB (same products data used by generators on server)
    $host = getenv('DATABASE_HOST') ?: '82.25.121.184';
    $user = getenv('DATABASE_USER') ?: 'u262009927_invoicemate';
    $password = getenv('DATABASE_PASSWORD') ?: '1@Endeavour07791';
    $dbname = getenv('DATABASE_NAME') ?: 'u262009927_invoicemate';
    $connect = @mysqli_connect($host, $user, $password, $dbname);
}

if (!$connect) {
    die("DB connection failed: " . mysqli_connect_error() . "\n");
}

echo "Connected to DB successfully\n";
echo "=== Create table ===\n";
if (!ensureAIImageGenerationsTable($connect)) {
    die("Failed to create table: " . mysqli_error($connect) . "\n");
}
echo "OK\n\n";

// Avoid duplicate backfill
$existing = mysqli_fetch_assoc(mysqli_query($connect, "SELECT COUNT(*) AS c FROM ai_image_generations WHERE source='backfill'"));
if ((int) ($existing['c'] ?? 0) > 0) {
    echo "Backfill already present ({$existing['c']} rows). Skipping insert.\n";
    echo "Delete WHERE source='backfill' if you want to re-run.\n\n";
} else {
    echo "=== Backfill from product_images (mockup + just_product) ===\n";

    $sql = "INSERT INTO ai_image_generations
        (product_id, product_image_id, generation_type, mockup_type, prompt_text, model_name, image_path, images_count, status, source, generated_at)
        SELECT
            pi.product_id,
            pi.id,
            CASE WHEN pi.just_product = 1 THEN 'just_product' ELSE 'mockup' END,
            CASE
                WHEN pi.just_product = 1 THEN NULL
                WHEN LOWER(IFNULL(pi.mockup_description,'')) LIKE '%living%' THEN 'living_room'
                WHEN LOWER(IFNULL(pi.mockup_description,'')) LIKE '%dining%' THEN 'dining_room'
                WHEN LOWER(IFNULL(pi.mockup_description,'')) LIKE '%office%' THEN 'office'
                WHEN LOWER(IFNULL(pi.mockup_description,'')) LIKE '%bedroom%' THEN 'bedroom'
                WHEN LOWER(IFNULL(pi.mockup_description,'')) LIKE '%corridor%' THEN 'corridor'
                WHEN LOWER(IFNULL(pi.mockup_description,'')) LIKE '%stair%' THEN 'staircase'
                WHEN LOWER(IFNULL(pi.mockup_description,'')) LIKE '%entry%' THEN 'entryway'
                ELSE NULL
            END,
            NULL,
            'gemini-2.5-flash-image',
            pi.image,
            1,
            'success',
            'backfill',
            IFNULL(pi.created_at, NOW())
        FROM product_images pi
        WHERE pi.is_mockup = 1 OR pi.just_product = 1";

    if (!mysqli_query($connect, $sql)) {
        die("Backfill failed: " . mysqli_error($connect) . "\n");
    }
    echo "Inserted rows: " . mysqli_affected_rows($connect) . "\n\n";
}

echo "=== Summary ===\n";
$q = mysqli_query($connect, "SELECT generation_type, source, COUNT(*) AS images, COUNT(DISTINCT product_id) AS products
    FROM ai_image_generations GROUP BY generation_type, source ORDER BY generation_type, source");
while ($row = mysqli_fetch_assoc($q)) {
    echo "{$row['generation_type']} | {$row['source']} | images={$row['images']} | products={$row['products']}\n";
}

echo "\n=== By month (success) ===\n";
$q = mysqli_query($connect, "SELECT DATE_FORMAT(generated_at,'%Y-%m') AS ym, generation_type, COUNT(*) AS cnt
    FROM ai_image_generations WHERE status='success'
    GROUP BY ym, generation_type ORDER BY ym, generation_type");
while ($row = mysqli_fetch_assoc($q)) {
    echo "{$row['ym']} | {$row['generation_type']} | {$row['cnt']}\n";
}

echo "\n=== Remaining queue ===\n";
$mockupLeft = mysqli_fetch_assoc(mysqli_query($connect, "SELECT COUNT(*) AS c FROM products WHERE is_temp=0 AND is_processed=0"));
$justLeft = mysqli_fetch_assoc(mysqli_query($connect, "SELECT COUNT(DISTINCT p.id) AS c FROM products p
    INNER JOIN product_images pi ON p.id=pi.product_id
    WHERE p.is_temp=0 AND p.just_product_processed=0
    AND NOT EXISTS (SELECT 1 FROM product_images pi2 WHERE pi2.product_id=p.id AND pi2.just_product=1)"));
echo "Mockups remaining: {$mockupLeft['c']}\n";
echo "Just-product remaining: {$justLeft['c']}\n";

echo "\nDone.\n";
