<?php

namespace Xolvio\OpenApiGenerator\Data;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use phpDocumentor\Reflection\DocBlock\Tags\Return_;
use phpDocumentor\Reflection\DocBlock\Tags\Var_;
use phpDocumentor\Reflection\DocBlockFactory;
use phpDocumentor\Reflection\Types\AbstractList;
use ReflectionEnum;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionProperty;
use RuntimeException;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Data as LaravelData;
use Spatie\LaravelData\DataCollection;
use Spatie\LaravelData\Optional;
use Spatie\LaravelData\Support\DataProperty;
use Spatie\LaravelData\Support\DataPropertyType;
use Spatie\LaravelData\Support\Transformation\TransformationContext;
use Spatie\LaravelData\Support\Transformation\TransformationContextFactory;
use Spatie\LaravelData\Support\Wrapping\WrapExecutionType;
use UnitEnum;

class Schema extends Data
{
    protected const CASTS = [
        'int'   => 'integer',
        'bool'  => 'boolean',
        'float' => 'number',
    ];

    public function __construct(
        public ?string $type = null,
        public ?bool $nullable = null,
        public ?string $format = null,
        public ?Schema $items = null,
        public ?string $ref = null,
        /** @var DataCollection<int,Property>|null */
        #[DataCollectionOf(Property::class)]
        public ?DataCollection $properties = null,
    ) {
        $this->type = self::CASTS[$this->type] ?? $this->type;
        $this->nullable = $this->nullable ?: null;
    }

    public static function fromReflectionProperty(ReflectionProperty $property): self
    {
        $type = $property->getType();

        if (! $type instanceof ReflectionNamedType) {
            throw new RuntimeException("Property {$property->getName()} has no type defined");
        }

        $type_name = $type->getName();
        $nullable = $type->allowsNull();

        if (is_a($type_name, LaravelData::class, true)) {
            /** @var class-string<LaravelData> $type_name */
            return self::fromData($type_name, $nullable);
        }

        if (is_a($type_name, DataCollection::class, true)) {
            // Try to get the collection type from attributes
            $dataCollectionAttribute = $property->getAttributes(DataCollectionOf::class)[0] ?? null;

            if ($dataCollectionAttribute) {
                /** @var class-string<LaravelData>|null $collectionType */
                $collectionType = $dataCollectionAttribute->getArguments()[0] ?? null;

                if ($collectionType && is_a($collectionType, LaravelData::class, true)) {
                    return self::fromDataCollection($collectionType, $nullable);
                }
            }

            // Fallback to docblock if attribute not found
            return self::fromListDocblock($property, $nullable);
        }

        return self::fromDataReflection(type_name: $type_name, reflection: $property, nullable: $nullable);
    }

    public static function fromDataReflection(
        string|ReflectionNamedType $type_name,
        ReflectionMethod|ReflectionFunction|ReflectionProperty|null $reflection = null,
        bool $nullable = false,
    ): self {
        if ($type_name instanceof ReflectionNamedType) {
            $nullable  = $type_name->allowsNull();
            $type_name = $type_name->getName();
        }

        $is_class = class_exists($type_name);

        if (is_a($type_name, DateTimeInterface::class, true)) {
            return self::fromDateTime($nullable);
        }

        if (! $is_class && 'array' !== $type_name) {
            return self::fromBuiltin($type_name, $nullable);
        }

        if (null !== $reflection && (is_a($type_name, DataCollection::class, true) || 'array' === $type_name)) {
            return self::fromListDocblock($reflection, $nullable);
        }

        if (is_a($type_name, UnitEnum::class, true)) {
            return self::fromEnum($type_name, $nullable);
        }

        return self::fromData($type_name, $nullable);
    }

    public static function fromParameterReflection(ReflectionParameter $parameter): self
    {
        $type = $parameter->getType();

        if (! $type instanceof ReflectionNamedType) {
            throw new RuntimeException("Parameter {$parameter->getName()} has no type defined");
        }

        $type_name = $type->getName();

        if (is_a($type_name, Model::class, true)) {
            /** @var Model */
            $instance  = (new $type_name());
            $type_name = $instance->getKeyType();
        }

        return new self(type: $type_name, nullable: $type->allowsNull());
    }

    public static function fromDataClass(string $class): self
    {
        return new self(
            type: 'object',
            properties: Property::fromDataClass($class),
        );
    }

    public function transform(
        null|TransformationContextFactory|TransformationContext $transformationContext = null,
    ): array {
        $array = array_filter(
            parent::transform($transformationContext),
            fn(mixed $value) => $value !== null && $value !== Optional::create(),
        );

        if (isset($array['ref'])) {
            $array['$ref'] = $array['ref'];
            unset($array['ref']);

            if ($array['nullable'] ?? false) {
                $array['allOf'][] = ['$ref' => $array['$ref']];
                unset($array['$ref']);
            }
        }

        if ($this->properties !== null) {
            $array['properties'] = collect($this->properties->items())
                ->mapWithKeys(fn(Property $property) => [$property->getName() => $property->type->transform($transformationContext)])
                ->toArray();
        }

        return $array;
    }

    protected static function fromBuiltin(string $type_name, bool $nullable): self
    {
        return new self(type: $type_name, nullable: $nullable);
    }

    protected static function fromDateTime(bool $nullable): self
    {
        return new self(type: 'string', format: 'date-time', nullable: $nullable);
    }

    protected static function fromEnum(string $type, bool $nullable): self
    {
        $enum = (new ReflectionEnum($type));

        $type_name = 'string';

        if ($enum->isBacked() && $type = $enum->getBackingType()) {
            $type_name = (string) $type;
        }

        return new self(type: $type_name, nullable: $nullable);
    }

    protected static function fromData(string $type_name, bool $nullable): self
    {
        $type_name = ltrim($type_name, '\\');

        if (! is_a($type_name, LaravelData::class, true)) {
            throw new RuntimeException("Type {$type_name} is not a Data class");
        }

        $scheme_name = last(explode('\\', $type_name));

        if (! $scheme_name || ! is_string($scheme_name)) {
            throw new RuntimeException("Cannot read basename from {$type_name}");
        }

        /** @var class-string<LaravelData> $type_name */
        OpenApi::addClassSchema($scheme_name, $type_name);

        return new self(
            ref: '#/components/schemas/' . $scheme_name,
            nullable: $nullable,
        );
    }

    protected static function fromDataCollection(string $type_name, bool $nullable): self
    {
        $type_name = ltrim($type_name, '\\');

        if (! is_a($type_name, LaravelData::class, true)) {
            throw new RuntimeException("Type {$type_name} is not a Data class");
        }

        return new self(
            type: 'array',
            items: self::fromData($type_name, false),
            nullable: $nullable,
        );
    }

    protected static function fromListDocblock(ReflectionMethod|ReflectionFunction|ReflectionProperty $reflection, bool $nullable): self
    {
        $docs = $reflection->getDocComment();
        if (! $docs) {
            throw new RuntimeException('Could not find required docblock of method/property ' . $reflection->getName());
        }

        $docblock = DocBlockFactory::createInstance()->create($docs);

        if ($reflection instanceof ReflectionMethod || $reflection instanceof ReflectionFunction) {
            $tag = $docblock->getTagsByName('return')[0] ?? null;
        } else {
            $tag = $docblock->getTagsByName('var')[0] ?? null;
        }

        /** @var null|Return_|Var_ $tag */
        if (! $tag) {
            throw new RuntimeException('Could not find required tag in docblock of method/property ' . $reflection->getName());
        }

        $tag_type = $tag->getType();

        if (! $tag_type instanceof AbstractList) {
            throw new RuntimeException('Return tag of method ' . $reflection->getName() . ' is not a list');
        }

        $class = $tag_type->getValueType()->__toString();

        if (! class_exists($class)) {
            throw new RuntimeException('Cannot resolve "' . $class . '". Make sure to use the full path in the phpdoc including the first "\".');
        }

        return new self(
            type: 'array',
            items: self::fromDataReflection($class),
            nullable: $nullable,
        );
    }
}
