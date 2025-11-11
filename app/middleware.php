<?php
namespace App\Middleware;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

class JwtMiddleware
{
    public function __invoke(Request $request, RequestHandler $handler): Response
    {
        $authHeader = $request->getHeaderLine('Authorization');

        if (!$authHeader) {
            $response = new \Slim\Psr7\Response();
            $response->getBody()->write(json_encode(['error' => 'Token tidak ditemukan']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(401);
        }

        $token = str_replace('Bearer ', '', $authHeader);
        $secret = $_ENV['JWT_SECRET'] ?? 'supersecretkey';

        try {
            $decoded = JWT::decode($token, new Key($secret, 'HS256'));
            $request = $request->withAttribute('user', (array)$decoded);
        } catch (\Exception $e) {
            $response = new \Slim\Psr7\Response();
            $response->getBody()->write(json_encode(['error' => 'Token tidak valid']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(401);
        }

        return $handler->handle($request);
    }
}
