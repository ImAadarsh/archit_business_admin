<?php
/**
 * AI image generation logging helpers.
 * Requires an active mysqli $connect (or pass $connect into functions).
 */

if (!function_exists('ensureAIImageGenerationsTable')) {
function ensureAIImageGenerationsTable($connect) {
    static $checked = false;
    if ($checked || !$connect) {
        return $checked;
    }
    $sql = "CREATE TABLE IF NOT EXISTS `ai_image_generations` (
      `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
      `product_id` int(11) NOT NULL,
      `product_image_id` int(11) DEFAULT NULL,
      `generation_type` enum('mockup','just_product','name_description','room_selection') NOT NULL,
      `mockup_type` varchar(50) DEFAULT NULL,
      `prompt_text` text DEFAULT NULL,
      `model_name` varchar(100) DEFAULT NULL,
      `image_path` varchar(500) DEFAULT NULL,
      `images_count` int(11) NOT NULL DEFAULT 1,
      `status` enum('success','failed','skipped') NOT NULL DEFAULT 'success',
      `source` enum('live','backfill') NOT NULL DEFAULT 'live',
      `generated_at` datetime NOT NULL,
      `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      KEY `idx_product_id` (`product_id`),
      KEY `idx_generation_type` (`generation_type`),
      KEY `idx_generated_at` (`generated_at`),
      KEY `idx_product_image_id` (`product_image_id`),
      KEY `idx_source_type` (`source`, `generation_type`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    $ok = @mysqli_query($connect, $sql);
    if ($ok) {
        // Upgrade existing tables that predate images_count
        $col = @mysqli_query($connect, "SHOW COLUMNS FROM ai_image_generations LIKE 'images_count'");
        if ($col && mysqli_num_rows($col) === 0) {
            @mysqli_query($connect, "ALTER TABLE ai_image_generations
                ADD COLUMN `images_count` int(11) NOT NULL DEFAULT 1
                COMMENT 'How many images this row produced (0 for text-only calls)'
                AFTER `image_path`");
        }
    }
    $checked = (bool) $ok;
    return $checked;
}
}

if (!function_exists('logAIImageGeneration')) {
/**
 * @param mysqli $connect
 * @param array $data keys: product_id, generation_type, mockup_type?, prompt_text?,
 *               model_name?, image_path?, product_image_id?, images_count?, status?, source?, generated_at?
 */
function logAIImageGeneration($connect, array $data) {
    if (!$connect) {
        return false;
    }
    if (!ensureAIImageGenerationsTable($connect)) {
        return false;
    }

    $productId = (int) ($data['product_id'] ?? 0);
    $generationType = $data['generation_type'] ?? '';
    $allowedTypes = ['mockup', 'just_product', 'name_description', 'room_selection'];
    if ($productId <= 0 || !in_array($generationType, $allowedTypes, true)) {
        return false;
    }

    $productImageId = isset($data['product_image_id']) && $data['product_image_id'] !== null && $data['product_image_id'] !== ''
        ? (int) $data['product_image_id']
        : null;
    $mockupType = $data['mockup_type'] ?? null;
    $promptText = $data['prompt_text'] ?? null;
    $modelName = $data['model_name'] ?? null;
    $imagePath = $data['image_path'] ?? null;
    $status = $data['status'] ?? 'success';
    $source = $data['source'] ?? 'live';
    $generatedAt = $data['generated_at'] ?? date('Y-m-d H:i:s');

    // Default: image rows count as 1; text-only calls count as 0
    if (isset($data['images_count'])) {
        $imagesCount = (int) $data['images_count'];
    } elseif (in_array($generationType, ['mockup', 'just_product'], true) && $status === 'success') {
        $imagesCount = 1;
    } else {
        $imagesCount = 0;
    }

    if (!in_array($status, ['success', 'failed', 'skipped'], true)) {
        $status = 'success';
    }
    if (!in_array($source, ['live', 'backfill'], true)) {
        $source = 'live';
    }

    if ($productImageId === null) {
        $sql = "INSERT INTO ai_image_generations
            (product_id, product_image_id, generation_type, mockup_type, prompt_text, model_name, image_path, images_count, status, source, generated_at)
            VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = mysqli_prepare($connect, $sql);
        if (!$stmt) {
            return false;
        }
        mysqli_stmt_bind_param(
            $stmt,
            'isssssisss',
            $productId,
            $generationType,
            $mockupType,
            $promptText,
            $modelName,
            $imagePath,
            $imagesCount,
            $status,
            $source,
            $generatedAt
        );
    } else {
        $sql = "INSERT INTO ai_image_generations
            (product_id, product_image_id, generation_type, mockup_type, prompt_text, model_name, image_path, images_count, status, source, generated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = mysqli_prepare($connect, $sql);
        if (!$stmt) {
            return false;
        }
        mysqli_stmt_bind_param(
            $stmt,
            'iissssissss',
            $productId,
            $productImageId,
            $generationType,
            $mockupType,
            $promptText,
            $modelName,
            $imagePath,
            $imagesCount,
            $status,
            $source,
            $generatedAt
        );
    }

    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    return $ok;
}
}

if (!function_exists('mapMockupDescriptionToType')) {
function mapMockupDescriptionToType($description) {
    $d = strtolower((string) $description);
    if (strpos($d, 'living') !== false) return 'living_room';
    if (strpos($d, 'dining') !== false) return 'dining_room';
    if (strpos($d, 'office') !== false) return 'office';
    if (strpos($d, 'bedroom') !== false) return 'bedroom';
    if (strpos($d, 'corridor') !== false) return 'corridor';
    if (strpos($d, 'staircase') !== false || strpos($d, 'stair') !== false) return 'staircase';
    if (strpos($d, 'entryway') !== false || strpos($d, 'entry') !== false) return 'entryway';
    return null;
}
}
