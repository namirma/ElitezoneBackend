<?php

/**
 * پاریتیِ معناییِ دو شاخه — «فرقشان فقط همان‌هایی است که باید باشد؟»
 *
 * دو شاخهٔ این پروژه (لاراول ۱۰ و لاراول ۱۳) عمداً هم‌زمان زنده‌اند. بخشی از
 * تفاوتشان **اجباری** است (شکلِ خودِ فریم‌ورک، نسخهٔ PHP، فرمِ مورد نیازِ larastan)
 * و بخشی هم صرفاً **سبک** است (هویستِ importها، داک‌بلاکِ ide-helper). هیچ‌کدام
 * از این دو نباید با یک واگراییِ واقعی — یک فیکس که فقط روی یک شاخه نشسته —
 * اشتباه گرفته شود؛ و دقیقاً همین اتفاق افتاده بود.
 *
 * این اسکریپت هر فایل PHP را «نرمال» می‌کند (کامنت‌ها، importها، فضای سفید و
 * نامِ کاملِ کلاس‌ها حذف/یکسان می‌شوند) و بعد مقایسه می‌کند. چیزی که بعد از آن
 * باقی بماند، تفاوتِ **معنایی** است.
 *
 * استفاده:
 *   php scripts/branch-parity.php redesign/order-flow-l10 redesign/order-flow-l13
 *
 * هر آرگومان می‌تواند یک ref گیت باشد یا مسیرِ یک worktree — تا بتوان دو درختِ
 * کاری را **پیش از** کامیت هم سنجید:
 *   php scripts/branch-parity.php ../api .
 *
 * خروج با کد ۱ یعنی فایلی معنایی فرق دارد که در allowlist نیست.
 * فهرستِ مجاز: docs/branch-parity-allowlist.txt
 */
if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}

$refA = $argv[1] ?? null;
$refB = $argv[2] ?? null;

if ($refA === null || $refB === null) {
    fwrite(STDERR, "usage: php scripts/branch-parity.php <refA> <refB>\n");
    exit(2);
}

$root = dirname(__DIR__);
$allowlistPath = $root.'/docs/branch-parity-allowlist.txt';

/** @return array<string, string> path => reason */
function loadAllowlist(string $path): array
{
    // ⚠️ قبلاً اینجا `return []` بود — بی‌صدا. مسیر نسبت به **جای خودِ اسکریپت**
    // حساب می‌شود (`dirname(__DIR__)`)، نه جایی که کاربر ایستاده؛ پس اجرای یک
    // کپیِ اسکریپت از بیرونِ مخزن، allowlist را پیدا نمی‌کرد و **هر ۲۱ موردِ
    // مجاز را «غیرمنتظره» می‌شمرد**: یک قرمزِ کاذبِ کامل، بدون هیچ نشانه‌ای.
    // یک نفر واقعاً روی همین گزارش داد که گیت قرمز است.
    if (! is_file($path)) {
        fwrite(STDERR, "branch-parity: فهرستِ مجاز پیدا نشد: {$path}\n");
        fwrite(STDERR, "  مسیر نسبت به محلِ خودِ اسکریپت حساب می‌شود؛ اسکریپت را از داخلِ مخزن اجرا کنید.\n");
        exit(2);
    }

    $out = [];

    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);

        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        [$file, $reason] = array_pad(explode('|', $line, 2), 2, '');
        $out[trim($file)] = trim($reason);
    }

    return $out;
}

function git(string ...$args): string
{
    $cmd = 'git '.implode(' ', array_map('escapeshellarg', $args));
    $out = shell_exec($cmd.' 2>&1');

    return $out === null ? '' : $out;
}

/**
 * سورس را به شکلی درمی‌آورد که فقط «معنا» بماند:
 *   • کامنت و داک‌بلاک حذف
 *   • `use X\Y;`ها حذف (چون هویست‌کردنِ import معنا را عوض نمی‌کند)
 *   • `A\B\C` به `C` کوتاه می‌شود (تا FQNِ درون‌خطی با importِ هویست‌شده یکی شود)
 *   • فضای سفید یکدست
 */
function normalize(string $code): string
{
    $tokens = @token_get_all($code);
    $pieces = [];
    $depth = 0;
    $skippingImport = false;

    foreach ($tokens as $token) {
        if (is_string($token)) {
            if ($token === '{') {
                $depth++;
            } elseif ($token === '}') {
                $depth--;
            }

            if ($skippingImport) {
                if ($token === ';') {
                    $skippingImport = false;
                }

                continue;
            }

            $pieces[] = $token;

            continue;
        }

        [$id, $text] = $token;

        if ($id === T_COMMENT || $id === T_DOC_COMMENT || $id === T_WHITESPACE) {
            continue;
        }

        if ($skippingImport) {
            continue;
        }

        // `use` در سطحِ فایل یعنی import؛ داخلِ کلاس یعنی trait و داخلِ امضا یعنی closure.
        if ($id === T_USE && $depth === 0) {
            $skippingImport = true;

            continue;
        }

        if ($id === T_NAME_QUALIFIED || $id === T_NAME_FULLY_QUALIFIED || $id === T_NAME_RELATIVE) {
            $parts = explode('\\', $text);
            $text = (string) end($parts);
        }

        $pieces[] = $text;
    }

    return implode(' ', $pieces);
}

$allowlist = loadAllowlist($allowlistPath);

$aIsTree = is_dir($refA);
$bIsTree = is_dir($refB);

/** فایل را از یک ref گیت یا از یک worktree می‌خواند. */
$read = static function (string $ref, bool $isTree, string $file): string {
    if (! $isTree) {
        return git('show', $ref.':'.$file);
    }

    $path = rtrim($ref, '/\\').'/'.$file;

    return is_file($path) ? (string) file_get_contents($path) : '';
};

/** @return list<string> */
$listPhp = static function (string $ref, bool $isTree): array {
    if (! $isTree) {
        $out = git('ls-tree', '-r', '--name-only', $ref);
    } else {
        $out = shell_exec('git -C '.escapeshellarg($ref).' ls-files 2>&1') ?? '';
    }

    return array_values(array_filter(
        explode("\n", str_replace("\r", '', $out)),
        static fn (string $l): bool => $l !== '' && str_ends_with($l, '.php')
    ));
};

$filesA = $listPhp($refA, $aIsTree);
$filesB = $listPhp($refB, $bIsTree);

// ⚠️ گاردِ قرینه‌ی بالا، در جهتِ مخالف. `git ls-tree` روی یک refِ **ناموجود**
// خطا نمی‌دهد؛ رشتهٔ خالی برمی‌گرداند. پس اسکریپت «۰ فایلِ مشترک» می‌شمرد،
// هیچ تفاوتی پیدا نمی‌کرد، و **سبز با exit 0** تمام می‌شد — یعنی گیت در حالتِ
// ورودیِ غلط، عبورِ کاذب می‌داد. نامِ شاخهٔ محلی که فقط `origin/…`ـش موجود است
// (مثل `redesign/order-flow-l13` روی یک چک‌اوتِ تازه) دقیقاً همین را می‌کند.
//
// این خطرناک‌ترین حالتِ ممکن برای یک گیت است: تنها محافظِ واگراییِ دو شاخه،
// وقتی ورودی‌اش را نمی‌بیند، «سالم است» اعلام می‌کند.
foreach ([[$refA, $filesA], [$refB, $filesB]] as [$ref, $files]) {
    if ($files !== []) {
        continue;
    }

    fwrite(STDERR, "branch-parity: «{$ref}» هیچ فایلِ PHPی نداد.\n");
    fwrite(STDERR, "  یعنی این ref وجود ندارد یا این مسیر یک worktree نیست — نه اینکه تفاوتی نباشد.\n");
    fwrite(STDERR, "  اگر نامِ شاخه است، شاید فقط نسخهٔ origin‌ش را دارید: origin/{$ref}\n");
    exit(2);
}

$changed = array_values(array_intersect($filesA, $filesB));

$onlyA = array_values(array_diff($filesA, $filesB));
$onlyB = array_values(array_diff($filesB, $filesA));

$semantic = [];
$cosmetic = 0;
$same = 0;

foreach ($changed as $file) {
    $a = $read($refA, $aIsTree, $file);
    $b = $read($refB, $bIsTree, $file);

    if ($a === $b) {
        $same++;

        continue;
    }

    if (normalize($a) === normalize($b)) {
        $cosmetic++;

        continue;
    }

    $semantic[] = $file;
}

$unexpected = array_values(array_filter($semantic, static fn (string $f): bool => ! isset($allowlist[$f])));
$expected = array_values(array_filter($semantic, static fn (string $f): bool => isset($allowlist[$f])));

printf("%s ↔ %s\n", $refA, $refB);
// ورودیِ سوم را هم صریح می‌کنیم. هر دو گاردِ بالا از این درس آمده‌اند که یک
// ورودیِ خوانده‌نشده بی‌صدا به نتیجهٔ غلط تبدیل می‌شود؛ پس خودِ خروجی باید
// بگوید چه چیزی خوانده شد، نه فقط چه چیزی نتیجه شد.
printf("فهرستِ مجاز: %s (%d ورودی)\n\n", $allowlistPath, count($allowlist));
printf("  فایل‌های PHPِ مشترک: %d\n", count($changed));
printf("  بایت‌به‌بایت یکسان: %d\n", $same);
printf("  فقط سبک (import/داک‌بلاک/فاصله): %d\n", $cosmetic);
printf("  فقط روی %s: %d   |   فقط روی %s: %d\n", $refA, count($onlyA), $refB, count($onlyB));
printf("  تفاوتِ معناییِ **مجاز** (allowlist): %d\n", count($expected));
printf("  تفاوتِ معناییِ **غیرمنتظره**: %d\n\n", count($unexpected));

foreach ($expected as $file) {
    printf("  ✓ %s\n      %s\n", $file, $allowlist[$file]);
}

if ($unexpected !== []) {
    echo "\n  ✗ این فایل‌ها معنا فرق دارند و در allowlist نیستند:\n";

    foreach ($unexpected as $file) {
        printf("      %s\n", $file);
    }

    echo "\n  یا فیکس را روی شاخهٔ عقب‌مانده پورت کنید، یا اگر تفاوت واقعاً اجباری است\n";
    echo "  با دلیلش به docs/branch-parity-allowlist.txt اضافه‌اش کنید.\n";

    exit(1);
}

echo "\n  هیچ واگراییِ معناییِ ثبت‌نشده‌ای وجود ندارد.\n";
exit(0);
