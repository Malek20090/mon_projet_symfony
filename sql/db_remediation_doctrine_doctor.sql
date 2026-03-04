-- Doctrine Doctor remediation helpers for MariaDB/MySQL.
-- Database: decides_db

-- ============================================================
-- 1) Collation normalization (table-level) with one target collation
-- ============================================================
-- Keep this in sync with the database default.
-- Alternative for better linguistic sorting (slightly slower): utf8mb4_unicode_ci
SET @target_charset := 'utf8mb4';
SET @target_collation := 'utf8mb4_general_ci';
SET @db_name := DATABASE();

-- Ensure database default matches target collation.
SET @sql_db := CONCAT(
    'ALTER DATABASE `', @db_name, '` CHARACTER SET ', @target_charset,
    ' COLLATE ', @target_collation
);
PREPARE stmt_db FROM @sql_db;
EXECUTE stmt_db;
DEALLOCATE PREPARE stmt_db;

-- Convert only tables whose collation differs from database default.
SELECT GROUP_CONCAT(
    CONCAT(
        'ALTER TABLE `', table_name,
        '` CONVERT TO CHARACTER SET ', @target_charset,
        ' COLLATE ', @target_collation
    ) SEPARATOR '; '
) INTO @sql_tables
FROM information_schema.tables
WHERE table_schema = @db_name
  AND table_type = 'BASE TABLE'
  AND table_collation IS NOT NULL
  AND table_collation <> @target_collation;

SET @sql_tables := IFNULL(@sql_tables, 'SELECT "No table collation changes required"');
PREPARE stmt_tables FROM @sql_tables;
EXECUTE stmt_tables;
DEALLOCATE PREPARE stmt_tables;

-- ============================================================
-- 2) Runtime tuning commands (session/global)
-- ============================================================
-- Development only: relax fsync on every commit for faster writes.
-- Keep innodb_flush_log_at_trx_commit=1 in production.
-- SET GLOBAL innodb_flush_log_at_trx_commit = 2;

-- Temporary runtime buffer pool increase (until server restart).
-- Recommended durable change: set in my.cnf/my.ini (see notes below).
-- SET GLOBAL innodb_buffer_pool_size = 536870912; -- 512MB

-- ============================================================
-- 3) Useful verification queries
-- ============================================================
SHOW VARIABLES LIKE 'innodb_buffer_pool_size';
SHOW VARIABLES LIKE 'innodb_flush_log_at_trx_commit';
SHOW VARIABLES LIKE 'collation_database';

SELECT table_name, table_collation
FROM information_schema.tables
WHERE table_schema = DATABASE()
  AND table_type = 'BASE TABLE'
ORDER BY table_name;

SELECT COUNT(*) AS timezone_rows
FROM mysql.time_zone_name;

-- ============================================================
-- 4) Timezone tables note
-- ============================================================
-- mysql.time_zone_name cannot be populated by pure SQL alone.
-- Load timezone data with mysql_tzinfo_to_sql (Linux/macOS) or
-- import timezone SQL dump provided with your MySQL/MariaDB install.
