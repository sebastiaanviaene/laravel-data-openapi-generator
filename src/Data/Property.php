<?php

namespace Xolvio\OpenApiGenerator\Data;

use ReflectionClass;
use ReflectionProperty;
use RuntimeException;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Data as LaravelData;
use Spatie\LaravelData\DataCollection;
use Spatie\LaravelData\Support\Transformation\TransformationContext;
use Spatie\LaravelData\Support\Transformation\TransformationContextFactory;

class Property extends Data
{
    public function __construct(
        public string $name,
        public Schema $type,
    ) {}

    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @return DataCollection<int,self>
     */
    public static function fromDataClass(string $class): DataCollection
    {
        if (! is_a($class, LaravelData::class, true)) {
            throw new RuntimeException('Class does not extend LaravelData');
        }

        $reflection = new ReflectionClass($class);
        $properties = $reflection->getProperties(ReflectionProperty::IS_PUBLIC);

        /** @var DataCollection<int,self> */
        return new DataCollection(
            self::class,
            array_map(
                fn(ReflectionProperty $property) => self::fromProperty($property),
                $properties
            )
        );
    }

    public static function fromProperty(ReflectionProperty $reflection): self
    {
        return new self(
            name: $reflection->getName(),
            type: Schema::fromReflectionProperty($reflection),
        );
    }

    public function transform(
        null|TransformationContextFactory|TransformationContext $transformationContext = null,
    ): array {
        return array_filter(
            parent::transform($transformationContext),
            fn(mixed $value) => $value !== null
        );
    }
}
