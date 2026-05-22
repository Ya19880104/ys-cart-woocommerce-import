<?php
declare(strict_types=1);

namespace YangSheep\YsCartWooImport\Database;

defined('ABSPATH') || exit;

final class TableMaker
{
    public static function jobsTable(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'ys_wc_migration_jobs';
    }

    public static function mapsTable(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'ys_wc_migration_maps';
    }

    public static function errorsTable(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'ys_wc_migration_errors';
    }

    public static function createTables(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();
        $jobs = self::jobsTable();
        $maps = self::mapsTable();
        $errors = self::errorsTable();

        dbDelta("CREATE TABLE {$jobs} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            type varchar(24) NOT NULL,
            entity varchar(32) NOT NULL DEFAULT 'all',
            status varchar(24) NOT NULL DEFAULT 'pending',
            source_fingerprint varchar(191) NOT NULL DEFAULT '',
            file_path text NULL,
            file_name varchar(255) NOT NULL DEFAULT '',
            total_count bigint(20) unsigned NOT NULL DEFAULT 0,
            processed_count bigint(20) unsigned NOT NULL DEFAULT 0,
            success_count bigint(20) unsigned NOT NULL DEFAULT 0,
            error_count bigint(20) unsigned NOT NULL DEFAULT 0,
            cursor_json longtext NULL,
            options_json longtext NULL,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            started_at datetime NULL,
            completed_at datetime NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY status (status),
            KEY type_entity (type, entity),
            KEY source_fingerprint (source_fingerprint)
        ) {$charset};");

        dbDelta("CREATE TABLE {$maps} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            job_id bigint(20) unsigned NOT NULL DEFAULT 0,
            source_fingerprint varchar(191) NOT NULL DEFAULT '',
            entity varchar(32) NOT NULL,
            source_id varchar(191) NOT NULL,
            target_id bigint(20) unsigned NOT NULL DEFAULT 0,
            target_type varchar(64) NOT NULL DEFAULT '',
            extra_json longtext NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY source_entity (source_fingerprint, entity, source_id),
            KEY job_id (job_id),
            KEY target (target_type, target_id)
        ) {$charset};");

        dbDelta("CREATE TABLE {$errors} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            job_id bigint(20) unsigned NOT NULL DEFAULT 0,
            entity varchar(32) NOT NULL,
            source_id varchar(191) NOT NULL DEFAULT '',
            message text NOT NULL,
            context_json longtext NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY job_id (job_id),
            KEY entity (entity)
        ) {$charset};");
    }
}

