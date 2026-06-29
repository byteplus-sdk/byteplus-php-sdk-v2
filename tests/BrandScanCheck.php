<?php

/**
 * Brand scan guard.
 *
 * Fails if any forbidden Volcengine-era brand token leaks into shipped sources
 * (code, docs, composer/meta config). This protects the Byteplus rebrand from
 * regressions when README/docs are edited or new files are added.
 *
 * Allowlist: a small set of server-side protocol constants that Byteplus
 * deliberately keeps unchanged (matching byteplus-python-sdk-v2):
 *   - ECS IMDS path prefix `/volcstack/latest/...`
 *   - ECS IMDS token headers `X-volc-ecs-metadata-token*`
 *   - the `volc_observe` service alias still accepted by the gateway
 */

$root = dirname(__DIR__);

// Directories/files that are not part of the shipped SDK surface.
$excludeDirs = ['.git', 'vendor', 'docs/_build'];
// BrandScanCheck.php itself necessarily contains the forbidden token list.
$excludeFiles = ['composer.lock', 'BRANDING_MIGRATION.md', 'BrandScanCheck.php'];

// Forbidden brand tokens (case-insensitive). Covers English + Chinese brand
// names, legacy env prefixes, the old UA, the rebranded host, and the IAM
// policy condition-key namespace (`volc:` -> `byteplus:`).
$forbidden = [
    'volcengine',
    'volcstack',
    'volc:',
    'volcstack-php-sdk',
    'VOLCENGINE_',
    'VOLCSTACK_',
    '.volces.com',
    '火山',
];

// Forbidden regex patterns (case-insensitive). These cover the Volcengine CLI
// abbreviation `ve ...` -> Byteplus `bp ...` in any markup form, e.g. `ve cli`,
// "ve login", or the back-ticked "`ve` CLI". A leading word boundary keeps a
// bare `ve` from ever matching (so service names like `vepfs`/`vefaas` and
// words like "save login" are never flagged); only `ve` + optional back-tick +
// whitespace + a known subcommand is forbidden.
$forbiddenRegex = [
    '/\bve`?\s+(login|sso\s+login|cli)\b/i',
];

// Allowlisted protocol/service substrings that Byteplus deliberately keeps.
// These are scrubbed from each line BEFORE forbidden-token matching, so a hit
// is only reported when it sits OUTSIDE an allowlisted token (precise per-hit
// behavior, not whole-line skip).
$allowlist = [
    '/volcstack/latest',
    'X-volc-ecs-metadata-token',
    'volc_observe',
];

function should_skip($path, $excludeDirs, $excludeFiles)
{
    foreach ($excludeDirs as $d) {
        if (strpos($path, '/' . $d . '/') !== false || strpos($path, $d . '/') === 0) {
            return true;
        }
    }
    foreach ($excludeFiles as $f) {
        if (basename($path) === $f) {
            return true;
        }
    }
    // Only scan text-ish files.
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $allowExt = ['php', 'md', 'json', 'yml', 'yaml', 'xml', 'txt'];
    return !in_array($ext, $allowExt, true);
}

/**
 * Remove every allowlisted substring from the line so that forbidden-token
 * matching only sees text OUTSIDE the allowlisted protocol/service constants.
 */
function scrub_allowlist($line, $allowlist)
{
    foreach ($allowlist as $a) {
        $line = str_ireplace($a, '', $line);
    }
    return $line;
}

$violations = [];
$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);

foreach ($it as $file) {
    $path = $file->getPathname();
    $rel = ltrim(str_replace($root, '', $path), '/');
    if (should_skip($rel, $excludeDirs, $excludeFiles)) {
        continue;
    }
    $content = @file_get_contents($path);
    if ($content === false) {
        continue;
    }
    $lines = explode("\n", $content);
    foreach ($lines as $i => $line) {
        $scrubbed = scrub_allowlist($line, $allowlist);
        $hit = null;
        foreach ($forbidden as $token) {
            if (stripos($scrubbed, $token) !== false) {
                $hit = $token;
                break;
            }
        }
        if ($hit === null) {
            foreach ($forbiddenRegex as $re) {
                if (preg_match($re, $scrubbed, $m)) {
                    $hit = trim($m[0]);
                    break;
                }
            }
        }
        if ($hit !== null) {
            $violations[] = sprintf('%s:%d  [%s]  %s', $rel, $i + 1, $hit, trim($line));
        }
    }
}

if (!empty($violations)) {
    fwrite(STDERR, "Brand scan FAILED. Forbidden Volcengine-era tokens found:\n");
    foreach ($violations as $v) {
        fwrite(STDERR, '  ' . $v . "\n");
    }
    fwrite(STDERR, sprintf("\n%d violation(s).\n", count($violations)));
    exit(1);
}

echo "Brand scan passed: no forbidden Volcengine-era tokens.\n";
