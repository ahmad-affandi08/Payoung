<?php

declare(strict_types=1);

use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Integrasi\Enum\JenisPesanWhatsapp;
use App\Domain\Integrasi\Enum\StatusNomorWhatsapp;
use App\Domain\Integrasi\Layanan\PengaturPesanWhatsapp;
use App\Domain\Integrasi\Model\NomorPengirimWhatsapp;
use App\Domain\Integrasi\Model\PenerimaLaporanWhatsapp;
use App\Domain\Integrasi\Model\RiwayatPesanWhatsapp;
use App\Domain\Karyawan\Model\Absensi;
use App\Domain\Karyawan\Model\Karyawan;
use App\Domain\Laporan\Aksi\KirimPenjualanPerJam;
use App\Domain\Laporan\Model\RingkasanPenjualanHarian;
use App\Domain\Organisasi\Model\Outlet;
use App\Domain\Pelanggan\Enum\JenisMutasiDeposit;
use App\Domain\Pelanggan\Enum\JenisMutasiPoin;
use App\Domain\Pelanggan\Enum\SumberMutasiDeposit;
use App\Domain\Pelanggan\Enum\SumberMutasiPoin;
use App\Domain\Pelanggan\Layanan\BukuDeposit;
use App\Domain\Pelanggan\Layanan\BukuPoin;
use App\Domain\Pelanggan\Model\Pelanggan;
use App\Domain\Pengelola\TimInternal\Enum\PeranPengelolaBawaan;
use App\Domain\Tenant\Enum\JenisOverride;
use App\Domain\Tenant\Model\OverrideTenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Pengelola\BantuanPengelola;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * D-299 tahap 2 dan 3: pesan otomatis poin/deposit ke pelanggan, penerima laporan karyawan, sakelar per jenis, riwayat.
 * Pesan baru mati secara bawaan. Tanpa nomor pengirim terhubung dan layanan aktif, tidak ada yang terkirim.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
    config(['integrasi.Whatsapp' => ['Penyedia' => 'Fonnte', 'Pengaturan' => [], 'Kredensial' => ['Token' => 'token-payoung', 'TokenAkun' => 'akun-payoung']]]);
    Http::fake(['api.fonnte.com/send' => Http::response(['status' => true, 'id' => ['ID1']])]);
});

/** @return array{Tenant: mixed, Pemilik: mixed, Outlet: Outlet} */
function TokoPesanOtomatis(): array
{
    $toko = BantuanOrganisasi::BuatTenant('Toko Kelontong Berkah Solo');
    BantuanOrganisasi::AturKonteks($toko['Tenant']->Id);
    OverrideTenant::query()->create([
        'IdTenant' => $toko['Tenant']->Id, 'Jenis' => JenisOverride::Fitur->value, 'Kunci' => 'integrasi.whatsapp', 'Nilai' => null,
        'BerakhirPada' => now()->addDays(60), 'Alasan' => 'Uji pesan otomatis',
        'DibuatOleh' => BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::SuperAdmin)->Id,
    ]);
    $outlet = Outlet::query()->firstOrFail();
    NomorPengirimWhatsapp::query()->create([
        'IdTenant' => $toko['Tenant']->Id, 'IdOutlet' => $outlet->Id, 'Label' => 'Kasir', 'NomorTelepon' => '6281200000001', 'Fungsi' => 'Umum',
        'Penyedia' => 'Fonnte', 'TokenPerangkat' => 'perangkat-umum', 'Status' => StatusNomorWhatsapp::Terhubung, 'Aktif' => true,
    ]);

    return $toko + ['Outlet' => $outlet];
}

function PesanTerkirimUji(): array
{
    return Http::recorded(fn ($r) => str_ends_with($r->url(), '/send'))->map(fn ($p) => $p[0])->all();
}

describe('D-299 pesan otomatis ke pelanggan', function (): void {
    it('poin bertambah tidak dikirim selama sakelarnya mati, lalu dikirim lewat nomor pengirim dan tercatat di riwayat setelah dinyalakan', function (): void {
        $toko = TokoPesanOtomatis();
        $pelanggan = Pelanggan::query()->create(['Nama' => 'Ani Wijayanti', 'NoHp' => '081355550101']);
        $buku = app(BukuPoin::class);

        $buku->Tambah($pelanggan->Id, 25, JenisMutasiPoin::Perolehan, SumberMutasiPoin::Penjualan, 901, null);
        expect(PesanTerkirimUji())->toBe([])->and(RiwayatPesanWhatsapp::query()->count())->toBe(0);

        app(PengaturPesanWhatsapp::class)->Atur(JenisPesanWhatsapp::PoinBertambah, true, $toko['Tenant']->Id);
        $buku->Tambah($pelanggan->Id, 10, JenisMutasiPoin::Perolehan, SumberMutasiPoin::Penjualan, 902, null);

        $terkirim = PesanTerkirimUji();
        expect($terkirim)->toHaveCount(1)
            ->and($terkirim[0]->header('Authorization')[0])->toBe('perangkat-umum')
            ->and($terkirim[0]['message'])->toContain('Halo Ani')->toContain('10 poin')->toContain('35');
        $riwayat = RiwayatPesanWhatsapp::query()->sole();
        expect($riwayat->Tujuan)->toBe('••••0101')->and($riwayat->Jenis)->toBe(JenisPesanWhatsapp::PoinBertambah);
    });

    it('deposit diisi dan dipakai mengirim saldo terbaru, penukaran poin mengirim sisa poin', function (): void {
        $toko = TokoPesanOtomatis();
        $pengatur = app(PengaturPesanWhatsapp::class);

        foreach ([JenisPesanWhatsapp::DepositDiisi, JenisPesanWhatsapp::DepositDipakai, JenisPesanWhatsapp::PoinDitukar] as $jenis) {
            $pengatur->Atur($jenis, true, $toko['Tenant']->Id);
        }

        $pelanggan = Pelanggan::query()->create(['Nama' => 'Budi Santoso', 'NoHp' => '081355550102']);
        $deposit = app(BukuDeposit::class);
        $deposit->Catat($pelanggan->Id, Uang::Dari('500000'), JenisMutasiDeposit::Isi, SumberMutasiDeposit::IsiDeposit, 11, 'DP/1', CarbonImmutable::now());
        $deposit->Catat($pelanggan->Id, Uang::Dari('-120000'), JenisMutasiDeposit::Pemakaian, SumberMutasiDeposit::Penjualan, 12, 'PJ/1', CarbonImmutable::now());
        $poin = app(BukuPoin::class);
        $poin->Tambah($pelanggan->Id, 50, JenisMutasiPoin::Perolehan, SumberMutasiPoin::Manual, 13, null);
        $poin->Kurangi($pelanggan->Id, 20, JenisMutasiPoin::Penukaran, SumberMutasiPoin::Penjualan, 14);

        $teks = array_map(fn ($p) => $p['message'], PesanTerkirimUji());
        expect($teks)->toHaveCount(3);
        expect($teks[0])->toContain('Rp 500.000')->and($teks[1])->toContain('Rp 120.000')->toContain('Rp 380.000')->and($teks[2])->toContain('20 poin')->toContain('30');
    });

    it('tidak mengirim bila konsol belum mengaktifkan WhatsApp walau sakelar menyala', function (): void {
        $toko = TokoPesanOtomatis();
        app(PengaturPesanWhatsapp::class)->Atur(JenisPesanWhatsapp::PoinBertambah, true, $toko['Tenant']->Id);
        config(['integrasi.Whatsapp' => ['Penyedia' => null]]);
        $pelanggan = Pelanggan::query()->create(['Nama' => 'Citra', 'NoHp' => '081355550103']);

        app(BukuPoin::class)->Tambah($pelanggan->Id, 10, JenisMutasiPoin::Perolehan, SumberMutasiPoin::Penjualan, 903, null);

        expect(PesanTerkirimUji())->toBe([]);
    });
});

describe('D-299 laporan otomatis ke karyawan', function (): void {
    it('penerima laporan maksimal enam per outlet dan nomor ganda ditolak, sakelar jenis tersimpan', function (): void {
        $toko = TokoPesanOtomatis();
        $sesi = BantuanOrganisasi::Masuk($this, $toko['Pemilik'], $toko['Tenant']->Id);
        $kirim = fn (string $nomor) => $sesi->post('/kelola/pelanggan/whatsapp/penerima', ['UuidOutlet' => $toko['Outlet']->Uuid, 'Nama' => 'Penerima', 'NomorTelepon' => $nomor]);

        foreach (range(1, 6) as $i) {
            $kirim('08121234560'.$i)->assertSessionHasNoErrors();
        }

        $kirim('081212345607')->assertSessionHasErrors('NomorTelepon');
        $kirim('081212345601')->assertSessionHasErrors('NomorTelepon');
        $kirim('123')->assertSessionHasErrors('NomorTelepon');
        expect(PenerimaLaporanWhatsapp::query()->count())->toBe(6);

        $sesi->put('/kelola/pelanggan/whatsapp/pesan-otomatis/LaporanTutupKasir', ['Aktif' => true])->assertSessionHasNoErrors();
        expect(app(PengaturPesanWhatsapp::class)->Aktif(JenisPesanWhatsapp::LaporanTutupKasir))->toBeTrue();
        $sesi->put('/kelola/pelanggan/whatsapp/pesan-otomatis/JenisSalah', ['Aktif' => true])->assertNotFound();
    });

    it('penjualan per jam dikirim ke semua penerima outlet sekali per jam dan hanya bila sakelarnya menyala', function (): void {
        $toko = TokoPesanOtomatis();
        foreach (['6281200000021', '6281200000022'] as $i => $nomor) {
            PenerimaLaporanWhatsapp::query()->create(['IdTenant' => $toko['Tenant']->Id, 'IdOutlet' => $toko['Outlet']->Id, 'Nama' => "P{$i}", 'NomorTelepon' => $nomor]);
        }
        $siang = CarbonImmutable::now()->setTimezone('Asia/Jakarta')->setTime(13, 5)->utc();
        RingkasanPenjualanHarian::query()->create([
            'IdTenant' => $toko['Tenant']->Id, 'IdOutlet' => $toko['Outlet']->Id, 'TanggalBisnis' => $siang->setTimezone('Asia/Jakarta')->toDateString(), 'JumlahTransaksi' => 7, 'Bersih' => '1250000.00', 'DihitungPada' => now(),
        ]);
        $aksi = app(KirimPenjualanPerJam::class);

        expect($aksi->Jalankan($toko['Tenant']->Id, $siang))->toBe(0);

        app(PengaturPesanWhatsapp::class)->Atur(JenisPesanWhatsapp::LaporanPenjualanPerJam, true, $toko['Tenant']->Id);
        expect($aksi->Jalankan($toko['Tenant']->Id, $siang))->toBe(1)
            ->and($aksi->Jalankan($toko['Tenant']->Id, $siang))->toBe(0);

        $pesan = PesanTerkirimUji();
        expect($pesan)->toHaveCount(2)->and($pesan[0]['message'])->toContain('7 transaksi')->toContain('Rp 1.250.000');
    });
});

describe('D-299 kabar absensi karyawan', function (): void {
    it('absen masuk dari kasir atau HP dikabarkan ke penerima, catatan manual dan keluar otomatis tidak', function (): void {
        $toko = TokoPesanOtomatis();
        PenerimaLaporanWhatsapp::query()->create(['IdTenant' => $toko['Tenant']->Id, 'IdOutlet' => $toko['Outlet']->Id, 'Nama' => 'Bu Rina', 'NomorTelepon' => '6281200000031']);
        $karyawan = Karyawan::query()->create(['Nama' => 'Dewi Lestari', 'IdOutlet' => $toko['Outlet']->Id]);
        $isian = ['IdKaryawan' => $karyawan->Id, 'IdOutlet' => $toko['Outlet']->Id, 'TanggalBisnis' => now()->toDateString(), 'MasukPada' => now()];

        Absensi::query()->create([...$isian, 'Sumber' => Absensi::SUMBER_POS]);
        expect(PesanTerkirimUji())->toBe([]);

        app(PengaturPesanWhatsapp::class)->Atur(JenisPesanWhatsapp::LaporanAbsensi, true, $toko['Tenant']->Id);
        $absen = Absensi::query()->create([...$isian, 'Sumber' => Absensi::SUMBER_WEB]);
        Absensi::query()->create([...$isian, 'MasukPada' => now()->subDay(), 'TanggalBisnis' => now()->subDay()->toDateString(), 'Sumber' => Absensi::SUMBER_MANUAL]);

        $pesan = PesanTerkirimUji();
        expect($pesan)->toHaveCount(1)->and($pesan[0]['message'])->toContain('Dewi Lestari')->toContain('absen masuk');

        $absen->update(['KeluarPada' => now()->addHours(8)]);
        $pesan = PesanTerkirimUji();
        expect($pesan)->toHaveCount(2)->and($pesan[1]['message'])->toContain('absen pulang');

        $lain = Absensi::query()->create([...$isian, 'MasukPada' => now()->subDays(2), 'TanggalBisnis' => now()->subDays(2)->toDateString(), 'Sumber' => Absensi::SUMBER_MANUAL]);
        $lain->update(['KeluarPada' => now()->subDays(2)->addHours(8), 'KeluarOtomatis' => true]);
        expect(PesanTerkirimUji())->toHaveCount(2);
    });
});
