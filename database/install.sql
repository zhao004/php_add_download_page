-- NOVA App Download 数据库结构，表前缀由安装器替换 __PREFIX__。

CREATE TABLE `__PREFIX__admin_user` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(64) NOT NULL,
  `password` varchar(255) NOT NULL,
  `last_login_at` datetime DEFAULT NULL,
  `last_login_ip` varchar(45) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_admin_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `__PREFIX__site_config` (
  `id` tinyint unsigned NOT NULL,
  `site_name` varchar(64) NOT NULL DEFAULT 'NOVA',
  `site_slogan` varchar(255) NOT NULL DEFAULT '',
  `app_icon` varchar(512) NOT NULL DEFAULT '',
  `favicon` varchar(512) NOT NULL DEFAULT '',
  `version` varchar(32) NOT NULL DEFAULT '',
  `version_label` varchar(64) NOT NULL DEFAULT '',
  `download_mode` varchar(16) NOT NULL DEFAULT 'local',
  `local_apk_path` varchar(512) NOT NULL DEFAULT '',
  `lanzou_url` varchar(512) NOT NULL DEFAULT '',
  `lanzou_pwd` varchar(255) NOT NULL DEFAULT '',
  `other_url` varchar(2048) NOT NULL DEFAULT '',
  `other_pwd` varchar(255) NOT NULL DEFAULT '',
  `stats_users` varchar(32) NOT NULL DEFAULT '',
  `copyright` varchar(255) NOT NULL DEFAULT '',
  `icp_beian` varchar(128) NOT NULL DEFAULT '',
  `hero_title` varchar(255) NOT NULL DEFAULT '',
  `hero_desc` text NOT NULL,
  `cta_text` varchar(64) NOT NULL DEFAULT '立即下载',
  `theme_color` varchar(7) NOT NULL DEFAULT '#4a9fd8',
  `trusted_proxy_ips` text NOT NULL,
  `log_dedupe_seconds` smallint unsigned NOT NULL DEFAULT 30,
  `record_bots` tinyint unsigned NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `__PREFIX__preview_page` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(128) NOT NULL,
  `image` varchar(512) NOT NULL,
  `sort` int NOT NULL DEFAULT 0,
  `status` tinyint unsigned NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_preview_status_sort` (`status`, `sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `__PREFIX__nav_item` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(64) NOT NULL,
  `url` varchar(512) NOT NULL,
  `target` varchar(16) NOT NULL DEFAULT '_self',
  `sort` int NOT NULL DEFAULT 0,
  `status` tinyint unsigned NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_nav_status_sort` (`status`, `sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `__PREFIX__feature_item` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(128) NOT NULL,
  `description` varchar(512) NOT NULL DEFAULT '',
  `icon` varchar(128) NOT NULL DEFAULT '',
  `link_url` varchar(512) DEFAULT NULL,
  `sort` int NOT NULL DEFAULT 0,
  `status` tinyint unsigned NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_feature_status_sort` (`status`, `sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `__PREFIX__friend_link` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(128) NOT NULL,
  `url` varchar(512) NOT NULL,
  `sort` int NOT NULL DEFAULT 0,
  `status` tinyint unsigned NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_friend_status_sort` (`status`, `sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `__PREFIX__trust_brand` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(128) NOT NULL,
  `logo` varchar(512) NOT NULL DEFAULT '',
  `url` varchar(512) NOT NULL DEFAULT '',
  `sort` int NOT NULL DEFAULT 0,
  `status` tinyint unsigned NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_trust_status_sort` (`status`, `sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `__PREFIX__visit_log` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `ip` varchar(45) NOT NULL,
  `country` varchar(64) NOT NULL DEFAULT '未知',
  `region` varchar(64) NOT NULL DEFAULT '未知',
  `city` varchar(64) NOT NULL DEFAULT '未知',
  `isp` varchar(128) NOT NULL DEFAULT '',
  `region_raw` varchar(255) NOT NULL DEFAULT '',
  `user_agent` varchar(512) NOT NULL DEFAULT '',
  `device_type` varchar(32) NOT NULL DEFAULT 'unknown',
  `referer` varchar(512) NOT NULL DEFAULT '',
  `path` varchar(255) NOT NULL DEFAULT '/',
  `method` varchar(16) NOT NULL DEFAULT 'GET',
  `session_id` varchar(64) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_visit_created_at` (`created_at`),
  KEY `idx_visit_ip` (`ip`),
  KEY `idx_visit_created_ip` (`created_at`, `ip`),
  KEY `idx_visit_city` (`city`),
  KEY `idx_visit_isp` (`isp`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `__PREFIX__download_click_log` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `ip` varchar(45) NOT NULL,
  `country` varchar(64) NOT NULL DEFAULT '未知',
  `region` varchar(64) NOT NULL DEFAULT '未知',
  `city` varchar(64) NOT NULL DEFAULT '未知',
  `isp` varchar(128) NOT NULL DEFAULT '',
  `region_raw` varchar(255) NOT NULL DEFAULT '',
  `user_agent` varchar(512) NOT NULL DEFAULT '',
  `device_type` varchar(32) NOT NULL DEFAULT 'unknown',
  `referer` varchar(512) NOT NULL DEFAULT '',
  `download_mode` varchar(16) NOT NULL,
  `status` varchar(32) NOT NULL DEFAULT 'pending',
  `fail_reason` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_download_created_at` (`created_at`),
  KEY `idx_download_ip` (`ip`),
  KEY `idx_download_status` (`status`),
  KEY `idx_download_created_status` (`created_at`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
