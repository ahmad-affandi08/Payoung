<?php

declare(strict_types=1);

use App\Domain\Kasir\Model\BukaLaci;
use App\Domain\Kasir\Model\Shift;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Penjualan\Model\Penjualan;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Kasir\BantuanKasir;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Penjualan\BantuanPenjualan;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Tenant\BantuanFitur;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * F-14 anti-fraud (OWN-09, BR-09.3): tab "Anti-fraud" laporan penjualan per kasir: void (termasuk void tunai ≤ 10 menit
 * setelah bayar), retur, diskon, buka laci tanpa transaksi, kas kurang saat tutup shift, dan skor risiko berteks
 * dibanding rata-rata kasir lain. Ekspor CSV ikut saring; tanpa izin laporan = 403.
 */

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-01 05:00:00', 'UTC'));
    BantuanPendaftaran::SiapkanPrasyarat();
    $this->travelTo(CarbonImmutable::parse('2026-10-07 05:00:00', 'UTC'));
});

it('kasir dengan void tunai cepat, void tinggi, buka laci manual, dan kas kurang berisiko Tinggi; kasir lain Rendah', function (): void {
    $k = BantuanPenjualan::Siapkan($this, 'Minimarket Jujur Makmur Sragen');
    BantuanFitur::Beri($k['Tenant']->Id, 'keamanan.anti-fraud');
    $minyak = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id);
    $baris = ['Baris' => [['Produk' => $minyak, 'Jumlah' => '1', 'Harga' => '38500.00']]];

    // Kasir: 4 transaksi, 3 di-void tunai 4 menit setelah bayar.
    $jual = [];
    for ($i = 0; $i < 4; $i++) {
        $jual[] = BantuanPenjualan::Jual($this, $k, $baris);
    }
    foreach (array_slice($jual, 0, 3) as $p) {
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [BantuanPenjualan::ItemVoid($k, $p)]))->toBe([['Diterima', null]]);
    }

    // Supervisor: 10 transaksi bersih, tanpa void.
    for ($i = 0; $i < 10; $i++) {
        $item = BantuanPenjualan::Item($k, $baris, ['UuidPengguna' => $k['Supervisor']->Uuid]);
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$item]))->toBe([['Diterima', null]]);
    }

    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $shift = Shift::query()->firstOrFail();
    for ($i = 0; $i < 3; $i++) {
        BukaLaci::query()->create([
            'Uuid' => (string) Str::ulid(), 'IdShift' => $shift->Id, 'IdPerangkat' => $shift->IdPerangkat, 'Alasan' => 'Tukar uang kecil',
            'DibukaOleh' => $k['Kasir']->Id, 'DibukaPada' => now(), 'DiterimaPada' => now(), 'PerluTinjauan' => false,
        ]);
    }
    Shift::query()->whereKey($shift->Id)->update(['DitutupOleh' => $k['Kasir']->Id, 'DitutupPada' => now(), 'Selisih' => '-150000.00', 'KasSeharusnya' => '650000.00', 'KasAktual' => '500000.00']);
    expect(Penjualan::query()->count())->toBe(14);

    BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id);
    $url = '/kelola/laporan/penjualan?dari=2026-10-01&sampai=2026-10-07&tab=anti-fraud';
    $this->get($url)->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
        ->component('Kelola/Laporan/Penjualan')
        ->where('Saring.Tab', 'anti-fraud')
        ->where('Isi.0.NamaKasir', $k['Kasir']->Nama)
        ->where('Isi.0.JumlahTransaksi', 4)
        ->where('Isi.0.JumlahVoid', 3)
        ->where('Isi.0.NilaiVoid', '115500.00')
        ->where('Isi.0.VoidCepatTunai', 3)
        ->where('Isi.0.BukaLaciManual', 3)
        ->where('Isi.0.ShiftSelisihKurang', 1)
        ->where('Isi.0.SelisihKurang', '150000.00')
        ->where('Isi.0.Tingkat', 'Tinggi')
        ->where('Isi.0.Skor', 80)
        ->where('Isi.0.Alasan', fn ($alasan): bool => collect($alasan)->contains('3 void tunai ≤ 10 menit setelah bayar')
            && collect($alasan)->contains(fn (string $a): bool => str_starts_with($a, 'Void 75,0% dari transaksi'))
            && collect($alasan)->contains('Buka laci tanpa transaksi 3 kali')
            && collect($alasan)->contains('Kas kurang Rp 150.000 di 1 shift (melebihi toleransi)'))
        ->where('Isi.1.NamaKasir', $k['Supervisor']->Nama)
        ->where('Isi.1.JumlahTransaksi', 10)
        ->where('Isi.1.Tingkat', 'Rendah')
        ->where('Isi.1.Skor', 0));

    $csv = $this->get('/kelola/laporan/penjualan/ekspor?dari=2026-10-01&sampai=2026-10-07&tab=anti-fraud');
    $csv->assertOk();
    expect($csv->streamedContent())->toContain('Skor risiko')->toContain('Tinggi')->toContain('Buka laci tanpa transaksi 3 kali');

    $kasir = BantuanOrganisasi::TambahAnggota($k['Tenant']->Id, PeranTenantBawaan::Kasir);
    BantuanOrganisasi::Masuk($this, $kasir, $k['Tenant']->Id)->get($url)->assertForbidden();
});

it('audit F-15/F-16: kas kurang dibebankan ke kasir pemilik shift walau ditutup supervisor; void berjam mundur tidak dihitung void cepat', function (): void {
    $k = BantuanPenjualan::Siapkan($this, 'Minimarket Adil Sukoharjo');
    BantuanFitur::Beri($k['Tenant']->Id, 'keamanan.anti-fraud');
    $minyak = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id);
    $baris = ['Baris' => [['Produk' => $minyak, 'Jumlah' => '1', 'Harga' => '38500.00']]];

    // Jam perangkat mundur: waktu void 5 menit SEBELUM penjualan (masih dalam toleransi server).
    $p = BantuanPenjualan::Jual($this, $k, $baris);
    $mundur = CarbonImmutable::instance($p->DibuatOfflinePada)->subMinutes(5);
    expect(BantuanKasir::KirimRingkas($this, $k['Token'], [BantuanPenjualan::ItemVoid($k, $p, ['DivoidPada' => $mundur])]))->toBe([['Diterima', null]]);

    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $shift = Shift::query()->firstOrFail();
    expect($shift->DibukaOleh)->toBe($k['Kasir']->Id);
    Shift::query()->whereKey($shift->Id)->update(['DitutupOleh' => $k['Supervisor']->Id, 'DitutupPada' => now(), 'Selisih' => '-50000.00', 'KasSeharusnya' => '538500.00', 'KasAktual' => '488500.00']);

    BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id);
    $this->get('/kelola/laporan/penjualan?dari=2026-10-01&sampai=2026-10-07&tab=anti-fraud')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
        ->where('Isi', fn ($isi): bool => collect($isi)->contains(fn ($b): bool => $b['NamaKasir'] === $k['Kasir']->Nama
            && $b['ShiftSelisihKurang'] === 1 && $b['SelisihKurang'] === '50000.00' && $b['JumlahVoid'] === 1 && $b['VoidCepatTunai'] === 0)
            && ! collect($isi)->contains(fn ($b): bool => $b['NamaKasir'] === $k['Supervisor']->Nama && $b['ShiftSelisihKurang'] > 0)));
});
