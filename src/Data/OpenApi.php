<?php

namespace Xolvio\OpenApiGenerator\Data;

use Illuminate\Console\Command;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Log;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;
use Spatie\LaravelData\Support\Transformation\TransformationContext;
use Spatie\LaravelData\Support\Transformation\TransformationContextFactory;
use Spatie\LaravelData\Support\Wrapping\WrapExecutionType;
use stdClass;
use Throwable;

class OpenApi extends Data
{
    /** @var array<string,class-string<Data>> */
    protected static array $schemas = [];

    /** @var array<string,class-string<Data>> */
    protected static array $temp_schemas = [];

    public function __construct(
        public string $openapi,
        public Info $info,
        /** @var array<string,array<string,Operation>> */
        public array $paths,
    ) {}

    /**
     * @param class-string<Data> $schema
     */
    public static function addClassSchema(string $name, $schema): void
    {
        static::$temp_schemas[$name] = $schema;
    }

    /** @return array<string,class-string<Data>> */
    public static function getSchemas(): array
    {
        return static::$schemas;
    }

    /** @return array<string,class-string<Data>> */
    public static function getTempSchemas(): array
    {
        return static::$temp_schemas;
    }

    /**
     * @param array<string,array<string,Route>> $routes
     */
    public static function fromRoutes(array $routes, Command $command): self
    {
        /** @var array<string,array<string,Operation>> $paths */
        $paths = [];

        foreach ($routes as $uri => $uri_routes) {
            foreach ($uri_routes as $method => $route) {
                try {
                    self::$temp_schemas = [];

                    $paths[$uri][$method] = Operation::fromRoute($route);

                    self::addTempSchemas();
                } catch (Throwable $th) {
                    $command->error("Failed to generate Operation from route {$method} {$route->getName()} {$uri}: {$th->getMessage()}");

                    Log::error($th);
                }
            }
        }

        return new self(
            openapi: config('openapi-generator.openapi'),
            info: Info::create(),
            paths: $paths,
        );
    }

    public function transform(
        null|TransformationContextFactory|TransformationContext $transformationContext = null,
    ): array {
        // Double call to make sure all schemas are resolved
        $this->resolveSchemas();

        $transformed = parent::transform($transformationContext);
        $paths = [
            'paths' => count($this->paths) > 0 ?
                array_map(
                    fn(array $path) => array_map(
                        fn(Operation $operation) => $operation->transform($transformationContext),
                        $path
                    ),
                    $this->paths
                ) :
                new stdClass(),
        ];

        return array_filter(
            array_merge(
                $transformed,
                $paths,
                [
                    'components' => [
                        'schemas' => $this->resolveSchemas(),
                        'securitySchemes' => [
                            SecurityScheme::BEARER_SECURITY_SCHEME => [
                                'type' => 'http',
                                'scheme' => 'bearer',
                            ],
                        ],
                    ],
                ]
            ),
            fn(mixed $value) => $value !== null && $value !== Optional::create()
        );
    }

    protected static function addTempSchemas(): void
    {
        static::$schemas = array_merge(
            static::$schemas,
            static::$temp_schemas,
        );
    }

    /**
     * @return array<string,mixed>
     */
    protected function resolveSchemas(): array
    {
        $schemas = array_map(
            fn(string $schema) => Schema::fromDataClass($schema)->transform(),
            static::$schemas
        );

        $this->addTempSchemas();

        return $schemas;
    }
}
