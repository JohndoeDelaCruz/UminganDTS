<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Department;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;

class ImportUminganOffices extends Command
{
    protected $signature = 'umdts:import-umingan-offices {--apply : Add missing verified offices to the configured database}';

    protected $description = 'Preview or explicitly import the sourced Umingan office directory without overwriting existing offices';

    public function handle(): int
    {
        $path = resource_path('data/umingan-offices.json');
        $manifest = File::json($path);
        $apply = (bool) $this->option('apply');

        $this->info(($apply ? 'Import into ' : 'Preview for ').DB::connection()->getDatabaseName());
        $this->line('Source: '.$manifest['sources']['directory'].'; verified '.$manifest['verified_on']);

        try {
            [$rows, $created, $existing] = DB::transaction(function () use ($apply, $manifest, $path) {
                $departments = Department::lockForUpdate()->get();
                $plan = [];
                foreach ($manifest['offices'] as $office) {
                    $names = array_map($this->normalize(...), [$office['name'], ...$office['aliases']]);
                    $matches = $departments->filter(fn (Department $department) => in_array($this->normalize($department->name), $names, true));
                    $codeMatch = $departments->first(fn (Department $department) => strtoupper($department->code) === $office['code']);

                    if ($codeMatch && ! $matches->contains('id', $codeMatch->id)) {
                        throw new RuntimeException('Code '.$office['code'].' is already assigned to another office. No offices were imported.');
                    }
                    if ($matches->count() > 1) {
                        throw new RuntimeException('Multiple existing entries match '.$office['name'].'. Resolve these duplicates before importing.');
                    }
                    $plan[] = [$office, $matches->first()];
                }

                // Validate the complete plan before adding any records. Existing configuration stays intact.
                $rows = [];
                $created = 0;
                $existing = 0;
                foreach ($plan as [$office, $match]) {
                    if ($match) {
                        $existing++;
                        $rows[] = [$match->code, $match->name, 'Keep existing'.($match->is_active ? '' : ' (inactive)')];

                        continue;
                    }
                    $rows[] = [$office['code'], $office['name'], $apply ? 'Added' : 'Would add'];
                    $created++;
                    if ($apply) {
                        $department = Department::create(['code' => $office['code'], 'name' => $office['name'], 'is_active' => true]);
                        AuditLog::record(null, 'department.imported', $department, [
                            'import' => 'umingan-offices',
                            'verified_on' => $manifest['verified_on'],
                            'source_urls' => array_map(fn (string $source) => $manifest['sources'][$source], $office['sources']),
                            'manifest_sha256' => hash_file('sha256', $path),
                            'code' => $office['code'],
                            'name' => $office['name'],
                            'operator' => 'Artisan CLI',
                        ]);
                    }
                }

                return [$rows, $created, $existing];
            }, 3);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->table(['Code', 'Office', 'Action'], $rows);
        $this->info(($apply ? 'Added ' : 'Would add ').$created.' offices; kept '.$existing.' existing entries.');
        if (! $apply) {
            $this->comment('Preview only. Run with --apply to import.');
        }

        return self::SUCCESS;
    }

    private function normalize(string $name): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower(Str::ascii($name)));
    }
}
