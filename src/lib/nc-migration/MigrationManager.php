<?php
/**
 * Main Migration Manager for Nextcloud S3 Migration
 */
require_once 'DatabaseManager.php';
require_once 'S3Manager.php';
require_once 'Logger.php';

class MigrationManager {
    private $db;
    private $s3;
    private $logger;
    private $config;
    private $testMode;
    private $batchSize = 1000;
    private $filesMigrated = 0;
    private $filesFailed = 0;
    private $bytesTransferred = 0;
    
    /**
     * Initialize the migration manager
     * 
     * @param array $config Configuration options
     * @param Logger $logger Logger instance
     */
    public function __construct($config, $logger) {
        $this->config = $config;
        $this->logger = $logger;
        $this->testMode = $config['test_mode'];
        
        if (isset($config['batch_size'])) {
            $this->batchSize = $config['batch_size'];
        }
        
        $this->logger->info("Initializing migration manager (Test mode: " . ($this->testMode ? 'Yes' : 'No') . ")");
        
        // Initialize database connection
        try {
            $this->db = new DatabaseManager(
                $config['db_host'],
                $config['db_port'],
                $config['db_name'],
                $config['db_user'],
                $config['db_password'],
                $this->logger
            );
            
            // Create necessary indexes
            $this->db->createMigrationIndexes();
        } catch (Exception $e) {
            $this->logger->error("Failed to initialize database: " . $e->getMessage());
            throw $e;
        }
        
        // Initialize S3 client
        try {
            $this->s3 = new S3Manager([
                'bucket' => $config['s3_bucket'],
                'region' => $config['s3_region'],
                'endpoint' => $config['s3_endpoint'],
                'key' => $config['s3_key'],
                'secret' => $config['s3_secret'],
                'use_path_style' => $config['s3_use_path_style'] ?? false,
                'use_multipart' => $config['s3_use_multipart'] ?? true,
                'multipart_threshold' => $config['s3_multipart_threshold'] ?? 100,
                'max_retries' => $config['s3_max_retries'] ?? 3,
            ], $this->logger);
        } catch (Exception $e) {
            $this->logger->error("Failed to initialize S3 client: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Run pre-migration checks
     * 
     * @return array Check results
     */
    public function runPreMigrationChecks() {
        $this->logger->info("Running pre-migration checks");
        $results = [
            'success' => true,
            'checks' => [],
        ];
        
        // Check database connection
        try {
            $results['checks']['database'] = [
                'name' => 'Database Connection',
                'status' => 'success',
                'message' => 'Successfully connected to database',
            ];
        } catch (Exception $e) {
            $results['success'] = false;
            $results['checks']['database'] = [
                'name' => 'Database Connection',
                'status' => 'error',
                'message' => 'Failed to connect to database: ' . $e->getMessage(),
            ];
        }
        
        // Check S3 connection
        try {
            $s3Connected = $this->s3->testConnection();
            if ($s3Connected) {
                $results['checks']['s3'] = [
                    'name' => 'S3 Connection',
                    'status' => 'success',
                    'message' => 'Successfully connected to S3 bucket: ' . $this->config['s3_bucket'],
                ];
            } else {
                $results['success'] = false;
                $results['checks']['s3'] = [
                    'name' => 'S3 Connection',
                    'status' => 'error',
                    'message' => 'Failed to connect to S3 bucket: ' . $this->config['s3_bucket'],
                ];
            }
        } catch (Exception $e) {
            $results['success'] = false;
            $results['checks']['s3'] = [
                'name' => 'S3 Connection',
                'status' => 'error',
                'message' => 'S3 connection error: ' . $e->getMessage(),
            ];
        }
        
        // Check local storage ID
        try {
            $localStorageId = $this->getLocalStorageId();
            $results['checks']['local_storage'] = [
                'name' => 'Local Storage',
                'status' => 'success',
                'message' => 'Local storage ID found: ' . $localStorageId,
            ];
        } catch (Exception $e) {
            $results['success'] = false;
            $results['checks']['local_storage'] = [
                'name' => 'Local Storage',
                'status' => 'error',
                'message' => 'Failed to find local storage ID: ' . $e->getMessage(),
            ];
        }
        
        // Check if object storage exists
        try {
            $objectStorageId = $this->getObjectStorageId();
            if ($objectStorageId) {
                $results['checks']['object_storage'] = [
                    'name' => 'Object Storage',
                    'status' => 'info',
                    'message' => 'Object storage already exists with ID: ' . $objectStorageId,
                ];
                
                // Check if files already exist in object storage
                $fileCount = $this->db->fetchColumn(
                    "SELECT COUNT(*) FROM oc_filecache WHERE storage = :storageId",
                    ['storageId' => $objectStorageId]
                );
                
                if ($fileCount > 0) {
                    $results['checks']['object_files'] = [
                        'name' => 'Object Storage Files',
                        'status' => 'warning',
                        'message' => 'Object storage already contains ' . $fileCount . ' files',
                    ];
                }
            } else {
                $results['checks']['object_storage'] = [
                    'name' => 'Object Storage',
                    'status' => 'info',
                    'message' => 'Object storage does not exist yet and will be created',
                ];
            }
        } catch (Exception $e) {
            $results['success'] = false;
            $results['checks']['object_storage'] = [
                'name' => 'Object Storage',
                'status' => 'error',
                'message' => 'Error checking object storage: ' . $e->getMessage(),
            ];
        }
        
        // Check data directory
        if (is_dir($this->config['data_directory'])) {
            $results['checks']['data_dir'] = [
                'name' => 'Data Directory',
                'status' => 'success',
                'message' => 'Data directory exists: ' . $this->config['data_directory'],
            ];
        } else {
            $results['success'] = false;
            $results['checks']['data_dir'] = [
                'name' => 'Data Directory',
                'status' => 'error',
                'message' => 'Data directory does not exist: ' . $this->config['data_directory'],
            ];
        }
        
        // Check backup directory
        if (is_dir($this->config['backup_directory'])) {
            $results['checks']['backup_dir'] = [
                'name' => 'Backup Directory',
                'status' => 'success',
                'message' => 'Backup directory exists: ' . $this->config['backup_directory'],
            ];
        } else {
            $results['success'] = false;
            $results['checks']['backup_dir'] = [
                'name' => 'Backup Directory',
                'status' => 'error',
                'message' => 'Backup directory does not exist: ' . $this->config['backup_directory'],
            ];
        }
        
        return $results;
    }
    
    /**
     * Create database backup
     * 
     * @return string Path to backup file
     */
    public function createDatabaseBackup() {
        if ($this->testMode) {
            $this->logger->info("Test mode: Skipping database backup");
            return null;
        }
        
        try {
            $this->logger->info("Creating database backup");
            return $this->db->backupDatabase(
                $this->config['backup_directory'],
                $this->config['db_host'],
                $this->config['db_name'],
                $this->config['db_user'],
                $this->config['db_password']
            );
        } catch (Exception $e) {
            $this->logger->error("Database backup failed: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Get the local storage ID
     * 
     * @return int Local storage ID
     */
    public function getLocalStorageId() {
        $this->logger->debug("Looking up local storage ID");
        
        $row = $this->db->fetchOne(
            'SELECT numeric_id FROM oc_storages WHERE id = :path',
            ['path' => 'local::' . $this->config['data_directory'] . '/']
        );
        
        if (!$row) {
            throw new Exception("Local storage not found for path: " . $this->config['data_directory']);
        }
        
        return (int)$row['numeric_id'];
    }
    
    /**
     * Get the object storage ID
     * 
     * @return int|null Object storage ID or null if not found
     */
    public function getObjectStorageId() {
        $this->logger->debug("Looking up object storage ID");
        
        $row = $this->db->fetchOne(
            'SELECT numeric_id FROM oc_storages WHERE id LIKE :pattern',
            ['pattern' => 'object::store:amazon::' . $this->config['s3_bucket']]
        );
        
        return $row ? (int)$row['numeric_id'] : null;
    }
    
    /**
     * Count total files to migrate
     * 
     * @param int $storageId Storage ID to count files for
     * @return int Number of files
     */
    public function countFilesToMigrate($storageId) {
        $this->logger->debug("Counting files to migrate from storage ID: $storageId");
        
        return $this->db->fetchColumn(
            'SELECT COUNT(*) FROM oc_filecache fc 
             JOIN oc_mimetypes mt ON fc.mimetype = mt.id 
             WHERE fc.storage = :storageId AND mt.mimetype <> :dirMimetype AND path <> \'\'',
            [
                'storageId' => $storageId,
                'dirMimetype' => 'httpd/unix-directory'
            ]
        );
    }
    
    /**
     * Start the migration process
     * 
     * @param callable $progressCallback Function to call with progress updates
     * @return array Migration results
     */
    public function startMigration($progressCallback = null) {
        $this->logger->info("Starting migration process");
        
        try {
            // Enable maintenance mode if needed
            if (!$this->testMode && $this->config['enable_maintenance']) {
                $this->enableMaintenanceMode(true);
            }
            
            // Create database backup
            $backupFile = $this->createDatabaseBackup();
            
            // Start transaction
            $this->db->beginTransaction();
            
            // Get local storage ID
            $localStorageId = $this->getLocalStorageId();
            $this->logger->info("Local storage ID: $localStorageId");
            
            // Count total files to migrate
            $totalFiles = $this->countFilesToMigrate($localStorageId);
            $this->logger->info("Total files to migrate: $totalFiles");
            
            // Initialize counters
            $this->filesMigrated = 0;
            $this->filesFailed = 0;
            $this->bytesTransferred = 0;
            
            // Process files in batches
            $lastFileId = 0;
            while ($this->filesMigrated + $this->filesFailed < $totalFiles) {
                $files = $this->getFilesBatch($localStorageId, $lastFileId);
                
                if (empty($files)) {
                    break;
                }
                
                foreach ($files as $file) {
                    try {
                        $result = $this->migrateFile($file);
                        
                        if ($result['success']) {
                            $this->filesMigrated++;
                            $this->bytesTransferred += $file['size'];
                        } else {
                            $this->filesFailed++;
                        }
                        
                        $lastFileId = $file['fileid'];
                        
                        // Report progress
                        if ($progressCallback) {
                            $progress = [
                                'total' => $totalFiles,
                                'migrated' => $this->filesMigrated,
                                'failed' => $this->filesFailed,
                                'bytes' => $this->bytesTransferred,
                                'current_file' => $file['path'],
                            ];
                            $progressCallback($progress);
                        }
                        
                        // Commit every 100 files to avoid large transactions
                        if (($this->filesMigrated + $this->filesFailed) % 100 == 0) {
                            $this->db->commit();
                            $this->db->beginTransaction();
                            $this->logger->debug("Committed batch, starting new transaction");
                        }
                    } catch (Exception $e) {
                        $this->logger->error("Error migrating file ID {$file['fileid']}: " . $e->getMessage());
                        $this->filesFailed++;
                    }
                }
            }
            
            // Commit final transaction
            $this->db->commit();
            
            // Update storage providers
            if (!$this->testMode) {
                $this->updateStorageProviders();
            }
            
            // Disable maintenance mode if needed
            if (!$this->testMode && $this->config['enable_maintenance']) {
                $this->enableMaintenanceMode(false);
            }
            
            $this->logger->info("Migration completed: {$this->filesMigrated} files migrated, {$this->filesFailed} failed");
            
            return [
                'success' => true,
                'files_migrated' => $this->filesMigrated,
                'files_failed' => $this->filesFailed,
                'bytes_transferred' => $this->bytesTransferred,
                'backup_file' => $backupFile,
            ];
        } catch (Exception $e) {
            // Rollback transaction
            $this->db->rollback();
            
            // Disable maintenance mode
            if (!$this->testMode && $this->config['enable_maintenance']) {
                $this->enableMaintenanceMode(false);
            }
            
            $this->logger->error("Migration failed: " . $e->getMessage());
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'files_migrated' => $this->filesMigrated,
                'files_failed' => $this->filesFailed,
            ];
        }
    }
    
    /**
     * Get a batch of files to migrate
     * 
     * @param int $storageId Storage ID to get files from
     * @param int $lastFileId Last file ID processed
     * @return array Files to migrate
     */
    private function getFilesBatch($storageId, $lastFileId) {
        $this->logger->debug("Getting file batch starting after file ID: $lastFileId");
        
        return $this->db->fetchAll(
            'SELECT fc.fileid, fc.path, fc.size, fc.storage 
             FROM oc_filecache fc 
             JOIN oc_mimetypes mt ON fc.mimetype = mt.id 
             WHERE fc.storage = :storageId 
             AND fc.fileid > :lastFileId 
             AND mt.mimetype <> :dirMimetype 
             AND fc.path <> \'\' 
             ORDER BY fc.fileid ASC 
             LIMIT :batchSize',
            [
                'storageId' => $storageId,
                'lastFileId' => $lastFileId,
                'dirMimetype' => 'httpd/unix-directory',
                'batchSize' => $this->batchSize
            ]
        );
    }
    
    /**
     * Migrate a single file to S3
     * 
     * @param array $file File data
     * @return array Migration result
     */
    private function migrateFile($file) {
        $this->logger->debug("Migrating file ID: {$file['fileid']}, Path: {$file['path']}");
        
        // Build local file path
        $localPath = $this->config['data_directory'] . '/' . $file['storage'] . '/' . $file['path'];
        
        // Check if local file exists
        if (!file_exists($localPath)) {
            $this->logger->warn("Local file not found: $localPath");
            
            // Remove from database if configured
            if (!$this->testMode && $this->config['delete_missing_files']) {
                $this->db->execute(
                    'DELETE FROM oc_filecache WHERE fileid = :fileId',
                    ['fileId' => $file['fileid']]
                );
                $this->logger->info("Deleted missing file from database: {$file['fileid']}");
            }
            
            return [
                'success' => false,
                'error' => 'Local file not found',
            ];
        }
        
        // Skip actual upload in test mode level 2
        if ($this->testMode === 2) {
            $this->logger->info("Test mode 2: Skipping S3 upload for file: {$file['path']}");
            return [
                'success' => true,
                'test_mode' => true,
            ];
        }
        
        // Upload to S3
        $objectKey = 'urn:oid:' . $file['fileid'];
        $result = $this->s3->uploadFile($localPath, $objectKey);
        
        if (!$result['success']) {
            $this->logger->error("Failed to upload file to S3: {$file['path']}");
            return [
                'success' => false,
                'error' => $result['error'],
            ];
        }
        
        // Verify upload if configured
        if ($this->config['verify_uploads']) {
            $verified = $this->s3->verifyObject($objectKey, $localPath);
            if (!$verified) {
                $this->logger->warn("File verification failed: {$file['path']}");
                return [
                    'success' => false,
                    'error' => 'File verification failed',
                ];
            }
        }
        
        // Update database storage ID if not in test mode
        if (!$this->testMode) {
            // Get object storage ID or create if not exists
            $objectStorageId = $this->getObjectStorageId();
            if (!$objectStorageId) {
                $objectStorageId = $this->createObjectStorage();
            }
            
            // Update file storage
            $this->db->execute(
                'UPDATE oc_filecache SET storage = :newStorageId WHERE fileid = :fileId',
                [
                    'newStorageId' => $objectStorageId,
                    'fileId' => $file['fileid']
                ]
            );
        }
        
        $this->logger->debug("File migrated successfully: {$file['path']}");
        return [
            'success' => true,
        ];
    }
    
    /**
     * Create object storage in database
     * 
     * @return int The new object storage ID
     */
    private function createObjectStorage() {
        $this->logger->info("Creating object storage in database");
        
        // Insert new storage
        $this->db->execute(
            'INSERT INTO oc_storages (id, available) VALUES (:id, 1)',
            ['id' => 'object::store:amazon::' . $this->config['s3_bucket']]
        );
        
        // Get the new storage ID
        return $this->getObjectStorageId();
    }
    
    /**
     * Update storage providers to use object storage
     */
    private function updateStorageProviders() {
        $this->logger->info("Updating storage providers");
        
        // Update home mounts to use object storage
        $this->db->execute(
            "UPDATE oc_mounts SET mount_provider_class = REPLACE(mount_provider_class, 'LocalHomeMountProvider', 'ObjectHomeMountProvider') 
             WHERE mount_provider_class LIKE '%LocalHomeMountProvider%'"
        );
        
        // Rename home storages to object storages
        $this->db->execute(
            "UPDATE oc_storages SET id = CONCAT('object::user:', SUBSTRING(id FROM LENGTH('home::') + 1)) 
             WHERE id LIKE 'home::%'"
        );
        
        // Get the local and object storage IDs
        $localStorageId = $this->getLocalStorageId();
        $objectStorageId = $this->getObjectStorageId();
        
        // Update file storage references
        if ($localStorageId && $objectStorageId) {
            $this->db->execute(
                'UPDATE oc_filecache SET storage = :objectStorageId WHERE storage = :localStorageId',
                [
                    'objectStorageId' => $objectStorageId,
                    'localStorageId' => $localStorageId
                ]
            );
        }
    }
    
    /**
     * Enable or disable maintenance mode
     * 
     * @param bool $enable Whether to enable or disable
     * @return bool Success status
     */
    private function enableMaintenanceMode($enable) {
        $action = $enable ? 'Enabling' : 'Disabling';
        $this->logger->info("$action maintenance mode");
        
        $configFile = $this->config['nextcloud_dir'] . '/config/config.php';
        if (!file_exists($configFile)) {
            $this->logger->error("Config file not found: $configFile");
            return false;
        }
        
        // Create backup of config file
        copy($configFile, $configFile . '.bak');
        
        // Read current config
        $configContent = file_get_contents($configFile);
        
        if ($enable) {
            // Enable maintenance mode
            if (strpos($configContent, "'maintenance' => false") !== false) {
                $configContent = str_replace("'maintenance' => false", "'maintenance' => true", $configContent);
            } elseif (strpos($configContent, "'maintenance' => true") === false) {
                // Add maintenance directive
                $configContent = preg_replace("/(return \[\s+)/", "$1'maintenance' => true,\n  ", $configContent);
            }
        } else {
            // Disable maintenance mode
            if (strpos($configContent, "'maintenance' => true") !== false) {
                $configContent = str_replace("'maintenance' => true", "'maintenance' => false", $configContent);
            }
        }
        
        // Write updated config
        return file_put_contents($configFile, $configContent) !== false;
    }
    
    /**
     * Clean up preview images
     * 
     * @param int $maxAgeDays Maximum age in days
     * @param int $maxCount Maximum number of previews to delete
     * @return array Cleanup results
     */
    public function cleanupPreviews($maxAgeDays, $maxCount = 1000) {
        $this->logger->info("Cleaning up preview images (max age: $maxAgeDays days, max count: $maxCount)");
        
        if ($maxAgeDays <= 0) {
            $this->logger->info("Preview cleanup disabled (max age is 0)");
            return [
                'deleted' => 0,
                'size' => 0
            ];
        }
        
        $cutoffTime = time() - ($maxAgeDays * 86400);
        $deleted = 0;
        $size = 0;
        
        // Get preview files
        $previews = $this->db->fetchAll(
            "SELECT fc.fileid, fc.path, fc.size, fc.storage_mtime, st.id as storage_id 
             FROM oc_filecache fc 
             JOIN oc_storages st ON st.numeric_id = fc.storage 
             JOIN oc_mimetypes mt ON fc.mimetype = mt.id 
             WHERE fc.path LIKE 'appdata_%/preview/%' 
             AND mt.mimetype <> 'httpd/unix-directory' 
             AND fc.storage_mtime < :cutoffTime 
             ORDER BY fc.storage_mtime ASC 
             LIMIT :maxCount",
            [
                'cutoffTime' => $cutoffTime,
                'maxCount' => $maxCount
            ]
        );
        
        foreach ($previews as $preview) {
            // Build local path
            if (substr($preview['storage_id'], 0, 13) == 'object::user:') {
                $path = $this->config['data_directory'] . '/' . substr($preview['storage_id'], 13) . '/' . $preview['path'];
            } else if (substr($preview['storage_id'], 0, 6) == 'home::') {
                $path = $this->config['data_directory'] . '/' . substr($preview['storage_id'], 6) . '/' . $preview['path'];
            } else {
                $path = $this->config['data_directory'] . '/' . $preview['path'];
            }
            
            // Delete from S3
            $objectKey = 'urn:oid:' . $preview['fileid'];
            $this->s3->deleteObject($objectKey);
            
            // Delete local file
            if (file_exists($path) && is_file($path)) {
                unlink($path);
            }
            
            // Delete from database
            if (!$this->testMode) {
                $this->db->execute(
                    'DELETE FROM oc_filecache WHERE fileid = :fileId',
                    ['fileId' => $preview['fileid']]
                );
            }
            
            $deleted++;
            $size += $preview['size'];
            
            $this->logger->debug("Deleted preview: {$preview['path']}");
        }
        
        $this->logger->info("Preview cleanup complete: deleted $deleted files ($size bytes)");
        
        return [
            'deleted' => $deleted,
            'size' => $size
        ];
    }
    
    /**
     * Close database connection
     */
    public function close() {
        if ($this->db) {
            $this->db->close();
        }
    }
}