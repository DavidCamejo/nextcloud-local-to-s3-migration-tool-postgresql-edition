import { useEffect, useState } from 'react';
import { Tabs, TabsList, TabsTrigger, TabsContent } from "@/components/ui/tabs";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from "@/components/ui/card";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Checkbox } from "@/components/ui/checkbox";
import { Progress } from "@/components/ui/progress";
import { 
  CheckCircle, 
  XCircle, 
  AlertCircle, 
  Info, 
  Settings, 
  Database, 
  Cloud, 
  Server, 
  FileUp,
  ArrowRight
} from 'lucide-react';

export default function MigrationTool() {
  const [activeTab, setActiveTab] = useState('overview');
  const [config, setConfig] = useState({
    // Database configuration
    db_host: 'localhost',
    db_port: '5432',
    db_name: 'nextcloud',
    db_user: 'nextcloud',
    db_password: '',
    
    // Nextcloud configuration
    nextcloud_dir: '/var/www/nextcloud',
    data_directory: '/var/www/nextcloud/data',
    backup_directory: '/var/www/nextcloud/backup',
    
    // S3 configuration
    s3_bucket: 'nextcloud-bucket',
    s3_region: 'us-east-1',
    s3_endpoint: 'https://s3.example.com',
    s3_key: '',
    s3_secret: '',
    s3_use_path_style: true,
    s3_use_multipart: true,
    s3_multipart_threshold: 100,
    
    // Migration options
    test_mode: true,
    batch_size: 1000,
    enable_maintenance: true,
    verify_uploads: true,
    delete_missing_files: false,
    preview_max_age: 30,
    log_level: 1,
    log_file: '/var/log/nextcloud_migration.log'
  });
  
  const [checkResults, setCheckResults] = useState(null);
  const [migrationRunning, setMigrationRunning] = useState(false);
  const [migrationProgress, setMigrationProgress] = useState({
    total: 0,
    migrated: 0,
    failed: 0,
    bytes: 0,
    percent: 0,
    current_file: '',
    status: 'idle'
  });
  const [previewCleanupResults, setPreviewCleanupResults] = useState(null);
  
  useEffect(() => {
    // Load configuration
    fetch('/api/migrate.php?action=getConfig')
      .then(response => response.json())
      .then(data => {
        if (data.success && data.config) {
          setConfig(prev => ({ ...prev, ...data.config }));
        }
      })
      .catch(error => console.error('Error loading configuration:', error));
      
    // Check migration status periodically if running
    const interval = setInterval(() => {
      if (migrationRunning) {
        fetch('/api/migrate.php?action=getMigrationStatus')
          .then(response => response.json())
          .then(data => {
            if (data.success) {
              setMigrationRunning(data.running);
              if (data.progress) {
                const progress = data.progress;
                const percent = Math.round(
                  (progress.migrated + progress.failed) / Math.max(1, progress.total) * 100
                );
                setMigrationProgress({
                  ...progress,
                  percent
                });
                
                if (progress.status === 'complete') {
                  setMigrationRunning(false);
                }
              }
            }
          })
          .catch(error => console.error('Error checking migration status:', error));
      }
    }, 1000);
    
    return () => clearInterval(interval);
  }, [migrationRunning]);
  
  const runPreMigrationChecks = () => {
    fetch('/api/migrate.php?action=runChecks')
      .then(response => response.json())
      .then(data => {
        if (data.success) {
          setCheckResults(data.results);
          setActiveTab('checks');
        }
      })
      .catch(error => console.error('Error running pre-migration checks:', error));
  };
  
  const saveConfiguration = () => {
    fetch('/api/migrate.php?action=saveConfig', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json'
      },
      body: JSON.stringify(config)
    })
      .then(response => response.json())
      .then(data => {
        if (data.success) {
          alert('Configuration saved successfully');
        } else {
          alert('Failed to save configuration: ' + data.error);
        }
      })
      .catch(error => console.error('Error saving configuration:', error));
  };
  
  const startMigration = () => {
    if (window.confirm(
      config.test_mode 
        ? 'Start migration in TEST mode?' 
        : 'Start PRODUCTION migration? This will modify your Nextcloud instance!'
    )) {
      fetch('/api/migrate.php?action=startMigration', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json'
        },
        body: JSON.stringify({ test_mode: config.test_mode })
      })
        .then(response => response.json())
        .then(data => {
          if (data.success) {
            setMigrationRunning(true);
            setMigrationProgress({
              total: 0,
              migrated: 0,
              failed: 0,
              bytes: 0,
              percent: 0,
              current_file: 'Initializing...',
              status: 'running'
            });
            setActiveTab('migrate');
          } else {
            alert('Failed to start migration: ' + data.error);
          }
        })
        .catch(error => console.error('Error starting migration:', error));
    }
  };
  
  const cleanupPreviews = () => {
    if (window.confirm('Start preview cleanup?')) {
      fetch('/api/migrate.php?action=cleanupPreviews', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json'
        },
        body: JSON.stringify({
          max_age_days: config.preview_max_age,
          max_count: 1000
        })
      })
        .then(response => response.json())
        .then(data => {
          if (data.success) {
            setPreviewCleanupResults(data.results);
            alert(`Preview cleanup completed: ${data.results.deleted} files removed`);
          } else {
            alert('Failed to clean up previews: ' + data.error);
          }
        })
        .catch(error => console.error('Error cleaning up previews:', error));
    }
  };
  
  const formatBytes = (bytes) => {
    if (bytes === 0) return '0 Bytes';
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB', 'PB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
  };
  
  const renderStatusIcon = (status) => {
    switch (status) {
      case 'success':
        return <CheckCircle className="h-5 w-5 text-green-500" />;
      case 'error':
        return <XCircle className="h-5 w-5 text-red-500" />;
      case 'warning':
        return <AlertCircle className="h-5 w-5 text-yellow-500" />;
      case 'info':
        return <Info className="h-5 w-5 text-blue-500" />;
      default:
        return null;
    }
  };
  
  return (
    <div className="container mx-auto py-8 max-w-5xl">
      <div className="text-center mb-6">
        <h1 className="text-3xl font-bold">Nextcloud Local to S3 Migration Tool</h1>
        <p className="text-muted-foreground mt-2">PostgreSQL Edition</p>
      </div>
      
      <Tabs value={activeTab} onValueChange={setActiveTab}>
        <TabsList className="grid grid-cols-4 w-full">
          <TabsTrigger value="overview">Overview</TabsTrigger>
          <TabsTrigger value="config">Configuration</TabsTrigger>
          <TabsTrigger value="checks">Pre-Migration Checks</TabsTrigger>
          <TabsTrigger value="migrate">Migrate</TabsTrigger>
        </TabsList>
        
        {/* Overview Tab */}
        <TabsContent value="overview">
          <Card>
            <CardHeader>
              <CardTitle>Nextcloud S3 Migration Tool</CardTitle>
              <CardDescription>
                Migrate your Nextcloud data from local storage to S3-compatible object storage.
              </CardDescription>
            </CardHeader>
            <CardContent>
              <div className="grid gap-4 py-4">
                <p className="text-sm">
                  This tool helps you migrate your Nextcloud instance's file storage from local storage to S3-compatible object storage.
                  The migration is optimized for PostgreSQL databases and handles the following tasks:
                </p>
                
                <div className="space-y-2">
                  <div className="flex items-center gap-2">
                    <CheckCircle className="h-5 w-5 text-green-500" />
                    <span>Database configuration and optimization for PostgreSQL</span>
                  </div>
                  <div className="flex items-center gap-2">
                    <CheckCircle className="h-5 w-5 text-green-500" />
                    <span>Transactional file migration to ensure data integrity</span>
                  </div>
                  <div className="flex items-center gap-2">
                    <CheckCircle className="h-5 w-5 text-green-500" />
                    <span>Comprehensive pre-migration checks</span>
                  </div>
                  <div className="flex items-center gap-2">
                    <CheckCircle className="h-5 w-5 text-green-500" />
                    <span>Automatic database backup</span>
                  </div>
                  <div className="flex items-center gap-2">
                    <CheckCircle className="h-5 w-5 text-green-500" />
                    <span>Preview image cleanup and optimization</span>
                  </div>
                  <div className="flex items-center gap-2">
                    <CheckCircle className="h-5 w-5 text-green-500" />
                    <span>Test mode to validate migration without making changes</span>
                  </div>
                </div>
                
                <Alert>
                  <AlertCircle className="h-4 w-4" />
                  <AlertTitle>Important</AlertTitle>
                  <AlertDescription>
                    Always backup your data before performing a migration. This tool creates a database backup,
                    but it's recommended to have a full backup of both your database and file data.
                  </AlertDescription>
                </Alert>
              </div>
            </CardContent>
            <CardFooter>
              <div className="flex justify-between w-full">
                <Button variant="outline" onClick={() => setActiveTab('config')}>
                  <Settings className="mr-2 h-4 w-4" />
                  Configure
                </Button>
                <Button onClick={runPreMigrationChecks}>
                  Run Pre-Migration Checks
                  <ArrowRight className="ml-2 h-4 w-4" />
                </Button>
              </div>
            </CardFooter>
          </Card>
        </TabsContent>
        
        {/* Configuration Tab */}
        <TabsContent value="config">
          <Card>
            <CardHeader>
              <CardTitle>Configuration</CardTitle>
              <CardDescription>
                Configure the migration settings
              </CardDescription>
            </CardHeader>
            <CardContent>
              <div className="space-y-6">
                {/* Database Configuration */}
                <div>
                  <h3 className="text-lg font-medium flex items-center">
                    <Database className="mr-2 h-5 w-5" />
                    Database Configuration
                  </h3>
                  <div className="grid grid-cols-2 gap-4 mt-2">
                    <div className="space-y-2">
                      <Label htmlFor="db_host">Host</Label>
                      <Input 
                        id="db_host" 
                        value={config.db_host} 
                        onChange={e => setConfig({...config, db_host: e.target.value})}
                      />
                    </div>
                    <div className="space-y-2">
                      <Label htmlFor="db_port">Port</Label>
                      <Input 
                        id="db_port" 
                        value={config.db_port} 
                        onChange={e => setConfig({...config, db_port: e.target.value})}
                      />
                    </div>
                    <div className="space-y-2">
                      <Label htmlFor="db_name">Database Name</Label>
                      <Input 
                        id="db_name" 
                        value={config.db_name} 
                        onChange={e => setConfig({...config, db_name: e.target.value})}
                      />
                    </div>
                    <div className="space-y-2">
                      <Label htmlFor="db_user">Username</Label>
                      <Input 
                        id="db_user" 
                        value={config.db_user} 
                        onChange={e => setConfig({...config, db_user: e.target.value})}
                      />
                    </div>
                    <div className="space-y-2 col-span-2">
                      <Label htmlFor="db_password">Password</Label>
                      <Input 
                        id="db_password" 
                        type="password"
                        value={config.db_password} 
                        onChange={e => setConfig({...config, db_password: e.target.value})}
                      />
                    </div>
                  </div>
                </div>
                
                {/* Nextcloud Configuration */}
                <div>
                  <h3 className="text-lg font-medium flex items-center">
                    <Server className="mr-2 h-5 w-5" />
                    Nextcloud Configuration
                  </h3>
                  <div className="grid grid-cols-1 gap-4 mt-2">
                    <div className="space-y-2">
                      <Label htmlFor="nextcloud_dir">Nextcloud Directory</Label>
                      <Input 
                        id="nextcloud_dir" 
                        value={config.nextcloud_dir} 
                        onChange={e => setConfig({...config, nextcloud_dir: e.target.value})}
                      />
                    </div>
                    <div className="space-y-2">
                      <Label htmlFor="data_directory">Data Directory</Label>
                      <Input 
                        id="data_directory" 
                        value={config.data_directory} 
                        onChange={e => setConfig({...config, data_directory: e.target.value})}
                      />
                    </div>
                    <div className="space-y-2">
                      <Label htmlFor="backup_directory">Backup Directory</Label>
                      <Input 
                        id="backup_directory" 
                        value={config.backup_directory} 
                        onChange={e => setConfig({...config, backup_directory: e.target.value})}
                      />
                    </div>
                  </div>
                </div>
                
                {/* S3 Configuration */}
                <div>
                  <h3 className="text-lg font-medium flex items-center">
                    <Cloud className="mr-2 h-5 w-5" />
                    S3 Configuration
                  </h3>
                  <div className="grid grid-cols-2 gap-4 mt-2">
                    <div className="space-y-2">
                      <Label htmlFor="s3_bucket">Bucket</Label>
                      <Input 
                        id="s3_bucket" 
                        value={config.s3_bucket} 
                        onChange={e => setConfig({...config, s3_bucket: e.target.value})}
                      />
                    </div>
                    <div className="space-y-2">
                      <Label htmlFor="s3_region">Region</Label>
                      <Input 
                        id="s3_region" 
                        value={config.s3_region} 
                        onChange={e => setConfig({...config, s3_region: e.target.value})}
                      />
                    </div>
                    <div className="space-y-2 col-span-2">
                      <Label htmlFor="s3_endpoint">Endpoint</Label>
                      <Input 
                        id="s3_endpoint" 
                        value={config.s3_endpoint} 
                        onChange={e => setConfig({...config, s3_endpoint: e.target.value})}
                      />
                    </div>
                    <div className="space-y-2">
                      <Label htmlFor="s3_key">Access Key</Label>
                      <Input 
                        id="s3_key" 
                        value={config.s3_key} 
                        onChange={e => setConfig({...config, s3_key: e.target.value})}
                      />
                    </div>
                    <div className="space-y-2">
                      <Label htmlFor="s3_secret">Secret Key</Label>
                      <Input 
                        id="s3_secret" 
                        type="password"
                        value={config.s3_secret} 
                        onChange={e => setConfig({...config, s3_secret: e.target.value})}
                      />
                    </div>
                    <div className="space-y-2 col-span-2 flex items-center space-x-2">
                      <Checkbox 
                        id="s3_use_path_style"
                        checked={config.s3_use_path_style}
                        onCheckedChange={(checked) => setConfig({...config, s3_use_path_style: checked})}
                      />
                      <Label htmlFor="s3_use_path_style">Use Path Style Endpoint</Label>
                    </div>
                    <div className="space-y-2 col-span-2 flex items-center space-x-2">
                      <Checkbox 
                        id="s3_use_multipart"
                        checked={config.s3_use_multipart}
                        onCheckedChange={(checked) => setConfig({...config, s3_use_multipart: checked})}
                      />
                      <Label htmlFor="s3_use_multipart">Use Multipart Upload</Label>
                    </div>
                    <div className="space-y-2 col-span-2">
                      <Label htmlFor="s3_multipart_threshold">Multipart Upload Threshold (MB)</Label>
                      <Input 
                        id="s3_multipart_threshold" 
                        type="number"
                        value={config.s3_multipart_threshold} 
                        onChange={e => setConfig({...config, s3_multipart_threshold: parseInt(e.target.value)})}
                      />
                    </div>
                  </div>
                </div>
                
                {/* Migration Options */}
                <div>
                  <h3 className="text-lg font-medium flex items-center">
                    <FileUp className="mr-2 h-5 w-5" />
                    Migration Options
                  </h3>
                  <div className="grid grid-cols-2 gap-4 mt-2">
                    <div className="space-y-2 flex items-center space-x-2">
                      <Checkbox 
                        id="test_mode"
                        checked={config.test_mode}
                        onCheckedChange={(checked) => setConfig({...config, test_mode: checked})}
                      />
                      <Label htmlFor="test_mode">Test Mode</Label>
                    </div>
                    <div className="space-y-2 flex items-center space-x-2">
                      <Checkbox 
                        id="enable_maintenance"
                        checked={config.enable_maintenance}
                        onCheckedChange={(checked) => setConfig({...config, enable_maintenance: checked})}
                      />
                      <Label htmlFor="enable_maintenance">Enable Maintenance Mode</Label>
                    </div>
                    <div className="space-y-2 flex items-center space-x-2">
                      <Checkbox 
                        id="verify_uploads"
                        checked={config.verify_uploads}
                        onCheckedChange={(checked) => setConfig({...config, verify_uploads: checked})}
                      />
                      <Label htmlFor="verify_uploads">Verify Uploads</Label>
                    </div>
                    <div className="space-y-2 flex items-center space-x-2">
                      <Checkbox 
                        id="delete_missing_files"
                        checked={config.delete_missing_files}
                        onCheckedChange={(checked) => setConfig({...config, delete_missing_files: checked})}
                      />
                      <Label htmlFor="delete_missing_files">Delete Missing Files</Label>
                    </div>
                    <div className="space-y-2">
                      <Label htmlFor="batch_size">Batch Size</Label>
                      <Input 
                        id="batch_size" 
                        type="number"
                        value={config.batch_size} 
                        onChange={e => setConfig({...config, batch_size: parseInt(e.target.value)})}
                      />
                    </div>
                    <div className="space-y-2">
                      <Label htmlFor="preview_max_age">Preview Max Age (days)</Label>
                      <Input 
                        id="preview_max_age" 
                        type="number"
                        value={config.preview_max_age} 
                        onChange={e => setConfig({...config, preview_max_age: parseInt(e.target.value)})}
                      />
                    </div>
                  </div>
                </div>
              </div>
            </CardContent>
            <CardFooter>
              <div className="flex justify-between w-full">
                <Button variant="outline" onClick={() => setActiveTab('overview')}>Back</Button>
                <div className="space-x-2">
                  <Button onClick={saveConfiguration}>Save Configuration</Button>
                  <Button onClick={runPreMigrationChecks}>Run Pre-Migration Checks</Button>
                </div>
              </div>
            </CardFooter>
          </Card>
        </TabsContent>
        
        {/* Pre-Migration Checks Tab */}
        <TabsContent value="checks">
          <Card>
            <CardHeader>
              <CardTitle>Pre-Migration Checks</CardTitle>
              <CardDescription>
                Verify your environment is ready for migration
              </CardDescription>
            </CardHeader>
            <CardContent>
              {checkResults ? (
                <div className="space-y-4">
                  <Alert variant={checkResults.success ? 'default' : 'destructive'}>
                    {checkResults.success ? (
                      <CheckCircle className="h-4 w-4" />
                    ) : (
                      <AlertCircle className="h-4 w-4" />
                    )}
                    <AlertTitle>
                      {checkResults.success ? 'All checks passed!' : 'Some checks failed!'}
                    </AlertTitle>
                    <AlertDescription>
                      {checkResults.success 
                        ? 'Your environment is ready for migration.'
                        : 'Please fix the issues below before proceeding with migration.'}
                    </AlertDescription>
                  </Alert>
                  
                  <div className="space-y-2">
                    {Object.entries(checkResults.checks).map(([key, check]) => (
                      <div key={key} className="flex items-start p-3 border rounded-md">
                        <div className="mr-3 mt-1">{renderStatusIcon(check.status)}</div>
                        <div>
                          <h4 className="font-medium">{check.name}</h4>
                          <p className="text-sm text-muted-foreground">{check.message}</p>
                        </div>
                      </div>
                    ))}
                  </div>
                </div>
              ) : (
                <div className="text-center py-8">
                  <p className="text-muted-foreground">Click the button below to run pre-migration checks</p>
                  <Button onClick={runPreMigrationChecks} className="mt-4">
                    Run Checks
                  </Button>
                </div>
              )}
            </CardContent>
            <CardFooter>
              <div className="flex justify-between w-full">
                <Button variant="outline" onClick={() => setActiveTab('config')}>Back to Configuration</Button>
                {checkResults && checkResults.success && (
                  <Button onClick={() => setActiveTab('migrate')}>Proceed to Migration</Button>
                )}
              </div>
            </CardFooter>
          </Card>
        </TabsContent>
        
        {/* Migration Tab */}
        <TabsContent value="migrate">
          <Card>
            <CardHeader>
              <CardTitle>Migration</CardTitle>
              <CardDescription>
                Migrate your Nextcloud data to S3 storage
              </CardDescription>
            </CardHeader>
            <CardContent>
              <div className="space-y-6">
                <Alert variant={config.test_mode ? 'default' : 'destructive'}>
                  <AlertCircle className="h-4 w-4" />
                  <AlertTitle>
                    {config.test_mode ? 'Test Mode Active' : 'Production Mode'}
                  </AlertTitle>
                  <AlertDescription>
                    {config.test_mode 
                      ? 'In test mode, no actual database changes will be made.'
                      : 'Warning: Production mode will modify your Nextcloud instance!'}
                  </AlertDescription>
                </Alert>
                
                {migrationRunning || migrationProgress.status === 'complete' ? (
                  <div className="space-y-4">
                    <div className="space-y-2">
                      <div className="flex justify-between text-sm">
                        <span>Progress</span>
                        <span>{migrationProgress.percent}%</span>
                      </div>
                      <Progress value={migrationProgress.percent} className="w-full" />
                    </div>
                    
                    <div className="grid grid-cols-2 gap-4 border rounded-md p-4">
                      <div>
                        <p className="text-sm text-muted-foreground">Files Migrated</p>
                        <p className="text-lg font-medium">{migrationProgress.migrated}</p>
                      </div>
                      <div>
                        <p className="text-sm text-muted-foreground">Files Failed</p>
                        <p className="text-lg font-medium">{migrationProgress.failed}</p>
                      </div>
                      <div>
                        <p className="text-sm text-muted-foreground">Total Files</p>
                        <p className="text-lg font-medium">{migrationProgress.total}</p>
                      </div>
                      <div>
                        <p className="text-sm text-muted-foreground">Data Transferred</p>
                        <p className="text-lg font-medium">{formatBytes(migrationProgress.bytes)}</p>
                      </div>
                    </div>
                    
                    <div className="border rounded-md p-4">
                      <p className="text-sm text-muted-foreground">Current File</p>
                      <p className="text-sm truncate">{migrationProgress.current_file}</p>
                    </div>
                    
                    {migrationProgress.status === 'complete' && (
                      <Alert>
                        <CheckCircle className="h-4 w-4" />
                        <AlertTitle>Migration Complete</AlertTitle>
                        <AlertDescription>
                          Successfully migrated {migrationProgress.migrated} files to S3 storage.
                        </AlertDescription>
                      </Alert>
                    )}
                  </div>
                ) : (
                  <div className="space-y-4">
                    <p className="text-sm">
                      Start the migration process by clicking the button below. This will:
                    </p>
                    
                    <ul className="list-disc pl-5 space-y-1 text-sm">
                      <li>Create a database backup</li>
                      <li>Enable maintenance mode (if configured)</li>
                      <li>Migrate files from local storage to S3</li>
                      <li>Update database references</li>
                      <li>Disable maintenance mode when completed</li>
                    </ul>
                    
                    <div className="flex justify-center py-4">
                      <Button onClick={startMigration}>
                        Start Migration
                      </Button>
                    </div>
                    
                    <div className="border-t pt-4">
                      <h4 className="font-medium mb-2">Preview Cleanup</h4>
                      <p className="text-sm mb-4">
                        Optionally clean up old preview images to reduce storage usage.
                        This will remove preview images older than {config.preview_max_age} days.
                      </p>
                      <Button variant="outline" onClick={cleanupPreviews}>
                        Clean Preview Images
                      </Button>
                      
                      {previewCleanupResults && (
                        <div className="mt-4 border rounded-md p-3 text-sm">
                          <p>Removed {previewCleanupResults.deleted} files</p>
                          <p>Freed {formatBytes(previewCleanupResults.size)} of space</p>
                        </div>
                      )}
                    </div>
                  </div>
                )}
              </div>
            </CardContent>
            <CardFooter>
              <div className="flex justify-between w-full">
                <Button 
                  variant="outline" 
                  onClick={() => setActiveTab('checks')}
                  disabled={migrationRunning}
                >
                  Back to Checks
                </Button>
                {migrationProgress.status === 'complete' && (
                  <Button onClick={runPreMigrationChecks}>
                    Run Post-Migration Checks
                  </Button>
                )}
              </div>
            </CardFooter>
          </Card>
        </TabsContent>
      </Tabs>
    </div>
  );
}