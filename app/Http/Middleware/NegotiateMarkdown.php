<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class NegotiateMarkdown
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set('markdown', str_ends_with($request->path(), '.md') || $request->prefers(['text/html', 'text/markdown']) === 'text/markdown');
        $response = $next($request);
        $response->setVary('Accept', false);
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
