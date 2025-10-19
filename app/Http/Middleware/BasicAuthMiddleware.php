<?php

namespace App\Http\Middleware;

use Closure;

class BasicAuthMiddleware
{
    public function handle($request, Closure $next)
    {
        $username = env('BASIC_AUTH_USER', 'admin');
        $password = env('BASIC_AUTH_PASS', 'secret');

        $authHeader = $request->header('Authorization');

        if (!$authHeader || strpos($authHeader, 'Basic ') !== 0) {
            return response()->json(['message' => 'Unauthorized'], 401, [
                'WWW-Authenticate' => 'Basic realm="Access denied"',
            ]);
        }

        $encodedCredentials = substr($authHeader, 6);
        $decoded = base64_decode($encodedCredentials);
        [$inputUser, $inputPass] = explode(':', $decoded, 2);

        if ($inputUser !== $username || $inputPass !== $password) {
            return response()->json(['message' => 'Invalid credentials'], 401, [
                'WWW-Authenticate' => 'Basic realm="Access denied"',
            ]);
        }

        return $next($request);
    }
}
