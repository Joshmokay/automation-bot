<?php// <submit_content class="php">declare(strict_types=1);
require_once __DIR__ . '/includes/db.php';

// Authentication Check
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php?redirect=submit_content.php');
    exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate();

    $user_id = $_SESSION['user_id'];
    $type = $_POST['submission_type'] ?? '';
    $title = trim($_POST['title'] ?? '');
    $verse = trim($_POST['verse'] ?? '');
    $content = trim($_POST['content'] ?? '');
    
    $valid_types = ['Devotional', 'Testimony', 'Article', 'Music', 'Health'];
    
    if (empty($title) || empty($type) || !in_array($type, $valid_types)) {
        $error = 'Please provide a valid title and submission type.';
    } else {
        $pdf_path = null;
        $cover_path = null;
        $upload_ok = true;

        // Handle PDF Upload if provided
        if (isset($_FILES['pdf_file']) && $_FILES['pdf_file']['error'] === UPLOAD_ERR_OK) {
            $allowed_mime = ['application/pdf'];
            $file_info = finfo_open(FILEINFO_MIME_TYPE);
            $mime_type = finfo_file($file_info, $_FILES['pdf_file']['tmp_name']);
            finfo_close($file_info);

            if (!in_array($mime_type, $allowed_mime)) {
                $error = 'Only PDF files are allowed.';
                $upload_ok = false;
            } elseif ($_FILES['pdf_file']['size'] > 5000000) { // 5MB limit
                $error = 'File size must not exceed 5MB.';
                $upload_ok = false;
            } else {
                $upload_dir = __DIR__ . '/uploads/submissions/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }
                
                $ext = pathinfo($_FILES['pdf_file']['name'], PATHINFO_EXTENSION);
                $filename = uniqid('sub_') . '.' . $ext;
                $destination = $upload_dir . $filename;
                
                if (move_uploaded_file($_FILES['pdf_file']['tmp_name'], $destination)) {
                    $pdf_path = 'uploads/submissions/' . $filename;
                } else {
                    $error = 'Failed to upload the PDF file.';
                    $upload_ok = false;
                }
            }
        }

        // Handle Cover Image Upload if provided
        if ($upload_ok && isset($_FILES['cover_image']) && $_FILES['cover_image']['error'] === UPLOAD_ERR_OK) {
            try {
                $upload_dir = __DIR__ . '/uploads/submissions/';
                $uploaded_filename = secure_upload(
                    'cover_image',
                    $upload_dir,
                    ['image/jpeg', 'image/png', 'image/webp', 'image/gif'],
                    ['jpg', 'jpeg', 'png', 'webp', 'gif'],
                    2_097_152 // 2MB limit
                );
                if ($uploaded_filename) {
                    $cover_path = 'uploads/submissions/' . $uploaded_filename;
                }
            } catch (Exception $e) {
                $error = 'Cover image upload error: ' . $e->getMessage();
                $upload_ok = false;
            }
        }

        if ($upload_ok) {
            if (empty($content) && empty($pdf_path)) {
                $error = 'Please provide either written content or upload a PDF.';
            } else {
                try {
                    $db = getDB();
                    $stmt = $db->prepare('INSERT INTO user_submissions (user_id, submission_type, title, verse, content, pdf_file, cover_image, status) VALUES (?, ?, ?, ?, ?, ?, ?, "Pending")');
                    $stmt->execute([$user_id, $type, $title, $verse, $content, $pdf_path, $cover_path]);
                    $success = 'Your submission has been received and is pending review. Thank you!';
                } catch (Exception $e) {
                    error_log($e->getMessage());
                    $error = 'An error occurred while saving your submission. Please try again.';
                }
            }
        }
    }
}

$pageTitle = 'Submit Writing — Advent Library';
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/navbar.php';
?>

<div class="max-w-3xl mx-auto px-4 py-16">
    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-8">
        <h1 class="font-heading text-3xl font-bold text-primary mb-2">Submit Your Writing</h1>
        <p class="text-secondary mb-8">Share your devotionals, testimonies, or articles with the Advent Library community. All submissions are reviewed by our editorial team.</p>

        <?php if ($error): ?>
            <div class="bg-red-50 text-red-600 p-4 rounded-lg mb-6 border border-red-100">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="bg-green-50 text-green-700 p-4 rounded-lg mb-6 border border-green-100">
                <?= htmlspecialchars($success) ?>
            </div>
        <?php endif; ?>

        <form action="submit_content.php" method="POST" enctype="multipart/form-data" class="space-y-6">
            <?= csrf_field() ?>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <label class="block text-sm font-semibold text-primary mb-2">Submission Type</label>
                    <select name="submission_type" class="w-full p-3 border border-gray-200 rounded-lg focus:border-secondary focus:ring-1 focus:ring-secondary outline-none transition-colors" required>
                        <option value="">Select a type...</option>
                        <option value="Devotional">Daily Devotional</option>
                        <option value="Testimony">Personal Testimony</option>
                        <option value="Article">Christian Article</option>
                        <option value="Music">Music</option>
                        <option value="Health">Health</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-primary mb-2">Title</label>
                    <input type="text" name="title" class="w-full p-3 border border-gray-200 rounded-lg focus:border-secondary focus:ring-1 focus:ring-secondary outline-none transition-colors" placeholder="Enter the title" required>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <label class="block text-sm font-semibold text-primary mb-2">Key Scripture Verse (Optional)</label>
                    <input type="text" name="verse" class="w-full p-3 border border-gray-200 rounded-lg focus:border-secondary focus:ring-1 focus:ring-secondary outline-none transition-colors" placeholder="e.g. John 3:16">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-primary mb-2">Cover Image / Thumbnail (Optional)</label>
                    <input type="file" name="cover_image" accept="image/*" class="w-full p-2.5 border border-gray-200 rounded-lg focus:border-secondary outline-none file:mr-4 file:py-1.5 file:px-3 file:rounded-full file:border-0 file:text-xs file:font-semibold file:bg-orange-50 file:text-secondary hover:file:bg-orange-100">
                    <p class="text-[10px] text-gray-500 mt-1">Allowed formats: JPG, PNG, WEBP, GIF. Max: 2MB.</p>
                </div>
            </div>

            <div class="border-t border-gray-100 pt-6">
                <h3 class="font-semibold text-primary mb-4">Choose Submission Method</h3>
                
                <div class="flex gap-4 mb-6">
                    <label class="flex-1 cursor-pointer">
                        <input type="radio" name="method" value="text" class="peer sr-only" checked onchange="toggleMethod()">
                        <div class="p-4 border border-gray-200 rounded-lg text-center peer-checked:border-secondary peer-checked:bg-orange-50 transition-colors">
                            <i class="fa-solid fa-pen-nib text-2xl mb-2 text-primary"></i>
                            <div class="font-semibold text-sm">Write Content</div>
                        </div>
                    </label>
                    <label class="flex-1 cursor-pointer">
                        <input type="radio" name="method" value="pdf" class="peer sr-only" onchange="toggleMethod()">
                        <div class="p-4 border border-gray-200 rounded-lg text-center peer-checked:border-secondary peer-checked:bg-orange-50 transition-colors">
                            <i class="fa-solid fa-file-pdf text-2xl mb-2 text-primary"></i>
                            <div class="font-semibold text-sm">Upload PDF</div>
                        </div>
                    </label>
                </div>

                <div id="method-text" class="space-y-2">
                    <label class="block text-sm font-semibold text-primary">Content</label>
                    <textarea name="content" id="editor" class="w-full h-64 p-3 border border-gray-200 rounded-lg focus:border-secondary focus:ring-1 focus:ring-secondary outline-none" placeholder="Write your submission here..."></textarea>
                </div>

                <div id="method-pdf" class="space-y-2 hidden">
                    <label class="block text-sm font-semibold text-primary">Upload PDF Document</label>
                    <input type="file" name="pdf_file" accept=".pdf" class="w-full p-3 border border-gray-200 rounded-lg focus:border-secondary outline-none file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-orange-50 file:text-secondary hover:file:bg-orange-100">
                    <p class="text-xs text-gray-500 mt-1">Maximum file size: 5MB. Only PDF formats are accepted.</p>
                </div>
            </div>

            <div class="pt-6">
                <button type="submit" class="btn-primary w-full py-4 text-lg">
                    Submit for Review
                </button>
            </div>
        </form>
    </div>
</div>

<!-- TinyMCE Rich Text Editor -->$_COOKIE<script src="https://cdnjs.cloudflare.com/ajax/libs/tinymce/6.8.2/tinymce.min.js" referrerpolicy="origin"></script>
<script>
    tinymce.init({
        selector: '#editor',
        plugins: 'lists link image code wordcount fullscreen table charmap preview searchreplace visualblocks',
        toolbar: 'undo redo | blocks fontfamily fontsize | bold italic underline strikethrough | forecolor backcolor | alignleft aligncenter alignright alignjustify | bullist numlist outdent indent | table link image | scriptureblock hr charmap preview fullscreen code',
        menubar: false,
        branding: false,
        promotion: false,
        height: 450,
        skin: document.documentElement.classList.contains('dark') ? 'oxide-dark' : 'oxide',
        skin_url: document.documentElement.classList.contains('dark') 
            ? 'https://cdnjs.cloudflare.com/ajax/libs/tinymce/6.8.2/skins/ui/oxide-dark'
            : 'https://cdnjs.cloudflare.com/ajax/libs/tinymce/6.8.2/skins/ui/oxide',
        content_css: document.documentElement.classList.contains('dark')
            ? 'https://cdnjs.cloudflare.com/ajax/libs/tinymce/6.8.2/skins/content/dark/content.min.css'
            : 'https://cdnjs.cloudflare.com/ajax/libs/tinymce/6.8.2/skins/content/default/content.min.css',
        style_formats: [
            { title: 'Paragraph', format: 'p' },
            { title: 'Scripture Verse Block', block: 'blockquote', classes: 'scripture-block', wrapper: true },
            { title: 'Heading 1', format: 'h1' },
            { title: 'Heading 2', format: 'h2' },
            { title: 'Heading 3', format: 'h3' },
            { title: 'Heading 4', format: 'h4' },
            { title: 'Heading 5', format: 'h5' },
             title: 'Heading 6', format: 'h6'         ],
        content_style: `
            body  font-family: 'Inter', sans-serif; font-size: 14px; line-height: 1.6;             .scripture-block {
                border-left: 4px solid #D4A017 !important;
                padding-left: 1rem !important;
                margin: 1.5rem 0 !important;
                font-style: italic !important;
                color: #4B5563 !important;
                background-color: #F9FAFB !important;
                padding-top: 0.5rem !important;
                padding-bottom: 0.5rem !important;
            }
            .dark .scripture-block {
                color: #D1D5DB !important;
                background-color: #1F2937 !important;
            }
        `,
        images_upload_handler: (blobInfo, progress) => new Promise((resolve, reject) => {
            const xhr = new XMLHttpRequest();
            xhr.withCredentials = true;
            xhr.open('POST', 'api/upload_image.php');

            xhr.upload.onprogress = (e) => {
                progress(e.loaded / e.total * 100);
            };

            xhr.onload = () => {
                if (xhr.status === 403) {
                    reject({ message: 'CSRF validation failed or access denied', remove: true });
                    return;
                }
                if (xhr.status < 200 || xhr.status >= 300) {
                    reject('HTTP Error: ' + xhr.status);
                    return;
                }
                const json = JSON.parse(xhr.responseText);
                if (!json || typeof json.location !== 'string') {
                    reject('Invalid JSON response: ' + xhr.responseText);
                    return;
                }
                resolve(json.location);
            };

            xhr.onerror = () => {
                reject('Image upload failed due to network error.');
            };

            const formData = new FormData();
            formData.append('file', blobInfo.blob(), blobInfo.filename());
            formData.append('_csrf_token', '<?= csrf_token() ?>');
            xhr.send(formData);
        }),
        setup: function (editor) {
            editor.ui.registry.addButton('scriptureblock', {
                text: 'Scripture Block',
                icon: 'quote',
                tooltip: 'Format selection as a scripture verse block',
                onAction: function () {
                    editor.execCommand('mceToggleFormat', false, 'blockquote');
                    const node = editor.selection.getNode();
                    if (node.nodeName === 'BLOCKQUOTE') {
                        node.classList.add('scripture-block');
                    }
                }
            });
        }
    });

    function toggleMethod() {
        const method = document.querySelector('input[name="method"]:checked').value;
        const textSection = document.getElementById('method-text');
        const pdfSection = document.getElementById('method-pdf');
        
        if (method === 'text') {
            textSection.classList.remove('hidden');
            pdfSection.classList.add('hidden');
        } else {
            textSection.classList.add('hidden');
            pdfSection.classList.remove('hidden');
        }
    }
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
</submit_content>