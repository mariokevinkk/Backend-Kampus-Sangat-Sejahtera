<?php
namespace App\Middleware;

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class JwtMiddleware {
    public function __invoke(Request $request, Response $response, $next) {
        $authHeader = $request->getHeaderLine('Authorization');
        if (!$authHeader || !preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
            $response->getBody()->write(json_encode(['error' => 'Token tidak ditemukan']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(401);
        }

        $token = $matches[1];

        try {
            $decoded = JWT::decode($token, new Key($_ENV['JWT_SECRET'] ?? 'supersecretkey', 'HS256'));
            $request = $request->withAttribute('user', $decoded);
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode(['error' => 'Token tidak valid']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(401);
        }

        return $next($request, $response);
    }
}
