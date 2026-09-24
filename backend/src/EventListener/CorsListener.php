<?php
declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpFoundation\RequestStack;

class CorsListener
{
    public function __construct(private RequestStack $requestStack) {}

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 9999)]
    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) return;
        $request = $event->getRequest();
        if ($request->getMethod() === 'OPTIONS') {
            $response = new \Symfony\Component\HttpFoundation\Response('', 204);
            $this->addCorsHeaders($response, $request);
            $event->setResponse($response);
        }
    }

    #[AsEventListener(event: KernelEvents::RESPONSE, priority: -9999)]
    public function onKernelResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        $this->addCorsHeaders($event->getResponse(), $request);
    }

    private function addCorsHeaders(\Symfony\Component\HttpFoundation\Response $response, ?\Symfony\Component\HttpFoundation\Request $request = null): void
    {
        if (!$request) $request = $this->requestStack->getCurrentRequest();
        $origin = $request?->headers->get('Origin');
        $allowed = $_ENV['CORS_ALLOW_ORIGIN'] ?? getenv('CORS_ALLOW_ORIGIN') ?: '*';
        // If allowed is *, reflect request origin when credentials needed
        if ($allowed === '*') $allowed = $origin ?: '*';
        // Allow multiple origins comma-separated
        if (str_contains($allowed, ',')) {
            $list = array_map('trim', explode(',', $allowed));
            if ($origin && in_array($origin, $list, true)) $allowed = $origin;
            else $allowed = $list[0];
        }
        $response->headers->set('Access-Control-Allow-Origin', $allowed);
        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS, PATCH');
        $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With');
        $response->headers->set('Access-Control-Allow-Credentials', 'true');
        $response->headers->set('Access-Control-Max-Age', '3600');
        $response->headers->set('Vary', 'Origin');
    }
}
