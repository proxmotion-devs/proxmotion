<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Servers\ConsoleLinks;
use App\Enums\ApiScope;
use App\Enums\ServerStatus;
use App\Http\Controllers\Concerns\RendersConsole;
use App\Models\ApiToken;
use App\Models\Server;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * The console for customers of the billing system (docs/adr/0008), the
 * panel's only customer-facing page. A signed single-use link grants this
 * browser session the console of one server; reconnects use that grant.
 */
class CustomerConsoleController extends Controller
{
    use RendersConsole;

    public function link(Request $request, Server $server, string $nonce, ConsoleLinks $links): RedirectResponse|Response
    {
        $token = $links->redeem($server, $nonce);

        if ($token === null) {
            return $this->unavailable($server, __('This console link has already been used or has expired. Open the console again from your client area.'));
        }

        $request->session()->put($this->grantKey($server), [
            'api_token_id' => $token->id,
            'expires_at' => now()->addMinutes(max(1, (int) config('proxmotion.console.max_minutes')))->getTimestamp(),
        ]);

        return to_route('console.show', $server);
    }

    public function show(Request $request, Server $server): Response
    {
        $grant = $request->session()->get($this->grantKey($server));
        $token = is_array($grant) && is_int($grant['expires_at'] ?? null) && $grant['expires_at'] > now()->getTimestamp()
            ? ApiToken::usableFor($grant['api_token_id'] ?? null, ApiScope::ServersSecrets)
            : null;

        if ($token === null) {
            $request->session()->forget($this->grantKey($server));

            return $this->unavailable($server, __('This console session has ended. Open the console again from your client area.'));
        }

        if ($server->status !== ServerStatus::Active) {
            return $this->unavailable($server, __('The console is only available while the server is active.'));
        }

        return $this->consolePage($request, $server, $token, null);
    }

    /**
     * @param  array<mixed>|string|null  $message  translated, as __() returns it
     */
    private function unavailable(Server $server, array|string|null $message): Response
    {
        return $this->renderConsole($server, null, $message, null);
    }

    private function grantKey(Server $server): string
    {
        return "console_grants.{$server->uuid}";
    }
}
