<?phprequire_once __DIR__ . '/includes/db.php';
$pageTitle = "Terms of Use - Advent Library";
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/navbar.php';
?>

<div class="pt-32 pb-16 min-h-screen bg-surface" style="background: var(--c-surface); color: var(--c-text-primary);">
    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
        <h1 class="text-4xl font-bold font-heading mb-8" style="font-family: 'Playfair Display', serif; color: var(--c-text-primary);">Terms of Use</h1>
        
        <div class="prose prose-lg max-w-none" style="color: var(--c-text-secondary); line-height: 1.8;">
            <p class="mb-6">Last updated: <?php echo date('F d, Y'); ?></p>
            
            <h2 class="text-2xl font-semibold mt-8 mb-4" style="color: var(--c-text-primary);">1. Acceptance of Terms</h2>
            <p class="mb-4">By accessing and using Advent Library ("the Website"), you accept and agree to be bound by the terms and provision of this agreement. In addition, when using this Website's particular services, you shall be subject to any posted guidelines or rules applicable to such services.</p>
            
            <h2 class="text-2xl font-semibold mt-8 mb-4" style="color: var(--c-text-primary);">2. Description of Service</h2>
            <p class="mb-4">Advent Library provides users with access to a rich collection of spiritual resources, including sermons, devotionals, books, and community submissions. You understand and agree that the Service is provided "AS-IS" and that Advent Library assumes no responsibility for the timeliness, deletion, mis-delivery, or failure to store any user communications or personalization settings.</p>

            <h2 class="text-2xl font-semibold mt-8 mb-4" style="color: var(--c-text-primary);">3. User Conduct and Submissions</h2>
            <p class="mb-4">When using the Website and submitting content (including but not limited to devotionals, testimonies, articles, and poems), you agree not to:</p>
            <ul class="list-disc pl-6 mb-6 space-y-2">
                <li>Upload, post, or otherwise transmit any content that is unlawful, harmful, threatening, abusive, harassing, defamatory, vulgar, obscene, invasive of another's privacy, hateful, or racially, ethnically, or otherwise objectionable.</li>
                <li>Harm minors in any way.</li>
                <li>Impersonate any person or entity, or falsely state or otherwise misrepresent your affiliation with a person or entity.</li>
                <li>Upload, post, or otherwise transmit any content that infringes any patent, trademark, trade secret, copyright, or other proprietary rights of any party.</li>
            </ul>
            <p class="mb-4">Advent Library reserves the right (but not the obligation) in its sole discretion to refuse or remove any content that is available via the Service.</p>

            <h2 class="text-2xl font-semibold mt-8 mb-4" style="color: var(--c-text-primary);">4. Modifications to Service</h2>
            <p class="mb-4">Advent Library reserves the right at any time and from time to time to modify or discontinue, temporarily or permanently, the Service (or any part thereof) with or without notice. You agree that Advent Library shall not be liable to you or to any third party for any modification, suspension, or discontinuance of the Service.</p>

            <h2 class="text-2xl font-semibold mt-8 mb-4" style="color: var(--c-text-primary);">5. Disclaimer of Warranties</h2>
            <p class="mb-4">You expressly understand and agree that your use of the service is at your sole risk. The service is provided on an "AS IS" and "AS AVAILABLE" basis. Advent Library expressly disclaims all warranties of any kind, whether express or implied, including, but not limited to the implied warranties of merchantability, fitness for a particular purpose and non-infringement.</p>

            <h2 class="text-2xl font-semibold mt-8 mb-4" style="color: var(--c-text-primary);">6. Contact Information</h2>
            <p class="mb-4">If you have any questions regarding these Terms of Use, please contact us at <a href="mailto:info@adventlibrary.org" style="color: var(--c-accent-blue); text-decoration: underline;">info@adventlibrary.org</a>.</p>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
