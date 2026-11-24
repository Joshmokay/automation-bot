<?php
// detypes=1);
require_once __DIR__ . '/includes/db.php';
$db = getDB();

// Fetch Today's Daily Devotional
$todaysDevotion = $db->query("
    SELECT d.*, c.name as category_name 
    FROM daily_devotionals d 
    LEFT JOIN devotional_categories c ON d.category_id = c.id 
    WHERE d.devotion_date <= CURDATE() 
    ORDER BY d.devotion_date DESC 
    LIMIT 1
")->fetch();

// Filters & Pagination setup
$categoryFilter = $_GET['category'] ?? '';
$searchQuery = $_GET['search'] ?? '';

$params = [];
$whereClause = "WHERE 1=1";

if ($categoryFilter) {
    $whereClause .= " AND c.name = ?";
    $params[] = $categoryFilter;
}
if ($searchQuery) {
    $whereClause .= " AND (b.title LIKE ? OR b.author LIKE ?)";
    $params[] = "%$searchQuery%";
    $params[] = "%$searchQuery%";
}

// Fetch Devotional Books
$sql = "
    SELECT b.*, c.name as category_name 
    FROM devotional_books b 
    LEFT JOIN devotional_categories c ON b.category_id = c.id 
    $whereClause 
    ORDER BY b.created_at DESC
";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$devotionalBooks = $stmt->fetchAll();

// Fetch Categories for Filter
$categories = $db->query('SELECT name FROM devotional_categories ORDER BY name')->fetchAll(PDO::FETCH_COLUMN);

function truncate($text, $length = 120) {
    if (!$text) return '';
    return strlen(strip_tags($text)) > $length ? substr(strip_tags($text), 0, $length) . '...' : strip_tags($text);
}
?>
<?php
$pageTitle = 'Devotionals — Advent Library';
include __DIR__ . '/includes/header.php';
?>

<?php include __DIR__ . '/includes/navbar.php'; ?>

<!-- Hero Banner -->
<section class="relative pt-32 pb-20 lg:pt-40 lg:pb-28 overflow-hidden bg-soft-white">
    <div class="absolute inset-0 z-0">
        <div class="absolute inset-0 bg-navy opacity-5"></div>
        <img src="https://images.unsplash.com/photo-1504052434569-70ad5836ab65?q=80&w=2070&auto=format&fit=crop" class="w-full h-full object-cover mix-blend-overlay opacity-20" alt="Devotional Background">
        <div class="absolute inset-0 bg-gradient-to-t from-soft-white via-transparent to-transparent"></div>
    </div>
    
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 relative z-10 text-center reveal">
        <span class="inline-block py-1 px-3 rounded-full bg-gold/10 border border-gold/20 text-gold text-sm font-semibold tracking-wider mb-6">
            SPIRITUAL GROWTH
        </span>
        <h1 class="text-4xl md:text-6xl font-display font-bold text-navy mb-6">Daily Devotionals</h1>
        <p class="text-lg text-slate-600 max-w-2xl mx-auto mb-10">Start your day with inspiration from Scripture and the Spirit of Prophecy. Explore our vast collection of daily readings and devotional books.</p>
        
        <!-- Search Bar -->
        <form action="devotionals.php" method="GET" class="max-w-xl mx-auto relative flex items-center shadow-lg rounded-xl bg-white">
            <svg class="w-5 h-5 text-slate-400 absolute left-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
            <input type="text" name="search" placeholder="Search devotionals by title or author..." value="<?= htmlspecialchars($searchQuery) ?>" 
                   class="w-full pl-12 pr-4 py-4 rounded-xl bg-transparent border-2 border-transparent text-slate-800 placeholder-slate-400 focus:outline-none focus:border-gold/50 transition-all">
            <button type="submit" class="absolute right-2 top-2 bottom-2 px-6 btn-primary rounded-lg">
                Search
            </button>
        </form>
    </div>
</section>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-16">
    
    <!-- TODAY'S DEVOTIONAL HIGHLIGHT -->$_COOKIE    <?php if ($todaysDevotion && !$searchQuery && !$categoryFilter): ?>
    <div class="mb-20 reveal">
        <h2 class="text-2xl font-display font-bold text-navy mb-6 flex items-center gap-3">
            <svg class="w-6 h-6 text-gold" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M12 8a4 4 0 100 8 4 4 0 000-8z"/></svg>
            Today's Devotional
        </h2>
        <div class="relative rounded-2xl overflow-hidden card bg-white">
            <div class="grid lg:grid-cols-2">
                <!-- Image side -->
                <div class="relative min-h-[300px] lg:min-h-full hidden lg:block">
                    <img src="<?= $todaysDevotion['background_image'] ? htmlspecialchars($todaysDevotion['background_image']) : 'https://images.unsplash.com/photo-1490730141103-6cac27aaab94?q=80&w=2070&auto=format&fit=crop' ?>" 
                         class="absolute inset-0 w-full h-full object-cover" alt="Daily Devotional">
                    <div class="absolute inset-0 bg-gradient-to-r from-transparent to-white"></div>
                </div>
                
                <!-- Content side -->
                <div class="p-8 lg:p-12 flex flex-col justify-center">
                    <div class="text-gold text-sm font-semibold tracking-wider uppercase mb-2">
                        <?= date('l, F j, Y', strtotime($todaysDevotion['devotion_date'])) ?>
                    </div>
                    <h3 class="text-3xl font-display font-bold text-navy mb-4"><?= htmlspecialchars($todaysDevotion['title']) ?></h3>
                    <?php if ($todaysDevotion['verse']): ?>
                        <blockquote class="border-l-4 border-gold pl-4 py-1 mb-6 text-lg font-scripture text-slate-600">
                            <?= htmlspecialchars($todaysDevotion['verse']) ?>
                        </blockquote>
                    <?php endif; ?>
                    <p class="text-slate-600 mb-8 leading-relaxed">
                        <?= truncate($todaysDevotion['content'], 250) ?>
                    </p>
                    <a href="devotion.php?id=<?= $todaysDevotion['id'] ?>&type=daily" class="inline-flex items-center gap-2 text-gold font-semibold hover:text-royal transition-colors w-max group">
                        Read Full Devotional
                        <svg class="w-5 h-5 group-hover:translate-x-1 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"/></svg>
                    </a>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- DEVOTIONAL BOOKS & READINGS -->
    <div class="reveal">
        <div class="flex flex-col md:flex-row md:items-end justify-between gap-6 mb-10">
            <div>
                <h2 class="text-2xl font-display font-bold text-navy">Devotional Library</h2>
                <p class="text-slate-500 text-sm mt-2">Explore daily reading books and spiritual articles.</p>
            </div>
            
            <!-- Category Filter -->
            <div class="flex flex-wrap gap-2">
                <a href="devotionals.php" class="px-4 py-2 rounded-full text-sm font-medium transition-colors border <?= $categoryFilter === '' ? 'bg-gold text-white border-gold shadow-md' : 'bg-white text-slate-500 border-slate-200 hover:border-gold hover:text-navy' ?>">All</a>
                <?php foreach ($categories as $cat): ?>
                    <a href="devotionals.php?category=<?= urlencode($cat) ?>" class="px-4 py-2 rounded-full text-sm font-medium transition-colors border <?= $categoryFilter === $cat ? 'bg-gold text-white border-gold shadow-md' : 'bg-white text-slate-500 border-slate-200 hover:border-gold hover:text-navy' ?>">
                        <?= htmlspecialchars($cat) ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>

        <?php if (empty($devotionalBooks)): ?>
            <div class="text-center py-20 bg-white border border-slate-200 rounded-2xl shadow-sm">
                <svg class="w-16 h-16 mx-auto text-slate-400 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/></svg>
                <h3 class="text-xl font-medium text-slate-700">No devotionals found</h3>
                <p class="text-slate-500 mt-2">Try adjusting your search or category filter.</p>
            </div>
        <?php else: ?>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
                <?php foreach ($devotionalBooks as $i => $book): ?>
                <div class="group card flex flex-col relative" style="animation: fadeUp 0.5s ease forwards <?= $i * 0.1 ?>s; opacity: 0;">
                    
                    <?php if ($book['is_new']): ?>
                        <div class="absolute top-4 right-4 z-10 px-2 py-1 bg-forest text-white text-xs font-bold rounded shadow-lg uppercase tracking-wider">New</div>
                    <?php endif; ?>

                    <div class="relative h-48 sm:h-56 overflow-hidden bg-slate-100 rounded-t-xl">
                        <?php if ($book['cover_image']): ?>
                            <img src="<?= htmlspecialchars($book['cover_image']) ?>" class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500" alt="<?= htmlspecialchars($book['title']) ?>">
                        <?php else: ?>
                            <div class="w-full h-full flex items-center justify-center text-slate-300">
                                <svg class="w-12 h-12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                            </div>
                        <?php endif; ?>
                        <div class="absolute inset-0 bg-gradient-to-t from-white to-transparent"></div>
                    </div>

                    <div class="p-5 flex-1 flex flex-col">
                        <div class="text-[10px] font-bold text-gold uppercase tracking-widest mb-2">
                            <?= htmlspecialchars($book['category_name'] ?? 'Devotional') ?>
                        </div>
                        <h3 class="text-lg font-display font-bold text-navy mb-1 leading-tight group-hover:text-gold transition-colors">
                            <?= htmlspecialchars($book['title']) ?>
                        </h3>
                        <p class="text-sm text-slate-500 mb-3 font-medium">By <?= htmlspecialchars($book['author']) ?></p>
                        <p class="text-sm text-slate-600 line-clamp-3 mb-6 flex-1">
                            <?= htmlspecialchars($book['description']) ?>
                        </p>
                        
                        <div class="flex items-center gap-3 mt-auto">
                            <a href="devotion.php?id=<?= $book['id'] ?>&type=book" class="flex-1 btn-primary text-center py-2 text-sm">
                                Read
                            </a>
                            <?php if ($book['pdf_file']): ?>
                                <a href="<?= htmlspecialchars($book['pdf_file']) ?>" download class="p-2 bg-slate-100 hover:bg-gold hover:text-white text-slate-500 rounded transition-colors" title="Download PDF">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<style>
@keyframes fadeUp {
    from { opacity: 0; transform: translateY(20px); }
    to { opacity: 1; transform: translateY(0); }
}
</style>

<?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
"}")