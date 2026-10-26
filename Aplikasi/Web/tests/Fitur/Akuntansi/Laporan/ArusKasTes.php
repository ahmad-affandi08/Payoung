<?php

declare(strict_types=1);

use App\Domain\Akuntansi\Aksi\SimpanTransaksiKasBank;
use App\Domain\Akuntansi\Data\DataTransaksiKasBank;
use App\Domain\Akuntansi\Enum\JenisTransaksiKasBank;
use App\Domain\Akuntansi\Enum\TipeAkun;
use App\Domain\Akuntansi\Kueri\ArusKas;
use App\Domain\Akuntansi\Model\Akun;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Penjualan\BantuanPenjualan;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * F-13 arus kas metode langsung (FIN-07 P1, PRD "Rincian F-13a", v1.55): kas masuk/keluar per aktivitas dari baris
 * jurnal akun kas & bank; kas awal + kenaikan bersih = kas akhir = saldo akun kas & bank; transfer antar kas tidak
 * tampil; klasifikasi akun lawan (ekuitas → pendanaan, aset tidak lancar → investasi); CSV, izin & isolasi tenant.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

function CatatKasBankArus(string $jenis, string $tanggal, string $kodeSumber, string $kodeTujuan, string $jumlah, string $keterangan, ?int $idOutlet = null): void
{
    app(SimpanTransaksiKasBank::class)->Jalankan(new DataTransaksiKasBank(
        JenisTransaksiKasBank::from($jenis),
        CarbonImmutable::parse($tanggal),
        $idOutlet,
        (string) Akun::query()->where('Kode', $kodeSumber)->value('Uuid'),
        (string) Akun::query()->where('Kode', $kodeTujuan)->value('Uuid'),
        Uang::Dari($jumlah),
        $keterangan,
        null,
        null,
    ));
}

/** Σ (debit − kredit) seluruh baris jurnal akun kas & bank tenant sampai tanggal. */
function SaldoKasBank(int $idTenant, string $sampai): string
{
    return (string) DB::table('JurnalDetail')
        ->join('Akun', 'Akun.Id', '=', 'JurnalDetail.IdAkun')
        ->where('JurnalDetail.IdTenant', $idTenant)
        ->where('Akun.KasBank', true)
        ->where('JurnalDetail.Tanggal', '<=', $sampai)
        ->selectRaw('CAST(COALESCE(SUM(`Debit` - `Kredit`), 0) AS DECIMAL(20,2)) AS Saldo')
        ->value('Saldo');
}

describe('F-13 arus kas (FIN-07)', function (): void {
    it('penjualan tunai, beban, aset tetap, modal: aktivitas benar. Transfer antar kas tidak tampil. Kas awal + kenaikan = kas akhir', function (): void {
        $k = BantuanPenjualan::Siapkan($this);
        $minyak = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id);
        $jual = BantuanPenjualan::Jual($this, $k, ['Baris' => [['Produk' => $minyak, 'Jumlah' => '2', 'Harga' => '38500.00']]]);
        $hari = CarbonImmutable::parse($jual->TanggalBisnis->toDateString());
        $awalBulan = $hari->startOfMonth();
        $sebelum = $awalBulan->subDays(3)->toDateString();

        CatatKasBankArus('Penerimaan', $sebelum, '3-1000', '1-1200', '2000000.00', 'Modal awal di bank');
        CatatKasBankArus('Penerimaan', $hari->toDateString(), '3-1000', '1-1200', '5000000.00', 'Tambahan modal pemilik');
        CatatKasBankArus('Pengeluaran', $hari->toDateString(), '1-1200', '1-2000', '1500000.00', 'Beli etalase kaca');
        CatatKasBankArus('Pengeluaran', $hari->toDateString(), '1-1100', '6-2000', '250000.00', 'Bayar listrik PLN', $k['Outlet']->Id);
        CatatKasBankArus('Transfer', $hari->toDateString(), '1-1200', '1-1150', '1000000.00', 'Ambil tunai ke brankas');

        $idTenant = $k['Tenant']->Id;
        $saldoAwal = SaldoKasBank($idTenant, $awalBulan->subDay()->toDateString());
        $saldoAkhir = SaldoKasBank($idTenant, $hari->toDateString());
        $penjualanTunai = Uang::Dari($jual->TotalAkhir);
        $operasi = $penjualanTunai->Kurangi(Uang::Dari('250000.00'));
        expect($saldoAwal)->toBe('2000000.00');

        BantuanPersediaan::MasukSebagai($this, $idTenant, PeranTenantBawaan::Akuntan)
            ->get("/kelola/akuntansi/laporan/arus-kas?dari={$awalBulan->toDateString()}&sampai={$hari->toDateString()}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $h) => $h
                ->component('Kelola/Akuntansi/Laporan/ArusKas')
                ->where('Laporan.AdaAkunKas', true)
                ->where('Laporan.Ringkasan.Operasi', $operasi->KeString())
                ->where('Laporan.Ringkasan.Investasi', '-1500000.00')
                ->where('Laporan.Ringkasan.Pendanaan', '5000000.00')
                ->where('Laporan.Ringkasan.SaldoAwal', $saldoAwal)
                ->where('Laporan.Ringkasan.SaldoAkhir', $saldoAkhir)
                ->where('Laporan.Ringkasan.Kenaikan', $operasi->Tambah(Uang::Dari('3500000.00'))->KeString())
                ->where('Laporan.Baris', function ($baris) use ($penjualanTunai): bool {
                    $perId = collect($baris)->keyBy('Id');
                    $kode = collect($baris)->pluck('Kode')->filter()->values()->all();

                    return $perId['Sumber|Penjualan']['Nilai'] === $penjualanTunai->KeString()
                        && $perId['Sumber|Penjualan']['Aktivitas'] === 'Operasi'
                        && collect($baris)->firstWhere('Kode', '6-2000')['Aktivitas'] === 'Operasi'
                        && collect($baris)->firstWhere('Kode', '1-2000')['Aktivitas'] === 'Investasi'
                        && collect($baris)->firstWhere('Kode', '3-1000')['Aktivitas'] === 'Pendanaan'
                        && ! $perId->has('Sumber|TransaksiKasBank')
                        && ! in_array('1-1150', $kode, true)
                        && collect($baris)->last()['Id'] === 'Total|Akhir';
                }));
    });

    it('klasifikasi akun lawan: ekuitas & kewajiban jangka panjang → pendanaan, aset tidak lancar → investasi, lainnya → operasi', function (): void {
        $akun = fn (string $kode, TipeAkun $tipe): Akun => (new Akun)->forceFill(['Kode' => $kode, 'Jenis' => $tipe]);

        expect(ArusKas::KlasifikasiAkun($akun('3-1000', TipeAkun::Ekuitas)))->toBe('Pendanaan')
            ->and(ArusKas::KlasifikasiAkun($akun('3-4000', TipeAkun::Ekuitas)))->toBe('Pendanaan')
            ->and(ArusKas::KlasifikasiAkun($akun('2-2100', TipeAkun::Kewajiban)))->toBe('Pendanaan')
            ->and(ArusKas::KlasifikasiAkun($akun('2-1100', TipeAkun::Kewajiban)))->toBe('Operasi')
            ->and(ArusKas::KlasifikasiAkun($akun('1-2000', TipeAkun::Aset)))->toBe('Investasi')
            ->and(ArusKas::KlasifikasiAkun($akun('1-2900', TipeAkun::Aset)))->toBe('Investasi')
            ->and(ArusKas::KlasifikasiAkun($akun('1-1450', TipeAkun::Aset)))->toBe('Operasi')
            ->and(ArusKas::KlasifikasiAkun($akun('4-1000', TipeAkun::Pendapatan)))->toBe('Operasi')
            ->and(ArusKas::KlasifikasiAkun($akun('6-2000', TipeAkun::Beban)))->toBe('Operasi');
    });

    it('ekspor CSV, periode tanpa mutasi (kas awal = kas akhir), izin & isolasi tenant', function (): void {
        $a = BantuanPersediaan::SiapkanTenant('Toko Sembako Berkah Jaya');
        CatatKasBankArus('Penerimaan', '2026-09-01', '3-1000', '1-1100', '1000000.00', 'Modal awal');
        CatatKasBankArus('Pengeluaran', '2026-09-05', '1-1100', '6-9000', '15000.00', '=HYPERLINK("http://jahat")');
        $masuk = BantuanPersediaan::MasukSebagai($this, $a['Tenant']->Id, PeranTenantBawaan::Akuntan);

        $csv = $masuk->get('/kelola/akuntansi/laporan/arus-kas/ekspor?dari=2026-09-01&sampai=2026-09-30')->assertOk();
        $csv->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        expect($csv->streamedContent())->toStartWith("\xEF\xBB\xBFAktivitas,Kode,Keterangan,")
            ->toContain('Pendanaan,3-1000,"Modal Pemilik",1000000.00')
            ->toContain('Operasi,6-9000,')
            ->toContain(',-15000.00')
            ->toContain('Akhir,,"Kas & bank akhir periode",985000.00');

        $masuk->get('/kelola/akuntansi/laporan/arus-kas?dari=2026-10-01&sampai=2026-10-31')->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Laporan.Ringkasan.Kenaikan', '0.00')
            ->where('Laporan.Ringkasan.SaldoAwal', '985000.00')
            ->where('Laporan.Ringkasan.SaldoAkhir', '985000.00'));

        foreach (['arus-kas', 'arus-kas/ekspor'] as $laporan) {
            BantuanPersediaan::MasukSebagai($this, $a['Tenant']->Id, PeranTenantBawaan::Kasir)->get("/kelola/akuntansi/laporan/{$laporan}")->assertForbidden();
        }

        $b = BantuanPersediaan::SiapkanTenant('Warung Bakso Pak Kumis');
        BantuanPersediaan::MasukSebagai($this, $b['Tenant']->Id, PeranTenantBawaan::Akuntan)
            ->get('/kelola/akuntansi/laporan/arus-kas?dari=2026-09-01&sampai=2026-09-30')
            ->assertInertia(fn (AssertableInertia $h) => $h
                ->where('Laporan.Ringkasan.SaldoAkhir', '0.00')
                ->where('Laporan.Baris', fn ($baris): bool => collect($baris)->where('Jenis', 'Rincian')->isEmpty()));
    });
});
