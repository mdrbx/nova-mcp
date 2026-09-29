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
    public const array OPERATIONS = [
        'index', 'show', 'create-fields', 'update-fields', 'replicate-fields', 'filters',
        'attachable', 'attach-fields', 'add-fields', 'actions',
        'store', 'update', 'destroy', 'restore', 'force-delete', 'attach', 'detach', 'add', 'run-action',
    ];

    private const array WRITES = ['store', 'update', 'destroy', 'restore', 'force-delete', 'attach', 'detach', 'add', 'run-action'];

    private const array WITH_ID = ['show', 'update-fields', 'replicate-fields', 'update', 'destroy', 'restore', 'force-delete', 'attachable', 'attach-fields', 'attach', 'detach', 'add-fields', 'add', 'actions', 'run-action'];

    private const array WITH_RELATIONSHIP = ['attachable', 'attach-fields', 'attach', 'detach', 'add-fields', 'add'];

    private const array WITH_DATA = ['store', 'update', 'attach', 'add', 'run-action'];

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

        if ($this->writes() && config('nova-mcp.read_only', false)) {
            return false;
        }

        if (in_array($this->operation, ['restore', 'force-delete'], true) && ! $this->resource::softDeletes()) {
            return false;
        }

        if (! $this->resource::authorizedToViewAny(request())) {
            return false;
        }

        if (in_array($this->operation, ['create-fields', 'replicate-fields', 'store'], true)) {
            return $this->resource::authorizedToCreate(request());
        }

        return true;
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        $properties = [];

        if (in_array($this->operation, self::WITH_ID, true)) {
            $properties['id'] = $schema->string()->max(255)->description('One exact Nova resource ID.')->required();
        }

        if (in_array($this->operation, self::WITH_RELATIONSHIP, true)) {
            $properties['relationship'] = $schema->string()->max(255)->description('Visible Nova relationship attribute from the parent resource detail.')->required();
        }

        if (in_array($this->operation, ['attach', 'detach'], true)) {
            $properties['related_id'] = $schema->string()->max(255)->description('One exact related resource ID.')->required();
        }

        if ($this->operation === 'run-action') {
            $properties['action'] = $schema->string()->max(255)->description('Exact synchronous action URI key returned by actions.')->required();
        }

        if ($this->operation === 'index') {
            $properties['query'] = $schema->object([
                'search' => $schema->string()->max(512),
                'page' => $schema->integer()->min(1),
                'perPage' => $schema->integer()->min(1)->max(100),
                'orderBy' => $schema->string()->max(255),
                'orderByDirection' => $schema->string()->enum(['asc', 'desc']),
                'filters' => $schema->string()->max(16384)->description('Base64-encoded Nova filter array; discover available filters first.'),
                'trashed' => $schema->string()->enum(['with', 'only', 'without']),
            ])->withoutAdditionalProperties();
        }

        if ($this->operation === 'attachable') {
            $properties['query'] = $schema->object(['search' => $schema->string()->max(512)])->withoutAdditionalProperties();
        }

        if (in_array($this->operation, self::WITH_DATA, true)) {
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

        $needsId = in_array($this->operation, self::WITH_ID, true);
        $needsData = in_array($this->operation, self::WITH_DATA, true);
        $needsRelationship = in_array($this->operation, self::WITH_RELATIONSHIP, true);

        $arguments = $request->validate([
            'id' => [$needsId ? 'required' : 'prohibited', 'string', 'max:255', 'not_regex:~[/\x5C\x00-\x1F\x7F]~'],
            'data' => [$needsData ? 'present' : 'prohibited', 'array'],
            'relationship' => [$needsRelationship ? 'required' : 'prohibited', 'string', 'max:255', 'regex:/\A[a-zA-Z0-9_-]+\z/'],
            'related_id' => [in_array($this->operation, ['attach', 'detach'], true) ? 'required' : 'prohibited', 'string', 'max:255', 'not_regex:~[/\x5C\x00-\x1F\x7F]~'],
            'action' => [$this->operation === 'run-action' ? 'required' : 'prohibited', 'string', 'max:255', 'regex:/\A[a-zA-Z0-9_-]+\z/'],
            'query' => [in_array($this->operation, ['index', 'attachable'], true) ? 'sometimes' : 'prohibited', $this->operation === 'attachable' ? 'array:search' : 'array:search,page,perPage,orderBy,orderByDirection,filters,trashed'],
            'query.search' => ['sometimes', 'string', 'max:512'],
            'query.page' => ['sometimes', 'integer', 'min:1'],
            'query.perPage' => ['sometimes', 'integer', 'between:1,100'],
            'query.orderBy' => ['sometimes', 'string', 'max:255'],
            'query.orderByDirection' => ['sometimes', 'in:asc,desc'],
            'query.filters' => ['sometimes', 'string', 'max:16384'],
            'query.trashed' => ['sometimes', 'in:with,only,without'],
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
            Arr::only($arguments, ['relationship', 'related_id', 'action']),
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
        return in_array($this->operation, self::WRITES, true);
    }
}
