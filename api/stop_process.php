<?php
session_start();
if (!defined('WEB_ROOT')) {
    require_once __DIR__ . '/../bootstrap.php'; // Adjust path as needed to reach bootstrap.php
}
require_once BASE_PATH . 'loadenv.php';
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING & ~E_DEPRECATED & ~E_USER_DEPRECATED);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
// Set error logging based on environment
$environment = getenv('APP_ENV') ?: 'development';
$output_dir = __DIR__ . '/output';

// kills ALL processes whose cmd line contain dream...actual but seems like this script also runs in those processes so return_var is 15
exec("pkill -f 'dream2img_actual.php'", $output, $return_code);
clean_output_directory($output_dir);
error_log($return_code === 15 || $return_code === 0 ? "Process(es) stopped" : "No processes found");
error_log("return code: " . $return_code);
echo $return_code;

/**
 * Cleans up the output directory but preserves the directory itself
 * @param string $dir The directory to clean
 */
function clean_output_directory($dir) {
    if (!is_dir($dir)) {
        return;
    }
    
    // Recursively delete all files and subdirectories
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    
    foreach ($items as $item) {
        if ($item->isDir()) {
            rmdir($item->getRealPath());
        } else {
            unlink($item->getRealPath());
        }
    }
    
    log_message("Cleaned output directory: $dir");
}
?>