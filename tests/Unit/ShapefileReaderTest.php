<?php

namespace Tests\Unit;

use App\Support\ShapefileReader;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ShapefileReaderTest extends TestCase
{
    private string $fixture = '';

    protected function setUp(): void
    {
        $this->fixture = __DIR__.'/../fixtures/shapefile/ZNIEFF_TEST.shp';

        parent::setUp();
    }

    public function test_lit_les_enregistrements_et_les_attributs(): void
    {
        $reader = new ShapefileReader($this->fixture);

        $this->assertSame(2, $reader->count());
        $this->assertSame(5, $reader->shapeType()); // polygon
        $this->assertEqualsWithDelta(100000.0, $reader->bbox()['minx'], 0.001);

        $enregistrements = iterator_to_array($reader->records(), false);
        $this->assertCount(2, $enregistrements);

        // Accents préservés (UTF-8 déclaré par le .cpg)
        $this->assertSame('230009093', $enregistrements[0]['attrs']['ID_MNHN']);
        $this->assertSame("Côte de l'Île — Bois de Test", $enregistrements[0]['attrs']['NOM']);
        $this->assertSame('9000000.00', $enregistrements[0]['attrs']['SUPERFICIE']);

        $this->assertSame('230030467', $enregistrements[1]['attrs']['ID_MNHN']);
        $this->assertSame('Les Longues Rais de Test', $enregistrements[1]['attrs']['NOM']);
    }

    public function test_wkt_regroupe_exterieurs_et_trous(): void
    {
        $reader = new ShapefileReader($this->fixture);
        $enregistrements = iterator_to_array($reader->records(), false);

        // WKT valide : polygone 1 = extérieur + trou, polygone 2 = îlot
        // (chaque polygone de MULTIPOLYGON est ((anneau[, trous])).
        $attendu1 = 'MULTIPOLYGON(((100000.0000 7000000.0000'
            .',100000.0000 7003000.0000,103000.0000 7003000.0000,103000.0000 7000000.0000,100000.0000 7000000.0000'
            .'),('
            .'100500.0000 7000500.0000,100600.0000 7000500.0000,100600.0000 7000600.0000,100500.0000 7000600.0000,100500.0000 7000500.0000'
            .')),((104000.0000 7001000.0000'
            .',104000.0000 7001200.0000,104200.0000 7001200.0000,104200.0000 7001000.0000,104000.0000 7001000.0000'
            .')))';
        $wkt = (string) $enregistrements[0]['wkt'];

        $this->assertSame($attendu1, $wkt, 'extérieurs + trous regroupés, îlot en second polygone');
        $wkt = (string) $enregistrements[0]['wkt'];

        $this->assertSame($attendu1, $wkt, 'extérieurs + trous regroupés, îlot en second polygone');

        $this->assertSame(
            'MULTIPOLYGON(((200000.0000 7100000.0000,200000.0000 7100100.0000,200100.0000 7100100.0000,200100.0000 7100000.0000,200000.0000 7100000.0000)))',
            (string) $enregistrements[1]['wkt']
        );
    }

    public function test_rejette_un_fichier_inconnu(): void
    {
        $this->expectException(RuntimeException::class);
        new ShapefileReader(__DIR__.'/fixtures/shapefile/inexistant.shp');
    }
}
