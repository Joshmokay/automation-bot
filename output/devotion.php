<?php
// ddaily' or 'book'

if (!$id) {
    header("Location: devotionals.php");
    exit;
}

$devotional = null;
$related = [];

if ($type === 'daily') {
    $stmt = $db->prepare("SELECT d.*, c.name as category_name FROM daily_devotionals d LEFT JOIN devotional_categories c ON d.category_id = c.id WHERE d.id = ?");
    $stmt->execute([$id]);
    $devotional = $stmt->fetch();
    
    if ($devotional) {
        $stmtRelated = $db->prepare("SELECT id, title, author FROM daily_devotionals WHERE category_id = ? AND id != ? ORDER BY devotion_date DESC LIMIT 3");
        $stmtRelated->execute([$devotional['category_id'], $id]);
        $related = $stmtRelated->fetchAll();
}
} else {
    $stmt = $db->prepare("SELECT b.*, c.name as category_name FROM devotional_books b LEFT JOIN devotional_categories c ON b.category_id = c.id WHERE b.id = ?");
    $stmt->execute([$id]);
    $devotional = $stmt->fetch();
    
    if ($devotional) {
        $stmtRelated = $db->prepare("SELECT id, title, author, cover_image FROM devotional_books WHERE category_id = ? AND id != ? ORDER BY created_at DESC LIMIT 3");
        $stmtRelated->execute([$devotional['category_id'], $id]);
        $related = $stmtRelated->fetchAll();
}
}

if (!$devotional) {
    header("Location: devotionals.php");
    exit;
}

<?php
$pageTitle = $devotional['title' . " - Advent Library";
include __DIR__ . '/includes/header.php';
?>

<?php include __DIR__ . '/includes/navbar.php'; ?>

<!-- Reading Settings Bar -->
<div class="fixed bottom-6 left-1/2 -translate-x-1/2 z-40 bg-white border border-slate-200 shadow-2xl rounded-full px-6 py-3 flex items-center gap-6 transition-colors duration-300">
    <div class="flex items-center gap-4">
        <button onclick="changeFontSize(-1)" class="text-slate-600 hover:text-gold transition-colors font-serif text-sm font-bold" title="Decrease Font Size">A-</button>
        <span class="text-[10px] font-medium uppercase tracking-widest text-slate-400">Size</span>
        <button onclick="changeFontSize(1)" class="text-slate-600 hover:text-gold transition-colors font-serif text-lg font-bold" title="Increase Font Size">A+</button>
    </div>
</div>

<!-- Main Reading Content -->
<article id="reading-container" class="min-h-screen pt-32 pb-32 bg-soft-white">
    <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
        
        <!-- Header -->
        <header class="text-center mb-12 reveal">
            <span class="text-gold text-xs font-bold uppercase tracking-widest">
                <?= htmlspecialchars($devotional['category_name'] ?? 'Devotional') ?>
            </span>
            <h1 class="text-4xl md:text-5xl font-display font-bold text-navy mt-4 mb-6 leading-tight">
                <?= htmlspecialchars($devotional['titlclass="flex items-center justify-center gap-4 text-sm font-medium text-slate-500">
                <span>By <?= htmlspecialchars($devotional['author'] ?? 'Unknown Author') ?></span>
                <?php if ($type === 'daily' && !empty($devotional['devotion_date'])): ?>
                    <span class="w-1.5 h-1.5 rounded-full bg-gold"></span>
                    <span><?= date('F j, Y', strtotime($devotional['devotion_date'])) ?></span>
                <?php endif; ?>
            </div>
            
            <!-- Social Share -->
            <div class="flex items-center justify-center gap-3 mt-8">
                <a href="#" class="w-10 h-10 rounded-full border border-slate-300 flex items-center justify-center text-slate-500 hover:border-gold hover:text-gold transition-colors"><svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M24 4.557c-.883.392-1.832.656-2.828.775 1.017-.609 1.798-1.574 2.165-2.724-.951.564-2.005.974-3.127 1.195-.897-.957-2.178-1.555-3.594-1.555-3.179 0-5.515 2.966-4.797 6.045-4.091-.205-7.719-2.165-10.148-5.144-1.29 2.213-.669 5.108 1.523 6.574-.806-.026-1.566-.247-2.229-.616-.054 2.281 1.581 4.415 3.949 4.89-.693.188-1.452.232-2.224.084.626 1.956 2.444 3.379 4.6 3.419-2.07 1.623-4.678 2.348-7.29 2.04 2.179 1.397 4.768 2.212 7.548 2.212 9.142 0 14.307-7.721 13.995-14.646.962-.695 1.797-1.562 2.457-2.549z"/></svg></a>
                <a href="#" class="w-10 h-10 rounded-full border border-slate-300 flex items-center justify-center text-slate-500 hover:border-gold hover:text-gold transition-colors"><svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M9 8h-3v4h3v12h5v-12h3.642l.358-4h-4v-1.667c0-.955.192-1.333 1.115-1.333h2.885v-5h-3.808c-3.596 0-5.192 1.583-5.192 4.615v3.385z"/></svg></a>
            </div>
        </header>

        <!-- Cover Image -->
        <?php if ($type === 'book' && !empty($devotional['cover_image'])): ?>
            <div class="mb-12 reveal">
                <img src="<?= htmlspecialchars($devotional['cover_image']) ?>" class="w-full max-h-[500px] object-cover rounded-2xl shadow-xl" alt="Cover">
            </div>
        <?php endif; ?>

        <!-- Bible Verse Highlight -->
        <?php if (!empty($devotional['verse'])): ?>
            <div class="reveal relative p-8 md:p-10 mb-12 bg-white border border-gold/20 rounded-2xl shadow-sm">
                <svg class="absolute top-6 left-6 w-8 h-8 text-gold/10" fill="currentColor" viewBox="0 0 24 24"><path d="M14.017 21v-7.391c0-5.704 3.731-9.57 8.983-10.609l.995 2.151c-2.432.917-3.995 3.638-3.995 5.849h4v10h-9.983zm-14.017 0v-7.391c0-5.704 3.748-9.57 9-10.609l.996 2.151c-2.433.917-3.996 3.638-3.996 5.849h3.983v10h-9.983z"/></svg>
                <blockquote class="relative z-10 text-xl md:text-2xl font-scripture italic text-slate-700 leading-relaxed text-center">
                    "<?= htmlspecialchars($devotional['verse']) ?>"
                </blockquote>
            </div>
        <?php endif; ?>

        <!-- Main Content Text -->
        <div id="reading-text" class="prose prose-lg max-w-none text-slate-800 font-serif leading-loose reveal text-lg">
            <?= nl2br(htmlspecialchars($devotional['content'])) ?>
        </div>

        <?php if ($type === 'book' && !empty($devotional['pdf_file'])): ?>
            <div class="mt-16 text-center reveal">
                <a href="<?= htmlspecialchars($devotional['pdf_file']) ?>" download class="inline-flex items-center gap-2 px-8 py-4 btn-primary rounded-xl shadow-lg hover:-translate-y-1">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                    Download PDF Version
                </a>
            </div>
        <?php endif; ?>

        <hr class="my-16 border-slate-200 reveal">

        <!-- Related Devotionals -->
        <?php if (!empty($related)): ?>
            <div class="reveal">
                <h3 class="text-2xl font-display font-bold text-navy mb-8">More from this Category</h3>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <?php foreach ($related as $rel): ?>
                        <a href="devotion.php?id=<?= $rel['id'] ?>&type=<?= $type ?>" class="group block card p-5 hover:border-gold transition-colors">
                            <h4 class="font-bold text-slate-900 mb-2 group-hover:text-gold transition-colors leading-tight"><?= htmlspecialchars($rel['title']) ?></h4>
                            <p class="text-sm text-slate-500">By <?= htmlspecialchars($rel['author'] ?? 'Unknown') ?></p>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

    </div>
</article>

<script>
    // Font Size Control
    let currentSize = parseInt(localStorage.getItem('readingFontSize')) || 18;
    const readingText = document.getElementById('reading-text');
    
    function updateFontSize() {
        readingText.style.fontSize = currentSize + 'px';
        localStorage.setItem('readingFontSize', currentSize);
    }
    
    function changeFontSize(step) {
        if (step > 0 && currentSize < 32) currentSize += 2;
        if (step < 0 && currentSize > 14) currentSize -= 2;
        updateFontSize();
    }
    
    // Apply initial font size
    updateFontSize();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
