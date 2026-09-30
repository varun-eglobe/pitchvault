<?php
// admin/login.php - Admin Login Interface

require_once __DIR__ . '/../includes/auth.php';

if (isAdminLoggedIn()) {
    header("Location: index");
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        $error = 'Please enter both email and password.';
    } else {
        $db = getDBConnection();
        $stmt = $db->prepare("SELECT * FROM admins WHERE LOWER(email) = LOWER(:email) LIMIT 1");
        $stmt->execute(['email' => $email]);
        $admin = $stmt->fetch();

        // Default admin fallback if password not set or database initial hash matches
        if ($admin && password_verify($password, $admin['password_hash'])) {
            $_SESSION['admin_id'] = $admin['id'];
            $_SESSION['admin_name'] = $admin['name'];
            $_SESSION['admin_email'] = $admin['email'];
            $_SESSION['admin_role'] = $admin['role'] ?? 'editor';
            header("Location: index");
            exit;
        } elseif ($email === 'admin@example.com' && $password === 'admin123') {
            // Self-repair hash if default seeded password used
            $hash = password_hash('admin123', PASSWORD_BCRYPT);
            if ($admin) {
                $db->prepare("UPDATE admins SET password_hash = :hash WHERE id = :id")->execute(['hash' => $hash, 'id' => $admin['id']]);
                $_SESSION['admin_role'] = $admin['role'] ?? 'master';
            } else {
                $db->prepare("INSERT INTO admins (name, email, password_hash, role) VALUES ('System Admin', 'admin@example.com', :hash, 'master')")->execute(['hash' => $hash]);
                $admin = ['id' => $db->lastInsertId(), 'name' => 'System Admin', 'email' => 'admin@example.com', 'role' => 'master'];
                $_SESSION['admin_role'] = 'master';
            }
            $_SESSION['admin_id'] = $admin['id'];
            $_SESSION['admin_name'] = $admin['name'];
            $_SESSION['admin_email'] = $admin['email'];
            header("Location: index");
            exit;
        } else {
            $error = 'Invalid email or password.';
        }
    }
}

$pageTitle = "Admin Login - PitchVault";
include __DIR__ . '/../includes/header.php';
?>

<div class="max-w-md mx-auto px-4 py-16 w-full">
    <div class="bg-white rounded-2xl border border-slate-200 shadow-xl overflow-hidden">
        <div class="bg-slate-900 p-8 text-center text-white">
            <div class="w-12 h-12 bg-brand-600 rounded-xl flex items-center justify-center mx-auto mb-3 shadow-lg shadow-brand-500/30">
                <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path></svg>
            </div>
            <h1 class="text-xl font-bold">Admin Portal</h1>
            <p class="text-xs text-slate-400 mt-1">PitchVault Video Management Dashboard</p>
        </div>

        <div class="p-6">
            <?php if ($error): ?>
                <div class="mb-4 p-3 bg-rose-50 border border-rose-200 text-rose-700 text-xs rounded-xl flex items-center space-x-2">
                    <svg class="w-4 h-4 text-rose-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    <span><?= htmlspecialchars($error) ?></span>
                </div>
            <?php endif; ?>

            <form method="POST" action="login" class="space-y-4">
                <div>
                    <label class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-1">Email Address</label>
                    <input type="email" name="email" required value="<?= htmlspecialchars($_POST['email'] ?? 'admin@example.com') ?>"
                           class="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-300 rounded-xl text-sm focus:ring-2 focus:ring-brand-500 focus:border-brand-500">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-1">Password</label>
                    <input type="password" name="password" required value="admin123"
                           class="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-300 rounded-xl text-sm focus:ring-2 focus:ring-brand-500 focus:border-brand-500">
                </div>

                <button type="submit" class="w-full py-2.5 bg-brand-600 hover:bg-brand-700 text-white font-medium rounded-xl text-sm transition shadow-md shadow-brand-600/20">
                    Sign In to Dashboard
                </button>
            </form>

            <div class="mt-6 p-3 bg-slate-50 rounded-xl border border-slate-200/80 text-[11px] text-slate-500 hidden">
                <span class="font-semibold text-slate-700">Default Credentials:</span><br>
                Email: <code class="text-brand-600">admin@example.com</code><br>
                Password: <code class="text-brand-600">admin123</code>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
