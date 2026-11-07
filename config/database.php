<?php
/**
 * =============================================================================
 * CityCare Hospital - Database Connection Helper
 * =============================================================================
 * 
 * Provides a secure, reusable PDO database connection for the CityCare system.
 * 
 * Key Security & Architecture Highlights:
 *  - Uses PDO (PHP Data Objects) with prepared statements to prevent SQL Injection.
 *  - Singleton caching pattern avoids creating duplicate connection pools.
 *  - Enforces UTF-8mb4 encoding to safely store special characters, names, and symbols.
 *  - Disables emulated prepared statements (forces native server-side prepared statements).
 *  - Catches PDOExceptions to prevent raw database credentials or stack traces from
 *    leaking to end users in web responses.
 */

declare(strict_types=1);

// Ensure core application configuration constants are loaded
if (!defined('APP_NAME')) {
    require_once __DIR__ . '/app.php';
}

// Database Connection Parameters
// Can be customized via environment variables or overridden per deployment
defined('DB_HOST')    || define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
defined('DB_PORT')    || define('DB_PORT', getenv('DB_PORT') ?: '3306');
defined('DB_NAME')    || define('DB_NAME', getenv('DB_NAME') ?: 'hospital_system');
defined('DB_USER')    || define('DB_USER', getenv('DB_USER') ?: 'root');
defined('DB_PASS')    || define('DB_PASS', getenv('DB_PASS') !== false ? getenv('DB_PASS') : '');
defined('DB_CHARSET') || define('DB_CHARSET', 'utf8mb4');

/**
 * Obtain a shared PDO connection instance.
 *
 * @throws RuntimeException When database connection fails
 * @return PDO
 */
function getDBConnection(): PDO
{
    static $pdoInstance = null;

    if ($pdoInstance !== null) {
        return $pdoInstance;
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=%s',
        DB_HOST,
        DB_PORT,
        DB_NAME,
        DB_CHARSET
    );

    $options = [
        // Throw exceptions on SQL errors for reliable error handling
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        // Return results as associative arrays (e.g., $row['full_name'])
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        // Disable emulation to use true native MySQL prepared statements
        PDO::ATTR_EMULATE_PREPARES   => false,
        // Ensure connection session uses full 4-byte UTF-8
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES " . DB_CHARSET . " COLLATE utf8mb4_unicode_ci",
        // 5-second timeout for rapid failover
        PDO::ATTR_TIMEOUT            => 5,
    ];

    try {
        $pdoInstance = new PDO($dsn, DB_USER, DB_PASS, $options);
        return $pdoInstance;
    } catch (PDOException $e) {
        // Log technical detail to server error log without leaking passwords in logs
        error_log(sprintf(
            '[CityCare DB Connection Error] Host: %s, DB: %s, Code: %s, Message: %s',
            DB_HOST,
            DB_NAME,
            $e->getCode(),
            $e->getMessage()
        ));

        $isDevelopment = (defined('APP_ENV') && APP_ENV === 'development');

        if ($isDevelopment) {
            $userMessage = sprintf(
                'Database Connection Failed (%s). Please check MySQL status and config/database.php settings.',
                htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8')
            );
        } else {
            $userMessage = 'We are currently experiencing technical difficulties connecting to the hospital database. Please try again later.';
        }

        throw new RuntimeException($userMessage, (int)$e->getCode());
    }
}

