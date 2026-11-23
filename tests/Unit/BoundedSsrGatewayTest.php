<?php

namespace Tests\Unit;

use App\Support\BoundedSsrGateway;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Inertia\Ssr\Gateway;
use Tests\TestCase;

/**
 * Le gateway SSR du portail est à timeouts bornés : quand le service de
 * rendu serveur est indisponible (hôte introuvable, service tombé), la
 * page retombe sur le shell client sans attendre le timeout DNS/HTTP
 * par défaut (plusieurs secondes par page).
 */
class BoundedSsrGatewayTest extends TestCase
{
    public function test_le_gateway_ssr_est_le_gateway_borne(): void
    {
        $this->assertInstanceOf(BoundedSsrGateway::class, $this->app->make(Gateway::class));
    }

    public function test_ssr_desactive_ne_tente_aucun_appel(): void
    {
        config(['inertia.ssr.enabled' => false]);
        Http::fake();

        $this->assertNull($this->app->make(Gateway::class)->dispatch(['component' => 'X', 'props' => []]));
        Http::assertNothingSent();
    }

    public function test_ssr_indisponible_retombent_sur_null(): void
    {
        config(['inertia.ssr.enabled' => true, 'inertia.ssr.ensure_bundle_exists' => false]);
        // Connexion refusée immédiate (port fermé en local) : repli, pas d'exception.
        Http::fake(fn () => throw new ConnectionException('refused'));

        $this->assertNull($this->app->make(Gateway::class)->dispatch(['component' => 'X', 'props' => []]));
    }
}
