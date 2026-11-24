<?phprequire_once __DIR__ . '/includes/db.php';
$db = getDB();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$type = $_GET['type'] ?? 'written'; // written or video

if (!$id) {
    header("Location: sermons.php");
    exit;
}

if ($type === 'video') {
    $stmt = $db->prepare("SELECT s.*, c.name as cat_name FROM video_sermons s LEFT JOIN sermon_categories c ON s.category_id = c.id WHERE s.id = ?");
} else {
    $stmt = $db->prepare("SELECT s.*, c.name as cat_name FROM written_sermons s LEFT JOIN sermon_categories c ON s.category_id = c.id WHERE s.id = ?");
}

$stmt->execute([$id]);
$sermon = $stmt->fetch();

if (!$sermon) {
    die("Sermon not found.");
}

// Update views
if ($type === 'written') {
    if (isset($sermon['views'])) {
        $db->prepare("UPDATE written_sermons SET views = views + 1 WHERE id = ?")->execute([$id]);
}
} else {
    $db->prepare("UPDATE video_sermons SET views = views + 1 WHERE id = ?")->execute([$id]);
}

$pageTitle = $sermon['title'] . ' - Advent Library';
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/navbar.php';
?>
<style type="text/tailwindcss">
    :root {
        --c-bg: var(--c-bglight);
        --c-surface: var(--c-bg-card);
        --c-border: var(--c-border-sepia);
        --c-gold: var(--c-accent);
        --c-gold-pale: rgba(194, 94, 69, 0.15);
        --c-text-primary: var(--c-primary);
        --c-text-secondary: var(--c-secondary);
        --c-text-muted: var(--c-secondary-light);
    }
    .dark {
        --c-gold-pale: rgba(110, 168, 255, 0.15);
    }
</style>

<div class="pt-32 pb-16 min-h-screen" style="background: var(--c-bg); color: var(--c-text-primary);">
    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
        
        <!-- Breadcrumbs -->$_COOKIE        <div class="mb-8 text-sm font-medium tracking-widest uppercase" style="color: var(--c-text-muted);">
            <a href="index.php" class="hover:text-gold transition-colors" style="color: var(--c-text-muted);">Home</a>
            <span class="mx-2">/</span>
            <a href="sermons.php" class="hover:text-gold transition-colors" style="color: var(--c-text-muted);">Sermons</a>
            <span class="mx-2">/</span>
            <span style="color: var(--c-text-primary);"><?= htmlspecialchars($sermon['title']) ?></span>
        </div>

        <?php if ($type === 'video'): ?>
            <div class="mb-8 bg-black rounded-xl overflow-hidden shadow-lg relative w-full" style="padding-bottom: 56.25%;">
                <iframe src="https://www.youtube.com/embed/<?= htmlspecialchars($sermon['youtube_id']) ?>?autoplay=0" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen class="absolute top-0 left-0 w-full h-full"></iframe>
            </div>
        <?php elseif (!empty($sermon['cover_image'])): ?>
            <div class="mb-8 rounded-xl overflow-hidden shadow-lg w-full h-64 sm:h-96">
                <img src="<?= htmlspecialchars($sermon['cover_image']) ?>" class="w-full h-full object-cover">
            </div>
        <?php endif; ?>

        <div class="rounded-2xl shadow-sm border p-8 sm:p-12" style="background: var(--c-surface); border-color: var(--c-border);">
            <div class="mb-6 flex flex-wrap justify-between items-start gap-4 border-b pb-6" style="border-color: var(--c-border);">
                <div>
                    <span class="inline-block px-3 py-1 text-xs font-bold uppercase tracking-wider rounded-full mb-3" style="background: var(--c-gold-pale); color: var(--c-gold);">
                        <?= htmlspecialchars($sermon['cat_name'] ?? 'Uncategorized') ?>
                    </span>
                    <h1 class="text-3xl sm:text-4xl font-bold font-heading mb-2" style="font-family: 'Playfair Display', serif; color: var(--c-text-primary);"><?= htmlspecialchars($sermon['title']) ?></h1>
                    <p class="text-lg flex items-center gap-2" style="color: var(--c-text-secondary);">
                        <i class="fa-solid fa-microphone text-sm"></i> <?= htmlspecialchars($sermon['preacher']) ?>
                    </p>
                </div>
                <div class="text-right text-sm" style="color: var(--c-text-muted);">
                    <div><i class="fa-solid fa-calendar mr-1"></i> <?= date('F d, Y', strtotime($sermon['created_at'])) ?></div>
                    <?php if (isset($sermon['views'])): ?>
                    <div class="mt-1"><i class="fa-solid fa-eye mr-1"></i> <?= number_format($sermon['views']) ?> views</div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="prose prose-lg max-w-none leading-relaxed" style="color: var(--c-text-secondary);">
                <?php if ($type === 'video'): ?>
                    <p><em>This is a video sermon. Please watch the embedded video above.</em></p>
                <?php else: ?>
                    <?php if (!empty($sermon['description'])): ?>
                        <p class="text-xl font-medium mb-6 italic" style="color: var(--c-text-primary); opacity: 0.9;"><?= htmlspecialchars($sermon['description']) ?></p>
                    <?php endif; ?>
                    <?= nl2br(htmlspecialchars($sermon['content'] ?? '')) ?>
                <?php endif; ?>
            </div>
        </div>

    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
}}