<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\NodeRequest;
use App\Models\Node;
use Illuminate\Http\RedirectResponse;

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
}
