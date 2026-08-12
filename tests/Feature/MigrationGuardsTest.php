<?php

declare(strict_types=1);

use Illuminate\Support\Str;

/*
 * Regression guards for MySQL-only migration failures that SQLite can never catch:
 *
 * 1. Error 1824 — newsletter_request_attempts once shared its timestamp with
 *    newsletter_requests, sorted first alphabetically, and tried to add its foreign
 *    key before the referenced table existed. SQLite does not validate foreign-key
 *    targets at DDL time.
 *
 * 2. Error 1059 — Laravel's auto-generated constraint names (table_column_foreign)
 *    exceeded MySQL's 64-character identifier limit for the long newsletter_* table
 *    names. SQLite has no identifier length limit.
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

it("keeps every generated index and constraint identifier within MySQL's 64-character limit", function () {
    $limit = 64;
    $violations = [];

    foreach (glob(database_path('migrations/*.php')) as $path) {
        $file = basename($path);
        $source = file_get_contents($path);
        $table = null;

        foreach (explode("\n", $source) as $line) {
            if (preg_match("/Schema::(?:create|table)\('([^']+)'/", $line, $match)) {
                $table = $match[1];
            }

            if ($table === null) {
                continue;
            }

            $generated = [];

            if (preg_match('/foreignIdFor\(\\\\?([A-Za-z0-9_\\\\]+)::class\)->constrained\(([^)]*)\)/', $line, $match)
                && ! str_contains($match[2], 'indexName')
                && substr_count($match[2], ',') < 2) {
                $model = resolveMigrationClassName($match[1], $source);
                if ($model !== null) {
                    $generated[] = "{$table}_".Str::snake(class_basename($model)).'_id_foreign';
                }
            }

            if (preg_match('/foreignId\(\'([^\']+)\'\)->constrained\(([^)]*)\)/', $line, $match)
                && ! str_contains($match[2], 'indexName')
                && substr_count($match[2], ',') < 2) {
                $generated[] = "{$table}_{$match[1]}_foreign";
            }

            if (preg_match('/\(\'([^\']+)\'[^)]*\).*->(unique|index)\(\)/', $line, $match)) {
                $generated[] = "{$table}_{$match[1]}_{$match[2]}";
            }

            if (preg_match('/\$table->(unique|index|primary)\(\[([^\]]+)\]\)(?!\s*,)/', $line, $match)) {
                $columns = collect(explode(',', $match[2]))
                    ->map(fn (string $column) => trim($column, " '\""))
                    ->implode('_');
                $generated[] = "{$table}_{$columns}_{$match[1]}";
            }

            foreach ($generated as $identifier) {
                if (strlen($identifier) > $limit) {
                    $violations[] = sprintf(
                        '%s generates `%s` (%d chars > %d) — pass an explicit shorter index/constraint name',
                        $file,
                        $identifier,
                        strlen($identifier),
                        $limit,
                    );
                }
            }
        }
    }

    expect($violations)->toBe([]);
});
