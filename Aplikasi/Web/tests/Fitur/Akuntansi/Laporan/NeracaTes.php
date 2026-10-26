<?php

declare(strict_types=1);

use App\Domain\Akuntansi\Aksi\SimpanTransaksiKasBank;
use App\Domain\Akuntansi\Data\DataTransaksiKasBank;
use App\Domain\Akuntansi\Enum\JenisTransaksiKasBank;
use App\Domain\Akuntansi\Enum\PeranAkun;
use App\Domain\Akuntansi\Model\Akun;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Pembelian\BantuanPembelian;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * F-13 neraca (FIN-07 P1, PRD "Rincian F-13a", v1.54): posisi aset = kewajiban + ekuitas dari `JurnalDetail` pada
 * akhir periode & sehari sebelum periode; laba yang belum ditutup buku di ekuitas (tahun berjalan vs tahun-tahun
 * lalu); invarian nilai persediaan neraca = Σ nilai stok dan hutang usaha = Σ sisa faktur; izin & isolasi tenant.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

function CatatKasBankNeraca(string $jenis, string $tanggal, string $kodeSumber, string $kodeTujuan, string $jumlah, string $keterangan): void
{
    app(SimpanTransaksiKasBank::class)->Jalankan(new DataTransaksiKasBank(
        JenisTransaksiKasBank::from($jenis),
        CarbonImmutable::parse($tanggal),
        null,
        (string) Akun::query()->where('Kode', $kodeSumber)->value('Uuid'),
        (string) Akun::query()->where('Kode', $kodeTujuan)->value('Uuid'),
        Uang::Dari($jumlah),
        $keterangan,
        null,
        null,
    ));
}

/**
 * @param  list<array<string, mixed>>  $baris
 */
function NilaiBarisNeraca(array $baris, string $kode): ?string
{
    $cocok = collect($baris)->firstWhere('Kode', $kode);

    return is_array($cocok) && is_string($cocok['Nilai']) ? $cocok['Nilai'] : null;
}

describe('F-13 neraca (FIN-07)', function (): void {
    it('seimbang. Persediaan neraca = Σ nilai stok, hutang usaha = Σ sisa faktur, laba tahun berjalan = pendapatan − HPP − beban', function (): void {
        $t = BantuanPembelian::SiapkanTenant();
        $idPemilik = $t['Pemilik']->Id;
        $hariIni = BantuanPembelian::Hari();
        $kemarin = $hariIni->subDay()->toDateString();
        $minyak = BantuanKatalog::BuatProduk(['Nama' => 'Minyak Goreng Sawit Bening Kemasan Pouch 2 Liter']);
        $pemasok = BantuanPembelian::BuatPemasok();

        // Posisi awal (kemarin): hanya setoran modal Rp 5.000.000 ke kas.
        CatatKasBankNeraca('Penerimaan', $kemarin, '3-1000', '1-1100', '5000000.00', 'Setoran modal pemilik');
        // Hari ini: terima 24 @ Rp 31.500, fakturkan, jual 4 (HPP), bayar listrik Rp 250.000.
        $grn = BantuanPembelian::TerimaTanpaPo($pemasok, $t['Gudang'], [[$minyak, '24', '31500']], $idPemilik);
        BantuanPembelian::Fakturkan($pemasok, [$grn], $idPemilik);
        BantuanPembelian::Jual($minyak, $t['Gudang'], '4', $idPemilik);
        CatatKasBankNeraca('Pengeluaran', $hariIni->toDateString(), '1-1100', '6-2000', '250000.00', 'Bayar listrik PLN');

        $stok = (string) DB::table('SaldoStok')->where('IdTenant', $t['Tenant']->Id)->sum('NilaiPersediaan');
        $kodePersediaan = (string) Akun::query()->whereKey(DB::table('PemetaanAkun')->where('IdTenant', $t['Tenant']->Id)->where('Kunci', PeranAkun::PersediaanBarangDagang->value)->whereNull('IdOutlet')->value('IdAkun'))->value('Kode');
        $kodeHutang = (string) Akun::query()->whereKey(DB::table('PemetaanAkun')->where('IdTenant', $t['Tenant']->Id)->where('Kunci', PeranAkun::HutangUsaha->value)->whereNull('IdOutlet')->value('IdAkun'))->value('Kode');
        expect(BigDecimal::of($stok)->isEqualTo('630000'))->toBeTrue();

        BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id, PeranTenantBawaan::Akuntan)
            ->get("/kelola/akuntansi/laporan/neraca?dari={$hariIni->toDateString()}&sampai={$hariIni->toDateString()}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $h) => $h
                ->component('Kelola/Akuntansi/Laporan/Neraca')
                ->where('Laporan.Posisi', ['Akhir' => $hariIni->toDateString(), 'Awal' => $kemarin])
                ->where('Laporan.Seimbang', true)
                ->where('Laporan.Ringkasan.Aset', ['Nilai' => '5380000.00', 'NilaiAwal' => '5000000.00'])
                ->where('Laporan.Ringkasan.Kewajiban', ['Nilai' => '756000.00', 'NilaiAwal' => '0.00'])
                ->where('Laporan.Ringkasan.Ekuitas', ['Nilai' => '4624000.00', 'NilaiAwal' => '5000000.00'])
                ->where('Laporan.Ringkasan.KewajibanEkuitas', ['Nilai' => '5380000.00', 'NilaiAwal' => '5000000.00'])
                ->where('Laporan.Ringkasan.LabaBerjalan', ['Nilai' => '-376000.00', 'NilaiAwal' => '0.00'])
                ->where('Laporan.Baris', fn ($baris): bool => NilaiBarisNeraca($baris->all(), $kodePersediaan) === '630000.00'
                    && NilaiBarisNeraca($baris->all(), $kodeHutang) === '756000.00'
                    && NilaiBarisNeraca($baris->all(), '1-1100') === '4750000.00'
                    && collect($baris)->pluck('Id')->contains('Laba|LabaBerjalan')
                    && ! collect($baris)->pluck('Id')->contains('Laba|LabaLalu')
                    && collect($baris)->last()['Id'] === 'Total|KewajibanEkuitas'));

        expect(BantuanPembelian::PeriksaInvarian($t['Tenant']->Id))->toBe([]);
    });

    it('laba sebelum 1 Januari tampil sebagai laba tahun-tahun lalu, laba sesudahnya laba tahun berjalan. Ekspor CSV', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();
        CatatKasBankNeraca('Penerimaan', '2025-12-01', '3-1000', '1-1100', '1000000.00', 'Modal awal');
        CatatKasBankNeraca('Pengeluaran', '2025-12-20', '1-1100', '6-2000', '100000.00', 'Listrik Desember');
        CatatKasBankNeraca('Pengeluaran', '2026-01-10', '1-1100', '6-2000', '40000.00', 'Listrik Januari');
        $masuk = BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id, PeranTenantBawaan::Akuntan);

        $masuk->get('/kelola/akuntansi/laporan/neraca?dari=2026-01-01&sampai=2026-01-31')->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Laporan.Posisi.Awal', '2025-12-31')
            ->where('Laporan.Seimbang', true)
            ->where('Laporan.Ringkasan.LabaBerjalan', ['Nilai' => '-40000.00', 'NilaiAwal' => '-100000.00'])
            ->where('Laporan.Ringkasan.Aset', ['Nilai' => '860000.00', 'NilaiAwal' => '900000.00'])
            ->where('Laporan.Baris', function ($baris): bool {
                $perId = collect($baris)->keyBy('Id');

                return $perId['Laba|LabaLalu']['Nilai'] === '-100000.00'
                    && $perId['Laba|LabaLalu']['NilaiAwal'] === '0.00'
                    && $perId['Laba|LabaBerjalan']['Nilai'] === '-40000.00'
                    && $perId['Subtotal|Ekuitas']['Nilai'] === '860000.00';
            }));

        $csv = $masuk->get('/kelola/akuntansi/laporan/neraca/ekspor?dari=2026-01-01&sampai=2026-01-31')->assertOk();
        $csv->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        expect($csv->streamedContent())->toStartWith("\xEF\xBB\xBFKode,Keterangan,\"Posisi 2026-01-31\",\"Posisi 2025-12-31\"")
            ->toContain('1-1100,"Kas Outlet",860000.00,900000.00')
            ->toContain(',"Laba tahun-tahun lalu (belum ditutup buku)",-100000.00,0.00')
            ->toContain(',"Total kewajiban dan ekuitas",860000.00,900000.00');
    });

    it('izin laporan.keuangan.lihat dan isolasi tenant', function (): void {
        $a = BantuanPersediaan::SiapkanTenant('Toko Sembako Berkah Jaya');
        CatatKasBankNeraca('Penerimaan', '2026-09-01', '3-1000', '1-1100', '1000000.00', 'Modal awal');

        foreach (['neraca', 'neraca/ekspor'] as $laporan) {
            BantuanPersediaan::MasukSebagai($this, $a['Tenant']->Id, PeranTenantBawaan::Kasir)->get("/kelola/akuntansi/laporan/{$laporan}")->assertForbidden();
        }

        $b = BantuanPersediaan::SiapkanTenant('Warung Bakso Pak Kumis');
        BantuanPersediaan::MasukSebagai($this, $b['Tenant']->Id, PeranTenantBawaan::Akuntan)
            ->get('/kelola/akuntansi/laporan/neraca?dari=2026-09-01&sampai=2026-09-30')
            ->assertInertia(fn (AssertableInertia $h) => $h
                ->where('Laporan.Seimbang', true)
                ->where('Laporan.Ringkasan.Aset', ['Nilai' => '0.00', 'NilaiAwal' => '0.00'])
                ->where('Laporan.Baris', fn ($baris): bool => collect($baris)->where('Jenis', 'Akun')->isEmpty()));
    });
});
