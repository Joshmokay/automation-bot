<?php// sabbath-school-reader.php — Modern Daily Sabbath School Lesson Reader
declare(strict_types=1);

require_once __DIR__ . '/includes/db.php';
$db = getDB();

// 1. Fetch Week Details
$week_id = $_GET['week_id'] ?? '';
if (!$week_id) {
    header('Location: sabbath-school-lessons.php');
    exit;
}

try {
    $weekStmt = $db->prepare("SELECT * FROM ss_official_weeks WHERE id = ?");
    $weekStmt->execute([$week_id]);
    $week = $weekStmt->fetch();
} catch (Throwable $e) {
    $week = null;
}

if (!$week) {
    header('Location: sabbath-school-lessons.php');
    exit;
}

// 2. Fetch Quarterly Details
try {
    $quarterlyStmt = $db->prepare("SELECT * FROM ss_official_quarterlies WHERE id = ?");
    $quarterlyStmt->execute([$week['quarterly_id']]);
    $quarterly = $quarterlyStmt->fetch();
} catch (Throwable $e) {
    $quarterly = null;
}

// 3. Fetch Days for the Week
try {
    $daysStmt = $db->prepare("SELECT id, title, day_number, date FROM ss_official_days WHERE week_id = ? ORDER BY day_number ASC");
    $daysStmt->execute([$week_id]);
    $days = $daysStmt->fetchAll();
} catch (Throwable $e) {
    $days = [];
}

// 4. Fetch Auxiliary Resources
try {
    $resourcesStmt = $db->prepare("SELECT id, resource_type, title, content, url FROM ss_official_resources WHERE week_id = ?");
    $resourcesStmt->execute([$week_id]);
    $resourcesList = $resourcesStmt->fetchAll();
} catch (Throwable $e) {
    $resourcesList = [];
}

// Group resources by type
$resources = [];
foreach ($resourcesList as $res) {
    $resources[$res['resource_type']] = $res;
}

// 5. Determine Active View
$activeType = $_GET['type'] ?? 'day'; // day, resource
$activeDayId = $_GET['day_id'] ?? '';
$activeResourceType = $_GET['resource_type'] ?? '';

$activeTitle = '';
$activeSubtitle = '';
$rawContent = '';
$activeUrl = '';

if ($activeType === 'resource' && $activeResourceType !== '') {
    $res = $resources[$activeResourceType] ?? null;
    if ($res) {
        $activeTitle = $res['title'];
        $activeSubtitle = ucfirst(str_replace('-', ' ', $activeResourceType));
        $rawContent = $res['content'] ?? '';
        $activeUrl = $res['url'] ?? '';
} else {
        // Fallback to first day
        $activeType = 'day';
}
}

if ($activeType === 'day') {
    $selectedDay = null;
    if ($activeDayId) {
        foreach ($days as $d) {
            if ($d['id'] === $activeDayId) {
                $selectedDay = $d;
                break;
}
        }
    }
    if (!$selectedDay) {
        $selectedDay = $days[0] ?? null;
    }
}
    }
}

// ── CUSTOM MARKDOWN TO HTML CONVERTER ──
function renderMarkdown(string $md): string {
    $md = str_replace("\r\n", "\n", $md);
    $md = str_replace("\r", "\n", $md);

    // Escape HTML first
    $html = htmlspecialchars($md, ENT_NOQUOTES, 'UTF-8');
    
    // Bold & Italics
    $html = preg_replace('/\*\*(.*?)\*\*/', '<strong>$1</strong>', $html);
    $html = preg_replace('/_(.*?)_/', '<em>$1</em>', $html);
    
    // Match blockquotes (Memory Texts)
    $lines = explode("\n", $html);
    $inBlockquote = false;
    $blockquoteContent = [];
    $newLines = [];
    
    foreach ($lines as $line) {
        if (strpos(trim($line), '&gt;') === 0) {
            $inBlockquote = true;
            $blockquoteContent[] = trim(substr(trim($line), 4));
        } else {
            if ($inBlockquote) {
                $inBlockquote = false;
                $bqText = implode("<br>", $blockquoteContent);
                // Strip paragraph tags if they are inside
                $bqText = str_ireplace(['&lt;p&gt;', '&lt;/p&gt;'], '', $bqText);
                $newLines[] = '<div class="my-5 p-5 bg-amber-500/10 border-l-4 border-amber-500 rounded-r-xl italic text-xs md:text-sm text-[var(--c-text-primary)] relative select-none"><span class="absolute -top-3 left-2 text-5xl font-serif text-amber-500/25 leading-none">“</span>' . $bqText . '</div>';
                $blockquoteContent = [];
        }
            $newLines[] = $line;
    }
}
    if ($inBlockquote) {
        $bqText = implode("<br>", $blockquoteContent);
        $bqText = str_ireplace(['&lt;p&gt;', '&lt;/p&gt;'], '', $bqText);
        $newLines[] = '<div class="my-5 p-5 bg-amber-500/10 border-l-4 border-amber-500 rounded-r-xl italic text-xs md:text-sm text-[var(--c-text-primary)] relative select-none"><span class="absolute -top-3 left-2 text-5xl font-serif text-amber-500/25 leading-none">“</span>' . $bqText . '</div>';
}
    
    $html = implode("\n", $newLines);
    
    // Markdown Links
    $html = preg_replace('/\[(.*?)\]\((.*?)\)/', '<a href="$2" target="_blank" class="text-navy hover:underline font-semibold">$1</a>', $html);
    
    // Headers
    $html = preg_replace('/^\s*#####\s*(.*)$/m', '<h5 class="text-[10px] font-bold text-amber-600 dark:text-amber-400 mt-4 mb-2 uppercase tracking-wider">$1</h5>', $html);
    $html = preg_replace('/^\s*####\s*(.*)$/m', '<h4 class="text-xs font-bold text-amber-600 dark:text-amber-400 mt-5 mb-2 font-mono uppercase tracking-widest border-b border-[var(--c-border)] pb-1">$1</h4>', $html);
    $html = preg_replace('/^\s*###\s*(.*)$/m', '<h3 class="text-sm font-bold text-[var(--c-text-primary)] mt-6 mb-3 border-l-2 border-navy pl-2 leading-none font-display">$1</h3>', $html);

    // Bible Verse Highlighting & Auto-Linking
    $booksPattern = '(Gen|Exo|Lev|Num|Deu|Jos|Jud|Rut|1\s*Sam|2\s*Sam|1\s*Kin|2\s*Kin|1\s*Chr|2\s*Chr|Ezr|Neh|Est|Job|Psa|Pro|Ecc|Son|Isa|Jer|Lam|Eze|Dan|Hos|Joe|Amo|Oba|Jon|Mic|Nah|Hab|Zep|Hag|Zec|Mal|Mat|Mar|Luk|Joh|Act|Rom|1\s*Cor|2\s*Cor|Gal|Eph|Phi|Col|1\s*The|2\s*The|1\s*Tim|2\s*Tim|Tit|Phm|Heb|Jas|1\s*Pet|2\s*Pet|1\s*Joh|2\s*Joh|3\s*Joh|Jud|Rev|Genesis|Exodus|Levit|Numer|Deuter|Joshua|Judges|Ruth|Samuel|Kings|Chron|Ezra|Nehem|Esther|Job|Psalms|Prov|Eccles|Isaiah|Jerem|Lament|Ezek|Daniel|Hosea|Joel|Amos|Obad|Jonah|Micah|Nahum|Habak|Zeph|Hagg|Zech|Malach|Matt|Mark|Luke|John|Acts|Rom|Galat|Ephes|Philip|Coloss|Thess|Timoth|Titus|Philemon|Hebrews|James|Peter|Jude|Revel)';
    $refRegex = '/' . $booksPattern . '\.?\s*\d+\s*:\s*\d+(?:\s*-\s*\d+)?/i';
    
    $html = preg_replace_callback($refRegex, function($matches) {
        $ref = urlencode($matches[0]);
        return '<a href="https://www.biblegateway.com/passage/?search=' . $ref . '&version=NKJV" target="_blank" class="text-amber-600 dark:text-amber-400 hover:underline inline-flex items-center gap-0.5 font-semibold"><span>📖</span> ' . $matches[0] . '</a>';
    }, $html);

    // Lists
    $html = preg_replace('/^\s*[\-\*]\s+(.*)$/m', '<li class="ml-4 list-disc text-xs md:text-sm leading-relaxed text-[var(--c-text-secondary)] mb-1">$1</li>', $html);
    $html = preg_replace('/^\s*(\d+)\.\s+(.*)$/m', '<li class="ml-4 list-decimal text-xs md:text-sm leading-relaxed text-[var(--c-text-secondary)] mb-1">$2</li>', $html);
    
    // Paragraph breaks (double line breaks)
    $html = preg_replace('/\n{2,}/', '</p><p class="text-xs md:text-sm leading-relaxed text-[var(--c-text-secondary)] mb-4">', $html);
    $html = '<p class="text-xs md:text-sm leading-relaxed text-[var(--c-text-secondary)] mb-4">' . $html . '</p>';
    
    // Cleanup empty paragraphs
    $html = str_replace('<p class="text-xs md:text-sm leading-relaxed text-[var(--c-text-secondary)] mb-4"></p>', '', $html);
    $html = str_replace('<p class="text-xs md:text-sm leading-relaxed text-[var(--c-text-secondary)] mb-4"><div', '<div', $html);
    $html = str_replace('</div></p>', '</div>', $html);
    $html = str_replace('<p class="text-xs md:text-sm leading-relaxed text-[var(--c-text-secondary)] mb-4"><h', '<h', $html);
    $html = str_replace('</h3></p>', '</h3>', $html);
    $html = str_replace('</h4></p>', '</h4>', $html);
    $html = str_replace('</h5></p>', '</h5>', $html);
    
    return $html;
}

$pageTitle = $activeTitle . ' — Sabbath School Lesson';
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/navbar.php';

// Sync color tokens from quarterly metadata if exists
$colorPrimary = $quarterly['color_primary'] ?? '#245A63';
$colorPrimaryDark = $quarterly['color_primary_dark'] ?? '#122D31';
?>

<style>
    :root {
        --navy:   <?= $colorPrimary ?>;
        --navy-d: <?= $colorPrimaryDark ?>;
        --gold:   #D4A017;
        --bg:     var(--c-bglight, #F8F5ED);
        --bg-card: var(--c-bg-card, #F5EFE3);
        --white:  #FFFFFF;
        --text-p: var(--c-text-primary, #2E1F14);
        --text-s: var(--c-text-secondary, #5C4D42);
        --border: var(--c-border-sepia, #E6DFD3);
    }

    .dark {
        --navy:   #38BDF8;
        --navy-d: #0284C7;
        --white:  #1E293B;
        --bg-card: #1E293B;
    }

    .text-navy { color: var(--navy) !important; }
    .bg-navy { background-color: var(--navy) !important; }
    .border-navy { border-color: var(--navy) !important; }
    .hover-bg-navy:hover { background-color: var(--navy) !important; color: white !important; }

    .ss-reader-layout {
        display: grid;
        grid-template-columns: 1fr;
        gap: 2rem;
    }

    @media (min-width: 1024px) {
        .ss-reader-layout {
            grid-template-columns: 280px 1fr;
    }
    }

    .nav-btn-active {
        background-color: var(--navy);
        color: white !important;
        border-color: var(--navy);
    }
    
    .study-content p {
        margin-bottom: 1.25rem;
    }
</style>

<!-- Breadcrumbs -->$_COOKIE<div class="fixed top-20 left-0 w-full z-40 bg-[var(--c-bg)]/90 backdrop-blur border-b border-[var(--c-border)] py-3 px-4 sm:px-8">
    <div class="max-w-7xl mx-auto flex items-center text-xs font-medium text-slate-500 tracking-widest uppercase">
        <a href="index.php" class="hover:text-[var(--c-gold)] transition-colors">Home</a>
        <span class="mx-2">/</span>
        <a href="sabbath-school-lessons.php" class="hover:text-[var(--c-gold)] transition-colors">Sabbath School</a>
        <span class="mx-2">/</span>
        <span class="text-navy truncate max-w-[200px]"><?= htmlspecialchars($week['title']) ?></span>
    </div>
</div>

<main class="pt-36 pb-16 min-h-screen" style="background-color: var(--c-bg)">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        
        <div class="ss-reader-layout">
            
            <!-- Left Navigation Sidebar -->$_COOKIE            <aside class="space-y-6">
                <!-- Quarterly Cover and Meta Info -->$_COOKIE                <div class="bg-white dark:bg-navy-light rounded-2xl border border-[var(--c-border)] p-4 shadow-sm flex items-center gap-3">
                    <?php if (file_exists(__DIR__ . '/' . $quarterly['cover_image'])): ?>
                        <img src="<?= htmlspecialchars($quarterly['cover_image']) ?>" class="w-16 h-20 object-cover rounded-lg shadow-sm" alt="Cover">
                    <?php endif; ?>
                    <div class="min-w-0">
                        <span class="text-[9px] uppercase font-bold tracking-wider text-amber-600 block mb-0.5">Sabbath School</span>
                        <h2 class="text-xs font-bold text-[var(--c-text-primary)] line-clamp-2 leading-tight" title="<?= htmlspecialchars($quarterly['title']) ?>">
                            <?= htmlspecialchars($quarterly['title']) ?>
                        </h2>
                        <p class="text-[10px] text-slate-400 font-semibold mt-1">Week <?= $week['week_number'] ?></p>
                    </div>
                </div>

                <!-- Daily Study Navigation -->$_COOKIE                <div class="bg-white dark:bg-navy-light rounded-2xl border border-[var(--c-border)] p-4 shadow-sm space-y-3">
                    <h3 class="text-[10px] uppercase font-bold tracking-wider text-slate-400 mb-2">Daily Study Guides</h3>
                    <div class="flex flex-col gap-1.5">
                        <?php foreach ($days as $d): 
                            $isActive = ($activeType === 'day' && $d['id'] === $activeDayId);
                            // Get day name (Sabbath, Sunday, etc.)
                            $dayName = date('l', strtotime($d['date']));
                        ?>
                            <a href="?week_id=<?= $week_id ?>&day_id=<?= $d['id'] ?>" class="flex items-center justify-between px-3 py-2 text-xs font-semibold rounded-lg border transition <?= $isActive ? 'nav-btn-active' : 'border-transparent text-[var(--c-text-primary)] hover:bg-slate-50 dark:hover:bg-slate-800' ?>">
                                <span><?= $dayName ?></span>
                                <span class="text-[10px] opacity-75 font-mono"><?= date('m/d', strtotime($d['date'])) ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Weekly & Media Resources -->$_COOKIE                <?php if (!empty($resources)): ?>
                    <div class="bg-white dark:bg-navy-light rounded-2xl border border-[var(--c-border)] p-4 shadow-sm space-y-3">
                        <h3 class="text-[10px] uppercase font-bold tracking-wider text-slate-400 mb-2">Study Resources</h3>
                        <div class="flex flex-col gap-1.5">
                            <!-- Inside Story -->$_COOKIE                            <?php if (isset($resources['inside-story'])): 
                                $isActive = ($activeType === 'resource' && $activeResourceType === 'inside-story');
                            ?>
                                <a href="?week_id=<?= $week_id ?>&type=resource&resource_type=inside-story" class="flex items-center gap-2 px-3 py-2 text-xs font-semibold rounded-lg border transition <?= $isActive ? 'nav-btn-active' : 'border-transparent text-[var(--c-text-primary)] hover:bg-slate-50 dark:hover:bg-slate-800' ?>">
                                    <span>📰</span> Inside Story
                                </a>
                            <?php endif; ?>

                            <!-- Teacher Comments -->$_COOKIE                            <?php if (isset($resources['teacher-comments'])): 
                                $isActive = ($activeType === 'resource' && $activeResourceType === 'teacher-comments');
                            ?>
                                <a href="?week_id=<?= $week_id ?>&type=resource&resource_type=teacher-comments" class="flex items-center gap-2 px-3 py-2 text-xs font-semibold rounded-lg border transition <?= $isActive ? 'nav-btn-active' : 'border-transparent text-[var(--c-text-primary)] hover:bg-slate-50 dark:hover:bg-slate-800' ?>">
                                    <span>🎓</span> Teacher Comments
                                </a>
                            <?php endif; ?>

                            <!-- Hope Sabbath School -->$_COOKIE                            <?php if (isset($resources['hope-ss'])): 
                                $isActive = ($activeType === 'resource' && $activeResourceType === 'hope-ss');
                            ?>
                                <a href="?week_id=<?= $week_id ?>&type=resource&resource_type=hope-ss" class="flex items-center gap-2 px-3 py-2 text-xs font-semibold rounded-lg border transition <?= $isActive ? 'nav-btn-active' : 'border-transparent text-[var(--c-text-primary)] hover:bg-slate-50 dark:hover:bg-slate-800' ?>">
                                    <span>💬</span> Hope SS Outline
                                </a>
                            <?php endif; ?>

                            <!-- PDF Resource -->$_COOKIE                            <?php if (isset($resources['pdf'])): 
                                $isActive = ($activeType === 'resource' && $activeResourceType === 'pdf');
                            ?>
                                <a href="?week_id=<?= $week_id ?>&type=resource&resource_type=pdf" class="flex items-center gap-2 px-3 py-2 text-xs font-semibold rounded-lg border transition <?= $isActive ? 'nav-btn-active' : 'border-transparent text-[var(--c-text-primary)] hover:bg-slate-50 dark:hover:bg-slate-800' ?>">
                                    <span>📄</span> Weekly PDF Document
                                </a>
                            <?php endif; ?>

                            <!-- Audio Resource -->$_COOKIE                            <?php if (isset($resources['audio'])): 
                                $isActive = ($activeType === 'resource' && $activeResourceType === 'audio');
                            ?>
                                <a href="?week_id=<?= $week_id ?>&type=resource&resource_type=audio" class="flex items-center gap-2 px-3 py-2 text-xs font-semibold rounded-lg border transition <?= $isActive ? 'nav-btn-active' : 'border-transparent text-[var(--c-text-primary)] hover:bg-slate-50 dark:hover:bg-slate-800' ?>">
                                    <span>🔊</span> Audio Lesson
                                </a>
                            <?php endif; ?>

                            <!-- Video Resource -->$_COOKIE                            <?php if (isset($resources['video'])): 
                                $isActive = ($activeType === 'resource' && $activeResourceType === 'video');
                            ?>
                                <a href="?week_id=<?= $week_id ?>&type=resource&resource_type=video" class="flex items-center gap-2 px-3 py-2 text-xs font-semibold rounded-lg border transition <?= $isActive ? 'nav-btn-active' : 'border-transparent text-[var(--c-text-primary)] hover:bg-slate-50 dark:hover:bg-slate-800' ?>">
                                    <span>📺</span> Video Lesson
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </aside>

            <!-- Right Content Panel -->$_COOKIE            <article class="bg-white dark:bg-navy-light rounded-3xl border border-[var(--c-border)] p-6 md:p-10 shadow-sm transition-colors">
                
                <!-- Content Header -->$_COOKIE                <header class="border-b border-[var(--c-border)] pb-5 mb-6">
                    <span class="text-xs font-bold text-amber-600 dark:text-amber-400 uppercase tracking-widest block mb-1">
                        <?= htmlspecialchars($activeSubtitle) ?>
                    </span>
                    <h1 class="text-2xl md:text-3xl lg:text-4xl font-bold font-display text-[var(--c-text-primary)] leading-tight">
                        <?= htmlspecialchars($activeTitle) ?>
                    </h1>
                </header>

                <!-- Study Content Body -->$_COOKIE                <div class="study-content select-text font-serif">
                    <?php if ($activeType === 'resource' && $activeResourceType === 'pdf' && $activeUrl !== ''): ?>
                        <!-- PDF.js Embedded View -->$_COOKIE                        <div class="space-y-4">
                            <div class="flex items-center justify-between bg-slate-50 dark:bg-navy p-3 rounded-lg border border-[var(--c-border)]">
                                <span class="text-xs text-[var(--c-text-secondary)] font-semibold">You can read the PDF resource directly below.</span>
                                <a href="<?= htmlspecialchars($activeUrl) ?>" target="_blank" class="bg-navy text-white text-xs px-4 py-1.5 rounded-lg hover:opacity-90 transition font-bold">
                                    Open PDF in New Tab
                                </a>
                            </div>
                            <iframe src="<?= htmlspecialchars($activeUrl) ?>" class="w-full h-[700px] border border-[var(--c-border)] rounded-2xl" allowfullscreen></iframe>
                        </div>
                        
                    <?php elseif ($activeType === 'resource' && $activeResourceType === 'audio' && $activeUrl !== ''): ?>
                        <!-- HTML5 Audio View -->$_COOKIE                        <div class="space-y-6 max-w-xl mx-auto py-10 text-center">
                            <div class="text-6xl mb-4">🔊</div>
                            <h2 class="text-lg font-bold text-[var(--c-text-primary)]"><?= htmlspecialchars($activeTitle) ?></h2>
                            <p class="text-xs text-slate-400">Stream the Sabbath School audio guide for this week.</p>
                            <div class="pt-4 flex justify-center">
                                <audio controls class="w-full">
                                    <source src="<?= htmlspecialchars($activeUrl) ?>" type="audio/mpeg">
                                    Your browser does not support the audio element.
                                </audio>
                            </div>
                        </div>

                    <?php elseif ($activeType === 'resource' && $activeResourceType === 'video' && $activeUrl !== ''): ?>
                        <!-- HTML5 Video / Iframe View -->$_COOKIE                        <div class="space-y-6 max-w-3xl mx-auto py-6">
                            <?php if (strpos($activeUrl, 'youtube.com') !== false || strpos($activeUrl, 'youtu.be') !== false): 
                                // Format Youtube embed URL
                                $ytId = '';
                                if (preg_match('/(?:youtube\.com\/(?:[^\/]+\/.+\/|(?:v|e(?:mbed)?)\/|.*[?&]v=)|youtu\.be\/)([^"&?\/ ]{11})/i', $activeUrl, $matches)) {
                                    $ytId = $matches[1];
                                }
                                $embedUrl = $ytId ? "https://www.youtube.com/embed/" . $ytId : $activeUrl;
                            ?>
                                <div class="aspect-video w-full overflow-hidden rounded-2xl border border-[var(--c-border)]">
                                    <iframe class="w-full h-full" src="<?= htmlspecialchars($embedUrl) ?>" title="YouTube video player" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" allowfullscreen></iframe>
                                </div>
                            <?php else: ?>
                                <div class="aspect-video w-full overflow-hidden rounded-2xl border border-[var(--c-border)] flex items-center justify-center bg-black">
                                    <video controls class="w-full h-full">
                                        <source src="<?= htmlspecialchars($activeUrl) ?>" type="video/mp4">
                                        Your browser does not support the video element.
                                    </video>
                                </div>
                            <?php endif; ?>
                            <div class="text-center">
                                <a href="<?= htmlspecialchars($activeUrl) ?>" target="_blank" class="text-xs text-navy font-bold hover:underline">
                                    Watch directly on external site &rarr;
                                </a>
                            </div>
                        </div>

                    <?php else: ?>
                        <!-- Markdown Text Content -->$_COOKIE                        <div class="prose max-w-none text-[var(--c-text-secondary)]">
                            <?= renderMarkdown($rawContent) ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Footer Navigation Day-by-Day -->$_COOKIE                <?php if ($activeType === 'day'): ?>
                    <div class="mt-10 pt-6 border-t border-[var(--c-border)] flex justify-between items-center text-xs">
                        <?php 
                        // Find current index
                        $currentIndex = -1;
                        foreach ($days as $idx => $d) {
                            if ($d['id'] === $activeDayId) {
                                $currentIndex = $idx;
                                break;
                        }
                        }
                        
                        $prevDay = $currentIndex > 0 ? $days[$currentIndex - 1] : null;
                        $nextDay = $currentIndex < count($days) - 1 ? $days[$currentIndex + 1] : null;
                        ?>

                        <?php if ($prevDay): ?>
                            <a href="?week_id=<?= $week_id ?>&day_id=<?= $prevDay['id'] ?>" class="text-navy hover:text-[var(--gold)] font-bold flex items-center gap-1">
                                &larr; Prev: <?= date('l', strtotime($prevDay['date'])) ?>
                            </a>
                        <?php else: ?>
                            <div></div>
                        <?php endif; ?>

                        <?php if ($nextDay): ?>
                            <a href="?week_id=<?= $week_id ?>&day_id=<?= $nextDay['id'] ?>" class="text-navy hover:text-[var(--gold)] font-bold flex items-center gap-1">
                                Next: <?= date('l', strtotime($nextDay['date'])) ?> &rarr;
                            </a>
                        <?php else: ?>
                            <div></div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

            </article>

        </div>

    </div>
</main>

<?php include __DIR__ . '/includes/footer.php'; ?>

The above content shows the entire, complete file contents of the requested file.
)}}
    if ($selectedDay) {
        $activeDayId = $selectedDay['id'];
        $dayDetailStmt = $db->prepare("SELECT * FROM ss_official_days WHERE id = ?");
        $dayDetailStmt->execute([$activeDayId]);
        $dayDetail = $dayDetailStmt->fetch();
        if ($dayDetail) {
            $activeTitle = $dayDetail['title'];
            $activeSubtitle = date('l, F d, Y', strtotime($dayDetail['date']));
            $rawContent = $da}}}}