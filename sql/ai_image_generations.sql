-- AI image / prompt generation tracking
-- Run once on the products database (e.g. u262009927_invoicemate)

CREATE TABLE IF NOT EXISTS `ai_image_generations` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `product_id` int(11) NOT NULL,
  `product_image_id` int(11) DEFAULT NULL COMMENT 'FK to product_images.id when known',
  `generation_type` enum('mockup','just_product','name_description','room_selection') NOT NULL,
  `mockup_type` varchar(50) DEFAULT NULL COMMENT 'living_room, corridor, etc.',
  `prompt_text` text DEFAULT NULL,
  `model_name` varchar(100) DEFAULT NULL,
  `image_path` varchar(500) DEFAULT NULL,
  `images_count` int(11) NOT NULL DEFAULT 1 COMMENT 'How many images this row produced (0 for text-only calls)',
  `status` enum('success','failed','skipped') NOT NULL DEFAULT 'success',
  `source` enum('live','backfill') NOT NULL DEFAULT 'live',
  `generated_at` datetime NOT NULL COMMENT 'When this generation happened',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_product_id` (`product_id`),
  KEY `idx_generation_type` (`generation_type`),
  KEY `idx_generated_at` (`generated_at`),
  KEY `idx_product_image_id` (`product_image_id`),
  KEY `idx_source_type` (`source`, `generation_type`),
  KEY `idx_day_type` (`generated_at`, `generation_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- If table already exists without images_count:
-- ALTER TABLE ai_image_generations
--   ADD COLUMN images_count int(11) NOT NULL DEFAULT 1
--   COMMENT 'How many images this row produced (0 for text-only calls)'
--   AFTER image_path;

-- When + how many images per day:
-- SELECT DATE(generated_at) AS day,
--        SUM(CASE WHEN generation_type IN ('mockup','just_product') THEN images_count ELSE 0 END) AS images,
--        SUM(CASE WHEN generation_type='mockup' THEN images_count ELSE 0 END) AS mockups,
--        SUM(CASE WHEN generation_type='just_product' THEN images_count ELSE 0 END) AS just_products,
--        COUNT(DISTINCT product_id) AS products
-- FROM ai_image_generations
-- WHERE status='success'
-- GROUP BY DATE(generated_at)
-- ORDER BY day DESC;
