<?php
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING & ~E_DEPRECATED & ~E_USER_DEPRECATED);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
// Set error logging based on environment
$environment = getenv('APP_ENV') ?: 'development';
if ($environment === 'production') {
    ini_set('error_log', 'stderr'); // Works for Vercel and many cloud platforms
} else {
    // Use BASE_PATH to determine log directory location
    // Make sure BASE_PATH is already defined before this code runs
    $logDir = rtrim(BASE_PATH, '/') . '/logs';
    
    // Alternative: Use WEB_ROOT if that's more appropriate for your setup
    // $logDir = rtrim($_SERVER['DOCUMENT_ROOT'] . WEB_ROOT, '/') . '/logs';
    
    // Make sure the logs directory exists
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    ini_set('error_log', $logDir . '/custom.log');
}
function generateFooter() {
    $currentYear = date("Y");
    $footerHtml = <<<HTML
    <style>
      footer {
        position: fixed;
        bottom: 0;
        left: 0;
        background-color: #f0f0f0;
        padding: 20px;
        text-align: center;
        width: 100%;
        box-sizing: border-box;
        z-index: 50; /* Ensures footer stays on top of other content */
      }

      .copyright {
        font-size: 14px;
        color: #333;
      }

      footer a {
        color: #007bff;
        text-decoration: none;
      }

      footer a:hover {
        text-decoration: underline;
      }
      
      /* Add padding to body to prevent content from being hidden behind the footer */
      body {
        padding-bottom: 60px; /* Adjust based on your footer height */
      }
    </style>

    <footer>
        <p class="copyright">&copy; {$currentYear} GooberBox. All rights reserved.</p>
    </footer>
    HTML;
    return $footerHtml;
}
?>