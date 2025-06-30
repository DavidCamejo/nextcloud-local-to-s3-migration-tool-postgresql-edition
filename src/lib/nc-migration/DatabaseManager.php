<?php
/**
 * PostgreSQL Database Manager for Nextcloud S3 Migration
 * 
 * Optimized for PostgreSQL syntax and transaction management
 */
class DatabaseManager {
    private $connection = null;
    private $isTransactionActive = false;
    private $queryCount = 0;
    private $logger;

    /**
     * Initialize the database connection
     * 
     * @param string $host PostgreSQL host
     * @param string $port PostgreSQL port
     * @param string $dbname Database name
     * @param string $user Database user
     * @param string $password Database password
     * @param Logger $logger Logger instance
     */
    public function __construct($host, $port, $dbname, $user, $password, $logger) {
        $this->logger = $logger;
        
        try {
            $dsn = "pgsql:host=$host;port=$port;dbname=$dbname";
            $this->connection = new PDO($dsn, $user, $password);
            $this->connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->connection->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
            $this->connection->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            
            // PostgreSQL specific settings
            $this->connection->exec("SET TIME ZONE 'UTC'");
            $this->connection->exec("SET search_path TO public");
            
            $this->logger->info("Database connection established successfully");
        } catch (PDOException $e) {
            $this->logger->error("Database connection failed: " . $e->getMessage());
            throw new Exception("Database connection failed: " . $e->getMessage());
        }
    }

    /**
     * Begin a database transaction
     */
    public function beginTransaction() {
        if (!$this->isTransactionActive) {
            $this->connection->beginTransaction();
            $this->isTransactionActive = true;
            $this->logger->debug("Transaction started");
        }
    }

    /**
     * Commit the current transaction
     */
    public function commit() {
        if ($this->isTransactionActive) {
            $this->connection->commit();
            $this->isTransactionActive = false;
            $this->logger->debug("Transaction committed");
        }
    }

    /**
     * Rollback the current transaction
     */
    public function rollback() {
        if ($this->isTransactionActive) {
            $this->connection->rollBack();
            $this->isTransactionActive = false;
            $this->logger->debug("Transaction rolled back");
        }
    }

    /**
     * Execute a query with parameters
     * 
     * @param string $sql SQL query
     * @param array $params Query parameters
     * @return PDOStatement|false Query result
     */
    public function execute($sql, $params = []) {
        try {
            $stmt = $this->connection->prepare($sql);
            $stmt->execute($params);
            $this->queryCount++;
            return $stmt;
        } catch (PDOException $e) {
            $this->logger->error("Query execution failed: " . $e->getMessage() . " - SQL: $sql");
            
            // Handle PostgreSQL specific error codes
            switch ($e->getCode()) {
                case '23505': // Unique violation
                    $this->logger->warn("Duplicate key violation detected");
                    break;
                case '23503': // Foreign key violation
                    $this->logger->warn("Foreign key constraint violation detected");
                    break;
            }
            
            throw $e;
        }
    }

    /**
     * Get a single row from a query
     * 
     * @param string $sql SQL query
     * @param array $params Query parameters
     * @return array|null The row or null if no results
     */
    public function fetchOne($sql, $params = []) {
        $stmt = $this->execute($sql, $params);
        return $stmt->fetch();
    }

    /**
     * Get all rows from a query
     * 
     * @param string $sql SQL query
     * @param array $params Query parameters
     * @return array Array of rows
     */
    public function fetchAll($sql, $params = []) {
        $stmt = $this->execute($sql, $params);
        return $stmt->fetchAll();
    }
    
    /**
     * Get a single column from a query
     * 
     * @param string $sql SQL query
     * @param array $params Query parameters
     * @param int $column Column index
     * @return mixed The column value
     */
    public function fetchColumn($sql, $params = [], $column = 0) {
        $stmt = $this->execute($sql, $params);
        return $stmt->fetchColumn($column);
    }
    
    /**
     * Count rows affected by the last statement
     * 
     * @return int Number of rows affected
     */
    public function rowCount() {
        return $this->connection->rowCount();
    }
    
    /**
     * Create necessary indexes for the migration
     */
    public function createMigrationIndexes() {
        $this->logger->info("Creating migration indexes if they don't exist");
        
        $indexes = [
            'CREATE INDEX IF NOT EXISTS oc_filecache_fileid_idx ON oc_filecache (fileid)',
            'CREATE INDEX IF NOT EXISTS oc_filecache_storage_idx ON oc_filecache (storage)',
            'CREATE INDEX IF NOT EXISTS oc_filecache_path_idx ON oc_filecache (path)',
        ];
        
        foreach ($indexes as $sql) {
            try {
                $this->connection->exec($sql);
                $this->logger->debug("Executed: $sql");
            } catch (PDOException $e) {
                $this->logger->warn("Failed to create index: " . $e->getMessage());
            }
        }
    }
    
    /**
     * Backup the database
     * 
     * @param string $backupDir Backup directory
     * @param string $dbHost Database host
     * @param string $dbName Database name
     * @param string $dbUser Database user
     * @param string $dbPassword Database password
     * @return string Backup file path
     */
    public function backupDatabase($backupDir, $dbHost, $dbName, $dbUser, $dbPassword) {
        if (!is_dir($backupDir)) {
            throw new Exception("Backup directory does not exist: $backupDir");
        }
        
        $backupFile = $backupDir . DIRECTORY_SEPARATOR . 'nextcloud_backup_' . date('Y-m-d_H-i-s') . '.sql';
        $this->logger->info("Creating database backup at: $backupFile");
        
        $command = sprintf(
            'PGPASSWORD=%s pg_dump -h %s -U %s -d %s -f %s',
            escapeshellarg($dbPassword),
            escapeshellarg($dbHost),
            escapeshellarg($dbUser),
            escapeshellarg($dbName),
            escapeshellarg($backupFile)
        );
        
        exec($command, $output, $returnVar);
        
        if ($returnVar !== 0) {
            throw new Exception("Database backup failed with error code: $returnVar");
        }
        
        $this->logger->info("Database backup completed successfully");
        return $backupFile;
    }

    /**
     * Close the database connection
     */
    public function close() {
        if ($this->isTransactionActive) {
            $this->rollback();
        }
        $this->connection = null;
        $this->logger->debug("Database connection closed");
    }
}