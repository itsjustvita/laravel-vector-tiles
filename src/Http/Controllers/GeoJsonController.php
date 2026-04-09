<?php

namespace ItsJustVita\VectorTiles\Http\Controllers;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use ItsJustVita\VectorTiles\Layer;
use ItsJustVita\VectorTiles\VectorTileManager;
use Symfony\Component\HttpFoundation\Response;

class GeoJsonController extends Controller
{
    public function __invoke(
        Request $request,
        VectorTileManager $manager,
        string $layer,
    ): Response {
        $layerConfig = $manager->getLayer($layer);

        if ($layerConfig === null) {
            abort(404, "Layer '{$layer}' not found.");
        }

        if (! empty($layerConfig->middleware)) {
            return app(Pipeline::class)
                ->send($request)
                ->through($layerConfig->middleware)
                ->then(fn (Request $req) => $this->handle($req, $layerConfig));
        }

        return $this->handle($request, $layerConfig);
    }

    protected function handle(Request $request, Layer $layerConfig): JsonResponse
    {
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

        $connection = DB::connection($layerConfig->connection);
        $grammar = $connection->getQueryGrammar();

        $query = $this->buildBaseQuery($layerConfig, $connection);

        if ($layerConfig->scope !== null) {
            ($layerConfig->scope)($query, $request->user());
        }

        $wrappedGeom = $grammar->wrap($layerConfig->geometry);
        $srid = (int) $layerConfig->srid;

        [$propertiesExpr, $propertyBindings] = $this->buildPropertiesExpression($layerConfig->properties, $grammar);

        $featureExpr = 'jsonb_build_object('
            ."'type', 'Feature', "
            ."'geometry', ST_AsGeoJSON(ST_Transform({$wrappedGeom}, 4326))::jsonb, "
            ."'properties', {$propertiesExpr}"
            .') AS feature';

        $query->select([])->selectRaw($featureExpr, $propertyBindings);

        $query->whereRaw(
            "ST_Intersects(ST_Transform({$wrappedGeom}, {$srid}), ST_MakeEnvelope(?, ?, ?, ?, 4326))",
            [$west, $south, $east, $north],
        );

        $query->limit($limit);

        $rows = $connection->select($query->toSql(), $query->getBindings());

        $features = array_map(
            fn (object $row) => json_decode($row->feature, true),
            $rows,
        );

        return response()->json([
            'type' => 'FeatureCollection',
            'features' => $features,
        ], 200, ['Content-Type' => 'application/geo+json']);
    }

    protected function buildBaseQuery(Layer $layer, $connection): QueryBuilder
    {
        if ($layer->source instanceof EloquentBuilder) {
            return clone $layer->source->toBase();
        }

        if (is_string($layer->source)) {
            return $connection->table($layer->source);
        }

        return $connection->table($layer->name);
    }

    /**
     * @return array{0: string, 1: array<int, string>}
     */
    protected function buildPropertiesExpression(array $properties, $grammar): array
    {
        if (empty($properties)) {
            return ["'{}'::jsonb", []];
        }

        $parts = [];
        $bindings = [];
        foreach ($properties as $column) {
            $wrapped = $grammar->wrap($column);
            $parts[] = "?, {$wrapped}";
            $bindings[] = $column;
        }

        return ['jsonb_build_object('.implode(', ', $parts).')', $bindings];
    }
}
