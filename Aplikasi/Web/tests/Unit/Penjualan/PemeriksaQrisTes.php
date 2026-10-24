<?php

declare(strict_types=1);

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Penjualan\Layanan\PemeriksaQris;

/*
 * F-08 (D-189): pemeriksaan QRIS statis milik toko. Vektor yang sama dipakai Dart (`Paket/Inti`) dan TypeScript
 * (`Pustaka/Qris.ts`): `Spesifikasi/VektorUjiQris/QrisDinamis.json`.
 */

/** @return array{Kasus: list<array<string, string>>, TidakSah: list<array<string, string>>} */
function VektorQris(): array
{
    $berkas = dirname(__DIR__, 5).'/Spesifikasi/VektorUjiQris/QrisDinamis.json';

    return json_decode((string) file_get_contents($berkas), true, flags: JSON_THROW_ON_ERROR);
}

function KodeGalatQris(string $teks): ?string
{
    try {
        (new PemeriksaQris)->Periksa($teks);
    } catch (PelanggaranAturanBisnis $galat) {
        return $galat->kode;
    }

    return null;
}

it('CRC16/CCITT-FALSE: nilai acuan standar "123456789" = 29B1', function (): void {
    expect((new PemeriksaQris)->HitungCrc16('123456789'))->toBe('29B1');
});

it('QRIS statis dari vektor lolos dan memberi nama merchant; hasil QRIS bernominal punya CRC sah tetapi ditolak sebagai sumber', function (): void {
    $pemeriksa = new PemeriksaQris;

    foreach (VektorQris()['Kasus'] as $kasus) {
        expect($pemeriksa->Periksa($kasus['Statis'])['NamaMerchant'])->not->toBe('', $kasus['Nama'])
            ->and($pemeriksa->HitungCrc16(substr($kasus['Dinamis'], 0, -4)))->toBe(substr($kasus['Dinamis'], -4), $kasus['Nama'])
            ->and(KodeGalatQris($kasus['Dinamis']))->toBe('BukanStatis', $kasus['Nama']);
    }

    $info = $pemeriksa->Periksa(VektorQris()['Kasus'][0]['Statis']);

    expect($info)->toBe(['NamaMerchant' => 'TOKO SEMBAKO MAJU JAYA', 'Kota' => 'SURAKARTA']);
});

it('QRIS tidak sah ditolak dengan kode masalah yang sama dengan Dart dan TypeScript', function (): void {
    foreach (VektorQris()['TidakSah'] as $kasus) {
        expect(KodeGalatQris($kasus['Teks']))->toBe($kasus['Galat'], $kasus['Nama']);
    }
});

it('spasi di awal atau akhir hasil baca diabaikan', function (): void {
    expect(KodeGalatQris("  \n".VektorQris()['Kasus'][0]['Statis']."\n"))->toBeNull();
});
