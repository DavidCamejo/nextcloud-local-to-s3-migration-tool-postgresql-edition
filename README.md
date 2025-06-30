# Nextcloud Local to S3 Migration Tool - PostgreSQL Edition

An optimized tool for migrating Nextcloud data from local storage to S3-compatible object storage with PostgreSQL database support.

## Features

- **PostgreSQL Optimized**: Specifically designed for PostgreSQL databases with proper transaction handling and SQL syntax
- **Web Interface**: User-friendly web interface to configure, check, and monitor migration progress
- **Robust Error Handling**: Improved error detection and recovery mechanisms
- **Modular Design**: Well-structured, maintainable code with clear separation of concerns
- **Verification System**: Built-in verification to ensure file integrity after migration
- **Preview Cleanup**: Optional cleanup of old preview images to save storage space
- **Test Mode**: Safe testing capability without making actual changes to your system
- **Progress Monitoring**: Real-time progress tracking with detailed statistics
- **Database Backup**: Automatic database backup before migration

## Requirements

- PHP 7.4 or higher with PDO PostgreSQL extension
- Nextcloud instance using PostgreSQL database
- AWS SDK for PHP (for S3 operations)
- Web server with PHP support (for the web interface)

## Installation

1. Clone this repository:
   ```
   git clone https://github.com/yourusername/nextcloud-s3-migration-psql.git
   cd nextcloud-s3-migration-psql
   ```

2. Install dependencies:
   ```
   composer require aws/aws-sdk-php
   ```

3. Configure your web server to serve the application directory.

4. Open the web interface in your browser and configure the migration settings.

## Command Line Usage

You can also run the migration tool from the command line:

```
php migrate.php --config=/path/to/config.php --test=1
```

Options:
- `--config` or `-c`: Path to custom configuration file
- `--test` or `-t`: Test mode (0=off, 1=on, 2=dry run)

## Configuration

Edit the `config.php` file to set your database and S3 settings:

### Database Configuration
```php
// Database configuration
define('DB_HOST', 'localhost');
define('DB_PORT', '5432');
define('DB_NAME', 'nextcloud');
define('DB_USER', 'nextcloud');
define('DB_PASSWORD', 'password');
```

### Nextcloud Configuration
```php
// Nextcloud configuration
define('NEXTCLOUD_DIR', '/var/www/nextcloud');
define('DATA_DIR', '/var/www/nextcloud/data');
define('BACKUP_DIR', '/var/www/nextcloud/backup');
```

### S3 Configuration
```php
// S3 configuration
define('S3_BUCKET', 'your-bucket-name');
define('S3_REGION', 'your-region');
define('S3_ENDPOINT', 'https://s3.your-provider.com');
define('S3_KEY', 'your-access-key');
define('S3_SECRET', 'your-secret-key');
define('S3_USE_PATH_STYLE', true); // Set to false for virtual-hosted style endpoints
define('S3_USE_MULTIPART', true);
define('S3_MULTIPART_THRESHOLD', 100); // In MB
```

### Migration Options
```php
// Migration options
define('TEST_MODE', true); // Set to false for production migration
define('BATCH_SIZE', 1000); // Number of files to process in a batch
define('ENABLE_MAINTENANCE', true); // Enable maintenance mode during migration
define('VERIFY_UPLOADS', true); // Verify files after upload
define('DELETE_MISSING_FILES', false); // Delete missing files from database
define('PREVIEW_MAX_AGE', 30); // Maximum age of preview images in days (0 to disable)
```

## Migration Process

1. **Pre-Migration Checks**:
   - Verify database and S3 connectivity
   - Check for storage IDs
   - Ensure data directories exist
   - Validate backup location

2. **Database Backup**:
   - Create a backup of your PostgreSQL database

3. **File Migration**:
   - Upload files to S3 in batches with transaction support
   - Verify uploads if configured
   - Update database references

4. **Storage Update**:
   - Update storage providers to use object storage
   - Rename home storages to object storages

5. **Cleanup**:
   - Optional preview image cleanup
   - Maintenance mode management

## Best Practices

- Always run in test mode first
- Have a full backup of your data before starting
- Perform during low-usage periods
- Monitor server resources during migration
- Verify Nextcloud functionality after migration

## Troubleshooting

### Common Issues

**Database Connection Errors**:
- Verify PostgreSQL connection settings
- Check if the user has proper permissions

**S3 Connection Issues**:
- Validate S3 credentials
- Check network connectivity to S3 endpoint
- Verify bucket permissions

**Missing Files**:
- Check file paths in configuration
- Ensure appropriate read permissions

**Web Interface Not Loading**:
- Verify web server configuration
- Check PHP error logs

## Migration Verification

After migration, verify that:

1. All files are accessible in Nextcloud
2. File operations (upload, download) work correctly
3. Sharing functionality works as expected
4. Preview generation is working

## Support

For issues, please open a ticket on the GitHub repository.

## License

This project is licensed under the MIT License - see the LICENSE file for details.

## Acknowledgements

- Original project by Eesger Toering / knoop.frl / geoarchive.eu
- PostgreSQL optimization enhancements for improved reliability and performance