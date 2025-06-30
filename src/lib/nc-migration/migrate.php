<?php
/**
 * Command-line migration script for Nextcloud S3 Migration
 */
require_once 'config.php';
require_once 'MigrationManager.php';
require_once 'Logger.php';

// Parse command-line options
$options = getopt('c:t:', ['config:', 'test:']);

$configFile = isset($options['c']) ? $options['c'] : (isset($options['config']) ? $options['config'] : null);
$testMode = isset($options['t']) ? $options['t'] : (isset($options['test']) ? $options['test'] : null);

// Load configuration
$config = getConfig();

// Override test mode if provided
if ($testMode !== null) {
    if ($testMode === '0' || $testMode === 'false' || $testMode === 'no') {
        $config['test_mode'] = false;
    } else if ($testMode === '1' || $testMode === 'true' || $testMode === 'yes') {
        $config['test_mode'] = 1;
    } else if ($testMode === '2') {
        $config['test_mode'] = 2;
    } else if ($testMode) {
        // User-specific test
        $config['test_mode'] = $testMode;
    }
}

// Initialize logger
$logger = new Logger(LOG_FILE, LOG_LEVEL);

// Print banner
echo "\n#########################################################################################";
echo "\n Nextcloud Local to S3 Migration Tool - PostgreSQL Edition";
echo "\n Version 1.0.0";
echo "\n Test Mode: " . ($config['test_mode'] ? ($config['test_mode'] === true ? '1' : $config['test_mode']) : 'No');
echo "\n#########################################################################################\n";

// Confirm before proceeding
if (!$config['test_mode']) {
    echo "\nWARNING: You are running in PRODUCTION mode. This will modify your Nextcloud instance.";
    echo "\nMake sure you have a backup before proceeding!";
    echo "\nDo you want to continue? (y/N): ";
    $input = trim(fgets(STDIN));
    if (strtolower($input) !== 'y') {
        echo "Migration aborted.\n";
        exit;
    }
}

// Initialize migration manager
try {
    $migrationManager = new MigrationManager($config, $logger);
    
    // Run pre-migration checks
    echo "\nRunning pre-migration checks...\n";
    $checkResults = $migrationManager->runPreMigrationChecks();
    
    foreach ($checkResults['checks'] as $check) {
        $statusIcon = '';
        switch ($check['status']) {
            case 'success':
                $statusIcon = '✅';
                break;
            case 'warning':
                $statusIcon = '⚠️';
                break;
            case 'error':
                $statusIcon = '❌';
                break;
            case 'info':
                $statusIcon = 'ℹ️';
                break;
        }
        echo "$statusIcon {$check['name']}: {$check['message']}\n";
    }
    
    if (!$checkResults['success']) {
        echo "\nPre-migration checks failed. Please fix the issues and try again.\n";
        exit(1);
    }
    
    echo "\nPre-migration checks passed. Starting migration...\n";
    
    // Start migration with progress reporting
    $result = $migrationManager->startMigration(function($progress) {
        static $lastPercent = -1;
        
        $percent = round(($progress['migrated'] + $progress['failed']) / $progress['total'] * 100);
        
        if ($percent != $lastPercent) {
            $lastPercent = $percent;
            $bytesFormatted = formatBytes($progress['bytes']);
            echo "\rProgress: {$percent}% ({$progress['migrated']} migrated, {$progress['failed']} failed, {$bytesFormatted}) - Current: {$progress['current_file']}";
        }
    });
    
    echo "\n\nMigration completed!";
    echo "\nFiles migrated: {$result['files_migrated']}";
    echo "\nFiles failed: {$result['files_failed']}";
    echo "\nTotal data transferred: " . formatBytes($result['bytes_transferred']);
    
    if (isset($result['backup_file'])) {
        echo "\nDatabase backup: {$result['backup_file']}";
    }
    
    if ($result['success']) {
        echo "\n\nMigration was successful. Your Nextcloud instance is now using S3 storage.\n";
    } else {
        echo "\n\nMigration failed: {$result['error']}\n";
    }
    
} catch (Exception $e) {
    echo "\nFatal error: " . $e->getMessage() . "\n";
    exit(1);
} finally {
    if (isset($migrationManager)) {
        $migrationManager->close();
    }
}

/**
 * Format bytes to human-readable size
 * 
 * @param int $bytes Bytes to format
 * @return string Formatted size
 */
function formatBytes($bytes) {
    if ($bytes == 0) {
        return "0 bytes";
    }
    $i = floor(log($bytes) / log(1024));
    $sizes = array('bytes', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB');
    return sprintf('%.2f %s', $bytes / pow(1024, $i), $sizes[$i]);
}