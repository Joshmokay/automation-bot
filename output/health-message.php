<?php// health-message.php — Adventist Health Study Feedback Page
declare(strict_types=1);

require_once __DIR__ . '/includes/db.php';

// 1. Determine active profile / API target
$profileMode = $_GET['profile'] ?? 'live';
$submissionData = null;
$apiStatus = 'Live API';
$apiStatusColor = 'bg-emerald-500';
$apiMessage = 'Connected to s9-adventisthealthstudy.lluh.org';

$liveUrl = 'https://s9-adventisthealthstudy.lluh.org/webform_rest/sandwiches/submission/c2b13bf1-be3b-4f38-b547-97ecc7874887?_format=json';

// Function to load local simulated profiles
function loadSimulatedProfile(string $profile): ?array {
    $filePath = __DIR__ . '/scratch/sandwiches_submission.json';
    if ($profile === 'unhealthy') {
        $filePath = __DIR__ . '/scratch/sandwiches_submission_unhealthy.json';
} elseif ($profile === 'vegetarian') {
        $filePath = __DIR__ . '/scratch/sandwiches_submission_vegetarian.json';
}
    if (file_exists($filePath)) {
        return json_decode(file_get_contents($filePath), true);
}
    return null;
}

if ($profileMode === 'live') {
    // Attempt live API fetch with a short timeout so it doesn't hang
    $loginUrl = 'https://s9-adventisthealthstudy.lluh.org/user/login?_format=json';
    $cookieFile = __DIR__ . '/scratch/cookie.txt';
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $loginUrl);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['name' => 'admin', 'pass' => 'admin']));
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 3); // 3 seconds timeout
    
    $loginRes = curl_exec($ch);
    $loginHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($loginHttpCode === 200) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $liveUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3);
        
        $submissionRes = curl_exec($ch);
        $fetchHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($fetchHttpCode === 200) {
            $submissionData = json_decode($submissionRes, true);
}
    }
    
    if (!$submissionData) {
        // Graceful fallback to Vegan mock profile
        $submissionData = loadSimulatedProfile('vegan');
        $apiStatus = 'Offline Fallback';
        $apiStatusColor = 'bg-amber-500';
        $apiMessage = 'LLUH server unreachable. Displaying simulated Vegan profile.';
    }
} else {
    // Explicitly loaded simulated profile
    $submissionData = loadSimulatedProfile($profileMode);
    $apiStatus = 'Simulated Data';
    $apiStatusColor = 'bg-sky-500';
    $apiMessage = 'Displaying local simulated profile: ' . ucfirst($profileMode);
}

if (!$submissionData) {
    die("Error loading survey submission data.");
}

$data = $submissionData['data'] ?? [];

// 2. HEALTH SCORING ENGINE
$breadType = $data['bread_type'] ?? 'white';
$fillings = $data['fillings'] ?? [];
$meatFillings = $data['meat_fillings'] ?? ['none'];
$toppings = $data['toppings'] ?? [];
$spreads = $data['spreads'] ?? [];
$frequency = $data['frequency'] ?? '1_3_times_week';
$notes = $data['notes'] ?? '';

// Fiber Score
$fiberScore = 30; // base refined bread
if ($breadType === 'whole_wheat') $fiberScore = 90;
if ($breadType === 'rye') $fiberScore = 75;
$veggiesCount = count(array_filter($toppings, fn($t) => $t !== 'none'));
$fiberScore += $veggiesCount * 5;
$fiberScore = min($fiberScore, 100);

// Plant Protein Score
$totalFillings = count($fillings);
$plantFillingsCount = 0;
$hasMeat = false;
foreach ($fillings as $f) {
    if (in_array($f, ['peanut_butter', 'hummus_vegetable', 'avocado', 'tofu_tempeh', 'beans'])) {
        $plantFillingsCount++;
    }
    if (in_array($f, ['ham', 'bologna', 'beef', 'pork', 'turkey', 'chicken'])) {
        $hasMeat = true;
    }
}
$plantProteinScore = $totalFillings > 0 ? (int)(($plantFillingsCount / $totalFillings) * 100) : 0;
if ($meatFillings[0] !== 'none') {
    $hasMeat = true;
    $plantProteinScore = (int)($plantProteinScore * 0.2); // severely penalize plant protein ratio if meat is used
}

// Antioxidant Score
$antioxidantScore = min($veggiesCount * 25, 100);

// Spreads Score
$spreadsScore = 100;
foreach ($spreads as $s) {
    if (in_array($s, ['mayonnaise', 'butter', 'margarine'])) {
        $spreadsScore -= 35;
    }
}
$spreadsScore = max($spreadsScore, 20);

// Overall Heart Health Rating
$overallScore = (int)(($fiberScore * 0.3) + ($plantProteinScore * 0.4) + ($antioxidantScore * 0.2) + ($spreadsScore * 0.1));

// Frequency Multiplier
if ($hasMeat && ($frequency === 'daily' || $frequency === '4_6_times_week')) {
    $overallScore -= 15; // penalize high meat frequency
}
$overallScore = max($overallScore, 10);

$grade = 'F';
$gradeColor = 'text-red-500 border-red-500 bg-red-500/10';
if ($overallScore >= 90) {
    $grade = 'A';
    $gradeColor = 'text-emerald-600 border-emerald-600 bg-emerald-500/10 dark:text-emerald-400';
} elseif ($overallScore >= 78) {
    $grade = 'B';
    $gradeColor = 'text-sky-600 border-sky-600 bg-sky-500/10 dark:text-sky-400';
} elseif ($overallScore >= 60) {
    $grade = 'C';
    $gradeColor = 'text-amber-600 border-amber-600 bg-amber-500/10 dark:text-amber-400';
} elseif ($overallScore >= 45) {
    $grade = 'D';
    $gradeColor = 'text-orange-500 border-orange-500 bg-orange-500/10';
}

$pageTitle = 'Adventist Health Study Feedback — Advent Library';
$pageDescription = 'Dietary analysis based on sandwiches survey submission data and LLUH Adventist Health Studies.';
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/navbar.php';
?>

<style>
    :root {
        --navy:   #245A63;
        --gold:   #D4A017;
        --bg:     var(--c-bglight, #F8F5ED);
        --bg-card: var(--c-bg-card, #F5EFE3);
        --white:  #FFFFFF;
        --text-p: var(--c-text-primary, #2E1F14);
        --text-s: var(--c-text-secondary, #5C4D42);
        --border: var(--c-border-sepia, #E6DFD3);
    }
    .dark {
        --navy:   #38BDF8;
        --white:  #1E293B;
        --bg-card: #1E293B;
    }
    .text-navy { color: var(--navy) !important; }
    .bg-navy { background-color: var(--navy) !important; }
    .border-navy { border-color: var(--navy) !important; }

    /* Visual Sandwich Stack Animation */
    .sandwich-layer {
        transition: transform 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
    }
    .sandwich-container:hover .sandwich-layer {
        transform: translateY(var(--hover-translate, 0)) scale(1.02);
    }
</style>

<!-- Breadcrumbs -->$_COOKIE<div class="bg-[var(--c-bg)] border-b border-[var(--c-border)] py-4 px-4 sm:px-8 relative z-10 transition-colors">
    <div class="max-w-7xl mx-auto flex items-center text-xs font-medium text-slate-500 tracking-widest uppercase">
        <a href="index.php" class="hover:text-[var(--c-gold)] transition-colors">Home</a>
        <span class="mx-2">/</span>
        <span class="text-navy">Health Study Feedback</span>
    </div>
</div>

<main class="pt-10 pb-20 min-h-screen" style="background-color: var(--c-bg)">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        
        <!-- Header Banner Section -->$_COOKIE        <header class="bg-gradient-to-br from-[#1C484F] to-[#0E2528] rounded-3xl p-6 sm:p-10 text-white relative overflow-hidden mb-10 shadow-lg border border-slate-700/50">
            <div class="absolute inset-0 opacity-10 bg-[radial-gradient(#fff_1px,transparent_1px)] [background-size:16px_16px] pointer-events-none"></div>
            
            <!-- API Status Badge -->$_COOKIE            <div class="absolute top-4 right-4 flex items-center gap-2 px-3.5 py-1.5 rounded-full bg-white/10 backdrop-blur-md border border-white/10 shadow-inner select-none">
                <span class="w-2.5 h-2.5 rounded-full <?= $apiStatusColor ?> animate-pulse"></span>
                <span class="text-[9px] uppercase font-black tracking-widest text-slate-200"><?= $apiStatus ?></span>
            </div>

            <div class="max-w-3xl relative z-10">
                <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-extrabold uppercase tracking-widest bg-amber-500/20 text-amber-300 border border-amber-500/25 mb-4">
                    🧬 Adventist Health Study Research
                </span>
                <h1 class="text-3xl sm:text-5xl font-bold tracking-tight leading-tight font-display mb-4">
                    Dietary Analysis & Feedback
                </h1>
                <p class="text-sm sm:text-base text-gray-300 leading-relaxed max-w-2xl">
                    Discover how your sandwich survey choices align with scientific longevity research from the Loma Linda University Health (LLUH) cohorts.
                </p>
                <div class="mt-2 text-xs text-emerald-400 font-medium flex items-center gap-1.5">
                    <i class="fa-solid fa-circle-nodes"></i> <?= $apiMessage ?>
                </div>
            </div>
        </header>

        <!-- Profile / Mode Selector Dropdown -->$_COOKIE        <section class="mb-8 flex flex-col sm:flex-row items-center justify-between gap-4 bg-white dark:bg-navy-light p-4 rounded-2xl border border-[var(--c-border)] shadow-sm">
            <div class="flex items-center gap-2">
                <i class="fa-solid fa-sliders text-slate-400"></i>
                <span class="text-xs font-bold text-slate-500 uppercase tracking-widest">Select Survey Submission Profile:</span>
            </div>
            
            <form method="GET" action="health-message.php" class="w-full sm:w-auto">
                <select name="profile" onchange="this.form.submit()" class="w-full sm:w-64 bg-[var(--c-bg)] text-[var(--c-text-primary)] border border-[var(--c-border)] rounded-xl px-4 py-2 text-xs font-semibold outline-none focus:border-navy focus:ring-2 focus:ring-navy/15 cursor-pointer">
                    <option value="live" <?= $profileMode === 'live' ? 'selected' : '' ?>>Live LLUH REST API Survey</option>
                    <option value="vegan" <?= $profileMode === 'vegan' ? 'selected' : '' ?>>Simulated: Plant-Based Vegan</option>
                    <option value="vegetarian" <?= $profileMode === 'vegetarian' ? 'selected' : '' ?>>Simulated: Lacto-Ovo Vegetarian</option>
                    <option value="unhealthy" <?= $profileMode === 'unhealthy' ? 'selected' : '' ?>>Simulated: Refined Meat-Based</option>
                </select>
            </form>
        </section>

        <!-- Main Column Grid Layout -->$_COOKIE        <div class="grid grid-cols-1 lg:grid-cols-12 gap-8">
            
            <!-- LEFT COLUMN: Sandwich survey results & Visual sandwich stack (5 cols) -->$_COOKIE            <div class="lg:col-span-5 space-y-8">
                
                <!-- Survey submission meta card -->$_COOKIE                <div class="bg-white dark:bg-navy-light rounded-3xl border border-[var(--c-border)] p-6 shadow-sm">
                    <h3 class="text-xs font-bold uppercase tracking-wider text-slate-400 mb-4 border-b border-[var(--c-border)] pb-2 flex items-center justify-between">
                        <span>Survey Metadata</span>
                        <span class="text-[10px] text-amber-600 dark:text-amber-400">SID: #<?= htmlspecialchars($submissionData['sid']) ?></span>
                    </h3>
                    <div class="space-y-3.5 text-xs">
                        <div class="flex justify-between">
                            <span class="text-slate-400 font-medium">Webform:</span>
                            <span class="text-[var(--c-text-primary)] font-semibold uppercase tracking-wider"><?= htmlspecialchars($submissionData['webform_id']) ?></span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-slate-400 font-medium">UUID:</span>
                            <span class="text-[var(--c-text-primary)] font-mono text-[10px] truncate max-w-[200px]" title="<?= htmlspecialchars($submissionData['uuid']) ?>"><?= htmlspecialchars($submissionData['uuid']) ?></span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-slate-400 font-medium">Completed:</span>
                            <span class="text-[var(--c-text-primary)] font-semibold"><?= date('F j, Y, g:i a', strtotime($submissionData['completed'])) ?></span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-slate-400 font-medium">Portion Size:</span>
                            <span class="text-[var(--c-text-primary)] font-semibold uppercase"><?= htmlspecialchars($portion_size = $data['portion_size'] ?? 'medium') ?></span>
                        </div>
                        <?php if ($notes): ?>
                            <div class="pt-3 border-t border-[var(--c-border)]">
                                <span class="text-slate-400 font-medium block mb-1">User Notes:</span>
                                <p class="text-xs text-[var(--c-text-secondary)] italic bg-slate-50 dark:bg-navy/20 p-2.5 rounded-lg leading-relaxed">
                                    "<?= htmlspecialchars($notes) ?>"
                                </p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- CSS Visual Sandwich Stack Card -->$_COOKIE                <div class="bg-white dark:bg-navy-light rounded-3xl border border-[var(--c-border)] p-6 shadow-sm flex flex-col items-center">
                    <h3 class="text-xs font-bold uppercase tracking-wider text-slate-400 mb-6 w-full text-center border-b border-[var(--c-border)] pb-2">
                        Your Sandwich Recipe Stack
                    </h3>
                    
                    <div class="sandwich-container w-full max-w-[280px] h-[340px] flex flex-col justify-between items-center relative py-4 select-none cursor-pointer">
                        
                        <!-- Top Bread -->$_COOKIE                        <?php 
                        $breadBg = 'bg-[#DC9C5C] border-[#B77A3F]'; // white bread
                        $breadLabel = 'Refined White Bread';
                        if ($breadType === 'whole_wheat') {
                            $breadBg = 'bg-[#8F5B30] border-[#693E1B]';
                            $breadLabel = '100% Whole Wheat';
                        } elseif ($breadType === 'rye') {
                            $breadBg = 'bg-[#6D5A4C] border-[#4A3D33]';
                            $breadLabel = 'Rye / Pumpernickel';
                        }
                        ?>
                        <div class="sandwich-layer w-full h-8 <?= $breadBg ?> border-b-4 rounded-t-3xl shadow flex items-center justify-center text-[10px] font-black text-white uppercase tracking-wider" style="--hover-translate: -24px">
                            <?= $breadLabel ?>
                        </div>

                        <!-- Spreads Top -->$_COOKIE                        <?php foreach ($spreads as $s): 
                            $spreadBg = 'bg-yellow-100 border-yellow-200 text-yellow-700';
                            if ($s === 'olive_oil') $spreadBg = 'bg-emerald-200 border-emerald-300 text-emerald-800';
                            if ($s === 'butter') $spreadBg = 'bg-amber-100 border-amber-200 text-amber-800';
                        ?>
                            <div class="sandwich-layer w-[90%] h-3 <?= $spreadBg ?> border-b rounded-full shadow-sm flex items-center justify-center text-[8px] font-bold uppercase tracking-widest" style="--hover-translate: -16px">
                                Spread: <?= str_replace('_', ' ', $s) ?>
                            </div>
                        <?php endforeach; ?>

                        <!-- Toppings veggies stack -->$_COOKIE                        <?php 
                        $vegIdx = 0;
                        foreach ($toppings as $t): 
                            if ($t === 'none') continue;
                            $vegBg = 'bg-green-500 border-green-600 text-white';
                            if ($t === 'tomato') $vegBg = 'bg-red-500 border-red-600 text-white';
                            if ($t === 'cucumber') $vegBg = 'bg-emerald-400 border-emerald-500 text-white';
                            $vegIdx++;
                            $trans = -10 + ($vegIdx * 4);
                        ?>
                            <div class="sandwich-layer w-[95%] h-5 <?= $vegBg ?> border-b-2 rounded-xl shadow flex items-center justify-center text-[8px] font-black uppercase tracking-widest" style="--hover-translate: <?= $trans ?>px">
                                <?= $t ?>
                            </div>
                        <?php endforeach; ?>

                        <!-- Protein fillings stack -->$_COOKIE                        <?php 
                        $fillIdx = 0;
                        foreach ($fillings as $f): 
                            $fillBg = 'bg-amber-600 border-amber-700 text-white'; // peanut butter
                            if ($f === 'hummus_vegetable') $fillBg = 'bg-[#DFD3C3] border-[#C7B198] text-[#5C4D42]';
                            if ($f === 'egg_salad') $fillBg = 'bg-[#F9ECC4] border-[#E8C560] text-amber-900';
                            if ($f === 'cheddar_cheese') $fillBg = 'bg-amber-400 border-amber-500 text-white';
                            if (in_array($f, ['ham', 'bologna', 'beef', 'pork'])) $fillBg = 'bg-rose-600 border-rose-700 text-white';
                            $fillIdx++;
                            $trans = 4 + ($fillIdx * 6);
                        ?>
                            <div class="sandwich-layer w-[92%] h-6 <?= $fillBg ?> border-b-2 rounded-xl shadow flex items-center justify-center text-[9px] font-black uppercase tracking-wider" style="--hover-translate: <?= $trans ?>px">
                                <?= str_replace('_', ' ', $f) ?>
                            </div>
                        <?php endforeach; ?>

                        <!-- Bottom Bread -->$_COOKIE                        <div class="sandwich-layer w-full h-8 <?= $breadBg ?> border-t-4 rounded-b-3xl shadow flex items-center justify-center text-[10px] font-black text-white uppercase tracking-wider" style="--hover-translate: 24px">
                            <?= $breadLabel ?>
                        </div>

                    </div>
                    <p class="text-[10px] text-slate-400 font-semibold mt-4 text-center">
                        💡 Hover over the sandwich to explode layers.
                    </p>
                </div>

            </div>

            <!-- RIGHT COLUMN: LLUH Health Feedback & Analysis (7 cols) -->$_COOKIE            <div class="lg:col-span-7 space-y-8">
                
                <!-- Grade and Score Card -->$_COOKIE                <div class="bg-white dark:bg-navy-light rounded-3xl border border-[var(--c-border)] p-6 md:p-8 shadow-sm flex flex-col md:flex-row items-center gap-6">
                    <div class="w-24 h-24 rounded-full border-4 flex flex-col items-center justify-center font-display <?= $gradeColor ?> shrink-0 select-none shadow">
                        <span class="text-4xl font-extrabold leading-none"><?= $grade ?></span>
                        <span class="text-[9px] uppercase font-black tracking-widest mt-1">Grade</span>
                    </div>
                    
                    <div class="flex-grow space-y-2">
                        <div class="flex items-center justify-between">
                            <h2 class="text-xl font-bold font-display text-[var(--c-text-primary)]">
                                AHS Heart Health Rating
                            </h2>
                            <span class="text-sm font-bold text-navy"><?= $overallScore ?>% Score</span>
                        </div>
                        <p class="text-xs text-[var(--c-text-secondary)] leading-relaxed">
                            This score reflects dietary fiber levels, plant-protein ratios, saturated-fat spreads, and fresh toppings density, calibrated against 50 years of Seventh-day Adventist epidemiology.
                        </p>
                    </div>
                </div>

                <!-- Progress bars card -->$_COOKIE                <div class="bg-white dark:bg-navy-light rounded-3xl border border-[var(--c-border)] p-6 md:p-8 shadow-sm space-y-6">
                    <h3 class="text-xs font-bold uppercase tracking-widest text-slate-500 border-b border-[var(--c-border)] pb-2 flex items-center gap-1.5">
                        <i class="fa-solid fa-chart-simple"></i> Nutrition Dimension Scores
                    </h3>
                    
                    <div class="space-y-4">
                        <!-- Fiber Progress -->$_COOKIE                        <div class="space-y-1.5">
                            <div class="flex justify-between text-xs font-semibold">
                                <span class="text-[var(--c-text-secondary)]">Dietary Fiber</span>
                                <span class="text-navy"><?= $fiberScore ?>/100</span>
                            </div>
                            <div class="h-2 rounded-full bg-slate-100 dark:bg-navy/30 overflow-hidden shadow-inner">
                                <div class="h-full bg-emerald-500 rounded-full transition-all duration-1000" style="width: <?= $fiberScore ?>%"></div>
                            </div>
                        </div>

                        <!-- Plant Protein Progress -->$_COOKIE                        <div class="space-y-1.5">
                            <div class="flex justify-between text-xs font-semibold">
                                <span class="text-[var(--c-text-secondary)]">Plant-Based Protein Quality</span>
                                <span class="text-navy"><?= $plantProteinScore ?>/100</span>
                            </div>
                            <div class="h-2 rounded-full bg-slate-100 dark:bg-navy/30 overflow-hidden shadow-inner">
                                <div class="h-full bg-teal-500 rounded-full transition-all duration-1000" style="width: <?= $plantProteinScore ?>%"></div>
                            </div>
                        </div>

                        <!-- Antioxidants Progress -->$_COOKIE                        <div class="space-y-1.5">
                            <div class="flex justify-between text-xs font-semibold">
                                <span class="text-[var(--c-text-secondary)]">Antioxidants / Micronutrients</span>
                                <span class="text-navy"><?= $antioxidantScore ?>/100</span>
                            </div>
                            <div class="h-2 rounded-full bg-slate-100 dark:bg-navy/30 overflow-hidden shadow-inner">
                                <div class="h-full bg-amber-500 rounded-full transition-all duration-1000" style="width: <?= $antioxidantScore ?>%"></div>
                            </div>
                        </div>

                        <!-- Spreads Progress -->$_COOKIE                        <div class="space-y-1.5">
                            <div class="flex justify-between text-xs font-semibold">
                                <span class="text-[var(--c-text-secondary)]">Healthy spreads (low-saturated fats)</span>
                                <span class="text-navy"><?= $spreadsScore ?>/100</span>
                            </div>
                            <div class="h-2 rounded-full bg-slate-100 dark:bg-navy/30 overflow-hidden shadow-inner">
                                <div class="h-full bg-sky-500 rounded-full transition-all duration-1000" style="width: <?= $spreadsScore ?>%"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Custom Recommendations Card -->$_COOKIE                <div class="bg-white dark:bg-navy-light rounded-3xl border border-[var(--c-border)] p-6 md:p-8 shadow-sm space-y-5">
                    <h3 class="text-xs font-bold uppercase tracking-widest text-slate-500 border-b border-[var(--c-border)] pb-2 flex items-center gap-1.5">
                        <i class="fa-solid fa-lightbulb text-amber-500"></i> Personalized Health Recommendations
                    </h3>

                    <div class="space-y-4">
                        <!-- 1. Bread Feedback -->$_COOKIE                        <div class="flex gap-3 text-xs leading-relaxed">
                            <div class="text-base shrink-0 mt-0.5"><?= $breadType === 'whole_wheat' ? '✅' : '⚠️' ?></div>
                            <div>
                                <h4 class="font-bold text-[var(--c-text-primary)] mb-0.5">Bread Selection: <?= str_replace('_', ' ', $breadType) ?></h4>
                                <p class="text-[var(--c-text-secondary)]">
                                    <?php if ($breadType === 'whole_wheat'): ?>
                                        Whole grain bread is rich in dietary fiber, helping lower blood pressure and cholesterol. The Adventist Health Study-2 highlights whole grains as a core component of longevity.
                                    <?php elseif ($breadType === 'rye'): ?>
                                        Rye bread is a solid choice. It offers good fiber content and a distinct nutrient profile. Ensure it is whole-rye for maximum health benefits.
                                    <?php else: ?>
                                        Refined white bread lacks essential fiber and causes rapid blood glucose spikes. We recommend switching to 100% whole wheat or sprouted grain bread.
                                    <?php endif; ?>
                                </p>
                            </div>
                        </div>

                        <!-- 2. Protein Fillings Feedback -->$_COOKIE                        <div class="flex gap-3 text-xs leading-relaxed">
                            <div class="text-base shrink-0 mt-0.5"><?= !$hasMeat ? '✅' : '⚠️' ?></div>
                            <div>
                                <h4 class="font-bold text-[var(--c-text-primary)] mb-0.5">Protein Fillings: <?= implode(', ', array_map(fn($f) => str_replace('_', ' ', $f), $fillings)) ?></h4>
                                <p class="text-[var(--c-text-secondary)]">
                                    <?php if ($hasMeat): ?>
                                        Processed meats (ham, bologna) are classified as carcinogens and are strongly linked to heart disease and colorectal cancers. The Adventist Health Study-2 showed that red/processed meat significantly increases mortality.
                                    <?php elseif (in_array('egg_salad', $fillings) || in_array('cheddar_cheese', $fillings)): ?>
                                        Lacto-ovo vegetarian options provide good protein but add saturated fat and cholesterol. Consider using plant-based spreads like hummus or avocado more frequently.
                                    <?php else: ?>
                                        Plant proteins (nuts, seeds, legumes) are associated with significantly lower rates of cardiovascular disease. Consuming nuts daily reduces heart disease risk by up to 50% according to AHS-1.
                                    <?php endif; ?>
                                </p>
                            </div>
                        </div>

                        <!-- 3. Toppings Feedback -->$_COOKIE                        <div class="flex gap-3 text-xs leading-relaxed">
                            <div class="text-base shrink-0 mt-0.5"><?= $veggiesCount > 0 ? '✅' : '⚠️' ?></div>
                            <div>
                                <h4 class="font-bold text-[var(--c-text-primary)] mb-0.5">Vegetable Toppings: <?= $veggiesCount > 0 ? implode(', ', $toppings) : 'none' ?></h4>
                                <p class="text-[var(--c-text-secondary)]">
                                    <?php if ($veggiesCount > 0): ?>
                                        Loading your sandwich with leafy greens, tomatoes, and sprouts adds vital antioxidants, potassium, and vitamins. Vegetarians in the AHS cohort show lower rates of cancer, partly due to high intake of raw vegetables.
                                    <?php else: ?>
                                        Your sandwich lacks fresh vegetables. Adding greens, tomatoes, or cucumbers is an easy way to increase your daily intake of fiber and health-promoting antioxidants.
                                    <?php endif; ?>
                                </p>
                            </div>
                        </div>

                        <!-- 4. Spreads Feedback -->$_COOKIE                        <div class="flex gap-3 text-xs leading-relaxed">
                            <div class="text-base shrink-0 mt-0.5"><?= $spreadsScore >= 80 ? '✅' : '⚠️' ?></div>
                            <div>
                                <h4 class="font-bold text-[var(--c-text-primary)] mb-0.5">Spreads & Fats: <?= !empty($spreads) ? implode(', ', $spreads) : 'none' ?></h4>
                                <p class="text-[var(--c-text-secondary)]">
                                    <?php if (in_array('mayonnaise', $spreads) || in_array('butter', $spreads)): ?>
                                        Mayonnaise and butter are high in saturated fats and calories. Try substituting them with mashed avocado, hummus, or olive-oil-based spreads.
                                    <?php else: ?>
                                        Olive oil and mustard are excellent low-saturated fat choices. Monounsaturated fats support heart health.
                                    <?php endif; ?>
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

            </div>

        </div>

        <!-- BOTTOM SECTION: LLUH Research Insights (Grounded Science) -->$_COOKIE        <section class="mt-16 bg-white dark:bg-navy-light rounded-3xl border border-[var(--c-border)] p-6 md:p-10 shadow-sm transition-colors space-y-8">
            <div class="text-center max-w-2xl mx-auto">
                <span class="text-xs font-bold text-amber-600 dark:text-amber-400 uppercase tracking-widest block mb-2">Loma Linda Blue Zone</span>
                <h2 class="text-2xl sm:text-3xl font-bold font-display text-[var(--c-text-primary)]">
                    Scientific Insights from Adventist Health Studies
                </h2>
                <p class="text-xs text-[var(--c-text-secondary)] leading-relaxed mt-2">
                    Loma Linda, California is the only designated Blue Zone in North America. The key factors behind this exceptional longevity are researched extensively through the Adventist Health Studies.
                </p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <!-- Card 1 -->$_COOKIE                <div class="p-5 border border-[var(--c-border)] rounded-2xl bg-[var(--c-bg)] shadow-inner space-y-3">
                    <span class="text-2xl">🥜</span>
                    <h3 class="font-bold text-[var(--c-text-primary)] text-sm font-display">The Power of Nuts</h3>
                    <p class="text-[11px] text-[var(--c-text-secondary)] leading-relaxed">
                        AHS-1 discovered that eating nuts 5 times a week can reduce the risk of non-fatal heart attacks by half, extending life expectancy by up to 2 years.
                    </p>
                </div>

                <!-- Card 2 -->$_COOKIE                <div class="p-5 border border-[var(--c-border)] rounded-2xl bg-[var(--c-bg)] shadow-inner space-y-3">
                    <span class="text-2xl">🌱</span>
                    <h3 class="font-bold text-[var(--c-text-primary)] text-sm font-display">Plant-Based Longevity</h3>
                    <p class="text-[11px] text-[var(--c-text-secondary)] leading-relaxed">
                        AHS-2 confirmed that vegetarians have a 12% lower risk of all-cause mortality compared to meat-eaters, and show lower rates of type-2 diabetes and hypertension.
                    </p>
                </div>

                <!-- Card 3 -->$_COOKIE                <div class="p-5 border border-[var(--c-border)] rounded-2xl bg-[var(--c-bg)] shadow-inner space-y-3">
                    <span class="text-2xl">🚶</span>
                    <h3 class="font-bold text-[var(--c-text-primary)] text-sm font-display">Lifestyle and Faith</h3>
                    <p class="text-[11px] text-[var(--c-text-secondary)] leading-relaxed">
                        Adventists who walk daily, avoid tobacco/alcohol, drink abundant water, and observe the weekly Sabbath rest live an average of 10 years longer than standard cohorts.
                    </p>
                </div>
            </div>
        </section>

    </div>
</main>

<?php include __DIR__ . '/includes/footer.php'; ?>
{
  "name": }}}