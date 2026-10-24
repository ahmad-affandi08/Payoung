<?php

declare(strict_types=1);

use App\Domain\Bersama\Tindakan\Data\DataKonteksTindakan;
use App\Domain\Bersama\Tindakan\Enum\TingkatTindakan;
use App\Domain\Organisasi\Aksi\BuatNotifikasiOperasional;
use App\Domain\Organisasi\Enum\JenisNotifikasiPengguna;
use App\Domain\Organisasi\Model\NotifikasiPengguna;
use App\Domain\Pengelola\TimInternal\Enum\PeranPengelolaBawaan;
use App\Domain\Penjualan\Layanan\KuotaTransaksiTenant;
use App\Domain\Penjualan\Layanan\PenentuKonteksTokoOnline;
use App\Domain\Penjualan\Layanan\PenyediaTindakanKuotaTransaksi;
use App\Domain\Tenant\Enum\JenisOverride;
use App\Domain\Tenant\Model\OverrideTenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Kasir\BantuanKasir;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Pengelola\BantuanPengelola;
use Tests\Pendukung\Penjualan\BantuanPenjualan;
use Tests\Pendukung\Penjualan\BantuanTokoOnline;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * P-04 (BR-P04.3): kuota transaksi penjualan per bulan (`KuotaTransaksiBulanan`), diatur dari konsol seperti batas
 * lainnya. Penegakannya lunak: penjualan yang sudah terjadi di perangkat selalu diterima saat sinkron; kuota hanya
 * menahan transaksi baru (layar jual aplikasi kasir lewat payload `Kuota.Transaksi`, pesanan online) dan memunculkan
 * peringatan. Dihitung langsung dari `Penjualan` (bulan = TanggalBisnis), void tetap dihitung.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

/** @return array<string, mixed> */
function SiapkanTokoKuota(object $tes, ?int $kuota, string $nama = 'Toko Elektronik Maju Jaya Solo'): array
{
    $k = BantuanPenjualan::Siapkan($tes, $nama);
    $k['Tv'] = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id, 'Televisi LED 32 Inci Full HD', '50', '1200000', '1500000.00');
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    AturKuotaTransaksi($k, $kuota);

    return $k;
}

/** @param array<string, mixed> $k */
function AturKuotaTransaksi(array $k, ?int $kuota): void
{
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    OverrideTenant::query()->where('Kunci', 'KuotaTransaksiBulanan')->delete();

    if ($kuota !== null) {
        OverrideTenant::query()->create([
            'IdTenant' => $k['Tenant']->Id,
            'Jenis' => JenisOverride::Batas,
            'Kunci' => 'KuotaTransaksiBulanan',
            'Nilai' => $kuota,
            'BerakhirPada' => now()->addDays(60),
            'Alasan' => 'Uji kuota transaksi',
            'DibuatOleh' => BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::SuperAdmin)->Id,
        ]);
    }
}

/** @param array<string, mixed> $k */
function JualKuota(object $tes, array $k, int $kali = 1): void
{
    for ($i = 0; $i < $kali; $i++) {
        BantuanPenjualan::Jual($tes, $k, ['Baris' => [['Produk' => $k['Tv'], 'Jumlah' => '1', 'Harga' => '1500000.00']]]);
    }
}

/** @param array<string, mixed> $k */
function KuotaPos(object $tes, array $k): array
{
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);

    return $tes->withToken($k['Token'])->getJson('/api/pos/v1/konfigurasi-aplikasi')->assertOk()->json('Kuota.Transaksi');
}

it('paket tanpa kuota = tanpa batas: payload tetap membawa pemakaian bulan ini', function (): void {
    $k = SiapkanTokoKuota($this, null);
    JualKuota($this, $k, 2);

    $kuota = KuotaPos($this, $k);

    expect($kuota['Batas'])->toBeNull()
        ->and($kuota['Sisa'])->toBeNull()
        ->and($kuota['Persen'])->toBeNull()
        ->and($kuota['Terpakai'])->toBe(2)
        ->and($kuota['Status'])->toBe('TanpaBatas')
        ->and($kuota['Periode'])->toBe(CarbonImmutable::now('Asia/Jakarta')->format('Y-m'));
});

it('status mengikuti pemakaian: Normal, HampirHabis mulai 80%, Habis saat mencapai batas', function (): void {
    $k = SiapkanTokoKuota($this, 5);

    JualKuota($this, $k, 3);
    expect(KuotaPos($this, $k))->toMatchArray(['Terpakai' => 3, 'Batas' => 5, 'Sisa' => 2, 'Persen' => 60, 'Status' => 'Normal']);

    JualKuota($this, $k);
    expect(KuotaPos($this, $k))->toMatchArray(['Terpakai' => 4, 'Sisa' => 1, 'Persen' => 80, 'Status' => 'HampirHabis']);

    JualKuota($this, $k);
    expect(KuotaPos($this, $k))->toMatchArray(['Terpakai' => 5, 'Sisa' => 0, 'Persen' => 100, 'Status' => 'Habis']);
});

it('penjualan di luar kuota tetap diterima saat sinkron (uang sudah di laci); hanya transaksi baru yang ditahan aplikasi', function (): void {
    $k = SiapkanTokoKuota($this, 1);
    JualKuota($this, $k);
    expect(KuotaPos($this, $k)['Status'])->toBe('Habis');

    // BantuanPenjualan::Jual gagal dengan RuntimeException bila server menolak penjualan.
    JualKuota($this, $k, 2);

    expect(KuotaPos($this, $k))->toMatchArray(['Terpakai' => 3, 'Batas' => 1, 'Sisa' => 0, 'Persen' => 100, 'Status' => 'Habis']);
});

it('void tetap dihitung: membatalkan transaksi tidak mengembalikan kuota', function (): void {
    $k = SiapkanTokoKuota($this, 5);
    $penjualan = BantuanPenjualan::Jual($this, $k, ['Baris' => [['Produk' => $k['Tv'], 'Jumlah' => '1', 'Harga' => '1500000.00']]]);

    expect(BantuanKasir::KirimRingkas($this, $k['Token'], [BantuanPenjualan::ItemVoid($k, $penjualan)]))->toBe([['Diterima', null]])
        ->and(KuotaPos($this, $k)['Terpakai'])->toBe(1);
});

it('hanya penjualan bulan berjalan dihitung; bulan lalu tidak', function (): void {
    $k = SiapkanTokoKuota($this, 5);
    JualKuota($this, $k, 2);
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $bulanLalu = CarbonImmutable::now('Asia/Jakarta')->subMonthNoOverflow()->startOfMonth()->addDays(9)->toDateString();
    DB::table('Penjualan')->where('IdTenant', $k['Tenant']->Id)->orderBy('Id')->limit(1)->update(['TanggalBisnis' => $bulanLalu]);

    expect(KuotaPos($this, $k)['Terpakai'])->toBe(1);
});

it('isolasi tenant: penjualan tenant lain tidak mengurangi kuota', function (): void {
    $a = SiapkanTokoKuota($this, 5, 'Toko Elektronik Maju Jaya Solo');
    JualKuota($this, $a, 3);
    $b = SiapkanTokoKuota($this, 5, 'Warung Bakso Pak Kumis Sragen');

    expect(KuotaPos($this, $b)['Terpakai'])->toBe(0)
        ->and(KuotaPos($this, $a)['Terpakai'])->toBe(3);
});

it('batas 0 langsung habis dan tidak ada transaksi baru yang boleh dimulai', function (): void {
    $k = SiapkanTokoKuota($this, 0);
    $layanan = app(KuotaTransaksiTenant::class);

    expect($layanan->Ambil($k['Tenant']->Id)->CekHabis())->toBeTrue()
        ->and($layanan->CekBolehMulai($k['Tenant']->Id))->toBeFalse();
});

it('ambang status: tepat 80% hampir habis, 79% masih aman, sama atau melewati batas habis', function (): void {
    expect(KuotaTransaksiTenant::TentukanStatus(79, 100)->value)->toBe('Normal')
        ->and(KuotaTransaksiTenant::TentukanStatus(80, 100)->value)->toBe('HampirHabis')
        ->and(KuotaTransaksiTenant::TentukanStatus(100, 100)->value)->toBe('Habis')
        ->and(KuotaTransaksiTenant::TentukanStatus(250, 100)->value)->toBe('Habis')
        ->and(KuotaTransaksiTenant::TentukanStatus(9999, null)->value)->toBe('TanpaBatas');
});

it('Kotak Tindakan: hampir habis = Perhatian, habis = Penting, hanya bagi pemegang langganan.kelola, kosong bila tanpa batas', function (): void {
    $k = SiapkanTokoKuota($this, 5);
    $penyedia = app(PenyediaTindakanKuotaTransaksi::class);
    $konteks = fn (bool $pemilik, array $izin = []): DataKonteksTindakan => new DataKonteksTindakan(
        $k['Tenant']->Id, $k['Pemilik']->Id, $pemilik, $izin, null, CarbonImmutable::now('Asia/Jakarta'),
    );

    expect($penyedia->Kumpulkan($konteks(true)))->toBe([]);

    JualKuota($this, $k, 4);
    $butir = $penyedia->Kumpulkan($konteks(true));
    expect($butir)->toHaveCount(1)
        ->and($butir[0]->kunci)->toBe('kuota.transaksi')
        ->and($butir[0]->tingkat)->toBe(TingkatTindakan::Perhatian)
        ->and($butir[0]->keterangan)->toContain('4 dari 5 transaksi');

    JualKuota($this, $k);
    expect($penyedia->Kumpulkan($konteks(true))[0]->tingkat)->toBe(TingkatTindakan::Penting)
        ->and($penyedia->Kumpulkan($konteks(false, ['produk.lihat'])))->toBe([])
        ->and($penyedia->Kumpulkan($konteks(false, ['langganan.kelola'])))->toHaveCount(1);

    AturKuotaTransaksi($k, null);
    expect($penyedia->Kumpulkan($konteks(true)))->toBe([]);
});

it('ringkasan harian membuat notifikasi KuotaTransaksi bagi pemilik saat hampir atau sudah habis', function (): void {
    $k = SiapkanTokoKuota($this, 5);
    JualKuota($this, $k, 4);

    app(BuatNotifikasiOperasional::class)->Jalankan($k['Tenant']->Id, CarbonImmutable::now('Asia/Jakarta'));

    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $kabar = NotifikasiPengguna::query()->where('Jenis', JenisNotifikasiPengguna::KuotaTransaksi->value)->get();
    expect($kabar->pluck('IdPengguna')->all())->toContain($k['Pemilik']->Id)
        ->and($kabar[0]->Judul)->toContain('hampir habis')
        ->and($kabar[0]->Data['Href'])->toBe('/kelola/langganan');
});

it('halaman Langganan membawa kuota transaksi untuk panel pemakaian', function (): void {
    $k = SiapkanTokoKuota($this, 10);
    JualKuota($this, $k, 8);
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);

    BantuanKatalog::MasukSebagai($this, $k['Tenant']->Id)->get('/kelola/langganan')->assertOk()
        ->assertInertia(fn (AssertableInertia $halaman) => $halaman
            ->component('Kelola/Langganan/Indeks')
            ->where('KuotaTransaksi.Batas', 10)
            ->where('KuotaTransaksi.Terpakai', 8)
            ->where('KuotaTransaksi.Status', 'HampirHabis'));
});

it('toko online berhenti menerima pesanan baru saat kuota habis, dan pulih begitu kuota bertambah', function (): void {
    $k = BantuanTokoOnline::Siapkan($this);
    $kiriman = BantuanTokoOnline::Kiriman($k, 'AmbilSendiri');
    AturKuotaTransaksi($k, 0);

    expect(app(PenentuKonteksTokoOnline::class)->Cari()?->aktif)->toBeFalse();
    $this->postJson('/'.$k['Slug'].'/pesan', $kiriman)->assertStatus(409);

    AturKuotaTransaksi($k, 100);
    expect(app(PenentuKonteksTokoOnline::class)->Cari()?->aktif)->toBeTrue();
    $this->postJson('/'.$k['Slug'].'/pesan', $kiriman)->assertCreated();
});
