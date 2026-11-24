<?php>$file = 'C:/xampp/htdocs/advent/dashboard.php';
$content = file_get_contents($file);

// 1. Update validPages
$content = str_replace(
    "['dashboard', 'my-writings', 'submit-writing', 'drafts', 'devotions', 'testimonials', 'notifications', 'profile-settings']",
    "['dashboard', 'my-writings', 'submit-writing', 'drafts', 'devotions', 'testimonials', 'notifications', 'profile-settings', 'ss-summaries', 'ss-saved']",
    $content);

// 2. Add Sidebar links
$sidebarInjection = <<<HTML
            <a href="?page=profile-settings" class="sidebar-link <?= \$page === 'profile-settings' ? 'active' : '' ?>">
                <i class="fa-solid fa-user-gear w-5"></i> Profile Settings
            </a>
            <div class="px-4 py-2 mt-4 mb-1 text-[10px] font-bold text-secondary uppercase tracking-widest border-t border-white/10 pt-4">Sabbath School</div>
            <a href="sabbath-school.php" class="sidebar-link">
                <i class="fa-solid fa-book-bible w-5"></i> Main SS Feed
            </a>
            <a href="?page=ss-summaries" class="sidebar-link <?= \$page === 'ss-summaries' ? 'active' : '' ?>">
                <i class="fa-solid fa-list w-5"></i> My Summaries
            </a>
            <a href="?page=ss-saved" class="sidebar-link <?= \$page === 'ss-saved' ? 'active' : '' ?>">
                <i class="fa-solid fa-bookmark w-5"></i> Saved Summaries
            </a>
HTML;

$content = str_replace(
    '<a href="?page=profile-settings" class="sidebar-link <?= $page === \'profile-settings\' ? \'active\' : \'\' ?>">' . "\n" . '                <i class="fa-solid fa-user-gear w-5"></i> Profile Settings' . "\n" . '            </a>',
    $sidebarInjection,
    $content);

// 3. Update Header
$content = str_replace(
    "case 'profile-settings': echo 'Account Settings'; break;",
    "case 'profile-settings': echo 'Account Settings'; break;\n                            case 'ss-summaries': echo 'My Sabbath School Summaries'; break;\n                            case 'ss-saved': echo 'Saved SS Summaries'; break;",
    $content);

// 4. Inject SS Stats into Dashboard Overview (Card 6 replacement or insertion)
$statsLogic = <<<PHP_ROUND_HALF_DOWN\$ssStatsQuery = \$db->prepare("SELECT COUNT(*) as total_ss, SUM(views) as ss_views FROM ss_lessons WHERE user_id = ?");
\$ssStatsQuery->execute([\$userId]);
\$ssStats = \$ssStatsQuery->fetch();
\$totalSS = (int)\$ssStats['total_ss'];
\$totalSSViews = (int)(\$ssStats['ss_views'] ?? 0);

\$ssLikesQuery = \$db->prepare("SELECT COUNT(*) FROM ss_likes l JOIN ss_lessons s ON l.lesson_id = s.id WHERE s.user_id = ?");
\$ssLikesQuery->execute([\$userId]);
\$totalSSLikes = (int)\$ssLikesQuery->fetchColumn();
PHP;

$content = str_replace(
    "// Fetch unread notifications count",
    $statsLogic . "\n\n// Fetch unread notifications count",
    $content);

$statsHTML = <<<HTML
                        <!-- Card 7: SS Summaries -->$_COOKIE                        <div class="bg-white p-5 rounded-2xl border border-gray-200/60 shadow-soft stat-glow flex flex-col justify-between hover:-translate-y-1 transition-all duration-300 group">
                            <div class="flex items-center justify-between mb-4">
                                <span class="text-xs text-text-muted font-bold uppercase tracking-wider">SS Summaries</span>
                                <div class="w-8 h-8 rounded-lg bg-purple-50 text-purple-600 flex items-center justify-center">
                                    <i class="fa-solid fa-book-bible"></i>
                                </div>
                            </div>
                            <div>
                                <div class="text-xl font-stats text-purple-600 leading-none mb-1"><?= \$totalSS ?></div>
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
                                <div class="text-xl font-stats text-red-600 leading-none mb-1"><?= \$totalSSLikes ?></div>
                                <span class="text-[10px] text-text-muted font-medium">Likes received</span>
                            </div>
                        </div>
HTML;

$content = str_replace(
    "</section>\n\n                    <!-- Quick Actions Panel -->",
    $statsHTML . "\n                    </section>\n\n                    <!-- Quick Actions Panel -->",
    $content);

// 5. Inject Pages Hook at the very end of the content loop
$content = str_replace(
    "<?php endif; ?>\n\n            </div>\n        </main>",
    "<?php endif; ?>\n\n                <?php if (in_array(\$page, ['ss-summaries', 'ss-saved'])) include __DIR__ . '/dashboard_ss.php'; ?>\n\n            </div>\n        </main>",
    $content);

file_put_contents($file, $content);
echo "dashboard.php patched successfully.\n";
)