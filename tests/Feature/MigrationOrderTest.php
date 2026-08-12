<?php

declare(strict_types=1);

use Illuminate\Support\Str;

/*
 * Regression guard for the MySQL 1824 incident: newsletter_request_attempts once
 * shared its timestamp with newsletter_requests, sorted first alphabetically, and
 * tried to add its foreign key before the referenced table existed. SQLite does not
 * validate foreign-key targets at DDL time, so the test suite could never catch the
 * broken order on its own — this static check keeps it honest.
 */

function resolveMigrationClassName(string $name, string $source): ?string
{
    $candidates = str_contains($name, '\\')
        ? [Str::start($name, '\\')]
        : [];

    if ($candidates === [] && preg_match('/^use\s+([A-Za-z0-9_\\\\]+\\\\'.preg_quote($name, '/').');/m', $source, $import)) {
        $candidates[] = '\\'.$import[1];
    }

    $candidates[] = '\\App\\Models\\'.$name;

    foreach ($candidates as $candidate) {
        if (class_exists($candidate)) {
            return $candidate;
        }
    }

    return null;
}

it('creates every table before another migration references it in a foreign key', function () {
    $files = collect(glob(database_path('migrations/*.php')))
        ->map(fn (string $path) => basename($path))
        ->sort()
        ->values();

    $createdAt = [];
    foreach ($files as $index => $file) {
        preg_match_all("/Schema::create\('([^']+)'/", file_get_contents(database_path("migrations/{$file}")), $matches);
        foreach ($matches[1] as $table) {
            $createdAt[$table] ??= $index;
        }
    }

    $violations = [];
    foreach ($files as $index => $file) {
        $source = file_get_contents(database_path("migrations/{$file}"));
        $references = [];

        preg_match_all('/foreignIdFor\(\\\\?([A-Za-z0-9_\\\\]+)::class\)(?:->constrained\(\'([^\']+)\'\))?/', $source, $byModel, PREG_SET_ORDER);
        foreach ($byModel as $match) {
            if (($match[2] ?? '') !== '') {
                $references[] = $match[2];

                continue;
            }

            $model = resolveMigrationClassName($match[1], $source);

            if ($model === null) {
                $violations[] = "{$file} references model `{$match[1]}` that cannot be resolved to a class";

                continue;
            }

            $references[] = (new $model)->getTable();
        }

        preg_match_all('/foreignId\(\'([^\']+)\'\)->constrained\((?:\'([^\']+)\')?\)/', $source, $byColumn, PREG_SET_ORDER);
        foreach ($byColumn as $match) {
            $references[] = ($match[2] ?? '') !== '' ? $match[2] : Str::plural(Str::beforeLast($match[1], '_id'));
        }

        foreach (array_unique($references) as $table) {
            if (! array_key_exists($table, $createdAt)) {
                $violations[] = "{$file} references `{$table}`, which no migration creates";

                continue;
            }

            if ($createdAt[$table] > $index) {
                $violations[] = "{$file} references `{$table}` before {$files[$createdAt[$table]]} creates it — rename one so the referenced table migrates first";
            }
        }
    }

    expect($violations)->toBe([]);
});
