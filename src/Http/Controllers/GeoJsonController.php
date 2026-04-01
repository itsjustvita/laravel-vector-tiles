<?php

namespace ItsJustVita\VectorTiles\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use ItsJustVita\VectorTiles\VectorTileManager;

class GeoJsonController extends Controller
{
    public function __invoke(
        Request $request,
        VectorTileManager $manager,
        string $layer,
    ): JsonResponse {
        $layerConfig = $manager->getLayer($layer);

        if ($layerConfig === null) {
            abort(404, "Layer '{$layer}' not found.");
        }

        $request->validate([
            'bbox' => ['required', 'string', function (string $attribute, mixed $value, \Closure $fail) {
                $parts = explode(',', $value);
                if (count($parts) !== 4) {
                    $fail('The bbox must contain exactly 4 comma-separated coordinates (west,south,east,north).');
                    return;
                }
                foreach ($parts as $part) {
                    if (! is_numeric(trim($part))) {
                        $fail('All bbox coordinates must be numeric.');
                        return;
                    }
                }
            }],
            'limit' => ['sometimes', 'integer', 'min:1'],
        ]);

        [$west, $south, $east, $north] = array_map(
            fn (string $v) => (float) trim($v),
            explode(',', $request->input('bbox')),
        );

        $defaultLimit = (int) config('vector-tiles.geojson.default_limit', 1000);
        $maxLimit = (int) config('vector-tiles.geojson.max_limit', 5000);
        $limit = min((int) $request->input('limit', $defaultLimit), $maxLimit);

        $table = $layerConfig->getTable();
        $geometry = $layerConfig->geometry;
        $srid = $layerConfig->srid;
        $properties = $layerConfig->properties;

        $propertyPairs = collect($properties)
            ->flatMap(fn (string $col) => ["'{$col}'", "\"{$col}\""])
            ->implode(', ');

        $propertiesJson = $propertyPairs
            ? "jsonb_build_object({$propertyPairs})"
            : "'{}'::jsonb";

        $sql = <<<SQL
            SELECT jsonb_build_object(
                'type', 'Feature',
                'geometry', ST_AsGeoJSON(ST_Transform("{$geometry}", 4326))::jsonb,
                'properties', {$propertiesJson}
            ) AS feature
            FROM "{$table}"
            WHERE ST_Intersects(
                ST_Transform("{$geometry}", {$srid}),
                ST_MakeEnvelope(?, ?, ?, ?, 4326)
            )
            LIMIT ?
            SQL;

        $bindings = [$west, $south, $east, $north, $limit];

        $rows = DB::connection($layerConfig->connection)->select($sql, $bindings);

        $features = array_map(
            fn (object $row) => json_decode($row->feature, true),
            $rows,
        );

        return response()->json([
            'type' => 'FeatureCollection',
            'features' => $features,
        ], 200, ['Content-Type' => 'application/geo+json']);
    }
}
