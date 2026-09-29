<?php

declare(strict_types=1);

namespace Mdrbx\NovaMcp;

use Laravel\Mcp\Server as McpServer;
use Laravel\Mcp\Server\Tools\ToolSearch;
use Laravel\Nova\Nova;

class Server extends McpServer
{
    protected string $name = 'Nova MCP';

    protected string $version = '0.2.0';

    protected string $instructions = <<<'MARKDOWN'
Discover Nova resources and operations with search_tools, then call their exact names using execute_tools.
Search for a resource label or URI key. An empty search browses the catalog.
Before writing, read create-fields or update-fields for Nova field attributes, options and validation rules.
For duplication, read replicate-fields with the source ID, then submit the chosen field values with store.
For relationships, read show to find the relationship attribute, then attachable and attach-fields before attach. Detach targets one related ID.
For HasMany or MorphMany relationships, read add-fields then use add to create a child through Nova and its add{Model} policy.
Read actions for a record before run-action; use its exact action key and field attributes. Only synchronous, non-standalone resource actions are exposed.
Results contain status and body (Nova API JSON), not raw database rows.
Writes require nova:write and remain subject to Nova permissions and validation.
Index query accepts search, page, perPage, orderBy, orderByDirection and Nova's base64-encoded filters.
Mutations target one explicit ID. Bulk selections, queued actions, uploads, lenses and custom tool endpoints are not exposed.
Nova may skip a denied mutation or return a danger message with HTTP 200. Inspect the response body and record state before reporting success.
MARKDOWN;

    protected function boot(): void
    {
        $this->instructions .= config('nova-mcp.read_only', false)
            ? "\nThis MCP server is read-only. No write operation is available, regardless of OAuth scopes."
            : "\nWrite operations require both nova:write and Nova authorization.";

        $guardrails = trim((string) config('nova-mcp.guardrails', ''));
        if ($guardrails !== '') {
            $this->instructions .= "\n\nApplication guardrails:\n{$guardrails}";
        }

        $tools = [];

        // Visibility and OAuth scopes are request-specific; never cache this catalog publicly.
        foreach (Nova::authorizedResources(request()) as $resource) {
            if (in_array($resource, config('nova-mcp.excluded_resources', []), true)) {
                continue;
            }

            $included = config('nova-mcp.resources', []);

            if ($included !== [] && ! in_array($resource, $included, true)) {
                continue;
            }

            foreach (ResourceTool::OPERATIONS as $operation) {
                $tools[] = new ResourceTool($resource, $operation);
            }
        }

        $this->tools = [ToolSearch::class => $tools];
    }
}
