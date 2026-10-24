<?php

declare(strict_types=1);

use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Bersama\Dokumen\Model\RiwayatStatusDokumen;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Katalog\Model\Produk;
use App\Domain\Organisasi\Enum\StatusOrganisasi;
use App\Domain\Persediaan\Aksi\BuangStokAwal;
use App\Domain\Persediaan\Aksi\SimpanStokAwal;
use App\Domain\Persediaan\Data\DataBarisStokAwal;
use App\Domain\Persediaan\Enum\StatusStokAwal;
use App\Domain\Persediaan\Model\MutasiStok;
use App\Domain\Persediaan\Model\StokAwal;
use App\Domain\Persediaan\Model\StokAwalDetail;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Persediaan\BantuanStokAwal;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * F-05a Tim C: draf stok awal (DesainF05a C.6.1–C.6.2). Draf dibuat idempoten per Uuid klien, diubah dengan versi
 * optimistis, isinya diperiksa (H-1 Konsinyasi ditolak, H-2 satuan dasar, H-3 batch wajib kedaluwarsa), dan draf
 * yang tidak dipakai dibuang (bukan dihapus, H-14). Draf tidak pernah menyentuh stok.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

/**
 * @return array{0: array<string, mixed>, 1: array<string, Produk>}
 */
function SiapkanDrafStokAwalUji(): array
{
    $t = BantuanPersediaan::SiapkanTenant();

    return [$t, BantuanPersediaan::BuatProdukSemuaJenis($t['Pcs'], $t['Kg'])];
}

/** Kode galat dari Aksi, atau null bila tidak ada galat. */
function AmbilKodeGalatDraf(Closure $kerja): ?string
{
    try {
        $kerja();
    } catch (PelanggaranAturanBisnis $galat) {
        return $galat->kode;
    }

    return null;
}

describe('F-05a stok awal draf: buat & ubah', function (): void {
    it('membuat draf dengan snapshot produk, Nilai = Jumlah × HPP (2 desimal, HalfUp), total, riwayat, dan audit; tanpa mutasi stok', function (): void {
        [$t, $p] = SiapkanDrafStokAwalUji();

        $draf = BantuanStokAwal::BuatDraf($t['Gudang'], [
            BantuanStokAwal::Baris($p['Stok'], '10', '1234.5678'),
            BantuanStokAwal::Baris($p['BahanBaku'], '2.5', '15500.123456'),
        ]);

        $baris = StokAwalDetail::query()->where('IdStokAwal', $draf->Id)->orderBy('Urutan')->get();
        expect($draf->Status)->toBe(StatusStokAwal::Draf)
            ->and($draf->Nomor)->toBeNull()
            ->and($draf->IdOutlet)->toBe($t['Outlet']->Id)
            ->and($draf->JumlahBaris)->toBe(2)
            ->and($draf->fresh()?->TotalNilai)->toBe('51095.99')
            ->and($baris[0]->NamaProduk)->toBe($p['Stok']->Nama)
            ->and($baris[0]->Sku)->toBe($p['Stok']->Sku)
            ->and($baris[0]->Jumlah)->toBe('10.0000')
            ->and($baris[0]->HppSatuan)->toBe('1234.567800')
            ->and($baris[0]->Nilai)->toBe('12345.68')
            ->and($baris[1]->Nilai)->toBe('38750.31')
            ->and(MutasiStok::query()->count())->toBe(0)
            ->and(RiwayatStatusDokumen::query()->where('IdDokumen', $draf->Id)->pluck('StatusKe')->all())->toBe(['Draf'])
            ->and(LogAudit::query()->where('Peristiwa', 'stok-awal.buat')->where('IdObjek', $draf->Id)->exists())->toBeTrue();
    });

    it('idempoten per Uuid klien: Uuid yang sama mengembalikan draf yang sama tanpa baris ganda', function (): void {
        [$t, $p] = SiapkanDrafStokAwalUji();
        $uuid = (string) Str::ulid();

        $pertama = BantuanStokAwal::BuatDraf($t['Gudang'], [BantuanStokAwal::Baris($p['Stok'], '5', '38000')], uuid: $uuid);
        $kedua = BantuanStokAwal::BuatDraf($t['Gudang'], [BantuanStokAwal::Baris($p['Stok'], '7', '39000')], uuid: $uuid);

        expect($kedua->Id)->toBe($pertama->Id)
            ->and(StokAwal::query()->count())->toBe(1)
            ->and(StokAwalDetail::query()->count())->toBe(1)
            ->and(StokAwalDetail::query()->value('Jumlah'))->toBe('5.0000');
    });

    it('ubah draf mengganti semua baris dan menghitung ulang total; versi usang ditolak DokumenBerubah', function (): void {
        [$t, $p] = SiapkanDrafStokAwalUji();
        $this->travelTo(CarbonImmutable::now()->subMinutes(5));
        $draf = BantuanStokAwal::BuatDraf($t['Gudang'], [BantuanStokAwal::Baris($p['Stok'], '5', '38000')]);
        $versiLama = $draf->DiubahPada?->toIso8601String();
        $this->travelBack();

        $diubah = app(SimpanStokAwal::class)->Jalankan(BantuanStokAwal::Data($t['Gudang'], [
            BantuanStokAwal::Baris($p['Produksi'], '24', '9500'),
            BantuanStokAwal::Baris($p['Stok'], '6', '38250.5'),
        ], versi: $versiLama), $draf);

        expect($diubah->JumlahBaris)->toBe(2)
            ->and($diubah->fresh()?->TotalNilai)->toBe('457503.00')
            ->and(StokAwalDetail::query()->where('IdStokAwal', $draf->Id)->orderBy('Urutan')->pluck('IdProduk')->all())->toBe([$p['Produksi']->Id, $p['Stok']->Id])
            ->and(LogAudit::query()->where('Peristiwa', 'stok-awal.ubah')->exists())->toBeTrue();

        expect(AmbilKodeGalatDraf(fn () => app(SimpanStokAwal::class)->Jalankan(BantuanStokAwal::Data($t['Gudang'], [
            BantuanStokAwal::Baris($p['Stok'], '1', '1'),
        ], versi: $versiLama), $draf)))->toBe('DokumenBerubah');
        expect(StokAwalDetail::query()->where('IdStokAwal', $draf->Id)->count())->toBe(2);
    });
});

describe('F-05a stok awal draf: pemeriksaan isi (C.6.1)', function (): void {
    it('menolak produk Konsinyasi (H-1), jenis tanpa stok, diarsipkan, dan tidak dikenal', function (): void {
        [$t, $p] = SiapkanDrafStokAwalUji();
        $p['Produksi']->update(['DiarsipkanPada' => now(), 'Aktif' => false]);
        $buat = fn (int $idProduk) => fn () => app(SimpanStokAwal::class)->Jalankan(BantuanStokAwal::Data($t['Gudang'], [
            new DataBarisStokAwal($idProduk, Kuantitas::Dari('1'), BigDecimal::of('1000'), null, null, []),
        ]), null);

        expect(AmbilKodeGalatDraf($buat($p['Konsinyasi']->Id)))->toBe('ProdukKonsinyasi')
            ->and(AmbilKodeGalatDraf($buat($p['Jasa']->Id)))->toBe('ProdukTanpaStok')
            ->and(AmbilKodeGalatDraf($buat($p['Produksi']->Id)))->toBe('ProdukDiarsipkan')
            ->and(AmbilKodeGalatDraf($buat(0)))->toBe('ProdukTidakDikenal')
            ->and(StokAwal::query()->count())->toBe(0);
    });

    it('jumlah dalam satuan dasar: pcs harus bulat, kg boleh desimal; jumlah 0 dan HPP > 6 desimal ditolak (H-2)', function (): void {
        [$t, $p] = SiapkanDrafStokAwalUji();
        $buat = fn (array $baris) => fn () => BantuanStokAwal::BuatDraf($t['Gudang'], $baris);

        expect(AmbilKodeGalatDraf($buat([BantuanStokAwal::Baris($p['Stok'], '1.5', '38000')])))->toBe('JumlahTidakValid')
            ->and(AmbilKodeGalatDraf($buat([BantuanStokAwal::Baris($p['Stok'], '0', '38000')])))->toBe('JumlahTidakValid')
            ->and(AmbilKodeGalatDraf($buat([BantuanStokAwal::Baris($p['Stok'], '1', '1.1234567')])))->toBe('HppTidakValid')
            ->and(AmbilKodeGalatDraf($buat([BantuanStokAwal::Baris($p['Stok'], '1', '-5')])))->toBe('HppTidakValid')
            ->and(AmbilKodeGalatDraf($buat([BantuanStokAwal::Baris($p['BahanBaku'], '12.3456', '14750')])))->toBeNull();
    });

    it('baris ganda (produk + batch sama) ditolak BarisGanda dengan semua masalah baris di detail', function (): void {
        [$t, $p] = SiapkanDrafStokAwalUji();

        try {
            BantuanStokAwal::BuatDraf($t['Gudang'], [
                BantuanStokAwal::Baris($p['Stok'], '1', '38000'),
                BantuanStokAwal::Baris($p['Batch'], '10', '18000', 'B-2609A', '2027-03-31'),
                BantuanStokAwal::Baris($p['Stok'], '2', '38000'),
                BantuanStokAwal::Baris($p['Batch'], '5', '18000', 'B-2609B', '2027-04-30'),
            ]);
            $galat = null;
        } catch (PelanggaranAturanBisnis $e) {
            $galat = $e;
        }

        expect($galat?->kode)->toBe('BarisGanda')
            ->and($galat?->bidang)->toBe('Baris.2.UuidProduk')
            ->and($galat?->detail['Baris'] ?? [])->toHaveCount(1)
            ->and($galat?->detail['Baris'][0]['Urutan'] ?? null)->toBe(3);
    });

    it('batch wajib nomor & kedaluwarsa (H-3), seri wajib sebanyak jumlah, produk biasa tanpa batch/seri', function (): void {
        [$t, $p] = SiapkanDrafStokAwalUji();
        $buat = fn (array $baris) => fn () => BantuanStokAwal::BuatDraf($t['Gudang'], $baris);

        expect(AmbilKodeGalatDraf($buat([BantuanStokAwal::Baris($p['Batch'], '10', '18000', 'B-01')])))->toBe('PelacakanTidakValid')
            ->and(AmbilKodeGalatDraf($buat([BantuanStokAwal::Baris($p['Batch'], '10', '18000')])))->toBe('PelacakanTidakValid')
            ->and(AmbilKodeGalatDraf($buat([BantuanStokAwal::Baris($p['Seri'], '2', '650000', nomorSeri: ['RC-0001'])])))->toBe('PelacakanTidakValid')
            ->and(AmbilKodeGalatDraf($buat([BantuanStokAwal::Baris($p['Stok'], '1', '38000', 'B-01', '2027-01-01')])))->toBe('PelacakanTidakValid')
            ->and(AmbilKodeGalatDraf($buat([
                BantuanStokAwal::Baris($p['Batch'], '10', '18000', ' B-01 ', '2027-01-01'),
                BantuanStokAwal::Baris($p['Seri'], '2', '650000', nomorSeri: ['RC-0001', 'RC-0002']),
            ])))->toBeNull()
            ->and(StokAwalDetail::query()->where('IdProduk', $p['Batch']->Id)->value('NomorBatch'))->toBe('B-01');
    });

    it('tanggal di masa depan, lokasi stok diarsipkan, dan dokumen tanpa baris ditolak', function (): void {
        [$t, $p] = SiapkanDrafStokAwalUji();
        $baris = [BantuanStokAwal::Baris($p['Stok'], '1', '38000')];
        $besok = CarbonImmutable::now('Asia/Jakarta')->addDays(2)->format('Y-m-d');

        expect(AmbilKodeGalatDraf(fn () => BantuanStokAwal::BuatDraf($t['Gudang'], $baris, $besok)))->toBe('TanggalDiMasaDepan')
            ->and(AmbilKodeGalatDraf(fn () => BantuanStokAwal::BuatDraf($t['Gudang'], [])))->toBe('BarisKosong');

        $gudangArsip = BantuanPersediaan::BuatGudang($t['Outlet'], 'Gudang Lama Pasar Legi');
        $gudangArsip->update(['Status' => StatusOrganisasi::Diarsipkan]);
        expect(AmbilKodeGalatDraf(fn () => BantuanStokAwal::BuatDraf($gudangArsip, $baris)))->toBe('GudangDiarsipkan');
    });

    it('baris melebihi MaksimalBaris ditolak BarisTerlaluBanyak', function (): void {
        [$t, $p] = SiapkanDrafStokAwalUji();
        config(['persediaan.StokAwal.MaksimalBaris' => 1]);

        expect(AmbilKodeGalatDraf(fn () => BantuanStokAwal::BuatDraf($t['Gudang'], [
            BantuanStokAwal::Baris($p['Stok'], '1', '38000'),
            BantuanStokAwal::Baris($p['Produksi'], '1', '9000'),
        ])))->toBe('BarisTerlaluBanyak');
    });
});

describe('F-05a stok awal draf: buang (H-14)', function (): void {
    it('Draf → Dibuang tanpa menghapus dokumen maupun barisnya; idempoten; draf yang dibuang tidak bisa diubah', function (): void {
        [$t, $p] = SiapkanDrafStokAwalUji();
        $draf = BantuanStokAwal::BuatDraf($t['Gudang'], [BantuanStokAwal::Baris($p['Stok'], '3', '38000')]);

        $dibuang = app(BuangStokAwal::class)->Jalankan($draf);
        app(BuangStokAwal::class)->Jalankan($draf);

        expect($dibuang->Status)->toBe(StatusStokAwal::Dibuang)
            ->and(StokAwal::query()->whereKey($draf->Id)->exists())->toBeTrue()
            ->and(StokAwalDetail::query()->where('IdStokAwal', $draf->Id)->count())->toBe(1)
            ->and(RiwayatStatusDokumen::query()->where('IdDokumen', $draf->Id)->pluck('StatusKe')->all())->toBe(['Draf', 'Dibuang'])
            ->and(LogAudit::query()->where('Peristiwa', 'stok-awal.buang')->count())->toBe(1)
            ->and(AmbilKodeGalatDraf(fn () => app(SimpanStokAwal::class)->Jalankan(
                BantuanStokAwal::Data($t['Gudang'], [BantuanStokAwal::Baris($p['Stok'], '1', '1')], versi: $dibuang->DiubahPada?->toIso8601String()),
                $dibuang,
            )))->toBe('StatusTidakSesuai');
    });
});
