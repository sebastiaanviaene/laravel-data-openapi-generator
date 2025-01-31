<?php

namespace Xolvio\OpenApiGenerator\Data;

use Illuminate\Support\Arr;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Data as LaravelData;
use Spatie\LaravelData\Optional;
use Spatie\LaravelData\Support\Transformation\TransformationContext;
use Spatie\LaravelData\Support\Transformation\TransformationContextFactory;

class RequestBody extends Data
{
    public function __construct(
        public Content $content,
    ) {}

    public static function fromRoute(ReflectionMethod|ReflectionFunction $method): ?self
    {
        $type = self::getFirstOfClassType($method, LaravelData::class);

        if (! $type) {
            return null;
        }

        return new self(
            content: Content::fromReflection($type, $method),
        );
    }

    public static function getFirstOfClassType(ReflectionMethod|ReflectionFunction $method, string $class): ?ReflectionNamedType
    {
        $parameter = Arr::first(
            $method->getParameters(),
            static function (ReflectionParameter $parameter) use ($class) {
                $type = $parameter->getType();

                return $type instanceof ReflectionNamedType && is_a($type->getName(), $class, true);
            }
        );

        return $parameter ? $parameter->getType() : null;
    }

    public function transform(
        null|TransformationContextFactory|TransformationContext $transformationContext = null,
    ): array {
        return array_filter(
            parent::transform($transformationContext),
            fn(mixed $value) => $value !== null && $value !== Optional::create(),
        );
    }
}
