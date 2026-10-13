<?php

declare(strict_types=1);

use App\Domain\Katalog\Enum\PelacakanProduk;
use App\Domain\Organisasi\Aksi\BuatNotifikasiKedaluwarsa;
use App\Domain\Organisasi\Enum\JenisNotifikasiPengguna;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Organisasi\Model\NotifikasiPengguna;
use App\Domain\Organisasi\Model\PengingatKedaluwarsaBatch;
use App\Domain\Organisasi\Model\PerangkatPengguna;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Penjualan\BantuanPenjualan;
use Tests\Pendukung\Persediaan\BantuanStokAwal;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/* F23 (INV-09, OWN-03): push peringatan kedaluwarsa batch H-30 / H-7 / lewat ke Aplikasi Owner. */

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-01 05:00:00', 'UTC'));
    BantuanPendaftaran::SiapkanPrasyarat();
    $this->travelTo(CarbonImmutable::parse('2026-10-07 05:00:00', 'UTC'));
    Queue::fake();
});

function PasangPerangkatOwner(int $idPengguna, string $nama = 'Pixel Owner'): void
{
    $token = str_repeat('fcm-', 20).$idPengguna;
    PerangkatPengguna::query()->create([
        'IdPengguna' => $idPengguna,
        'Nama' => $nama,
        'Platform' => 'Android',
        'Token' => $token,
        'HashToken' => PerangkatPengguna::BuatHashToken($token),
        'TerakhirTerdaftarPada' => now(),
    ]);
}

/** @return array<string, mixed> tenant dengan susu UHT: lewat (4/10), tinggal 5 hari (12/10), dalam 30 hari (30/10), jauh (31/12). */
function SiapkanBatchKedaluwarsa(object $tes): array
{
    $k = BantuanPenjualan::Siapkan($tes);
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    config(['persediaan.StokAwal.WajibKedaluwarsaBatch' => false]);
    $susu = BantuanKatalog::BuatProduk(['Nama' => 'Susu UHT Full Cream 1 Liter', 'Pelacakan' => PelacakanProduk::Batch], '19500.00');
    BantuanStokAwal::BuatDanPosting($k['Gudang'], [
        BantuanStokAwal::Baris($susu, '4', '10000', 'UHT-LEWAT', '2026-10-04'),
        BantuanStokAwal::Baris($susu, '10', '10000', 'UHT-SEGERA', '2026-10-12'),
        BantuanStokAwal::Baris($susu, '6', '10000', 'UHT-DEKAT', '2026-10-30'),
        BantuanStokAwal::Baris($susu, '6', '10000', 'UHT-JAUH', '2026-12-31'),
    ], $k['Pemilik']->Id);

    return $k;
}

describe('F23 peringatan kedaluwarsa batch lewat push', function (): void {
    it('satu ringkasan untuk Owner: lewat, ≤ 7 hari, dan ≤ 30 hari; batch yang masih jauh tidak ikut', function (): void {
        $k = SiapkanBatchKedaluwarsa($this);
        PasangPerangkatOwner($k['Pemilik']->Id);

        $dibuat = app(BuatNotifikasiKedaluwarsa::class)->Jalankan($k['Tenant']->Id, CarbonImmutable::parse('2026-10-07', 'Asia/Jakarta'));

        expect($dibuat)->toBe(1);
        $notifikasi = NotifikasiPengguna::query()->sole();
        expect($notifikasi->IdPengguna)->toBe($k['Pemilik']->Id)
            ->and($notifikasi->Jenis)->toBe(JenisNotifikasiPengguna::StokKedaluwarsa)
            ->and($notifikasi->Judul)->toBe('Barang sudah lewat kedaluwarsa')
            ->and($notifikasi->Isi)->toContain('1 batch sudah lewat kedaluwarsa')
            ->and($notifikasi->Isi)->toContain('1 batch tinggal 7 hari atau kurang')
            ->and($notifikasi->Isi)->toContain('1 batch dalam 30 hari')
            ->and($notifikasi->Isi)->toContain('Susu UHT Full Cream 1 Liter (batch UHT-LEWAT)')
            ->and($notifikasi->Isi)->not->toContain('UHT-JAUH')
            ->and(PengingatKedaluwarsaBatch::query()->count())->toBe(3);
    });

    it('idempoten: batch yang sudah diumumkan pada tingkat yang sama tidak diumumkan lagi, hari ini maupun besok', function (): void {
        $k = SiapkanBatchKedaluwarsa($this);
        PasangPerangkatOwner($k['Pemilik']->Id);
        $aksi = app(BuatNotifikasiKedaluwarsa::class);

        expect($aksi->Jalankan($k['Tenant']->Id, CarbonImmutable::parse('2026-10-07', 'Asia/Jakarta')))->toBe(1)
            ->and($aksi->Jalankan($k['Tenant']->Id, CarbonImmutable::parse('2026-10-07', 'Asia/Jakarta')))->toBe(0)
            ->and($aksi->Jalankan($k['Tenant']->Id, CarbonImmutable::parse('2026-10-08', 'Asia/Jakarta')))->toBe(0);

        expect(NotifikasiPengguna::query()->count())->toBe(1)->and(PengingatKedaluwarsaBatch::query()->count())->toBe(3);
    });

    it('batch yang naik tingkat (H-30 → H-7 → lewat) diumumkan lagi sekali per tingkat', function (): void {
        $k = SiapkanBatchKedaluwarsa($this);
        PasangPerangkatOwner($k['Pemilik']->Id);
        $aksi = app(BuatNotifikasiKedaluwarsa::class);
        $aksi->Jalankan($k['Tenant']->Id, CarbonImmutable::parse('2026-10-07', 'Asia/Jakarta'));

        // 23 Okt: UHT-DEKAT (30/10) tinggal 7 hari (H7); UHT-SEGERA (12/10) sudah lewat (Lewat).
        $this->travelTo(CarbonImmutable::parse('2026-10-23 05:00:00', 'UTC'));
        expect($aksi->Jalankan($k['Tenant']->Id, CarbonImmutable::parse('2026-10-23', 'Asia/Jakarta')))->toBe(1);

        $terakhir = NotifikasiPengguna::query()->orderByDesc('Id')->firstOrFail();
        expect($terakhir->Isi)->toContain('1 batch sudah lewat kedaluwarsa')
            ->and($terakhir->Isi)->toContain('1 batch tinggal 7 hari atau kurang')
            ->and($terakhir->Isi)->not->toContain('dalam 30 hari')
            ->and(PengingatKedaluwarsaBatch::query()->where('KunciBatch', 'like', '%UHT-DEKAT')->orderBy('Id')->pluck('Tingkat')->all())->toBe(['H30', 'H7']);

        expect($aksi->Jalankan($k['Tenant']->Id, CarbonImmutable::parse('2026-10-24', 'Asia/Jakarta')))->toBe(0);
    });

    it('semua anggota aktif berizin stok menerima (dengan atau tanpa perangkat Owner, F31); Kasir yang tanpa izin tidak', function (): void {
        $k = SiapkanBatchKedaluwarsa($this);
        $kasir = BantuanOrganisasi::TambahAnggota($k['Tenant']->Id, PeranTenantBawaan::Kasir);
        $admin = BantuanOrganisasi::TambahAnggota($k['Tenant']->Id, PeranTenantBawaan::Admin);
        PasangPerangkatOwner($k['Pemilik']->Id);
        PasangPerangkatOwner($kasir->Id, 'HP Kasir');

        $dibuat = app(BuatNotifikasiKedaluwarsa::class)->Jalankan($k['Tenant']->Id, CarbonImmutable::parse('2026-10-07', 'Asia/Jakarta'));

        expect($dibuat)->toBe(2)
            ->and(NotifikasiPengguna::query()->orderBy('IdPengguna')->pluck('IdPengguna')->all())->toBe([$k['Pemilik']->Id, $admin->Id])
            ->and(NotifikasiPengguna::query()->where('IdPengguna', $kasir->Id)->exists())->toBeFalse()
            ->and(NotifikasiPengguna::query()->where('IdPengguna', $admin->Id)->sole()->Data)->toMatchArray(['Href' => '/kelola/laporan/stok?tab=kedaluwarsa']);
    });

    it('tanpa batch yang mendekati kedaluwarsa tidak ada notifikasi dan tidak ada penanda', function (): void {
        $k = BantuanPenjualan::Siapkan($this);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        PasangPerangkatOwner($k['Pemilik']->Id);

        expect(app(BuatNotifikasiKedaluwarsa::class)->Jalankan($k['Tenant']->Id, CarbonImmutable::parse('2026-10-07', 'Asia/Jakarta')))->toBe(0)
            ->and(PengingatKedaluwarsaBatch::query()->count())->toBe(0);
    });

    it('perintah tindakan:buat-notifikasi-operasional ikut mengumumkan kedaluwarsa; tenant lain tidak bocor (isolasi)', function (): void {
        $k = SiapkanBatchKedaluwarsa($this);
        PasangPerangkatOwner($k['Pemilik']->Id);
        $lain = BantuanPenjualan::Siapkan($this, 'Toko Lain');
        PasangPerangkatOwner($lain['Pemilik']->Id, 'Pixel Lain');

        $this->artisan('tindakan:buat-notifikasi-operasional', ['--tenant' => [$k['Tenant']->Id]])->assertSuccessful();

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(NotifikasiPengguna::query()->where('Jenis', JenisNotifikasiPengguna::StokKedaluwarsa->value)->pluck('IdPengguna')->all())->toBe([$k['Pemilik']->Id]);
        BantuanOrganisasi::AturKonteks($lain['Tenant']->Id);
        expect(NotifikasiPengguna::query()->where('Jenis', JenisNotifikasiPengguna::StokKedaluwarsa->value)->count())->toBe(0);
    });
});
