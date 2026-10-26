<?php

declare(strict_types=1);

use App\Domain\Akuntansi\Aksi\SimpanTransaksiKasBank;
use App\Domain\Akuntansi\Data\DataTransaksiKasBank;
use App\Domain\Akuntansi\Enum\JenisTransaksiKasBank;
use App\Domain\Akuntansi\Kueri\LabaRugi;
use App\Domain\Akuntansi\Model\Akun;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Organisasi\Model\OutletPengguna;
use App\Domain\Penjualan\Model\Penjualan;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Akuntansi\BantuanJurnal;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Penjualan\BantuanPenjualan;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Tenant\BantuanPendaftaran;
use Tests\TestCase;

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

/** Transaksi kas & bank langsung lewat Aksi (tenant konteks). */
function CatatKasBank(string $jenis, string $tanggal, string $kodeSumber, string $kodeTujuan, string $jumlah, ?int $idOutlet = null, string $keterangan = 'Uji laporan'): void
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

/**
 * Tenant ritel dengan dua penjualan tunai (stok awal minyak 10 @ Rp 30.000), beban listrik bulan ini, dan beban
 * bulan lalu.
 *
 * @return array{k: array<string, mixed>, Penjualan: list<Penjualan>, Tanggal: CarbonImmutable}
 */
function SiapkanDataLaporan(TestCase $tes): array
{
    $k = BantuanPenjualan::Siapkan($tes);
    $minyak = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id);
    $satu = BantuanPenjualan::Jual($tes, $k, ['Baris' => [['Produk' => $minyak, 'Jumlah' => '2', 'Harga' => '38500.00']]]);
    $dua = BantuanPenjualan::Jual($tes, $k, ['Baris' => [['Produk' => $minyak, 'Jumlah' => '1', 'Harga' => '38500.00']]]);
    $tanggal = CarbonImmutable::parse($satu->TanggalBisnis->toDateString());
    CatatKasBank('Pengeluaran', $tanggal->toDateString(), '1-1100', '6-2000', '1250000.00', $k['Outlet']->Id, 'Bayar listrik PLN');
    CatatKasBank('Pengeluaran', $tanggal->startOfMonth()->subDays(10)->toDateString(), '1-1200', '6-2000', '500000.00', null, 'Listrik bulan lalu');

    return ['k' => $k, 'Penjualan' => [$satu, $dua], 'Tanggal' => $tanggal];
}

describe('F-13a laporan keuangan (FIN-06, FIN-07; PRD "Rincian F-13a")', function (): void {
    it('laba rugi = pendapatan − HPP − beban cocok dengan data penjualan uji. Pembanding bulan sebelumnya', function (): void {
        $d = SiapkanDataLaporan($this);
        $bulan = $d['Tanggal'];
        $pendapatan = Uang::Nol();
        $hpp = Uang::Nol();

        foreach ($d['Penjualan'] as $p) {
            $pendapatan = $pendapatan->Tambah(Uang::Dari($p->Subtotal));
            $hpp = $hpp->Tambah(Uang::Dari($p->TotalHpp));
        }

        expect($pendapatan->KeString())->toBe('115500.00')->and($hpp->KeString())->toBe('90000.00');
        $beban = Uang::Dari('1250000.00');
        $query = 'dari='.$bulan->startOfMonth()->toDateString().'&sampai='.$bulan->endOfMonth()->toDateString();

        BantuanPersediaan::MasukSebagai($this, $d['k']['Tenant']->Id, PeranTenantBawaan::Akuntan)
            ->get('/kelola/akuntansi/laporan/laba-rugi?'.$query)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $h) => $h
                ->component('Kelola/Akuntansi/Laporan/LabaRugi')
                ->where('Laporan.Periode.DariSebelumnya', $bulan->startOfMonth()->subMonthNoOverflow()->toDateString())
                ->where('Laporan.Periode.SampaiSebelumnya', $bulan->startOfMonth()->subDay()->toDateString())
                ->where('Laporan.Ringkasan.Pendapatan', ['Nilai' => $pendapatan->KeString(), 'NilaiSebelumnya' => '0.00'])
                ->where('Laporan.Ringkasan.Hpp', ['Nilai' => $hpp->KeString(), 'NilaiSebelumnya' => '0.00'])
                ->where('Laporan.Ringkasan.LabaKotor', ['Nilai' => $pendapatan->Kurangi($hpp)->KeString(), 'NilaiSebelumnya' => '0.00'])
                ->where('Laporan.Ringkasan.Beban', ['Nilai' => $beban->KeString(), 'NilaiSebelumnya' => '500000.00'])
                ->where('Laporan.Ringkasan.LabaBersih', ['Nilai' => $pendapatan->Kurangi($hpp)->Kurangi($beban)->KeString(), 'NilaiSebelumnya' => '-500000.00'])
                ->where('Laporan.Baris', fn ($baris): bool => collect($baris)->pluck('Jenis')->first() === 'Kepala'
                    && collect($baris)->firstWhere('Kode', '4-1000')['Nilai'] === '115500.00'
                    && collect($baris)->firstWhere('Kode', '5-1000')['Nilai'] === '90000.00'
                    && collect($baris)->last()['Label'] === 'Laba bersih'));
    });

    it('periode pembanding: bulan penuh → bulan penuh sebelumnya. Rentang bebas → jumlah hari yang sama', function (): void {
        expect(LabaRugi::HitungPeriodeSebelumnya('2026-09-01', '2026-09-30'))->toBe(['2026-08-01', '2026-08-31'])
            ->and(LabaRugi::HitungPeriodeSebelumnya('2026-03-01', '2026-03-31'))->toBe(['2026-02-01', '2026-02-28'])
            ->and(LabaRugi::HitungPeriodeSebelumnya('2026-01-01', '2026-03-31'))->toBe(['2025-10-01', '2025-12-31'])
            ->and(LabaRugi::HitungPeriodeSebelumnya('2026-09-10', '2026-09-16'))->toBe(['2026-09-03', '2026-09-09']);
    });

    it('neraca saldo: Σ debit = Σ kredit (mutasi & saldo), saldo akhir per akun benar, saldo awal dari jurnal sebelum periode', function (): void {
        $d = SiapkanDataLaporan($this);
        $hari = $d['Tanggal']->toDateString();
        $masuk = BantuanPersediaan::MasukSebagai($this, $d['k']['Tenant']->Id, PeranTenantBawaan::Akuntan);

        $masuk->get("/kelola/akuntansi/laporan/neraca-saldo?dari={$d['Tanggal']->startOfMonth()->toDateString()}&sampai={$hari}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $h) => $h
                ->component('Kelola/Akuntansi/Laporan/NeracaSaldo')
                ->where('Laporan.Seimbang', true)
                ->where('Laporan.Total', fn ($total): bool => $total['Debit'] === $total['Kredit'] && $total['SaldoAkhirDebit'] === $total['SaldoAkhirKredit'])
                ->where('Laporan.Baris', function ($baris): bool {
                    $perKode = collect($baris)->keyBy('Kode');

                    return $perKode['1-1500']['SaldoAkhirDebit'] === '210000.00'
                        && $perKode['4-1000']['SaldoAkhirKredit'] === '115500.00'
                        && $perKode['1-1200']['SaldoAwalKredit'] === '500000.00'
                        && $perKode['1-1200']['Debit'] === '0.00'
                        && $perKode['1-1200']['SaldoAkhirKredit'] === '500000.00';
                }));

        // Semua jurnal sebelum periode: masuk saldo awal, mutasi nol, tetap seimbang.
        $besok = $d['Tanggal']->addDay()->toDateString();
        $masuk->get("/kelola/akuntansi/laporan/neraca-saldo?dari={$besok}&sampai={$besok}")->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Laporan.Seimbang', true)
            ->where('Laporan.Total.Debit', '0.00')
            ->where('Laporan.Total', fn ($total): bool => $total['SaldoAwalDebit'] === $total['SaldoAwalKredit'] && $total['SaldoAwalDebit'] !== '0.00'));
    });

    it('buku besar: saldo awal, mutasi urut, saldo berjalan benar di halaman berikutnya. Tautan jurnal & dokumen sumber', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();
        CatatKasBank('Penerimaan', '2026-08-31', '3-1000', '1-1100', '1000000.00', null, 'Modal awal');

        foreach (range(1, 27) as $i) {
            CatatKasBank('Pengeluaran', '2026-09-'.str_pad((string) min(28, $i), 2, '0', STR_PAD_LEFT), '1-1100', '6-9000', '1000.00', null, "Parkir hari {$i}");
        }

        $kas = Akun::query()->where('Kode', '1-1100')->sole();
        $masuk = BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id, PeranTenantBawaan::Akuntan);
        $alamat = "/kelola/akuntansi/laporan/buku-besar?akun={$kas->Uuid}&dari=2026-09-01&sampai=2026-09-30";

        $masuk->get($alamat)->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Akuntansi/Laporan/BukuBesar')
            ->where('Akun.Kode', '1-1100')
            ->where('Saring.Akun', $kas->Uuid)
            ->where('Mutasi.Meta.Total', 27)
            ->where('Mutasi.Ringkasan', ['SaldoAwal' => '1000000.00', 'TotalDebit' => '0.00', 'TotalKredit' => '27000.00', 'SaldoAkhir' => '973000.00'])
            ->where('Mutasi.Data.0.Tanggal', '2026-09-01')
            ->where('Mutasi.Data.0.Kredit', '1000.00')
            ->where('Mutasi.Data.0.Saldo', '999000.00')
            ->where('Mutasi.Data.0.LabelSumber', 'Transaksi kas & bank')
            ->where('Mutasi.Data.0.NomorSumber', 'KB/2026/09/0001')
            ->where('Mutasi.Data.0.TautanSumber', fn ($t): bool => str_starts_with((string) $t, '/kelola/akuntansi/kas-bank/'))
            ->has('Mutasi.Data', 25)
            ->has('OpsiAkun'));
        $masuk->getJson($alamat.'&halaman=2')->assertOk()
            ->assertJsonPath('Meta.Halaman', 2)
            ->assertJsonCount(2, 'Data')
            ->assertJsonPath('Data.0.Saldo', '974000.00')
            ->assertJsonPath('Data.1.Saldo', '973000.00')
            ->assertJsonPath('Ringkasan.SaldoAkhir', '973000.00');

        // Akun bersaldo normal kredit: saldo positif di sisi kredit.
        $modal = Akun::query()->where('Kode', '3-1000')->sole();
        $masuk->getJson("/kelola/akuntansi/laporan/buku-besar?akun={$modal->Uuid}&dari=2026-08-01&sampai=2026-09-30")
            ->assertJsonPath('Data.0.Saldo', '1000000.00');

        // Tanpa akun: kosong; akun tak dikenal: 404.
        $masuk->get('/kelola/akuntansi/laporan/buku-besar')->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Akun', null)->where('Mutasi.Meta.Total', 0));
        $masuk->get('/kelola/akuntansi/laporan/buku-besar?akun=01J9ZC5V7Q8R2T4W6Y8A0B2C4D')->assertNotFound();
    });

    it('ekspor CSV memakai filter yang sama. Angka desimal tidak dinetralkan, teks berbahaya dinetralkan', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();
        CatatKasBank('Pengeluaran', '2026-09-05', '1-1100', '6-9000', '15000.00', null, '=HYPERLINK("http://jahat")');
        $kas = Akun::query()->where('Kode', '1-1100')->sole();
        $masuk = BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id, PeranTenantBawaan::Akuntan);

        $bukuBesar = $masuk->get("/kelola/akuntansi/laporan/buku-besar/ekspor?akun={$kas->Uuid}&dari=2026-09-01&sampai=2026-09-30");
        $bukuBesar->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $isi = $bukuBesar->streamedContent();
        expect($isi)->toStartWith("\xEF\xBB\xBFTanggal,")
            ->toContain('2026-09-05,JU/2026/09/000001')
            ->toContain(',-15000.00')
            ->toContain("\"'=HYPERLINK(\"\"http://jahat\"\")\"");

        $neraca = $masuk->get('/kelola/akuntansi/laporan/neraca-saldo/ekspor?dari=2026-09-01&sampai=2026-09-30')->assertOk();
        expect($neraca->streamedContent())->toContain('1-1100,"Kas Outlet",Aset,0.00,0.00,0.00,15000.00,0.00,15000.00')
            ->toContain(',Total,,0.00,0.00,15000.00,15000.00,15000.00,15000.00');
        $labaRugi = $masuk->get('/kelola/akuntansi/laporan/laba-rugi/ekspor?dari=2026-09-01&sampai=2026-09-30')->assertOk();
        expect($labaRugi->streamedContent())->toContain('6-9000,"Beban Selisih Kas / Lain-lain",15000.00,0.00')->toContain(',"Laba bersih",-15000.00,0.00');
        $masuk->get('/kelola/akuntansi/laporan/buku-besar/ekspor')->assertNotFound();
    });

    it('izin, isolasi tenant, dan filter/batas outlet', function (): void {
        $a = BantuanPersediaan::SiapkanTenant('Toko Sembako Berkah Jaya');
        $solo = BantuanJurnal::BuatOutlet();
        CatatKasBank('Pengeluaran', '2026-09-05', '1-1100', '6-9000', '15000.00', $a['Outlet']->Id);
        CatatKasBank('Pengeluaran', '2026-09-06', '1-1100', '6-9000', '7000.00', $solo->Id);
        CatatKasBank('Pengeluaran', '2026-09-07', '1-1200', '6-2000', '300000.00');
        $kas = Akun::query()->where('Kode', '1-1100')->sole();
        $periode = 'dari=2026-09-01&sampai=2026-09-30';

        foreach (['laba-rugi', 'neraca-saldo', 'buku-besar', 'laba-rugi/ekspor'] as $laporan) {
            BantuanPersediaan::MasukSebagai($this, $a['Tenant']->Id, PeranTenantBawaan::Kasir)->get("/kelola/akuntansi/laporan/{$laporan}")->assertForbidden();
        }

        $masuk = BantuanPersediaan::MasukSebagai($this, $a['Tenant']->Id, PeranTenantBawaan::Akuntan);
        $masuk->get("/kelola/akuntansi/laporan/laba-rugi?{$periode}&outlet={$solo->Uuid}")->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Saring.Outlet', $solo->Uuid)
            ->where('Laporan.Ringkasan.Beban.Nilai', '7000.00'));
        $masuk->get("/kelola/akuntansi/laporan/laba-rugi?{$periode}")->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Laporan.Ringkasan.Beban.Nilai', '322000.00'));

        // Akuntan outlet Solo: hanya baris jurnal outlet Solo; outlet lain 404.
        $akuntan = BantuanOrganisasi::TambahAnggota($a['Tenant']->Id, PeranTenantBawaan::Akuntan, semuaOutlet: false);
        OutletPengguna::query()->create(['IdOutlet' => $solo->Id, 'IdPengguna' => $akuntan->Id, 'IdPeran' => BantuanOrganisasi::Peran($a['Tenant']->Id, PeranTenantBawaan::Akuntan)->Id]);
        BantuanOrganisasi::Masuk($this, $akuntan, $a['Tenant']->Id);
        $this->get("/kelola/akuntansi/laporan/laba-rugi?{$periode}")->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Laporan.Ringkasan.Beban.Nilai', '7000.00')
            ->where('OpsiOutlet', [['Uuid' => $solo->Uuid, 'Nama' => 'Cabang Solo Baru']]));
        $this->get("/kelola/akuntansi/laporan/neraca-saldo?{$periode}&outlet={$a['Outlet']->Uuid}")->assertNotFound();
        $this->getJson("/kelola/akuntansi/laporan/buku-besar?akun={$kas->Uuid}&{$periode}")->assertJsonPath('Meta.Total', 1)->assertJsonPath('Data.0.Kredit', '7000.00');

        // Tenant lain tidak melihat data tenant A dan tidak bisa membuka akunnya.
        $b = BantuanPersediaan::SiapkanTenant('Warung Bakso Pak Kumis');
        BantuanPersediaan::MasukSebagai($this, $b['Tenant']->Id, PeranTenantBawaan::Akuntan);
        $this->get("/kelola/akuntansi/laporan/neraca-saldo?{$periode}")->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Laporan.Baris', [])->where('Laporan.Total.Debit', '0.00'));
        $this->get("/kelola/akuntansi/laporan/buku-besar?akun={$kas->Uuid}")->assertNotFound();
        $this->get("/kelola/akuntansi/laporan/laba-rugi?{$periode}&outlet={$solo->Uuid}")->assertNotFound();
    });

    it('filter tanggal tidak sah jatuh ke bulan berjalan. Rentang terbalik ditukar', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();
        $hariIni = CarbonImmutable::today();
        $masuk = BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id, PeranTenantBawaan::Akuntan);

        $masuk->get('/kelola/akuntansi/laporan/neraca-saldo?dari=2026-02-30&sampai=kemarin')->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Saring', ['Dari' => $hariIni->startOfMonth()->toDateString(), 'Sampai' => $hariIni->toDateString(), 'Outlet' => '']));
        $masuk->get('/kelola/akuntansi/laporan/neraca-saldo?dari=2026-09-30&sampai=2026-09-01')->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Saring.Dari', '2026-09-01')->where('Saring.Sampai', '2026-09-30'));
    });
});
