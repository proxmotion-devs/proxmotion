<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Infrastructure\NodeNotRemovable;
use App\Domain\Infrastructure\NodeRemover;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\NodeRequest;
use App\Models\Node;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class NodeController extends Controller
{
    /**
     * Update the placement settings of a node. Everything else comes from the sync.
     */
    public function update(NodeRequest $request, Node $node): RedirectResponse
    {
        $node->update($request->validated());

        $this->toast('success', 'Node :name updated.', ['name' => $node->name]);

        return back();
    }

    /**
     * Remove a node Proxmox no longer reports from the panel (docs/adr/0057).
     */
    public function destroy(Request $request, Node $node, NodeRemover $remover, #[CurrentUser] User $admin): RedirectResponse
    {
        try {
            $remover->remove($node, $admin, $request->ip());
        } catch (NodeNotRemovable $e) {
            $this->toast('error', $e->getMessage(), $e->replace);

            return back();
        }

        $this->toast('success', 'Node :name removed from the panel. Nothing was changed on Proxmox.', ['name' => $node->name]);

        return back();
    }
}
