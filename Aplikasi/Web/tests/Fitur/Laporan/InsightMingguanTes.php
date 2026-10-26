<?php

declare(strict_types=1);

use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Laporan\Kueri\InsightMingguan;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Organisasi\Model\Pengguna;
use App\Domain\Organisasi\Model\PeranIzin;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request as PermintaanHttp;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Penjualan\BantuanPenjualan;
use Tests\Pendukung\Tenant\BantuanFitur;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * X6 insight mingguan (v3.79): tiap Senin pemilik menerima ringkasan minggu lalu (Senin–Minggu) dibanding minggu
 * sebelumnya, produk terlaris/naik/turun, dan stok yang habis ≤ 7 hari. Owner bawaan berlangganan; anggota lain perlu
 * izin laporan penjualan dan memilih sendiri; paling banyak sekali per minggu. Dikirim lewat WhatsApp ke nomor HP akun
 * (D-33: notifikasi tenant tidak memakai email).
 */

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-01 05:00:00', 'UTC'));
    BantuanPendaftaran::SiapkanPrasyarat();
    Mail::fake();
    config(['integrasi.Whatsapp' => ['Penyedia' => 'Fonnte', 'Pengaturan' => [], 'Kredensial' => ['Token' => 'rahasia-uji']]]);
    Http::fake(['api.fonnte.com/send' => Http::response(['status' => true, 'id' => ['8101']])]);
});

/** Pesan WhatsApp insight yang dikirim ke penyedia (Fonnte), boleh disaring per nomor. */
function InsightTerkirim(?string $nomor = null): Collection
{
    return collect(Http::recorded())->map(fn (array $pasangan): PermintaanHttp => $pasangan[0])
        ->filter(fn (PermintaanHttp $r): bool => str_contains($r->url(), 'api.fonnte.com/send') && ($nomor === null || $r['target'] === $nomor))
        ->values();
}

function AturNoHpInsight(Pengguna $pengguna, string $noHp): void
{
    Pengguna::query()->whereKey($pengguna->Id)->update(['NoHp' => $noHp]);
}

it('Owner menerima insight minggu lalu vs minggu sebelumnya. Sekali per minggu; Kasir tanpa izin laporan tidak menerima', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-21 05:00:00', 'UTC'));
    $k = BantuanPenjualan::Siapkan($this, 'Toko Sembako Insight Wonogiri');
    BantuanFitur::Beri($k['Tenant']->Id, 'laporan.insight');
    $beras = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id, 'Beras Pandan Wangi Karung 5 kg', '40', '60000', '75000.00');
    $gula = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id, 'Gula Pasir Lokal Kemasan 1 kg', '100', '14000', '18000.00');
    $kasir = BantuanOrganisasi::TambahAnggota($k['Tenant']->Id, PeranTenantBawaan::Kasir);
    AturNoHpInsight($k['Pemilik'], '081277770001');
    AturNoHpInsight($kasir, '081277770002');
    $jual = function (string $waktu, mixed $produk, string $jumlah, string $harga) use ($k): void {
        $this->travelTo(CarbonImmutable::parse($waktu, 'UTC'));
        BantuanPenjualan::Jual($this, $k, ['Baris' => [['Produk' => $produk, 'Jumlah' => $jumlah, 'Harga' => $harga]]]);
    };
    // Minggu 21–27 Sep: beras 2 × 75.000 + gula 10 × 18.000 = 330.000.
    $jual('2026-09-22 05:00:00', $beras, '2', '75000.00');
    $jual('2026-09-23 05:00:00', $gula, '10', '18000.00');
    // Minggu 28 Sep–4 Okt: beras 36 × 75.000 = 2.700.000 (sisa 2 karung), gula 5 × 18.000 = 90.000 → 2.790.000.
    $jual('2026-09-29 05:00:00', $beras, '36', '75000.00');
    $jual('2026-10-01 05:00:00', $gula, '5', '18000.00');

    $this->travelTo(CarbonImmutable::parse('2026-10-05 00:15:00', 'UTC'));
    $jalankan = fn () => Artisan::call('laporan:kirim-insight-mingguan', ['--tenant' => [$k['Tenant']->Id]]);
    BantuanOrganisasi::Masuk($this, $kasir, $k['Tenant']->Id)->put('/kelola/laporan/penjualan/insight-whatsapp', ['Aktif' => true])->assertForbidden();

    expect($jalankan())->toBe(0);
    expect(InsightTerkirim())->toHaveCount(1);
    $pesan = (string) InsightTerkirim('6281277770001')[0]['message'];
    expect($pesan)->toContain('ringkasan penjualan Toko Sembako Insight Wonogiri')
        ->and($pesan)->toContain('Penjualan bersih: Rp 2.790.000 (naik 745,5% dari minggu sebelumnya, Rp 330.000)')
        ->and($pesan)->toContain('Transaksi: 2, rata-rata')
        ->and($pesan)->toContain("Produk terlaris:\n• Beras Pandan Wangi Karung 5 kg")
        ->and($pesan)->toContain("Naik paling banyak:\n• Beras Pandan Wangi Karung 5 kg: +Rp 2.550.000")
        ->and($pesan)->toContain("Turun paling banyak:\n• Gula Pasir Lokal Kemasan 1 kg: −Rp 90.000")
        ->and($pesan)->toContain("Stok yang segera habis:\n• Beras Pandan Wangi Karung 5 kg")
        ->and($pesan)->toContain('/kelola/laporan/penjualan?dari=2026-09-28&sampai=2026-10-04');
    Mail::assertNothingSent();

    // Jalan ulang minggu yang sama: tidak dikirim lagi.
    $jalankan();
    expect(InsightTerkirim())->toHaveCount(1);
});

it('anggota ber-izin laporan penjualan tanpa persediaan.lihat menerima insight tanpa saran restock (data stok)', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-21 05:00:00', 'UTC'));
    $k = BantuanPenjualan::Siapkan($this, 'Toko Sembako Insight Sragen');
    BantuanFitur::Beri($k['Tenant']->Id, 'laporan.insight');
    $beras = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id, 'Beras Pandan Wangi Karung 5 kg', '40', '60000', '75000.00');
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $peranKasir = BantuanOrganisasi::Peran($k['Tenant']->Id, PeranTenantBawaan::Kasir);
    PeranIzin::query()->create(['IdPeran' => $peranKasir->Id, 'KunciIzin' => 'laporan.penjualan.lihat']);
    $kasir = BantuanOrganisasi::TambahAnggota($k['Tenant']->Id, PeranTenantBawaan::Kasir);
    AturNoHpInsight($k['Pemilik'], '081277770011');
    AturNoHpInsight($kasir, '081277770012');
    $this->travelTo(CarbonImmutable::parse('2026-09-29 05:00:00', 'UTC'));
    BantuanPenjualan::Jual($this, $k, ['Baris' => [['Produk' => $beras, 'Jumlah' => '38', 'Harga' => '75000.00']]]);

    $this->travelTo(CarbonImmutable::parse('2026-10-05 00:15:00', 'UTC'));
    BantuanOrganisasi::Masuk($this, $kasir, $k['Tenant']->Id)->put('/kelola/laporan/penjualan/insight-whatsapp', ['Aktif' => true])->assertRedirect();
    Artisan::call('laporan:kirim-insight-mingguan', ['--tenant' => [$k['Tenant']->Id]]);

    expect(InsightTerkirim())->toHaveCount(2)
        ->and((string) InsightTerkirim('6281277770011')[0]['message'])->toContain('Stok yang segera habis:')
        ->and((string) InsightTerkirim('6281277770012')[0]['message'])->not->toContain('Stok yang segera habis:')
        ->and((string) InsightTerkirim('6281277770012')[0]['message'])->not->toContain('Saran restock');
});

it('sakelar di laporan penjualan: Owner bawaan aktif, berhenti = tidak dikirim, tanpa penjualan tidak dikirim', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-21 05:00:00', 'UTC'));
    $k = BantuanPenjualan::Siapkan($this, 'Toko Sembako Insight Wonogiri');
    BantuanFitur::Beri($k['Tenant']->Id, 'laporan.insight');
    AturNoHpInsight($k['Pemilik'], '081277770021');
    $pemilik = fn () => BantuanOrganisasi::Masuk($this, $k['Pemilik'], $k['Tenant']->Id);
    $pemilik()->get('/kelola/laporan/penjualan')->assertInertia(fn (AssertableInertia $h) => $h
        ->where('InsightWhatsapp', ['BisaWhatsapp' => true, 'Aktif' => true]));

    // Tanpa penjualan dua minggu terakhir: tidak ada pesan.
    $this->travelTo(CarbonImmutable::parse('2026-10-05 00:15:00', 'UTC'));
    Artisan::call('laporan:kirim-insight-mingguan', ['--tenant' => [$k['Tenant']->Id]]);
    expect(InsightTerkirim())->toHaveCount(0);

    $this->travelTo(CarbonImmutable::parse('2026-10-06 05:00:00', 'UTC'));
    $teh = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id, 'Teh Melati Celup Isi 25', '10', '3000', '10000.00');
    BantuanPenjualan::Jual($this, $k, ['Baris' => [['Produk' => $teh, 'Jumlah' => '1', 'Harga' => '10000.00']]]);
    $pemilik()->put('/kelola/laporan/penjualan/insight-whatsapp', ['Aktif' => false])
        ->assertSessionHas('Kilat', 'Insight mingguan lewat WhatsApp dimatikan.');
    $pemilik()->get('/kelola/laporan/penjualan')->assertInertia(fn (AssertableInertia $h) => $h->where('InsightWhatsapp.Aktif', false));

    $this->travelTo(CarbonImmutable::parse('2026-10-12 00:15:00', 'UTC'));
    Artisan::call('laporan:kirim-insight-mingguan', ['--tenant' => [$k['Tenant']->Id]]);
    expect(InsightTerkirim())->toHaveCount(0);
    Mail::assertNothingSent();

    // Akun tanpa nomor HP: sakelar tidak bisa dinyalakan.
    AturNoHpInsight($k['Pemilik'], '');
    $pemilik()->get('/kelola/laporan/penjualan')->assertInertia(fn (AssertableInertia $h) => $h
        ->where('InsightWhatsapp', ['BisaWhatsapp' => false, 'Aktif' => false]));
    $pemilik()->put('/kelola/laporan/penjualan/insight-whatsapp', ['Aktif' => true])
        ->assertSessionHasErrors(['Aktif' => 'Akun Anda belum punya nomor HP. Tambahkan nomor HP dulu untuk menerima insight.']);
});

it('persen perubahan & produk naik/turun dihitung tanpa float', function (): void {
    expect(InsightMingguan::HitungPersen(Uang::Dari('2790000'), Uang::Dari('330000')))->toBe('745.5')
        ->and(InsightMingguan::HitungPersen(Uang::Dari('50'), Uang::Dari('100')))->toBe('-50.0')
        ->and(InsightMingguan::HitungPersen(Uang::Dari('50'), Uang::Nol()))->toBeNull();

    $hasil = InsightMingguan::BandingkanProduk(
        [['IdProduk' => 1, 'NamaProduk' => 'Kopi', 'Bersih' => '300.00'], ['IdProduk' => 2, 'NamaProduk' => 'Teh', 'Bersih' => '50.00']],
        [['IdProduk' => 2, 'NamaProduk' => 'Teh', 'Bersih' => '80.00'], ['IdProduk' => 3, 'NamaProduk' => 'Susu', 'Bersih' => '20.00']],
    );
    expect($hasil['Naik'])->toBe([['NamaProduk' => 'Kopi', 'Selisih' => '300.00']])
        ->and($hasil['Turun'])->toBe([['NamaProduk' => 'Teh', 'Selisih' => '-30.00'], ['NamaProduk' => 'Susu', 'Selisih' => '-20.00']]);
});
