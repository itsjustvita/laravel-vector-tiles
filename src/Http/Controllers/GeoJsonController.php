<?php

namespace ItsJustVita\VectorTiles\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Routing\Controller;
use Illuminate\Routing\Router;
use ItsJustVita\VectorTiles\Layer;
use ItsJustVita\VectorTiles\Query\LayerQueryFactory;
use ItsJustVita\VectorTiles\Support\WebMercator;
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
            'bbox' => ['required', 'string', function (string $attribute, mixed $value, \Closure $fail) {
                $error = $this->bboxError((string) $value);

                if ($error !== null) {
                    $fail($error);
                }
            }],
            'limit' => ['sometimes', 'integer', 'min:1'],
        ]);

        [$west, $south, $east, $north] = $this->parseBbox($request->input('bbox'));

        $defaultLimit = (int) config('vector-tiles.geojson.default_limit', 1000);
        $maxLimit = (int) config('vector-tiles.geojson.max_limit', 5000);
        $limit = min((int) $request->input('limit', $defaultLimit), $maxLimit);

        $query = $queries->make($layerConfig, $request->user());
        $grammar = $query->getGrammar();

        $geometry = $grammar->wrap($layerConfig->geometry);
        $srid = (int) $layerConfig->srid;

        if ($srid === 3857) {
            $south = max($south, -WebMercator::MAX_LATITUDE);
            $north = min($north, WebMercator::MAX_LATITUDE);
        }

        $wgs84 = $srid === 4326 ? $geometry : "ST_Transform({$geometry}, 4326)";

        [$propertiesExpr, $propertyBindings] = $this->buildPropertiesExpression($layerConfig->properties, $grammar);

        $featureExpr = 'jsonb_build_object('
            ."'type', 'Feature', "
            ."'geometry', ST_AsGeoJSON({$wgs84})::jsonb, "
            ."'properties', {$propertiesExpr}"
            .') AS feature';

        $query->select([])->selectRaw($featureExpr, $propertyBindings);

        $query->whereRaw($this->bboxFilter($geometry, $srid), [$west, $south, $east, $north]);

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
     * Bbox filter that keeps a spatial index usable for the layer's SRID.
     *
     * The bbox is only transformed to EPSG:3857, which is valid for the clamped
     * latitude range. Other SRIDs transform the geometry instead, which can use
     * an expression index on ST_Transform(geom, 4326).
     */
    protected function bboxFilter(string $geometry, int $srid): string
    {
        $bbox = 'ST_MakeEnvelope(?, ?, ?, ?, 4326)';

        return match ($srid) {
            4326 => "ST_Intersects({$geometry}, {$bbox})",
            3857 => "ST_Intersects({$geometry}, ST_Transform({$bbox}, 3857))",
            default => "ST_Intersects(ST_Transform({$geometry}, 4326), {$bbox})",
        };
    }

    protected function bboxError(string $bbox): ?string
    {
        $parts = explode(',', $bbox);

        if (count($parts) !== 4) {
            return 'The bbox must contain exactly 4 comma-separated coordinates (west,south,east,north).';
        }

        foreach ($parts as $part) {
            if (! is_numeric(trim($part))) {
                return 'All bbox coordinates must be numeric.';
            }
        }

        [$west, $south, $east, $north] = $this->parseBbox($bbox);

        if ($west < -180 || $east > 180 || $west > $east) {
            return 'The bbox longitudes must lie within [-180, 180] with west <= east.';
        }

        if ($south < -90 || $north > 90 || $south > $north) {
            return 'The bbox latitudes must lie within [-90, 90] with south <= north.';
        }

        return null;
    }

    /**
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    protected function parseBbox(string $bbox): array
    {
        return array_map(fn (string $v) => (float) trim($v), explode(',', $bbox));
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
