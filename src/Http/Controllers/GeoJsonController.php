<?php

namespace ItsJustVita\VectorTiles\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Routing\Controller;
use Illuminate\Routing\Router;
use InvalidArgumentException;
use ItsJustVita\VectorTiles\Layer;
use ItsJustVita\VectorTiles\Query\BboxFilter;
use ItsJustVita\VectorTiles\Query\LayerQueryFactory;
use ItsJustVita\VectorTiles\VectorTileManager;
use Symfony\Component\HttpFoundation\Response;

class GeoJsonController extends Controller
{
    public function __invoke(
        Request $request,
        VectorTileManager $manager,
        LayerQueryFactory $queries,
        string $layer,
    ): Response {
        $layerConfig = $manager->getLayer($layer);

        if ($layerConfig === null) {
            abort(404, "Layer '{$layer}' not found.");
        }

        if (! empty($layerConfig->middleware)) {
            return app(Pipeline::class)
                ->send($request)
                ->through(app(Router::class)->resolveMiddleware($layerConfig->middleware))
                ->then(fn (Request $req) => $this->handle($req, $layerConfig, $queries));
        }

        return $this->handle($request, $layerConfig, $queries);
    }

    protected function handle(Request $request, Layer $layerConfig, LayerQueryFactory $queries): JsonResponse
    {
        $request->validate([
            'bbox' => ['bail', 'required', 'string', function (string $attribute, mixed $value, \Closure $fail) {
                try {
                    BboxFilter::parse($value);
                } catch (InvalidArgumentException $e) {
                    $fail($e->getMessage());
                }
            }],
            'limit' => ['sometimes', 'integer', 'min:1'],
        ]);

        $bbox = BboxFilter::parse($request->input('bbox'));

        $defaultLimit = (int) config('vector-tiles.geojson.default_limit', 1000);
        $maxLimit = (int) config('vector-tiles.geojson.max_limit', 5000);
        $limit = min((int) $request->input('limit', $defaultLimit), $maxLimit);

        $query = $queries->make($layerConfig, $request->user());
        $grammar = $query->getGrammar();

        $geometry = $grammar->wrap($layerConfig->geometry);
        $srid = (int) $layerConfig->srid;

        $wgs84 = $srid === 4326 ? $geometry : "ST_Transform({$geometry}, 4326)";

        [$propertiesExpr, $propertyBindings] = $this->buildPropertiesExpression($layerConfig->properties, $grammar);

        $featureExpr = 'jsonb_build_object('
            ."'type', 'Feature', "
            ."'geometry', ST_AsGeoJSON({$wgs84})::jsonb, "
            ."'properties', {$propertiesExpr}"
            .') AS feature';

        $query->select([])->selectRaw($featureExpr, $propertyBindings);

        BboxFilter::apply($query, $layerConfig->geometry, $srid, $bbox);

        $query->limit($limit);

        $rows = $query->getConnection()->select($query->toSql(), $query->getBindings());

        $features = array_map(
            fn (object $row) => json_decode($row->feature, true),
            $rows,
        );

        return response()->json([
            'type' => 'FeatureCollection',
            'features' => $features,
        ], 200, ['Content-Type' => 'application/geo+json']);
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
            $parts[] = "?::text, {$wrapped}";
            $bindings[] = $column;
        }

        return ['jsonb_build_object('.implode(', ', $parts).')', $bindings];
    }
}
