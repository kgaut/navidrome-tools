#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Standalone Twig syntax linter for the templates/ directory.
 *
 * Why not `bin/console lint:twig` ? That command boots the Symfony kernel
 * (and therefore needs env vars / a compiled container), which is brittle in
 * CI and in the dev container. This linter only needs the Twig library: it
 * tokenizes + parses every template, which is exactly the stage that catches
 * structural errors — duplicate `{% block %}` definitions, unclosed tags,
 * bad `{% %}` nesting, etc. (the class of bug that 500'd every page once).
 *
 * Unknown functions/filters (path, csrf_token, app, our custom Twig
 * extensions…) are stubbed so they are NOT reported — we only care about
 * syntax here, not runtime symbol resolution.
 *
 * Exit code: 0 when all templates parse, 1 otherwise (CI-friendly).
 */

require __DIR__ . '/../vendor/autoload.php';

use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\Source;
use Twig\TwigFilter;
use Twig\TwigFunction;

$templatesDir = __DIR__ . '/../templates';

$env = new Environment(new FilesystemLoader($templatesDir), ['cache' => false]);
$env->registerUndefinedFunctionCallback(static fn (string $name): TwigFunction => new TwigFunction($name, static fn (mixed ...$args): string => ''));
$env->registerUndefinedFilterCallback(static fn (string $name): TwigFilter => new TwigFilter($name, static fn (mixed $value = null, mixed ...$args): mixed => $value));

$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($templatesDir, FilesystemIterator::SKIP_DOTS));

$checked = 0;
$errors = [];
foreach ($iterator as $file) {
    if (!$file->isFile() || !str_ends_with($file->getFilename(), '.twig')) {
        continue;
    }
    $name = ltrim(str_replace($templatesDir, '', $file->getPathname()), '/');
    ++$checked;
    try {
        $env->parse($env->tokenize(new Source((string) file_get_contents($file->getPathname()), $name)));
    } catch (\Twig\Error\SyntaxError $e) {
        $errors[] = sprintf('%s :: %s', $name, $e->getMessage());
    }
}

if ($errors !== []) {
    fwrite(\STDERR, "Twig syntax errors:\n");
    foreach ($errors as $error) {
        fwrite(\STDERR, '  x ' . $error . "\n");
    }
    fwrite(\STDERR, sprintf("\n%d template(s) checked, %d error(s).\n", $checked, count($errors)));
    exit(1);
}

fwrite(\STDOUT, sprintf("OK - %d Twig templates parsed, no syntax error.\n", $checked));
exit(0);
