<?php
/**
 * Configuration for Nextcloud S3 Migration
 */

// Database configuration
define('DB_HOST', 'localhost');
define('DB_PORT', '5432');
define('DB_NAME', 'nextcloud');
define('DB_USER', 'nextcloud');
define('DB_PASSWORD', 'nextcloud_password');

// Nextcloud configuration
define('NEXTCLOUD_DIR', '/var/www/nextcloud');
define('DATA_DIR', '/var/www/nextcloud/data');
define('BACKUP_DIR', '/var/www/nextcloud/backup');

// S3 configuration
define('S3_BUCKET', 'nextcloud-bucket');
define('S3_REGION', 'us-east-1');
define('S3_ENDPOINT', 'https://s3.example.com');
define('S3_KEY', 'your_s3_access_key');
define('S3_SECRET', 'your_s3_secret_key');
define('S3_USE_PATH_STYLE', true);
define('S3_USE_MULTIPART', true);
define('S3_MULTIPART_THRESHOLD', 100); // In MB

// Migration options
define('TEST_MODE', true); // Set to false for production migration
define('BATCH_SIZE', 1000); // Number of files to process in a batch
define('ENABLE_MAINTENANCE', true); // Enable maintenance mode during migration
define('VERIFY_UPLOADS', true); // Verify files after upload
define('DELETE_MISSING_FILES', false); // Delete missing files from database
define('PREVIEW_MAX_AGE', 30); // Maximum age of preview images in days (0 to disable)
define('LOG_LEVEL', 1); // 0=DEBUG, 1=INFO, 2=WARN, 3=ERROR
define('LOG_FILE', '/var/log/nextcloud_migration.log');

// Get configuration as array
function getConfig() {
    return [
        // Database configuration
        'db_host' => DB_HOST,
        'db_port' => DB_PORT,
        'db_name' => DB_NAME,
        'db_user' => DB_USER,
        'db_password' => DB_PASSWORD,
        
        // Nextcloud configuration
        'nextcloud_dir' => NEXTCLOUD_DIR,
        'data_directory' => DATA_DIR,
        'backup_directory' => BACKUP_DIR,
        
        // S3 configuration
        's3_bucket' => S3_BUCKET,
        's3_region' => S3_REGION,
        's3_endpoint' => S3_ENDPOINT,
        's3_key' => S3_KEY,
        's3_secret' => S3_SECRET,
        's3_use_path_style' => S3_USE_PATH_STYLE,
        's3_use_multipart' => S3_USE_MULTIPART,
        's3_multipart_threshold' => S3_MULTIPART_THRESHOLD,
        
        // Migration options
        'test_mode' => TEST_MODE,
        'batch_size' => BATCH_SIZE,
        'enable_maintenance' => ENABLE_MAINTENANCE,
        'verify_uploads' => VERIFY_UPLOADS,
        'delete_missing_files' => DELETE_MISSING_FILES,
        'preview_max_age' => PREVIEW_MAX_AGE,
    ];
}