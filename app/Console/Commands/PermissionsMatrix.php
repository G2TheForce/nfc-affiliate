<?php

namespace App\Console\Commands;

use App\Support\Authorization\PermissionMatrix;
use Illuminate\Console\Command;

class PermissionsMatrix extends Command
{
    protected $signature = 'affiliate:permissions-matrix {--check : Fail if docs/permissions-matrix.md is out of date}';

    protected $description = 'Write the role/permission matrix to docs/permissions-matrix.md';

    public function handle(): int
    {
        $path = base_path('docs/permissions-matrix.md');
        $markdown = PermissionMatrix::toMarkdown();

        if ($this->option('check')) {
            if (! is_file($path) || file_get_contents($path) !== $markdown) {
                $this->error('docs/permissions-matrix.md is out of date. Run php artisan affiliate:permissions-matrix.');

                return self::FAILURE;
            }
            $this->info('Permissions matrix is up to date.');

            return self::SUCCESS;
        }

        file_put_contents($path, $markdown);
        $this->info('Wrote docs/permissions-matrix.md');

        return self::SUCCESS;
    }
}
