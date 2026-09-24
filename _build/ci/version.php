<?php
/**
 * Check that _build/config.inc.php, the FetchIt class, package.json and
 * package-lock.json name the same version, and print the package signature
 * for $GITHUB_OUTPUT.
 *
 * Usage: php _build/ci/version.php [tag]
 *
 * With a tag (a release), the tag must be vX.Y.Z or vX.Y.Z-<release> for
 * that version (cliff.toml only sees tags starting with "v"), and the
 * changelog must have a "## [X.Y.Z]" section.
 */

$root = dirname(__DIR__, 2) . '/';
$config = require $root . '_build/config.inc.php';
$version = $config['version'];
$signature = $config['name_lower'] . '-' . $version . '-' . $config['release'];
$errors = [];

$class = 'core/components/fetchit/model/fetchit.class.php';
if (!preg_match("/public \\\$version = '([^']+)'/", (string)file_get_contents($root . $class), $match)) {
    $errors[] = "No \$version in {$class}.";
} elseif ($match[1] !== $version) {
    $errors[] = "{$class} says {$match[1]}, _build/config.inc.php says {$version}.";
}

$package = json_decode((string)file_get_contents($root . 'package.json'), true);
if (!isset($package['version']) || $package['version'] !== $version) {
    $found = isset($package['version']) ? $package['version'] : 'nothing';
    $errors[] = "package.json says {$found}, _build/config.inc.php says {$version}.";
}

$lock = json_decode((string)file_get_contents($root . 'package-lock.json'), true);
foreach (['version' => 'package-lock.json', 'root package' => 'package-lock.json packages[""]'] as $where => $label) {
    $found = $where === 'version'
        ? (isset($lock['version']) ? $lock['version'] : 'nothing')
        : (isset($lock['packages']['']['version']) ? $lock['packages']['']['version'] : 'nothing');
    if ($found !== $version) {
        $errors[] = "{$label} says {$found}, _build/config.inc.php says {$version} (run npm version {$version} --no-git-tag-version).";
    }
}

$tag = isset($argv[1]) ? trim($argv[1]) : '';
if ($tag !== '') {
    if ($tag !== 'v' . $version && $tag !== 'v' . $version . '-' . $config['release']) {
        $errors[] = "The tag {$tag} does not name version {$version}: use v{$version}.";
    }

    $changelog = (string)file_get_contents($root . 'core/components/fetchit/docs/changelog.txt');
    if (strpos($changelog, '## [' . $version . ']') === false) {
        $errors[] = "core/components/fetchit/docs/changelog.txt has no section for [{$version}].";
    }
}

if ($errors) {
    fwrite(STDERR, implode("\n", $errors) . "\n");
    exit(1);
}

echo "version={$version}\n";
echo "signature={$signature}\n";
