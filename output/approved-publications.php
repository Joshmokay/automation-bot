<?php// approved-publications.php — List of Approved Publications
declare(strict_types=1);
require_once __DIR__ . '/includes/db.php';

$pageTitle       = 'Approved Publications — Advent Library';
$pageDescription = 'Officially approved books, articles, and publications available in Advent Library.';

$db = getDB();

// Get filter inputs
$search   = trim($_GET['q'] ?? '');
$authorId = (int)($_GET['author'] ?? 0);
$type     = trim($_GET['type'] ?? 'all');

$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = 8; // publications per page
$offset = ($page - 1) * $limit;

// Build query
$where = ["s.status = 'Approved'"];
$params = [];

if ($search !== '') {
    $where[] = "(s.title LIKE ? OR s.content LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($authorId > 0) {
    $where[] = "s.user_id = ?";
    $params[] = $authorId;
}

if ($type !== 'all' && $type !== '') {
    $where[] = "s.submission_type = ?";
    $params[] = $type;
}

$whereClause = implode(" AND ", $where);

// Get total count
$countStmt = $db->prepare("SELECT COUNT(*) FROM user_submissions s JOIN users u ON s.user_id = u.id WHERE $whereClause");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($total / $limit));

// Get publications
$queryStr = "
    SELECT s.id, s.title, s.submission_type, s.created_at, s.content, s.pdf_file,
           u.id as author_id, u.name as author_name, NULL as author_photo
    FROM user_submissions s
    JOIN users u ON s.user_id = u.id
    WHERE $whereClause
    ORDER BY s.title ASC
    LIMIT $limit OFFSET $offset
";

$stmt = $db->prepare($queryStr);
$stmt->execute($params);
$publications = $stmt->fetchAll();

// Get unique authors for filter dropdown
$authorsStmt = $db->query("
    SELECT DISTINCT u.id, u.name
    FROM user_submissions s
    JOIN users u ON s.user_id = u.id
    WHERE s.status = 'Approved'
    ORDER BY u.name ASC
");
$authors = $authorsStmt->fetchAll();

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/navbar.php';
?>

<style>
    :root {
        --navy:   var(--c-primary);
        --gold:   var(--c-accent);
        --gold-l: var(--c-accent-light);
        --bg:     var(--c-bglight);
        --border: var(--c-border-sepia);
    }
    
    .pub-list-row {
        background-color: var(--c-bg-card);
        border: 1px solid var(--border);
        transition: all 0.3s ease;
    }
    .pub-list-row:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 25px -4px rgba(46,31,20,0.06);
        border-color: var(--gold);
    }
</style>

<div class="max-w-4xl mx-auto px-4 py-8 min-h-[70vh]">
    <!-- Header Section -->$_COOKIE    <div class="mb-8 text-center sm:text-left">
        <span class="section-eyebrow">Fellowship Voices</span>
        <h1 class="font-heading text-3xl font-bold text-primary mt-1 mb-2">Approved Publications</h1>
        <p class="text-sm text-secondary max-w-xl">Officially approved books, articles, and publications available in Advent Library.</p>
    </div>

    <!-- Search & Filter Controls -->$_COOKIE    <form method="GET" action="approved-publications.php" class="bg-white dark:bg-navy-light p-4 rounded-xl border border-border-sepia dark:border-white/10 shadow-soft mb-8 flex flex-col sm:flex-row gap-3 items-end">
        <div class="flex-grow w-full">
            <label for="q" class="block text-[10px] font-bold uppercase tracking-wider text-secondary mb-1.5">Search Title</label>
            <div class="relative">
                <input type="text" name="q" id="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search by title..." class="input-field !py-2 pl-9 text-xs">
                <span class="absolute left-3 top-1/2 -translate-y-1/2 text-secondary/70">
                    <svg class="icon" aria-hidden="true"><use href="#icon-search"/></svg>
                </span>
            </div>
        </div>
        <div class="w-full sm:w-44 shrink-0">
            <label for="author" class="block text-[10px] font-bold uppercase tracking-wider text-secondary mb-1.5">Author</label>
            <select name="author" id="author" class="input-field !py-2 text-xs">
                <option value="0">All Authors</option>
                <?php foreach ($authors as $auth): ?>
                    <option value="<?= $auth['id'] ?>" <?= $authorId === (int)$auth['id'] ? 'selected' : '' ?>><?= htmlspecialchars($auth['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="w-full sm:w-44 shrink-0">
            <label for="type" class="block text-[10px] font-bold uppercase tracking-wider text-secondary mb-1.5">Type</label>
            <select name="type" id="type" class="input-field !py-2 text-xs">
                <option value="all">All Types</option>
                <option value="Devotional" <?= $type === 'Devotional' ? 'selected' : '' ?>>Devotions</option>
                <option value="Testimony" <?= $type === 'Testimony' ? 'selected' : '' ?>>Testimonies</option>
                <option value="Article" <?= $type === 'Article' ? 'selected' : '' ?>>Articles</option>
                <option value="Music" <?= $type === 'Music' ? 'selected' : '' ?>>Music</option>
                <option value="Health" <?= $type === 'Health' ? 'selected' : '' ?>>Health</option>
            </select>
        </div>
        <div class="flex gap-2 w-full sm:w-auto shrink-0">
            <button type="submit" class="btn-primary w-full sm:w-auto !py-2 px-5 text-xs">Filter</button>
            <?php if ($search !== '' || $authorId > 0 || $type !== 'all'): ?>
                <a href="approved-publications.php" class="btn-outline w-full sm:w-auto !py-2 px-4 text-center text-xs flex items-center justify-center">Reset</a>
            <?php endif; ?>
        </div>
    </form>

    <!-- Publication List Layout -->$_COOKIE    <?php if (!empty($publications)): ?>
        <div class="space-y-4" role="list">
            <?php foreach ($publications as $pub): 
                $snippet = strip_tags($pub['content'] ?? '');
                if (strlen($snippet) > 200) {
                    $snippet = substr($snippet, 0, 200) . '...';
                }
                $pubData = [
                    'id' => $pub['id'],
                    'title' => $pub['title'],
                    'submission_type' => $pub['submission_type'],
                    'created_at' => date('M j, Y', strtotime($pub['created_at'])),
                    'content' => $pub['content'],
                    'pdf_file' => $pub['pdf_file'],
                    'cover_image' => '',
                    'verse' => '',
                    'views' => 0,
                    'author_name' => $pub['author_name'],
                    'author_photo' => $pub['author_photo']
                ];
            ?>
                <article class="pub-list-row dark:bg-navy-light p-5 rounded-xl flex flex-col md:flex-row justify-between items-start md:items-center gap-4 hover:scale-[1.005]">
                    <div class="flex-grow min-w-0">
                        <div class="flex items-center gap-2 mb-1.5">
                            <span class="badge badge-blue !py-0.5 !px-2 !text-[8px]"><?= htmlspecialchars($pub['submission_type']) ?></span>
                            <span class="text-[9px] text-secondary font-mono"><?= date('M j, Y', strtotime($pub['created_at'])) ?></span>
                        </div>
                        <h3 class="font-heading text-base font-bold text-primary hover:text-accent transition-colors mb-1 truncate" title="<?= htmlspecialchars($pub['title']) ?>">
                            <?= htmlspecialchars($pub['title']) ?>
                        </h3>
                        <p class="text-[11px] text-secondary mb-2">by <span class="font-semibold text-primary dark:text-white"><?= htmlspecialchars($pub['author_name']) ?></span></p>
                        <?php if ($snippet): ?>
                            <p class="text-xs text-secondary leading-relaxed line-clamp-2"><?= htmlspecialchars($snippet) ?></p>
                        <?php endif; ?>
                    </div>
                    <div class="shrink-0 w-full md:w-auto flex items-center justify-end gap-2">
                        <?php if ($pub['pdf_file']): ?>
                            <a href="<?= htmlspecialchars($pub['pdf_file']) ?>" download class="p-2 bg-slate-100 dark:bg-navy hover:bg-gold dark:hover:bg-gold hover:text-white text-slate-500 rounded transition-colors" title="Download PDF">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                            </a>
                        <?php else: ?>
                            <a href="publication.php?id=<?= $pub['id'] ?>&download=1" class="p-2 bg-slate-100 dark:bg-navy hover:bg-gold dark:hover:bg-gold hover:text-white text-slate-500 rounded transition-colors" title="Download PDF">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                            </a>
                        <?php endif; ?>
                        
                        <a href="publication.php?id=<?= $pub['id'] ?>" class="btn-primary w-full md:w-auto !py-2 px-5 text-xs font-semibold text-center">
                            View Publication
                        </a>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>

        <!-- Pagination -->$_COOKIE        <?php if ($totalPages > 1): ?>
            <div class="flex justify-center items-center gap-3 mt-8">
                <?php if ($page > 1): ?>
                    <a href="?page=<?= $page - 1 ?>&q=<?= urlencode($search) ?>&author=<?= $authorId ?>&type=<?= urlencode($type) ?>" class="btn-outline !py-1.5 px-3 text-xs">&larr; Previous</a>
                <?php endif; ?>
                
                <span class="text-xs font-semibold text-secondary">Page <?= $page ?> of <?= $totalPages ?></span>
                
                <?php if ($page < $totalPages): ?>
                    <a href="?page=<?= $page + 1 ?>&q=<?= urlencode($search) ?>&author=<?= $authorId ?>&type=<?= urlencode($type) ?>" class="btn-outline !py-1.5 px-3 text-xs">Next &rarr;</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>

    <?php else: ?>
        <!-- Empty State -->$_COOKIE        <div class="text-center py-16 bg-white dark:bg-navy-light border border-border-sepia dark:border-white/10 rounded-xl">
            <span class="text-4xl mb-3 block">📖</span>
            <h3 class="font-heading text-base font-bold text-primary mb-1">No Publications Found</h3>
            <p class="text-xs text-secondary max-w-xs mx-auto">Approved publications will appear here once they become available.</p>
        </div>
    <?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
]")"}