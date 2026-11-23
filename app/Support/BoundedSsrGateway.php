<?php

namespace App\Support;

use Exception;
use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Support\Facades\Http;
use Inertia\Ssr\HttpGateway;
use Inertia\Ssr\Response;

/**
 * Gateway SSR avec timeouts bornés : si le service de rendu serveur est
 * tombé (ou l'hôte introuvable), la page retombe IMMÉDIATEMENT sur le
 * shell rendu côté client — jamais d'erreur 500, et jamais 3 s (timeout
 * DNS) voire 30 s (timeout HTTP par défaut) payés à chaque page.
 */
class BoundedSsrGateway extends HttpGateway
{
    private const TIMEOUT_S = 2;

    private const CONNECT_TIMEOUT_S = 1;

    /**
     * @param  array<string, mixed>  $page
     */
    public function dispatch(array $page): ?Response
    {
        if (! $this->shouldDispatch()) {
            return null;
        }

        try {
            $response = Http::connectTimeout(self::CONNECT_TIMEOUT_S)
                ->timeout(self::TIMEOUT_S)
                ->post($this->getUrl('/render'), $page)
                ->throw()
                ->json();
        } catch (Exception $e) {
            if ($e instanceof StrayRequestException) {
                throw $e;
            }

            return null;
        }

        if (is_null($response)) {
            return null;
        }

        return new Response(
            implode("\n", $response['head']),
            $response['body']
        );
    }

    /**
     * Health check borné lui aussi (watchdog / supervision).
     */
    public function isHealthy(): bool
    {
        try {
            return Http::connectTimeout(self::CONNECT_TIMEOUT_S)
                ->timeout(self::TIMEOUT_S)
                ->get($this->getUrl('/health'))
                ->successful();
        } catch (Exception $e) {
            if ($e instanceof StrayRequestException) {
                throw $e;
            }

            return false;
        }
    }
}
