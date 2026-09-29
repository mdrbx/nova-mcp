<?php

declare(strict_types=1);

namespace Mdrbx\NovaMcp;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Nova\Resource as NovaResource;

class ResourceTool extends Tool
{
    public const array OPERATIONS = ['index', 'show', 'create-fields', 'update-fields', 'filters', 'store', 'update', 'destroy'];

    /**
     * @param  class-string<NovaResource<Model>>  $resource
     */
    public function __construct(private readonly string $resource, private readonly string $operation)
    {
        $key = $resource::uriKey();
        $label = $resource::label();
        $this->name = "nova-{$key}-{$operation}";
        $this->description = "Nova {$label} ({$key}): {$operation}. Uses the resource's Nova API, permissions and validation.";
    }

    public function shouldRegister(): bool
    {
        $scopes = request()->attributes->get('nova-mcp.scopes', []);

        if (! is_array($scopes) || ! Scopes::allows($scopes)) {
            return false;
        }

        if ($this->writes() && ! in_array(Scopes::WRITE, $scopes, true)) {
            return false;
        }

        if (! $this->resource::authorizedToViewAny(request())) {
            return false;
        }

        if (in_array($this->operation, ['create-fields', 'store'], true)) {
            return $this->resource::authorizedToCreate(request());
        }

        return true;
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        $properties = [];

        if (in_array($this->operation, ['show', 'update-fields', 'update', 'destroy'], true)) {
            $properties['id'] = $schema->string()->max(255)->description('One exact Nova resource ID.')->required();
        }

        if ($this->operation === 'index') {
            $properties['query'] = $schema->object([
                'search' => $schema->string()->max(512),
                'page' => $schema->integer()->min(1),
                'perPage' => $schema->integer()->min(1)->max(100),
                'orderBy' => $schema->string()->max(255),
                'orderByDirection' => $schema->string()->enum(['asc', 'desc']),
                'filters' => $schema->string()->max(16384)->description('Base64-encoded Nova filter array; discover available filters first.'),
            ])->withoutAdditionalProperties();
        }

        if (in_array($this->operation, ['store', 'update'], true)) {
            $properties['data'] = $schema->object()->description('Nova form attributes. Discover fields first; Nova validates and fills them.')->required();
        }

        return $properties;
    }

    /** @return array<string, bool> */
    public function annotations(): array
    {
        return [
            'readOnlyHint' => ! $this->writes(),
            'destructiveHint' => $this->writes(),
            'idempotentHint' => ! $this->writes(),
            'openWorldHint' => $this->writes(),
        ];
    }

    public function handle(Request $request, NovaApi $api): Response
    {
        if (! $this->shouldRegister()) {
            return Response::error('This Nova operation is not available.');
        }

        $needsId = in_array($this->operation, ['show', 'update-fields', 'update', 'destroy'], true);
        $needsData = in_array($this->operation, ['store', 'update'], true);

        $arguments = $request->validate([
            'id' => [$needsId ? 'required' : 'prohibited', 'string', 'max:255', 'not_regex:~[/\x5C\x00-\x1F\x7F]~'],
            'data' => [$needsData ? 'present' : 'prohibited', 'array'],
            'query' => [$this->operation === 'index' ? 'sometimes' : 'prohibited', 'array:search,page,perPage,orderBy,orderByDirection,filters'],
            'query.search' => ['sometimes', 'string', 'max:512'],
            'query.page' => ['sometimes', 'integer', 'min:1'],
            'query.perPage' => ['sometimes', 'integer', 'between:1,100'],
            'query.orderBy' => ['sometimes', 'string', 'max:255'],
            'query.orderByDirection' => ['sometimes', 'in:asc,desc'],
            'query.filters' => ['sometimes', 'string', 'max:16384'],
        ]);

        $result = $api->dispatch(
            match ($this->operation) {
                'create-fields' => 'createFields',
                'update-fields' => 'updateFields',
                default => $this->operation,
            },
            $this->resource::uriKey(),
            $arguments['id'] ?? null,
            $arguments['query'] ?? [],
            $arguments['data'] ?? [],
        );

        if ($this->operation === 'index' && $result['status'] === 200) {
            // Repeated Nova UI field metadata exceeds ToolSearch's output budget on ordinary 50-row pages.
            // Keep Nova-authorized values; complete field metadata remains available through the form/detail tools.
            $result['body']['resources'] = array_map(function (array $resource): array {
                $resource['id'] = Arr::only($resource['id'], ['attribute', 'name', 'value']);
                $resource['fields'] = array_map(
                    fn (array $field): array => Arr::only($field, ['attribute', 'name', 'value']),
                    $resource['fields'],
                );
                unset($resource['actions']);

                return $resource;
            }, $result['body']['resources']);
        }

        $json = json_encode($result, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);

        return $result['status'] >= 200 && $result['status'] < 300
            ? Response::text($json)
            : Response::error($json);
    }

    private function writes(): bool
    {
        return in_array($this->operation, ['store', 'update', 'destroy'], true);
    }
}
