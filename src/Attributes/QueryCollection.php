<?php

namespace Nodesol\LaraQL\Attributes;

use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Str;

class QueryCollection implements Operation
{
    private \ReflectionClass $reflector;

    public function __construct(
        public string $class,
        public ?string $name = null,
        public ?string $return_type = null,
        public ?array $directives = [],
        public ?array $filters = ['where: _ @whereConditions(column: {})', 'first: Int! = 10', 'page: Int', 'orderBy: _ @orderBy'],
        public ?string $query = '@paginate(defaultCount: 10)',
        public bool|string|null $authorize = null,
    ) {
        $this->reflector = new \ReflectionClass($this->class);
    }

    public function getName(): string
    {
        return $this->name ?? Str::snake(Str::plural($this->reflector->getShortName()));
    }

    public function getReturnType(): string
    {
        return $this->return_type ?? ("[{$this->reflector->getShortName()}!]!");
    }

    public function getAuthorize(): string
    {
        if (!is_null($this->authorize)) {
            if (is_string($this->authorize)) {
                return $this->authorize;
            }

            if ($this->authorize) {
                return '@canModel(ability: "viewAny")';
            }

        }

        return '';
    }

    public function getSchema(): string
    {
        $directives = implode(' ', $this->directives);
        $filters = '';

        if (is_array($this->filters) && count($this->filters)) {
            $filterDefinitions = array_merge(
                $this->filters ?? [],
                $this->filters_override ?? []
            );

            $filterDefinitions = array_map(
                fn(string $filter): string =>
                    trim($filter) === 'orderBy: _ @orderBy'
                    ? $this->getOrderByFilter()
                    : $filter,
                $filterDefinitions
            );

            $filters = implode(" \n ", $filterDefinitions);
            $filters = <<<ENDDATA
                (
                    $filters
                )
            ENDDATA;
        }

        return <<<ENDDATA
        extend type Query $directives {
            {$this->getName()} $filters: {$this->getReturnType()} {$this->getAuthorize()} {$this->query}
        }
        ENDDATA;
    }

    private function getOrderByFilter(): string
    {
        if (!$this->reflector->isSubclassOf(EloquentModel::class)) {
            return 'orderBy: _ @orderBy';
        }

        $model = new $this->class;
        $relations = [];

        foreach ($this->reflector->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $name = $method->getName();
            $returnType = $method->getReturnType();

            if (
                $method->isStatic()
                || $method->getNumberOfParameters() !== 0
                || !$returnType instanceof \ReflectionNamedType
                || !is_a($returnType->getName(), Relation::class, true)
                || in_array($name, $model->getHidden(), true)
                || in_array($name, ['column', 'order'], true)
            ) {
                continue;
            }

            $relation = Relation::noConstraints(fn() => $method->invoke($model));

            if (!$relation instanceof Relation || $relation instanceof MorphTo) {
                continue;
            }

            $related = $relation->getRelated();
            $columns = $related->getConnection()
                ->getSchemaBuilder()
                ->getColumnListing($related->getTable());

            $columns = array_values(array_diff($columns, $related->getHidden()));

            $relationName = json_encode($name, JSON_THROW_ON_ERROR);
            $fieldName = 'orderBy' . Str::studly($name);

            if ($columns === []) {
                $relations[] = "$fieldName: _ @orderBy(relations: [{ relation: $relationName }])";
                continue;
            }

            $columnNames = json_encode($columns, JSON_THROW_ON_ERROR);
            $relations[] = "$fieldName: _ @orderBy(relations: [{ relation: $relationName, columns: $columnNames }])";
        }

        if ($relations === []) {
            return 'orderBy: _ @orderBy';
        }

        return implode(" \n ", array_merge(['orderBy: _ @orderBy'], $relations));
    }
}
