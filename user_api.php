<?php
/**
 * User Management API
 * Handles user CRUD operations, roles, and activity logs
 */

require_once 'db_connection.php';
require_once 'Auth.php';
require_once 'ActivityLogger.php';

header('Content-Type: application/json');

$auth = new Auth($conn);

function ensureUserEmployeeColumn($conn)
{
    $check = $conn->query("SELECT COUNT(*) AS cnt FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'employee_id'");
    if ($check && (int)$check->fetch_assoc()['cnt'] === 0) {
        $conn->query("ALTER TABLE users ADD COLUMN employee_id INT NULL, ADD INDEX idx_employee (employee_id)");
    }
}

function employeesExist($conn)
{
    $table = $conn->query("SHOW TABLES LIKE 'employees'");
    if (!$table || $table->num_rows === 0) {
        return false;
    }
    $count = $conn->query("SELECT COUNT(*) AS cnt FROM employees");
    if (!$count) {
        return false;
    }
    return ((int)$count->fetch_assoc()['cnt']) > 0;
}

function employeesTableExists($conn)
{
    $table = $conn->query("SHOW TABLES LIKE 'employees'");
    return $table && $table->num_rows > 0;
}

ensureUserEmployeeColumn($conn);

// Check authentication
if (!$auth->isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit;
}

$action = $_GET['action'] ?? '';

switch($action) {
    case 'get_stats':
        if (!$auth->hasPermission('users.view')) {
            echo json_encode(['success' => false, 'message' => 'Permission denied']);
            exit;
        }
        
        $totalUsers = $conn->query("SELECT COUNT(*) as count FROM users")->fetch_assoc()['count'];
        $activeUsers = $conn->query("SELECT COUNT(*) as count FROM users WHERE is_active = 1")->fetch_assoc()['count'];
        $totalRoles = $conn->query("SELECT COUNT(*) as count FROM roles")->fetch_assoc()['count'];
        
        echo json_encode([
            'success' => true,
            'data' => [
                'total_users' => $totalUsers,
                'active_users' => $activeUsers,
                'total_roles' => $totalRoles
            ]
        ]);
        break;
        
    case 'get_users':
        if (!$auth->hasPermission('users.view')) {
            echo json_encode(['success' => false, 'message' => 'Permission denied']);
            exit;
        }

        if (employeesTableExists($conn)) {
            $query = "SELECT u.*, r.role_name, e.name AS employee_name
                      FROM users u
                      LEFT JOIN roles r ON u.role_id = r.role_id
                      LEFT JOIN employees e ON u.employee_id = e.id
                      ORDER BY u.created_at DESC";
        } else {
            $query = "SELECT u.*, r.role_name, NULL AS employee_name
                      FROM users u
                      LEFT JOIN roles r ON u.role_id = r.role_id
                      ORDER BY u.created_at DESC";
        }
        
        $result = $conn->query($query);
        $users = $result->fetch_all(MYSQLI_ASSOC);
        
        echo json_encode([
            'success' => true,
            'data' => $users
        ]);
        break;
        
    case 'get_user':
        if (!$auth->hasPermission('users.view')) {
            echo json_encode(['success' => false, 'message' => 'Permission denied']);
            exit;
        }
        
        $userId = $_GET['id'] ?? 0;
        
        if (employeesTableExists($conn)) {
            $stmt = $conn->prepare("SELECT u.*, r.role_name, e.name AS employee_name
                                    FROM users u
                                    LEFT JOIN roles r ON u.role_id = r.role_id
                                    LEFT JOIN employees e ON u.employee_id = e.id
                                    WHERE u.user_id = ?");
        } else {
            $stmt = $conn->prepare("SELECT u.*, r.role_name, NULL AS employee_name
                                    FROM users u
                                    LEFT JOIN roles r ON u.role_id = r.role_id
                                    WHERE u.user_id = ?");
        }
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        
        if ($user) {
            echo json_encode([
                'success' => true,
                'data' => $user
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'User not found'
            ]);
        }
        break;
        
    case 'create_user':
        if (!$auth->hasPermission('users.create')) {
            echo json_encode(['success' => false, 'message' => 'Permission denied']);
            exit;
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        $username = trim($input['username'] ?? '');
        $email = trim($input['email'] ?? '');
        $password = $input['password'] ?? '';
        $fullName = trim($input['full_name'] ?? '');
        $roleId = intval($input['role_id'] ?? 0);
        $isActive = intval($input['is_active'] ?? 1);
        
        // Validation
        if (empty($username) || empty($password)) {
            echo json_encode(['success' => false, 'message' => 'Username and password are required']);
            exit;
        }
        
        if (empty($email)) {
            echo json_encode(['success' => false, 'message' => 'Email is required']);
            exit;
        }
        
        if ($roleId <= 0) {
            echo json_encode(['success' => false, 'message' => 'Please select a valid role']);
            exit;
        }
        
        // Check if username exists
        $stmt = $conn->prepare("SELECT user_id FROM users WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            echo json_encode(['success' => false, 'message' => 'Username already exists']);
            exit;
        }
        
        // Check if email exists
        $stmt = $conn->prepare("SELECT user_id FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            echo json_encode(['success' => false, 'message' => 'Email address already exists']);
            exit;
        }
        
        // Verify role exists
        $stmt = $conn->prepare("SELECT role_id FROM roles WHERE role_id = ?");
        $stmt->bind_param("i", $roleId);
        $stmt->execute();
        if ($stmt->get_result()->num_rows === 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid role selected']);
            exit;
        }
        
        // Hash password
        $hashedPassword = password_hash($password, PASSWORD_BCRYPT);
        
        $stmt = $conn->prepare("INSERT INTO users (username, email, password_hash, full_name, role_id, is_active) 
                    VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssssii", $username, $email, $hashedPassword, $fullName, $roleId, $isActive);
        
        if ($stmt->execute()) {
            $auth->logActivity($auth->getCurrentUser()['user_id'], 'CREATE', 'users', "Created user: $username");
            
            echo json_encode([
                'success' => true,
                'message' => 'User created successfully',
                'user_id' => $stmt->insert_id
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to create user: ' . $conn->error]);
        }
        break;
        
    case 'update_user':
        if (!$auth->hasPermission('users.edit')) {
            echo json_encode(['success' => false, 'message' => 'Permission denied']);
            exit;
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        $userId = intval($input['user_id'] ?? 0);
        $username = trim($input['username'] ?? '');
        $email = trim($input['email'] ?? '');
        $password = $input['password'] ?? '';
        $fullName = trim($input['full_name'] ?? '');
        $roleId = intval($input['role_id'] ?? 0);
        $isActive = intval($input['is_active'] ?? 1);
        
        if (empty($username)) {
            echo json_encode(['success' => false, 'message' => 'Username is required']);
            exit;
        }
        
        if (empty($email)) {
            echo json_encode(['success' => false, 'message' => 'Email is required']);
            exit;
        }
        
        if ($roleId <= 0) {
            echo json_encode(['success' => false, 'message' => 'Please select a valid role']);
            exit;
        }
        
        // Check if username exists for other users
        $stmt = $conn->prepare("SELECT user_id FROM users WHERE username = ? AND user_id != ?");
        $stmt->bind_param("si", $username, $userId);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            echo json_encode(['success' => false, 'message' => 'Username already exists']);
            exit;
        }
        
        // Check if email exists for other users
        $stmt = $conn->prepare("SELECT user_id FROM users WHERE email = ? AND user_id != ?");
        $stmt->bind_param("si", $email, $userId);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            echo json_encode(['success' => false, 'message' => 'Email address already exists']);
            exit;
        }
        
        if (!empty($password)) {
            // Update with new password
            $hashedPassword = password_hash($password, PASSWORD_BCRYPT);
            $stmt = $conn->prepare("UPDATE users 
                                   SET username = ?, email = ?, password_hash = ?, full_name = ?, role_id = ?, is_active = ?
                                   WHERE user_id = ?");
            $stmt->bind_param("ssssiii", $username, $email, $hashedPassword, $fullName, $roleId, $isActive, $userId);
        } else {
            // Update without password change
            $stmt = $conn->prepare("UPDATE users 
                                   SET username = ?, email = ?, full_name = ?, role_id = ?, is_active = ?
                                   WHERE user_id = ?");
            $stmt->bind_param("sssiii", $username, $email, $fullName, $roleId, $isActive, $userId);
        }
        
        if ($stmt->execute()) {
            $auth->logActivity($auth->getCurrentUser()['user_id'], 'UPDATE', 'users', "Updated user: $username");
            
            echo json_encode([
                'success' => true,
                'message' => 'User updated successfully'
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to update user']);
        }
        break;
        
    case 'delete_user':
        if (!$auth->hasPermission('users.delete')) {
            echo json_encode(['success' => false, 'message' => 'Permission denied']);
            exit;
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        $userId = $input['user_id'] ?? 0;
        
        // Don't allow deleting current user
        if ($userId == $auth->getCurrentUser()['user_id']) {
            echo json_encode(['success' => false, 'message' => 'Cannot delete your own account']);
            exit;
        }
        
        // Get username before deletion
        $stmt = $conn->prepare("SELECT username FROM users WHERE user_id = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $username = $stmt->get_result()->fetch_assoc()['username'] ?? 'Unknown';
        
        $stmt = $conn->prepare("DELETE FROM users WHERE user_id = ?");
        $stmt->bind_param("i", $userId);
        
        if ($stmt->execute()) {
            $auth->logActivity($auth->getCurrentUser()['user_id'], 'DELETE', 'users', "Deleted user: $username");
            
            echo json_encode([
                'success' => true,
                'message' => 'User deleted successfully'
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to delete user']);
        }
        break;
        
    case 'get_roles':
        if (!$auth->hasPermission('users.view')) {
            echo json_encode(['success' => false, 'message' => 'Permission denied']);
            exit;
        }
        
        $query = "SELECT r.*, 
                         (SELECT COUNT(*) FROM users WHERE role_id = r.role_id) as users_count,
                         (SELECT COUNT(*) FROM role_permissions WHERE role_id = r.role_id) as permissions_count
                  FROM roles r
                  ORDER BY r.role_name";
        
        $result = $conn->query($query);
        $roles = $result->fetch_all(MYSQLI_ASSOC);
        
        echo json_encode([
            'success' => true,
            'data' => $roles
        ]);
        break;
        
    case 'get_employees':
        if (!$auth->hasPermission('users.view')) {
            echo json_encode(['success' => false, 'message' => 'Permission denied']);
            exit;
        }

        $employees = [];
        if (employeesTableExists($conn)) {
            $result = $conn->query("SELECT id, name FROM employees ORDER BY name ASC");
            if ($result) {
                $employees = $result->fetch_all(MYSQLI_ASSOC);
            }
        }

        echo json_encode(['success' => true, 'data' => $employees]);
        break;

    case 'get_role_permissions':
        if (!$auth->hasPermission('roles.manage')) {
            echo json_encode(['success' => false, 'message' => 'Permission denied']);
            exit;
        }
        $roleId = isset($_GET['role_id']) ? intval($_GET['role_id']) : 0;
        if ($roleId <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid role ID']);
            exit;
        }
        $stmt = $conn->prepare(
            "SELECT p.permission_name
             FROM role_permissions rp
             JOIN permissions p ON p.permission_id = rp.permission_id
             WHERE rp.role_id = ?
             ORDER BY p.permission_name"
        );
        $stmt->bind_param('i', $roleId);
        $stmt->execute();
        $result = $stmt->get_result();
        $perms = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        echo json_encode(['success' => true, 'data' => $perms]);
        break;

    case 'get_active_users':
        if (!$auth->hasPermission('users.view')) {
            echo json_encode(['success' => false, 'message' => 'Permission denied']);
            exit;
        }
        $result = $conn->query("SELECT user_id, username, full_name FROM users WHERE is_active = 1 ORDER BY full_name");
        $users = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) $users[] = $row;
        }
        echo json_encode(['success' => true, 'data' => $users]);
        break;

    case 'get_login_sessions':
        if (!$auth->hasPermission('users.view')) {
            echo json_encode(['success' => false, 'message' => 'Permission denied']);
            exit;
        }
        $logger = new ActivityLogger($conn);
        $filters = [];
        if (!empty($_GET['user_id'])) $filters['user_id'] = (int)$_GET['user_id'];
        if (!empty($_GET['date_from'])) $filters['date_from'] = $_GET['date_from'];
        if (!empty($_GET['date_to'])) $filters['date_to'] = $_GET['date_to'];
        if (!empty($_GET['status'])) $filters['status'] = $_GET['status'];
        $filters['limit'] = 100;
        echo json_encode(['success' => true, 'data' => $logger->getLoginSessions($filters)]);
        break;

    case 'get_change_logs':
        if (!$auth->hasPermission('users.view')) {
            echo json_encode(['success' => false, 'message' => 'Permission denied']);
            exit;
        }
        $logger = new ActivityLogger($conn);
        $filters = [];
        if (!empty($_GET['user_id'])) $filters['user_id'] = (int)$_GET['user_id'];
        if (!empty($_GET['module'])) $filters['module'] = $_GET['module'];
        if (!empty($_GET['action_type'])) $filters['action_type'] = $_GET['action_type'];
        if (!empty($_GET['date_from'])) $filters['date_from'] = $_GET['date_from'];
        if (!empty($_GET['date_to'])) $filters['date_to'] = $_GET['date_to'];
        $filters['limit'] = 100;
        echo json_encode(['success' => true, 'data' => $logger->getChangeLogs($filters)]);
        break;
        
    default:
        echo json_encode([
            'success' => false,
            'message' => 'Invalid action'
        ]);
}
?>
