<?php

namespace ItsJustVita\VectorTiles\Commands;

use Illuminate\Console\Command;
use InvalidArgumentException;
use ItsJustVita\VectorTiles\Commands\Concerns\ResolvesUser;
use ItsJustVita\VectorTiles\Diagnostics\CheckStatus;
use ItsJustVita\VectorTiles\Diagnostics\LayerDoctor;
use ItsJustVita\VectorTiles\VectorTileManager;
use Symfony\Component\Console\Formatter\OutputFormatter;

class DoctorCommand extends Command
{
    use ResolvesUser;

    protected $signature = 'vector-tiles:doctor
        {layer? : Only check this layer}
        {--strict : Also exit with 1 when a check warns}
        {--user= : Run layer scopes as the user with this id}';

    protected $description = 'Check that layers are set up correctly for serving tiles';

    public function handle(VectorTileManager $manager, LayerDoctor $doctor): int
    {
        $name = $this->argument('layer');
        $layers = $name === null ? $manager->getLayers() : array_filter([$name => $manager->getLayer($name)]);

        if ($name !== null && $layers === []) {
            $this->error("Layer '{$name}' not found.");

            return self::FAILURE;
        }

        if ($layers === []) {
            $this->line('No layers defined.');

            return self::SUCCESS;
        }

        try {
            $user = $this->resolveUser();
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $statuses = [];

        foreach ($layers as $layer) {
            $this->newLine();
            $this->line("<options=bold>{$layer->name}</>");

            foreach ($doctor->diagnose($layer, $user) as $check) {
                $statuses[] = $check->status;

                [$icon, $color] = match ($check->status) {
                    CheckStatus::Pass => ['✓', 'green'],
                    CheckStatus::Warn => ['⚠', 'yellow'],
                    CheckStatus::Fail => ['✗', 'red'],
                };

                $this->line("  <fg={$color}>{$icon}</> ".OutputFormatter::escape($check->message));

                foreach ($check->hint === null ? [] : explode("\n", $check->hint) as $line) {
                    $this->line('      '.OutputFormatter::escape($line));
                }
            }
        }

        $failed = in_array(CheckStatus::Fail, $statuses, true)
            || ($this->option('strict') && in_array(CheckStatus::Warn, $statuses, true));

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
