-- ============================================================
-- 博客文章点赞系统 - 数据库结构
-- 版本: v1.0.0
-- 兼容: MySQL 5.7+ / 8.x / MariaDB 10.2+
-- 字符集: utf8mb4 / utf8mb4_unicode_ci
--
-- 用法（三选一）：
--   1) phpMyAdmin 打开你的数据库 → 导入 → 选择本文件 → 执行
--   2) 命令行：mysql -h主机 -u用户名 -p 数据库名 < schema.sql
--   3) 虚拟主机面板自带的 SQL 执行窗口，整段粘贴执行
--
-- 本脚本可重复执行，不会破坏已有数据。
-- ============================================================

SET NAMES utf8mb4;
SET time_zone = '+08:00';

-- ------------------------------------------------------------
-- 表 1：article_likes —— 每篇文章的总点赞数
-- 一篇文章一行。用 article_id 做主键，天然保证"一篇文章只有一个计数"。
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `article_likes` (
  `article_id` VARCHAR(191) NOT NULL COMMENT '文章唯一标识，建议用 slug，如 hello-world',
  `like_count` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '累计点赞数',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '首次被点赞的时间',
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '最后一次被点赞的时间',
  PRIMARY KEY (`article_id`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='文章点赞总数';

-- ------------------------------------------------------------
-- 表 2：like_records —— 点赞明细，用来防重复点赞
-- (article_id, visitor_hash) 建唯一索引：数据库层面锁死"一人一篇一赞"。
-- 即使前端被绕过、并发请求同时打进来，唯一索引也会兜住。
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `like_records` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `article_id` VARCHAR(191) NOT NULL COMMENT '文章唯一标识',
  `visitor_hash` CHAR(64) NOT NULL COMMENT 'sha256(visitor_id + salt)，不存原始访客标识',
  `ip_hash` CHAR(64) NULL COMMENT 'sha256(IP + salt)，用于限流，可空',
  `user_agent_hash` CHAR(64) NULL COMMENT 'sha256(User-Agent + salt)，仅作分析，可空',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '点赞时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_article_visitor` (`article_id`, `visitor_hash`),
  KEY `idx_article_id` (`article_id`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_ip_created` (`ip_hash`, `created_at`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='点赞明细（防重）';

-- ------------------------------------------------------------
-- 自检：执行完应该看到两张表
-- ------------------------------------------------------------
-- SHOW TABLES;
-- DESCRIBE article_likes;
-- DESCRIBE like_records;

-- ------------------------------------------------------------
-- 常用维护语句（按需手动执行，不要盲目跑）
-- ------------------------------------------------------------
-- 看某篇文章的点赞数：
--   SELECT * FROM article_likes WHERE article_id = 'hello-world';
--
-- 看某篇文章最近的点赞记录：
--   SELECT id, article_id, created_at FROM like_records
--   WHERE article_id = 'hello-world' ORDER BY id DESC LIMIT 20;
--
-- 清零某篇文章（同时清掉防重记录，等于"重置"）：
--   DELETE FROM like_records   WHERE article_id = 'hello-world';
--   DELETE FROM article_likes  WHERE article_id = 'hello-world';
--
-- 只改计数、保留防重记录（不推荐，计数会与明细对不上）：
--   UPDATE article_likes SET like_count = 0 WHERE article_id = 'hello-world';
--
-- 校对：统计每篇文章"明细条数"与"计数"是否一致，正常应该查不出任何行
--   SELECT l.article_id, l.like_count, COUNT(r.id) AS real_count
--   FROM article_likes l
--   LEFT JOIN like_records r ON r.article_id = l.article_id
--   GROUP BY l.article_id, l.like_count
--   HAVING l.like_count <> COUNT(r.id);
