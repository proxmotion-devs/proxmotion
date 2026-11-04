<?php

declare(strict_types=1);

namespace App\Http;

use App\Domain\Settings\Branding;
use App\Http\Middleware\HandleInertiaRequests;
use App\Support\Locales;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * The panel's own page for errors of browser requests, instead of Laravel's
 * plain HTML (which Inertia shows in an overlay): forbidden, not found,
 * server error and maintenance. A server error keeps Laravel's page with its
 * stack trace while debugging; API requests and files keep their responses.
 * An expired page (CSRF token, 419) goes back with a toast instead.
 */
final class ErrorPages
{
    public const array STATUSES = [403, 404, 500, 503];

    /** Paths that never answer with a page: the API, images, the console proxy. */
    private const array SKIPPED_PATHS = ['api/*', 'branding/*', 'internal/*', 'build/*'];

    public static function respond(Response $response, Throwable $exception, Request $request): Response
    {
        $status = $response->getStatusCode();

        if ($request->expectsJson() || $request->is(...self::SKIPPED_PATHS)) {
            return $response;
        }

        if ($status === 419) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('The page expired. Please try again.')]);

            return back();
        }

        if (! in_array($status, self::STATUSES, true) || ($status === 500 && config('app.debug') === true)) {
            return $response;
        }

        return Inertia::render('errors/Show', [...self::shared($request), 'status' => $status])
            ->toResponse($request)
            ->setStatusCode($status);
    }

    /**
     * The props every page gets. A request that matched no route never ran
     * the middleware and has no session, so it gets only what needs none.
     *
     * @return array<string, mixed>
     */
    private static function shared(Request $request): array
    {
        try {
            return app(HandleInertiaRequests::class)->share($request);
        } catch (Throwable) {
            return [
                'name' => Branding::name(),
                'branding' => Branding::forPages(),
                'locale' => App::getLocale(),
                'availableLocales' => Locales::SUPPORTED,
                'auth' => ['user' => null],
                'errors' => (object) [],
            ];
        }
    }
}
