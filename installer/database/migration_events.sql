-- Migration: Add Events Table
-- Description: Creates the events table for managing library events
-- Date: 2025-11-17

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;

-- Create events table
CREATE TABLE IF NOT EXISTS `events` (
  `id` int NOT NULL AUTO_INCREMENT,
  `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Event title',
  `slug` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'URL slug generated from title',
  `content` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT 'Event description (HTML allowed)',
  `event_date` date NOT NULL COMMENT 'Event date',
  `event_time` time DEFAULT NULL COMMENT 'Event time',
  `featured_image` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Featured image path',
  `seo_title` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Custom SEO title',
  `seo_description` text COLLATE utf8mb4_unicode_ci COMMENT 'Custom meta description',
  `seo_keywords` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'SEO keywords (comma-separated)',
  `og_image` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Custom Open Graph image',
  `og_title` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Open Graph Title',
  `og_description` text COLLATE utf8mb4_unicode_ci COMMENT 'Open Graph Description',
  `og_type` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT 'article' COMMENT 'Open Graph Type',
  `og_url` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Open Graph URL',
  `twitter_card` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT 'summary_large_image' COMMENT 'Twitter Card Type',
  `twitter_title` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Twitter Card Title',
  `twitter_description` text COLLATE utf8mb4_unicode_ci COMMENT 'Twitter Card Description',
  `twitter_image` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Twitter Card Image URL',
  `is_active` tinyint(1) DEFAULT '1' COMMENT 'Event visibility status',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`),
  KEY `idx_active` (`is_active`),
  KEY `idx_event_date` (`event_date`),
  KEY `idx_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Library events and activities';

-- Add events page enable/disable setting to system_settings
INSERT INTO `system_settings` (`category`, `setting_key`, `setting_value`, `description`)
VALUES ('cms', 'events_page_enabled', '1', 'Enable or disable the events page on the frontend')
ON DUPLICATE KEY UPDATE `setting_value` = '1';

/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
