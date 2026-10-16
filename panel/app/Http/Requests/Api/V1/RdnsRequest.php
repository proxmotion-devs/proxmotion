<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\RdnsRequest as AdminRdnsRequest;

/**
 * POST /api/v1/servers/{id}/rdns: the same rules as in the admin panel. The
 * token scope is checked by the route; the server's state by the controller.
 */
class RdnsRequest extends AdminRdnsRequest
{
    public function authorize(): bool
    {
        return true;
    }
}
