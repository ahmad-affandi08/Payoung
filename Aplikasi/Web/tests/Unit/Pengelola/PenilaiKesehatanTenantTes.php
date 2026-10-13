<?php

declare(strict_types=1);

use App\Domain\Pengelola\Tenant\Enum\KategoriKesehatan;
use App\Domain\Pengelola\Tenant\Layanan\PenilaiKesehatanTenant;

/*
 * P-07 PGL-09: skor kesehatan tenant (0–100) dari ukuran pemakaian. Fungsi murni, semua bilangan bulat.
 */

/** @return array{HariAktif: int, TransaksiMingguIni: int, TransaksiMingguLalu: int, FiturDipakai: int, PerangkatLamaOffline: int, TiketTerbukaLama: int, Tertunggak: bool, UmurHari: int} */
function UkuranKesehatan(array $timpa = []): array
{
    return [
        'HariAktif' => 12,
        'TransaksiMingguIni' => 80,
        'TransaksiMingguLalu' => 80,
        'FiturDipakai' => 5,
        'PerangkatLamaOffline' => 0,
        'TiketTerbukaLama' => 0,
        'Tertunggak' => false,
        'UmurHari' => 120,
        ...$timpa,
    ];
}

it('tenant yang aktif tiap hari, memakai banyak fitur, dan tertib tagihan mendapat skor 100 (Sehat)', function (): void {
    $hasil = (new PenilaiKesehatanTenant)->Nilai(UkuranKesehatan());

    expect($hasil['Skor'])->toBe(100)
        ->and($hasil['Kategori'])->toBe(KategoriKesehatan::Sehat)
        ->and(array_sum(array_column($hasil['Faktor'], 'Poin')))->toBe(100)
        ->and(array_sum(array_column($hasil['Faktor'], 'Maks')))->toBe(100);
});

it('tenant tanpa transaksi, tanpa fitur, dan tertunggak hanya mendapat poin perangkat dan tiket (Berisiko)', function (): void {
    $hasil = (new PenilaiKesehatanTenant)->Nilai(UkuranKesehatan(['HariAktif' => 0, 'TransaksiMingguIni' => 0, 'TransaksiMingguLalu' => 0, 'FiturDipakai' => 0, 'Tertunggak' => true]));

    expect($hasil['Skor'])->toBe(15)->and($hasil['Kategori'])->toBe(KategoriKesehatan::Berisiko);
});

it('hari aktif dihitung terhadap target 10 hari dan tidak melebihi poin maksimum', function (): void {
    $nilai = fn (int $hari): int => (new PenilaiKesehatanTenant)->Nilai(UkuranKesehatan(['HariAktif' => $hari]))['Faktor']['HariAktif']['Poin'];

    expect($nilai(0))->toBe(0)
        ->and($nilai(5))->toBe(20)
        ->and($nilai(10))->toBe(40)
        ->and($nilai(14))->toBe(40);
});

it('tenant baru tidak dihukum karena umurnya: target hari aktif mengecil sesuai hari yang sudah lewat', function (): void {
    $nilai = fn (int $umur, int $hari): int => (new PenilaiKesehatanTenant)->Nilai(UkuranKesehatan(['UmurHari' => $umur, 'HariAktif' => $hari]))['Faktor']['HariAktif']['Poin'];

    // Hari pendaftaran: satu hari aktif sudah penuh. Hari ke-8: target 6 hari (8 × 10 / 14 dibulatkan ke atas).
    expect($nilai(0, 1))->toBe(40)
        ->and($nilai(0, 0))->toBe(0)
        ->and($nilai(7, 6))->toBe(40)
        ->and($nilai(7, 3))->toBe(20)
        ->and($nilai(13, 10))->toBe(40);
});

it('tren transaksi: stabil atau naik 15 poin, turun sedikit 8 poin, turun tajam 0; tanpa pembanding 15 bila ada transaksi', function (): void {
    $tren = fn (int $ini, int $lalu): int => (new PenilaiKesehatanTenant)->Nilai(UkuranKesehatan(['TransaksiMingguIni' => $ini, 'TransaksiMingguLalu' => $lalu]))['Faktor']['TrenTransaksi']['Poin'];

    expect($tren(90, 100))->toBe(15)
        ->and($tren(150, 100))->toBe(15)
        ->and($tren(89, 100))->toBe(8)
        ->and($tren(60, 100))->toBe(8)
        ->and($tren(59, 100))->toBe(0)
        ->and($tren(0, 100))->toBe(0)
        ->and($tren(5, 0))->toBe(15)
        ->and($tren(0, 0))->toBe(0);
});

it('fitur utama 4 poin per fitur sampai 5 fitur; perangkat offline, tiket lama, dan tunggakan mengurangi poin', function (): void {
    $nilai = new PenilaiKesehatanTenant;

    expect($nilai->Nilai(UkuranKesehatan(['FiturDipakai' => 0]))['Faktor']['FiturDipakai']['Poin'])->toBe(0)
        ->and($nilai->Nilai(UkuranKesehatan(['FiturDipakai' => 3]))['Faktor']['FiturDipakai']['Poin'])->toBe(12)
        ->and($nilai->Nilai(UkuranKesehatan(['FiturDipakai' => 9]))['Faktor']['FiturDipakai']['Poin'])->toBe(20)
        ->and($nilai->Nilai(UkuranKesehatan(['PerangkatLamaOffline' => 2]))['Skor'])->toBe(94)
        ->and($nilai->Nilai(UkuranKesehatan(['TiketTerbukaLama' => 1]))['Skor'])->toBe(95)
        ->and($nilai->Nilai(UkuranKesehatan(['Tertunggak' => true]))['Skor'])->toBe(90);
});

it('batas kategori: 70 Sehat, 69 Perlu Perhatian, 40 Perlu Perhatian, 39 Berisiko', function (): void {
    $kategori = function (int $hariAktif, int $fitur, bool $tertunggak = false): array {
        $hasil = (new PenilaiKesehatanTenant)->Nilai(UkuranKesehatan(['HariAktif' => $hariAktif, 'FiturDipakai' => $fitur, 'Tertunggak' => $tertunggak, 'TransaksiMingguIni' => 0, 'TransaksiMingguLalu' => 0]));

        return [$hasil['Skor'], $hasil['Kategori']];
    };

    // Tanpa tren (0), perangkat 10 + tiket 5 + tagihan 10 = 25 dasar.
    expect($kategori(10, 1))->toBe([40 + 4 + 25, KategoriKesehatan::PerluPerhatian])
        ->and($kategori(10, 2))->toBe([40 + 8 + 25, KategoriKesehatan::Sehat])
        ->and($kategori(4, 0, true))->toBe([16 + 15, KategoriKesehatan::Berisiko])
        ->and($kategori(5, 0, true))->toBe([20 + 15, KategoriKesehatan::Berisiko])
        ->and($kategori(7, 0, true))->toBe([28 + 15, KategoriKesehatan::PerluPerhatian]);
});

it('tenant yang belum seminggu mendaftar tidak dicap Berisiko walau belum bertransaksi', function (): void {
    $nilai = fn (int $umur): array => (new PenilaiKesehatanTenant)->Nilai(UkuranKesehatan(['UmurHari' => $umur, 'HariAktif' => 0, 'TransaksiMingguIni' => 0, 'TransaksiMingguLalu' => 0, 'FiturDipakai' => 0]));

    expect($nilai(0)['Skor'])->toBe(25)
        ->and($nilai(0)['Kategori'])->toBe(KategoriKesehatan::PerluPerhatian)
        ->and($nilai(6)['Kategori'])->toBe(KategoriKesehatan::PerluPerhatian)
        ->and($nilai(7)['Kategori'])->toBe(KategoriKesehatan::Berisiko);
});
