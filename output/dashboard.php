<?php
// dashboard.php — Premium User Dashboard
declare(strict_types=1);

require_once __DIR__ . '/includes/db.php'; // Bootstraps security.php and db connection

// 1. AUTHENTICATION & SECURITY CONTROL
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php?redirect=dashboard.php');
    exit;
}

$db = getDB();
$userId = $_SESSION['user_id'];

// Fetch user data
$userQuery = $db->prepare("SELECT * FROM users WHERE id = ?");
$userQuery->execute([$userId]);
$userData = $userQuery->fetch();

if (!$userData) {
    session_destroy();
    header('Location: login.php');
    exit;
}

// User role check & session fingerprint validation
if (!isset($_SESSION['_user_fingerprint']) || $_SESSION['_user_fingerprint'] !== hash('sha256', ($_SERVER['HTTP_USER_AGENT'] ?? '') . ($_SERVER['REMOTE_ADDR'] ?? ''))) {
    session_unset();
    session_destroy();
    header('Location: login.php');
    exit;
}

// Handle simple GET preview query (AJAX endpoint inside controller)
if (isset($_GET['action']) && $_GET['action'] === 'get_preview' && isset($_GET['id'])) {
    header('Content-Type: application/json');
    
    $previewId = (int)$_GET['id'];
    
    // Select from user submissions
    // Allow either the author or any approved public submission
    $prevStmt = $db->prepare("
        SELECT s.*, u.name as author_name 
        FROM user_submissions s
        JOIN users u ON s.user_id = u.id
        WHERE s.id = ? AND (s.user_id = ? OR s.status = 'Approved')
    ");
    $prevStmt->execute([$previewId, $userId]);
    $item = $prevStmt->fetch();
    
    if (!$item) {
        echo json_encode(['error' => 'Submission not found or access denied.']);
        exit;
}

    // Increment view count if someone else is viewing it (not the author)
    if ($item['user_id'] !== $userId && $item['status'] === 'Approved') {
        $incStmt = $db->prepare("UPDATE user_submissions SET views = views + 1 WHERE id = ?");
        $incStmt->execute([$previewId]);
    }

    echo json_encode([
        'title' => $item['title'],
        'submission_type' => $item['submission_type'],
        'date' => date('M j, Y', strtotime($item['created_at'])),
        'status' => $item['status'],
        'views' => $item['views'],
        'content' => $item['content'],
        'pdf_file' => $item['pdf_file'],
        'cover_image' => $item['cover_image'] ?? '',
        'verse' => $item['verse'] ?? ''
    ]);
    exit;
}

$error = '';
$success = '';

// 2. CONTROLLER ACTIONS (POST HANDLERS)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate();
    $action = $_POST['action'] ?? '';

    // A. Submit or Save Writing
    if ($action === 'submit_writing') {
        $submissionId = isset($_POST['submission_id']) && $_POST['submission_id'] !== '' ? (int)$_POST['submission_id'] : null;
        $type = $_POST['submission_type'] ?? '';
        $title = trim($_POST['title'] ?? '');
        $verse = trim($_POST['verse'] ?? '');
        $content = $_POST['content'] ?? ''; // From TinyMCE
        $method = $_POST['method'] ?? 'text';
        $status = isset($_POST['submit_for_review']) ? 'Pending' : 'Draft';

        $validTypes = ['Devotional', 'Testimony', 'Article', 'Poem', 'Inspirational', 'Music', 'Health'];

        if (empty($title) || empty($type) || !in_array($type, $validTypes, true)) {
            $error = 'Please provide a valid title and submission type.';
        } else {
            $pdfPath = null;
            $coverPath = null;
            $uploadOk = true;

            // Retain PDF and Cover image path if editing
            if ($submissionId) {
                $stmt = $db->prepare("SELECT pdf_file, cover_image FROM user_submissions WHERE id = ? AND user_id = ?");
                $stmt->execute([$submissionId, $userId]);
                $existing = $stmt->fetch();
                if ($existing) {
                    $pdfPath = $existing['pdf_file'] ?: null;
                    $coverPath = $existing['cover_image'] ?: null;
                }
            }

            // Upload PDF if method is PDF
            if ($method === 'pdf' && isset($_FILES['pdf_file']) && $_FILES['pdf_file']['error'] === UPLOAD_ERR_OK) {
                try {
                    $uploadDir = __DIR__ . '/uploads/submissions/';
                    $uploadedFilename = secure_upload('pdf_file', $uploadDir, ['application/pdf'], ['pdf'], 5_242_880);
                    if ($uploadedFilename) {
                        $pdfPath = 'uploads/submissions/' . $uploadedFilename;
                    }
                } catch (Exception $e) {
                    $error = 'PDF Upload error: ' . $e->getMessage();
                    $uploadOk = false;
                }
            }

            // Upload Cover Image if provided
            if ($uploadOk && isset($_FILES['cover_image']) && $_FILES['cover_image']['error'] === UPLOAD_ERR_OK) {
                try {
                    $uploadDir = __DIR__ . '/uploads/submissions/';
                    $uploadedFilename = secure_upload(
                        'cover_image', 
                        $uploadDir, 
                        ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], 
                        ['jpg', 'jpeg', 'png', 'webp', 'gif'], 
                        2_097_152 // 2MB
                    );
                    if ($uploadedFilename) {
                        $coverPath = 'uploads/submissions/' . $uploadedFilename;
                    }
                } catch (Exception $e) {
                    $error = 'Cover image upload error: ' . $e->getMessage();
                    $uploadOk = false;
                }
            }

            if ($uploadOk) {
                if ($method === 'text' && empty($content)) {
                    $error = 'Please write some content before submitting.';
                } elseif ($method === 'pdf' && empty($pdfPath)) {
                    $error = 'Please upload a PDF file.';
                } else {
                    try {
                        if ($submissionId) {
                            // Update
                            $stmt = $db->prepare("
                                UPDATE user_submissions 
                                SET submission_type = ?, title = ?, verse = ?, content = ?, pdf_file = ?, cover_image = ?, status = ? 
                                WHERE id = ? AND user_id = ?
                            ");
                            $stmt->execute([$type, $title, $verse, $method === 'text' ? $content : null, $method === 'pdf' ? $pdfPath : null, $coverPath, $status, $submissionId, $userId]);
                            $success = $status === 'Pending' ? 'Your writing has been updated and submitted for review!' : 'Draft updated successfully!';
                        } else {
                            // Insert
                            $stmt = $db->prepare("
                                INSERT INTO user_submissions (user_id, submission_type, title, verse, content, pdf_file, cover_image, status) 
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                            ");
                            $stmt->execute([$userId, $type, $title, $verse, $method === 'text' ? $content : null, $method === 'pdf' ? $pdfPath : null, $coverPath, $status]);
                            $success = $status === 'Pending' ? 'Your writing has been submitted for review. Thank you!' : 'Draft saved successfully!';
                        }
                        
                        // Clear post state so user doesn't double-submit
                        $_POST = [];
                    } catch (Exception $e) {
                        $error = 'Database error: ' . $e->getMessage();
                    }
                }
            }
        }
    }

    // B. Delete Writing
    if ($action === 'delete_writing') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $db->prepare("DELETE FROM user_submissions WHERE id = ? AND user_id = ?");
            $stmt->execute([$id, $userId]);
            $success = 'Writing deleted successfully.';
        }
    }

    // C. Update Profile Settings
    if ($action === 'update_profile') {
        $displayName = trim($_POST['display_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $bio = trim($_POST['bio'] ?? '');
        $facebook = trim($_POST['facebook_link'] ?? '');
        $twitter = trim($_POST['twitter_link'] ?? '');
        $instagram = trim($_POST['instagram_link'] ?? '');
        $youtube = trim($_POST['youtube_link'] ?? '');
        
        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if (empty($displayName) || empty($email)) {
            $error = 'Display name and email address are required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } else {
            // Check email uniqueness
            $emailCheck = $db->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
            $emailCheck->execute([$email, $userId]);
            if ($emailCheck->fetch()) {
                $error = 'This email address is already in use by another account.';
            } else {
                try {
                    $db->beginTransaction();
                    
                    // Handle Profile Photo Upload
                    $photoPath = $userData['profile_photo'];
                    if (isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] === UPLOAD_ERR_OK) {
                        $uploadDir = __DIR__ . '/uploads/profiles/';
                        $uploadedPhoto = secure_upload(
                            'profile_photo', 
                            $uploadDir, 
                            ['image/jpeg', 'image/png', 'image/webp'], 
                            ['jpg', 'jpeg', 'png', 'webp'], 
                            2_097_152 // 2MB
                        );
                        if ($uploadedPhoto) {
                            $photoPath = 'uploads/profiles/' . $uploadedPhoto;
                        }
                    }

                    // Update basic profile details
                    $updateStmt = $db->prepare("
                        UPDATE users 
                        SET name = ?, email = ?, bio = ?, profile_photo = ?, 
                            facebook_link = ?, twitter_link = ?, instagram_link = ?, youtube_link = ?
                        WHERE id = ?
                    ");
                    $updateStmt->execute([
                        $displayName, $email, $bio, $photoPath, 
                        $facebook, $twitter, $instagram, $youtube, $userId                    ]);

                    // Handle Password Change if requested
                    if ($newPassword !== '') {
                        if ($currentPassword === '') {
                            throw new Exception('Current password is required to set a new password.');
                        }
                        if ($newPassword !== $confirmPassword) {
                            throw new Exception('New password and confirmation do not match.');
                        }
                        if (strlen($newPassword) < 8) {
                            throw new Exception('New password must be at least 8 characters long.');
                        }

                        // Verify current password
                        if (!password_verify($currentPassword, $userData['password_hash'])) {
                            throw new Exception('The current password you entered is incorrect.');
                        }

                        // Update password hash
                        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
                        $pwStmt = $db->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
                        $pwStmt->execute([$newHash, $userId]);
                    }

                    $db->commit();
                    $success = 'Profile settings updated successfully!';
                    
                    // Refresh user data array
                    $userQuery->execute([$userId]);
                    $userData = $userQuery->fetch();
                    $_SESSION['user_name'] = $userData['name'];
                } catch (Exception $e) {
                    $db->rollBack();
                    $error = $e->getMessage();
                }
            }
        }
    }
}

// 3. FETCH SIDEBAR STATS & TAB ROUTING
$page = $_GET['page'] ?? 'dashboard';
$validPages = ['dashboard', 'my-writings', 'submit-writing', 'drafts', 'devotions', 'testimonials', 'notifications', 'profile-settings', 'ss-summaries', 'ss-saved', 'my-borrowed-books'];
if (!in_array($page, $validPages, true)) {
    $page = 'dashboard';
}

// Global user stats for overview & sidebar
$statsQuery = $db->prepare("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'Approved' THEN 1 ELSE 0 END) as approved,
        SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN status = 'Rejected' THEN 1 ELSE 0 END) as rejected,
        SUM(views) as total_views
    FROM user_submissions 
    WHERE user_id = ?
");
$statsQuery->execute([$userId]);
$stats = $statsQuery->fetch();

$totalSubmissions = (int)$stats['total'];
$approvedCount   = (int)$stats['approved'];
$pendingCount    = (int)$stats['pending'];
$rejectedCount   = (int)$stats['rejected'];
$totalViews      = (int)($stats['total_views'] ?? 0);
$accountStatus   = ucfirst($userData['status'] ?? 'Active');

$ssStatsQuery = $db->prepare("SELECT COUNT(*) as total_ss, SUM(views) as ss_views FROM ss_lessons WHERE user_id = ?");
$ssStatsQuery->execute([$userId]);
$ssStats = $ssStatsQuery->fetch();
$totalSS = (int)$ssStats['total_ss'];
$totalSSViews = (int)($ssStats['ss_views'] ?? 0);

$ssLikesQuery = $db->prepare("SELECT COUNT(*) FROM ss_likes l JOIN ss_lessons s ON l.lesson_id = s.id WHERE s.user_id = ?");
$ssLikesQuery->execute([$userId]);
$totalSSLikes = (int)$ssLikesQuery->fetchColumn();

// Fetch unread notifications count
$unreadNotifyQuery = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
$unreadNotifyQuery->execute([$userId]);
$unreadNotificationsCount = (int)$unreadNotifyQuery->fetchColumn();

// Fetch User's current reading progress from new self-hosted table
$readingProgressQuery = $db->prepare("
    SELECT rp.*, b.title, b.author, b.cover_image, b.language
    FROM reading_progress rp
    JOIN books b ON rp.book_id = b.id
    WHERE rp.user_id = ?
    ORDER BY rp.updated_at DESC
    LIMIT 6
");
$readingProgressQuery->execute([$userId]);
$readingProgressList = $readingProgressQuery->fetchAll();

// Fetch User's favorite books
$favBooksQuery = $db->prepare("
    SELECT f.created_at as marked_fav_at, b.*, c.name as cat_name, c.color as cat_color
    FROM book_favorites f
    JOIN books b ON f.book_id = b.id
    LEFT JOIN categories c ON b.category_id = c.id
    WHERE f.user_id = ?
    ORDER BY f.created_at DESC
    LIMIT 6
");
$favBooksQuery->execute([$userId]);
$favoriteBooksList = $favBooksQuery->fetchAll();

// ── Borrow Stats for sidebar badge + My Borrowed Books page ──────────────
$borrowStatsQuery = $db->prepare("
    SELECT
        COUNT(*) as total_borrowed,
        SUM(CASE WHEN status IN ('Approved','Overdue') THEN 1 ELSE 0 END) as active_loans,
        SUM(CASE WHEN status = 'Returned' THEN 1 ELSE 0 END) as returned_count,
        SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END) as pending_count,
        SUM(CASE WHEN status = 'Overdue' THEN 1 ELSE 0 END) as overdue_count
    FROM borrow_requests
    WHERE user_id = ?
");
$borrowStatsQuery->execute([$userId]);
$borrowStats = $borrowStatsQuery->fetch();
$totalBorrowed   = (int)($borrowStats['total_borrowed'] ?? 0);
$activeLoans     = (int)($borrowStats['active_loans']   ?? 0);
$returnedCount   = (int)($borrowStats['returned_count'] ?? 0);
$pendingBorrows  = (int)($borrowStats['pending_count']  ?? 0);
$overdueCount    = (int)($borrowStats['overdue_count']  ?? 0);

// Auto-update overdue status in DB
try { $db->exec("UPDATE borrow_requests SET status='Overdue' WHERE status='Approved' AND expected_return_date < CURRENT_DATE AND user_id={$userId}"); } catch(Exception $e) {}

// RENDER HEADER
$pageTitle = 'Dashboard — Advent Library';
include __DIR__ . '/includes/header.php';
?>

<style type="text/tailwindcss">
    /* Extra styles for dashboard */
    .sidebar-link {
        @apply flex items-center gap-3 px-4 py-3 rounded-lg text-sm font-medium transition-all duration-200 hover:bg-white/10;
        color: rgba(248, 245, 237, 0.75) !important;
    }
    .sidebar-link:hover {
        color: #FFFFFF !important;
    }
    .sidebar-link.active {
        background-color: rgba(255, 255, 255, 0.1) !important;
        color: #FFFFFF !important;
        box-shadow: none !important;
    }
    .dark .sidebar-link.active {
        background-color: rgba(255, 255, 255, 0.1) !important;
        color: #FFFFFF !important;
        box-shadow: none !important;
    }
    #dashboard-sidebar, .mobile-header-bg {
        background-color: #245A63 !important;
        border-color: rgba(255, 255, 255, 0.1) !important;
    }
    .dark #dashboard-sidebar, .dark .mobile-header-bg {
        background-color: #245A63 !important;
        border-color: rgba(255, 255, 255, 0.1) !important;
    }
    .welcome-hero-bg {
        background: linear-gradient(135deg, #2E1F14 0%, #1f140c 100%) !important;
    }
    .dark .welcome-hero-bg {
        background: linear-gradient(135deg, #2A241F 0%, #1E1A17 100%) !important;
        border: 1px solid #4A4037;
    }
    .stat-glow {
        box-shadow: 0 10px 30px -5px rgba(212,160,23,0.15);
    }
    /* Hide front-end branded right panel and reset body padding for dashboard */
    #branded-right-panel {
        display: none !important;
    }
    body {
        padding-right: 0 !important;
    }
</style>

<div class="min-h-screen flex flex-col bg-bglight">

    <!-- Mobile Header -->$_COOKIE    <div class="lg:hidden grid grid-cols-3 items-center mobile-header-bg text-white p-4 sticky top-0 z-40 shadow-md">
        <!-- Left: Brand (Logo + Text) -->$_COOKIE        <div class="flex items-center gap-2 min-w-0">
            <div class="w-8 h-8 flex-shrink-0 flex items-center justify-center">
                <img src="includes/sda_logo.png" alt="Advent Library Logo" class="w-full h-full object-contain">
            </div>
            <span class="font-heading text-sm font-bold truncate text-white">KarUSDAdvent</span>
        </div>
        <!-- Center: Centered Toggle Menu Button -->$_COOKIE        <div class="flex items-center justify-center">
            <button id="sidebar-toggle" onclick="toggleSidebar()" class="p-2 text-white hover:text-secondary focus:outline-none bg-white/10 hover:bg-white/20 rounded-lg shadow-inner flex items-center justify-center transition-all duration-200 active:scale-95" aria-label="Toggle sidebar menu">
                <i class="fa-solid fa-bars text-lg"></i>
            </button>
        </div>
        <!-- Right: Actions (Theme Toggle + Notifications) -->$_COOKIE        <div class="flex items-center justify-end gap-3.5">
            <button class="theme-toggle-btn text-white hover:text-secondary focus:outline-none flex items-center justify-center" aria-label="Toggle theme">
                <span class="active-dashboard-theme-icon flex items-center justify-center">
                    <svg class="icon icon-lg" aria-hidden="true"><use href="#icon-moon"/></svg>
                </span>
            </button>
            <div class="relative">
                <a href="?page=notifications" class="text-white hover:text-secondary">
                    <i class="fa-solid fa-bell text-lg"></i>
                    <?php if ($unreadNotificationsCount > 0): ?>
                        <span class="absolute -top-1 -right-1 bg-red-500 text-white rounded-full w-4 h-4 flex items-center justify-center text-[10px] font-bold"><?= $unreadNotificationsCount ?></span>
                    <?php endif; ?>
                </a>
            </div>
        </div>
    </div>

    <!-- Background overlay for mobile sidebar -->$_COOKIE    <div id="sidebar-overlay" onclick="toggleSidebar()" class="fixed inset-0 z-40 bg-black/50 hidden lg:hidden transition-opacity duration-300"></div>

    <!-- Left Sidebar Navigation -->$_COOKIE    <aside id="dashboard-sidebar" class="fixed inset-y-0 left-0 z-50 w-64 bg-primary text-white flex flex-col transform -translate-x-full lg:translate-x-0 transition-transform duration-300 ease-in-out border-r border-white/10">
        <!-- Sidebar Header (Logo) -->$_COOKIE        <div class="p-8 border-b border-white/10 flex flex-col items-center justify-center relative flex-shrink-0">
            <a href="index.php" class="flex flex-col items-center text-center gap-4 group mx-auto">
                <div class="logo-emblem w-20 h-20 flex items-center justify-center transition-transform duration-300 group-hover:scale-105">
                    <img src="includes/sda_logo.png" alt="Advent Library Logo" class="w-full h-full object-contain">
                </div>
                <div class="flex flex-col items-center leading-none">
                    <span class="font-heading text-2xl font-bold tracking-tight text-white">KarUSDAdvent</span>
                    <span class="font-body text-[10px] tracking-widest uppercase text-secondary font-semibold mt-1.5">Library</span>
                </div>
            </a>
            <button id="sidebar-close" onclick="toggleSidebar()" class="absolute top-4 right-4 lg:hidden text-white/70 hover:text-white focus:outline-none" aria-label="Close sidebar">
                <i class="fa-solid fa-xmark text-lg"></i>
            </button>
        </div>

        <!-- User profile summary -->$_COOKIE        <div class="p-6 border-b border-white/10 flex items-center gap-4">
            <div class="relative w-12 h-12 rounded-full overflow-hidden border-2 border-secondary bg-white/10 flex-shrink-0">
                <?php if ($userData['profile_photo']): ?>
                    <img src="<?= htmlspecialchars($userData['profile_photo']) ?>" class="w-full h-full object-cover" alt="Profile avatar">
                <?php else: ?>
                    <div class="w-full h-full flex items-center justify-center text-secondary font-bold text-lg">
                        <?= strtoupper(substr($userData['name'], 0, 1)) ?>
                    </div>
                <?php endif; ?>
            </div>
            <div class="min-w-0 flex-1">
                <h4 class="font-medium text-sm truncate text-white leading-snug"><?= htmlspecialchars($userData['name']) ?></h4>
                <p class="text-xs text-secondary truncate uppercase font-semibold tracking-wider mt-0.5"><?= ucfirst($userData['role']) ?></p>
            </div>
        </div>

        <!-- Sidebar Navigation Menu Links -->$_COOKIE        <nav class="flex-1 px-4 py-6 space-y-1 overflow-y-auto" aria-label="Sidebar Navigation">
            <a href="?page=dashboard" class="sidebar-link <?= $page === 'dashboard' ? 'active' : '' ?>">
                <i class="fa-solid fa-chart-line w-5"></i> Dashboard
            </a>
            <a href="?page=my-writings" class="sidebar-link <?= $page === 'my-writings' ? 'active' : '' ?>">
                <i class="fa-solid fa-book-open w-5"></i> My Writings
            </a>
            <a href="?page=submit-writing" class="sidebar-link <?= $page === 'submit-writing' ? 'active' : '' ?>">
                <i class="fa-solid fa-pen-nib w-5"></i> Submit Writing
            </a>
            <a href="?page=drafts" class="sidebar-link <?= $page === 'drafts' ? 'active' : '' ?>">
                <i class="fa-solid fa-file-signature w-5"></i> Drafts
            </a>
            <a href="?page=devotions" class="sidebar-link <?= $page === 'devotions' ? 'active' : '' ?>">
                <i class="fa-solid fa-sun w-5"></i> Devotions
            </a>
            <a href="?page=testimonials" class="sidebar-link <?= $page === 'testimonials' ? 'active' : '' ?>">
                <i class="fa-solid fa-comment-dots w-5"></i> Testimonials
            </a>
            <a href="?page=notifications" class="sidebar-link <?= $page === 'notifications' ? 'active' : '' ?>">
                <i class="fa-solid fa-bell w-5"></i> Notifications
                <?php if ($unreadNotificationsCount > 0): ?>
                    <span class="ml-auto bg-red-500 text-white rounded-full px-2 py-0.5 text-xs font-bold"><?= $unreadNotificationsCount ?></span>
                <?php endif; ?>
            </a>
            <a href="?page=my-borrowed-books" class="sidebar-link <?= $page === 'my-borrowed-books' ? 'active' : '' ?>">
                <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/></svg>
                My Borrowed Books
                <?php if ($activeLoans > 0): ?>
                    <span class="ml-auto bg-blue-500 text-white rounded-full px-2 py-0.5 text-xs font-bold"><?= $activeLoans ?></span>
                <?php endif; ?>
            </a>
            <a href="?page=profile-settings" class="sidebar-link <?= $page === 'profile-settings' ? 'active' : '' ?>">
                <i class="fa-solid fa-user-gear w-5"></i> Profile Settings
            </a>
            <div class="px-4 py-2 mt-4 mb-1 text-[10px] font-bold text-secondary uppercase tracking-widest border-t border-white/10 pt-4">Resources</div>
            <a href="literature_history.php" class="sidebar-link">
                <i class="fa-solid fa-clock-rotate-left w-5"></i> Literature History
            </a>
            <a href="egw-books.php" class="sidebar-link">
                <i class="fa-solid fa-book w-5"></i> EGW Library
            </a>

            <div class="px-4 py-2 mt-4 mb-1 text-[10px] font-bold text-secondary uppercase tracking-widest border-t border-white/10 pt-4">Sabbath School</div>
            <a href="sabbath-school.php" class="sidebar-link">
                <i class="fa-solid fa-book-bible w-5"></i> Main SS Feed
            </a>
            <a href="?page=ss-summaries" class="sidebar-link <?= $page === 'ss-summaries' ? 'active' : '' ?>">
                <i class="fa-solid fa-list w-5"></i> My Summaries
            </a>
            <a href="?page=ss-saved" class="sidebar-link <?= $page === 'ss-saved' ? 'active' : '' ?>">
                <i class="fa-solid fa-bookmark w-5"></i> Saved Summaries
            </a>
        </nav>

        <!-- Sidebar Footer -->$_COOKIE        <div class="p-4 border-t border-white/10">
            <a href="logout.php" class="flex items-center gap-3 px-4 py-3 rounded-lg text-sm font-medium transition-colors hover:bg-red-500/10 text-red-400 hover:text-red-300">
                <i class="fa-solid fa-right-from-bracket w-5"></i> Sign Out
            </a>
        </div>
    </aside>

    <!-- Main Content Area -->$_COOKIE    <div class="lg:pl-64 flex-1 flex flex-col">

        <!-- Top Header for Desktop -->$_COOKIE        <header class="hidden lg:flex items-center justify-between h-20 bg-white border-b border-gray-200 px-8 sticky top-0 z-30 shadow-xs">
            <div>
                <h2 class="text-xl font-heading font-bold text-primary">
                    <?php                        switch($page) {
                            case 'dashboard': echo 'Dashboard Overview'; break;
                            case 'my-writings': echo 'My Submissions'; break;
                            case 'submit-writing': echo 'Create New Writing'; break;
                            case 'drafts': echo 'My Saved Drafts'; break;
                            case 'devotions': echo 'Daily Devotionals'; break;
                            case 'testimonials': echo 'Spiritual Testimonies'; break;
                            case 'notifications': echo 'Notification Center'; break;
                            case 'profile-settings': echo 'Account Settings'; break;
                            case 'ss-summaries': echo 'My Sabbath School Summaries'; break;
                            case 'ss-saved': echo 'Saved SS Summaries'; break;
                            case 'my-borrowed-books': echo 'My Borrowed Books'; break;
                        }
                    ?>
                </h2>
            </div>
            
            <div class="flex items-center gap-6">
                <!-- Theme Switcher -->$_COOKIE                <button class="theme-toggle-btn p-2 text-gray-500 hover:text-primary rounded-lg transition-colors focus:outline-none flex items-center justify-center" aria-label="Toggle theme">
                    <span class="active-dashboard-theme-icon flex items-center justify-center">
                        <svg class="icon icon-lg" aria-hidden="true"><use href="#icon-moon"/></svg>
                    </span>
                </button>

                <!-- Notifications Bell Icon -->$_COOKIE                <div class="relative">
                    <a href="?page=notifications" class="text-gray-500 hover:text-primary transition-colors">
                        <i class="fa-solid fa-bell text-lg"></i>
                        <?php if ($unreadNotificationsCount > 0): ?>
                            <span class="absolute -top-1 -right-1 bg-red-500 text-white rounded-full w-4 h-4 flex items-center justify-center text-[10px] font-bold"><?= $unreadNotificationsCount ?></span>
                        <?php endif; ?>
                    </a>
                </div>

                <!-- Back to Main Site -->$_COOKIE                <a href="index.php" class="text-sm font-semibold text-primary hover:text-secondary transition-colors">
                    Back to Home <i class="fa-solid fa-arrow-right-to-bracket ml-1"></i>
                </a>
            </div>
        </header>

        <!-- Main Dashboard View Container -->$_COOKIE        <main class="flex-1 p-6 md:p-8 overflow-y-auto">
            
            <!-- Global Message Alerts -->$_COOKIE            <?php if ($error): ?>
                <div class="max-w-7xl mx-auto mb-6 bg-red-50 text-red-600 border border-red-200 p-4 rounded-xl flex items-center gap-3 shadow-sm">
                    <i class="fa-solid fa-circle-exclamation text-lg"></i>
                    <div><?= htmlspecialchars($error) ?></div>
                </div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="max-w-7xl mx-auto mb-6 bg-green-50 text-green-700 border border-green-200 p-4 rounded-xl flex items-center gap-3 shadow-sm animate-fade-in">
                    <i class="fa-solid fa-circle-check text-lg"></i>
                    <div><?= htmlspecialchars($success) ?></div>
                </div>
            <?php endif; ?>

            <div class="max-w-7xl mx-auto">
                
                <!-- ───────────────────────────────────────────────────────── -->$_COOKIE                <!-- PAGE: DASHBOARD OVERVIEW -->$_COOKIE                <!-- ───────────────────────────────────────────────────────── -->$_COOKIE                <?php if ($page === 'dashboard'): ?>
                    
                    <!-- Welcome Hero Banner -->$_COOKIE                    <section class="welcome-hero-bg rounded-2xl p-6 md:p-8 text-white relative overflow-hidden mb-8 shadow-card reveal">
                        <!-- Background decoration -->$_COOKIE                        <div class="absolute inset-0 z-0 opacity-10 line-pattern"></div>
                        <div class="absolute right-0 bottom-0 top-0 w-1/3 bg-radial-gradient z-0 opacity-20 hidden md:block" style="background: radial-gradient(circle, var(--c-gold) 0%, transparent 70%);"></div>

                        <div class="relative z-10 flex flex-col md:flex-row md:items-center justify-between gap-6">
                            <div>
                                <span class="text-secondary font-bold text-xs uppercase tracking-wider block mb-2">Welcome Back</span>
                                <h1 class="text-3xl md:text-4xl font-heading font-bold mb-2">Greeting, <?= htmlspecialchars($userData['name']) ?>!</h1>
                                <p class="text-white/80 max-w-xl text-sm md:text-base">"Let your light so shine before men, that they may see your good works, andn." — Matthew 5:16</p>
                            </div>
                            <div class="flex-shrink-0">
    <a href="?page=submit-writing" class="btn-primary">
                                    <i class="fa-solid fa-pen-nib"></i> Write Content
                                </a>
                            </div>
                        </div>
                    </section>

                    <!-- User Statistics Grid -->$_COOKIE                    <section class="grid grid-cols-2 lg:grid-cols-6 gap-4 mb-8">
                        
                        <!-- Card 1: Total Submissions -->$_COOKIE                        <div class="bg-white p-5 rounded-2xl border border-gray-200/60 shadow-soft stat-glow flex flex-col justify-between hover:-translate-y-1 transition-all duration-300 group">
                            <div class="flex items-center justify-between mb-4">
                                <span class="text-xs text-text-muted font-bold uppercase tracking-wider">Total Work</span>
                                <div class="w-8 h-8 rounded-lg bg-indigo-50 text-indigo-600 flex items-center justify-center group-hover:scale-110 transition-transform">
                                    <i class="fa-solid fa-paper-plane"></i>
                                </div>
                            </div>
                            <div>
                                <div class="text-2xl font-stats text-primary leading-none mb-1"><?= $totalSubmissions ?></div>
                                <span class="text-[10px] text-text-muted font-medium">Submissions made</span>
                            </div>
                        </div>

                        <!-- Card 2: Approved -->$_COOKIE                        <div class="bg-white p-5 rounded-2xl border border-gray-200/60 shadow-soft stat-glow flex flex-col justify-between hover:-translate-y-1 transition-all duration-300 group">
                            <div class="flex items-center justify-between mb-4">
                                <span class="text-xs text-text-muted font-bold uppercase tracking-wider">Approved</span>
                                <div class="w-8 h-8 rounded-lg bg-emerald-50 text-emerald-600 flex items-center justify-center group-hover:scale-110 transition-transform">
                                    <i class="fa-solid fa-circle-check"></i>
                                </div>
                            </div>
                            <div>
                                <div class="text-2xl font-stats text-emerald-600 leading-none mb-1"><?= $approvedCount ?></div>
                                <span class="text-[10px] text-text-muted font-medium">Published writings</span>
                            </div>
                        </div>

                        <!-- Card 3: Pending -->$_COOKIE                        <div class="bg-white p-5 rounded-2xl border border-gray-200/60 shadow-soft stat-glow flex flex-col justify-between hover:-translate-y-1 transition-all duration-300 group">
                            <div class="flex items-center justify-between mb-4">
                                <span class="text-xs text-text-muted font-bold uppercase tracking-wider">Pending</span>
                                <div class="w-8 h-8 rounded-lg bg-amber-50 text-amber-600 flex items-center justify-center group-hover:scale-110 transition-transform">
                                    <i class="fa-solid fa-hourglass-half"></i>
                                </div>
                            </div>
                            <div>
                                <div class="text-2xl font-stats text-amber-500 leading-none mb-1"><?= $pendingCount ?></div>
                                <span class="text-[10px] text-text-muted font-medium">Under review</span>
                            </div>
                        </div>

                        <!-- Card 4: Rejected -->$_COOKIE                        <div class="bg-white p-5 rounded-2xl border border-gray-200/60 shadow-soft stat-glow flex flex-col justify-between hover:-translate-y-1 transition-all duration-300 group">
                            <div class="flex items-center justify-between mb-4">
                                <span class="text-xs text-text-muted font-bold uppercase tracking-wider">Rejected</span>
                                <div class="w-8 h-8 rounded-lg bg-rose-50 text-rose-600 flex items-center justify-center group-hover:scale-110 transition-transform">
                                    <i class="fa-solid fa-circle-xmark"></i>
                                </div>
                            </div>
                            <div>
                                <div class="text-2xl font-stats text-rose-500 leading-none mb-1"><?= $rejectedCount ?></div>
                                <span class="text-[10px] text-text-muted font-medium">Action needed</span>
                            </div>
                        </div>

                        <!-- Card 5: Total Views -->$_COOKIE                        <div class="bg-white p-5 rounded-2xl border border-gray-200/60 shadow-soft stat-glow flex flex-col justify-between hover:-translate-y-1 transition-all duration-300 group">
                            <div class="flex items-center justify-between mb-4">
                                <span class="text-xs text-text-muted font-bold uppercase tracking-wider">Total Views</span>
                                <div class="w-8 h-8 rounded-lg bg-cyan-50 text-cyan-600 flex items-center justify-center group-hover:scale-110 transition-transform">
                                    <i class="fa-solid fa-eye"></i>
                                </div>
                            </div>
                            <div>
                                <div class="text-2xl font-stats text-cyan-600 leading-none mb-1"><?= $totalViews ?></div>
                                <span class="text-[10px] text-text-muted font-medium">Spiritual impact</span>
                            </div>
                        </div>

                        <!-- Card 6: Account Status -->$_COOKIE                        <div class="bg-white p-5 rounded-2xl border border-gray-200/60 shadow-soft stat-glow flex flex-col justify-between hover:-translate-y-1 transition-all duration-300 group">
                            <div class="flex items-center justify-between mb-4">
                                <span class="text-xs text-text-muted font-bold uppercase tracking-wider">Status</span>
                                <div class="w-8 h-8 rounded-lg bg-green-50 text-green-600 flex items-center justify-center">
                                    <i class="fa-solid fa-shield-halved"></i>
                                </div>
                            </div>
                            <div>
                                <div class="text-xl font-stats text-emerald-600 leading-none mb-1"><?= $accountStatus ?></div>
                                <span class="text-[10px] text-text-muted font-medium">Account status</span>
                            </div>
                        </div>

                                            <!-- Card 7: SS Summaries -->$_COOKIE                        <div class="bg-white p-5 rounded-2xl border border-gray-200/60 shadow-soft stat-glow flex flex-col justify-between hover:-translate-y-1 transition-all duration-300 group">
                            <div class="flex items-center justify-between mb-4">
                                <span class="text-xs text-text-muted font-bold uppercase tracking-wider">SS Summaries</span>
                                <div class="w-8 h-8 rounded-lg bg-purple-50 text-purple-600 flex items-center justify-center">
                                    <i class="fa-solid fa-book-bible"></i>
                                </div>
                            </div>
                            <div>
                                <div class="text-xl font-stats text-purple-600 leading-none mb-1"><?= $totalSS ?></div>
                                <span class="text-[10px] text-text-muted font-medium">Summaries written</span>
                            </div>
                        </div>
                        <!-- Card 8: SS Likes -->$_COOKIE                        <div class="bg-white p-5 rounded-2xl border border-gray-200/60 shadow-soft stat-glow flex flex-col justify-between hover:-translate-y-1 transition-all duration-300 group">
                            <div class="flex items-center justify-between mb-4">
                                <span class="text-xs text-text-muted font-bold uppercase tracking-wider">SS Likes</span>
                                <div class="w-8 h-8 rounded-lg bg-red-50 text-red-600 flex items-center justify-center">
                                    <i class="fa-solid fa-heart"></i>
                                </div>
                            </div>
                            <div>
                                <div class="text-xl font-stats text-red-600 leading-none mb-1"><?= $totalSSLikes ?></div>
                                <span class="text-[10px] text-text-muted font-medium">Likes received</span>
                            </div>
                        </div>
                    </section>

                    <!-- Continue Reading Section -->$_COOKIE                    <?php if (!empty($readingProgressList)): ?>
                    <section class="bg-white rounded-2xl border border-gray-200/60 shadow-soft p-6 mb-8 reveal animate-fade-in">
                        <h3 class="text-base font-heading font-bold text-primary mb-4 flex items-center gap-2">
                            <i class="fa-solid fa-book-open text-secondary"></i> Continue Reading
                        </h3>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                            <?php foreach ($readingProgressList as $rp): ?>
                            <div class="flex gap-4 p-4 border border-gray-100 rounded-xl hover:shadow-md transition-all duration-300">
                                <div class="w-16 h-20 bg-slate-100 rounded overflow-hidden flex-shrink-0 border border-gray-100 shadow-sm">
                                    <?php if ($rp['cover_image'] && file_exists(__DIR__ . '/' . $rp['cover_image'])): ?>
                                        <img src="<?= htmlspecialchars($rp['cover_image']) ?>" class="w-full h-full object-cover">
                                    <?php else: ?>
                                        <div class="w-full h-full flex items-center justify-center text-[10px] text-slate-400 bg-slate-200 font-bold uppercase">PDF</div>
                                    <?php endif; ?>
                                </div>
                                <div class="flex-1 min-w-0 flex flex-col justify-between">
                                    <div>
                                        <h4 class="font-bold text-sm text-primary truncate leading-snug" title="<?= htmlspecialchars($rp['title']) ?>"><?= htmlspecialchars($rp['title']) ?></h4>
                                        <p class="text-xs text-text-muted truncate mt-0.5">by <?= htmlspecialchars($rp['author'] ?: 'Unknown') ?></p>
                                    </div>
                                    <div class="mt-2">
                                        <div class="text-[10px] text-secondary font-bold mb-2">
                                            Currently on <span class="bg-[#D4A017]/10 text-[#D4A017] px-2 py-0.5 rounded-full">Page <?= $rp['last_page'] ?></span>
                                        </div>
                                        <a href="reader.php?id=<?= $rp['book_id'] ?>" class="inline-flex items-center gap-1.5 text-[11px] font-bold text-[#D4A017] hover:text-primary transition-colors">
                                            Resume Reading <i class="fa-solid fa-arrow-right-long"></i>
                                        </a>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </section>
                    <?php endif; ?>

                    <!-- Favorite Books Section -->$_COOKIE                    <?php if (!empty($favoriteBooksList)): ?>
                    <section class="bg-white rounded-2xl border border-gray-200/60 shadow-soft p-6 mb-8 reveal animate-fade-in">
                        <h3 class="text-base font-heading font-bold text-primary mb-4 flex items-center gap-2">
                            <i class="fa-solid fa-heart text-red-500"></i> My Favorite Books
                        </h3>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                            <?php foreach ($favoriteBooksList as $fav): ?>
                            <div class="flex gap-4 p-4 border border-gray-100 rounded-xl hover:shadow-md transition-all duration-300">
                                <div class="w-16 h-20 bg-slate-100 rounded overflow-hidden flex-shrink-0 border border-gray-100 shadow-sm">
                                    <?php if ($fav['cover_image'] && file_exists(__DIR__ . '/' . $fav['cover_image'])): ?>
                                        <img src="<?= htmlspecialchars($fav['cover_image']) ?>" class="w-full h-full object-cover">
                                    <?php else: ?>
                                        <div class="w-full h-full flex items-center justify-center text-[10px] text-slate-400 bg-slate-200 font-bold uppercase">PDF</div>
                                    <?php endif; ?>
                                </div>
                                <div class="flex-1 min-w-0 flex flex-col justify-between">
                                    <div>
                                        <h4 class="font-bold text-sm text-primary truncate leading-snug" title="<?= htmlspecialchars($fav['title']) ?>"><?= htmlspecialchars($fav['title']) ?></h4>
                                        <p class="text-xs text-text-muted truncate mt-0.5">by <?= htmlspecialchars($fav['author'] ?: 'Unknown') ?></p>
                                    </div>
                                    <div class="flex gap-2 mt-2 pt-1 border-t border-gray-50">
                                        <a href="reader.php?id=<?= $fav['id'] ?>" class="text-[10px] font-bold text-white bg-[#1E3A5F] hover:bg-[#D4A017] px-2.5 py-1 rounded transition-colors text-center">Read</a>
                                        <a href="download.php?id=<?= $fav['id'] ?>" class="text-[10px] font-bold text-white bg-[#D4A017] hover:bg-amber-600 px-2.5 py-1 rounded transition-colors text-center">Download</a>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </section>
                    <?php endif; ?>

                    <!-- Quick Actions Panel -->$_COOKIE                    <section class="bg-white rounded-2xl border border-gray-200/60 shadow-soft p-6 mb-8 reveal">
                        <h3 class="text-base font-heading font-bold text-primary mb-4">Quick Actions</h3>
                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                            <a href="?page=submit-writing&type=Devotional" class="p-4 border border-gray-100 rounded-xl hover:border-secondary hover:bg-secondary/5 transition-all text-center flex flex-col items-center justify-center group">
                                <div class="w-10 h-10 rounded-full bg-amber-50 text-amber-600 flex items-center justify-center text-lg mb-2 group-hover:scale-110 transition-transform">
                                    <i class="fa-solid fa-sun"></i>
                                </div>
                                <span class="font-bold text-xs text-primary">Write Devotional</span>
                            </a>
                            <a href="?page=submit-writing&type=Article" class="p-4 border border-gray-100 rounded-xl hover:border-secondary hover:bg-secondary/5 transition-all text-center flex flex-col items-center justify-center group">
                                <div class="w-10 h-10 rounded-full bg-blue-50 text-blue-600 flex items-center justify-center text-lg mb-2 group-hover:scale-110 transition-transform">
                                    <i class="fa-solid fa-file-signature"></i>
                                </div>
                                <span class="font-bold text-xs text-primary">Write Article</span>
                            </a>
                            <a href="?page=profile-settings" class="p-4 border border-gray-100 rounded-xl hover:border-secondary hover:bg-secondary/5 transition-all text-center flex flex-col items-center justify-center group">
                                <div class="w-10 h-10 rounded-full bg-emerald-50 text-emerald-600 flex items-center justify-center text-lg mb-2 group-hover:scale-110 transition-transform">
                                    <i class="fa-solid fa-user-gear"></i>
                                </div>
                                <span class="font-bold text-xs text-primary">Profile Settings</span>
                            </a>
                            <a href="index.php" class="p-4 border border-gray-100 rounded-xl hover:border-secondary hover:bg-secondary/5 transition-all text-center flex flex-col items-center justify-center group">
                                <div class="w-10 h-10 rounded-full bg-purple-50 text-purple-600 flex items-center justify-center text-lg mb-2 group-hover:scale-110 transition-transform">
                                    <i class="fa-solid fa-house"></i>
                                </div>
                                <span class="font-bold text-xs text-primary">Go to Home</span>
                            </a>
                        </div>
                    </section>

                    <!-- Split Recent Activity & Discover Content -->$_COOKIE                    <div class="grid grid-cols-1 lg:grid-cols-12 gap-8">
                        
                        <!-- Recent Activity Section -->$_COOKIE                        <section class="lg:col-span-5 bg-white rounded-2xl border border-gray-200/60 shadow-soft p-6 flex flex-col h-full">
                            <div class="flex items-center justify-between mb-6">
                                <h3 class="text-lg font-heading font-bold text-primary">Recent Activity</h3>
                                <a href="?page=my-writings" class="text-xs font-semibold text-secondary hover:underline">View all</a>
                            </div>
                            
                            <div class="space-y-4 flex-1">
                                <?php                                // Fetch latest 5 user submissions
                                $recentQuery = $db->prepare("
                                    SELECT id, title, submission_type, status, created_at 
                                    FROM user_submissions 
                                    WHERE user_id = ? 
                                    ORDER BY created_at DESC 
                                    LIMIT 5
                                ");
                                $recentQuery->execute([$userId]);
                                $recentSubmissions = $recentQuery->fetchAll();

                                if (empty($recentSubmissions)):
                                ?>
                                    <div class="text-center py-12 text-text-muted">
                                        <i class="fa-solid fa-clock-rotate-left text-3xl mb-3 block text-gray-300"></i>
                                        <p class="text-sm">No recent activities found.</p>
                                    </div>
                                <?php                                else:
                                    foreach ($recentSubmissions as $activity):
                                        $typeLabel = htmlspecialchars($activity['submission_type']);
                                        $statusClass = 'text-amber-500';
                                        $icon = 'fa-paper-plane';
                                        
                                        if ($activity['status'] === 'Approved') {
                                            $statusClass = 'text-emerald-500';
                                            $icon = 'fa-circle-check';
                                        } elseif ($activity['status'] === 'Rejected') {
                                            $statusClass = 'text-rose-500';
                                            $icon = 'fa-circle-xmark';
                                        } elseif ($activity['status'] === 'Draft') {
                                            $statusClass = 'text-gray-400';
                                            $icon = 'fa-file-signature';
                                        }
                                ?>
                                    <div class="flex items-start gap-4 p-3 hover:bg-bglight rounded-xl transition-colors">
                                        <div class="w-8 h-8 rounded-full bg-slate-50 flex items-center justify-center text-primary border border-gray-200 flex-shrink-0">
                                            <i class="fa-solid <?= $icon ?> text-xs"></i>
                                        </div>
                                        <div class="min-w-0 flex-1">
                                            <h4 class="text-sm font-semibold text-textdark truncate"><?= htmlspecialchars($activity['title']) ?></h4>
                                            <p class="text-xs text-text-muted mt-0.5">
                                                Saved as <span class="font-medium text-primary"><?= $typeLabel ?></span>
                                            </p>
                                        </div>
                                        <div class="text-right flex-shrink-0">
                                            <span class="text-[10px] font-bold uppercase <?= $statusClass ?> block"><?= $activity['status'] ?></span>
                                            <span class="text-[10px] text-text-muted mt-0.5 block"><?= date('M j, g:i a', strtotime($activity['created_at'])) ?></span>
                                        </div>
                                    </div>
                                <?php 
                                    endforeach;
                                endif; 
                                ?>
                            </div>
                        </section>

                        <!-- Discover Content Area -->$_COOKIE                        <section class="lg:col-span-7 bg-white rounded-2xl border border-gray-200/60 shadow-soft p-6">
                            <div class="flex items-center justify-between mb-6">
                                <div>
                                    <h3 class="text-lg font-heading font-bold text-primary">Discover Content</h3>
                                    <p class="text-xs text-text-muted">Inspirational devotionals and testimonies from the library community.</p>
                                </div>
                                <a href="?page=devotions" class="text-xs font-semibold text-secondary hover:underline">Explore all</a>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <?php                                $discoverQuery = $db->prepare("
                                    SELECT s.id, s.title, s.submission_type, s.created_at, s.content, u.name as author_name, u.profile_photo
                                    FROM user_submissions s
                                    JOIN users u ON s.user_id = u.id
                                    WHERE s.status = 'Approved' AND s.submission_type IN ('Devotional', 'Testimony')
                                    ORDER BY s.created_at DESC
                                    LIMIT 4
                                ");
                                $discoverQuery->execute();
                                $discoverItems = $discoverQuery->fetchAll();

                                if (empty($discoverItems)):
                                ?>
                                    <div class="col-span-2 text-center py-16 text-text-muted">
                                        <i class="fa-solid fa-book-bible text-3xl mb-3 block text-gray-300"></i>
                                        <p class="text-sm">No community articles published yet.</p>
                                    </div>
                                <?php                                else:
                                    foreach ($discoverItems as $item):
                                        // Simple plain text content truncate
                                        $snippet = strip_tags($item['content'] ?? '');
                                        if (strlen($snippet) > 95) {
                                            $snippet = substr($snippet, 0, 95) . '...';
                                        }
                                ?>
                                    <div class="p-4 border border-gray-200/60 rounded-xl hover:border-secondary transition-all hover:shadow-sm flex flex-col justify-between h-48 bg-slate-50/50">
                                        <div>
                                            <div class="flex items-center justify-between mb-2">
                                                <span class="badge badge-gold text-[9px]"><?= htmlspecialchars($item['submission_type']) ?></span>
                                                <span class="text-[9px] text-text-muted"><?= date('M j, Y', strtotime($item['created_at'])) ?></span>
                                            </div>
                                            <h4 class="font-heading font-bold text-sm text-primary line-clamp-1 mb-1"><?= htmlspecialchars($item['title']) ?></h4>
                                            <p class="text-xs text-text-muted line-clamp-3 leading-relaxed"><?= $snippet ?: 'PDF submission or no text details available.' ?></p>
                                        </div>

                                        <!-- Author footer -->$_COOKIE                                        <div class="flex items-center gap-2 mt-4 pt-3 border-t border-gray-100">
                                            <div class="w-6 h-6 rounded-full overflow-hidden border border-secondary flex-shrink-0 bg-white flex items-center justify-center text-[10px] text-primary font-bold">
                                                <?php if ($item['profile_photo']): ?>
                                                    <img src="<?= htmlspecialchars($item['profile_photo']) ?>" class="w-full h-full object-cover">
                                                <?php else: ?>
                                                    <?= strtoupper(substr($item['author_name'], 0, 1)) ?>
                                                <?php endif; ?>
                                            </div>
                                            <span class="text-[10px] text-primary font-semibold truncate">By <?= htmlspecialchars($item['author_name']) ?></span>
                                            
                                            <!-- Action Button -->$_COOKIE                                            <button onclick="triggerPreview(<?= $item['id'] ?>)" class="ml-auto text-secondary hover:text-primary transition-colors text-[10px] font-bold">
                                                Read <i class="fa-solid fa-chevron-right text-[8px]"></i>
                                            </button>
                                        </div>
                                    </div>
                                <?php 
                                    endforeach;
                                endif; 
                                ?>
                            </div>
                        </section>

                    </div>

                <!-- ───────────────────────────────────────────────────────── -->$_COOKIE                <!-- PAGE: MY WRITINGS -->$_COOKIE                <!-- ───────────────────────────────────────────────────────── -->$_COOKIE                <?php elseif ($page === 'my-writings'): ?>
                    
                    <div class="bg-white rounded-2xl border border-gray-200/60 shadow-soft p-6">
                        
                        <!-- Search, Filter & Actions Bar -->$_COOKIE                        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-6">
                            
                            <!-- Search & Filter Controls -->$_COOKIE                            <div class="flex flex-col sm:flex-row gap-3 w-full md:max-w-2xl">
                                <div class="relative flex-1">
                                    <i class="fa-solid fa-magnifying-glass text-slate-400 absolute left-3.5 top-3.5"></i>
                                    <input type="text" id="submissions-search" placeholder="Search writings by title..." 
                                           class="input-field pl-10 py-2.5 w-full">
                                </div>
                                <select id="submissions-type-filter" class="input-field py-2.5 sm:w-48">
                                    <option value="">All Types</option>
                                    <option value="Devotional">Devotionals</option>
                                    <option value="Testimony">Testimonies</option>
                                    <option value="Article">Articles</option>
                                    <option value="Poem">Poems</option>
                                    <option value="Inspirational">Inspirational</option>
                                </select>
                                <select id="submissions-status-filter" class="input-field py-2.5 sm:w-48">
                                    <option value="">All Statuses</option>
                                    <option value="Draft">Draft</option>
                                    <option value="Pending">Pending Review</option>
                                    <option value="Approved">Approved</option>
                                    <option value="Rejected">Rejected</option>
                                </select>
                            </div>

                            <a href="?page=submit-writing" class="btn-primary flex-shrink-0">
                                <i class="fa-solid fa-plus"></i> New Submission
                            </a>
                        </div>

                        <?php                        // Query all submissions for user
                        $subStmt = $db->prepare("
                            SELECT * FROM user_submissions 
                            WHERE user_id = ? 
                            ORDER BY created_at DESC
                        ");
                        $subStmt->execute([$userId]);
                        $submissionsList = $subStmt->fetchAll();
                        ?>

                        <!-- Writings Table (Desktop) -->$_COOKIE                        <div class="hidden md:block overflow-x-auto rounded-xl border border-gray-200/60">
                            <table class="min-w-full divide-y divide-gray-200 text-left text-sm" id="submissions-table">
                                <thead class="bg-slate-50 text-primary uppercase font-bold text-xs">
                                    <tr>
                                        <th scope="col" class="px-6 py-4 cursor-pointer hover:bg-slate-100 transition-colors" onclick="sortTable(0)">Title <i class="fa-solid fa-sort ml-1 opacity-40"></i></th>
                                        <th scope="col" class="px-6 py-4 cursor-pointer hover:bg-slate-100 transition-colors" onclick="sortTable(1)">Category <i class="fa-solid fa-sort ml-1 opacity-40"></i></th>
                                        <th scope="col" class="px-6 py-4 cursor-pointer hover:bg-slate-100 transition-colors" onclick="sortTable(2)">Date <i class="fa-solid fa-sort ml-1 opacity-40"></i></th>
                                        <th scope="col" class="px-6 py-4 cursor-pointer hover:bg-slate-100 transition-colors" onclick="sortTable(3)">Status <i class="fa-solid fa-sort ml-1 opacity-40"></i></th>
                                        <th scope="col" class="px-6 py-4 cursor-pointer hover:bg-slate-100 transition-colors" onclick="sortTable(4)">Views <i class="fa-solid fa-sort ml-1 opacity-40"></i></th>
                                        <th scope="col" class="px-6 py-4 text-right">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 bg-white">
                                    <?php                                    if (empty($submissionsList)):
                                    ?>
                                        <tr class="no-results-row">
                                            <td colspan="6" class="text-center py-12 text-text-muted">
                                                <i class="fa-solid fa-folder-open text-4xl mb-3 block text-gray-300"></i>
                                                <p>No writings submitted yet. Let's create your first content!</p>
                                            </td>
                                        </tr>
                                    <?php                                    else:
                                        foreach ($submissionsList as $item):
                                            $status = $item['status'];
                                            $statusBadge = 'badge-blue';
                                            if ($status === 'Approved') $statusBadge = 'badge-green';
                                            elseif ($status === 'Rejected') $statusBadge = 'bg-red-50 text-red-600 border border-red-100';
                                            elseif ($status === 'Draft') $statusBadge = 'bg-slate-100 text-slate-500 border border-slate-200';
                                            else $statusBadge = 'badge-gold';
                                    ?>
                                        <tr class="hover:bg-bglight/50 transition-colors submission-row" 
                                            data-title="<?= e(strtolower($item['title'])) ?>"
                                            data-type="<?= e($item['submission_type']) ?>"
                                            data-status="<?= e($item['status']) ?>">
                                            <td class="px-6 py-4 font-semibold text-primary max-w-xs truncate">
                                                <?= htmlspecialchars($item['title']) ?>
                                            </td>
                                            <td class="px-6 py-4">
                                                <span class="badge badge-blue"><?= htmlspecialchars($item['submission_type']) ?></span>
                                            </td>
                                            <td class="px-6 py-4 text-text-muted text-xs">
                                                <?= date('M j, Y', strtotime($item['created_at'])) ?>
                                            </td>
                                            <td class="px-6 py-4">
                                                <span class="badge <?= $statusBadge ?>"><?= $status === 'Pending' ? 'Pending Review' : $status ?></span>
                                            </td>
                                            <td class="px-6 py-4 font-stats text-xs">
                                                <?= (int)$item['views'] ?>
                                            </td>
                                            <td class="px-6 py-4 text-right flex items-center justify-end gap-2 h-14">
                                                <!-- Preview Button -->$_COOKIE                                                <button onclick="triggerPreview(<?= $item['id'] ?>)" class="text-indigo-600 hover:text-indigo-800 p-1.5 hover:bg-indigo-50 rounded" title="Preview">
                                                    <i class="fa-solid fa-eye text-sm"></i>
                                                </button>
                                                
                                                <!-- Edit / Resubmit Button -->$_COOKIE                                                <a href="?page=submit-writing&edit=<?= $item['id'] ?>" class="text-amber-600 hover:text-amber-800 p-1.5 hover:bg-amber-50 rounded" title="Edit">
                                                    <i class="fa-solid fa-pen text-sm"></i>
                                                </a>
                                                
                                                <!-- Delete Form -->$_COOKIE                                                <form action="?page=my-writings" method="POST" onsubmit="return confirm('Are you sure you want to delete this writing? This cannot be undone.');" class="inline">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="delete_writing">
                                                    <input type="hidden" name="id" value="<?= $item['id'] ?>">
                                                    <button type="submit" class="text-rose-600 hover:text-rose-800 p-1.5 hover:bg-rose-50 rounded" title="Delete">
                                                        <i class="fa-solid fa-trash-can text-sm"></i>
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php 
                                        endforeach;
                                    endif; 
                                    ?>
                                    <tr id="no-search-results" class="hidden">
                                        <td colspan="6" class="text-center py-12 text-text-muted">
                                            <i class="fa-solid fa-circle-info text-3xl mb-3 block text-gray-300"></i>
                                            <p>No writings match your search/filter criteria.</p>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- Writings Card List (Mobile) -->$_COOKIE                        <div class="md:hidden space-y-4" id="submissions-mobile-list">
                            <?php if (empty($submissionsList)): ?>
                                <div class="text-center py-12 text-text-muted bg-slate-50/50 rounded-xl border border-gray-200/60">
                                    <i class="fa-solid fa-folder-open text-4xl mb-3 block text-gray-300"></i>
                                    <p>No writings submitted yet. Let's create your first content!</p>
                                </div>
                            <?php else:
                                foreach ($submissionsList as $item):
                                    $status = $item['status'];
                                    $statusBadge = 'badge-blue';
                                    if ($status === 'Approved') $statusBadge = 'badge-green';
                                    elseif ($status === 'Rejected') $statusBadge = 'bg-red-50 text-red-600 border border-red-100';
                                    elseif ($status === 'Draft') $statusBadge = 'bg-slate-100 text-slate-500 border border-slate-200';
                                    else $statusBadge = 'badge-gold';
                            ?>
                                <div class="bg-white border border-gray-200/60 rounded-xl p-4 flex flex-col justify-between gap-4 shadow-xs mobile-submission-card" 
                                     data-title="<?= e(strtolower($item['title'])) ?>"
                                     data-type="<?= e($item['submission_type']) ?>"
                                     data-status="<?= e($item['status']) ?>">
                                    <div>
                                        <div class="flex items-center justify-between mb-2">
                                            <span class="badge <?= $statusBadge ?> text-[9px]"><?= $status === 'Pending' ? 'Pending Review' : $status ?></span>
                                            <span class="text-[10px] text-text-muted"><?= date('M j, Y', strtotime($item['created_at'])) ?></span>
                                        </div>
                                        <h4 class="font-bold text-sm text-primary leading-snug truncate"><?= htmlspecialchars($item['title']) ?></h4>
                                        <div class="flex items-center gap-4 text-xs text-text-muted mt-2">
                                            <span><i class="fa-solid fa-eye mr-1"></i> <?= (int)$item['views'] ?> views</span>
                                        </div>
                                    </div>
                                    <div class="flex items-center justify-between pt-3 border-t border-gray-100">
                                        <button onclick="triggerPreview(<?= $item['id'] ?>)" class="text-xs font-semibold text-indigo-600 hover:text-indigo-800">
                                            Preview
                                        </button>
                                        <div class="flex items-center gap-2">
                                            <a href="?page=submit-writing&edit=<?= $item['id'] ?>" class="text-amber-600 hover:text-amber-800 p-2 bg-amber-50 rounded" title="Edit">
                                                <i class="fa-solid fa-pen text-xs"></i>
                                            </a>
                                            <form action="?page=my-writings" method="POST" onsubmit="return confirm('Are you sure you want to delete this writing?');" class="inline">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="delete_writing">
                                                <input type="hidden" name="id" value="<?= $item['id'] ?>">
                                                <button type="submit" class="text-rose-600 hover:text-rose-800 p-2 bg-rose-50 rounded" title="Delete">
                                                    <i class="fa-solid fa-trash-can text-xs"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; endif; ?>
                        </div>
                    </div>

                <!-- ───────────────────────────────────────────────────────── -->$_COOKIE                <!-- PAGE: SUBMIT WRITING -->$_COOKIE                <!-- ───────────────────────────────────────────────────────── -->$_COOKIE                <?php elseif ($page === 'submit-writing'): 
                    // Check if editing
                    $editId = isset($_GET['edit']) ? (int)$_GET['edit'] : null;
                    $editItem = null;
                    if ($editId) {
                        $editStmt = $db->prepare("SELECT * FROM user_submissions WHERE id = ? AND user_id = ?");
                        $editStmt->execute([$editId, $userId]);
                        $editItem = $editStmt->fetch();
                    }
                    
                    $formTitle = $editItem ? $editItem['title'] : '';
                    $formType = $editItem ? $editItem['submission_type'] : '';
                    $formContent = $editItem ? $editItem['content'] : '';
                    $formPdf = $editItem ? $editItem['pdf_file'] : '';
                    $formVerse = $editItem ? $editItem['verse'] : '';
                    $formCover = $editItem ? $editItem['cover_image'] : '';
                    $formMethod = $formPdf ? 'pdf' : 'text';
                ?>
                    <div class="bg-white rounded-2xl border border-gray-200/60 shadow-soft p-6 md:p-8">
                        <div class="flex items-center justify-between mb-6">
                            <div>
                                <h3 class="text-xl font-heading font-bold text-primary"><?= $editItem ? 'Edit Your Writing' : 'Share Your Writing' ?></h3>
                                <p class="text-xs text-text-muted">All submissions undergo editorial review before publishing to the community.</p>
                            </div>
                            <div id="autosave-status" class="text-[11px] text-emerald-600 font-semibold flex items-center gap-1.5 bg-green-50 px-3 py-1 rounded-full border border-green-100 hidden">
                                <i class="fa-solid fa-circle-notch animate-spin text-[10px]"></i> Autosaving...
                            </div>
                        </div>

                        <form action="?page=my-writings" method="POST" enctype="multipart/form-data" id="writing-form" class="space-y-6">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="submit_writing">
                            <input type="hidden" name="submission_id" id="submission_id" value="<?= $editId ?>">

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <label class="block text-xs font-bold uppercase tracking-wider text-text-muted mb-2">Submission Type *</label>
                                    <select name="submission_type" id="submission_type" class="input-field py-3" required>
                                        <option value="">Select Category...</option>
                                        <option value="Devotional" <?= $formType === 'Devotional' ? 'selected' : '' ?>>Daily Devotional</option>
                                        <option value="Testimony" <?= $formType === 'Testimony' ? 'selected' : '' ?>>Personal Testimony</option>
                                        <option value="Article" <?= $formType === 'Article' ? 'selected' : '' ?>>Christian Article</option>
                                        <option value="Poem" <?= $formType === 'Poem' ? 'selected' : '' ?>>Poem</option>
                                        <option value="Inspirational" <?= $formType === 'Inspirational' ? 'selected' : '' ?>>Inspirational Writing</option>
                                        <option value="Music" <?= $formType === 'Music' ? 'selected' : '' ?>>Music</option>
                                        <option value="Health" <?= $formType === 'Health' ? 'selected' : '' ?>>Health</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-xs font-bold uppercase tracking-wider text-text-muted mb-2">Writing Title *</label>
                                    <input type="text" name="title" id="submission_title" class="input-field py-3" placeholder="Enter writing title" value="<?= e($formTitle) ?>" required>
                                </div>
                            </div>

                            <!-- Scripture highlighting tip / input & Cover Image -->$_COOKIE                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <label class="block text-xs font-bold uppercase tracking-wider text-text-muted mb-2">Key Scripture Verse (Optional)</label>
                                    <input type="text" id="scripture_input" name="verse" class="input-field py-3" placeholder="e.g. John 3:16" value="<?= e($formVerse) ?>">
                                    <p class="text-[10px] text-text-muted mt-1.5">If writing, use the "Scripture Block" tool in the editor to format scripture.</p>
                                </div>
                                <div>
                                    <label class="block text-xs font-bold uppercase tracking-wider text-text-muted mb-2">Cover Image / Thumbnail (Optional)</label>
                                    <?php if ($formCover): ?>
                                        <div class="p-2 bg-slate-50 border border-gray-200 text-primary rounded-lg text-xs flex items-center justify-between mb-2">
                                            <span class="truncate"><i class="fa-solid fa-image mr-1"></i> <strong><?= basename($formCover) ?></strong></span>
                                            <a href="<?= htmlspecialchars($formCover) ?>" target="_blank" class="underline font-semibold">View Cover</a>
                                        </div>
                                    <?php endif; ?>
                                    <input type="file" name="cover_image" accept="image/*" 
                                           class="w-full p-2.5 border border-gray-200 rounded-lg outline-none file:mr-4 file:py-1.5 file:px-3 file:rounded-full file:border-0 file:text-xs file:font-semibold file:bg-secondary/15 file:text-primary hover:file:bg-secondary/25 transition-colors">
                                    <p class="text-[10px] text-text-muted mt-1">Allowed formats: JPG, PNG, WEBP, GIF. Max size: 2MB.</p>
                                </div>
                            </div>

                            <div class="border-t border-gray-100 pt-6">
                                <label class="block text-xs font-bold uppercase tracking-wider text-text-muted mb-4">Choose Submission Method</label>
                                <div class="flex gap-4 mb-6 max-w-md">
                                    <label class="flex-1 cursor-pointer">
                                        <input type="radio" name="method" value="text" class="peer sr-only" <?= $formMethod === 'text' ? 'checked' : '' ?> onchange="toggleFormMethod()">
                                        <div class="p-4 border border-gray-200 rounded-xl text-center peer-checked:border-secondary peer-checked:bg-secondary/5 hover:border-secondary/40 transition-all">
                                            <i class="fa-solid fa-pen-nib text-xl mb-1 text-primary"></i>
                                            <div class="font-bold text-xs text-primary">Write Rich Text</div>
                                        </div>
                                    </label>
                                    <label class="flex-1 cursor-pointer">
                                        <input type="radio" name="method" value="pdf" class="peer sr-only" <?= $formMethod === 'pdf' ? 'checked' : '' ?> onchange="toggleFormMethod()">
                                        <div class="p-4 border border-gray-200 rounded-xl text-center peer-checked:border-secondary peer-checked:bg-secondary/5 hover:border-secondary/40 transition-all">
                                            <i class="fa-solid fa-file-pdf text-xl mb-1 text-primary"></i>
                                            <div class="font-bold text-xs text-primary">Upload PDF</div>
                                        </div>
                                    </label>
                                </div>

                                <!-- Text Rich Editor -->$_COOKIE                                <div id="method-text" class="space-y-2 <?= $formMethod === 'pdf' ? 'hidden' : '' ?>">
                                    <label class="block text-xs font-bold uppercase tracking-wider text-text-muted">Content Editor</label>
                                    <textarea name="content" id="editor" class="w-full h-80"><?= htmlspecialchars($formContent) ?></textarea>
                                </div>

                                <!-- PDF Upload Section -->$_COOKIE                                <div id="method-pdf" class="space-y-2 <?= $formMethod === 'text' ? 'hidden' : '' ?>">
                                    <label class="block text-xs font-bold uppercase tracking-wider text-text-muted mb-2">Upload Document (PDF)</label>
                                    <?php if ($formPdf): ?>
                                        <div class="p-3 bg-indigo-50 border border-indigo-100 text-indigo-700 rounded-lg text-xs flex items-center justify-between mb-3">
                                            <span><i class="fa-solid fa-file-pdf mr-2"></i> Current Attachment: <strong><?= basename($formPdf) ?></strong></span>
                                            <a href="<?= htmlspecialchars($formPdf) ?>" target="_blank" class="underline hover:text-indigo-900 font-semibold">View PDF</a>
                                        </div>
                                    <?php endif; ?>
                                    <input type="file" name="pdf_file" accept=".pdf" 
                                           class="w-full p-3 border border-gray-200 rounded-lg outline-none file:mr-4 file:py-1.5 file:px-4 file:rounded-full file:border-0 file:text-xs file:font-semibold file:bg-secondary/10 file:text-primary hover:file:bg-secondary/25 transition-colors">
                                    <p class="text-[10px] text-text-muted">Max file size: 5MB. Only PDF formats are accepted.</p>
                                </div>
                            </div>

                            <div class="pt-6 border-t border-gray-100 flex flex-col sm:flex-row gap-4">
                                <button type="submit" name="submit_for_review" class="btn-primary py-3 px-8 text-sm flex-1 sm:flex-none">
                                    <i class="fa-solid fa-paper-plane"></i> Submit for Review
                                </button>
                                <button type="submit" name="save_draft" class="btn-outline py-3 px-8 text-sm flex-1 sm:flex-none">
                                    <i class="fa-solid fa-file-signature"></i> Save as Draft
                                </button>
                                <a href="?page=my-writings" class="btn-ghost !bg-gray-100 !text-slate-600 hover:!bg-gray-200 border-0 py-3 px-6 text-sm text-center flex-1 sm:flex-none">
                                    Cancel
                                </a>
                            </div>
                        </form>
                    </div>

                <!-- ───────────────────────────────────────────────────────── -->$_COOKIE                <!-- PAGE: DRAFTS -->$_COOKIE                <!-- ───────────────────────────────────────────────────────── -->$_COOKIE                <?php elseif ($page === 'drafts'): ?>
                    <div class="bg-white rounded-2xl border border-gray-200/60 shadow-soft p-6">
                        <h3 class="text-lg font-heading font-bold text-primary mb-6">Saved Drafts</h3>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                            <?php                            $draftStmt = $db->prepare("
                                SELECT id, title, submission_type, created_at, content, pdf_file 
                                FROM user_submissions 
                                WHERE user_id = ? AND status = 'Draft' 
                                ORDER BY created_at DESC
                            ");
                            $draftStmt->execute([$userId]);
                            $drafts = $draftStmt->fetchAll();

                            if (empty($drafts)):
                            ?>
                                <div class="col-span-full text-center py-16 text-text-muted">
                                    <i class="fa-solid fa-file-signature text-4xl mb-3 block text-gray-300"></i>
                                    <p class="text-sm">You have no saved drafts at the moment.</p>
                                    <a href="?page=submit-writing" class="btn-primary mt-4 py-2 px-5 text-xs inline-flex">Start writing</a>
                                </div>
                            <?php                            else:
                                foreach ($drafts as $draft):
                                    $snippet = strip_tags($draft['content'] ?? '');
                                    if (strlen($snippet) > 130) {
                                        $snippet = substr($snippet, 0, 130) . '...';
                                    }
                            ?>
                                <div class="border border-gray-200/80 rounded-2xl p-5 hover:border-secondary hover:shadow-sm transition-all flex flex-col justify-between h-48 bg-slate-50/20">
                                    <div>
                                        <div class="flex items-center justify-between mb-2">
                                            <span class="badge badge-gold text-[9px]"><?= htmlspecialchars($draft['submission_type']) ?></span>
                                            <span class="text-[9px] text-text-muted">Saved <?= date('M j, Y', strtotime($draft['created_at'])) ?></span>
                                        </div>
                                        <h4 class="font-heading font-bold text-sm text-primary mb-1 truncate"><?= htmlspecialchars($draft['title'] ?: 'Untitled Draft') ?></h4>
                                        <p class="text-xs text-text-muted line-clamp-3 leading-relaxed"><?= $snippet ?: ($draft['pdf_file'] ? 'PDF File Draft Attached' : 'Empty draft content.') ?></p>
                                    </div>
                                    
                                    <div class="flex items-center justify-between mt-4 pt-3 border-t border->$_COOKIE
                               <a href="?page=submit-writing&edit=<?= $d"}")}")