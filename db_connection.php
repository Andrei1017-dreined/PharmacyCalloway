<?php
/**
 * Database Connection - Optimized
 * Uses credentials from config.php with connection pooling and performance settings
 */

require_once 'config.php';

/**
 * Pick an application database when DB_NAME is missing or invalid.
 */
function resolveApplicationDatabase(mysqli $connection) {
    $systemDatabases = ['mysql', 'information_schema', 'performance_schema', 'sys'];
    $candidates = [];

    $result = $connection->query("SHOW DATABASES");
    while ($row = $result->fetch_row()) {
        $dbName = (string)($row[0] ?? '');
        if ($dbName !== '' && !in_array($dbName, $systemDatabases, true)) {
            $candidates[] = $dbName;
        }
    }

    if (empty($candidates)) {
        return null;
    }

    // Prefer names that likely belong to this project.
    foreach ($candidates as $candidate) {
        if (stripos($candidate, 'calloway') !== false || stripos($candidate, 'pharmacy') !== false) {
            return $candidate;
        }
    }

    return $candidates[0];
}

// Use persistent connection for better performance (connection pooling)
// Note: SSL connections are created without the 'p:' prefix to avoid SSL handshake issues.
$host = IS_PRODUCTION ? 'p:' . DB_HOST : DB_HOST; // 'p:' prefix enables persistent connection

// Enable SSL automatically for Azure MySQL hosts, or via env override
$sslEnv = getenv('DB_SSL');
$useSsl = $sslEnv !== false
    ? filter_var($sslEnv, FILTER_VALIDATE_BOOLEAN)
    : (strpos(DB_HOST, '.mysql.database.azure.com') !== false);

$configuredDbName = trim((string) DB_NAME);
// Detect obviously invalid DB names: empty, contains Azure domain, or looks like a server/host name
$dbNameLooksInvalid = $configuredDbName === ''
    || strpos($configuredDbName, '.mysql.database.azure.com') !== false
    || preg_match('/^[a-z0-9_-]+-server$/i', $configuredDbName);

// Create connection with error reporting
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/**
 * Helper: connect via SSL without selecting a DB, then auto-detect.
 */
function _connectSslAutoDetect() {
    $conn = mysqli_init();
    if (!$conn) {
        throw new mysqli_sql_exception('Failed to initialize MySQL connection');
    }
    $conn->options(MYSQLI_OPT_SSL_VERIFY_SERVER_CERT, false);
    $conn->ssl_set(null, null, null, null, null);
    $conn->real_connect(DB_HOST, DB_USER, DB_PASS, null, 3306, null, MYSQLI_CLIENT_SSL);
    $resolvedDbName = resolveApplicationDatabase($conn);
    if (!$resolvedDbName) {
        throw new mysqli_sql_exception('No non-system database found. Set DB_NAME in App Settings.');
    }
    $conn->select_db($resolvedDbName);
    return $conn;
}

try {
    if ($useSsl) {
        if ($dbNameLooksInvalid) {
            $conn = _connectSslAutoDetect();
        } else {
            // Try the configured name; fall back to auto-detect if DB doesn't exist
            try {
                $conn = mysqli_init();
                if (!$conn) {
                    throw new mysqli_sql_exception('Failed to initialize MySQL connection');
                }
                $conn->options(MYSQLI_OPT_SSL_VERIFY_SERVER_CERT, false);
                $conn->ssl_set(null, null, null, null, null);
                $conn->real_connect(DB_HOST, DB_USER, DB_PASS, $configuredDbName, 3306, null, MYSQLI_CLIENT_SSL);
            } catch (mysqli_sql_exception $dbErr) {
                // 1049 = Unknown database – fall back to auto-detect
                error_log("DB '{$configuredDbName}' failed ({$dbErr->getMessage()}), attempting auto-detect…");
                $conn = _connectSslAutoDetect();
            }
        }
    } else {
        if ($dbNameLooksInvalid) {
            $conn = new mysqli($host, DB_USER, DB_PASS, '');
            $resolvedDbName = resolveApplicationDatabase($conn);
            if (!$resolvedDbName) {
                throw new mysqli_sql_exception('No non-system database found. Set DB_NAME in App Settings.');
            }
            $conn->select_db($resolvedDbName);
        } else {
            try {
                $conn = new mysqli($host, DB_USER, DB_PASS, $configuredDbName);
            } catch (mysqli_sql_exception $dbErr) {
                error_log("DB '{$configuredDbName}' failed ({$dbErr->getMessage()}), attempting auto-detect…");
                $conn = new mysqli($host, DB_USER, DB_PASS, '');
                $resolvedDbName = resolveApplicationDatabase($conn);
                if (!$resolvedDbName) {
                    throw new mysqli_sql_exception('No non-system database found. Set DB_NAME in App Settings.');
                }
                $conn->select_db($resolvedDbName);
            }
        }
    }
    
    // Set charset to utf8mb4 for full unicode support
    $conn->set_charset("utf8mb4");
    
    // Optimize connection settings for performance
    $conn->options(MYSQLI_OPT_INT_AND_FLOAT_NATIVE, 1); // Return native types
    
    // Set reasonable timeouts
    if (IS_PRODUCTION) {
        $conn->query("SET SESSION wait_timeout = 28800"); // 8 hours
        $conn->query("SET SESSION interactive_timeout = 28800");
    }
    
    // Set SQL mode for strict data handling
    $conn->query("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
    
} catch (mysqli_sql_exception $e) {
    // Log error instead of leaking it to user in production
    if (defined('DEBUG_MODE') && DEBUG_MODE) {
        die("Connection failed: " . $e->getMessage());
    } else {
        error_log("Database connection failed: " . $e->getMessage());
        die("System error. Please try again later.");
    }
}

/**
 * Get PDO connection (optional - for code that prefers PDO)
 * @return PDO|null
 */
function getPDOConnection() {
    static $pdo = null;
    
    if ($pdo === null) {
        try {
            $dsn = sprintf(
                'mysql:host=%s;dbname=%s;charset=utf8mb4',
                DB_HOST,
                DB_NAME
            );
            
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
            ];
            
            if (IS_PRODUCTION) {
                $options[PDO::ATTR_PERSISTENT] = true;
            }
            
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            error_log("PDO Connection failed: " . $e->getMessage());
            return null;
        }
    }
    
    return $pdo;
}

?>