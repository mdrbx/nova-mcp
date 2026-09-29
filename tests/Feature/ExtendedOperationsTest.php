<?php

declare(strict_types=1);

namespace Mdrbx\NovaMcp\Tests\Feature;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany as EloquentBelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany as EloquentHasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany as EloquentMorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Actions\DestructiveAction;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Fields\BelongsToMany;
use Laravel\Nova\Fields\HasMany;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\MorphToMany;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Nova;
use Laravel\Nova\Resource;
use Mdrbx\NovaMcp\Tests\Fixtures\Record;
use Mdrbx\NovaMcp\Tests\Fixtures\RecordPolicy;
use Mdrbx\NovaMcp\Tests\Fixtures\RecordResource;
use Mdrbx\NovaMcp\Tests\Fixtures\User;
use Mdrbx\NovaMcp\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class ExtendedOperationsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::table('records', fn (Blueprint $table) => $table->softDeletes());
        Schema::create('labels', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->foreignId('record_id')->nullable();
        });
        Schema::create('label_record', function (Blueprint $table): void {
            $table->foreignId('record_id');
            $table->foreignId('label_id');
            $table->string('note');
        });
        Schema::create('labelables', function (Blueprint $table): void {
            $table->foreignId('label_id');
            $table->morphs('labelable');
            $table->string('note');
        });
        Nova::serving(fn (): Nova => Nova::replaceResources([ManagedRecordResource::class, LabelResource::class]));
        Gate::policy(ManagedRecord::class, ManagedRecordPolicy::class);
        Gate::policy(Label::class, LabelPolicy::class);
    }

    #[DataProvider('relationships')]
    public function test_relationship_discovery_attachment_and_detachment_use_nova(string $relationship, string $table): void
    {
        $user = $this->user();
        $parent = $this->record($user);
        $label = Label::query()->create(['name' => 'Available']);
        $other = Label::query()->create(['name' => 'Other']);
        $token = $this->oauthToken($user, ['nova:read', 'nova:write']);
        $context = ['id' => (string) $parent->id, 'relationship' => $relationship];

        $discovery = $this->execute($token, [
            ['name' => 'nova-records-attachable', 'arguments' => $context],
            ['name' => 'nova-records-attach-fields', 'arguments' => $context],
        ]);

        $this->assertTrue($discovery['ok'], json_encode($discovery));
        $this->assertContains($label->id, array_column($this->entry($discovery)['body']['resources'], 'value'));
        $this->assertContains('note', array_column($this->entry($discovery, 1)['body'], 'attribute'));

        $attached = $this->execute($token, [['name' => 'nova-records-attach', 'arguments' => $context + [
            'related_id' => (string) $label->id, 'data' => ['note' => 'From MCP'],
        ]]]);

        $this->assertTrue($attached['ok']);
        $this->assertDatabaseHas($table, ['label_id' => $label->id, 'note' => 'From MCP']);
        $this->assertDatabaseMissing($table, ['label_id' => $other->id]);
        $this->assertDatabaseHas('action_events', ['user_id' => $user->id, 'name' => 'Attach']);

        $detached = $this->execute($token, [['name' => 'nova-records-detach', 'arguments' => $context + ['related_id' => (string) $label->id]]]);

        $this->assertTrue($detached['ok']);
        $this->assertDatabaseCount($table, 0);
        $this->assertModelExists($label);
    }

    public static function relationships(): array
    {
        return ['belongs to many' => ['labels', 'label_record'], 'morph to many' => ['morphedLabels', 'labelables']];
    }

    public function test_missing_relationship_policy_methods_keep_novas_defaults(): void
    {
        Gate::policy(ManagedRecord::class, RecordPolicy::class);
        $user = $this->user();
        $parent = $this->record($user);
        $label = Label::query()->create(['name' => 'Allowed without a relationship policy method']);
        $token = $this->oauthToken($user, ['nova:read', 'nova:write']);
        $context = ['id' => (string) $parent->id, 'relationship' => 'labels', 'related_id' => (string) $label->id];

        $attached = $this->execute($token, [['name' => 'nova-records-attach', 'arguments' => $context + ['data' => ['note' => 'Default permission']]]]);
        $this->assertTrue($attached['ok']);
        $this->assertDatabaseHas('label_record', ['record_id' => $parent->id, 'label_id' => $label->id]);

        $detached = $this->execute($token, [['name' => 'nova-records-detach', 'arguments' => $context]]);
        $this->assertTrue($detached['ok']);
        $this->assertDatabaseCount('label_record', 0);

        $added = $this->execute($token, [['name' => 'nova-records-add', 'arguments' => [
            'id' => (string) $parent->id, 'relationship' => 'children', 'data' => ['name' => 'Default add permission'],
        ]]]);
        $this->assertTrue($added['ok']);
        $this->assertDatabaseHas('labels', ['name' => 'Default add permission', 'record_id' => $parent->id]);
    }

    #[DataProvider('hiddenRelationshipOperations')]
    public function test_relationship_metadata_does_not_expose_hidden_fields(string $operation): void
    {
        $user = $this->user();
        $parent = $this->record($user);
        Label::query()->create(['name' => 'Hidden candidate']);
        $token = $this->oauthToken($user);

        $result = $this->execute($token, [['name' => "nova-records-{$operation}", 'arguments' => ['id' => (string) $parent->id, 'relationship' => 'hiddenLabels']]]);

        $this->assertFalse($result['ok']);
        $this->assertSame(404, $this->entry($result)['status']);
        $this->assertStringNotContainsString('Hidden candidate', json_encode($result));
    }

    public static function hiddenRelationshipOperations(): array
    {
        return [['attachable'], ['attach-fields']];
    }

    #[DataProvider('writeOperations')]
    public function test_read_only_configuration_and_read_scope_block_every_write(string $operation, bool $readOnly): void
    {
        $user = $this->user();
        $parent = $this->record($user);
        $label = Label::query()->create(['name' => 'Available']);
        $managed = ManagedRecord::findOrFail($parent->id);
        $managed->labels()->attach($label, ['note' => 'Existing']);
        if ($operation === 'restore') {
            $managed->delete();
        }
        $token = $this->oauthToken($user, $readOnly ? ['nova:read', 'nova:write'] : ['nova:read']);
        // Switching to read-only must take effect for tokens that already carry write access.
        config(['nova-mcp.read_only' => $readOnly]);
        $arguments = match ($operation) {
            'store' => ['data' => ['name' => 'Blocked']],
            'update' => ['id' => (string) $parent->id, 'data' => ['name' => 'Blocked']],
            'attach' => ['id' => (string) $parent->id, 'relationship' => 'labels', 'related_id' => (string) $label->id, 'data' => ['note' => 'Blocked']],
            'detach' => ['id' => (string) $parent->id, 'relationship' => 'labels', 'related_id' => (string) $label->id],
            'add' => ['id' => (string) $parent->id, 'relationship' => 'children', 'data' => ['name' => 'Blocked']],
            'run-action' => ['id' => (string) $parent->id, 'action' => 'rename-record', 'data' => ['name' => 'Blocked']],
            default => ['id' => (string) $parent->id],
        };

        $result = $this->execute($token, [['name' => "nova-records-{$operation}", 'arguments' => $arguments]]);

        $this->assertFalse($result['ok']);
        $this->assertDatabaseCount('records', 1);
        $this->assertDatabaseHas('records', ['id' => $parent->id, 'name' => 'Fixture record']);
        $this->assertSame($operation === 'restore', ManagedRecord::withTrashed()->findOrFail($parent->id)->trashed());
        $this->assertDatabaseCount('label_record', 1);
        $this->assertDatabaseCount('labels', 1);
        $this->assertDatabaseHas('label_record', ['label_id' => $label->id, 'note' => 'Existing']);
        $this->assertDatabaseCount('action_events', 0);
    }

    public static function writeOperations(): array
    {
        $cases = [];
        foreach (['store', 'update', 'destroy', 'restore', 'force-delete', 'attach', 'detach', 'add', 'run-action'] as $operation) {
            $cases["{$operation} read-only"] = [$operation, true];
            $cases["{$operation} missing write scope"] = [$operation, false];
        }

        return $cases;
    }

    public function test_read_only_discovery_keeps_reads_and_announces_configured_guardrails(): void
    {
        $token = $this->oauthToken($this->user(), ['nova:read', 'nova:write']);
        config(['nova-mcp.read_only' => true, 'nova-mcp.guardrails' => "Never publish customer data.\nAsk before exporting records."]);

        $catalog = $this->toolResult($this->rpc('tools/call', ['name' => 'search_tools', 'arguments' => ['query' => 'records', 'limit' => 50]], $token['access_token']));
        $names = array_column($catalog['tools'], 'name');
        $this->assertContains('nova-records-index', $names);
        $this->assertContains('nova-records-attachable', $names);
        foreach (['store', 'update', 'destroy', 'restore', 'force-delete', 'attach', 'detach', 'add', 'run-action'] as $operation) {
            $this->assertNotContains("nova-records-{$operation}", $names);
        }

        $response = $this->rpc('initialize', ['protocolVersion' => '2025-11-25', 'capabilities' => (object) [], 'clientInfo' => ['name' => 'Test', 'version' => '1']], $token['access_token'])->assertOk();
        $instructions = $response->json('result.instructions');
        $this->assertStringContainsString('This MCP server is read-only.', $instructions);
        $this->assertStringContainsString("Never publish customer data.\nAsk before exporting records.", $instructions);
        $this->assertStringContainsString('Discover Nova resources', $instructions);

        $this->assertTrue($this->execute($token, [['name' => 'nova-records-index', 'arguments' => []]])['ok']);
    }

    #[DataProvider('attachmentDenials')]
    public function test_attachment_rejects_policy_visibility_and_payload_bypasses(string $parentName, string $labelName, string $relationship, array $data, array $configuration, int $status): void
    {
        config($configuration);
        $user = $this->user();
        $parent = $this->record($user, ['name' => $parentName]);
        $label = Label::query()->create(['name' => $labelName]);
        $token = $this->oauthToken($user, ['nova:read', 'nova:write']);

        $result = $this->execute($token, [['name' => 'nova-records-attach', 'arguments' => [
            'id' => (string) $parent->id, 'relationship' => $relationship, 'related_id' => (string) $label->id,
            'data' => $data,
        ]]]);

        $this->assertFalse($result['ok']);
        $this->assertSame($status, $this->entry($result)['status']);
        $this->assertDatabaseCount('label_record', 0);
        $this->assertDatabaseMissing('action_events', ['name' => 'Attach']);
    }

    public static function attachmentDenials(): array
    {
        return [
            'attachAny policy' => ['No attachments', 'Available', 'labels', ['note' => 'MCP'], [], 403],
            'attach policy' => ['Parent', 'Denied', 'labels', ['note' => 'MCP'], [], 401],
            'relatable query' => ['Parent', 'Unrelatable', 'labels', ['note' => 'MCP'], [], 422],
            'hidden field' => ['Parent', 'Available', 'hiddenLabels', ['note' => 'MCP'], [], 404],
            'non-relationship attribute' => ['Parent', 'Available', 'name', ['note' => 'MCP'], [], 404],
            'excluded related resource' => ['Parent', 'Available', 'labels', ['note' => 'MCP'], ['nova-mcp.excluded_resources' => [LabelResource::class]], 403],
            'related resource outside allowlist' => ['Parent', 'Available', 'labels', ['note' => 'MCP'], ['nova-mcp.resources' => [ManagedRecordResource::class]], 403],
            'required pivot field' => ['Parent', 'Available', 'labels', [], [], 422],
            'target override' => ['Parent', 'Available', 'labels', ['note' => 'MCP', 'labels' => 'all'], [], 422],
            'bulk override' => ['Parent', 'Available', 'labels', ['note' => 'MCP', 'resources' => 'all'], [], 422],
            'context override' => ['Parent', 'Available', 'labels', ['note' => 'MCP', 'viaResourceId' => 'another'], [], 422],
        ];
    }

    #[DataProvider('additionPermissions')]
    public function test_adding_a_child_respects_the_parent_policy_and_creates_the_relationship(bool $allowed): void
    {
        $user = $this->user();
        $parent = $this->record($user, ['name' => $allowed ? 'Parent' : 'No children']);
        $token = $this->oauthToken($user, ['nova:read', 'nova:write']);
        $context = ['id' => (string) $parent->id, 'relationship' => 'children'];

        $fields = $this->execute($token, [['name' => 'nova-records-add-fields', 'arguments' => $context]]);
        $this->assertSame($allowed, $fields['ok']);

        $result = $this->execute($token, [['name' => 'nova-records-add', 'arguments' => $context + ['data' => ['name' => 'Child']]]]);

        $this->assertSame($allowed, $result['ok'], json_encode($result));
        $this->assertSame($allowed ? 201 : 403, $this->entry($result)['status']);
        $this->assertDatabaseCount('labels', $allowed ? 1 : 0);
        if ($allowed) {
            $this->assertDatabaseHas('labels', ['name' => 'Child', 'record_id' => $parent->id]);
        }
    }

    public static function additionPermissions(): array
    {
        return [[true], [false]];
    }

    public function test_relationship_operations_cannot_access_another_users_parent(): void
    {
        $parent = $this->record($this->user());
        $label = Label::query()->create(['name' => 'Available']);
        $token = $this->oauthToken($this->user(), ['nova:read', 'nova:write']);

        $result = $this->execute($token, [['name' => 'nova-records-attach', 'arguments' => [
            'id' => (string) $parent->id, 'relationship' => 'labels', 'related_id' => (string) $label->id, 'data' => ['note' => 'MCP'],
        ]]]);

        $this->assertFalse($result['ok']);
        $this->assertSame(404, $this->entry($result)['status']);
        $this->assertDatabaseCount('label_record', 0);
    }

    public function test_detach_policy_keeps_the_link(): void
    {
        $user = $this->user();
        $parent = $this->record($user);
        $label = Label::query()->create(['name' => 'Keep']);
        $managed = ManagedRecord::findOrFail($parent->id);
        $managed->labels()->attach($label, ['note' => 'Keep this link']);
        $token = $this->oauthToken($user, ['nova:read', 'nova:write']);

        $result = $this->execute($token, [['name' => 'nova-records-detach', 'arguments' => [
            'id' => (string) $parent->id, 'relationship' => 'labels', 'related_id' => (string) $label->id,
        ]]]);

        // Nova skips detach-denied records, just as it skips delete-denied records.
        $this->assertTrue($result['ok'], json_encode($result));
        $this->assertDatabaseHas('label_record', ['record_id' => $parent->id, 'label_id' => $label->id]);
        $this->assertDatabaseMissing('action_events', ['name' => 'Detach']);
    }

    #[DataProvider('softDeleteOperations')]
    public function test_soft_delete_operations_keep_native_policy_decisions(string $operation, bool $allowed, bool $defined = true): void
    {
        if (! $defined) {
            Gate::policy(ManagedRecord::class, RecordPolicy::class);
        }
        $user = $this->user();
        $record = $this->record($user, ['name' => $allowed ? 'Allowed' : 'Locked record']);
        $managed = ManagedRecord::findOrFail($record->id);
        $managed->delete();
        $token = $this->oauthToken($user, ['nova:read', 'nova:write']);

        $result = $this->execute($token, [['name' => "nova-records-{$operation}", 'arguments' => ['id' => (string) $record->id]]]);

        $this->assertTrue($result['ok'], json_encode($result));
        if ($allowed && $operation === 'force-delete') {
            $this->assertDatabaseMissing('records', ['id' => $record->id]);

            return;
        }

        $this->assertSame(! $allowed, ManagedRecord::withTrashed()->findOrFail($record->id)->trashed());
    }

    public static function softDeleteOperations(): array
    {
        return [
            'restore allowed' => ['restore', true],
            'restore denied' => ['restore', false],
            'force delete allowed' => ['force-delete', true],
            'force delete denied' => ['force-delete', false],
            'restore method missing' => ['restore', false, false],
            'force delete method missing' => ['force-delete', false, false],
        ];
    }

    public function test_replication_metadata_uses_the_replicate_policy_and_creation_uses_nova_fields(): void
    {
        $user = $this->user();
        $source = $this->record($user, ['name' => 'Original']);
        $denied = $this->record($user, ['name' => 'Locked record']);
        $token = $this->oauthToken($user, ['nova:read', 'nova:write']);

        $metadata = $this->execute($token, [['name' => 'nova-records-replicate-fields', 'arguments' => ['id' => (string) $source->id]]]);
        $this->assertTrue($metadata['ok']);
        $this->assertContains('Original', array_column($this->entry($metadata)['body']['fields'], 'value'));
        $this->assertNotContains('secret', array_column($this->entry($metadata)['body']['fields'], 'attribute'));

        $copy = $this->execute($token, [['name' => 'nova-records-store', 'arguments' => ['data' => ['name' => 'Copied']]]]);
        $this->assertTrue($copy['ok']);
        $this->assertDatabaseHas('records', ['owner_id' => $user->id, 'name' => 'Copied']);

        $blocked = $this->execute($token, [['name' => 'nova-records-replicate-fields', 'arguments' => ['id' => (string) $denied->id]]]);
        $this->assertFalse($blocked['ok']);
        $this->assertSame(403, $this->entry($blocked)['status']);
    }

    public function test_action_discovery_and_execution_preserve_validation_and_policy_fallbacks(): void
    {
        $user = $this->user();
        $record = $this->record($user);
        $locked = $this->record($user, ['name' => 'Locked record']);
        $token = $this->oauthToken($user, ['nova:read', 'nova:write']);

        $metadata = $this->execute($token, [['name' => 'nova-records-actions', 'arguments' => ['id' => (string) $record->id]]]);
        $this->assertTrue($metadata['ok']);
        $this->assertSame(['rename-record', 'destroy-record'], array_column($this->entry($metadata)['body']['actions'], 'uriKey'));

        $invalid = $this->execute($token, [['name' => 'nova-records-run-action', 'arguments' => ['id' => (string) $record->id, 'action' => 'rename-record', 'data' => []]]]);
        $this->assertFalse($invalid['ok']);
        $this->assertSame(422, $this->entry($invalid)['status']);

        $result = $this->execute($token, [['name' => 'nova-records-run-action', 'arguments' => ['id' => (string) $record->id, 'action' => 'rename-record', 'data' => ['name' => 'Renamed']]]]);
        $this->assertTrue($result['ok']);
        $this->assertDatabaseHas('records', ['id' => $record->id, 'name' => 'Renamed']);
        $this->assertDatabaseHas('action_events', ['name' => 'Rename Record', 'status' => 'finished']);

        $this->execute($token, [['name' => 'nova-records-run-action', 'arguments' => ['id' => (string) $locked->id, 'action' => 'rename-record', 'data' => ['name' => 'Bypassed']]]]);
        $this->assertDatabaseHas('records', ['id' => $locked->id, 'name' => 'Locked record']);

        $deleted = $this->execute($token, [['name' => 'nova-records-run-action', 'arguments' => ['id' => (string) $record->id, 'action' => 'destroy-record', 'data' => []]]]);
        $this->assertTrue($deleted['ok']);
        $this->assertSoftDeleted('records', ['id' => $record->id]);
    }

    #[DataProvider('explicitActionPermissions')]
    public function test_explicit_action_policies_override_novas_fallbacks(string $policy, string $action, string $name, bool $allowed): void
    {
        Gate::policy(ManagedRecord::class, $policy);
        $user = $this->user();
        $record = $this->record($user, ['name' => $name]);
        $token = $this->oauthToken($user, ['nova:read', 'nova:write']);

        $result = $this->execute($token, [['name' => 'nova-records-run-action', 'arguments' => [
            'id' => (string) $record->id, 'action' => $action, 'data' => ['name' => 'Changed by action'],
        ]]]);

        // A denied native action returns HTTP 200 with a danger message, without changing records.
        $this->assertSame(200, $this->entry($result)['status']);
        if (! $allowed) {
            $this->assertArrayHasKey('danger', $this->entry($result)['body']);
        }
        $this->assertDatabaseHas('records', ['id' => $record->id, 'name' => $allowed ? 'Changed by action' : $name, 'deleted_at' => null]);
    }

    public static function explicitActionPermissions(): array
    {
        return [
            'runAction allows despite update denial' => [AllowActionsPolicy::class, 'rename-record', 'Locked record', true],
            'runAction denies despite update permission' => [DenyActionsPolicy::class, 'rename-record', 'Editable', false],
            'runDestructiveAction denies despite delete permission' => [DenyActionsPolicy::class, 'destroy-record', 'Editable', false],
        ];
    }

    public function test_action_can_run_callback_is_enforced_by_nova(): void
    {
        Nova::serving(fn (): Nova => Nova::replaceResources([CallbackRecordResource::class]));
        $user = $this->user();
        $record = $this->record($user);
        $token = $this->oauthToken($user, ['nova:read', 'nova:write']);

        $result = $this->execute($token, [['name' => 'nova-records-run-action', 'arguments' => [
            'id' => (string) $record->id, 'action' => 'rename-record', 'data' => ['name' => 'Blocked'],
        ]]]);

        $this->assertArrayHasKey('danger', $this->entry($result)['body']);
        $this->assertDatabaseHas('records', ['id' => $record->id, 'name' => 'Fixture record']);
        $this->assertDatabaseCount('action_events', 0);
    }

    #[DataProvider('unsupportedActions')]
    public function test_hidden_queued_standalone_and_injected_actions_cannot_run(string $action, array $data, int $status): void
    {
        Queue::fake();
        $user = $this->user();
        $record = $this->record($user);
        $token = $this->oauthToken($user, ['nova:read', 'nova:write']);

        $result = $this->execute($token, [['name' => 'nova-records-run-action', 'arguments' => [
            'id' => (string) $record->id, 'action' => $action, 'data' => $data,
        ]]]);

        $this->assertFalse($result['ok']);
        $this->assertSame($status, $this->entry($result)['status']);
        $this->assertDatabaseHas('records', ['id' => $record->id, 'name' => 'Fixture record']);
        Queue::assertNothingPushed();
    }

    public static function unsupportedActions(): array
    {
        return [
            'queued' => ['queued-rename', ['name' => 'Blocked'], 422],
            'hidden' => ['hidden-rename', ['name' => 'Blocked'], 403],
            'standalone' => ['standalone-rename', ['name' => 'Blocked'], 422],
            'bulk injection' => ['rename-record', ['name' => 'Blocked', 'resources' => 'all'], 422],
            'pivot injection' => ['rename-record', ['name' => 'Blocked', 'pivotAction' => 'true'], 422],
            'action override' => ['rename-record', ['name' => 'Blocked', 'action' => 'destroy-record'], 422],
        ];
    }
}

class ManagedRecord extends Record
{
    use SoftDeletes;

    protected $table = 'records';

    public function labels(): EloquentBelongsToMany
    {
        return $this->belongsToMany(Label::class, 'label_record', 'record_id', 'label_id')->withPivot('note');
    }

    public function morphedLabels(): EloquentMorphToMany
    {
        return $this->morphToMany(Label::class, 'labelable')->withPivot('note');
    }

    public function hiddenLabels(): EloquentBelongsToMany
    {
        return $this->labels();
    }

    public function children(): EloquentHasMany
    {
        return $this->hasMany(Label::class, 'record_id');
    }
}

class Label extends Model
{
    public $timestamps = false;

    protected $guarded = [];
}

class ManagedRecordResource extends RecordResource
{
    public static $model = ManagedRecord::class;

    public function fields(NovaRequest $request): array
    {
        return [
            ...parent::fields($request),
            HasMany::make('Children', 'children', LabelResource::class),
            BelongsToMany::make('Labels', 'labels', LabelResource::class)->fields(fn (): array => [Text::make('Note')->rules('required')]),
            MorphToMany::make('Morphed labels', 'morphedLabels', LabelResource::class)->fields(fn (): array => [Text::make('Note')->rules('required')]),
            BelongsToMany::make('Hidden labels', 'hiddenLabels', LabelResource::class)->canSee(fn (): bool => false),
        ];
    }

    public function actions(NovaRequest $request): array
    {
        return [new RenameRecord, new DestroyRecord, new QueuedRename, (new HiddenRename)->canSee(fn (): bool => false), (new StandaloneRename)->standalone()];
    }
}

class LabelResource extends Resource
{
    public static $model = Label::class;

    public static $title = 'name';

    public static function uriKey(): string
    {
        return 'labels';
    }

    public function fields(NovaRequest $request): array
    {
        return [ID::make(), Text::make('Name')->rules('required')];
    }

    public static function relatableQuery(NovaRequest $request, Builder $query): Builder
    {
        return $query->where('name', '!=', 'Unrelatable');
    }
}

class CallbackRecordResource extends ManagedRecordResource
{
    public function actions(NovaRequest $request): array
    {
        return [(new RenameRecord)->canRun(fn (): bool => false)];
    }
}

class ManagedRecordPolicy extends RecordPolicy
{
    public function addLabel(User $user, ManagedRecord $record): bool
    {
        return $record->name !== 'No children';
    }

    public function attachAnyLabel(User $user, ManagedRecord $record): bool
    {
        return $record->name !== 'No attachments';
    }

    public function attachLabel(User $user, ManagedRecord $record, Label $label): bool
    {
        return $label->name !== 'Denied';
    }

    public function detachLabel(User $user, ManagedRecord $record, Label $label): bool
    {
        return $label->name !== 'Keep';
    }

    public function restore(User $user, ManagedRecord $record): bool
    {
        return $this->update($user, $record);
    }

    public function forceDelete(User $user, ManagedRecord $record): bool
    {
        return $this->update($user, $record);
    }
}

class LabelPolicy
{
    // Missing relationship methods deliberately exercise Nova's documented allow-by-default behavior.
    public function create(User $user): bool
    {
        return true;
    }
}

class AllowActionsPolicy extends ManagedRecordPolicy
{
    public function runAction(User $user, ManagedRecord $record, Action $action): bool
    {
        return true;
    }
}

class DenyActionsPolicy extends ManagedRecordPolicy
{
    public function runAction(User $user, ManagedRecord $record, Action $action): bool
    {
        return false;
    }

    public function runDestructiveAction(User $user, ManagedRecord $record, DestructiveAction $action): bool
    {
        return false;
    }
}

class RenameRecord extends Action
{
    public function fields(NovaRequest $request): array
    {
        return [Text::make('Name')->rules('required')];
    }

    public function handle(ActionFields $fields, Collection $models): void
    {
        foreach ($models as $model) {
            $model->update(['name' => $fields->name]);
        }
    }
}

class QueuedRename extends RenameRecord implements ShouldQueue {}

class HiddenRename extends RenameRecord {}

class StandaloneRename extends RenameRecord {}

class DestroyRecord extends DestructiveAction
{
    public function handle(ActionFields $fields, Collection $models): void
    {
        foreach ($models as $model) {
            $model->delete();
        }
    }
}
