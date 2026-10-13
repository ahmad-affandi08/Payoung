<?php

declare(strict_types=1);

use App\Domain\Kasir\Model\Shift;
use App\Domain\Pemenuhan\Model\TiketDapur;
use App\Domain\Persediaan\Aksi\AjukanPenyesuaianStok;
use App\Domain\Persediaan\Enum\AlasanPenyesuaian;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Kasir\BantuanKasir;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Penjualan\BantuanPenjualan;
use Tests\Pendukung\Penjualan\BantuanPesananTerbuka;
use Tests\Pendukung\Persediaan\BantuanDokumenPersediaan;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Persediaan\PemeriksaInvarian;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * §10.10 tab operasional di laporan penjualan: waktu saji dapur (KDS) per stasiun, food cost teoretis vs aktual per
 * bahan, dan performa kasir. Ekspor CSV ikut tab.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

it('waktu saji dapur: rata-rata tunggu/masak/total, median, persentil 90, sajikan, dan tiket lewat target per stasiun; ekspor CSV', function (): void {
    $k = BantuanPesananTerbuka::SiapkanRestoran($this);
    $buka = BantuanPesananTerbuka::ItemBuka($k, $k['Meja']);
    $tambah = BantuanPesananTerbuka::ItemTambah($k, $buka['Uuid'], [[$k['Kopi'], '2', '25000.00'], [$k['Nasi'], '1', '35000.00']]);
    expect(BantuanPesananTerbuka::Kirim($this, $k, [$buka, $tambah]))->toBe([['Diterima', null], ['Diterima', null]]);

    // Bar: tunggu 2 menit, masak 8 menit (total 10), disajikan 2 menit kemudian. Dapur: tanpa tahap Dimasak, total 20 menit.
    $sekarang = CarbonImmutable::now();
    $ubah = fn (int $idStasiun, array $kolom) => DB::table('TiketDapur')->where('IdStasiunDapur', $idStasiun)->update($kolom);
    $ubah($k['Bar']->Id, [
        'DikirimPada' => $sekarang->subMinutes(30)->toDateTimeString(),
        'MulaiPada' => $sekarang->subMinutes(28)->toDateTimeString(),
        'SiapPada' => $sekarang->subMinutes(20)->toDateTimeString(),
        'DisajikanPada' => $sekarang->subMinutes(18)->toDateTimeString(),
    ]);
    $ubah($k['Dapur']->Id, [
        'DikirimPada' => $sekarang->subMinutes(40)->toDateTimeString(),
        'MulaiPada' => null,
        'SiapPada' => $sekarang->subMinutes(20)->toDateTimeString(),
        'DisajikanPada' => null,
    ]);
    expect(TiketDapur::query()->count())->toBe(2);
    BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id);

    $this->get('/kelola/laporan/penjualan?tab=waktu-saji')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
        ->where('Saring.Tab', 'waktu-saji')
        ->where('Saring.Target', 15)
        ->has('Isi', 2)
        ->where('Isi.0.NamaStasiun', 'Bar')
        ->where('Isi.0.JumlahTiket', 1)
        ->where('Isi.0.TungguRataRata', 120)
        ->where('Isi.0.MasakRataRata', 480)
        ->where('Isi.0.TotalRataRata', 600)
        ->where('Isi.0.TotalMedian', 600)
        ->where('Isi.0.TotalP90', 600)
        ->where('Isi.0.SajikanRataRata', 120)
        ->where('Isi.0.LewatTarget', 0)
        ->where('Isi.1.NamaStasiun', 'Dapur')
        ->where('Isi.1.TungguRataRata', null)
        ->where('Isi.1.MasakRataRata', 1200)
        ->where('Isi.1.TotalRataRata', 1200)
        ->where('Isi.1.SajikanRataRata', null)
        ->where('Isi.1.LewatTarget', 1)
        ->where('Isi.1.PersenLewatTarget', '100.00'));

    // Target 5 menit: Bar (10 menit) ikut terlambat; nilai di luar pilihan kembali ke 15.
    $this->get('/kelola/laporan/penjualan?tab=waktu-saji&target=5')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
        ->where('Saring.Target', 5)
        ->where('Isi.0.LewatTarget', 1));
    $this->get('/kelola/laporan/penjualan?tab=waktu-saji&target=7')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h->where('Saring.Target', 15));

    $csv = $this->get('/kelola/laporan/penjualan/ekspor?tab=waktu-saji')->assertOk()->streamedContent();
    expect($csv)->toContain('Stasiun')->toContain('Persentil 90')->toContain('Lewat target 15 menit')->toContain('Dapur');
});

it('waktu saji dapur: tiket yang belum siap dan tiket di luar periode tidak dihitung', function (): void {
    $k = BantuanPesananTerbuka::SiapkanRestoran($this);
    $buka = BantuanPesananTerbuka::ItemBuka($k, $k['Meja']);
    $tambah = BantuanPesananTerbuka::ItemTambah($k, $buka['Uuid'], [[$k['Kopi'], '1', '25000.00'], [$k['Nasi'], '1', '35000.00']]);
    BantuanPesananTerbuka::Kirim($this, $k, [$buka, $tambah]);
    $sekarang = CarbonImmutable::now();

    // Bar masih Antre (SiapPada kosong); Dapur siap tetapi 30 hari lalu.
    DB::table('TiketDapur')->where('IdStasiunDapur', $k['Dapur']->Id)->update([
        'DikirimPada' => $sekarang->subDays(30)->toDateTimeString(),
        'SiapPada' => $sekarang->subDays(30)->addMinutes(5)->toDateTimeString(),
    ]);
    BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id);

    $this->get('/kelola/laporan/penjualan?tab=waktu-saji')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h->has('Isi', 0));
});

it('food cost: teoretis dari penjualan, terbuang dari susut, selisih stok dari penyesuaian; persen terhadap penjualan bersih; ekspor CSV', function (): void {
    $k = BantuanPesananTerbuka::SiapkanRestoran($this);
    BantuanPenjualan::Jual($this, $k, ['Baris' => [['Produk' => $k['Kopi'], 'Jumlah' => '5', 'Harga' => '25000.00']]]);
    $terbuang = [
        'Jenis' => 'BahanTerbuang.Catat',
        'Uuid' => BantuanKasir::Uuid(),
        'Data' => [
            'UuidProduk' => $k['Kopi']->Uuid,
            'Jumlah' => '2',
            'Alasan' => 'Tumpah',
            'Catatan' => 'Jatuh saat diantar',
            'UuidPengguna' => $k['Supervisor']->Uuid,
            'DibuatPada' => CarbonImmutable::now()->subMinute()->utc()->toIso8601ZuluString(),
        ],
    ];
    expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$terbuang]))->toBe([['Diterima', null]]);
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    app(AjukanPenyesuaianStok::class)->Jalankan(BantuanDokumenPersediaan::DrafPenyesuaian($k['Gudang'], AlasanPenyesuaian::KonsumsiInternal, [BantuanDokumenPersediaan::Baris($k['Kopi'], '-1')], tanggal: CarbonImmutable::now('Asia/Jakarta')->toDateString()), $k['Pemilik']->Id);
    expect(PemeriksaInvarian::PeriksaSemua($k['Tenant']->Id))->toBe([]);
    BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id);

    $this->get('/kelola/laporan/penjualan?tab=food-cost')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
        ->where('Saring.Tab', 'food-cost')
        ->where('Isi.Ringkasan.Penjualan', '125000.00')
        ->where('Isi.Ringkasan.Teoretis', '40000.00')
        ->where('Isi.Ringkasan.Terbuang', '16000.00')
        ->where('Isi.Ringkasan.Selisih', '8000.00')
        ->where('Isi.Ringkasan.Aktual', '64000.00')
        ->where('Isi.Ringkasan.TeoretisPersen', '32.00')
        ->where('Isi.Ringkasan.AktualPersen', '51.20')
        ->where('Isi.Ringkasan.SelisihPoin', '19.20')
        ->has('Isi.Baris', 1)
        ->where('Isi.Baris.0.NamaProduk', $k['Kopi']->Nama)
        ->where('Isi.Baris.0.QtyTeoretis', '5.0000')
        ->where('Isi.Baris.0.QtyTerbuang', '2.0000')
        ->where('Isi.Baris.0.QtySelisih', '1.0000')
        ->where('Isi.Baris.0.NilaiAktual', '64000.00')
        ->where('Isi.Baris.0.VarianPersen', '60.00'));

    $csv = $this->get('/kelola/laporan/penjualan/ekspor?tab=food-cost')->assertOk()->streamedContent();
    expect($csv)->toContain('Nilai teoretis')->toContain('Varian vs teoretis')->toContain('64000.00');
});

it('performa kasir: transaksi, omzet, jam kerja shift tutup, transaksi & omzet per jam, persen void; ekspor CSV', function (): void {
    $k = BantuanPesananTerbuka::SiapkanRestoran($this);
    BantuanPenjualan::Jual($this, $k, ['Baris' => [['Produk' => $k['Kopi'], 'Jumlah' => '2', 'Harga' => '25000.00']]]);
    BantuanPenjualan::Jual($this, $k, ['Baris' => [['Produk' => $k['Kopi'], 'Jumlah' => '1', 'Harga' => '25000.00']]]);
    $batal = BantuanPenjualan::Jual($this, $k, ['Baris' => [['Produk' => $k['Kopi'], 'Jumlah' => '1', 'Harga' => '25000.00']]]);
    expect(BantuanKasir::KirimRingkas($this, $k['Token'], [BantuanPenjualan::ItemVoid($k, $batal)]))->toBe([['Diterima', null]]);
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);

    // Shift kasir ditutup setelah 2 jam kerja.
    $sekarang = CarbonImmutable::now();
    DB::table('Shift')->where('IdTenant', $k['Tenant']->Id)->update([
        'DibukaPada' => $sekarang->subHours(3)->toDateTimeString(),
        'DitutupPada' => $sekarang->subHour()->toDateTimeString(),
        'DitutupOleh' => $k['Supervisor']->Id,
    ]);
    expect(Shift::query()->count())->toBe(1);
    BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id);

    $this->get('/kelola/laporan/penjualan?tab=performa-kasir')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
        ->where('Saring.Tab', 'performa-kasir')
        ->has('Isi', 1)
        ->where('Isi.0.Kunci', $k['Kasir']->Uuid)
        ->where('Isi.0.JumlahTransaksi', 2)
        ->where('Isi.0.Bersih', '75000.00')
        ->where('Isi.0.RataRataKeranjang', '37500.00')
        ->where('Isi.0.JamKerja', '2.00')
        ->where('Isi.0.ShiftDitutup', 1)
        ->where('Isi.0.TransaksiPerJam', '1.00')
        ->where('Isi.0.OmzetPerJam', '37500.00')
        ->where('Isi.0.JumlahVoid', 1)
        ->where('Isi.0.PersenVoid', '33.33'));

    $csv = $this->get('/kelola/laporan/penjualan/ekspor?tab=performa-kasir')->assertOk()->streamedContent();
    expect($csv)->toContain('Transaksi per jam')->toContain('Omzet per jam')->toContain('Void %');
});

it('performa kasir tanpa shift tertutup: jam kerja nol dan angka per jam kosong, bukan pembagian nol', function (): void {
    $k = BantuanPesananTerbuka::SiapkanRestoran($this);
    BantuanPenjualan::Jual($this, $k, ['Baris' => [['Produk' => $k['Kopi'], 'Jumlah' => '1', 'Harga' => '25000.00']]]);
    BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id);

    $this->get('/kelola/laporan/penjualan?tab=performa-kasir')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
        ->where('Isi.0.JamKerja', '0.00')
        ->where('Isi.0.TransaksiPerJam', null)
        ->where('Isi.0.OmzetPerJam', null));
});
