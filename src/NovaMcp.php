<?php

declare(strict_types=1);

namespace Mdrbx\NovaMcp;

use Illuminate\Http\Request;
use Laravel\Nova\Menu\MenuItem;
use Laravel\Nova\Tool;

class NovaMcp extends Tool
{
    public function menu(Request $request): MenuItem
    {
        // A regular navigation keeps this tool independent of Nova's frontend build and Inertia version.
        return MenuItem::externalLink(__('MCP connections'), route('nova-mcp.connections.index'));
    }
}
