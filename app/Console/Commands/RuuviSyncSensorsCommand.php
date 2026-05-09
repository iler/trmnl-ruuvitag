<?php

namespace App\Console\Commands;

use App\Models\Sensor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Syncs the sensor list from `config/ruuvi.php` into the `sensors` table.
 *
 * Idempotent: running this repeatedly will only insert new sensors and update
 * mutable fields on existing ones. Sensors removed from the config are *not*
 * deleted from the database — instead they are marked `enabled = false` so
 * their historical readings stay accessible.
 *
 * Usage:
 *   php artisan ruuvi:sync-sensors          # sync from config
 *   php artisan ruuvi:sync-sensors --prune  # also delete sensors not in config
 *   php artisan ruuvi:sync-sensors --dry-run
 */
class RuuviSyncSensorsCommand extends Command
{
    protected $signature = 'ruuvi:sync-sensors
                            {--prune : Delete sensors (and their readings) that are no longer in config}
                            {--dry-run : Show what would change without writing to the database}';

    protected $description = 'Sync the sensor list from config/ruuvi.php into the sensors table';

    public function handle(): int
    {
        /** @var array<int, array<string, mixed>> $rows */
        $rows = config('ruuvi.sensors', []);

        $configured = collect($rows)
            ->map(fn (array $row) => $this->normalize($row))
            ->keyBy('mac');

        if ($configured->isEmpty()) {
            $this->warn('No sensors defined in config/ruuvi.php — nothing to sync.');
            $this->line('Add entries to the `sensors` array and re-run this command.');

            return self::SUCCESS;
        }

        $existing = Sensor::all()->keyBy(fn (Sensor $s) => strtoupper($s->mac));

        $toCreate = $configured->diffKeys($existing);
        $toUpdate = $configured->intersectByKeys($existing);
        $toDisable = $existing->diffKeys($configured);

        $this->table(
            ['MAC', 'Name', 'Action'],
            collect()
                ->merge($toCreate->map(fn ($s) => [$s['mac'], $s['display_name'], 'create']))
                ->merge($toUpdate->map(fn ($s) => [$s['mac'], $s['display_name'], 'update']))
                ->merge($toDisable->map(fn (Sensor $s) => [
                    strtoupper($s->mac),
                    $s->display_name,
                    $this->option('prune') ? 'delete' : 'disable',
                ]))
                ->all()
        );

        if ($this->option('dry-run')) {
            $this->info('Dry run — no changes written.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($toCreate, $toUpdate, $toDisable) {
            foreach ($toCreate as $row) {
                Sensor::create($row);
            }

            foreach ($toUpdate as $mac => $row) {
                Sensor::where('mac', $mac)->update(array_merge($row, ['enabled' => true]));
            }

            foreach ($toDisable as $sensor) {
                if ($this->option('prune')) {
                    $sensor->delete();
                } else {
                    $sensor->update(['enabled' => false]);
                }
            }
        });

        $this->info(sprintf(
            'Synced: %d created, %d updated, %d %s.',
            $toCreate->count(),
            $toUpdate->count(),
            $toDisable->count(),
            $this->option('prune') ? 'pruned' : 'disabled',
        ));

        return self::SUCCESS;
    }

    /**
     * Normalize a config row into a sensors-table row.
     * Uppercases the MAC, fills in defaults, validates required fields.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function normalize(array $row): array
    {
        if (empty($row['mac'])) {
            throw new \InvalidArgumentException('Each sensor entry must have a `mac` field.');
        }
        if (empty($row['display_name'])) {
            throw new \InvalidArgumentException("Sensor {$row['mac']} is missing `display_name`.");
        }

        return [
            'mac' => strtoupper($row['mac']),
            'display_name' => $row['display_name'],
            'enabled' => $row['enabled'] ?? true,
            'temp_min' => $row['temp_min'] ?? null,
            'temp_max' => $row['temp_max'] ?? null,
            'humidity_min' => $row['humidity_min'] ?? null,
            'humidity_max' => $row['humidity_max'] ?? null,
            'battery_low_mv' => $row['battery_low_mv'] ?? 2500,
            'display_order' => $row['display_order'] ?? 0,
        ];
    }
}
