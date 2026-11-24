<?php// <setup_submissions_db class="php">require_once __DIR__ . '/includes/db.php';

try {
    $db = getDB();
    
    $sql = "
    CREATE TABLE IF NOT EXISTS `user_submissions` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `user_id` int(11) NOT NULL,
      `submission_type` enum('Devotional','Testimony','Article') NOT NULL,
      `title` varchar(255) NOT NULL,
      `content` longtext DEFAULT NULL,
      `pdf_file` varchar(255) DEFAULT NULL,
      `status` enum('Pending','Approved','Rejected') DEFAULT 'Pending',
      `admin_notes` text DEFAULT NULL,
      `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
      PRIMARY KEY (`id`),
      KEY `user_id` (`user_id`),
      CONSTRAINT `fk_user_submissions` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    
    $db->exec($sql);
    echo "user_submissions table created successfully!\n";
} catch (Exception $e) {
    echo "Error creating table: " . $e->getMessage() . "\n";
}
?>
</setup_submissions_db>