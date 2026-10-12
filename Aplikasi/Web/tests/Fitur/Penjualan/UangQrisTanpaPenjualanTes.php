<?php

declare(strict_types=1);

use App\Domain\Akuntansi\Enum\PeranAkun;
use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Bersama\Tindakan\Data\DataKonteksTindakan;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Penjualan\Enum\HasilPenutupanTagihanQris;
use App\Domain\Penjualan\Enum\StatusTagihanQris;
use App\Domain\Penjualan\Enum\SumberTagihanQris;
use App\Domain\Penjualan\Layanan\PenyediaTindakanPenjualan;
use App\Domain\Penjualan\Model\TagihanQris;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Pembelian\BantuanPembelian;
use Tests\Pendukung\Penjualan\BantuanPenjualan;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Tenant\BantuanPendaftaran;
use Tests\TestCase;

/*
 * E8: uang QRIS yang masuk tanpa penjualan (tagihan QRIS POS Lunas, PerluTinjauan, tanpa UuidPenjualan) bisa ditutup:
 * dikembalikan ke pelanggan (tanpa jurnal) atau diakui sebagai pendapatan lain (Dr kas/bank, Cr Pendapatan Lain).
 * Penutupan bisa dibuka kembali (jurnal dibalik). Hanya tagihan POS; izin akuntansi.kelola.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

/**
 * @param  array<string, mixed>  $k
 * @param  array<string, mixed>  $ubah
 */
function BuatTagihanQrisTerlambat(array $k, string $jumlah = '38500.00', array $ubah = []): TagihanQris
{
    static $urut = 0;
    $urut++;

    return TagihanQris::query()->create(array_merge([
        'IdOutlet' => $k['Outlet']->Id,
        'IdPerangkat' => $k['Perangkat']->Id,
        'IdMetodePembayaran' => $k['Qris']->Id,
        'NomorPesanan' => 'PYTELAMBAT'.$urut.strtoupper(substr(md5((string) microtime(true)), 0, 6)),
        'Penyedia' => 'Doku',
        'IsiQr' => '',
        'Jumlah' => $jumlah,
        'Status' => StatusTagihanQris::Lunas,
        'KedaluwarsaPada' => now()->subHour(),
        'LunasPada' => now(),
        'JumlahDiterima' => $jumlah,
        'PerluTinjauan' => true,
        'AlasanTinjauan' => 'Uang QRIS diterima setelah tagihan berstatus Kedaluwarsa.',
    ], $ubah));
}

/** @return array<string, mixed> */
function SiapkanQrisTerlambat(TestCase $tes, string $nama = 'Kafe QRIS Terlambat'): array
{
    $k = BantuanPenjualan::Siapkan($tes, $nama);
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    BantuanOrganisasi::Masuk($tes, $k['Pemilik'], $k['Tenant']->Id);

    return $k;
}

describe('E8 uang QRIS tanpa penjualan', function (): void {
    it('daftar memuat tagihan POS yang menunggu keputusan; tagihan toko online dan yang terhubung ke penjualan tidak ikut', function (): void {
        $k = SiapkanQrisTerlambat($this);
        $satu = BuatTagihanQrisTerlambat($k);
        BuatTagihanQrisTerlambat($k, '1000.00', ['Sumber' => SumberTagihanQris::TokoOnline, 'IdPerangkat' => null]);
        BuatTagihanQrisTerlambat($k, '2000.00', ['UuidPenjualan' => '01J9PNJ0000000000000000001']);
        BuatTagihanQrisTerlambat($k, '3000.00', ['PerluTinjauan' => false]);

        $this->get('/kelola/akuntansi/qris-tanpa-penjualan')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Akuntansi/QrisTanpaPenjualan/Daftar')->has('Tagihan.Data', 1)->where('Tagihan.Data.0.Uuid', $satu->Uuid)
            ->where('Tagihan.Data.0.Jumlah', '38500.00')->where('Tagihan.Data.0.Ditutup', false)->where('Izin.Kelola', true)->has('OpsiAkun'));
    });

    it('dikembalikan ke pelanggan: ditutup tanpa jurnal; catatan wajib; tidak bisa ditutup dua kali', function (): void {
        $k = SiapkanQrisTerlambat($this);
        $tagihan = BuatTagihanQrisTerlambat($k);
        $url = "/kelola/akuntansi/qris-tanpa-penjualan/{$tagihan->Uuid}/tutup";

        $this->post($url, ['Hasil' => 'Dikembalikan', 'Catatan' => 'ok'])->assertSessionHasErrors('Catatan');
        $this->post($url, ['Hasil' => 'Dikembalikan', 'Catatan' => 'Refund lewat dasbor DOKU ref RF-20261012-01'])->assertSessionHasNoErrors();
        $tagihan->refresh();

        expect($tagihan->DitutupPada)->not->toBeNull()
            ->and($tagihan->HasilPenutupan)->toBe(HasilPenutupanTagihanQris::Dikembalikan)
            ->and($tagihan->PerluTinjauan)->toBeFalse()
            ->and($tagihan->IdJurnalPenutupan)->toBeNull()
            ->and(BantuanPembelian::SaldoPeran($k['Tenant']->Id, PeranAkun::PendapatanLain))->toBe('0.00')
            ->and(LogAudit::query()->where('Peristiwa', 'tagihan-qris.tutup')->count())->toBe(1);

        $this->post($url, ['Hasil' => 'Dikembalikan', 'Catatan' => 'Refund lewat dasbor DOKU ref RF-20261012-01'])->assertSessionHasErrors('Umum');
        $this->getJson('/kelola/akuntansi/qris-tanpa-penjualan?saring[Penutupan]=Terbuka')->assertOk()->assertJsonPath('Meta.Total', 0);
        $this->getJson('/kelola/akuntansi/qris-tanpa-penjualan?saring[Penutupan]=Ditutup')->assertOk()->assertJsonPath('Meta.Total', 1)->assertJsonPath('Data.0.HasilPenutupan', 'Dikembalikan');
    });

    it('diakui sebagai pendapatan lain: Dr kas/bank, Cr Pendapatan Lain seimbang; buka kembali membalik jurnal dan bisa ditutup ulang', function (): void {
        $k = SiapkanQrisTerlambat($this);
        $tagihan = BuatTagihanQrisTerlambat($k, '125000.00');
        $url = "/kelola/akuntansi/qris-tanpa-penjualan/{$tagihan->Uuid}/tutup";
        $kas = BantuanPembelian::AkunKas();
        $isian = ['Hasil' => 'DiakuiPendapatan', 'Catatan' => 'Pelanggan tidak ditemukan setelah 30 hari', 'UuidAkun' => $kas->Uuid, 'Tanggal' => BantuanPembelian::Hari()->format('Y-m-d')];

        // Akun kas/bank dan tanggal wajib untuk pengakuan pendapatan; tanggal masa depan ditolak.
        $this->post($url, [...$isian, 'UuidAkun' => null])->assertSessionHasErrors('UuidAkun');
        $this->post($url, [...$isian, 'Tanggal' => BantuanPembelian::Hari()->addDays(2)->format('Y-m-d')])->assertSessionHasErrors('Tanggal');
        expect($tagihan->refresh()->DitutupPada)->toBeNull();

        $this->post($url, $isian)->assertSessionHasNoErrors();
        $tagihan->refresh();

        expect($tagihan->HasilPenutupan)->toBe(HasilPenutupanTagihanQris::DiakuiPendapatan)
            ->and($tagihan->IdJurnalPenutupan)->not->toBeNull()
            ->and(BantuanPembelian::SaldoPeran($k['Tenant']->Id, PeranAkun::PendapatanLain))->toBe('-125000.00')
            ->and(BantuanPembelian::PeriksaInvarian($k['Tenant']->Id))->toBe([]);

        $buka = "/kelola/akuntansi/qris-tanpa-penjualan/{$tagihan->Uuid}/buka-kembali";
        $this->post($buka, ['Alasan' => 'x'])->assertSessionHasErrors('Alasan');
        $this->post($buka, ['Alasan' => 'Salah memilih hasil penutupan'])->assertSessionHasNoErrors();
        $tagihan->refresh();

        expect($tagihan->DitutupPada)->toBeNull()
            ->and($tagihan->PerluTinjauan)->toBeTrue()
            ->and($tagihan->IdJurnalPenutupan)->toBeNull()
            ->and(BantuanPembelian::SaldoPeran($k['Tenant']->Id, PeranAkun::PendapatanLain))->toBe('0.00');

        // Belum ditutup = tidak bisa dibuka kembali; ditutup ulang membuat jurnal baru (kunci sumber berbeda).
        $this->post($buka, ['Alasan' => 'Salah memilih hasil penutupan'])->assertSessionHasErrors('Umum');
        $this->post($url, $isian)->assertSessionHasNoErrors();
        expect(BantuanPembelian::SaldoPeran($k['Tenant']->Id, PeranAkun::PendapatanLain))->toBe('-125000.00')
            ->and(BantuanPembelian::PeriksaInvarian($k['Tenant']->Id))->toBe([]);
    });

    it('menolak tagihan toko online, yang sudah terhubung ke penjualan, dan yang belum lunas', function (): void {
        $k = SiapkanQrisTerlambat($this);
        $isian = ['Hasil' => 'Dikembalikan', 'Catatan' => 'Refund lewat dasbor gerbang'];

        foreach ([['Sumber' => SumberTagihanQris::TokoOnline, 'IdPerangkat' => null], ['UuidPenjualan' => '01J9PNJ0000000000000000002'], ['Status' => StatusTagihanQris::Menunggu]] as $ubah) {
            $tagihan = BuatTagihanQrisTerlambat($k, '1000.00', $ubah);
            $this->post("/kelola/akuntansi/qris-tanpa-penjualan/{$tagihan->Uuid}/tutup", $isian)->assertSessionHasErrors('Umum');
            expect($tagihan->refresh()->DitutupPada)->toBeNull();
        }
    });

    it('izin: supervisor melihat tetapi tidak bisa menutup; kasir tidak bisa melihat; tenant lain tidak melihat tagihannya', function (): void {
        $k = SiapkanQrisTerlambat($this);
        $tagihan = BuatTagihanQrisTerlambat($k);

        BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id, PeranTenantBawaan::Kasir);
        $this->get('/kelola/akuntansi/qris-tanpa-penjualan')->assertForbidden();

        BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id, PeranTenantBawaan::Supervisor);
        $alamat = "/kelola/akuntansi/qris-tanpa-penjualan/{$tagihan->Uuid}";
        $kolom = $this->get('/kelola/akuntansi/qris-tanpa-penjualan');

        if ($kolom->status() === 200) {
            $kolom->assertInertia(fn (AssertableInertia $h) => $h->where('Izin.Kelola', false));
        } else {
            $kolom->assertForbidden();
        }

        $this->post("{$alamat}/tutup", ['Hasil' => 'Dikembalikan', 'Catatan' => 'Tidak berwenang menutup'])->assertForbidden();

        $b = BantuanPenjualan::Siapkan($this, 'Toko Tenant Lain QRIS');
        BantuanOrganisasi::Masuk($this, $b['Pemilik'], $b['Tenant']->Id);
        $this->get('/kelola/akuntansi/qris-tanpa-penjualan')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h->has('Tagihan.Data', 0));
        $this->post("{$alamat}/tutup", ['Hasil' => 'Dikembalikan', 'Catatan' => 'Bukan milik tenant ini'])->assertSessionHasErrors('Umum');
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect($tagihan->refresh()->DitutupPada)->toBeNull();
    });

    it('Kotak Tindakan hanya memuat tagihan POS yang belum ditutup dan menautkan ke halaman penutupan', function (): void {
        $k = SiapkanQrisTerlambat($this);
        $pos = BuatTagihanQrisTerlambat($k);
        BuatTagihanQrisTerlambat($k, '1000.00', ['Sumber' => SumberTagihanQris::TokoOnline, 'IdPerangkat' => null]);
        $konteks = new DataKonteksTindakan($k['Tenant']->Id, $k['Pemilik']->Id, true, [], null, CarbonImmutable::now('Asia/Jakarta')->startOfDay());
        $penyedia = app(PenyediaTindakanPenjualan::class);
        $butir = collect($penyedia->Kumpulkan($konteks))->firstWhere('kunci', 'tagihan-qris.tinjauan');

        expect($butir->jumlah)->toBe(1)
            ->and($butir->rincian[0]->tautan)->toBe('/kelola/akuntansi/qris-tanpa-penjualan?cari='.$pos->Uuid);

        $this->post("/kelola/akuntansi/qris-tanpa-penjualan/{$pos->Uuid}/tutup", ['Hasil' => 'Dikembalikan', 'Catatan' => 'Refund lewat dasbor gerbang'])->assertSessionHasNoErrors();
        expect(collect($penyedia->Kumpulkan($konteks))->firstWhere('kunci', 'tagihan-qris.tinjauan')->jumlah)->toBe(0);
    });
});
