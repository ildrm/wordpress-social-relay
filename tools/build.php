<?php
declare(strict_types=1);

if (!class_exists(ZipArchive::class)) {
    fwrite(STDERR, "PHP zip extension is required.\n");
    exit(1);
}
$root = dirname(__DIR__);
$files = ['social-relay.php', 'uninstall.php', 'README.md', 'LICENSE', 'assets/admin.css'];
foreach (['src', 'docs'] as $directory) {
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
$target = $dist . DIRECTORY_SEPARATOR . 'social-relay-0.1.0.zip';
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
