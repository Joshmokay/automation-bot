<?php
// setup_devotionals_db.php
require_once __DIR__ . '/includes/db.php';

try {
    $db = getDB();

    echo "Setting up Devotionals Database Tables...<br>";

    // 1. Devotional Categories
    $db->exec("CREATE TABLE IF NOT EXISTS devotional_categories (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL UNIQUE
    )");
    echo "Created devotional_categories table.<br>";

    // Populate categories
    $categories = [
        'Morning Devotionals', 'Evening Devotionals', 'Youth Devotionals', 
        'Family Devotionals', 'Prayer & Faith', 'Christian Living', 
        'Hope & Encouragement', 'End Time Preparation', 'Health & Wellness', 
        'Ellen G. White Devotionals'
    ];
    $stmt = $db->prepare("INSERT IGNORE INTO devotional_categories (name) VALUES (?)");
    foreach ($categories as $cat) {
        $stmt->execute([$cat]);
}

    // 2. Daily Devotionals
    // Rename existing 'devotions' table if it exists
    $tableExists = $db->query("SHOW TABLES LIKE 'devotions'")->rowCount() > 0;
    if ($tableExists) {
        $db->exec("RENAME TABLE devotions TO daily_devotionals");
        echo "Renamed devotions to daily_devotionals.<br>";
    } else {
        $db->exec("CREATE TABLE IF NOT EXISTS daily_devotionals (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255),
            verse VARCHAR(255),
            content TEXT,
            devotion_date DATE
        )");
        echo "Created daily_devotionals table.<br>";
    }

    // Add new columns to daily_devotionals safely
    $columns = [
        'category_id' => 'INT NULL',
        'author' => 'VARCHAR(255) NULL',
        'background_image' => 'VARCHAR(255) NULL',
        'created_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP'
    ];

    foreach ($columns as $col => $def) {
        try {
            $db->exec("ALTER TABLE daily_devotionals ADD COLUMN $col $def");
            echo "Added $col to daily_devotionals.<br>";
} catch (PDOException $e) {
            // Column might already exist
}
    }

    // Add foreign key to daily_devotionals safely
    try {
        $db->exec("ALTER TABLE daily_devotionals ADD CONSTRAINT fk_daily_cat FOREIGN KEY (category_id) REFERENCES devotional_categories(id) ON DELETE SET NULL");
    } catch (PDOException $e) {}

    // 3. Devotional Books
    $db->exec("CREATE TABLE IF NOT EXISTS devotional_books (
        id INT AUTO_INCREMENT PRIMARY KEY,
        category_id INT NULL,
        title VARCHAR(255) NOT NULL,
        author VARCHAR(255) NOT NULL,
        description TEXT,
        content TEXT,
        cover_image VARCHAR(255) NULL,
        pdf_file VARCHAR(255) NULL,
        is_featured BOOLEAN DEFAULT 0,
        is_new BOOLEAN DEFAULT 1,
        is_popular BOOLEAN DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (category_id) REFERENCES devotional_categories(id) ON DELETE SET NULL
    )");
    echo "Created devotional_books table.<br>";

    echo "<strong>Database setup complete!</strong>";

} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}
?>
