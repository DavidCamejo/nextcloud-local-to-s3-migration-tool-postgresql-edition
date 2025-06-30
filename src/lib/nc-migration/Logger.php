<?php
/**
 * Logger class for Nextcloud S3 Migration
 */
class Logger {
    const LOG_DEBUG = 0;
    const LOG_INFO = 1;
    const LOG_WARN = 2;
    const LOG_ERROR = 3;
    
    private $logLevel;
    private $logFile;
    private $outputToConsole;
    
    /**
     * Initialize the logger
     * 
     * @param string $logFile Path to log file
     * @param int $logLevel Minimum log level
     * @param bool $outputToConsole Whether to output to console
     */
    public function __construct($logFile, $logLevel = self::LOG_INFO, $outputToConsole = true) {
        $this->logFile = $logFile;
        $this->logLevel = $logLevel;
        $this->outputToConsole = $outputToConsole;
        
        // Initialize the log file
        if (!file_exists(dirname($logFile))) {
            mkdir(dirname($logFile), 0755, true);
        }
        
        // Write header to log file
        $this->writeToLog("Nextcloud S3 Migration Log - Started at " . date('Y-m-d H:i:s'), self::LOG_INFO);
    }
    
    /**
     * Log a debug message
     * 
     * @param string $message Message to log
     */
    public function debug($message) {
        $this->writeToLog($message, self::LOG_DEBUG);
    }
    
    /**
     * Log an info message
     * 
     * @param string $message Message to log
     */
    public function info($message) {
        $this->writeToLog($message, self::LOG_INFO);
    }
    
    /**
     * Log a warning message
     * 
     * @param string $message Message to log
     */
    public function warn($message) {
        $this->writeToLog($message, self::LOG_WARN);
    }
    
    /**
     * Log an error message
     * 
     * @param string $message Message to log
     */
    public function error($message) {
        $this->writeToLog($message, self::LOG_ERROR);
    }
    
    /**
     * Write a message to the log
     * 
     * @param string $message Message to log
     * @param int $level Log level
     */
    private function writeToLog($message, $level) {
        if ($level < $this->logLevel) {
            return;
        }
        
        $timestamp = date('Y-m-d H:i:s');
        $levelStr = $this->getLevelString($level);
        $logMessage = "[$timestamp][$levelStr] $message" . PHP_EOL;
        
        // Write to file
        file_put_contents($this->logFile, $logMessage, FILE_APPEND);
        
        // Output to console if enabled
        if ($this->outputToConsole) {
            $consoleColor = $this->getConsoleColor($level);
            echo $consoleColor . $logMessage . "\033[0m";
        }
    }
    
    /**
     * Get string representation of log level
     * 
     * @param int $level Log level
     * @return string Level string
     */
    private function getLevelString($level) {
        switch ($level) {
            case self::LOG_DEBUG:
                return 'DEBUG';
            case self::LOG_INFO:
                return 'INFO';
            case self::LOG_WARN:
                return 'WARN';
            case self::LOG_ERROR:
                return 'ERROR';
            default:
                return 'UNKNOWN';
        }
    }
    
    /**
     * Get console color code for log level
     * 
     * @param int $level Log level
     * @return string Color code
     */
    private function getConsoleColor($level) {
        switch ($level) {
            case self::LOG_DEBUG:
                return "\033[0;37m"; // White
            case self::LOG_INFO:
                return "\033[0;32m"; // Green
            case self::LOG_WARN:
                return "\033[1;33m"; // Yellow
            case self::LOG_ERROR:
                return "\033[1;31m"; // Red
            default:
                return "\033[0m"; // Default
        }
    }
}