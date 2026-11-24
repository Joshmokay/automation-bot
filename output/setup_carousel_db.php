<?php
// setup_carousel_db.php — Run once to create the hero_slides table
declare(strict_types=1);
require_once __DIR__ . '/includes/db.php';
$db = getDB();

$db->exec("
CREATE TABLE IF NOT EXISTS hero_slides (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    sort_order   INT NOT NULL DEFAULT 0,
    title        VARCHAR(200) NOT NULL,
    subtitle     VARCHAR(400) NOT NULL DEFAULT '',
    eyebrow      VARCHAR(150) NOT NULL DEFAULT 'Advent Library',
    btn1_label   VARCHAR(80)  NOT NULL DEFAULT 'Explore Library',
    btn1_url     VARCHAR(300) NOT NULL DEFAULT 'books.php',
    btn2_label   VARCHAR(80)  NOT NULL DEFAULT '',
    btn2_url     VARCHAR(300) NOT NULL DEFAULT '',
    bg_image     VARCHAR(400) NOT NULL DEFAULT '',
    overlay_opacity DECIMAL(3,2) NOT NULL DEFAULT 0.55,
    is_active    TINYINT(1)   NOT NULL DEFAULT 1,
    created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// Insert default slides only if table is empty
$count = $db->query('SELECT COUNT(*) FROM hero_slides')->fetchColumn();
if ((int)$count === 0) {
    $db->exec("
    INSERT INTO hero_slides (sort_order, title, subtitle, eyebrow, btn1_label, btn1_url, btn2_label, btn2_url, bg_image, overlay_opacity) VALUES
    (1, 'Welcome to\nAdvent Library', 'A sacred space for spiritual growth — explore Christian books, daily devotions, and sermons grounded in Adventist faith.', 'Seventh-day Adventist Digital Library', 'Explore Library', 'books.php', 'Today\\'s Devotion', 'devotionals.php', 'https://images.unsplash.com/photo-1507003211169-0a1dd7228f2d?w=1600&q=80', 0.55),
    (2, 'Daily Devotionals\nfor Every Heart', 'Start each morning with God\\'s Word. Our curated devotional readings inspire faith, hope, and a deeper walk with Christ.', 'Morning Devotionals', 'Read Today', 'devotionals.php', 'Explore All', 'devotionals.php', 'https://images.unsplash.com/photo-1438232992991-995b7058bbb3?w=1600&q=80', 0.60),
    (3, 'Timeless Wisdom\nfrom EGW', 'Discover the Spirit of Prophecy. Ellen G. White\\'s writings continue to illuminate truth and guide the remnant church.', 'EGW Writings', 'Browse Writings', 'ellen_white.php', '', '', 'https://images.unsplash.com/photo-1457369804613-52c61a468e7d?w=1600&q=80', 0.62)
    ");
}

echo '<p style="font-family:sans-serif;color:green;padding:2rem">✅ Hero slides table created and seeded successfully. <a href="index.php">Go to homepage</a></p>';
