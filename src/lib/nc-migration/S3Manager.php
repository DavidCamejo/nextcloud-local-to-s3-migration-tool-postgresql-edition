<?php
/**
 * S3 Storage Manager for Nextcloud Migration
 * 
 * Handles S3 operations with improved error handling and verification
 */
require_once 'vendor/autoload.php';
use Aws\S3\S3Client;
use Aws\S3\MultipartUploader;
use Aws\Exception\AwsException;
use Aws\S3\Exception\S3Exception;

class S3Manager {
    const STORAGE_ID = 2; // Default storage ID for S3 in Nextcloud
    
    private $s3Client;
    private $bucket;
    private $useMultipart;
    private $multipartThreshold;
    private $maxRetries;
    private $logger;
    
    /**
     * Initialize the S3 client
     * 
     * @param array $config S3 configuration options
     * @param Logger $logger Logger instance
     */
    public function __construct($config, $logger) {
        $this->logger = $logger;
        $this->bucket = $config['bucket'];
        $this->useMultipart = $config['use_multipart'] ?? false;
        $this->multipartThreshold = $config['multipart_threshold'] ?? 100; // In MB
        $this->maxRetries = $config['max_retries'] ?? 3;
        
        $s3Config = [
            'version' => 'latest',
            'region' => $config['region'],
            'credentials' => [
                'key' => $config['key'],
                'secret' => $config['secret'],
            ],
            'http' => [
                'connect_timeout' => 5,
                'timeout' => 60,
            ]
        ];
        
        // Support both path style and virtual-hosted style endpoints
        if (isset($config['endpoint'])) {
            $s3Config['endpoint'] = $config['endpoint'];
        }
        
        if (isset($config['use_path_style']) && $config['use_path_style']) {
            $s3Config['use_path_style_endpoint'] = true;
        }
        
        try {
            $this->s3Client = new S3Client($s3Config);
            $this->logger->info("S3 client initialized for bucket: {$this->bucket}");
        } catch (AwsException $e) {
            $this->logger->error("S3 client initialization failed: " . $e->getMessage());
            throw new Exception("S3 client initialization failed: " . $e->getMessage());
        }
    }

    /**
     * Upload a file to S3
     * 
     * @param string $localPath Local file path
     * @param string $objectKey S3 object key
     * @param array $options Additional upload options
     * @return array Upload result
     */
    public function uploadFile($localPath, $objectKey, $options = []) {
        if (!file_exists($localPath)) {
            throw new Exception("Local file not found: $localPath");
        }

        $fileSize = filesize($localPath);
        $this->logger->debug("Uploading file: $localPath (Size: $fileSize bytes) to S3 key: $objectKey");

        $params = array_merge([
            'Bucket' => $this->bucket,
            'Key' => $objectKey,
            'SourceFile' => $localPath,
            'ACL' => 'private',
        ], $options);

        try {
            // Use multipart upload for large files
            if ($this->useMultipart && $fileSize > ($this->multipartThreshold * 1024 * 1024)) {
                $this->logger->debug("Using multipart upload for large file: $localPath");
                
                $uploader = new MultipartUploader($this->s3Client, $localPath, [
                    'bucket' => $this->bucket,
                    'key' => $objectKey,
                    'acl' => 'private',
                    'before_upload' => function ($command) {
                        // You can modify the command before each upload if needed
                    },
                ]);

                $result = $uploader->upload();
                return [
                    'success' => true,
                    'url' => $result['ObjectURL'],
                ];
            } else {
                $result = $this->s3Client->putObject($params);
                return [
                    'success' => true,
                    'url' => $result['ObjectURL'],
                ];
            }
        } catch (AwsException $e) {
            $this->logger->error("S3 upload failed for file $localPath: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'file' => $localPath,
            ];
        }
    }

    /**
     * Delete an object from S3
     * 
     * @param string $objectKey Object key to delete
     * @return array Delete result
     */
    public function deleteObject($objectKey) {
        try {
            $this->logger->debug("Deleting S3 object: $objectKey");
            
            $result = $this->s3Client->deleteObject([
                'Bucket' => $this->bucket,
                'Key' => $objectKey,
            ]);
            
            return [
                'success' => true,
                'key' => $objectKey,
            ];
        } catch (AwsException $e) {
            $this->logger->error("S3 delete failed for key $objectKey: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'key' => $objectKey,
            ];
        }
    }

    /**
     * Verify an object exists in S3 and matches the local file
     * 
     * @param string $objectKey S3 object key
     * @param string $localPath Local file path
     * @return bool True if the object exists and matches
     */
    public function verifyObject($objectKey, $localPath) {
        try {
            $result = $this->s3Client->headObject([
                'Bucket' => $this->bucket,
                'Key' => $objectKey,
            ]);
            
            $s3Size = $result['ContentLength'];
            $localSize = filesize($localPath);
            
            if ($s3Size !== $localSize) {
                $this->logger->warn("Size mismatch for $objectKey: S3=$s3Size, Local=$localSize");
                return false;
            }
            
            return true;
        } catch (AwsException $e) {
            $this->logger->error("S3 verification failed for $objectKey: " . $e->getMessage());
            return false;
        }
    }

    /**
     * List objects in the bucket
     * 
     * @param string $prefix Prefix filter
     * @param int $maxItems Maximum items to return
     * @return array List of objects
     */
    public function listObjects($prefix = '', $maxItems = 1000) {
        try {
            $this->logger->debug("Listing S3 objects with prefix: $prefix (max: $maxItems)");
            
            $result = $this->s3Client->listObjects([
                'Bucket' => $this->bucket,
                'Prefix' => $prefix,
                'MaxKeys' => $maxItems,
            ]);
            
            $objects = [];
            if ($result['Contents']) {
                foreach ($result['Contents'] as $object) {
                    $objects[] = [
                        'key' => $object['Key'],
                        'size' => $object['Size'],
                        'lastModified' => $object['LastModified'],
                    ];
                }
            }
            
            return $objects;
        } catch (AwsException $e) {
            $this->logger->error("S3 list objects failed: " . $e->getMessage());
            throw new Exception("S3 list objects failed: " . $e->getMessage());
        }
    }
    
    /**
     * Test the S3 connection
     * 
     * @return bool True if connection successful
     */
    public function testConnection() {
        try {
            $this->s3Client->headBucket(['Bucket' => $this->bucket]);
            $this->logger->info("S3 connection test successful for bucket: {$this->bucket}");
            return true;
        } catch (AwsException $e) {
            $this->logger->error("S3 connection test failed: " . $e->getMessage());
            return false;
        }
    }
}