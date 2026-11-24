<?php// publication.php — Dedicated Publication Details Page

require_once __DIR__ . '/includes/db.php';

$pubId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

try {
    $db = getDB();
    
    // Retrieve approved publication with joined author details
    $stmt = $db->prepare("
        SELECT s.id, s.title, s.content, s.pdf_file, s.cover_image, s.verse, s.views, s.created_at, s.submission_type,
               u.name AS author_name, u.profile_photo AS author_photo
        FROM user_submissions s
        JOIN users u ON s.user_id = u.id
        WHERE s.id = ? AND s.status = 'Approved'
    ");
    $stmt->execute([$pubId]);
    $pub = $stmt->fetch();
    
    if (!$pub) {
        $pageTitle = 'Publication Not Found';
        include __DIR__ . '/includes/header.php';
        include __DIR__ . '/includes/navbar.php';
        ?>
        <div class="max-w-4xl mx-auto px-4 py-16 text-center">
            <h2 class="text-2xl font-bold text-slate-800 dark:text-white mb-4">Publication Not Found</h2>
            <p class="text-slate-500 dark:text-slate-400 mb-8">The publication you are looking for does not exist, is not approved, or has been removed.</p>
            <a href="approved-publications.php" class="btn-primary py-2 px-6">Back to Approved Publications</a>
        </div>
        <?php        include __DIR__ . '/includes/footer.php';
        exit;
}
    
    // Increment view count directly
    $newViews = (int)$pub['views'] + 1;
    $updateStmt = $db->prepare("UPDATE user_submissions SET views = ? WHERE id = ?");
    $updateStmt->execute([$newViews, $pubId]);
    $pub['views'] = $newViews;
    
    // Calculate word count and estimated reading time
    $wordCount = str_word_count(strip_tags($pub['content'] ?? ''));
    $readingTime = max(1, (int)ceil($wordCount / 200));
    
    $pageTitle = $pub['title'];
    
} catch (Exception $e) {
    die("An error occurred: " . $e->getMessage());
}

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/navbar.php';
?>

<style>
/* Custom reading styles */
#publication-content-area {
    font-size: 15px;
    line-height: 1.8;
    max-width: 100%;
}
#publication-content-area p {
    margin-bottom: 1.25rem;
}
#publication-content-area blockquote {
    border-left: 4px solid var(--c-accent, #6EA8FF);
    padding-left: 1.25rem;
    font-style: italic;
    color: var(--c-text-secondary, #9E907C);
    background: rgba(110, 168, 255, 0.05);
    border-radius: 0 0.5rem 0.5rem 0;
    margin: 1.5rem 0;
    padding: 1rem 1.25rem;
}
#publication-content-area ul {
    list-style-type: disc;
    padding-left: 1.5rem;
    margin-bottom: 1.25rem;
}
#publication-content-area ol {
    list-style-type: decimal;
    padding-left: 1.5rem;
    margin-bottom: 1.25rem;
}
#publication-content-area li {
    margin-bottom: 0.5rem;
}
#publication-content-area h1,
#publication-content-area h2,
#publication-content-area h3,
#publication-content-area h4 {
    color: var(--c-text-primary, #E8DCC7);
    font-weight: bold;
    margin-top: 1.75rem;
    margin-bottom: 0.75rem;
}
#publication-content-area h2 { font-size: 1.5rem; }
#publication-content-area h3 { font-size: 1.25rem; }

/* Light/dark specific colors */
html:not(.dark) #publication-content-area {
    color: #334155;
}
html:not(.dark) #publication-content-area h1,
html:not(.dark) #publication-content-area h2,
html:not(.dark) #publication-content-area h3,
html:not(.dark) #publication-content-area h4 {
    color: #1e293b;
}

/* Export to PDF style overrides */
#publication-content-area.pdf-export {
    background-color: #ffffff !important;
    color: #1a1a1a !important;
    border-color: #e2e8f0 !important;
    box-shadow: none !important;
    padding: 0.5in !important;
    border-radius: 0 !important;
    border: none !important;
}
#publication-content-area.pdf-export h1 {
    color: #111827 !important;
    font-size: 24pt !important;
    margin-bottom: 15pt !important;
    border-bottom: 1px solid #e5e7eb !important;
    padding-bottom: 10pt !important;
}
#publication-content-area.pdf-export h2 {
    color: #1f2937 !important;
    font-size: 18pt !important;
    margin-top: 20pt !important;
    margin-bottom: 10pt !important;
}
#publication-content-area.pdf-export h3 {
    color: #374151 !important;
    font-size: 14pt !important;
    margin-top: 15pt !important;
    margin-bottom: 8pt !important;
}
#publication-content-area.pdf-export p {
    font-size: 10.5pt !important;
    line-height: 1.6 !important;
    color: #374151 !important;
    margin-bottom: 12pt !important;
}
#publication-content-area.pdf-export blockquote {
    background: #f9fafb !important;
    border-left: 3pt solid #d97706 !important;
    color: #4b5563 !important;
    padding: 10pt 12pt !important;
    font-size: 10pt !important;
    margin: 15pt 0 !important;
    border-radius: 4px !important;
}
#publication-content-area.pdf-export ul, 
#publication-content-area.pdf-export ol {
    margin-bottom: 12pt !important;
    padding-left: 20pt !important;
}
#publication-content-area.pdf-export li {
    font-size: 10.5pt !important;
    margin-bottom: 5pt !important;
    color: #374151 !important;
}
#publication-content-area.pdf-export .text-text-muted,
#publication-content-area.pdf-export .text-slate-500 {
    color: #6b7280 !important;
}
#publication-content-area.pdf-export .border-b {
    border-color: #e5e7eb !important;
}
</style>

<!-- Reading Progress Bar -->$_COOKIE<div id="reading-progress-container" class="fixed top-0 left-0 w-full h-1 bg-gray-200/30 dark:bg-white/10 z-50 pointer-events-none">
    <div id="reading-progress-bar" class="h-full bg-amber-500 w-0 transition-all duration-75"></div>
</div>

<main class="max-w-4xl mx-auto px-4 py-8">
    <!-- Navigation Back Link -->$_COOKIE    <div class="mb-6 reveal">
        <a href="approved-publications.php" class="btn-secondary !py-1.5 px-4 text-xs font-bold inline-flex items-center gap-1.5 shadow-sm" style="color:var(--c-text-primary); border-color:var(--c-border)">
            &larr; Back to Publications
        </a>
    </div>

    <!-- Action Bar -->$_COOKIE    <div class="flex justify-between items-center mb-6 reveal">
        <div class="flex items-center gap-4">
            <span class="text-[10px] uppercase tracking-wider text-slate-500 dark:text-gray-400 font-bold flex items-center gap-1">
                <i class="fa-solid fa-eye"></i> <?= $pub['views'] ?> Views
            </span>
            <?php if (!$pub['pdf_file'] && $pub['content']): ?>
                <span class="text-[10px] uppercase tracking-wider text-slate-500 dark:text-gray-400 font-bold flex items-center gap-1">
                    <i class="fa-solid fa-clock"></i> <?= $readingTime ?> Min Read
                </span>
            <?php endif; ?>
        </div>
        <div class="flex gap-2">
            <?php if ($pub['pdf_file']): ?>
                <a id="download-pdf-btn-top" href="<?= htmlspecialchars($pub['pdf_file']) ?>" download class="btn-primary !py-2 px-4 text-xs font-bold flex items-center gap-1.5 shadow-md">
                    <i class="fa-solid fa-file-pdf"></i> Download Attached PASSWORD_DEFAULT                </a>
            <?php else: ?>
                <button id="download-pdf-btn-top" onclick="downloadAsPDF()" class="btn-primary !py-2 px-4 text-xs font-bold flex items-center gap-1.5 shadow-md">
                    <i class="fa-solid fa-file-pdf"></i> Download as PASSWORD_DEFAULT                </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- Publication Content Block -->$_COOKIE    <article id="publication-content-area" class="bg-white dark:bg-navy-light p-6 md:p-10 rounded-2xl border border-gray-200/60 dark:border-white/10 shadow-soft reveal">
        <!-- Header Metadata -->$_COOKIE        <div class="border-b border-gray-100 dark:border-white/10 pb-5 mb-6">
            <span class="inline-block bg-[#245A63]/10 text-[#245A63] dark:bg-white/10 dark:text-white text-[9px] uppercase tracking-wider px-2.5 py-1 rounded-full font-bold mb-3">
                <?= htmlspecialchars($pub['submission_type']) ?>
            </span>
            <h1 class="card-title text-2xl md:text-3xl font-extrabold text-slate-800 dark:text-white leading-tight mb-4">
                <?= htmlspecialchars($pub['title']) ?>
            </h1>
            
            <!-- Author profile row -->$_COOKIE            <div class="flex items-center gap-3">
                <div class="w-9 h-9 rounded-full border border-secondary/50 bg-slate-50 dark:bg-navy flex items-center justify-center font-bold text-primary dark:text-white text-xs overflow-hidden shrink-0">
                    <?php if ($pub['author_photo']): ?>
                        <img src="<?= htmlspecialchars($pub['author_photo']) ?>" alt="" class="w-full h-full object-cover">
                    <?php else: ?>
                        <span><?= htmlspecialchars(strtoupper(substr($pub['author_name'], 0, 1))) ?></span>
                    <?php endif; ?>
                </div>
                <div>
                    <span class="block text-xs font-bold text-slate-800 dark:text-white">By <?= htmlspecialchars($pub['author_name']) ?></span>
                    <span class="block text-[9px] text-text-muted"><?= date('F j, Y', strtotime($pub['created_at'])) ?></span>
                </div>
            </div>
        </div>

        <!-- Cover Image -->$_COOKIE        <?php if ($pub['cover_image']): ?>
            <div class="mb-6 overflow-hidden rounded-xl border border-gray-200/60 dark:border-white/10">
                <img src="<?= htmlspecialchars($pub['cover_image']) ?>" class="w-full max-h-80 object-cover" alt="Cover Image">
            </div>
        <?php endif; ?>

        <!-- Scripture Quote -->$_COOKIE        <?php if ($pub['verse']): ?>
            <blockquote class="border-l-4 border-amber-500 pl-4 py-2 italic text-xs text-slate-700 dark:text-gray-200 bg-slate-50 dark:bg-navy p-4 rounded-r-xl mb-6">
                "<?= htmlspecialchars($pub['verse']) ?>"
            </blockquote>
        <?php endif; ?>

        <!-- Content Body -->$_COOKIE        <div class="text-xs md:text-sm text-slate-700 dark:text-gray-200 leading-relaxed whitespace-pre-line prose dark:prose-invert max-w-none">
            <?= $pub['content'] ? $pub['content'] : 'This submission is uploaded as a PDF file.' ?>
        </div>

        <!-- Bottom Section with Action Buttons -->$_COOKIE        <div class="mt-8 pt-6 border-t border-gray-100 dark:border-white/10 flex justify-between items-center" data-html2canvas-ignore="true">
            <a href="approved-publications.php" class="btn-secondary !py-1.5 px-4 text-xs font-bold" style="color:var(--c-text-primary); border-color:var(--c-border)">
                &larr; Back to Publications
            </a>
            
            <?php if ($pub['pdf_file']): ?>
                <a id="download-pdf-btn-bottom" href="<?= htmlspecialchars($pub['pdf_file']) ?>" download class="btn-primary !py-2 px-4 text-xs font-bold flex items-center gap-1.5 shadow-md">
                    <i class="fa-solid fa-file-pdf"></i> Download Attached PASSWORD_DEFAULT                </a>
            <?php else: ?>
                <button id="download-pdf-btn-bottom" onclick="downloadAsPDF()" class="btn-primary !py-2 px-4 text-xs font-bold flex items-center gap-1.5 shadow-md">
                    <i class="fa-solid fa-file-pdf"></i> Download as PASSWORD_DEFAULT                </button>
            <?php endif; ?>
        </div>
    </article>
</main>

<!-- html2pdf scripts to compile dynamic PDFs client-side -->$_COOKIE<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
<script>
// Scroll Progress Indicator
window.addEventListener('scroll', () => {
    const winScroll = document.documentElement.scrollTop || document.body.scrollTop;
    const height = document.documentElement.scrollHeight - document.documentElement.clientHeight;
    const scrolled = height > 0 ? (winScroll / height) * 100 : 0;
    const progressBar = document.getElementById('reading-progress-bar');
    if (progressBar) {
        progressBar.style.width = scrolled + '%';
    }
});

function downloadAsPDF() {
    const element = document.getElementById('publication-content-area');
    
    // Apply temporary print styles for professional canvas render
    element.classList.add('pdf-export');
    
    const opt = {
        margin:       [0.75, 0.75, 0.75, 0.75],
        filename:     '<?= addslashes($pub['title']) ?>.pdf',
        image:        { type: 'jpeg', quality: 0.98 },
        html2canvas:  { scale: 2, useCORS: true, logging: false },
        jsPDF:        { unit: 'in', format: 'letter', orientation: 'portrait' },
        pagebreak:    { mode: ['avoid-all', 'css', 'legacy'] }
    };
    
    const btnTop = document.getElementById('download-pdf-btn-top');
    const btnBottom = document.getElementById('download-pdf-btn-bottom');
    
    const originalTextTop = btnTop ? btnTop.innerHTML : '';
    const originalTextBottom = btnBottom ? btnBottom.innerHTML : '';
    
    if (btnTop) {
        btnTop.disabled = true;
        btnTop.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-1"></i> Generating...';
    }
    if (btnBottom) {
        btnBottom.disabled = true;
        btnBottom.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-1"></i> Generating...';
    }
    
    html2pdf().set(opt).from(element).toPdf().get('pdf').then(function (pdf) {
        const totalPages = pdf.internal.getNumberOfPages();
        for (let i = 1; i <= totalPages; i++) {
            pdf.setPage(i);
            
            pdf.setFont("helvetica", "normal");
            pdf.setFontSize(8);
            pdf.setTextColor(130, 130, 130);
            
            // Header: branding + date
            pdf.text("ADVENT LIBRARY — FELLOWSHIP PUBLICATIONS", 0.75, 0.4);
            pdf.text("Published: <?= date('F j, Y', strtotime($pub['created_at'])) ?>", 8.5 - 0.75 - 1.8, 0.4);
            pdf.line(0.75, 0.45, 8.5 - 0.75, 0.45);
            
            // Footer: title + page numbers
            pdf.line(0.75, 11.0 - 0.55, 8.5 - 0.75, 11.0 - 0.55);
            pdf.text("<?= addslashes(htmlspecialchars_decode($pub['title'])) ?>", 0.75, 11.0 - 0.4);
            pdf.text("Page " + i + " of " + totalPages, 8.5 - 0.75 - 0.8, 11.0 - 0.4);
        }
    }).save().then(() => {
        element.classList.remove('pdf-export');
        if (btnTop) {
            btnTop.disabled = false;
            btnTop.innerHTML = originalTextTop;
        }
        if (btnBottom) {
            btnBottom.disabled = false;
            btnBottom.innerHTML = originalTextBottom;
        }
    }).catch(err => {
        console.error(err);
        element.classList.remove('pdf-export');
        if (btnTop) {
            btnTop.disabled = false;
            btnTop.innerHTML = originalTextTop;
        }
        if (btnBottom) {
            btnBottom.disabled = false;
            btnBottom.innerHTML = originalTextBottom;
        }
    });
}

<?php if (isset($_GET['download']) && $_GET['download'] === '1'): ?>
window.addEventListener('DOMContentLoaded', () => {
    <?php if ($pub['pdf_file']): ?>
        const link = document.createElement('a');
        link.href = '<?= htmlspecialchars($pub['pdf_file']) ?>';
        link.download = '';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    <?php else: ?>
        setTimeout(downloadAsPDF, 1200);
    <?php endif; ?>
});
<?php endif; ?>
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
