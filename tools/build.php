<?php
declare(strict_types=1);

if (!class_exists(ZipArchive::class)) {
    fwrite(STDERR, "PHP zip extension is required.\n");
    exit(1);
}
$root = dirname(__DIR__);
$files = ['social-relay.php', 'uninstall.php', 'README.md', 'LICENSE'];
foreach (['src', 'docs', 'assets', 'languages'] as $directory) {
    if (!is_dir($root . DIRECTORY_SEPARATOR . $directory)) {
        continue;
    }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . DIRECTORY_SEPARATOR . $directory, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $files[] = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($root) + 1));
        }
    }
}
sort($files);
$dist = $root . DIRECTORY_SEPARATOR . 'dist';
if (!is_dir($dist) && !mkdir($dist, 0775, true)) {
    throw new RuntimeException('Could not create dist directory.');
}
$main = file_get_contents($root . DIRECTORY_SEPARATOR . 'social-relay.php');
if (!is_string($main) || !preg_match('/^ \* Version: ([0-9]+\.[0-9]+\.[0-9]+)$/m', str_replace("\r", '', $main), $version)) {
    throw new RuntimeException('Could not determine the plugin version.');
}
$target = $dist . DIRECTORY_SEPARATOR . 'social-relay-' . $version[1] . '.zip';
$zip = new ZipArchive();
if ($zip->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    throw new RuntimeException('Could not create release ZIP.');
}
foreach ($files as $relative) {
    $source = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    if (!$zip->addFile($source, 'social-relay/' . $relative)) {
        throw new RuntimeException('Could not add ' . $relative);
    }
}
$zip->close();
echo $target . PHP_EOL;
