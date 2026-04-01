<?php

namespace ItsJustVita\VectorTiles\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class InstallCommand extends Command
{
    protected $signature = 'vector-tiles:install';

    protected $description = 'Install the Vector Tiles package';

    public function handle(): int
    {
        $this->components->info('Installing Laravel Vector Tiles...');

        $this->callSilent('vendor:publish', [
            '--tag' => 'vector-tiles-config',
        ]);
        $this->components->task('Config file published');

        $this->checkPostgis();

        $this->newLine();
        $this->components->info('Vector Tiles installed successfully!');
        $this->newLine();
        $this->line('  Next steps:');
        $this->line('  1. Define layers in <comment>config/vector-tiles.php</comment> or via the fluent API');
        $this->line('  2. Visit <comment>/tiles/{layer}/{z}/{x}/{y}.mvt</comment> to serve tiles');
        $this->newLine();

        return self::SUCCESS;
    }

    protected function checkPostgis(): void
    {
        try {
            $result = DB::selectOne('SELECT PostGIS_Version() AS version');
            $this->components->task(
                "PostGIS detected: v{$result->version}"
            );
        } catch (\Throwable) {
            $this->components->warn(
                'PostGIS not detected. Make sure PostGIS is installed and enabled on your database.'
            );
        }
    }
}
