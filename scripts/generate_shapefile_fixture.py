#!/usr/bin/env python3
"""Génère la fixture shapefile des tests (tests/fixtures/shapefile).

Conforme à la spec ESRI : anneaux extérieurs HORAIRE (aire signée du
lacet négative), trous TRIGONOMÉTRIQUE (positive). Deux enregistrements
polygonales + un DBF UTF-8 (.cpg) avec accents — cas couvert par
tests/Unit/ShapefileReaderTest.php.

Usage : python scripts/generate_shapefile_fixture.py
"""
import struct
import time
from pathlib import Path

OUT = Path(__file__).resolve().parent.parent / 'tests' / 'fixtures' / 'shapefile'
OUT.mkdir(parents=True, exist_ok=True)

SQUARE = [(100000.0, 7000000.0), (100000.0, 7003000.0), (103000.0, 7003000.0), (103000.0, 7000000.0), (100000.0, 7000000.0)]
HOLE = [(100500.0, 7000500.0), (100600.0, 7000500.0), (100600.0, 7000600.0), (100500.0, 7000600.0), (100500.0, 7000500.0)]
ISLAND = [(104000.0, 7001000.0), (104000.0, 7001200.0), (104200.0, 7001200.0), (104200.0, 7001000.0), (104000.0, 7001000.0)]
SMALL = [(200000.0, 7100000.0), (200000.0, 7100100.0), (200100.0, 7100100.0), (200100.0, 7100000.0), (200000.0, 7100000.0)]


def shp_header(length_words: int, shape_type: int, bbox) -> bytes:
    now = time.localtime()
    head = struct.pack('>i', 9994) + b'\x00' * 20
    head += struct.pack('>i', length_words)
    head += struct.pack('<i', 1000) + struct.pack('<i', shape_type)
    head += struct.pack('<4d', *bbox)
    head += struct.pack('<4d', 0, 0, 0, 0)
    return head


def poly_record(num: int, rings) -> bytes:
    parts, all_pts = [], []
    for ring in rings:
        parts.append(len(all_pts))
        all_pts.extend(ring)
    content = struct.pack('<i', 5)  # type polygon
    xs = [p[0] for p in all_pts]
    ys = [p[1] for p in all_pts]
    content += struct.pack('<4d', min(xs), min(ys), max(xs), max(ys))
    content += struct.pack('<ii', len(parts), len(all_pts))
    for p in parts:
        content += struct.pack('<i', p)
    for x, y in all_pts:
        content += struct.pack('<2d', x, y)
    assert len(content) % 2 == 0
    return struct.pack('>ii', num, len(content) // 2) + content


def dbf_header(nrec: int, fields, rlen: int) -> bytes:
    now = time.localtime()
    head = bytes([3]) + bytes([now.tm_year - 1900, now.tm_mon, now.tm_mday])
    head += struct.pack('<I', nrec)
    head += struct.pack('<H', 32 + 32 * len(fields) + 1)
    head += struct.pack('<H', rlen)
    head += b'\x00' * 20
    desc = b''
    for name, ftype, flen in fields:
        nb = name.encode('ascii')
        assert len(nb) <= 10
        desc += nb + b'\x00' * (11 - len(nb))
        desc += bytes([ord(ftype)]) + b'\x00' * 4 + bytes([flen, 0]) + b'\x00' * 14
    return head + desc + b'\r'


def dbf_record(fields, values) -> bytes:
    record = b' '
    for (name, ftype, flen), value in zip(fields, values):
        raw = value.encode('utf-8') if ftype == 'C' else value.encode('ascii')
        assert len(raw) <= flen, (name, value)
        record += raw + b' ' * (flen - len(raw))
    return record


records = poly_record(1, [SQUARE, HOLE, ISLAND]) + poly_record(2, [SMALL])
total = 100 + len(records)
(OUT / 'ZNIEFF_TEST.shp').write_bytes(shp_header(total // 2, 5, [100000.0, 7000000.0, 104200.0, 7100100.0]) + records)

fields = [('ID_MNHN', 'C', 12), ('NOM', 'C', 40), ('SUPERFICIE', 'N', 18)]
rows = [
    ('230009093', "Côte de l'Île — Bois de Test", '9000000.00'),  # 900 ha
    ('230030467', 'Les Longues Rais de Test', '10000.00'),  # 1 ha
]
rlen = 1 + sum(f[2] for f in fields)
data = dbf_header(len(rows), fields, rlen)
for values in rows:
    data += dbf_record(fields, values)
data += b'\x1a'
(OUT / 'ZNIEFF_TEST.dbf').write_bytes(data)
(OUT / 'ZNIEFF_TEST.cpg').write_bytes(b'UTF-8')

print(f'Fixture écrite dans {OUT} ({total} octets SHP)')
