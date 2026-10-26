<?php

declare(strict_types=1);

use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Laporan\Aksi\BangunUlangRingkasanPenjualanHarian;
use App\Domain\Laporan\Model\RingkasanPenjualanHarian;
use App\Domain\Laporan\Penangan\PerbaruiRingkasanPenjualanHarian;
use App\Domain\Penjualan\Enum\StatusPenjualan;
use App\Domain\Penjualan\Model\Penjualan;
use App\Domain\Penjualan\Model\PenjualanDetail;
use App\Domain\Penjualan\Model\ReturPenjualan;
use App\Domain\Penjualan\Peristiwa\PenjualanDiterima;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Tests\Pendukung\Akuntansi\BantuanJurnal;
use Tests\Pendukung\Kasir\BantuanKasir;
use Tests\Pendukung\Laporan\BantuanLaporan;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\PanduanAwal\BantuanPanduanAwal;
use Tests\Pendukung\Penjualan\BantuanPenjualan;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

beforeEach(function (): void {
    // Prasyarat pendaftaran (dokumen legal berlaku sejak "kemarin") dibuat pada tanggal tetap sebelum semua tanggal
    // skenario, agar tidak bergantung pada tanggal saat test dijalankan.
    $this->travelTo(CarbonImmutable::parse('2026-09-01 05:00:00', 'UTC'));
    BantuanPendaftaran::SiapkanPrasyarat();
    // Siang hari WIB agar tanggal bisnis (tutup buku 04:00) = tanggal kalender lokal.
    $this->travelTo(CarbonImmutable::parse('2026-10-07 05:00:00', 'UTC'));
});

/**
 * Σ dokumen per (outlet, tanggal) dihitung ulang di test dari angka total dokumen (rumus TotalAkhir, bukan rumus
 * kueri laporan): bersih jual = TotalAkhir − BiayaLayanan − TotalPajak − Pembulatan; retur = TotalNilai − TotalPajak −
 * TotalBiayaLayanan.
 *
 * @return array{Bersih: string, Pajak: string, Hpp: string, Diskon: string, JumlahTransaksi: int, JumlahRetur: int, JumlahVoid: int}
 */
function HitungSigmaDokumen(int $idOutlet, string $tanggal): array
{
    $bersih = Uang::Nol();
    $pajak = Uang::Nol();
    $hpp = Uang::Nol();
    $diskon = Uang::Nol();
    $jumlah = 0;
    $void = 0;

    foreach (Penjualan::query()->where('IdOutlet', $idOutlet)->where('TanggalBisnis', $tanggal)->get() as $p) {
        if ($p->Status === StatusPenjualan::Void) {
            $void++;

            continue;
        }

        $jumlah++;
        $bersih = $bersih->Tambah(Uang::Dari($p->TotalAkhir)->Kurangi(Uang::Dari($p->BiayaLayanan))->Kurangi(Uang::Dari($p->TotalPajak))->Kurangi(Uang::Dari($p->Pembulatan)));
        $pajak = $pajak->Tambah(Uang::Dari($p->TotalPajak));
        $hpp = $hpp->Tambah(Uang::Dari($p->TotalHpp));
        $diskon = $diskon->Tambah(Uang::Dari($p->TotalDiskon));
    }

    $retur = ReturPenjualan::query()->where('IdOutlet', $idOutlet)->where('TanggalBisnis', $tanggal)->get();

    foreach ($retur as $r) {
        $bersih = $bersih->Kurangi(Uang::Dari($r->TotalNilai)->Kurangi(Uang::Dari($r->TotalPajak))->Kurangi(Uang::Dari($r->TotalBiayaLayanan)));
        $pajak = $pajak->Kurangi(Uang::Dari($r->TotalPajak));
        $hpp = $hpp->Kurangi(Uang::Dari($r->TotalHpp));
    }

    return [
        'Bersih' => $bersih->KeString(),
        'Pajak' => $pajak->KeString(),
        'Hpp' => $hpp->KeString(),
        'Diskon' => $diskon->KeString(),
        'JumlahTransaksi' => $jumlah,
        'JumlahRetur' => $retur->count(),
        'JumlahVoid' => $void,
    ];
}

/** @return array{Bersih: string, Pajak: string, Hpp: string, Diskon: string, JumlahTransaksi: int, JumlahRetur: int, JumlahVoid: int} */
function AmbilBarisRingkasan(int $idOutlet, string $tanggal): array
{
    $r = RingkasanPenjualanHarian::query()->where('IdOutlet', $idOutlet)->where('TanggalBisnis', $tanggal)->sole();

    return [
        'Bersih' => $r->Bersih,
        'Pajak' => $r->Pajak,
        'Hpp' => $r->Hpp,
        'Diskon' => $r->Diskon,
        'JumlahTransaksi' => $r->JumlahTransaksi,
        'JumlahRetur' => $r->JumlahRetur,
        'JumlahVoid' => $r->JumlahVoid,
    ];
}

describe('F-14a ringkasan penjualan harian (RingkasanPenjualanHarian)', function (): void {
    it('invariant: setelah penjualan, void, dan retur diterima, baris ringkasan (lewat penangan antrean) = Σ dokumen. Void dikeluarkan, retur mengurangi. Angka & per metode/kanal sesuai', function (): void {
        $k = BantuanPenjualan::Siapkan($this);
        BantuanPanduanAwal::TerbitkanTarif('Ppn', null, '12.000000');
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $minyak = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id);
        BantuanPenjualan::AturProfilPajak($k, pkp: true);
        BantuanPenjualan::PasangKelompokPajak('Uji laporan: barang kena PPN', ['Ppn' => 'Subtotal'], $minyak);
        $o = $k['Outlet']->Id;

        // Setelah penjualan pertama.
        $a = BantuanPenjualan::Jual($this, $k, ['Pajak' => [['Ppn', '12.000000', 11, 12]], 'Baris' => [['Produk' => $minyak, 'Jumlah' => '2', 'Harga' => '38500.00']]]);
        $tanggal = $a->TanggalBisnis->toDateString();
        expect($tanggal)->toBe('2026-10-07')
            ->and(AmbilBarisRingkasan($o, $tanggal))->toBe(HitungSigmaDokumen($o, $tanggal))
            ->and(AmbilBarisRingkasan($o, $tanggal)['Bersih'])->toBe('77000.00');

        // Setelah void (penjualan dikeluarkan dari tanggal jualnya).
        $b = BantuanPenjualan::Jual($this, $k, ['Pajak' => [['Ppn', '12.000000', 11, 12]], 'Baris' => [['Produk' => $minyak, 'Jumlah' => '1', 'Harga' => '38500.00']]]);
        expect(AmbilBarisRingkasan($o, $tanggal)['JumlahTransaksi'])->toBe(2);
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [BantuanPenjualan::ItemVoid($k, $b)]))->toBe([['Diterima', null]]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(AmbilBarisRingkasan($o, $tanggal))->toBe(HitungSigmaDokumen($o, $tanggal))
            ->and(AmbilBarisRingkasan($o, $tanggal)['JumlahVoid'])->toBe(1)
            ->and(AmbilBarisRingkasan($o, $tanggal)['Bersih'])->toBe('77000.00');

        // Setelah retur (mengurangi pada tanggal returnya).
        $detail = PenjualanDetail::query()->where('IdPenjualan', $a->Id)->sole();
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [BantuanPenjualan::ItemRetur($k, $a, [['Detail' => $detail, 'Jumlah' => '1']])]))->toBe([['Diterima', null]]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $baris = RingkasanPenjualanHarian::query()->where('IdOutlet', $o)->where('TanggalBisnis', $tanggal)->sole();
        expect(AmbilBarisRingkasan($o, $tanggal))->toBe(HitungSigmaDokumen($o, $tanggal))
            ->and($baris->Kotor)->toBe('77000.00')
            ->and($baris->Retur)->toBe('38500.00')
            ->and($baris->Bersih)->toBe('38500.00')
            ->and($baris->Pajak)->toBe('4235.00')
            ->and($baris->Hpp)->toBe('30000.00')
            ->and($baris->JumlahRetur)->toBe(1)
            ->and($baris->PerMetodeBayar)->toEqual([['IdMetodePembayaran' => $k['Tunai']->Id, 'Jenis' => 'Tunai', 'Nama' => $k['Tunai']->Nama, 'Jumlah' => '42735.00']])
            ->and($baris->PerKanal)->toEqual([['Kanal' => 'BawaPulang', 'Bersih' => '38500.00', 'JumlahTransaksi' => 1]]);
    });

    it('data uji lengkap: Σ dokumen = ringkasan. Kotor, diskon, retur, bersih, pajak, HPP, per metode (tunai bersih kembalian − refund) & kanal', function (): void {
        $d = BantuanLaporan::SiapkanDataPenjualan($this);
        $o = $d['Outlet']->Id;
        $tanggal = $d['A']->TanggalBisnis->toDateString();
        $r = RingkasanPenjualanHarian::query()->where('IdOutlet', $o)->where('TanggalBisnis', $tanggal)->sole();

        expect(AmbilBarisRingkasan($o, $tanggal))->toBe(HitungSigmaDokumen($o, $tanggal))
            ->and($d['C']->Status)->toBe(StatusPenjualan::Void)
            ->and($d['A']->Status)->toBe(StatusPenjualan::DireturSebagian)
            ->and([$r->Kotor, $r->Diskon, $r->Retur, $r->Bersih, $r->Pajak, $r->Hpp])->toBe(['125500.00', '3850.00', '38500.00', '83150.00', '9146.50', '60000.00'])
            ->and([$r->JumlahTransaksi, $r->JumlahRetur, $r->JumlahVoid])->toBe([2, 1, 1])
            ->and($r->PerMetodeBayar)->toEqual([
                ['IdMetodePembayaran' => $d['Qris']->Id, 'Jenis' => 'QrisStatis', 'Nama' => 'QRIS Toko Berkah', 'Jumlah' => '50000.00'],
                ['IdMetodePembayaran' => $d['Tunai']->Id, 'Jenis' => 'Tunai', 'Nama' => $d['Tunai']->Nama, 'Jumlah' => '42296.50'],
            ])
            ->and($r->PerKanal)->toEqualCanonicalizing([
                ['Kanal' => 'BawaPulang', 'Bersih' => '48500.00', 'JumlahTransaksi' => 1],
                ['Kanal' => 'MakanDiTempat', 'Bersih' => '34650.00', 'JumlahTransaksi' => 1],
            ]);
    });

    it('penangan antrean idempoten: dijalankan berulang (atau peristiwa ganda) hasilnya sama. Baris rusak diperbaiki. Baris outlet tanpa dokumen dihapus', function (): void {
        $d = BantuanLaporan::SiapkanDataPenjualan($this);
        $o = $d['Outlet']->Id;
        $tanggal = $d['A']->TanggalBisnis->toDateString();
        $sebelum = RingkasanPenjualanHarian::query()->where('IdOutlet', $o)->sole()->only(['Kotor', 'Diskon', 'Retur', 'Bersih', 'Pajak', 'Hpp', 'JumlahTransaksi', 'PerMetodeBayar', 'PerKanal']);

        $peristiwa = new PenjualanDiterima($d['Tenant']->Id, $o, $tanggal, $d['A']->Id);
        app(PerbaruiRingkasanPenjualanHarian::class)->handle($peristiwa);
        app(PerbaruiRingkasanPenjualanHarian::class)->handle($peristiwa);
        event($peristiwa);
        BantuanOrganisasi::AturKonteks($d['Tenant']->Id);

        expect(RingkasanPenjualanHarian::query()->count())->toBe(1)
            ->and(RingkasanPenjualanHarian::query()->where('IdOutlet', $o)->sole()->only(array_keys($sebelum)))->toBe($sebelum);

        RingkasanPenjualanHarian::query()->where('IdOutlet', $o)->update(['Bersih' => '1.00', 'JumlahTransaksi' => 99]);
        $cabang = BantuanJurnal::BuatOutlet();
        RingkasanPenjualanHarian::query()->create(['IdOutlet' => $cabang->Id, 'TanggalBisnis' => $tanggal, 'Bersih' => '5000.00', 'DihitungPada' => now()]);

        expect(app(BangunUlangRingkasanPenjualanHarian::class)->Jalankan(CarbonImmutable::parse($tanggal)))->toBe(1)
            ->and(RingkasanPenjualanHarian::query()->count())->toBe(1)
            ->and(RingkasanPenjualanHarian::query()->where('IdOutlet', $o)->sole()->only(array_keys($sebelum)))->toBe($sebelum);
    });

    it('perintah laporan:bangun-ulang-ringkasan {tanggal?}: membangun ulang semua tenant dari dokumen, tanpa tanggal = H-1 & H-2. Tanggal tidak valid ditolak. Dijadwalkan tiap malam', function (): void {
        $d = BantuanLaporan::SiapkanDataPenjualan($this);
        $o = $d['Outlet']->Id;
        $tanggal = $d['A']->TanggalBisnis->toDateString();
        $harapan = HitungSigmaDokumen($o, $tanggal);
        RingkasanPenjualanHarian::query()->delete();

        // Tanpa tanggal = H-1 & H-2: penjualan hari ini belum dibangun ulang.
        expect(Artisan::call('laporan:bangun-ulang-ringkasan'))->toBe(0);
        BantuanOrganisasi::AturKonteks($d['Tenant']->Id);
        expect(RingkasanPenjualanHarian::query()->count())->toBe(0);

        expect(Artisan::call('laporan:bangun-ulang-ringkasan', ['tanggal' => $tanggal]))->toBe(0)
            ->and(Artisan::output())->toContain('1 baris ringkasan ditulis');
        BantuanOrganisasi::AturKonteks($d['Tenant']->Id);
        expect(AmbilBarisRingkasan($o, $tanggal))->toBe($harapan);

        // Esok hari (H-1) perintah malam membangun ulang tanggal itu.
        RingkasanPenjualanHarian::query()->delete();
        $this->travelTo(CarbonImmutable::parse('2026-10-07 20:00:00', 'UTC'));
        expect(Artisan::call('laporan:bangun-ulang-ringkasan'))->toBe(0);
        BantuanOrganisasi::AturKonteks($d['Tenant']->Id);
        expect(AmbilBarisRingkasan($o, $tanggal))->toBe($harapan);

        expect(Artisan::call('laporan:bangun-ulang-ringkasan', ['tanggal' => '2026-02-30']))->toBe(1)
            ->and(Artisan::call('laporan:bangun-ulang-ringkasan', ['--tenant' => ['abc']]))->toBe(1);

        $jadwal = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'laporan:bangun-ulang-ringkasan'));
        expect($jadwal)->not->toBeNull();
    });
});
