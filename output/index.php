<?php
// index.php — Advent Library Homepage
declare(strict_types=1);

require_once __DIR__ . '/includes/db.php';

// ── Data fetching helpers ──────────────────────────────────────────────────

function fetchLatestBooks(int $limit = 8): array {
    try {
        $db = getDB();
        $stmt = $db->prepare(
            'SELECT id, title, author, category, description, cover_image, created_at
             FROM books
             ORDER BY created_at DESC
             LIMIT :limit'
        );
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        error_log($e->getMessage());
        return [];
}
}

function fetchTodaysDevotion(): ?array {
    try {
        $db = getDB();
        $stmt = $db->prepare(
            'SELECT d.id, d.title, d.verse, d.content, d.author, c.name as category_name
             FROM daily_devotionals d
             LEFT JOIN devotional_categories c ON d.category_id = c.id
             WHERE d.devotion_date <= CURDATE()
             ORDER BY d.devotion_date DESC
             LIMIT 1'
        );
        $stmt->execute();
        return $stmt->fetch() ?: null;
    } catch (Throwable $e) {
        error_log($e->getMessage());
        return null;
    }
}

function fetchLatestSermons(int $limit = 3): array {
    try {
        $db = getDB();
        $stmt = $db->prepare(
            'SELECT s.id, s.title, s.preacher, c.name as topic, s.description as content, s.created_at
             FROM written_sermons s
             LEFT JOIN sermon_categories c ON s.category_id = c.id
             ORDER BY s.created_at DESC
             LIMIT :limit'
        );
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        error_log($e->getMessage());
        return [];
    }
}

function isNewBook(string $created_at): bool {
    return strtotime($created_at) >= strtotime('-7 days');
}

function truncate(string $text, int $words = 18): string {
    $parts = explode(' ', strip_tags($text));
    return count($parts) > $words
        ? implode(' ', array_slice($parts, 0, $words)) . '&hellip;'
        : $text;
}

// ── Fetch all data ─────────────────────────────────────────────────────────
$books    = fetchLatestBooks(8);
$devotion = fetchTodaysDevotion();
$sermons  = fetchLatestSermons(3);

// ── Fetch hero carousel slides ─────────────────────────────────────────────
$heroSlides = [];
try {
    $heroSlides = getDB()
        ->query('SELECT * FROM hero_slides WHERE is_active=1 ORDER BY sort_order ASC, id ASC')
        ->fetchAll();
} catch (Throwable $e) {
    error_log('Hero slides fetch error: ' . $e->getMessage());
}
// Fallback slide if none exist or table missing
if (empty($heroSlides)) {
    $heroSlides = [['id'=>0,'eyebrow'=>'Seventh-day Adventist Digital Library','title'=>"Welcome to\nAdvent Library",'subtitle'=>'A sacred space for spiritual growth — explore Christian books, daily devotions, and sermons grounded in Adventist faith.','btn1_label'=>'Explore Library','btn1_url'=>'books.php','btn2_label'=>"Today's Devotion",'btn2_url'=>'devotionals.php','bg_image'=>'https://images.unsplash.com/photo-1507003211169-0a1dd7228f2d?w=1600&q=80','overlay_opacity'=>0.55]];
}

// ── Book categories for search filter ─────────────────────────────────────
$book_categories = [
    'All Categories', 'Theology', 'Prophecy', 'Devotional', 'Biography',
    'Health & Lifestyle', 'Evangelism', 'Family', 'Youth', 'Ellen G. White',
];
?>
<?php include __DIR__ . '/includes/header.php'; ?>
    <style>
        /* ── CSS Custom Properties (Light Theme) ── */
        :root {
            --c-bg:          #F8FAFC;
            --c-bg-soft:     #F1F5F9;
            --c-surface:     #FFFFFF;
            --c-surface-2:   #F8FAFC;
            --c-border:      rgba(30,58,95,.1);
            --c-border-soft: rgba(30,58,95,.05);

            --c-gold:        #D4A017;
            --c-gold-light:  #EBB52C;
            --c-gold-pale:   rgba(212,160,23,.1);
            --c-gold-glow:   rgba(212,160,23,.2);

            --c-text-primary:   #1E3A5F;
            --c-text-secondary: #4B5563;
            --c-text-muted:     #6B7280;

            --c-accent-teal:  #2E7D32;
            --c-accent-blue:  #2563EB;
        }

        /* ── Hero Carousel ── */
        .hero-carousel {
            position: relative;
            width: 100%;
            min-height: 100vh;
            overflow: hidden;
            background: #0a1223;
        }

        /* Each slide */
        .carousel-slide {
            position: absolute;
            inset: 0;
            display: flex;
            flex-direction: column;
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            opacity: 0;
            pointer-events: none;
            transition: opacity 1s cubic-bezier(0.4,0,0.2,1);
            will-change: opacity;
        }
        .carousel-slide.active {
            opacity: 1;
            pointer-events: auto;
            position: relative;
            min-height: 100vh;
        }

        /* Dark overlay per slide */
        .carousel-overlay {
            position: absolute;
            inset: 0;
            background: #0a1223;
            pointer-events: none;
        }

        /* Grid lines overlay */
        .hero-grid-lines {
            position: absolute; inset: 0;
            background-image:
                linear-gradient(rgba(255,255,255,0.03) 1px, transparent 1px),
                linear-gradient(90deg, rgba(255,255,255,0.03) 1px, transparent 1px);
            background-size: 60px 60px;
            pointer-events: none;
        }

        /* Cross watermark */
        .hero-cross {
            position: absolute;
            right: 8%;
            top: 50%;
            transform: translateY(-50%);
            opacity: .04;
            pointer-events: none;
        }

        /* ── Carousel content entrance animations ── */
        @keyframes carouselFadeUp {
            from { opacity:0; transform:translateY(28px); }
            to   { opacity:1; transform:translateY(0); }
        }
        .carousel-slide.active .carousel-anim-child {
            animation: carouselFadeUp .75s cubic-bezier(0.22,1,0.36,1) both;
        }
        .carousel-slide.active .delay-0 { animation-delay: .05s; }
        .carousel-slide.active .delay-1 { animation-delay: .18s; }
        .carousel-slide.active .delay-2 { animation-delay: .3s;  }
        .carousel-slide.active .delay-3 { animation-delay: .42s; }
        .carousel-slide.active .delay-4 { animation-delay: .55s; }

        /* ── Arrow buttons ── */
        .carousel-arrow {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            z-index: 30;
            width: 3.25rem;
            height: 3.25rem;
            border-radius: 50%;
            background: rgba(255,255,255,0.08);
            backdrop-filter: blur(8px);
            border: 1px solid rgba(255,255,255,0.15);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: background .2s, transform .2s, box-shadow .2s;
        }
        .carousel-arrow:hover {
            background: rgba(212,160,23,0.25);
            border-color: rgba(212,160,23,0.5);
            transform: translateY(-50%) scale(1.1);
            box-shadow: 0 0 20px rgba(212,160,23,0.3);
        }
        .carousel-prev { left: 1.5rem; }
        .carousel-next { right: 1.5rem; }

        /* ── Dot indicators ── */
        .carousel-dots {
            position: absolute;
            bottom: 2rem;
            left: 50%;
            transform: translateX(-50%);
            z-index: 30;
            display: flex;
            gap: .6rem;
            align-items: center;
        }
        .carousel-dot {
            width: .5rem;
            height: .5rem;
            border-radius: 999px;
            background: rgba(255,255,255,0.3);
            border: none;
            cursor: pointer;
            transition: width .35s ease, background .3s ease;
        }
        .carousel-dot.active {
            width: 2rem;
            background: var(--c-gold);
        }



        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(30px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        @keyframes fadeIn {
            from { opacity: 0; }
            to   { opacity: 1; }
        }
        @keyframes shimmer {
            0%, 100% { opacity: .5; }
            50%      { opacity: 1; }
        }

        .animate-fade-up   { animation: fadeUp .7s ease both; }
        .delay-100         { animation-delay: .1s; }
        .delay-200         { animation-delay: .2s; }
        .delay-300         { animation-delay: .3s; }
        .delay-400         { animation-delay: .4s; }
        .delay-500         { animation-delay: .5s; }
        .delay-600         { animation-delay: .6s; }

        .hero-eyebrow {
            display: inline-flex;
            align-items: center;
            gap: .5rem;
            background: var(--c-gold-pale);
            border: 1px solid var(--c-gold-glow);
            border-radius: 999px;
            padding: .375rem 1rem;
            font-size: .7rem;
            letter-spacing: .15em;
            text-transform: uppercase;
            color: var(--c-gold-light);
            font-weight: 500;
        }
        .hero-title {
            font-family: 'Montserrat', sans-serif;
            font-size: clamp(2.8rem, 7vw, 6rem);
            font-weight: 800;
            line-height: 1.05;
            color: #ffffff;
            letter-spacing: -0.025em;
            text-transform: uppercase;
            text-shadow: 0 2px 20px rgba(0,0,0,0.4);
        }
        .hero-title em {
            font-style: italic;
            color: var(--c-gold);
        }
        .hero-subtitle {
            font-size: clamp(1rem, 2vw, 1.2rem);
            color: rgba(255,255,255,0.75);
            line-height: 1.7;
            max-width: 38rem;
        }
        .btn-primary {
            display: inline-flex;
            align-items: center;
            gap: .625rem;
            background: var(--c-gold);
            color: #0b1120;
            font-weight: 600;
            font-size: .9rem;
            letter-spacing: .02em;
            padding: .85rem 2rem;
            border-radius: .5rem;
            transition: all .25s ease;
            border: 1px solid transparent;
        }
        .btn-primary:hover {
            background: var(--c-gold-light);
            transform: translateY(-2px);
            box-shadow: 0 12px 32px var(--c-gold-glow);
        }
        .btn-secondary {
            display: inline-flex;
            align-items: center;
            gap: .625rem;
            background: rgba(255,255,255,0.08);
            color: #ffffff;
            font-weight: 500;
            font-size: .9rem;
            letter-spacing: .02em;
            padding: .85rem 2rem;
            border-radius: .5rem;
            border: 1px solid rgba(255,255,255,0.25);
            backdrop-filter: blur(4px);
            transition: all .25s ease;
        }
        .btn-secondary:hover {
            border-color: rgba(255,255,255,0.5);
            background: rgba(255,255,255,0.15);
            transform: translateY(-2px);
        }

        /* Stats row */
        .stat-item   { text-align: center; }
        .stat-number {
            font-family: 'Montserrat', sans-serif;
            font-size: 2.2rem;
            font-weight: 800;
            color: var(--c-gold);
            line-height: 1;
            letter-spacing: -0.025em;
        }
        .stat-label { font-size: .75rem; color: rgba(255,255,255,0.5); letter-spacing: .08em; text-transform: uppercase; margin-top: .25rem; }
        .stat-divider { width: 1px; background: rgba(255,255,255,0.15); height: 2.5rem; align-self: center; }

        /* ── Section titles ── */
        .section-eyebrow {
            font-size: .7rem;
            letter-spacing: .2em;
            text-transform: uppercase;
            color: var(--c-gold);
            font-weight: 600;
        }
        .section-title {
            font-family: 'Montserrat', sans-serif;
            font-size: clamp(2rem, 4vw, 3rem);
            font-weight: 800;
            color: var(--c-text-primary);
            line-height: 1.15;
            text-transform: uppercase;
            letter-spacing: -0.025em;
        }
        .section-subtitle { color: var(--c-text-secondary); line-height: 1.7; }

        /* ── Search Section ── */
        #search-section { background: var(--c-surface); border-top: 1px solid var(--c-border); border-bottom: 1px solid var(--c-border); }
        .search-bar {
            background: var(--c-bg);
            border: 1px solid var(--c-border);
            border-radius: .75rem;
            transition: border-color .2s, box-shadow .2s;
        }
        .search-bar:focus-within {
            border-color: var(--c-gold);
            box-shadow: 0 0 0 3px var(--c-gold-pale);
        }
        .search-input {
            background: transparent;
            color: var(--c-text-primary);
            font-size: .95rem;
            outline: none;
        }
        .search-input::placeholder { color: var(--c-text-muted); }
        .search-select {
            background: var(--c-surface);
            color: var(--c-text-secondary);
            border: none;
            border-left: 1px solid var(--c-border);
            outline: none;
            font-size: .875rem;
            cursor: pointer;
        }
        .search-btn {
            background: var(--c-gold);
            color: #0b1120;
            font-weight: 600;
            border-radius: .5rem;
            transition: background .2s, transform .2s;
            white-space: nowrap;
        }
        .search-btn:hover { background: var(--c-gold-light); transform: scale(1.02); }

        /* ─ Book Cards ── */
        .book-card {
            background: var(--c-surface);
            border: 1px solid var(--c-border);
            border-radius: 1rem;
            overflow: hidden;
            transition: transform .3s ease, box-shadow .3s ease, border-color .3s ease;
            position: relative;
            display: flex;
            flex-direction: column;
        }
        .book-card:hover {
            transform: translateY(-6px);
            box-shadow: 0 20px 50px rgba(0,0,0,.4), 0 0 0 1px var(--c-gold-glow);
            border-color: var(--c-gold-glow);
        }
        .book-cover {
            width: 100%;
            aspect-ratio: 4/5;
            object-fit: cover;
            display: block;
            transition: transform .4s ease;
        }
        .book-card:hover .book-cover { transform: scale(1.04); }
        .book-cover-placeholder {
            width: 100%;
            aspect-ratio: 4/5;
            background: linear-gradient(135deg, var(--c-surface-2) 0%, var(--c-bg) 100%);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: .75rem;
        }
        .badge-new {
            position: absolute;
            top: .75rem; left: .75rem;
            background: #16a34a;
            color: #fff;
            font-size: .6rem;
            font-weight: 700;
            letter-spacing: .1em;
            text-transform: uppercase;
            padding: .25rem .625rem;
            border-radius: 999px;
            z-index: 2;
            box-shadow: 0 2px 8px rgba(22,163,74,.4);
        }
        .badge-category {
            font-size: .65rem;
            font-weight: 600;
            letter-spacing: .1em;
            text-transform: uppercase;
            color: var(--c-gold);
            background: var(--c-gold-pale);
            padding: .2rem .625rem;
            border-radius: 999px;
        }
        .book-title {
            font-family: 'Montserrat', sans-serif;
            font-size: 1.15rem;
            font-weight: 700;
            color: var(--c-text-primary);
            line-height: 1.3;
            letter-spacing: -0.025em;
        }
        .book-author { font-size: .8rem; color: var(--c-text-muted); }
        .book-desc   { font-size: .8rem; color: var(--c-text-secondary); line-height: 1.6; flex-grow: 1; }
        .btn-read {
            display: inline-flex;
            align-items: center;
            gap: .4rem;
            background: var(--c-gold-pale);
            border: 1px solid var(--c-gold-glow);
            color: var(--c-gold);
            font-size: .8rem;
            font-weight: 600;
            padding: .5rem 1rem;
            border-radius: .5rem;
            transition: all .2s;
        }
        .btn-read:hover { background: var(--c-gold); color: #0b1120; }

        /* ── Devotion Section ── */
        #devotion-section {
            background: linear-gradient(135deg, var(--c-surface) 0%, var(--c-bg) 100%);
            border-top: 1px solid var(--c-border);
            border-bottom: 1px solid var(--c-border);
        }
        .devotion-card {
            background: var(--c-surface-2);
            border: 1px solid var(--c-border);
            border-radius: 1.25rem;
            position: relative;
            overflow: hidden;
        }
        .devotion-card::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 3px;
            background: linear-gradient(90deg, var(--c-gold), var(--c-gold-light), var(--c-gold));
        }
        .devotion-verse {
            font-family: 'Amatic SC', cursive;
            font-size: 1.6rem;
            color: var(--c-text-secondary);
            line-height: 1.5;
            border-left: 3px solid var(--c-gold);
            padding-left: 1.25rem;
            letter-spacing: 0.05em;
        }
        .devotion-title {
            font-family: 'Montserrat', sans-serif;
            font-size: 2rem;
            font-weight: 800;
            color: var(--c-text-primary);
            text-transform: uppercase;
            letter-spacing: -0.025em;
        }
        .devotion-content { color: var(--c-text-secondary); line-height: 1.8; font-size: .95rem; }

        /* ── Sermon Cards ── */
        .sermon-card {
            background: var(--c-surface);
            border: 1px solid var(--c-border);
            border-radius: 1rem;
            transition: all .3s ease;
            position: relative;
            overflow: hidden;
        }
        .sermon-card::after {
            content: '';
            position: absolute;
            bottom: 0; left: 0;
            width: 0; height: 2px;
            background: linear-gradient(90deg, var(--c-gold), var(--c-gold-light));
            transition: width .35s ease;
        }
        .sermon-card:hover { transform: translateY(-4px); box-shadow: 0 16px 40px rgba(0,0,0,.35); border-color: var(--c-border); }
        .sermon-card:hover::after { width: 100%; }
        .sermon-number {
            font-family: 'Montserrat', sans-serif;
            font-size: 3rem;
            font-weight: 800;
            color: var(--c-border);
            line-height: 1;
            letter-spacing: -0.025em;
        }
        .sermon-title  {
            font-family: 'Montserrat', sans-serif;
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--c-text-primary);
            line-height: 1.3;
            letter-spacing: -0.025em;
        }
        .sermon-preacher { font-size: .8rem; color: var(--c-gold); font-weight: 500; }
        .sermon-topic    { font-size: .75rem; color: var(--c-text-muted); text-transform: uppercase; letter-spacing: .08em; }
        .sermon-excerpt  { font-size: .875rem; color: var(--c-text-secondary); line-height: 1.7; }

        /* ── Features ── */
        #features-section { background: var(--c-bg); }
        .feature-card {
            background: var(--c-surface);
            border: 1px solid var(--c-border);
            border-radius: 1rem;
            padding: 2rem;
            transition: all .3s ease;
            text-align: center;
        }
        .feature-card:hover {
            transform: translateY(-4px);
            border-color: var(--c-gold-glow);
            box-shadow: 0 12px 32px rgba(0,0,0,.3), 0 0 0 1px var(--c-gold-pale);
        }
        .feature-icon {
            width: 3.5rem;
            height: 3.5rem;
            border-radius: .75rem;
            background: var(--c-gold-pale);
            border: 1px solid var(--c-gold-glow);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1.25rem;
            color: var(--c-gold);
            transition: transform .3s;
        }
        .feature-card:hover .feature-icon { transform: scale(1.1) rotate(-5deg); }
        .feature-title  { font-family: 'Montserrat', sans-serif; font-size: 1.3rem; font-weight: 700; color: var(--c-text-primary); margin-bottom: .5rem; letter-spacing: -0.025em; }
        .feature-desc   { font-size: .875rem; color: var(--c-text-secondary); line-height: 1.7; }
        .feature-number { font-family: 'Montserrat', sans-serif; font-size: 1.8rem; font-weight: 800; color: var(--c-gold); letter-spacing: -0.025em; }

        /* ── About ── */
        #about-section { background: var(--c-surface); border-top: 1px solid var(--c-border); }
        .about-pillar {
            display: flex;
            align-items: flex-start;
            gap: 1rem;
        }
        .pillar-dot {
            width: .5rem;
            height: .5rem;
            border-radius: 50%;
            background: var(--c-gold);
            margin-top: .45rem;
            flex-shrink: 0;
        }

        /*  Scrollbar  */
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: var(--c-bg); }
        ::-webkit-scrollbar-thumb { background: var(--c-surface-2); border-radius: 999px; }
        ::-webkit-scrollbar-thumb:hover { background: var(--c-text-muted); }

        /* ── Divider ornament ── */
        .ornament-line {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        .ornament-line::before, .ornament-line::after {
            content: '';
            flex: 1;
            height: 1px;
            background: linear-gradient(to right, transparent, var(--c-border));
        }
        .ornament-line::after { background: linear-gradient(to left, transparent, var(--c-border)); }

        /* Empty state */
        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
            color: var(--c-text-muted);
        }
        .empty-state svg { margin: 0 auto 1rem; opacity: .3; }
    </style>

<?php include __DIR__ . '/includes/navbar.php'; ?>

<!-- ══════════════════════════════════════════════════════════════
     HERO CAROUSEL SECTION
══════════════════════════════════════════════════════════════ -->
<section id="hero" class="hero-carousel" aria-label="Hero Carousel" role="region">

    <?php foreach ($heroSlides as $idx => $slide):
        $bgStyle = '';
        if ($slide['bg_image']) {
            $bgUrl = str_starts_with($slide['bg_image'], 'http')
                ? htmlspecialchars($slide['bg_image'])
                : htmlspecialchars($slide['bg_image']);
            $bgStyle = "background-image:url('" . $bgUrl . "')";
        }
        $isFirst = ($idx === 0);
    ?>
    <div class="carousel-slide <?= $isFirst ? 'active' : '' ?>" data-slide="<?= $idx ?>" style="<?= $bgStyle ?>" aria-hidden="<?= $isFirst ? 'false' : 'true' ?>">
        <!-- Overlay -->
        <div class="carousel-overlay" style="opacity:<?= (float)$slide['overlay_opacity'] ?>"></div>
        <!-- Decorative grid lines -->
        <div class="hero-grid-lines" aria-hidden="true"></div>
        <!-- Cross watermark -->
        <div class="hero-cross" aria-hidden="true">
            <svg width="500" height="700" viewBox="0 0 500 700" fill="none"><rect x="210" y="0" width="80" height="700" fill="white"/><rect x="50" y="180" width="400" height="80" fill="white"/></svg>
        </div>

        <!-- Content -->
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 relative z-10 py-36 lg:py-44">
            <div class="max-w-3xl">
                <div class="carousel-anim-child delay-0">
                    <span class="hero-eyebrow">
                        <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
                        <?= htmlspecialchars($slide['eyebrow']) ?>
                    </span>
                </div>

                <h1 class="hero-title mt-6 carousel-anim-child delay-1">
                    <?php
                        $lines = explode("\n", $slide['title']);
                        echo htmlspecialchars($lines[0]);
                        if (isset($lines[1])) echo '<br><em>' . htmlspecialchars($lines[1]) . '</em>';
                    ?>
                </h1>

                <?php if ($slide['subtitle']): ?>
                <p class="hero-subtitle mt-6 carousel-anim-child delay-2">
                    <?= htmlspecialchars($slide['subtitle']) ?>
                </p>
                <?php endif; ?>

                <div class="flex flex-wrap gap-4 mt-10 carousel-anim-child delay-3">
                    <?php if ($slide['btn1_label']): ?>
                    <a href="<?= htmlspecialchars($slide['btn1_url']) ?>" class="btn-primary">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/></svg>
                        <?= htmlspecialchars($slide['btn1_label']) ?>
                    </a>
                    <?php endif; ?>
                    <?php if ($slide['btn2_label']): ?>
                    <a href="<?= htmlspecialchars($slide['btn2_url']) ?>" class="btn-secondary">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707"/></svg>
                        <?= htmlspecialchars($slide['btn2_label']) ?>
                    </a>
                    <?php endif; ?>
                </div>

                <!-- Stats Row -->
                <div class="flex flex-wrap items-center gap-6 mt-16 carousel-anim-child delay-4">
                    <div class="stat-item"><div class="stat-number">1K+</div><div class="stat-label">Books</div></div>
                    <div class="stat-divider hidden sm:block"></div>
                    <div class="stat-item"><div class="stat-number">365</div><div class="stat-label">Devotions/yr</div></div>
                    <div class="stat-divider hidden sm:block"></div>
                    <div class="stat-item"><div class="stat-number">500+</div><div class="stat-label">Sermons</div></div>
                    <div class="stat-divider hidden sm:block"></div>
                    <div class="stat-item"><div class="stat-number">Free</div><div class="stat-label">Always</div></div>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>

    <!-- ── Navigation Arrows ───────────────────────────────── -->
    <?php if (count($heroSlides) > 1): ?>
    <button class="carousel-arrow carousel-prev" aria-label="Previous slide">
        <svg width="24" height="24" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M15 19l-7-7 7-7"/></svg>
    </button>
    <button class="carousel-arrow carousel-next" aria-label="Next slide">
        <svg width="24" height="24" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/></svg>
    </button>

    <!-- ── Dot Indicators ───────────────────────────────────── -->
    <div class="carousel-dots" role="tablist" aria-label="Slide indicators">
        <?php foreach ($heroSlides as $idx => $slide): ?>
        <button class="carousel-dot <?= $idx === 0 ? 'active' : '' ?>" role="tab" aria-selected="<?= $idx === 0 ? 'true' : 'false' ?>" aria-label="Go to slide <?= $idx + 1 ?>" data-target="<?= $idx ?>"></button>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- ── Scroll Indicator ─────────────────────────────────── -->
    <div class="absolute bottom-8 left-1/2 -translate-x-1/2 flex flex-col items-center gap-2 opacity-30 z-20" aria-hidden="true">
        <span class="text-xs tracking-widest uppercase text-white">Scroll</span>
        <div class="w-px h-12 bg-gradient-to-b from-white to-transparent"></div>
    </div>

</section>


<!-- ══════════════════════════════════════════════════════════════
     SEARCH SECTION
══════════════════════════════════════════════════════════════ -->
<section id="search-section" class="py-12 lg:py-16">
    <div class="max-w-7xl mx-auto px-4 md:px-6 lg:px-8">

        <div class="text-center mb-8 reveal">
            <p class="section-eyebrow">Discover Knowledge</p>
            <h2 class="section-title mt-2">Search Our Collection</h2>
        </div>

        <form action="books.php" method="GET" role="search" class="reveal">
            <div class="search-bar flex flex-col sm:flex-row items-stretch sm:items-center gap-0 overflow-hidden">
                <!-- Search Icon -->
                <div class="flex items-center px-4 text-gray-500 flex-shrink-0">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                    </svg>
                </div>

                <!-- Text input -->
                <input
                    type="search"
                    name="q"
                    placeholder="Search books, authors, topics…"
                    class="search-input flex-1 py-4 px-2 w-full"
                    aria-label="Search the library"
                    autocomplete="off"
                >

                <!-- Category Dropdown -->
                <select name="category" class="search-select py-4 px-4 sm:min-w-[180px]" aria-label="Filter by category">
                    <?php foreach ($book_categories as $cat): ?>
                    <option value="<?= htmlspecialchars($cat === 'All Categories' ? '' : $cat) ?>">
                        <?= htmlspecialchars($cat) ?>
                    </option>
                    <?php endforeach; ?>
                </select>

                <!-- Submit -->
                <button type="submit" class="search-btn px-6 py-4 flex items-cent-sm">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                    <span class="hidden sm:inline">Search</span>
                </button>
            </div>
        </form>
    </div>
</section>

<!-- ══════════════════════════════════════════════════════════════
     FEATURED CONTENT SECTION
══════════════════════════════════════════════════════════════ -->
<?php if (!empty($books) || !empty($sermons) || $devotion): 
    $featuredBook = $books[0] ?? null;
    $featuredSermon = $sermons[0] ?? null;
?>
<section id="featured-section" class="py-12 lg:py-16 bg-surface-2" style="background: var(--c-surface-2); border-bottom: 1px solid var(--c-border);">
    <div class="max-w-7xl mx-auto px-4 md:px-6 lg:px-8">
        
        <div class="text-center mb-10 reveal">
            <p class="section-eyebrow">Highlights</p>
            <h2 class="section-title mt-2">Featured Today</h2>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
            
            <!-- Featured Devotion (Left, spans 7) -->
            <?php if ($devotion): ?>
            <div class="lg:col-span-7 reveal">
                <article class="devotion-card p-8 h-full flex flex-col justify-center">
                    <div class="flex items-center gap-2 mb-4">
                        <svg class="w-4 h-4" style="color:var(--c-gold)" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                        <span class="text-xs tracking-widest uppercase" style="color:var(--c-gold)">Daily Devotion • <?= date('F j') ?></span>
                    </div>
                    <h3 class="devotion-title mb-4" style="font-size: 1.6rem;"><?= htmlspecialchars($devotion['title']) ?></h3>
                    <?php if (!empty($devotion['verse'])): ?>
                    <blockquote class="devotion-verse mb-4" style="font-size: 1.4rem;">
                        <?= htmlspecialchars($devotion['verse']) ?>
                    </blockquote>
                    <?php endif; ?>
                    <p class="devotion-content mb-6 flex-1 text-sm">
                        <?= nl2br(htmlspecialchars(truncate($devotion['content'], 35))) ?>
                    </p>
                    <a href="devotion.php?id=<?= (int)$devotion['id'] ?>&type=daily" class="btn-primary inline-flex self-start py-2 px-4 text-xs">
                        Read Devotion
                    </a>
                </article>
            </div>
            <?php endif; ?>

            <!-- Right Column (spans 5) -->$_COOKIE            <div class="lg:col-span-5 flex flex-col gap-6 reveal delay-100">
                
                <!-- Featured Book -->$_COOKIE                <?php if ($featuredBook): ?>
                <article class="book-card flex-row h-full">
                    <div class="w-1/3 flex-shrink-0 bg-gray-100">
                        <?php if (!empty($featuredBook['cover_image'])): ?>
                        <img src="<?= htmlspecialchars($featuredBook['cover_image']) ?>" alt="Cover" class="w-full h-full object-cover">
                        <?php else: ?>
                        <div class="w-full h-full flex items-center justify-center text-xs text-gray-400 p-2 text-center bg-gray-200">No Cover</div>
                        <?php endif; ?>
                    </div>
                    <div class="p-4 flex flex-col justify-center flex-1">
                        <span class="badge-category self-start mb-2"><?= htmlspecialchars($featuredBook['category'] ?? 'New Release') ?></span>
                        <h3 class="book-title text-base"><?= htmlspecialchars($featuredBook['title']) ?></h3>
                        <p class="book-author mt-1 text-xs mb-3">by <?= htmlspecialchars($featuredBook['author']) ?></p>
                        <a href="book.php?id=<?= (int)$featuredBook['id'] ?>" class="btn-read self-start mt-auto py-1.5 px-3 text-xs">Read Book</a>
                    </div>
                </article>
                <?php endif; ?>

                <!-- Featured Sermon or Scripture -->$_COOKIE                <?php if ($featuredSermon): ?>
                <article class="sermon-card p-5 h-full flex flex-col justify-center border border-[var(--c-border)] shadow-sm">
                    <span class="text-xs uppercase tracking-widest text-[var(--c-gold)] font-bold mb-2">Featured Sermon</span>
                    <h3 class="sermon-title text-base mb-1"><?= htmlspecialchars($featuredSermon['title']) ?></h3>
                    <p class="sermon-preacher text-xs mb-3">
                        <svg class="w-3 h-3 inline mr-1" fill="currentColor" viewBox="0 0 24 24"><path d="M12 12c2.7 0 4.8-2.1 4.8-4.8S14.7 2.4 12 2.4 7.2 4.5 7.2 7.2 9.3 12 12 12zm0 2.4c-3.2 0-9.6 1.6-9.6 4.8v2.4h19.2v-2.4c0-3.2-6.4-4.8-9.6-4.8z"/></svg>
                        <?= htmlspecialchars($featuredSermon['preacher']) ?>
                    </p>
                    <a href="sermon.php?id=<?= (int)$featuredSermon['id'] ?>" class="btn-read self-start mt-auto py-1.5 px-3 text-xs">Read Sermon</a>
                </article>
                <?php else: ?>
                <div class="card p-6 h-full flex items-center justify-center text-center bg-gradient-to-br from-[var(--c-navy)] to-gray-900 text-white rounded-xl">
                    <div>
                        <svg class="w-6 h-6 mx-auto mb-2 text-[var(--c-gold)]" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
                        <p class="font-scripture text-xl leading-relaxed">"Thy word is a lamp unto my feet, and a light unto my path."</p>
                        <p class="text-xs mt-2 text-gray-400 tracking-widest uppercase">Psalm 119:105</p>
                    </div>
                </div>
                <?php endif; ?>
                
            </div>
        </div>

    </div>
</section>
<?php endif; ?>

<!-- ══════════════════════════════════════════════════════════════
     LATEST BOOKS SECTION
══════════════════════════════════════════════════════════════ -->$_COOKIE<section id="books-section" class="py-12 lg:py-16">
    <div class="max-w-7xl mx-auto px-4 md:px-6 lg:px-8">

        <!-- Section header -->$_COOKIE        <div class="flex flex-col sm:flex-row sm:items-end justify-between gap-4 mb-10 reveal">
            <div>
                <p class="section-eyebrow">Digital Collection</p>
                <h2 class="section-title mt-2">Latest Books</h2>
                <p class="section-subtitle mt-3 max-w-lg text-sm">
                    Freshly added titles from our growing library of Adventist and Christian literature.
                </p>
            </div>
            <a href="books.php" class="btn-secondary flex-shrink-0 text-sm py-2.5 px-5">
                View All fetchLatestBooks                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"/></svg>
            </a>
        </div>

        <?php if (!empty($books)): ?>
        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-x-6 gap-y-10">
            <?php foreach ($books as $i => $book): ?>
            <article class="book-card reveal" style="transition-delay: <?= ($i % 4) * 80 ?>ms"
                     aria-label="<?= htmlspecialchars($book['title']) ?>">

                <?php if (isNewBook($book['created_at'])): ?>
                <span class="badge-new" aria-label="New this week">NEW</span>
                <?php endif; ?>

                <!-- Cover -->$_COOKIE                <div class="overflow-hidden relative">
                    <?php if (!empty($book['cover_image'])): ?>
                    <intl_get_error_message                        src="<?= htmlspecialchars($book['cover_image']) ?>"
                        alt="Cover of <?= htmlspecialchars($book['title']) ?>"
                        class="book-cover"
                        loading="lazy"
                        onerror="this.parentElement.innerHTML = '<div class=\'book-cover-placeholder\'><svg class=\'w-10 h-10 opacity-20\' fill=\'none\' stroke=\'currentColor\' viewBox=\'0 0 24 24\'><path stroke-linecap=\'round\' stroke-linejoin=\'round\' stroke-width=\'1\' d=\'M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253\'/></svg></div>'"
                    >
                    <?php else: ?>
                    <div class="book-cover-placeholder">
                        <svg class="w-10 h-10 opacity-20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/>
                        </svg>
                        <span class="font-display text-xs opacity-30">No Cover</span>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Body -->$_COOKIE                <div class="p-3 flex flex-col gap-2 flex-1">
                    <span class="badge-category self-start"><?= htmlspecialchars($book['category'] ?? 'General') ?></span>
                    <h3 class="book-title"><?= htmlspecialchars($book['title']) ?></h3>
                    <p class="book-author">by <?= htmlspecialchars($book['author']) ?></p>
                    <?php if (!empty($book['description'])): ?>
                    <p class="book-desc"><?= truncate($book['description'], 16) ?></p>
                    <?php endif; ?>
                    <div class="mt-auto pt-3">
                        <a href="book.php?id=<?= (int)$book['id'] ?>" class="btn-read">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                            Read / Download
                        </a>
                    </div>
                </div>
            </article>
            <?php endforeach; ?>
        </div>

        <?php else: ?>
        <div class="empty-state reveal">
            <svg class="w-16 h-16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/>
            </svg>
            <h3 class="font-display text-xl mt-4 mb-2" style="color:var(--c-text-secondary)">No books yet</h3>
            <p class="text-sm">The library is being filled. Check back soon.</p>
        </div>
        <?php endif; ?>

    </div>
</section>





<!-- ══════════════════════════════════════════════════════════════
     LATEST SERMONS SECTION
══════════════════════════════════════════════════════════════ -->$_COOKIE<section id="sermons-section" class="py-12 lg:py-16">
    <div class="max-w-7xl mx-auto px-4 md:px-6 lg:px-8">

        <div class="flex flex-col sm:flex-row sm:items-end justify-between gap-4 mb-10 reveal">
            <div>
                <p class="section-eyebrow">From the Pulpit</p>
                <h2 class="section-title mt-2">Latest Sermons</h2>
                <p class="section-subtitle mt-3 text-sm max-w-lg">Written sermons from faithful preachers in the Adventist tradition.</p>
            </div>
            <a href="sermons.php" class="btn-secondary flex-shrink-0 text-sm py-2.5 px-5">
                All fetchLatestSermons                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"/></svg>
            </a>
        </div>

        <?php if (!empty($sermons)): ?>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            <?php foreach ($sermons as $i => $sermon): ?>
            <article class="sermon-card p-7 flex flex-col gap-4 reveal" style="transition-delay: <?= $i * 100 ?>ms">
                <div class="sermon-number">0<?= $i + 1 ?></div>
                <div>
                    <?php if (!empty($sermon['topic'])): ?>
                    <span class="sermon-topic"><?= htmlspecialchars($sermon['topic']) ?></span>
                    <?php endif; ?>
                    <h3 class="sermon-title mt-1"><?= htmlspecialchars($sermon['title']) ?></h3>
                    <p class="sermon-preacher mt-1">
                        <svg class="w-3.5 h-3.5 inline mr-1" fill="currentColor" viewBox="0 0 24 24"><path d="M12 12c2.7 0 4.8-2.1 4.8-4.8S14.7 2.4 12 2.4 7.2 4.5 7.2 7.2 9.3 12 12 12zm0 2.4c-3.2 0-9.6 1.6-9.6 4.8v2.4h19.2v-2.4c0-3.2-6.4-4.8-9.6-4.8z"/></svg>
                        <?= htmlspecialchars($sermon['preacher']) ?>
                    </p>
                </div>
                <p class="sermon-excerpt flex-1"><?= truncate($sermon['content'], 22) ?></p>
                <a href="sermon.php?id=<?= (int)$sermon['id'] ?>" class="btn-read mt-auto self-start">
                    Read fetchLatestSermons                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"/></svg>
                </a>
            </article>
            <?php endforeach; ?>
        </div>

        <?php else: ?>
        <div class="empty-state reveal">
            <svg class="w-14 h-14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-wiATURES SECTION
══════════════════════════════════════════════════════════════ -->$_COOKIE<section id="features-section" class="py-12 lg:py-16" style="border-top:1px solid var(--c-border)">
    <div class="max-w-7xl mx-auto px-4 md:px-6 lg:px-8">

        <div class="text-center mb-10 reveal">
            <p class="section-eyebrow">What We Offer</p>
            <h2 class="section-title mt-2">Everything You Need</h2>
            <p class="section-subtitle mt-3 text-sm max-w-xl mx-auto">
                A comprehensive digital resource platform built to nurture your faith journey.
            </p>
        </div>

        <?php       

            [
                'number' => '1,000+',
                'title'  => 'Christian Books',
                'desc'   => 'An expansive digital collection spanning theology, prophecy, biography, health, and Adventist heritage — all freely accessible.',
                'icon'   => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/>',
            ],
            [
                'number' => '365',
                'title'  => 'Daily Devotions',
                'desc'   => 'Start every morning with Spirit-led Scripture reflections — thoughtfully curated for daily spiritual nourishment.',
                'icon'   => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M12 8a4 4 0 100 8 4 4 0 000-8z"/>',
            ],
            [
                'number' => '500+',
                'title'  => 'Written Sermons',
                'desc'   => 'Dive into a rich archive of preached messages, available to read, study, and share with your congregation.',
                'icon'   => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 11a7 7 0 01-7 7m0 0a7 7 0 01-7-7m7 7v4m0 0H8m4 0h4m-4-8a3 3 0 01-3-3V5a3 3 0 116 0v6a3 3 0 01-3 3z"/>',
            ],
        
                'number' => 'Free',
                'title'  => 'Spiritual Rchool resources — all in one place.',
                'icon'   => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m1.636-6.364l.707.707M6 20l6-6 6 6"/>
            ],
        ];
        ?>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
            <?php foreach ($features as $i => $feat): ?>
            <div class="feature-card reveal" style="transition-delay: <?= $i * 80 ?>ms">
                <div class="feature-icon">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <?= $feat['icon'] ?>
                    </svg>
                </div>
                <div class="feature-number"><?= $feat['number'] ?></div>
                <h3 class="feature-title"><?= $feat['title'] ?></h3>
                <p class="feature-desc"><?= $feat['desc'] ?></p>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>


<!-- ══════════════════════════════════════════════════════════════
     ABOUT SECTION
══════════════════════════════════════════════════════════════ -->
<section id="about-section" class="py-12 lg:py-16">
    <div class="max-w-7xl mx-auto px-4 md:px-6 lg:px-8">
        <div class="grid lg:grid-cols-2 gap-16 items-center">

            <!-- Text -->
            <div class="reveal">
                <p class="section-eyebrow">Our Mission</p>
                <h2 class="section-title mt-2">Sharing the Light of <em class="font-scripture" style="color:var(--c-gold)">Truth</em></h2>
                <p class="section-subtitle mt-5 text-sm leading-relaxed">
                    Advent Library was born from a passion to make Seventh-day Adventist knowledge and Christian literature accessible to every believer — regardless of location or circumstance.
                </p>

                <div class="flex flex-col gap-5 mt-8">
                    <?php
                    $pillars = [
                        ['title' => 'Faith-Rooted Knowledge', 'desc' => 'Every resource is grounded in Scripture and the Spirit of Prophecy.'],
                        ['title' => 'Digital Access for All', 'desc' => 'Free, mobile-friendly access to the entire Adventist literary heritage.'],
                        ['title' => 'Community & Growth', 'desc' => 'Connecting Adventists across the globe through shared spiritual resources.'],
                        ['title' => 'Ongoing Updates',     'desc' => 'New books, devotions, and sermons are added regularly by our editorial team.'],
                    ];
                    foreach ($pillars as $p):
                    ?>
                    <div class="about-pillar">
                        <div class="pillar-dot"></div>
                        <div>
                            <h4 class="font-semibold text-sm" style="color:var(--c-text-primary)"><?= $p['title'] ?></h4>
                            <p class="text-sm mt-0.5" style="color:var(--c-text-secondary)"><?= $p['desc'] ?></p>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <a href="about.php" class="btn-secondary inline-flex mt-10 text-sm py-2.5 px-5">
                    Learn More About Us
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"/></svg>
                </a>
            </div>

            <!-- Decorative panel -->
            <div class="reveal lg:flex hidden" aria-hidden="true">
                <div class="relative w-full">
                    <!-- Large quote -->
                    <div class="absolute -top-8 -left-4 font-display text-[10rem] leading-none select-none" style="color:var(--c-border); font-size: 12rem;">"</div>
                    <div class="relative z-10 rounded-2xl overflow-hidden border" style="border-color:var(--c-border); background:var(--c-surface-2)">
                        <div class="p-10">
                            <blockquote class="font-scripture text-2xl leading-relaxed mb-6" style="color:var(--c-text-primary)">
                                The light of God's word is to be received and cherished. It is a lamp to our feet and a light to our path.
                            </blockquote>
                            <cite class="text-sm not-italic" style="color:var(--c-gold)"> Ellen G. White, <em>Testimonies for the Church</em></cite>

                            <div class="grid grid-cols-3 gap-4 mt-10 pt-8" style="border-top:1px solid var(--c-border)">
                                <?php foreach ([['1K+','Books'], ['365','Devotions'], ['500+','Sermons']] as [$n,$l]): ?>
                                <div class="text-center">
                                    <div class="font-display text-2xl font-bold" style="color:var(--c-gold)"><?= $n ?></div>
                                    <div class="text-xs mt-1 tracking-widest uppercase" style="color:var(--c-text-muted)"><?= $l ?></div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- CTA Banner -->
<section class="py-16" style="background:linear-gradient(135deg, var(--c-surface) 0%, var(--c-surface-2) 100%); border-top:1px solid var(--c-border); border-bottom:1px solid var(--c-border);">
    <div class="max-w-3xl mx-auto px-4 text-center reveal">
        <p class="section-eyebrow">Start Your Journey</p>
        <h2 class="section-title mt-3">Begin Reading Today —<br><em class="font-scripture" style="color:var(--c-gold)">It's Completely Free</em></h2>
        <p class="section-subtitle mt-4 text-sm mb-8">
            Join thousands of Adventists who use Advent Library for their daily spiritual growth.
        </p>
        <div class="flex flex-wrap justify-center gap-4">
            <a href="register.php" class="btn-primary">Create Free Account</a>
            <a href="books.php" class="btn-secondary">Browse Without Signing In</a>
        </div>
    </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>

<!-- ═════════════════════════════════════════════════════════
     JAVASCRIPT
══════════════════════════════════════════════════════════════ -->
<script>
(function() {
    'use strict';

    // "]]')}}')}}