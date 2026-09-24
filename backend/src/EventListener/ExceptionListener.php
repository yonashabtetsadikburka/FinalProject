<?php
declare(strict_types=1);

namespace App\EventListener;

use App\Exception\AppException;
use App\Service\ApiResponse;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

#[AsEventListener(event: KernelEvents::EXCEPTION)]
class ExceptionListener
{
    public function onKernelException(ExceptionEvent $event): void
    {
        $e = $event->getThrowable();
        if ($e instanceof AppException) {
            $event->setResponse(ApiResponse::errore($e->codice, $e->getMessage(), $e->http));
            return;
        }
        // Log unexpected errors, return generic 500
        if ($event->getRequest()->getPathInfo() !== '/salute') {
            error_log('ERRORE: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        }
        $event->setResponse(ApiResponse::errore('ERRORE_INTERNO', 'Errore interno del server', 500));
    }
}
