-- Migration: Add Themes Table
-- Description: Creates the themes table for theme management system
-- Date: 2025-11-19

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;

-- Create themes table
CREATE TABLE IF NOT EXISTS `themes` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Theme display name',
  `slug` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Unique theme identifier',
  `version` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT '1.0.0' COMMENT 'Theme version',
  `author` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT 'Admin' COMMENT 'Theme author',
  `description` text COLLATE utf8mb4_unicode_ci COMMENT 'Theme description',
  `active` tinyint(1) DEFAULT '0' COMMENT '1 = active theme, 0 = inactive',
  `settings` json DEFAULT NULL COMMENT 'Theme settings (colors, typography, logo, advanced)',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`),
  KEY `idx_active` (`active`),
  KEY `idx_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Theme management system';

--
-- Default theme data
--

INSERT INTO `themes` (`name`, `slug`, `version`, `author`, `description`, `active`, `settings`) VALUES
-- Theme 1: Pinakes Classic (Active by default)
('Pinakes Classic', 'default', '1.0.0', 'Pinakes Team', 'Tema predefinito con colori magenta originali di Pinakes', 1, '{"colors": {"primary": "#d70161", "secondary": "#111827", "button": "#d70262", "button_text": "#ffffff"}, "typography": {"font_family": "system-ui, sans-serif", "font_size_base": "16px"}, "logo": {"url": "", "width": "auto", "height": "50px"}, "advanced": {"custom_css": "", "custom_js": ""}}'),

-- Theme 2: Minimal Black & White
('Minimal', 'minimal-bw', '1.0.0', 'Pinakes Team', 'Design minimale con nero, grigio e bianco per un look pulito ed elegante', 0, '{"colors": {"primary": "#404040", "secondary": "#000000", "button": "#808080", "button_text": "#ffffff"}, "typography": {"font_family": "system-ui, sans-serif", "font_size_base": "16px"}, "logo": {"url": "", "width": "auto", "height": "50px"}, "advanced": {"custom_css": "", "custom_js": ""}}'),

-- Theme 3: Ocean Blue
('Ocean Blue', 'ocean-blue', '1.0.0', 'Pinakes Team', 'Tonalità blu oceano moderne e professionali per un\'interfaccia fresca', 0, '{"colors": {"primary": "#0284c7", "secondary": "#0c4a6e", "button": "#0ea5e9", "button_text": "#ffffff"}, "typography": {"font_family": "system-ui, sans-serif", "font_size_base": "16px"}, "logo": {"url": "", "width": "auto", "height": "50px"}, "advanced": {"custom_css": "", "custom_js": ""}}'),

-- Theme 4: Forest Green
('Forest Green', 'forest-green', '1.0.0', 'Pinakes Team', 'Verde smeraldo naturale che richiama la tranquillità della natura', 0, '{"colors": {"primary": "#059669", "secondary": "#064e3b", "button": "#10b981", "button_text": "#ffffff"}, "typography": {"font_family": "system-ui, sans-serif", "font_size_base": "16px"}, "logo": {"url": "", "width": "auto", "height": "50px"}, "advanced": {"custom_css": "", "custom_js": ""}}'),

-- Theme 5: Sunset Orange
('Sunset Orange', 'sunset-orange', '1.0.0', 'Pinakes Team', 'Arancione caldo e accogliente ispirato ai tramonti mediterranei', 0, '{"colors": {"primary": "#ea580c", "secondary": "#7c2d12", "button": "#f97316", "button_text": "#ffffff"}, "typography": {"font_family": "system-ui, sans-serif", "font_size_base": "16px"}, "logo": {"url": "", "width": "auto", "height": "50px"}, "advanced": {"custom_css": "", "custom_js": ""}}'),

-- Theme 6: Burgundy Red
('Burgundy', 'burgundy-red', '1.0.0', 'Pinakes Team', 'Rosso borgogna elegante e raffinato per un look sofisticato', 0, '{"colors": {"primary": "#be123c", "secondary": "#881337", "button": "#e11d48", "button_text": "#ffffff"}, "typography": {"font_family": "system-ui, sans-serif", "font_size_base": "16px"}, "logo": {"url": "", "width": "auto", "height": "50px"}, "advanced": {"custom_css": "", "custom_js": ""}}'),

-- Theme 7: Teal Professional
('Teal Professional', 'teal-professional', '1.0.0', 'Pinakes Team', 'Verde acqua professionale perfetto per ambienti corporate', 0, '{"colors": {"primary": "#0d9488", "secondary": "#134e4a", "button": "#14b8a6", "button_text": "#ffffff"}, "typography": {"font_family": "system-ui, sans-serif", "font_size_base": "16px"}, "logo": {"url": "", "width": "auto", "height": "50px"}, "advanced": {"custom_css": "", "custom_js": ""}}'),

-- Theme 8: Slate Gray
('Slate Gray', 'slate-gray', '1.0.0', 'Pinakes Team', 'Grigio ardesia moderno per un design neutro e contemporaneo', 0, '{"colors": {"primary": "#475569", "secondary": "#1e293b", "button": "#64748b", "button_text": "#ffffff"}, "typography": {"font_family": "system-ui, sans-serif", "font_size_base": "16px"}, "logo": {"url": "", "width": "auto", "height": "50px"}, "advanced": {"custom_css": "", "custom_js": ""}}'),

-- Theme 9: Coral Warm
('Coral Warm', 'coral-warm', '1.0.0', 'Pinakes Team', 'Tonalità corallo calde e invitanti per un\'atmosfera accogliente', 0, '{"colors": {"primary": "#f43f5e", "secondary": "#9f1239", "button": "#fb7185", "button_text": "#ffffff"}, "typography": {"font_family": "system-ui, sans-serif", "font_size_base": "16px"}, "logo": {"url": "", "width": "auto", "height": "50px"}, "advanced": {"custom_css": "", "custom_js": ""}}'),

-- Theme 10: Navy Classic
('Navy Classic', 'navy-classic', '1.0.0', 'Pinakes Team', 'Blu navy classico e intramontabile per un look istituzionale', 0, '{"colors": {"primary": "#1e40af", "secondary": "#1e3a8a", "button": "#3b82f6", "button_text": "#ffffff"}, "typography": {"font_family": "system-ui, sans-serif", "font_size_base": "16px"}, "logo": {"url": "", "width": "auto", "height": "50px"}, "advanced": {"custom_css": "", "custom_js": ""}}');

/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
