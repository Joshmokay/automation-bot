<?php// download-lesson.php — Programmatic PDF Generator for Sabbath School Lessons
declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/includes/db.php';

use Dompdf\Dompdf;
use Dompdf\Options;

$db = getDB();

// 1. Fetch Request Parameters & Validate
$type = $_GET['type'] ?? 'day';
$day_id = $_GET['day_id'] ?? '';
$resource_type = $_GET['resource_type'] ?? '';
$week_id = $_GET['week_id'] ?? '';

$rawContent = '';
$activeTitle = '';
$activeSubtitle = '';
$week = null;
$quarterly = null;

if ($type === 'resource' && $resource_type !== '' && $week_id !== '') {
    try {
        $resStmt = $db->prepare("SELECT * FROM ss_official_resources WHERE week_id = ? AND resource_type = ?");
        $resStmt->execute([$week_id, $resource_type]);
        $res = $resStmt->fetch();
        
        if (!$res) {
            header("HTTP/1.1 404 Not Found");
            echo "<h1>404 Not Found</h1><p>The requested study resource was not found.</p>";
            exit;
}
        
        $rawContent = $res['content'] ?? '';
        $activeTitle = $res['title'];
        $activeSubtitle = ucfirst(str_replace('-', ' ', $resource_type));
        
        // Fetch week and quarterly
        $weekStmt = $db->prepare("SELECT * FROM ss_official_weeks WHERE id = ?");
        $weekStmt->execute([$week_id]);
        $week = $weekStmt->fetch();
        
        if ($week) {
            $quarterlyStmt = $db->prepare("SELECT * FROM ss_official_quarterlies WHERE id = ?");
            $quarterlyStmt->execute([$week['quarterly_id']]);
            $quarterly = $quarterlyStmt->fetch();
}
        
        // Form a unique caching day_id for resource type
        $cache_id = "resource_" . $week_id . "_" . $resource_type;
    } catch (Throwable $e) {
        header("HTTP/1.1 500 Internal Server Error");
        echo "<h1>500 Internal Server Error</h1><p>" . htmlspecialchars($e->getMessage()) . "</p>";
        exit;
    }
} else {
    // Day-by-day lesson guide
    if (!$day_id) {
        header("HTTP/1.1 400 Bad Request");
        echo "<h1>400 Bad Request</h1><p>Missing day_id parameter.</p>";
        exit;
    }
    
    try {
        $dayStmt = $db->prepare("SELECT * FROM ss_official_days WHERE id = ?");
        $dayStmt->execute([$day_id]);
        $day = $dayStmt->fetch();
        
        if (!$day) {
            header("HTTP/1.1 404 Not Found");
            echo "<h1>404 Not Found</h1><p>The requested lesson day was not found.</p>";
            exit;
}
        
        $rawContent = $day['content'];
        $activeTitle = $day['title'];
        $activeSubtitle = date('l, F d, Y', strtotime($day['date']));
        
        // Fetch week and quarterly
        $weekStmt = $db->prepare("SELECT * FROM ss_official_weeks WHERE id = ?");
        $weekStmt->execute([$day['week_id']]);
        $week = $weekStmt->fetch();
        
        if ($week) {
            $quarterlyStmt = $db->prepare("SELECT * FROM ss_official_quarterlies WHERE id = ?");
            $quarterlyStmt->execute([$week['quarterly_id']]);
            $quarterly = $quarterlyStmt->fetch();
}
        
        $cache_id = "day_" . $day_id;
    } catch (Throwable $e) {
        header("HTTP/1.1 500 Internal Server Error");
        echo "<h1>500 Internal Server Error</h1><p>" . htmlspecialchars($e->getMessage()) . "</p>";
        exit;
    }
}

if (!$week || !$quarterly) {
    header("HTTP/1.1 404 Not Found");
    echo "<h1>404 Not Found</h1><p>Lesson metadata could not be resolved.</p>";
    exit;
}

// 2. Custom PDF-Optimized Markdown Parser
function renderMarkdownForPDF(string $md): string {
    $md = str_replace("\r\n", "\n", $md);
    $md = str_replace("\r", "\n", $md);

    // Escape HTML first
    $html = htmlspecialchars($md, ENT_NOQUOTES, 'UTF-8');
    
    // Bold & Italics
    $html = preg_replace('/\*\*(.*?)\*\*/', '<strong>$1</strong>', $html);
    $html = preg_replace('/_(.*?)_/', '<em>$1</em>', $html);
    
    // Blockquotes
    $lines = explode("\n", $html);
    $inBlockquote = false;
    $blockquoteContent = [];
    $newLines = [];
    
    foreach ($lines as $line) {
        if (strpos(trim($line), '&gt;') === 0) {
            $inBlockquote = true;
            $blockquoteContent[] = trim(substr(trim($line), 4));
} else {
            if ($inBlockquote) {
                $inBlockquote = false;
                $bqText = implode("<br>", $blockquoteContent);
                $bqText = str_ireplace(['&lt;p&gt;', '&lt;/p&gt;'], '', $bqText);
                $newLines[] = '<div class="memory-text"><strong>Memory Text:</strong><br><em>' . $bqText . '</em></div>';
                $blockquoteContent = [];
}
            $newLines[] = $line;
        }
    }
    if ($inBlockquote) {
        $bqText = implode("<br>", $blockquoteContent);
        $bqText = str_ireplace(['&lt;p&gt;', '&lt;/p&gt;'], '', $bqText);
        $newLines[] = '<div class="memory-text"><strong>Memory Text:</strong><br><em>' . $bqText . '</em></div>';
    }
    
    $html = implode("\n", $newLines);
    
    // Links (Display nicely in print PDF)
    $html = preg_replace('/\[(.*?)\]\((.*?)\)/', '<span class="link-styled">$1</span>', $html);
    
    // Headers
    $html = preg_replace('/^\s*#####\s*(.*)$/m', '<h5 class="header-h5">$1</h5>', $html);
    $html = preg_replace('/^\s*####\s*(.*)$/m', '<h4 class="header-h4">$1</h4>', $html);
    $html = preg_replace('/^\s*###\s*(.*)$/m', '<h3 class="header-h3">$1</h3>', $html);

    // Bible references
    $booksPattern = '(Gen|Exo|Lev|Num|Deu|Jos|Jud|Rut|1\s*Sam|2\s*Sam|1\s*Kin|2\s*Kin|1\s*Chr|2\s*Chr|Ezr|Neh|Est|Job|Psa|Pro|Ecc|Son|Isa|Jer|Lam|Eze|Dan|Hos|Joe|Amo|Oba|Jon|Mic|Nah|Hab|Zep|Hag|Zec|Mal|Mat|Mar|Luk|Joh|Act|Rom|1\s*Cor|2\s*Cor|Gal|Eph|Phi|Col|1\s*The|2\s*The|1\s*Tim|2\s*Tim|Tit|Phm|Heb|Jas|1\s*Pet|2\s*Pet|1\s*Joh|2\s*Joh|3\s*Joh|Jud|Rev|Genesis|Exodus|Levit|Numer|Deuter|Joshua|Judges|Ruth|Samuel|Kings|Chron|Ezra|Nehem|Esther|Job|Psalms|Prov|Eccles|Isaiah|Jerem|Lament|Ezek|Daniel|Hosea|Joel|Amos|Obad|Jonah|Micah|Nahum|Habak|Zeph|Hagg|Zech|Malach|Matt|Mark|Luke|John|Acts|Rom|Galat|Ephes|Philip|Coloss|Thess|Timoth|Titus|Philemon|Hebrews|James|Peter|Jude|Revel)';
    $refRegex = '/' . $booksPattern . '\.?\s*\d+\s*:\s*\d+(?:\s*-\s*\d+)?/i';
    
    $html = preg_replace_callback($refRegex, function($matches) {
        return '<span class="bible-ref">📖 ' . $matches[0] . '</span>';
    }, $html);

    // Lists
    $html = preg_replace('/^\s*[\-\*]\s+(.*)$/m', '<li class="list-bullet">$1</li>', $html);
    $html = preg_replace('/^\s*(\d+)\.\s+(.*)$/m', '<li class="list-number">$2</li>', $html);
    
    // Paragraphs
    $html = preg_replace('/\n{2,}/', '</p><p class="paragraph">', $html);
    $html = '<p class="paragraph">' . $html . '</p>';
    
    // Cleanup empty paragraphs
    $html = str_replace('<p class="paragraph"></p>', '', $html);
    $html = str_replace('<p class="paragraph"><div', '<div', $html);
    $html = str_replace('</div></p>', '</div>', $html);
    $html = str_replace('<p class="paragraph"><h', '<h', $html);
    $html = str_replace('</h3></p>', '</h3>', $html);
    $html = str_replace('</h4></p>', '</h4>', $html);
    $html = str_replace('</h5></p>', '</h5>', $html);
    
    return $html;
}

// 3. Cache Setup
$cacheDir = __DIR__ . '/cache/pdf';
if (!file_exists($cacheDir)) {
    mkdir($cacheDir, 0777, true);
}

$contentHash = md5($rawContent);
$cacheFilename = "lesson_{$cache_id}_{$contentHash}.pdf";
$cachePath = $cacheDir . '/' . $cacheFilename;

// If cached file exists, serve it immediately!
if (file_exists($cachePath)) {
    $downloadName = str_replace([' ', '/', '\\', ':', '*', '?', '"', '<', '>', '|'], '_', $activeTitle) . '.pdf';
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $downloadName . '"');
    header('Content-Length: ' . filesize($cachePath));
    readfile($cachePath);
    exit;
}

// 4. Base64 Logo Encoding (avoiding path resolution and GD issues)
$logoPath = __DIR__ . '/includes/logo.png';
$logoBase64 = '';
if (file_exists($logoPath)) {
    $logoData = file_get_contents($logoPath);
    $logoBase64 = 'data:image/png;base64,' . base64_encode($logoData);
}

// 5. Generate PDF HTML content
ob_start();
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    @page {
        margin: 120px 50px 80px 50px;
    }
    
    /* Header repeated on every page */
    #header {
        position: fixed;
        top: -95px;
        left: 0;
        right: 0;
        height: 60px;
        border-bottom: 2px solid #D4A017;
        padding-bottom: 5px;
    }
    
    #header table {
        width: 100%;
        border-collapse: collapse;
    }
    
    .logo-img {
        height: 40px;
        width: 40px;
    }
    
    .logo-text {
        font-family: Helvetica, Arial, sans-serif;
        font-size: 16px;
        font-weight: bold;
        color: #122D31;
        letter-spacing: 0.5px;
        line-height: 1.2;
    }
    
    .logo-subtext {
        font-family: Helvetica, Arial, sans-serif;
        font-size: 8px;
        font-weight: bold;
        color: #777;
        text-transform: uppercase;
        letter-spacing: 1px;
    }
    
    /* Footer repeated on every page */
    #footer {
        position: fixed;
        bottom: -50px;
        left: 0;
        right: 0;
        height: 30px;
        border-top: 1px solid #E6DFD3;
        padding-top: 5px;
        text-align: center;
        font-family: Helvetica, Arial, sans-serif;
        font-size: 8pt;
        color: #777;
    }
    
    #footer .page-number:after {
        content: counter(page);
    }
    
    /* Document elements styling */
    body {
        font-family: "Georgia", Times, serif;
        font-size: 11pt;
        line-height: 1.6;
        color: #2E1F14;
    }
    
    .lesson-meta {
        font-family: Helvetica, Arial, sans-serif;
        font-size: 9.5pt;
        font-weight: bold;
        color: #C28213;
        text-transform: uppercase;
        margin-bottom: 3px;
        letter-spacing: 0.5px;
    }
    
    .lesson-title {
        font-family: Helvetica, Arial, sans-serif;
        font-size: 20pt;
        font-weight: bold;
        color: #122D31;
        margin-top: 0;
        margin-bottom: 8px;
        line-height: 1.25;
    }
    
    .quarterly-title {
        font-family: Helvetica, Arial, sans-serif;
        font-size: 9pt;
        color: #666;
        margin-bottom: 25px;
        border-bottom: 1px dashed #E6DFD3;
        padding-bottom: 10px;
    }
    
    .paragraph {
        margin-bottom: 14px;
        text-align: justify;
    }
    
    .memory-text {
        background-color: #FBF9F4;
        border-left: 3px solid #D4A017;
        padding: 10px 14px;
        margin: 18px 0;
        font-size: 10pt;
        border-radius: 0 4px 4px 0;
    }
    
    .header-h3 {
        font-family: Helvetica, Arial, sans-serif;
        font-size: 11.5pt;
        font-weight: bold;
        color: #122D31;
        margin-top: 22px;
        margin-bottom: 8px;
        border-left: 2px solid #122D31;
        padding-left: 6px;
    }
    
    .header-h4 {
        font-family: Helvetica, Arial, sans-serif;
        font-size: 10pt;
        font-weight: bold;
        color: #C28213;
        margin-top: 18px;
        margin-bottom: 6px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    
    .header-h5 {
        font-family: Helvetica, Arial, sans-serif;
        font-size: 9pt;
        font-weight: bold;
        color: #555;
        margin-top: 14px;
        margin-bottom: 4px;
        text-transform: uppercase;
    }
    
    .bible-ref {
        color: #C28213;
        font-weight: bold;
    }
    
    .link-styled {
        color: #122D31;
        text-decoration: underline;
        font-weight: bold;
    }
    
    .list-bullet {
        margin-left: 15px;
        list-style-type: disc;
        margin-bottom: 4px;
    }
    
    .list-number {
        margin-left: 15px;
        list-style-type: decimal;
        margin-bottom: 4px;
    }
</style>
</head>
<body>

<!-- Repeated Header -->$_COOKIE<div id="header">
    <table>
        <tr>
            <td style="width: 50px; vertical-align: middle;">
                <?php if ($logoBase64 !== ''): ?>
                    <img src="<?= $logoBase64 ?>" class="logo-img" alt="Logo">
                <?php endif; ?>
            </td>
            <td style="vertical-align: middle; padding-left: 8px;">
                <div class="logo-text">ADVENT LIBRARY</div>
                <div class="logo-subtext">Sabbath School Lesson Guide</div>
            </td>
            <td style="text-align: right; vertical-align: middle; font-family: Helvetica, Arial, sans-serif; font-size: 9px; color: #777;">
                <?= htmlspecialchars($quarterly['title']) ?><br>
                Week <?= $week['week_number'] ?> &bull; <?= htmlspecialchars($activeSubtitle) ?>
            </td>
        </tr>
    </table>
</div>

<!-- Repeated Footer -->$_COOKIE<div id="footer">
    <span>Advent Library Sabbath School Lesson | </span>
    <span class="page-number">Page </span>
</div>

<!-- Meta/Titles -->$_COOKIE<div class="lesson-meta"><?= htmlspecialchars($activeSubtitle) ?></div>
<h1 class="lesson-title"><?= htmlspecialchars($activeTitle) ?></h1>
<div class="quarterly-title">Study Guide &bull; <?= htmlspecialchars($quarterly['title']) ?> &bull; Week <?= $week['week_number'] ?></div>

<!-- Main Body -->$_COOKIE<div class="content">
    <?= renderMarkdownForPDF($rawContent) ?>
</div>

</body>
</html>
<?php$html = ob_get_clean();

// 6. Initialize Dompdf & Render
try {
    $options = new Options();
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isPhpEnabled', false);
    $options->set('isRemoteEnabled', false);
    
    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    
    // Clean up any old cached files for this specific lesson id
    $oldFiles = glob($cacheDir . "/lesson_{$cache_id}_*.pdf");
    if ($oldFiles) {
        foreach ($oldFiles as $oldFile) {
            if (file_exists($oldFile)) {
                @unlink($oldFile);
}
        }
    }
    
    // Save to cache
    file_put_contents($cachePath, $dompdf->output());
    
    // 7. Output PDF file to User
    $downloadName = str_replace([' ', '/', '\\', ':', '*', '?', '"', '<', '>', '|'], '_', $activeTitle) . '.pdf';
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $downloadName . '"');
    header('Content-Length: ' . filesize($cachePath));
    readfile($cachePath);
    exit;
} catch (Throwable $e) {
    header("HTTP/1.1 500 Internal Server Error");
    echo "<h1>500 Internal Server Error</h1><p>Failed to generate PDF: " . htmlspecialchars($e->getMessage()) . "</p>";
    exit;
}
)}}}}