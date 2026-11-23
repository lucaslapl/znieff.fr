<?php

namespace Tests\Unit;

use App\Services\AbcClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Le badge « ABC » des fiches communes repose sur UNE requête vers le
 * GeoJSON « une feature par commune » de l'Observatoire des ABC. Le code
 * INSEE y est stocké sous « code_commune » (et non « insee ») — toute la
 * détection repose donc sur cette clé, avec repli indisponible = null
 * (has_abc inchangé, jamais d'exception vers la commande d'import).
 */
class AbcClientTest extends TestCase
{
    public function test_api_desactivee_aucune_requete(): void
    {
        config(['portail.abc_api.enabled' => false]);
        Http::fake();

        $this->assertNull($this->app->make(AbcClient::class)->communesAvecAbc());
        Http::assertNothingSent();
    }

    public function test_codes_insee_extraits_du_geojson_observatoire(): void
    {
        config(['portail.abc_api.enabled' => true]);
        // Structure réelle de l'Observatoire : une feature par commune
        // (ou par couple ABC–commune) d'où des codes en doublon.
        Http::fake([
            'observatoire-abc.fr/api/abc.geojson' => Http::response([
                'features' => [
                    ['properties' => ['code_commune' => '27036', 'commune' => 'Acquigny']],
                    ['properties' => ['code_commune' => '27336', 'commune' => 'Louviers']],
                    ['properties' => ['code_commune' => '27036', 'commune' => 'Acquigny']],
                    ['properties' => ['code_commune' => 'pas-un-code']],
                    ['properties' => []],                ],
            ]),
        ]);

        $codes = $this->app->make(AbcClient::class)->communesAvecAbc();

        $this->assertSame(['27036', '27336'], $codes);
    }

    public function test_api_indisponible_repli_sur_null(): void
    {
        config(['portail.abc_api.enabled' => true]);
        Http::fake(['observatoire-abc.fr/*' => Http::response('', 503)]);

        $this->assertNull($this->app->make(AbcClient::class)->communesAvecAbc());
    }
}
