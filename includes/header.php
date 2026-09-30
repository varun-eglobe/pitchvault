<?php
// includes/header.php - Shared Navigation and Layout Header
require_once __DIR__ . '/auth.php';
$baseUrl = getBaseUrl();
$pageTitle = $pageTitle ?? 'Private Pitching Video Platform';
?>
<!DOCTYPE html>
<html lang="en" class="h-full bg-slate-50 font-sans text-slate-900 antialiased">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <!-- High-Resolution Favicons & PWA Add to Homescreen Meta Icons -->
    <link rel="icon" type="image/svg+xml" href="<?= $baseUrl ?>/favicon.svg">
    <link rel="icon" type="image/png" sizes="64x64" href="<?= $baseUrl ?>/favicon.png">
    <link rel="apple-touch-icon" sizes="180x180" href="<?= $baseUrl ?>/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="192x192" href="<?= $baseUrl ?>/icon-192.png">
    <link rel="icon" type="image/png" sizes="512x512" href="<?= $baseUrl ?>/icon-512.png">
    <link rel="manifest" href="<?= $baseUrl ?>/manifest.php?url=<?= urlencode($_SERVER['REQUEST_URI'] ?? './') ?>">
    <meta name="theme-color" content="#1d4ed8">
    <meta name="apple-mobile-web-app-title" content="PitchVault">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        brand: {
                            50: '#f0f7ff',
                            100: '#e0effe',
                            500: '#2563eb',
                            600: '#1d4ed8',
                            700: '#1e40af',
                            800: '#1e3a8a',
                            900: '#172554',
                        }
                    }
                }
            }
        }
    </script>
    <!-- Google Fonts Inter -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; }
        .glass-header {
            background: rgba(255, 255, 255, 0.85);
            backdrop-filter: blur(12px);
        }
    </style>
    <script>
        function escapeHtml(str) {
            if (!str) return '';
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function copyToClipboard(text) {
            if (navigator.clipboard && window.isSecureContext) {
                return navigator.clipboard.writeText(text).catch(function() {
                    return fallbackCopyText(text);
                });
            } else {
                return fallbackCopyText(text);
            }
        }

        function fallbackCopyText(text) {
            return new Promise(function(resolve, reject) {
                try {
                    var textArea = document.createElement("textarea");
                    textArea.value = text;
                    textArea.setAttribute('readonly', '');
                    textArea.style.position = "fixed";
                    textArea.style.top = "0";
                    textArea.style.left = "0";
                    textArea.style.width = "2em";
                    textArea.style.height = "2em";
                    textArea.style.padding = "0";
                    textArea.style.border = "none";
                    textArea.style.outline = "none";
                    textArea.style.boxShadow = "none";
                    textArea.style.background = "transparent";
                    document.body.appendChild(textArea);
                    textArea.focus();
                    textArea.select();
                    textArea.setSelectionRange(0, 99999);
                    var successful = document.execCommand('copy');
                    document.body.removeChild(textArea);
                    if (successful) {
                        resolve();
                    } else {
                        reject(new Error('Copy command failed'));
                    }
                } catch (err) {
                    reject(err);
                }
            });
        }
    </script>
</head>
<body class="flex flex-col min-h-full bg-slate-50">

<!-- Navigation Bar -->
<?php $currentScript = basename($_SERVER['PHP_SELF'], '.php'); ?>
<header class="sticky top-0 z-40 border-b border-slate-200 glass-header">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between h-16 items-center">
            <!-- Brand Logo -->
            <div class="flex items-center space-x-2 sm:space-x-3 shrink-0">
                <a href="<?= isAdminLoggedIn() ? $baseUrl . '/admin/index' : $baseUrl . '/index' ?>" class="flex items-center space-x-2 sm:space-x-2.5 group" title="<?= isAdminLoggedIn() ? 'Dashboard' : 'Viewer Dashboard' ?>">
                    <div class="w-8 h-8 sm:w-9 sm:h-9 rounded-xl bg-gradient-to-tr from-brand-600 to-indigo-500 flex items-center justify-center text-white shadow-md shadow-brand-500/20 shrink-0 group-hover:scale-105 transition-transform">
                        <svg class="w-4 h-4 sm:w-5 sm:h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"></path>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                    </div>
                    <span class="font-bold text-base sm:text-lg text-slate-900 tracking-tight leading-tight">
                        <small class="text-gray-500 font-normal block text-[10px] sm:text-xs">eglobe's</small> PitchVault
                    </span>
                </a>

                <span class="hidden sm:inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-medium bg-brand-50 text-brand-700 border border-brand-200">Private</span>
            </div>

            <!-- Header Menu / Auth status -->
            <div class="flex items-center space-x-2 sm:space-x-3">
                <?php if (isAdminLoggedIn()): ?>
                    <!-- Desktop Navigation Links -->
                    <nav class="hidden md:flex items-center space-x-1">
                        <a href="<?= $baseUrl ?>/admin/index" class="inline-flex items-center space-x-2 px-3 py-2 rounded-lg text-sm font-medium <?= $currentScript === 'index' ? 'bg-brand-50 text-brand-700 font-semibold' : 'text-slate-700 hover:text-slate-900 hover:bg-slate-100' ?> transition">
                            <svg class="w-4 h-4 <?= $currentScript === 'index' ? 'text-brand-600' : 'text-slate-500' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"></path>
                            </svg>
                            <span>Dashboard</span>
                        </a>
                        <a href="<?= $baseUrl ?>/admin/projects" class="inline-flex items-center space-x-2 px-3 py-2 rounded-lg text-sm font-medium <?= in_array($currentScript, ['projects', 'project']) ? 'bg-brand-50 text-brand-700 font-semibold' : 'text-slate-700 hover:text-slate-900 hover:bg-slate-100' ?> transition">
                            <svg class="w-4 h-4 <?= in_array($currentScript, ['projects', 'project']) ? 'text-brand-600' : 'text-slate-500' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"></path>
                            </svg>
                            <span>Projects</span>
                        </a>
                        <a href="<?= $baseUrl ?>/admin/shares" class="inline-flex items-center space-x-2 px-3 py-2 rounded-lg text-sm font-medium <?= $currentScript === 'shares' ? 'bg-brand-50 text-brand-700 font-semibold' : 'text-slate-700 hover:text-slate-900 hover:bg-slate-100' ?> transition">
                            <svg class="w-4 h-4 <?= $currentScript === 'shares' ? 'text-brand-600' : 'text-slate-500' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z"></path>
                            </svg>
                            <span>Shares</span>
                        </a>
                        <?php if (!isSalesAdmin()): ?>
                            <a href="<?= $baseUrl ?>/admin/analytics" class="inline-flex items-center space-x-2 px-3 py-2 rounded-lg text-sm font-medium <?= $currentScript === 'analytics' ? 'bg-brand-50 text-brand-700 font-semibold' : 'text-slate-700 hover:text-slate-900 hover:bg-slate-100' ?> transition">
                                <svg class="w-4 h-4 <?= $currentScript === 'analytics' ? 'text-brand-600' : 'text-slate-500' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 012 2h2a2 2 0 012-2z"></path>
                                </svg>
                                <span>Analytics</span>
                            </a>
                        <?php endif; ?>
                        <?php if (isMasterAdmin()): ?>
                            <a href="<?= $baseUrl ?>/admin/users" class="inline-flex items-center space-x-2 px-3 py-2 rounded-lg text-sm font-medium <?= $currentScript === 'users' ? 'bg-brand-50 text-brand-700 font-semibold' : 'text-slate-700 hover:text-slate-900 hover:bg-slate-100' ?> transition">
                                <svg class="w-4 h-4 <?= $currentScript === 'users' ? 'text-brand-600' : 'text-slate-500' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path>
                                </svg>
                                <span>Users</span>
                            </a>
                        <?php endif; ?>
                    </nav>

                    <div class="h-5 w-px bg-slate-200 hidden md:block"></div>

                    <!-- User Actions -->
                    <div class="flex items-center space-x-1 sm:space-x-2">
                        <span class="hidden sm:inline-flex items-center space-x-1.5 px-2 py-1 text-slate-700 text-xs font-semibold cursor-default truncate max-w-[220px]" title="<?= htmlspecialchars(($_SESSION['admin_name'] ?? 'Admin') . ' (' . getAdminRoleLabel() . ')') ?>">
                            <svg class="w-3.5 h-3.5 text-slate-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path></svg>
                            <span class="truncate"><?= htmlspecialchars($_SESSION['admin_name'] ?? 'Admin') ?></span>
                            <span class="text-[10px] font-extrabold px-1.5 py-0.5 rounded bg-slate-100 text-slate-700 border border-slate-200 uppercase tracking-tight shrink-0">
                                <?= isMasterAdmin() ? 'Admin' : (isSalesAdmin() ? 'Sales' : 'Editor') ?>
                            </span>
                        </span>
                        
                        <a href="<?= $baseUrl ?>/admin/profile" class="p-1.5 text-slate-400 hover:text-slate-700 hover:bg-slate-100 rounded-xl transition <?= $currentScript === 'profile' ? 'text-brand-600 bg-brand-50' : '' ?>" title="Account Settings & Security">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path>
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                            </svg>
                        </a>

                        <a href="<?= $baseUrl ?>/admin/logout" class="hidden sm:inline-flex text-xs sm:text-sm font-medium text-rose-600 hover:text-rose-700 px-2.5 py-1.5 rounded-lg hover:bg-rose-50 transition">
                            Logout
                        </a>

                        <!-- Mobile Hamburger Toggle Button -->
                        <button type="button" id="mobileMenuToggle" class="p-1.5 sm:p-2 rounded-xl text-slate-600 hover:text-slate-900 hover:bg-slate-100 transition md:hidden focus:outline-hidden" aria-label="Toggle navigation menu" aria-expanded="false">
                            <svg id="hamburgerIcon" class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path>
                            </svg>
                            <svg id="closeIcon" class="w-6 h-6 hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                            </svg>
                        </button>
                    </div>

                <?php else: ?>
                    <!-- Secure Shared Viewing Mode (No Admin Login Link) -->
                    <span class="inline-flex items-center px-2.5 sm:px-3 py-1 rounded-full text-xs font-medium bg-slate-100 text-slate-600 border border-slate-200">
                        <svg class="w-3.5 h-3.5 text-slate-400 mr-1.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path></svg>
                        <span>Secure Share</span>
                    </span>
                <?php endif; ?>
            </div>
        </div>
    </div>
</header>

<?php if (isAdminLoggedIn()): ?>
    <!-- Mobile Navigation Drawer Backdrop -->
    <div id="mobileDrawerBackdrop" class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-xs opacity-0 pointer-events-none transition-opacity duration-300 md:hidden"></div>

    <!-- Mobile Slide-Out Navigation Drawer from Left -->
    <aside id="mobileDrawer" class="fixed top-0 bottom-0 left-0 z-50 w-72 max-w-[85vw] bg-white shadow-2xl transition-transform duration-300 ease-in-out -translate-x-full md:hidden flex flex-col justify-between overflow-y-auto">
        
        <div>
            <!-- Drawer Header -->
            <div class="h-16 px-4 sm:px-5 border-b border-slate-100 flex items-center justify-between bg-slate-50/80">
                    <div class="flex items-center space-x-2.5">
                        <div class="w-8 h-8 rounded-xl bg-gradient-to-tr from-brand-600 to-indigo-500 flex items-center justify-center text-white shadow-md shadow-brand-500/20 shrink-0">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"></path>
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                        </div>
                        <span class="font-bold text-base text-slate-900 tracking-tight leading-tight">
                            <small class="text-gray-500 font-normal block text-[10px]">eglobe's</small> PitchVault
                        </span>
                    </div>

                    <button type="button" id="closeDrawerBtn" class="p-1.5 rounded-xl text-slate-400 hover:text-slate-700 hover:bg-slate-100 transition focus:outline-none" aria-label="Close navigation drawer">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                    </button>
                </div>

                <!-- User Account Details Badge -->
                <div class="p-3.5 mx-4 mt-4 bg-slate-50 border border-slate-200/80 rounded-2xl flex items-center space-x-3">
                    <div class="w-9 h-9 rounded-full bg-brand-100 text-brand-700 font-bold flex items-center justify-center text-xs shrink-0 uppercase border border-brand-200/60">
                        <?= substr($_SESSION['admin_name'] ?? 'A', 0, 1) ?>
                    </div>
                    <div class="min-w-0 flex-1">
                        <span class="block font-bold text-slate-900 text-xs truncate"><?= htmlspecialchars($_SESSION['admin_name'] ?? 'Admin') ?></span>
                        <span class="inline-block px-2 py-0.5 mt-0.5 rounded text-[10px] font-extrabold bg-slate-200/80 text-slate-700 border border-slate-300/60 uppercase tracking-tight">
                            <?= isMasterAdmin() ? 'Admin' : (isSalesAdmin() ? 'Sales' : 'Editor') ?>
                        </span>
                    </div>
                </div>

                <!-- Drawer Navigation Menu -->
                <div class="px-4 py-4 space-y-1">
                    <div class="px-3 py-1.5 text-[11px] font-bold text-slate-400 uppercase tracking-wider">Navigation</div>
                    
                    <a href="<?= $baseUrl ?>/admin/index" class="flex items-center space-x-3 px-3.5 py-2.5 rounded-xl text-sm font-medium <?= $currentScript === 'index' ? 'bg-brand-50 text-brand-700 font-semibold border border-brand-200/60' : 'text-slate-700 hover:bg-slate-100' ?> transition">
                        <svg class="w-4 h-4 <?= $currentScript === 'index' ? 'text-brand-600' : 'text-slate-500' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"></path>
                        </svg>
                        <span>Dashboard</span>
                    </a>

                    <a href="<?= $baseUrl ?>/admin/projects" class="flex items-center space-x-3 px-3.5 py-2.5 rounded-xl text-sm font-medium <?= in_array($currentScript, ['projects', 'project']) ? 'bg-brand-50 text-brand-700 font-semibold border border-brand-200/60' : 'text-slate-700 hover:bg-slate-100' ?> transition">
                        <svg class="w-4 h-4 <?= in_array($currentScript, ['projects', 'project']) ? 'text-brand-600' : 'text-slate-500' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"></path>
                        </svg>
                        <span>Projects</span>
                    </a>

                    <a href="<?= $baseUrl ?>/admin/shares" class="flex items-center space-x-3 px-3.5 py-2.5 rounded-xl text-sm font-medium <?= $currentScript === 'shares' ? 'bg-brand-50 text-brand-700 font-semibold border border-brand-200/60' : 'text-slate-700 hover:bg-slate-100' ?> transition">
                        <svg class="w-4 h-4 <?= $currentScript === 'shares' ? 'text-brand-600' : 'text-slate-500' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z"></path>
                        </svg>
                        <span>Shares</span>
                    </a>

                    <?php if (!isSalesAdmin()): ?>
                        <a href="<?= $baseUrl ?>/admin/analytics" class="flex items-center space-x-3 px-3.5 py-2.5 rounded-xl text-sm font-medium <?= $currentScript === 'analytics' ? 'bg-brand-50 text-brand-700 font-semibold border border-brand-200/60' : 'text-slate-700 hover:bg-slate-100' ?> transition">
                            <svg class="w-4 h-4 <?= $currentScript === 'analytics' ? 'text-brand-600' : 'text-slate-500' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 012 2h2a2 2 0 012-2z"></path>
                            </svg>
                            <span>Analytics</span>
                        </a>
                    <?php endif; ?>

                    <?php if (isMasterAdmin()): ?>
                        <a href="<?= $baseUrl ?>/admin/users" class="flex items-center space-x-3 px-3.5 py-2.5 rounded-xl text-sm font-medium <?= $currentScript === 'users' ? 'bg-brand-50 text-brand-700 font-semibold border border-brand-200/60' : 'text-slate-700 hover:bg-slate-100' ?> transition">
                            <svg class="w-4 h-4 <?= $currentScript === 'users' ? 'text-brand-600' : 'text-slate-500' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path>
                            </svg>
                            <span>Users</span>
                        </a>
                    <?php endif; ?>

                    <div class="pt-3 pb-1 border-t border-slate-100 my-2">
                        <div class="px-3 py-1.5 text-[11px] font-bold text-slate-400 uppercase tracking-wider">Account Settings</div>
                        
                        <a href="<?= $baseUrl ?>/admin/profile" class="flex items-center space-x-3 px-3.5 py-2.5 rounded-xl text-sm font-medium <?= $currentScript === 'profile' ? 'bg-brand-50 text-brand-700 font-semibold border border-brand-200/60' : 'text-slate-700 hover:bg-slate-100' ?> transition">
                            <svg class="w-4 h-4 <?= $currentScript === 'profile' ? 'text-brand-600' : 'text-slate-500' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path>
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                            </svg>
                            <span>Profile Settings</span>
                        </a>
                    </div>
                </div>
            </div>

            <!-- Drawer Footer (Logout) -->
            <div class="p-4 border-t border-slate-100 bg-slate-50/50">
                <a href="<?= $baseUrl ?>/admin/logout" class="flex items-center justify-center space-x-2 w-full py-2.5 px-4 bg-rose-50 hover:bg-rose-100 text-rose-700 font-semibold text-xs rounded-xl transition border border-rose-200/80 shadow-2xs">
                    <svg class="w-4 h-4 text-rose-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path></svg>
                    <span>Logout</span>
                </a>
            </div>
        </aside>

        <script>
            document.addEventListener('DOMContentLoaded', function() {
                var toggleBtn = document.getElementById('mobileMenuToggle');
                var drawer = document.getElementById('mobileDrawer');
                var backdrop = document.getElementById('mobileDrawerBackdrop');
                var closeBtn = document.getElementById('closeDrawerBtn');

                function openDrawer() {
                    if (!drawer || !backdrop) return;
                    backdrop.classList.remove('opacity-0', 'pointer-events-none');
                    backdrop.classList.add('opacity-100', 'pointer-events-auto');
                    drawer.classList.remove('-translate-x-full');
                    drawer.classList.add('translate-x-0');
                    document.body.style.overflow = 'hidden';
                    if (toggleBtn) toggleBtn.setAttribute('aria-expanded', 'true');
                }

                function closeDrawer() {
                    if (!drawer || !backdrop) return;
                    backdrop.classList.remove('opacity-100', 'pointer-events-auto');
                    backdrop.classList.add('opacity-0', 'pointer-events-none');
                    drawer.classList.remove('translate-x-0');
                    drawer.classList.add('-translate-x-full');
                    document.body.style.overflow = '';
                    if (toggleBtn) toggleBtn.setAttribute('aria-expanded', 'false');
                }

                if (toggleBtn) toggleBtn.addEventListener('click', openDrawer);
                if (closeBtn) closeBtn.addEventListener('click', closeDrawer);
                if (backdrop) backdrop.addEventListener('click', closeDrawer);

                document.addEventListener('keydown', function(e) {
                    if (e.key === 'Escape') closeDrawer();
                });
            });
        </script>
    <?php endif; ?>
