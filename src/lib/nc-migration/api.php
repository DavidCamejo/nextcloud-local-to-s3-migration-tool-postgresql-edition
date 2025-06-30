<?php
/**
 * API Handler for Nextcloud S3 Migration Web Interface
 */
header('Content-Type: application/json');

require_once 'config.php';
require_once 'MigrationManager.php';
require_once 'Logger.php';

// Initialize logger
$logger = new Logger(LOG_FILE, LOG_LEVEL);

// Get action parameter
$action = isset($_GET['action']) ? $_GET['action'] : '';

// Handle different API actions
try {
    switch ($action) {
        case 'getConfig':
            // Return configuration (excluding sensitive information)
            $config = getConfig();
            // Remove sensitive information
            unset($config['db_password']);
            unset($config['s3_secret']);
            echo json_encode([
                'success' => true,
                'config' => $config
            ]);
            break;
            
        case 'saveConfig':
            // Save configuration
            $data = json_decode(file_get_contents('php://input'), true);
            if (!$data) {
                throw new Exception('Invalid request data');
            }
            
            // Validate required fields
            $required = [
                'db_host', 'db_port', 'db_name', 'db_user', 'db_password',
                'nextcloud_dir', 'data_directory', 'backup_directory',
                's3_bucket', 's3_region', 's3_key', 's3_secret'
            ];
            
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    throw new Exception("Missing required field: $field");
                }
            }
            
            // Update config file
            $configContent = "<?php\n/**\n * Configuration for Nextcloud S3 Migration\n */\n\n";
            
            // Database configuration
            $configContent .= "// Database configuration\n";
            $configContent .= "define('DB_HOST', '{$data['db_host']}');\n";
            $configContent .= "define('DB_PORT', '{$data['db_port']}');\n";
            $configContent .= "define('DB_NAME', '{$data['db_name']}');\n";
            $configContent .= "define('DB_USER', '{$data['db_user']}');\n";
            $configContent .= "define('DB_PASSWORD', '{$data['db_password']}');\n\n";
            
            // Nextcloud configuration
            $configContent .= "// Nextcloud configuration\n";
            $configContent .= "define('NEXTCLOUD_DIR', '{$data['nextcloud_dir']}');\n";
            $configContent .= "define('DATA_DIR', '{$data['data_directory']}');\n";
            $configContent .= "define('BACKUP_DIR', '{$data['backup_directory']}');\n\n";
            
            // S3 configuration
            $configContent .= "// S3 configuration\n";
            $configContent .= "define('S3_BUCKET', '{$data['s3_bucket']}');\n";
            $configContent .= "define('S3_REGION', '{$data['s3_region']}');\n";
            $configContent .= "define('S3_ENDPOINT', '{$data['s3_endpoint']}');\n";
            $configContent .= "define('S3_KEY', '{$data['s3_key']}');\n";
            $configContent .= "define('S3_SECRET', '{$data['s3_secret']}');\n";
            $configContent .= "define('S3_USE_PATH_STYLE', " . ($data['s3_use_path_style'] ? 'true' : 'false') . ");\n";
            $configContent .= "define('S3_USE_MULTIPART', " . ($data['s3_use_multipart'] ? 'true' : 'false') . ");\n";
            $configContent .= "define('S3_MULTIPART_THRESHOLD', {$data['s3_multipart_threshold']});\n\n";
            
            // Migration options
            $configContent .= "// Migration options\n";
            $configContent .= "define('TEST_MODE', " . ($data['test_mode'] ? 'true' : 'false') . ");\n";
            $configContent .= "define('BATCH_SIZE', {$data['batch_size']});\n";
            $configContent .= "define('ENABLE_MAINTENANCE', " . ($data['enable_maintenance'] ? 'true' : 'false') . ");\n";
            $configContent .= "define('VERIFY_UPLOADS', " . ($data['verify_uploads'] ? 'true' : 'false') . ");\n";
            $configContent .= "define('DELETE_MISSING_FILES', " . ($data['delete_missing_files'] ? 'true' : 'false') . ");\n";
            $configContent .= "define('PREVIEW_MAX_AGE', {$data['preview_max_age']});\n";
            $configContent .= "define('LOG_LEVEL', {$data['log_level']});\n";
            $configContent .= "define('LOG_FILE', '{$data['log_file']}');\n\n";
            
            // Add getConfig function
            $configContent .= "// Get configuration as array\n";
            $configContent .= "function getConfig() {\n";
            $configContent .= "    return [\n";
            $configContent .= "        // Database configuration\n";
            $configContent .= "        'db_host' => DB_HOST,\n";
            $configContent .= "        'db_port' => DB_PORT,\n";
            $configContent .= "        'db_name' => DB_NAME,\n";
            $configContent .= "        'db_user' => DB_USER,\n";
            $configContent .= "        'db_password' => DB_PASSWORD,\n\n";
            $configContent .= "        // Nextcloud configuration\n";
            $configContent .= "        'nextcloud_dir' => NEXTCLOUD_DIR,\n";
            $configContent .= "        'data_directory' => DATA_DIR,\n";
            $configContent .= "        'backup_directory' => BACKUP_DIR,\n\n";
            $configContent .= "        // S3 configuration\n";
            $configContent .= "        's3_bucket' => S3_BUCKET,\n";
            $configContent .= "        's3_region' => S3_REGION,\n";
            $configContent .= "        's3_endpoint' => S3_ENDPOINT,\n";
            $configContent .= "        's3_key' => S3_KEY,\n";
            $configContent .= "        's3_secret' => S3_SECRET,\n";
            $configContent .= "        's3_use_path_style' => S3_USE_PATH_STYLE,\n";
            $configContent .= "        's3_use_multipart' => S3_USE_MULTIPART,\n";
            $configContent .= "        's3_multipart_threshold' => S3_MULTIPART_THRESHOLD,\n\n";
            $configContent .= "        // Migration options\n";
            $configContent .= "        'test_mode' => TEST_MODE,\n";
            $configContent .= "        'batch_size' => BATCH_SIZE,\n";
            $configContent .= "        'enable_maintenance' => ENABLE_MAINTENANCE,\n";
            $configContent .= "        'verify_uploads' => VERIFY_UPLOADS,\n";
            $configContent .= "        'delete_missing_files' => DELETE_MISSING_FILES,\n";
            $configContent .= "        'preview_max_age' => PREVIEW_MAX_AGE,\n";
            $configContent .= "    ];\n";
            $configContent .= "}\n";
            
            // Write to config file
            file_put_contents('config.php', $configContent);
            
            echo json_encode([
                'success' => true,
                'message' => 'Configuration saved successfully'
            ]);
            break;
            
        case 'runChecks':
            // Run pre-migration checks
            $config = getConfig();
            $migrationManager = new MigrationManager($config, $logger);
            $results = $migrationManager->runPreMigrationChecks();
            
            echo json_encode([
                'success' => true,
                'results' => $results
            ]);
            break;
            
        case 'startMigration':
            // Start migration process
            $data = json_decode(file_get_contents('php://input'), true);
            $config = getConfig();
            
            // Override test mode if provided
            if (isset($data['test_mode'])) {
                $config['test_mode'] = $data['test_mode'];
            }
            
            session_start();
            $_SESSION['migration_running'] = true;
            $_SESSION['migration_progress'] = [
                'total' => 0,
                'migrated' => 0,
                'failed' => 0,
                'bytes' => 0,
                'current_file' => '',
                'status' => 'running'
            ];
            session_write_close();
            
            // Start migration in background
            $migrationManager = new MigrationManager($config, $logger);
            $migrationManager->startMigration(function($progress) {
                session_start();
                $_SESSION['migration_progress'] = $progress;
                $_SESSION['migration_progress']['status'] = 'running';
                session_write_close();
            });
            
            // Update status to complete
            session_start();
            $_SESSION['migration_progress']['status'] = 'complete';
            $_SESSION['migration_running'] = false;
            session_write_close();
            
            echo json_encode([
                'success' => true,
                'message' => 'Migration started'
            ]);
            break;
            
        case 'getMigrationStatus':
            // Get migration status
            session_start();
            $progress = isset($_SESSION['migration_progress']) ? $_SESSION['migration_progress'] : null;
            $running = isset($_SESSION['migration_running']) ? $_SESSION['migration_running'] : false;
            session_write_close();
            
            echo json_encode([
                'success' => true,
                'running' => $running,
                'progress' => $progress
            ]);
            break;
            
        case 'cleanupPreviews':
            // Clean up preview images
            $data = json_decode(file_get_contents('php://input'), true);
            $config = getConfig();
            
            // Override preview max age if provided
            $maxAgeDays = isset($data['max_age_days']) ? (int)$data['max_age_days'] : $config['preview_max_age'];
            $maxCount = isset($data['max_count']) ? (int)$data['max_count'] : 1000;
            
            $migrationManager = new MigrationManager($config, $logger);
            $results = $migrationManager->cleanupPreviews($maxAgeDays, $maxCount);
            
            echo json_encode([
                'success' => true,
                'results' => $results
            ]);
            break;
            
        default:
            // Invalid action
            throw new Exception('Invalid action specified');
    }
} catch (Exception $e) {
    // Return error response
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}