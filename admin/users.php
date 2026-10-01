<?php
// admin/users.php - User Management & Role-Based Access Control (Admin Only)

require_once __DIR__ . '/../includes/auth.php';
requireAdminLogin();
requireMasterAdmin(); // Only Master Admins can manage users

$db = getDBConnection();
$message = sanitize($_GET['msg'] ?? '');
$error = sanitize($_GET['error'] ?? '');

// Handle CRUD operations
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = sanitize($_POST['action'] ?? '');
    $adminId = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $name = sanitize($_POST['name'] ?? '');
    $email = strtolower(trim(sanitize($_POST['email'] ?? '')));
    $password = $_POST['password'] ?? '';
    $role = sanitize($_POST['role'] ?? 'editor');
    $assignedProjects = $_POST['projects'] ?? []; // Array of project IDs

    // Normalize role name
    if (!in_array($role, ['master', 'admin', 'editor', 'sales'])) {
        $role = 'editor';
    }
    $dbRole = ($role === 'admin') ? 'master' : $role;

    if ($action === 'create') {
        if (empty($name) || empty($email) || empty($password)) {
            $error = 'Name, email, and password are required.';
        } else {
            // Check if email exists
            $stmt = $db->prepare("SELECT id FROM admins WHERE LOWER(email) = LOWER(:email)");
            $stmt->execute(['email' => $email]);
            if ($stmt->fetch()) {
                $error = 'An admin account with this email address already exists.';
            } else {
                $hash = password_hash($password, PASSWORD_BCRYPT);
                $stmt = $db->prepare("INSERT INTO admins (name, email, password_hash, role, created_at) VALUES (:name, :email, :hash, :role, NOW())");
                $stmt->execute(['name' => $name, 'email' => $email, 'hash' => $hash, 'role' => $dbRole]);
                $newAdminId = $db->lastInsertId();

                // Assign projects
                if (!empty($assignedProjects) && is_array($assignedProjects)) {
                    $stmtProj = $db->prepare("INSERT INTO admin_projects (admin_id, project_id) VALUES (:aid, :pid)");
                    foreach ($assignedProjects as $pid) {
                        $stmtProj->execute(['aid' => $newAdminId, 'pid' => (int)$pid]);
                    }
                }
                header("Location: users?msg=" . urlencode('User created successfully with role ' . getAdminRoleLabel($dbRole) . '!'));
                exit;
            }
        }
    } elseif ($action === 'update') {
        if ($adminId > 0 && !empty($name) && !empty($email)) {
            // Check if changing to an existing email used by another account
            $stmt = $db->prepare("SELECT id FROM admins WHERE LOWER(email) = LOWER(:email) AND id != :id");
            $stmt->execute(['email' => $email, 'id' => $adminId]);
            if ($stmt->fetch()) {
                $error = 'Another user account is already using this email address.';
            } else {
                if (!empty($password)) {
                    $hash = password_hash($password, PASSWORD_BCRYPT);
                    $stmt = $db->prepare("UPDATE admins SET name = :name, email = :email, role = :role, password_hash = :hash WHERE id = :id");
                    $stmt->execute(['name' => $name, 'email' => $email, 'role' => $dbRole, 'hash' => $hash, 'id' => $adminId]);
                } else {
                    $stmt = $db->prepare("UPDATE admins SET name = :name, email = :email, role = :role WHERE id = :id");
                    $stmt->execute(['name' => $name, 'email' => $email, 'role' => $dbRole, 'id' => $adminId]);
                }

                // Update assigned projects
                $db->prepare("DELETE FROM admin_projects WHERE admin_id = :id")->execute(['id' => $adminId]);
                if (!empty($assignedProjects) && is_array($assignedProjects)) {
                    $stmtProj = $db->prepare("INSERT INTO admin_projects (admin_id, project_id) VALUES (:aid, :pid)");
                    foreach ($assignedProjects as $pid) {
                        $stmtProj->execute(['aid' => $adminId, 'pid' => (int)$pid]);
                    }
                }

                if ($adminId === (int)$_SESSION['admin_id']) {
                    $_SESSION['admin_name'] = $name;
                    $_SESSION['admin_email'] = $email;
                    $_SESSION['admin_role'] = $dbRole;
                }
                
                header("Location: users?msg=" . urlencode('User updated successfully!'));
                exit;
            }
        }
    } elseif ($action === 'delete') {
        if ($adminId > 0 && $adminId != $_SESSION['admin_id']) {
            $db->prepare("DELETE FROM admin_projects WHERE admin_id = :id")->execute(['id' => $adminId]);
            $db->prepare("DELETE FROM admins WHERE id = :id")->execute(['id' => $adminId]);
            header("Location: users?msg=" . urlencode('User account deleted.'));
            exit;
        } else {
            $error = 'Cannot delete your own logged-in account.';
        }
    } elseif ($action === 'toggle_status') {
        if ($adminId > 0) {
            if ($adminId === (int)$_SESSION['admin_id']) {
                $error = 'You cannot deactivate your own logged-in account.';
            } else {
                $stmt = $db->prepare("UPDATE admins SET is_active = IF(is_active = 1, 0, 1) WHERE id = :id");
                $stmt->execute(['id' => $adminId]);
                
                $checkStmt = $db->prepare("SELECT is_active FROM admins WHERE id = :id");
                $checkStmt->execute(['id' => $adminId]);
                $st = $checkStmt->fetchColumn();
                $statusLabel = ((int)$st === 1) ? 'activated' : 'deactivated';
                
                header("Location: users?msg=" . urlencode("User account has been $statusLabel successfully."));
                exit;
            }
        }
    }

    if ($error) {
        header("Location: users?error=" . urlencode($error));
        exit;
    }
}

// Fetch all admin accounts and their assigned projects
$admins = $db->query("SELECT * FROM admins ORDER BY FIELD(role, 'master', 'editor', 'sales'), created_at DESC")->fetchAll();
foreach ($admins as &$admin) {
    $stmt = $db->prepare("SELECT p.id, p.title FROM admin_projects ap JOIN projects p ON ap.project_id = p.id WHERE ap.admin_id = :aid");
    $stmt->execute(['aid' => $admin['id']]);
    $admin['assigned_projects'] = $stmt->fetchAll();
    $admin['project_ids'] = array_column($admin['assigned_projects'], 'id');
}
unset($admin);

// Fetch all projects for the assignment dropdown/checkboxes
$allProjects = $db->query("SELECT id, title FROM projects ORDER BY title ASC")->fetchAll();

$pageTitle = "User Management & Roles - PitchVault";
include __DIR__ . '/../includes/header.php';
?>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 sm:py-8 w-full">

    <div class="md:flex md:items-center md:justify-between mb-8">
        <div>
            <h1 class="text-2xl sm:text-3xl font-extrabold text-slate-900 tracking-tight">User & Role Management</h1>
            <p class="text-sm text-slate-500 mt-1">Manage platform accounts and configure role permissions (Admin, Editor, Sales).</p>
        </div>
        <div class="mt-4 md:mt-0">
            <button onclick="openUserModal()" class="inline-flex items-center space-x-2 px-4 py-2.5 bg-brand-600 hover:bg-brand-700 text-white font-semibold rounded-xl text-sm transition shadow-md shadow-brand-600/20">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"></path></svg>
                <span>Add New User</span>
            </button>
        </div>
    </div>

    <!-- Alert Notifications -->
    <?php if ($message): ?>
        <div class="mb-6 p-4 bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm font-semibold rounded-xl flex items-center justify-between shadow-xs">
            <div class="flex items-center space-x-2.5">
                <svg class="w-5 h-5 text-emerald-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                <span><?= htmlspecialchars($message) ?></span>
            </div>
            <button onclick="this.parentElement.remove()" class="text-emerald-500 hover:text-emerald-700 text-sm font-bold">&times;</button>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="mb-6 p-4 bg-rose-50 border border-rose-200 text-rose-800 text-sm font-semibold rounded-xl flex items-center justify-between shadow-xs">
            <div class="flex items-center space-x-2.5">
                <svg class="w-5 h-5 text-rose-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                <span><?= htmlspecialchars($error) ?></span>
            </div>
            <button onclick="this.parentElement.remove()" class="text-rose-500 hover:text-rose-700 text-sm font-bold">&times;</button>
        </div>
    <?php endif; ?>

    <!-- Roles Matrix Cards Explanation -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-8">
        <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-xs">
            <div class="flex items-center space-x-2.5 mb-2">
                <span class="w-3 h-3 rounded-full bg-indigo-500"></span>
                <h3 class="font-bold text-slate-900 text-sm">Admin (Manage All)</h3>
            </div>
            <p class="text-xs text-slate-500 leading-relaxed">Full platform access. Create/edit/delete projects, manage videos, user accounts, and full share tracking.</p>
        </div>

        <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-xs">
            <div class="flex items-center space-x-2.5 mb-2">
                <span class="w-3 h-3 rounded-full bg-blue-500"></span>
                <h3 class="font-bold text-slate-900 text-sm">Editor (Manage Video)</h3>
            </div>
            <p class="text-xs text-slate-500 leading-relaxed">Can upload, edit, and delete videos within assigned projects. Cannot manage platform user accounts.</p>
        </div>

        <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-xs">
            <div class="flex items-center space-x-2.5 mb-2">
                <span class="w-3 h-3 rounded-full bg-emerald-500"></span>
                <h3 class="font-bold text-slate-900 text-sm">Sales (Share Only)</h3>
            </div>
            <p class="text-xs text-slate-500 leading-relaxed">Can <strong>ONLY Share</strong> projects or videos. Cannot edit or delete projects/videos, nor upload content.</p>
        </div>
    </div>

    <!-- Users Table -->
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse">
                <thead>
                    <tr class="bg-slate-50 border-b border-slate-200 text-[11px] font-bold text-slate-500 uppercase tracking-wider">
                        <th class="px-6 py-4">User Name</th>
                        <th class="px-6 py-4">Email Address</th>
                        <th class="px-6 py-4">Assigned Role</th>
                        <th class="px-6 py-4">Status</th>
                        <th class="px-6 py-4">Assigned Projects</th>
                        <th class="px-6 py-4">Created Date</th>
                        <th class="px-6 py-4 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 text-sm">
                    <?php foreach ($admins as $admin): 
                        $r = strtolower($admin['role']);
                        $isActive = (int)($admin['is_active'] ?? 1) === 1;
                    ?>
                        <tr class="hover:bg-slate-50/70 transition <?= !$isActive ? 'bg-slate-50/60' : '' ?>">
                            <td class="px-6 py-4 font-semibold text-slate-900">
                                <div class="flex items-center space-x-2.5">
                                    <div class="w-8 h-8 rounded-full bg-slate-100 text-slate-700 flex items-center justify-center text-xs font-bold shrink-0">
                                        <?= strtoupper(substr($admin['name'], 0, 1)) ?>
                                    </div>
                                    <div>
                                        <span class="block <?= !$isActive ? 'text-slate-400 line-through' : '' ?>"><?= htmlspecialchars($admin['name']) ?></span>
                                        <?php if ($admin['id'] == $_SESSION['admin_id']): ?>
                                            <span class="text-[10px] font-bold text-brand-600 bg-brand-50 px-1.5 py-0.5 rounded border border-brand-200">You</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                            <td class="px-6 py-4 text-slate-600 font-medium"><?= htmlspecialchars($admin['email']) ?></td>
                            <td class="px-6 py-4">
                                <?php if (in_array($r, ['master', 'admin'])): ?>
                                    <span class="inline-flex items-center px-2.5 py-1 rounded-md text-xs font-bold bg-indigo-50 text-indigo-700 border border-indigo-200">
                                        <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path></svg>
                                        Admin (Manage all)
                                    </span>
                                <?php elseif ($r === 'editor'): ?>
                                    <span class="inline-flex items-center px-2.5 py-1 rounded-md text-xs font-bold bg-blue-50 text-blue-700 border border-blue-200">
                                        <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path></svg>
                                        Editor (Manage Video)
                                    </span>
                                <?php elseif ($r === 'sales'): ?>
                                    <span class="inline-flex items-center px-2.5 py-1 rounded-md text-xs font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
                                        <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z"></path></svg>
                                        Sales (Share Only)
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4">
                                <?php if ($admin['id'] == $_SESSION['admin_id']): ?>
                                    <div class="inline-flex items-center space-x-2 opacity-80" title="You cannot deactivate your logged-in account">
                                        <span class="relative inline-flex h-6 w-11 shrink-0 cursor-not-allowed rounded-full border-2 border-transparent bg-emerald-500 transition-colors">
                                            <span class="pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow-md ring-0 translate-x-5"></span>
                                        </span>
                                        <span class="text-xs font-semibold text-emerald-700">Active</span>
                                    </div>
                                <?php else: ?>
                                    <form method="POST" action="<?= getBaseUrl() ?>/admin/users" class="inline">
                                        <input type="hidden" name="action" value="toggle_status">
                                        <input type="hidden" name="id" value="<?= $admin['id'] ?>">
                                        <button type="submit" 
                                                class="inline-flex items-center space-x-2 group focus:outline-none cursor-pointer"
                                                title="Click to <?= $isActive ? 'deactivate' : 'activate' ?> user">
                                            <span class="relative inline-flex h-6 w-11 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out <?= $isActive ? 'bg-emerald-500 group-hover:bg-emerald-600' : 'bg-slate-300 group-hover:bg-slate-400' ?>">
                                                <span class="pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow-md ring-0 transition duration-200 ease-in-out <?= $isActive ? 'translate-x-5' : 'translate-x-0' ?>"></span>
                                            </span>
                                            <span class="text-xs font-semibold transition-colors <?= $isActive ? 'text-emerald-700 font-bold' : 'text-slate-500' ?>">
                                                <?= $isActive ? 'Active' : 'Inactive' ?>
                                            </span>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 text-slate-500 text-xs">
                                <?php if (in_array($r, ['master', 'admin'])): ?>
                                    <span class="text-indigo-600 font-semibold">All Projects (Unrestricted)</span>
                                <?php elseif (empty($admin['assigned_projects'])): ?>
                                    <span class="text-slate-400 italic">All Projects</span>
                                <?php else: ?>
                                    <div class="flex flex-wrap gap-1 max-w-xs">
                                        <?php foreach ($admin['assigned_projects'] as $p): ?>
                                            <span class="px-2 py-0.5 bg-slate-100 border border-slate-200 text-slate-700 rounded-md text-[11px] font-medium">
                                                <?= htmlspecialchars($p['title']) ?>
                                            </span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 text-slate-500 text-xs"><?= date('M d, Y', strtotime($admin['created_at'])) ?></td>
                            <td class="px-6 py-4 text-right space-x-2">
                                <button onclick='openEditModal(<?= json_encode($admin) ?>)' class="px-2.5 py-1 text-xs font-semibold text-brand-600 hover:text-brand-800 hover:bg-brand-50 rounded-lg transition border border-brand-200">
                                    Edit
                                </button>
                                <?php if ($admin['id'] != $_SESSION['admin_id']): ?>
                                    <form method="POST" action="<?= getBaseUrl() ?>/admin/users" onsubmit="return confirm('Are you sure you want to delete this user account?');" class="inline">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= $admin['id'] ?>">
                                        <button type="submit" class="px-2.5 py-1 text-xs font-semibold text-rose-600 hover:text-rose-800 hover:bg-rose-50 rounded-lg transition border border-rose-200">
                                            Delete
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Create / Edit User Modal -->
<div id="userModal" class="fixed inset-0 z-50 hidden flex items-center justify-center bg-slate-900/60 backdrop-blur-sm p-4">
    <div class="bg-white rounded-2xl shadow-2xl max-w-md w-full border border-slate-200 overflow-hidden flex flex-col max-h-[90vh]">
        <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between shrink-0 bg-slate-50/50">
            <h3 id="modalTitle" class="font-bold text-slate-900 text-lg">Add New User</h3>
            <button onclick="closeUserModal()" class="text-slate-400 hover:text-slate-600 p-1.5 rounded-lg">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
            </button>
        </div>

        <div class="overflow-y-auto p-6">
            <form method="POST" action="<?= getBaseUrl() ?>/admin/users" class="space-y-4" id="userForm">
                <input type="hidden" id="formAction" name="action" value="create">
                <input type="hidden" id="adminId" name="id" value="0">

                <div>
                    <label for="adminName" class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">Full Name</label>
                    <input type="text" id="adminName" name="name" required placeholder="John Smith"
                           class="w-full px-3.5 py-2.5 bg-slate-50/50 border border-slate-300 rounded-xl text-sm focus:ring-2 focus:ring-brand-500">
                </div>

                <div>
                    <label for="adminEmail" class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">Email Address</label>
                    <input type="email" id="adminEmail" name="email" required placeholder="john@example.com"
                           class="w-full px-3.5 py-2.5 bg-slate-50/50 border border-slate-300 rounded-xl text-sm focus:ring-2 focus:ring-brand-500">
                </div>

                <!-- Role Selection -->
                <div>
                    <label for="adminRoleSelect" class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">User Role & Permissions</label>
                    <select id="adminRoleSelect" name="role" required class="w-full px-3.5 py-2.5 bg-slate-50/50 border border-slate-300 rounded-xl text-sm font-semibold focus:ring-2 focus:ring-brand-500 text-slate-800">
                        <option value="admin">Admin (Manage all)</option>
                        <option value="editor" selected>Editor (Manage Video)</option>
                        <option value="sales">Sales (Can only Share Project or Videos)</option>
                    </select>
                    <p class="text-[11px] text-slate-500 mt-1">
                        <strong>Sales role</strong> can only generate & manage share permissions, but cannot upload/edit videos or projects.
                    </p>
                </div>

                <div>
                    <label for="adminPassword" class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">
                        Password <span id="passwordHint" class="text-slate-400 font-normal normal-case ml-1"></span>
                    </label>
                    <input type="password" id="adminPassword" name="password" placeholder="••••••••"
                           class="w-full px-3.5 py-2.5 bg-slate-50/50 border border-slate-300 rounded-xl text-sm focus:ring-2 focus:ring-brand-500">
                </div>

                <div class="pt-2">
                    <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-2">Assigned Projects</label>
                    <div class="bg-slate-50/50 border border-slate-200 rounded-xl p-3 max-h-40 overflow-y-auto space-y-2">
                        <?php if (empty($allProjects)): ?>
                            <p class="text-xs text-slate-500">No projects available.</p>
                        <?php else: ?>
                            <?php foreach ($allProjects as $proj): ?>
                                <label class="flex items-center space-x-2 text-sm text-slate-700">
                                    <input type="checkbox" name="projects[]" value="<?= $proj['id'] ?>" class="project-checkbox rounded text-brand-600 focus:ring-brand-500">
                                    <span><?= htmlspecialchars($proj['title']) ?></span>
                                </label>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="pt-4 flex justify-end space-x-2 border-t border-slate-100">
                    <button type="button" onclick="closeUserModal()" class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-sm font-semibold transition">Cancel</button>
                    <button type="submit" class="px-4 py-2 bg-brand-600 hover:bg-brand-700 text-white rounded-xl text-sm font-semibold transition shadow-md shadow-brand-600/20">Save User</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
const userModal = document.getElementById('userModal');
const modalTitle = document.getElementById('modalTitle');
const formAction = document.getElementById('formAction');
const adminId = document.getElementById('adminId');
const adminName = document.getElementById('adminName');
const adminEmail = document.getElementById('adminEmail');
const adminRoleSelect = document.getElementById('adminRoleSelect');
const adminPassword = document.getElementById('adminPassword');
const passwordHint = document.getElementById('passwordHint');
const projectCheckboxes = document.querySelectorAll('.project-checkbox');

function openUserModal() {
    modalTitle.textContent = 'Add New User';
    formAction.value = 'create';
    adminId.value = '0';
    adminName.value = '';
    adminEmail.value = '';
    adminRoleSelect.value = 'editor';
    adminPassword.value = '';
    adminPassword.required = true;
    passwordHint.textContent = '';
    
    projectCheckboxes.forEach(cb => cb.checked = false);
    userModal.classList.remove('hidden');
}

function closeUserModal() {
    userModal.classList.add('hidden');
}

function openEditModal(admin) {
    modalTitle.textContent = 'Edit User Account';
    formAction.value = 'update';
    adminId.value = admin.id;
    adminName.value = admin.name;
    adminEmail.value = admin.email;
    
    var r = admin.role.toLowerCase();
    if (r === 'master' || r === 'admin') {
        adminRoleSelect.value = 'admin';
    } else if (r === 'sales') {
        adminRoleSelect.value = 'sales';
    } else {
        adminRoleSelect.value = 'editor';
    }

    adminPassword.value = '';
    adminPassword.required = false;
    passwordHint.textContent = '(Leave blank to keep current)';
    
    projectCheckboxes.forEach(cb => {
        cb.checked = (admin.project_ids || []).includes(parseInt(cb.value));
    });

    userModal.classList.remove('hidden');
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
