<?php

declare(strict_types=1);

namespace Mdrbx\NovaMcp\Tests\Feature;

use Mdrbx\NovaMcp\Tests\Fixtures\ForbiddenResource;
use Mdrbx\NovaMcp\Tests\Fixtures\Record;
use Mdrbx\NovaMcp\Tests\Fixtures\RecordFilter;
use Mdrbx\NovaMcp\Tests\Fixtures\RecordResource;
use Mdrbx\NovaMcp\Tests\Fixtures\RestrictNovaRoute;
use Mdrbx\NovaMcp\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class ServerTest extends TestCase
{
    public function test_tool_search_catalog_respects_resource_visibility_and_read_scope(): void
    {
        $token = $this->oauthToken($this->user());

        $response = $this->rpc('tools/list', token: $token['access_token']);
        $response->assertOk();
        $this->assertSame(['search_tools', 'execute_tools'], array_column($response->json('result.tools'), 'name'));

        $catalog = $this->toolResult($this->rpc('tools/call', [
            'name' => 'search_tools',
            'arguments' => ['query' => '', 'limit' => 50],
        ], $token['access_token']));

        $names = array_column($catalog['tools'], 'name');
        $this->assertContains('nova-records-index', $names);
        $this->assertContains('nova-records-show', $names);
        $this->assertNotContains('nova-forbidden-records-index', $names);
        $this->assertNotContains('nova-records-store', $names);
        $this->assertNotContains('nova-records-update', $names);
        $this->assertNotContains('nova-records-destroy', $names);
    }

    public function test_index_preserves_query_scopes_search_pagination_and_hidden_fields(): void
    {
        $user = $this->user();
        $visible = $this->record($user, ['name' => 'Searchable owned record']);
        $this->record($user, ['name' => 'Unmatched owned record']);
        $this->record($this->user(), ['name' => 'Searchable other record']);
        $token = $this->oauthToken($user);

        $result = $this->execute($token, [[
            'name' => 'nova-records-index',
            'arguments' => ['query' => ['search' => 'Searchable', 'perPage' => 1]],
        ]]);

        $this->assertTrue($result['ok']);
        $payload = $this->entry($result);
        $this->assertSame(200, $payload['status']);
        $this->assertCount(1, $payload['body']['resources']);
        $this->assertSame((string) $visible->id, (string) $payload['body']['resources'][0]['id']['value']);
        $this->assertNotContains('secret', array_column($payload['body']['resources'][0]['fields'], 'attribute'));
        $this->assertSame(1, $payload['body']['total']);
    }

    public function test_detail_hides_another_users_record_with_404(): void
    {
        $record = $this->record($this->user());
        $token = $this->oauthToken($this->user());

        $result = $this->execute($token, [[
            'name' => 'nova-records-show',
            'arguments' => ['id' => (string) $record->id],
        ]]);

        $this->assertFalse($result['ok']);
        $this->assertSame(404, $this->entry($result)['status']);
    }

    public function test_a_full_page_fits_the_native_tool_search_result_limit(): void
    {
        $user = $this->user();
        for ($index = 0; $index < 50; $index++) {
            $this->record($user, ['name' => "Record {$index}"]);
        }
        $token = $this->oauthToken($user);

        $result = $this->execute($token, [[
            'name' => 'nova-records-index',
            'arguments' => ['query' => ['perPage' => 50]],
        ]]);

        $this->assertTrue($result['ok']);
        $this->assertCount(50, $this->entry($result)['body']['resources']);
        $this->assertSame(50, $this->entry($result)['body']['total']);
    }

    public function test_batched_tools_keep_their_route_identity_and_restore_request_context(): void
    {
        $user = $this->user();
        $first = $this->record($user, ['name' => 'First owned record']);
        $second = $this->record($user, ['name' => 'Second owned record']);
        $token = $this->oauthToken($user);
        $guard = auth()->getDefaultDriver();

        $result = $this->execute($token, [
            ['name' => 'nova-records-show', 'arguments' => ['id' => (string) $first->id]],
            ['name' => 'nova-records-show', 'arguments' => ['id' => (string) $second->id]],
        ]);

        $this->assertTrue($result['ok']);
        $this->assertSame('First owned record', $this->entry($result)['body']['title']);
        $this->assertSame('Second owned record', $this->entry($result, 1)['body']['title']);
        $this->assertSame('nova-mcp', request()->path());
        $this->assertSame('nova-mcp.endpoint', app('router')->currentRouteName());
        $this->assertSame($guard, auth()->getDefaultDriver());
    }

    public function test_read_scope_cannot_execute_a_known_mutation_name(): void
    {
        $token = $this->oauthToken($this->user());

        $result = $this->execute($token, [[
            'name' => 'nova-records-store',
            'arguments' => ['data' => ['name' => 'Forbidden creation']],
        ]]);

        $this->assertFalse($result['ok']);
        $this->assertDatabaseMissing('records', ['name' => 'Forbidden creation']);
    }

    public function test_creation_uses_nova_fields_hooks_and_action_history(): void
    {
        $user = $this->user();
        $token = $this->oauthToken($user, ['nova:read', 'nova:write']);

        $result = $this->execute($token, [[
            'name' => 'nova-records-store',
            'arguments' => ['data' => ['name' => 'Created through Nova', 'secret' => 'Client override']],
        ]]);

        $this->assertTrue($result['ok']);
        $this->assertSame(201, $this->entry($result)['status']);
        $this->assertDatabaseHas('records', ['name' => 'Created through Nova', 'owner_id' => $user->id, 'secret' => 'server secret']);
        $this->assertDatabaseHas('action_events', ['user_id' => $user->id, 'name' => 'Create', 'actionable_type' => Record::class]);
    }

    public function test_creation_returns_nova_validation_errors_without_writing(): void
    {
        $token = $this->oauthToken($this->user(), ['nova:read', 'nova:write']);

        $result = $this->execute($token, [[
            'name' => 'nova-records-store',
            'arguments' => ['data' => ['name' => '']],
        ]]);

        $this->assertFalse($result['ok']);
        $this->assertSame(422, $this->entry($result)['status']);
        $this->assertSame(['The Name field is required.'], $this->entry($result)['body']['errors']['name']);
        $this->assertDatabaseCount('records', 0);
    }

    #[DataProvider('updatePermissions')]
    public function test_updates_apply_the_resource_policy(string $name, bool $allowed): void
    {
        $user = $this->user();
        $record = $this->record($user, ['name' => $name]);
        $token = $this->oauthToken($user, ['nova:read', 'nova:write']);

        $result = $this->execute($token, [[
            'name' => 'nova-records-update',
            'arguments' => ['id' => (string) $record->id, 'data' => ['name' => 'Updated through Nova']],
        ]]);

        $this->assertSame($allowed, $result['ok']);
        $this->assertSame($allowed ? 200 : 403, $this->entry($result)['status']);
        $this->assertDatabaseHas('records', ['id' => $record->id, 'name' => $allowed ? 'Updated through Nova' : $name]);
    }

    public static function updatePermissions(): array
    {
        return ['allowed' => ['Editable record', true], 'policy denied' => ['Locked record', false]];
    }

    #[DataProvider('deletePermissions')]
    public function test_deletion_only_removes_the_selected_authorized_record(bool $allowed): void
    {
        $user = $this->user();
        $record = $this->record($allowed ? $user : $this->user());
        $other = $this->record($this->user());
        $token = $this->oauthToken($user, ['nova:read', 'nova:write']);

        $result = $this->execute($token, [[
            'name' => 'nova-records-destroy',
            'arguments' => ['id' => (string) $record->id],
        ]]);

        // Nova deliberately skips records denied by the delete policy and returns 200.
        $this->assertTrue($result['ok']);
        $this->assertDatabaseCount('records', $allowed ? 1 : 2);
        $this->assertModelExists($other);
    }

    public static function deletePermissions(): array
    {
        return ['allowed' => [true], 'policy denied' => [false]];
    }

    #[DataProvider('injectedArguments')]
    public function test_route_and_bulk_selection_injection_cannot_redirect_a_mutation(array $arguments): void
    {
        $user = $this->user();
        $record = $this->record($user);
        $token = $this->oauthToken($user, ['nova:read', 'nova:write']);
        $arguments['id'] ??= (string) $record->id;

        $result = $this->execute($token, [['name' => 'nova-records-update', 'arguments' => $arguments]]);

        $this->assertFalse($result['ok']);
        $this->assertDatabaseHas('records', ['id' => $record->id, 'name' => 'Fixture record']);
    }

    public static function injectedArguments(): array
    {
        return [
            'path traversal' => [['id' => '../forbidden-records/1', 'data' => ['name' => 'Injected']]],
            'resource' => [['data' => ['name' => 'Injected', 'resource' => 'forbidden-records']]],
            'identifier' => [['data' => ['name' => 'Injected', 'resourceId' => 'another-record']]],
            'bulk' => [['data' => ['name' => 'Injected', 'resources' => 'all']]],
            'method' => [['data' => ['name' => 'Injected', '_method' => 'DELETE']]],
            'relationship' => [['data' => ['name' => 'Injected', 'viaResource' => 'forbidden-records']]],
        ];
    }

    public function test_field_and_filter_metadata_comes_from_authorized_nova_definitions(): void
    {
        $user = $this->user();
        $record = $this->record($user);
        $token = $this->oauthToken($user, ['nova:read', 'nova:write']);

        $result = $this->execute($token, [
            ['name' => 'nova-records-create-fields', 'arguments' => []],
            ['name' => 'nova-records-update-fields', 'arguments' => ['id' => (string) $record->id]],
            ['name' => 'nova-records-filters', 'arguments' => []],
        ]);

        $this->assertTrue($result['ok']);
        foreach ([0, 1] as $index) {
            $attributes = array_column($this->entry($result, $index)['body']['fields'], 'attribute');
            $this->assertContains('name', $attributes);
            $this->assertNotContains('secret', $attributes);
        }
        $this->assertSame([RecordFilter::class], array_column($this->entry($result, 2)['body'], 'class'));
    }

    public function test_native_route_middleware_still_restricts_tool_execution(): void
    {
        $token = $this->oauthToken($this->user());
        $route = collect(app('router')->getRoutes())->first(fn ($route): bool => $route->uri() === 'nova-api/{resource}' && in_array('GET', $route->methods(), true));
        $route->middleware(RestrictNovaRoute::class);

        $result = $this->execute($token, [['name' => 'nova-records-index', 'arguments' => []]]);

        $this->assertFalse($result['ok']);
        $this->assertSame(403, $this->entry($result)['status']);
        $this->assertSame(['message'], array_keys($this->entry($result)['body']));
    }

    public function test_reserved_nova_endpoint_cannot_be_used_as_a_record_identifier(): void
    {
        $token = $this->oauthToken($this->user());

        $result = $this->execute($token, [['name' => 'nova-records-show', 'arguments' => ['id' => 'filters']]]);

        $this->assertFalse($result['ok']);
        $this->assertSame(422, $this->entry($result)['status']);
    }

    #[DataProvider('resourceRestrictions')]
    public function test_resource_configuration_cannot_be_bypassed_with_a_known_tool_name(array $configuration): void
    {
        config($configuration);
        $token = $this->oauthToken($this->user());

        $result = $this->execute($token, [['name' => 'nova-records-index', 'arguments' => []]]);

        $this->assertFalse($result['ok']);
    }

    public static function resourceRestrictions(): array
    {
        return [
            'outside allowlist' => [['nova-mcp.resources' => [ForbiddenResource::class]]],
            'explicitly excluded' => [['nova-mcp.excluded_resources' => [RecordResource::class]]],
        ];
    }

    public function test_bearer_requests_work_with_csrf_enforcement_enabled_for_browser_routes(): void
    {
        $user = $this->user();
        $token = $this->oauthToken($user, ['nova:read', 'nova:write']);
        $this->app['env'] = 'production';

        $result = $this->execute($token, [
            ['name' => 'nova-records-index', 'arguments' => []],
            ['name' => 'nova-records-store', 'arguments' => ['data' => ['name' => 'Bearer without browser CSRF']]],
        ]);

        $this->assertTrue($result['ok']);
        $this->assertDatabaseHas('records', ['name' => 'Bearer without browser CSRF', 'owner_id' => $user->id]);
    }

    public function test_the_current_mcp_protocol_discovers_and_searches_tools_with_mirrored_headers(): void
    {
        $token = $this->oauthToken($this->user());
        $meta = [
            'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
            'io.modelcontextprotocol/clientCapabilities' => (object) [],
        ];

        $this->rpc('server/discover', ['_meta' => $meta], $token['access_token'], [
            'MCP-Protocol-Version' => '2026-07-28',
            'Mcp-Method' => 'server/discover',
        ])->assertOk()->assertJsonPath('result.supportedVersions.0', '2026-07-28');

        $result = $this->toolResult($this->rpc('tools/call', [
            '_meta' => $meta,
            'name' => 'search_tools',
            'arguments' => ['query' => 'records', 'limit' => 10],
        ], $token['access_token'], [
            'MCP-Protocol-Version' => '2026-07-28',
            'Mcp-Method' => 'tools/call',
            'Mcp-Name' => 'search_tools',
        ]));

        $this->assertContains('nova-records-index', array_column($result['tools'], 'name'));
    }
}
