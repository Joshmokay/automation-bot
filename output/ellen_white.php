<?php
// ellen_white.php — Ellen G. White Writings
declare(strict_types=1);
require_once __DIR__ . '/includes/db.php';

// Fetch categories
function fetchEGWCategories(): array {
    try {
        $db = getDB();
        $stmt = $db->query('SELECT * FROM egw_categories ORDER BY category_name ASC');
        return $stmt->fetchAll();
} catch (Throwable $e) {
        return [];
}
}

// Fetch books with filtering & pagination
function fetchEGWBooks(string $search = '', string $category = '', int $limit = 12, int $offset = 0): array {
    try {
        $db = getDB();
        
        $where = [];
        $params = [];
        
        if ($search) {
            $where[] = '(b.title LIKE :search OR b.description LIKE :search)';
            $params[':search'] = '%' . $search . '%';
}
        
        if ($category) {
            $where[] = 'c.id = :category';
            $params[':category'] = $category;
}
        
        $whereClause = count($where) > 0 ? 'WHERE ' . implode(' AND ', $where) : '';
        
        $sql = "SELECT b.*, c.category_name 
                FROM egw_books b 
                LEFT JOIN egw_categories c ON b.category_id = c.id 
                $whereClause 
                ORDER BY b.created_at DESC 
                LIMIT :limit OFFSET :offset";
                
        $stmt = $db->prepare($sql);
        
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
    }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        
        $stmt->execute();
        return $stmt->fetchAll();
} catch (Throwable $e) {
        return [];
    }
}

function countEGWBooks(string $search = '', string $category = ''): int {
    try {
        $db = getDB();
        $where = [];
        $params = [];
        if ($search) {
            $where[] = '(b.title LIKE :search OR b.description LIKE :search)';
            $params[':search'] = '%' . $search . '%';
        }
        if ($category) {
            $where[] = 'c.id = :category';
            $params[':category'] = $category;
        }
        $whereClause = count($where) > 0 ? 'WHERE ' . implode(' AND ', $where) : '';
        
        $sql = "SELECT COUNT(b.id) 
                FROM egw_books b 
                LEFT JOIN egw_categories c ON b.category_id = c.id 
                $whereClause";
        $stmt = $db->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->execute();
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function isEGWNewBook(string $created_at): bool {
    return strtotime($created_at) >= strtotime('-7 days');
}

$search = $_GET['q'] ?? '';
$category = $_GET['category'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 12;
$offset = ($page - 1) * $limit;

$categories = fetchEGWCategories();
$books = fetchEGWBooks($search, $category, $limit, $offset);
$total_books = countEGWBooks($search, $category);
$total_pages = ceil($total_books / $limit);

?>
<?php
$pageTitle = 'Ellen G. White Writings — Advent Library';
include __DIR__ . '/includes/header.php';
?>
    <style type="text/tailwindcss">
        :root {
            --c-bg:          theme('colors.soft-white');
            --c-bg-soft:     #ffffff;
            --c-surface:     #ffffff;
            --c-surface-2:   #fafafa;
            --c-border:      rgba(30, 58, 95, 0.1);
            --c-border-soft: rgba(30, 58, 95, 0.05);

            --c-gold:        theme('colors.gold');
            --c-gold-light:  #e8b84a;
            --c-gold-pale:   theme('colors.gold/10');
            --c-gold-glow:   theme('colors.gold/20');

            --c-text-primary:   theme('colors.navy');
            --c-text-secondary: theme('colors.slate.500');
            --c-text-muted:     theme('colors.slate.400');
        }

        /* Hero */
        .egw-hero {
            position: relative;
            padding: 8rem 1rem 5rem;
            background: linear-gradient(to bottom, rgba(20,30,50,0.8), rgba(20,30,50,0.95)), url('https://images.unsplash.com/photo-1457369804613-52c61a468e7d?w=1600&q=80') center/cover;
            border-bottom: 1px solid var(--c-border);
        }
        
        /* Cards */
        .book-card {
            background: var(--c-surface);
            border: 1px solid var(--c-border);
            border-radius: 1rem;
            overflow: hidden;
            transition: transform .3s ease, box-shadow .3s ease, border-color .3s ease;
        }
        .book-card:hover {
            transform: translateY(-6px);
            box-shadow: 0 20px 50px rgba(0,0,0,.4), 0 0 0 1px var(--c-gold-glow);
            border-color: var(--c-gold-glow);
        }
        
        .book-cover { width: 100%; aspect-ratio: 3/4; object-fit: cover; transition: transform .4s ease; }
        .book-card:hover .book-cover { transform: scale(1.04); }
        .book-cover-placeholder {
            width: 100%; aspect-ratio: 3/4;
            background: linear-gradient(135deg, var(--c-surface-2) 0%, var(--c-bg) 100%);
            display: flex; flex-direction: column; align-items: center; justify-content: center;
        }
        
        .badge-new {
            position: absolute; top: .75rem; left: .75rem;
            background: #16a34a; color: #fff;
            font-size: .6rem; font-weight: 700; letter-spacing: .1em; text-transform: uppercase;
            padding: .25rem .625rem; border-radius: 999px; z-index: 2;
            box-shadow: 0 2px 8px rgba(22,163,74,.4);
        }
        
        .badge-category {
            font-size: .65rem; font-weight: 600; letter-spacing: .1em; text-transform: uppercase;
            color: var(--c-gold); background: var(--c-gold-pale);
            padding: .2rem .625rem; border-radius: 999px;
        }
        
        .btn-read {
            display: inline-flex; align-items: center; justify-content: center; gap: .4rem;
            background: var(--c-gold-pale); border: 1px solid var(--c-gold-glow); color: var(--c-gold);
            font-size: .8rem; font-weight: 600; padding: .6rem 1rem; border-radius: .5rem;
            transition: all .2s; width: 100%;
        }
        .btn-read:hover { background: var(--c-gold); color: #0b1120; }
        
        /* Search Bar */
        .search-bar { background: var(--c-surface); border: 1px solid var(--c-border); border-radius: .75rem; }
        .search-bar:focus-within { border-color: var(--c-gold); box-shadow: 0 0 0 3px var(--c-gold-pale); }
        .search-input { background: transparent; color: var(--c-text-primary); outline: none; width: 100%; }
        .search-select { background: var(--c-surface); color: var(--c-text-secondary); border: none; border-left: 1px solid var(--c-border); outline: none; }
        .search-btn { background: var(--c-gold); color: #0b1120; font-weight: 600; border-radius: .5rem; transition: background .2s, transform .2s; }
        .search-btn:hover { background: var(--c-gold-light); transform: scale(1.02); }
        
        /* Quote Section */
        .quote-section {
            background: linear-gradient(135deg, var(--c-surface) 0%, var(--c-bg) 100%);
            border-top: 1px solid var(--c-border);
            border-bottom: 1px solid var(--c-border);
        }
    </style>
</head>
<body>

<?php include __DIR__ . '/includes/navbar.php'; ?>

<!-- Breadcrumbs -->
<div class="fixed top-20 left-0 w-full z-40 bg-[var(--c-bg)]/80 backdrop-blur-sm border-b border-[var(--c-border)] py-3 px-4 sm:px-6 lg:px-8">
    <div class="max-w-7xl mx-auto flex items-center text-xs font-medium text-[var(--c-text-secondary)] tracking-wider uppercase">
        <a href="index.php" class="hover:text-[var(--c-gold)] transition-colors">Home</a>
        <span class="mx-2">/</span>
        <span class="text-[var(--c-text-primary)]">Ellen G. White Writings</span>
    </div>
</div>

<!-- Hero Section -->
<section class="egw-hero text-center">
    <div class="max-w-3xl mx-auto px-4">
        <div class="inline-flex items-center gap-2 px-4 py-1.5 rounded-full bg-[var(--c-gold-pale)] border border-[var(--c-gold-glow)] text-[var(--c-gold-light)] text-xs font-semibold tracking-widest uppercase mb-6 shadow-sm">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/></svg>
            Inspired Counsel
        </div>
        <h1 class="font-display text-4xl sm:text-5xl lg:text-6xl font-bold text-white leading-tight mb-6">
            Ellen G. White <em class="text-[var(--c-gold)] italic">Writings</em>
        </h1>
        <p class="text-lg text-slate-100 leading-relaxed max-w-2xl mx-auto">
            Explore the prophetic insights, practical guidance, and spiritual wisdom given to the Seventh-day Adventist Church. A digital library of timeless truth.
        </p>
    </div>
</section>

<!-- Search Section -->
<section class="py-12 border-b border-[var(--c-border)]">
    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
        <form action="ellen_white.php" method="GET" role="search">
            <div class="search-bar flex flex-col sm:flex-row items-stretch sm:items-center gap-0 overflow-hidden shadow-lg">
                <div class="flex items-center px-4 text-gray-500 flex-shrink-0">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                </div>
                <input type="search" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search books by title, keyword, or topic…" class="search-input py-4 px-2" autocomplete="off">
                <select name="category" class="search-select py-4 px-4 sm:min-w-[180px]">
                    <option value="">All Topics</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= $cat['id'] ?>" <?= ($category == $cat['id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($cat['category_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="search-btn px-8 py-4 flex items-center justify-center gap-2 m-1.5 text-sm">
                    Search
                </button>
            </div>
        </form>
    </div>
</section>

<!-- Books Grid Section -->
<section class="py-16">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between items-end mb-10">
            <div>
                <h2 class="font-display text-3xl font-bold text-[var(--c-text-primary)]">The Library</h2>
                <p class="text-[var(--c-text-secondary)] mt-2 text-sm">Showing <?= count($books) ?> of <?= $total_books ?> writings.</p>
            </div>
        </div>

        <?php if (count($books) > 0): ?>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-8">
                <?php foreach ($books as $book): ?>
                    <article class="book-card flex flex-col relative group">
                        <?php if (isEGWNewBook($book['created_at'])): ?>
                            <span class="badge-new">New</span>
                        <?php endif; ?>
                        
                        <div class="relative overflow-hidden">
                            <?php if (!empty($book['cover_image']) && file_exists(__DIR__ . '/' . $book['cover_image'])): ?>
                                <img src="<?= htmlspecialchars($book['cover_image']) ?>" alt="<?= htmlspecialchars($book['title']) ?>" class="book-cover">
                            <?php else: ?>
                                <div class="book-cover-placeholder text-[var(--c-gold)] opacity-30">
                                    <svg class="w-16 h-16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/></svg>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="p-6 flex flex-col flex-grow">
                            <div class="mb-3">
                                <span class="badge-category"><?= htmlspecialchars($book['category_name'] ?? 'Uncategorized') ?></span>
                            </div>
                            <h3 class="font-display text-xl font-bold text-[var(--c-text-primary)] leading-tight mb-2 group-hover:text-[var(--c-gold)] transition-colors">
                                <?= htmlspecialchars($book['title']) ?>
                            </h3>
                            <p class="text-sm text-[var(--c-text-secondary)] line-clamp-3 mb-6 flex-grow">
                                <?= htmlspecialchars($book['description']) ?>
                            </p>
                            
                            <?php if (!empty($book['pdf_file'])): ?>
                                <a href="<?= htmlspecialchars($book['pdf_file']) ?>" target="_blank" class="btn-read mt-auto">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                                    Read PDF
                                </a>
                            <?php else: ?>
                                <button disabled class="btn-read opacity-50 cursor-not-allowed mt-auto" title="PDF unavailable">
                                    Not Available
                                </button>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
            
            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <div class="flex justify-center items-center gap-2 mt-16">
                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                        <a href="?q=<?= urlencode($search) ?>&category=<?= urlencode($category) ?>&page=<?= $i ?>" 
                           class="w-10 h-10 flex items-center justify-center rounded-lg text-sm font-medium transition-all duration-200 <?= ($page === $i) ? 'bg-[var(--c-gold)] text-[#0b1120] shadow-lg shadow-[var(--c-gold-glow)]' : 'bg-[var(--c-surface)] border border-[var(--c-border)] text-[var(--c-text-secondary)] hover:bg-[var(--c-surface-2)] hover:text-white' ?>">
                            <?= $i ?>
                        </a>
                    <?php endfor; ?>
                </div>
            <?php endif; ?>

        <?php else: ?>
            <div class="text-center py-20 bg-[var(--c-surface)] border border-[var(--c-border)] rounded-2xl">
                <svg class="w-16 h-16 text-[var(--c-border)] mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.172 16.172a4 4 0 015.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                <h3 class="font-display text-2xl font-bold text-[var(--c-text-primary)] mb-2">No Writings Found</h3>
                <p class="text-[var(--c-text-secondary)]">Try adjusting your search or selecting a different topic.</p>
                <?php if ($search || $category): ?>
                    <a href="ellen_white.php" class="inline-block mt-6 text-[var(--c-gold)] hover:text-[var(--c-gold-light)] font-medium text-sm underline underline-offset-4">Clear all filters</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</section>

<!-- Featured Quote -->
<section class="quote-section py-20">
    <div class="max-w-4xl mx-auto px-4 text-center">
        <svg class="w-10 h-10 text-[var(--c-gold)] opacity-30 mx-auto mb-6" fill="currentColor" viewBox="0 0 24 24"><path d="M14.017 21v-7.391c0-5.704 3.731-9.57 8.983-10.609l.995 2.151c-2.432.917-3.995 3.638-3.995 5.849h4v10h-9.983zm-14.017 0v-7.391c0-5.704 3.748-9.57 9-10.609l.996 2.151c-2.433.917-3.996 3.638-3.996 5.849h3.983v10h-9.983z"/></svg>
        <blockquote class="font-scripture text-3xl md:text-4xl font-medium text-navy leading-relaxed mb-8">
            "The greatest want of the world is the want of men—men who will not be bought or sold, men who in their inmost souls are true and honest, men who do not fear to call sin by its right name."
        </blockquote>
        <div class="text-[var(--c-gold)] font-bold tracking-widest uppercase text-sm">
             Ellen G. White, <span class="text-[var(--c-text-secondary)] font-medium">Education, p. 57</span>
        </div>
    </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>

</body>
</html>
"}}}"}}}