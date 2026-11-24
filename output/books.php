<?php
// books.php — Advent Library Unified Digital Catalog
declare(strict_types=1);

require_once __DIR__ . '/includes/db.php';

$db = getDB();

// ── AJAX AUTOCOMPLETE SUGGESTIONS ──
if (isset($_GET['action']) && $_GET['action'] === 'suggest') {
        header('Content-Type: application/json');
            $q = trim($_GET['q'] ?? '');
                if ($q === '') {
                            echo json_encode([]);
                                    exit;
                }
                    try {
                                $stmt = $db->prepare("
                                            SELECT id, title, author, cover_image 
                                                        FROM books 
                                                                    WHERE title LIKE ? OR author LIKE ? 
                                                                                LIMIT 5
                                                                                        ");
                                                                                                $stmt->execute(['%' . $q . '%', '%' . $q . '%']);
                                                                                                        $results = $stmt->fetchAll();
                                                                                                                echo json_encode($results);
                                                                                                                    } catch (Throwable $e) {
                                                                                                                                echo json_encode([]);
                                                                                                                    }
                                                                                                                        exit;
}

$pageTitle       = 'Digital Library — Advent Library';
$pageDescription = 'Access our complete collection of self-hosted publications, Ellen G. White writings, and community documents in high-fidelity PDF.';

// Helper to truncate text
function truncateWords(string $text, int $words = 16): string {
        $text  = strip_tags($text);
            $parts = explode(' ', $text);
                return count($parts) > $words
                        ? implode(' ', array_slice($parts, 0, $words)) . '…'
                                : $text;
}

// Helper to format file size
function getFormattedFileSize(string $path): string {
        $fullPath = __DIR__ . '/' . $path;
            if (!empty($path) && file_exists($fullPath)) {
                        $size = filesize($fullPath);
                                $units = ['B', 'KB', 'MB', 'GB'];
                                        $power = $size > 0 ? (int)floor(log($size, 1024)) : 0;
                                                return number_format($size / pow(1024, $power), 1) . ' ' . $units[$power];
            }
                return 'N/A';
}

// ── Gather Statistics for Hero Banner ──
$totalBooksCount = (int)$db->query("SELECT COUNT(*) FROM books")->fetchColumn();
$totalCats       = (int)$db->query("SELECT COUNT(*) FROM categories")->fetchColumn();
$totalReadsCount = 0;
$totalDownloadsCount = (int)$db->query("SELECT COALESCE(SUM(download_count), 0) FROM books")->fetchColumn();

// ── Search & Filter parameters ──
$searchQuery = trim($_GET['q'] ?? '');
$filterCat   = (int)($_GET['category'] ?? 0);
$filterLang  = trim($_GET['language'] ?? '');
$filterYear  = trim($_GET['year'] ?? '');
$filterAuthor= trim($_GET['author'] ?? '');
$filterFeat  = isset($_GET['featured']) && $_GET['featured'] === '1';

// Build SQL where clause
$whereSql = [];
$whereParams = [];

if ($searchQuery !== '') {
        $whereSql[] = '(b.title LIKE ? OR b.author LIKE ? OR b.description LIKE ?)';
            $whereParams[] = '%' . $searchQuery . '%';
                $whereParams[] = '%' . $searchQuery . '%';
                    $whereParams[] = '%' . $searchQuery . '%';
}
if ($filterCat) {
        $whereSql[] = 'b.category_id = ?';
            $whereParams[] = $filterCat;
}
// language column does not exist in schema
if ($filterYear !== '') {
        $whereSql[] = 'b.publication_year = ?';
            $whereParams[] = (int)$filterYear;
}
if ($filterAuthor !== '') {
        $whereSql[] = 'b.author = ?';
            $whereParams[] = $filterAuthor;
}
if ($filterFeat) {
        $whereSql[] = 'b.is_featured = 1';
}

$where = $whereSql ? 'WHERE ' . implode(' AND ', $whereSql) : '';

// ── Pagination setup ──
$page  = max(1, (int)($_GET['page'] ?? 1));
$limit = 10;
$offset = ($page - 1) * $limit;

// Count total matching books
$countStmt = $db->prepare("SELECT COUNT(*) FROM books b $where");
$countStmt->execute($whereParams);
$totalMatching = (int)$countStmt->fetchColumn();
$totalPages = (int)ceil($totalMatching / $limit);

// Fetch books for current page
$booksQueryStr = "
    SELECT b.*, c.name AS cat_name, c.color AS cat_color, c.slug AS cat_slug
        FROM books b
            LEFT JOIN categories c ON b.category_id = c.id
                $where
                    ORDER BY b.created_at DESC
                        LIMIT $limit OFFSET $offset
                        ";
                        $booksStmt = $db->prepare($booksQueryStr);
                        $booksStmt->execute($whereParams);
                        $catalogBooks = $booksStmt->fetchAll();
                        
                        // ── Fetch Featured Books (limit 4) ──
                        // $featured = $db->query("
                        //     SELECT b.*, c.name AS cat_name, c.color AS cat_color 
                        //     FROM books b
                        //     LEFT JOIN categories c ON b.category_id = c.id
                        //     WHERE b.is_featured = 1
                        //     ORDER BY b.created_at DESC
                        //     LIMIT 4
                        // ")->fetchAll();
                        // 
                        // // ── Fetch Most Read Books (limit 4) ──
                        // $mostRead = $db->query("
                        //     SELECT b.*, c.name AS cat_name, c.color AS cat_color 
                        //     FROM books b
                        //     LEFT JOIN categories c ON b.category_id = c.id
                        //     ORDER BY b.download_count DESC
                        //     LIMIT 4
                        // ")->fetchAll();
                        // 
                        // // ── Fetch Recently Added Books (limit 4) ──
                        // $recentlyAdded = $db->query("
                        //     SELECT b.*, c.name AS cat_name, c.color AS cat_color 
                        //     FROM books b
                        //     LEFT JOIN categories c ON b.category_id = c.id
                        //     ORDER BY b.created_at DESC
                        //     LIMIT 4
                        // ")->fetchAll();
                        // 
                        // // ── Gather filter options for dropdowns ──
                        // $categories = $db->query("SELECT id, name FROM categories ORDER BY name ASC")->fetchAll();
                        // $languages  = ['English'];
                        // $years      = $db->query("SELECT DISTINCT publication_year FROM books WHERE publication_year IS NOT NULL AND publication_year > 0 ORDER BY publication_year DESC")->fetchAll(PDO::FETCH_COLUMN);
                        // $authors    = $db->query("SELECT DISTINCT author FROM books WHERE author IS NOT NULL AND author != '' ORDER BY author ASC")->fetchAll(PDO::FETCH_COLUMN);
                        // 
                        // include __DIR__ . '/includes/header.php';
                        // include __DIR__ . '/includes/navbar.php';
                        // ?>
                        
                        <style>
                            :root {
                                        --navy:   #245A63; /* Teal/Forest brand color */
                                                --gold:   #D4A017;
                                                        --gold-l: #F2C144;
                                                                --bg:     var(--c-bglight, #F8F5ED);
                                                                        --bg2:    var(--c-bg-card, #F5EFE3);
                                                                                --white:  var(--c-bg-card, #FFFFFF);
                                                                                        --text1:  var(--c-text-primary, #2E1F14);
                                                                                                --text2:  var(--c-text-secondary, #5C4D42);
                                                                                                        --muted:  var(--c-text-secondary, #8E7E73);
                                                                                                                --border: var(--c-border-sepia, #E6DFD3);
}

    .dark {
                --navy:   #38BDF8; /* Sky/Teal in dark mode */
                        --gold:   #F59E0B;
                                --gold-l: #FBBF24;
                                        --white:  var(--c-bg-card, #1E293B);
}

    .text-navy {
                color: var(--navy) !important;
}
    .bg-navy {
                background-color: var(--navy) !important;
}

    .books-hero {
                background: linear-gradient(135deg, #245A63 0%, #122D31 100%);
                        position: relative;
                                overflow: hidden;
}
    .dark .books-hero {
                background: linear-gradient(135deg, #1A3E44 0%, #0E2225 100%);
}
    .books-hero::before {
                content: '';
                        position: absolute;
                                inset: 0;
                                        background: radial-gradient(circle at 80% 50%, rgba(212,160,23,0.12) 0%, transparent 60%);
                                                pointer-events: none;
}

    .book-card {
                background: var(--white);
                        border: 1px solid var(--border);
                                border-radius: 0.75rem;
                                        overflow: hidden;
                                                display: flex;
                                                        flex-direction: column;
                                                                transition: transform .3s ease, box-shadow .3s ease, border-color .3s ease;
                                                                        position: relative;
                                                                                box-shadow: 0 4px 12px rgba(46,31,20,0.04);
}
    .book-card:hover {
                transform: translateY(-4px);
                        box-shadow: 0 16px 32px rgba(46,31,20,0.1);
                                border-color: var(--gold);
}
    .book-cover-wrap {
                position: relative;
                        aspect-ratio: 2/3;
                                background: linear-gradient(135deg, var(--navy) 0%, #1a100a 100%);
                                        overflow: hidden;
                                                flex-shrink: 0;
                                                        width: 100%;
}
    .book-cover-wrap img {
                width: 100%;
                        height: 100%;
                                object-fit: cover;
                                        transition: transform .5s ease;
}
    .book-card:hover .book-cover-wrap img {
                transform: scale(1.04);
}

    .cat-tag {
                position: absolute;
                        bottom: 0.5rem;
                                right: 0.5rem;
                                        color: #fff;
                                                font-size: 0.6rem;
                                                        font-weight: 700;
                                                                padding: 0.2rem 0.6rem;
                                                                        border-radius: 4px;
                                                                                z-index: 10;
                                                                                        box-shadow: 0 2px 6px rgba(0,0,0,0.2);
}
    
    .featured-badge {
                position: absolute;
                        top: 0.5rem;
                                left: 0.5rem;
                                        background: var(--gold);
                                                color: #fff;
                                                        font-size: 0.55rem;
                                                                font-weight: 800;
                                                                        text-transform: uppercase;
                                                                                padding: 0.2rem 0.5rem;
                                                                                        border-radius: 4px;
                                                                                                z-index: 10;
                                                                                                        box-shadow: 0 2px 6px rgba(0,0,0,0.15);
}

    .book-body {
                padding: 1rem;
                        flex: 1;
                                display: flex;
                                        flex-direction: column;
                                                gap: 0.35rem;
}
    
    .book-title {
                font-family: 'Playfair Display', serif;
                        font-size: 0.9rem;
                                font-weight: 700;
                                        color: var(--navy);
                                                line-height: 1.25;
                                                        display: -webkit-box;
                                                                -webkit-line-clamp: 2;
                                                                        -webkit-box-orient: vertical;
                                                                                overflow: hidden;
                                                                                        min-height: 2.5em;
}

    .book-author {
                font-size: 0.72rem;
                        color: var(--gold);
                                font-weight: 600;
                                        white-space: nowrap;
                                                overflow: hidden;
                                                        text-overflow: ellipsis;
}

    .book-desc {
                font-size: 0.72rem;
                        color: var(--text2);
                                line-height: 1.4;
                                        display: -webkit-box;
                                                -webkit-line-clamp: 2;
                                                        -webkit-box-orient: vertical;
                                                                overflow: hidden;
                                                                        height: 2.8em;
}

    .book-actions {
                display: flex;
                        gap: 0.35rem;
                                margin-top: auto;
                                        padding-top: 0.6rem;
                                                border-top: 1px solid var(--border);
}

    .btn-read {
                flex: 1;
                        display: inline-flex;
                                align-items: center;
                                        justify-content: center;
                                                gap: 0.25rem;
                                                        background: var(--navy);
                                                                color: var(--white);
                                                                        font-size: 0.7rem;
                                                                                font-weight: 700;
                                                                                        padding: 0.45rem 0.6rem;
                                                                                                border-radius: 0.375rem;
                                                                                                        transition: background-color 0.2s, transform 0.1s;
}
    .btn-read:hover {
                background-color: var(--gold);
                        color: #fff;
}

    .btn-download {
                display: inline-flex;
                        align-items: center;
                                justify-content: center;
                                        gap: 0.25rem;
                                                background: var(--gold);
                                                        color: #fff;
                                                                font-size: 0.7rem;
                                                                        font-weight: 700;
                                                                                padding: 0.45rem 0.6rem;
                                                                                        border-radius: 0.375rem;
                                                                                                transition: background-color 0.2s, transform 0.1s;
}
    .btn-download:hover {
                background-color: var(--gold-l);
}
    .dark .book-card {
                box-shadow: 0 4px 12px rgba(0,0,0,0.25);
}
    .dark .book-card:hover {
                box-shadow: 0 16px 32px rgba(0,0,0,0.4);
}
</style>

<!-- ══════════════════════════════════════════════
      HERO BANNER & STATS
           ══════════════════════════════════════════════ -->
           <section class="books-hero text-white py-20 lg:py-24">
                <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 relative z-10 text-center">
                            <span class="inline-flex items-center gap-1 bg-white/10 border border-white/20 rounded-full px-3.5 py-1 text-xs uppercase tracking-widest text-[#D4A017] font-bold mb-4">
                                            📚 Self-Hosted Digital Library
</span>
        <h1 class="text-4xl md:text-5xl lg:text-6xl font-extrabold uppercase tracking-tight text-white mb-4" style="font-family: 'Montserrat', sans-serif;">
                        The Advent Library <em class="text-[#D4A017] not-italic">Collection</em>
</h1>
        <p class="text-lg text-white/70 max-w-2xl mx-auto mb-10 leading-relaxed">
                        Preserving original formats, layouts, illustrations, and pagination. Access thousands of sacred publications locally and read them instantly.
</p>

        <!-- Search Bar with Suggestions -->
                 <div class="max-w-2xl mx-auto mb-12 relative">
                                <form action="books.php" method="GET" autocomplete="off" id="searchForm">
                                                    <div class="flex items-center bg-[var(--white)] border border-[var(--border)] rounded-xl overflow-hidden shadow-lg p-1.5">
                                                                            <div class="px-3 text-gray-400">
                                                                                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
</div>
                    <input type="search" name="q" id="search-input" value="<?= htmlspecialchars($searchQuery) ?>" placeholder="Search books by title, author, description..." class="flex-grow py-3.5 text-sm outline-none text-[var(--text1)] bg-transparent">
                                        <button type="submit" class="bg-[var(--gold)] hover:bg-[var(--gold-l)] text-white font-bold px-6 py-3.5 rounded-lg text-xs uppercase tracking-wider transition">
                                                                    Search Catalog
</button>
</div>
</form>
            <!-- Auto-Suggestions Overlay -->
                         <div id="suggestions-box" class="absolute left-0 right-0 top-full mt-2 bg-[var(--white)] rounded-xl shadow-2xl border border-[var(--border)] text-left overflow-hidden hidden z-50">
                                            <!-- Loaded dynamically -->
</div>
</div>

        <!-- Statistics overview row -->
                 <div class="grid grid-cols-2 md:grid-cols-4 gap-4 max-w-4xl mx-auto pt-6 border-t border-white/10">
                                <div class="p-4 rounded-xl bg-white/5 border border-white/10 backdrop-blur-sm">
                                                    <span class="text-[10px] uppercase font-bold tracking-widest text-gray-400">Total Publications</span>
                                                                    <div class="text-2xl md:text-3xl font-stats font-bold text-[#D4A017] mt-1"><?= number_format($totalBooksCount) ?></div>
</div>
            <div class="p-4 rounded-xl bg-white/5 border border-white/10 backdrop-blur-sm">
                                <span class="text-[10px] uppercase font-bold tracking-widest text-gray-400">Categories</span>
                                                <div class="text-2xl md:text-3xl font-stats font-bold text-[#D4A017] mt-1"><?= number_format($totalCats) ?></div>
</div>
            <div class="p-4 rounded-xl bg-white/5 border border-white/10 backdrop-blur-sm">
                                <span class="text-[10px] uppercase font-bold tracking-widest text-gray-400">Total Reads</span>
                                                <div class="text-2xl md:text-3xl font-stats font-bold text-[#D4A017] mt-1"><?= number_format($totalReadsCount) ?></div>
</div>
            <div class="p-4 rounded-xl bg-white/5 border border-white/10 backdrop-blur-sm">
                                <span class="text-[10px] uppercase font-bold tracking-widest text-gray-400">Downloads Logged</span>
                                                <div class="text-2xl md:text-3xl font-stats font-bold text-[#D4A017] mt-1"><?= number_format($totalDownloadsCount) ?></div>
</div>
</div>
</div>
</section>

<!-- ── Main Workspace ── -->
 <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
        
     <!-- ── FEATURED picked publications (Only show on default list) ── -->
          <?php if (empty($searchQuery) && !$filterCat && $page === 1 && !empty($featured)): ?>
                <section class="mb-16">
                            <div class="flex items-center justify-between mb-6 border-b border-[#E6DFD3] pb-3">
                                            <h2 class="text-xl font-extrabold uppercase text-navy flex items-center gap-2 tracking-tight">
                                                                <span class="text-[#D4A017]">★</span> Featured Publications
          </h2>
                      <span class="text-xs text-gray-500 font-bold">Premium Picks</span>
          </div>
                  <div class="grid grid-cols-4 sm:grid-cols-5 lg:grid-cols-6 gap-2 md:gap-4">
                                <?php foreach ($featured as $book): ?>
                                                <article class="book-card relative flex flex-col bg-[var(--white)] border border-[var(--border)] rounded-lg overflow-hidden transition-all duration-300 hover:-translate-y-0.5 hover:shadow-soft group">
                                                                    <div class="book-cover-wrap">
                                                                                            <span class="featured-badge">Featured</span>
                                                                                                                <?php if ($book['cover_image'] && file_exists(__DIR__ . '/' . $book['cover_image'])): ?>
                                                                                                                                            <img src="<?= htmlspecialchars($book['cover_image']) ?>?v=<?= time() ?>" alt="<?= htmlspecialchars($book['title']) ?>" loading="lazy">
                                                                                                                                                                <?php else: ?>
                                                                                                                                                                                            <div class="w-full h-full flex flex-col items-center justify-center text-[9px] text-white/40 uppercase font-bold text-center px-2 leading-normal">
                                                                                                                                                                                                                            <?= htmlspecialchars(truncateWords($book['title'], 4)) ?>
                                                                                                                                                                </div>
                                                                                                                                                                                    <?php endif; ?>
                                                                                                                                                                                                        <?php if ($book['cat_name']): ?>
                                                                                                                                                                                                                                <span class="cat-tag" style="background: <?= htmlspecialchars($book['cat_color'] ?? '#3B82F6') ?>ee">
                                                                                                                                                                                                                                                            <?= htmlspecialchars($book['cat_name']) ?>
                                                                                                                                                                                                        </span>
                                                                                                                                                                                                                            <?php endif; ?>
                                                                                                                                                                                                        </div>
                                                                                                                                                                                                                        <div class="p-1.5 flex flex-col flex-1 gap-1">
                                                                                                                                                                                                                                                <h3 class="text-[10px] md:text-xs font-bold text-[var(--text1)] leading-tight line-clamp-1 w-full font-heading" title="<?= htmlspecialchars($book['title']) ?>">
                                                                                                                                                                                                                                                                            <a href="book-details.php?id=<?= $book['id'] ?>" class="hover:text-[var(--gold)] transition-colors">
                                                                                                                                                                                                                                                                                                            <?= htmlspecialchars($book['title']) ?>
                                                                                                                                                                                                        </a>
                                                                                                                                                                                                        </h3>
                                                                                                                                                                                                                            
                                                                                                                                                                                                                            <div class="flex gap-1 mt-auto">
                                                                                                                                                                                                                                                        <a href="reader.php?id=<?= $book['id'] ?>" class="flex-1 flex items-center justify-center bg-[var(--navy)] hover:bg-[var(--gold)] text-[var(--white)] py-1 rounded text-[9px] font-bold transition-all shadow-sm" title="Read Online">
                                                                                                                                                                                                                                                                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                                                                                                                                                                                        </a>
                                                                                                                                                                                                                                <a href="download.php?id=<?= $book['id'] ?>" class="flex-1 flex items-center justify-center bg-[var(--gold)] hover:bg-[var(--gold-l)] text-white py-1 rounded text-[9px] font-bold transition-all shadow-sm" title="Download PDF">
                                                                                                                                                                                                                                                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                                                                                                                                                                                                        </a>
                                                                                                                                                                                                        </div>
                                                                                                                                                                                                        </div>
                                                                                                                                                                                                        </article>
                                                                                                                                                                                                                    <?php endforeach; ?>
                                                                                                                                                                                                        </div>
                                                                                                                                                                                                        </section>
                                                                                                                                                                                                            <?php endif; ?>

                                                                                                                                                                                                                <div class="grid grid-cols-1 lg:grid-cols-4 gap-8">
                                                                                                                                                                                                                            
                                                                                                                                                                                                                        <!-- ── Left Sidebar Filters ── -->
                                                                                                                                                                                                                                 <aside class="lg:col-span-1 space-y-6">
                                                                                                                                                                                                                                                <div class="bg-[var(--white)] p-6 rounded-xl border border-[var(--border)] shadow-sm">
                                                                                                                                                                                                                                                                    <h3 class="text-sm font-extrabold uppercase text-[var(--navy)] border-b border-[var(--border)] pb-2 mb-4">🔍 Filters</h3>
                                                                                                                                                                                                                                                                                    
                                                                                                                                                                                                                                                                                    <form action="books.php" method="GET" class="space-y-4">
                                                                                                                                                                                                                                                                                                            <?php if ($searchQuery !== ''): ?>
                                                                                                                                                                                                                                                                                                                                        <input type="hidden" name="q" value="<?= htmlspecialchars($searchQuery) ?>">
                                                                                                                                                                                                                                                                                                                                                            <?php endif; ?>

                                                                                                                                                                                                                                                                                                                                                                                <!-- Category Filter -->
                                                                                                                                                                                                                                                                                                                                                                                                     <div>
                                                                                                                                                                                                                                                                                                                                                                                                                                <label class="block text-xs font-bold text-gray-500 uppercase mb-1">Category</label>
                                                                                                                                                                                                                                                                                                                                                                                                                                                        <select name="category" class="w-full bg-[var(--bg)] border border-[var(--border)] text-sm rounded-lg px-3 py-2 outline-none text-[var(--text1)] focus:border-[var(--gold)]">
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                        <option value="">All Categories</option>
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                    <?php foreach ($categories as $cat): ?>
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                        <option value="<?= $cat['id'] ?>" <?= $filterCat === (int)$cat['id'] ? 'selected' : '' ?>><?= htmlspecialchars($cat['name']) ?></option>
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                    <?php endforeach; ?>
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                    </select>
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                    </div>

                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                        <!-- Author Filter -->
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                             <div>
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                        <label class="block text-xs font-bold text-gray-500 uppercase mb-1">Author</label>
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                <select name="author" class="w-full bg-[var(--bg)] border border-[var(--border)] text-sm rounded-lg px-3 py-2 outline-none text-[var(--text1)] focus:border-[var(--gold)]">
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                <option value="">All Authors</option>
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                            <?php foreach ($authors as $auth): ?>
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                            <option value="<?= htmlspecialchars($auth) ?>" <?= $filterAuthor === $auth ? 'selected' : '' ?>><?= htmlspecialchars($auth) ?></option>
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                        <?php endforeach; ?>
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                            </select>
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                            </div>

                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                <!-- Language Filter -->
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                     <div>
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                <label class="block text-xs font-bold text-gray-500 uppercase mb-1">Language</label>
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                        <select name="language" class="w-full bg-[var(--bg)] border border-[var(--border)] text-sm rounded-lg px-3 py-2 outline-none text-[var(--text1)] focus:border-[var(--gold)]">
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                        <option value="">All Languages</option>
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                    <?php foreach ($languages as $lang): ?>
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                    <option value="<?= htmlspecialchars($lang) ?>" <?= $filterLang === $lang ? 'selected' : '' ?>><?= htmlspecialchars($lang) ?></option>
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                <?php endforeach; ?>
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                    </select>
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                    </div>

                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                        <!-- Year Filter -->
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                             <div>
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                        <label class="block text-xs font-bold text-gray-500 uppercase mb-1">Publication Year</label>
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                <select name="year" class="w-full bg-[var(--bg)] border border-[var(--border)] text-sm rounded-lg px-3 py-2 outline-none text-[var(--text1)] focus:border-[var(--gold)]">
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                <option value="">Any Year</option>
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                            <?php foreach ($years as $yr): ?>
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                            <option value="<?= $yr ?>" <?= $filterYear === (string)$yr ? 'selected' : '' ?>><?= $yr ?></option>
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                        <?php endforeach; ?>
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                            </select>
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                            </div>

                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                <!-- Featured checkbox -->
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                     <div class="flex items-center gap-2 pt-1">
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                <input type="checkbox" id="sidebar-feat" name="featured" value="1" <?= $filterFeat ? 'checked' : '' ?> class="w-4 h-4 text-amber-500 bg-[var(--bg)] border-[var(--border)] rounded focus:ring-amber-500">
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                        <label for="sidebar-feat" class="text-xs font-bold text-[var(--text1)] select-none">Show Featured Only</label>
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                            </div>

                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                <button type="submit" class="w-full bg-[var(--navy)] hover:bg-[var(--gold)] text-[var(--white)] py-2.5 text-xs font-bold uppercase tracking-wider rounded-lg transition mt-4">
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                            Apply Filters
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                            </button>
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                <?php if ($filterCat || $filterLang || $filterYear || $filterAuthor || $filterFeat || $searchQuery !== ''): ?>
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                        <a href="books.php" class="block w-full text-center py-2 text-xs font-bold text-red-500 border border-red-500/20 rounded-lg hover:bg-red-500/10 transition">
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                        Clear All Filters
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                </a>
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                    <?php endif; ?>
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                </form>
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                </div>
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                            
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                        <!-- Quick Category Grid -->
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                     <div class="bg-[var(--white)] p-6 rounded-xl border border-[var(--border)] shadow-sm hidden lg:block">
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                        <h3 class="text-sm font-extrabold uppercase text-[var(--navy)] border-b border-[var(--border)] pb-2 mb-4"> Browse Topics</h3>
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                        <div class="space-y-2">
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                <?php foreach (array_slice($categories, 0, 8) as $cat): ?>
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                        <a href="books.php?category=<?= $cat['id'] ?>" class="flex items-center justify-between text-xs font-bold text-[var(--text2)] hover:text-[var(--gold)] transition-colors py-1">
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                        <span><?= htmlspecialchars($cat['name']) ?></span>
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                    <span class="text-[var(--muted)] font-normal">&rarr;</span>
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                </a>
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                    <?php endforeach; ?>
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                </div>
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                            </div>
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                    </aside>

                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                            <!--  Right Catalog Grid ── -->
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                      <main class="lg:col-span-3">
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                    <div class="flex items-center justify-between border-b border-[var(--border)] pb-3 mb-6">
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                        <h2 class="text-xl font-extrabold uppercase text-[var(--navy)]">
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                <?= ($searchQuery !== '' || $filterCat || $filterLang || $filterYear || $filterAuthor || $filterFeat) ? 'Search Results' : 'Catalog Library' ?>
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                    <span class="text-xs text-[var(--muted)] font-normal lowercase">(<?= number_format($totalMatching) ?> books found)</span>
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                </h2>
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                <!-- Page layout indicators -->
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                 <span class="text-xs text-[var(--text2)]">Page <?= $page ?> of <?= max(1, $totalPages) ?></span>
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                </div>

                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                            <?php if (empty($catalogBooks)): ?>
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                            <div class="text-center py-20 bg-[var(--white)] border border-[var(--border)] rounded-2xl">
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                    <svg class="w-16 h-16 mx-auto text-[var(--muted)] mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/></svg>
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                        <h3 class="text-lg font-bold text-[var(--navy)] mb-1">No Books Found</h3>
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                            <p class="text-xs text-[var(--teP
    }
    }
    }
    }
    }
    }
    }
    }
    }
    }
    }
    }
    }
    }
    }
    }
    }
    }
    }
    }
    }
    }
    }
    }
                            })))"
}
}
}
}
}
            }
}
}
                                                                                                                    }")
                    }
                }
}