<?php

declare(strict_types=1);

use App\Domain\Akuntansi\Enum\PeranAkun;
use App\Domain\Akuntansi\Model\JurnalDetail;
use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Bersama\Dokumen\Enum\StatusDokumenTerposting;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Pelanggan\Enum\StatusPiutang;
use App\Domain\Pelanggan\Layanan\PencatatPiutangPenjualan;
use App\Domain\Pelanggan\Model\HapusBukuPiutang;
use App\Domain\Pelanggan\Model\Pelanggan;
use App\Domain\Pelanggan\Model\Piutang;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Kasir\BantuanKasir;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Pembelian\BantuanPembelian;
use Tests\Pendukung\Penjualan\BantuanPenjualan;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Tenant\BantuanPendaftaran;
use Tests\TestCase;

/*
 * E8 hapus-buku piutang: sisa piutang yang lewat jatuh tempo dan tidak akan tertagih dibukukan sebagai beban
 * (Dr Beban Piutang Tak Tertagih, Cr Piutang Usaha); piutangnya berstatus DihapusBuku dan keluar dari daftar tagihan;
 * pembatalan membalik jurnal dan mengembalikan tagihan (aturan #8: dokumen asal tidak diedit).
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

/**
 * Tenant + dua penjualan tempo Rp 77.000 ke Toko Makmur Jaya; piutang pertama sudah lewat jatuh tempo 40 hari.
 *
 * @return array<string, mixed>
 */
function SiapkanPiutangHapusBuku(TestCase $tes, string $nama = 'Grosir Sembako Hapus Buku'): array
{
    $k = BantuanPenjualan::Siapkan($tes, $nama);
    $produk = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id);
    $toko = Pelanggan::query()->create(['Nama' => 'Toko Makmur Jaya', 'NoHp' => '6281355550001', 'LimitKredit' => '5000000', 'TerminHari' => 30]);
    $item = fn (): array => BantuanPenjualan::Item(
        $k,
        ['Baris' => [['Produk' => $produk, 'Jumlah' => '2', 'Harga' => '38500.00']], 'Pembayaran' => [['Metode' => $k['Tempo'], 'Jumlah' => '77000.00']]],
        ['UuidPelanggan' => $toko->Uuid],
    );

    expect(BantuanKasir::KirimRingkas($tes, $k['Token'], [$item(), $item()]))->toBe([['Diterima', null], ['Diterima', null]]);
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    BantuanOrganisasi::Masuk($tes, $k['Pemilik'], $k['Tenant']->Id);
    $piutang = Piutang::query()->orderBy('Id')->get();
    $piutang[0]->forceFill(['JatuhTempo' => BantuanPembelian::Hari()->subDays(40)->format('Y-m-d')])->save();

    return $k + ['Toko' => $toko, 'P1' => $piutang[0], 'P2' => $piutang[1]];
}

/**
 * @return array{Debit: string, Kredit: string}
 */
function TotalJurnalHapusBuku(int $idJurnal): array
{
    $debit = Uang::Nol();
    $kredit = Uang::Nol();

    foreach (JurnalDetail::query()->where('IdJurnal', $idJurnal)->get() as $b) {
        $debit = $debit->Tambah(Uang::Dari((string) $b->Debit));
        $kredit = $kredit->Tambah(Uang::Dari((string) $b->Kredit));
    }

    return ['Debit' => $debit->KeString(), 'Kredit' => $kredit->KeString()];
}

/** @return array<string, string> */
function IsianHapusBuku(?string $tanggal = null, string $alasan = 'Pelanggan tutup usaha dan tidak bisa dihubungi'): array
{
    return ['Tanggal' => $tanggal ?? BantuanPembelian::Hari()->format('Y-m-d'), 'Alasan' => $alasan];
}

describe('E8 hapus-buku piutang', function (): void {
    it('membukukan seluruh sisa sebagai beban: Dr Beban Piutang Tak Tertagih, Cr Piutang Usaha seimbang; piutang keluar dari daftar tagihan', function (): void {
        $k = SiapkanPiutangHapusBuku($this);
        $idTenant = $k['Tenant']->Id;

        $this->post("/kelola/piutang/{$k['P1']->Uuid}/hapus-buku", IsianHapusBuku())->assertSessionHasNoErrors()->assertRedirect();
        $dokumen = HapusBukuPiutang::query()->sole();
        $piutang = $k['P1']->refresh();

        expect($dokumen->Nomor)->toStartWith('HB/')
            ->and((string) $dokumen->Jumlah)->toBe('77000.00')
            ->and($dokumen->Status)->toBe(StatusDokumenTerposting::Diposting)
            ->and($dokumen->IdPiutangAktif)->toBe($piutang->Id)
            ->and($piutang->Status)->toBe(StatusPiutang::DihapusBuku)
            ->and($piutang->AmbilSisa()->KeString())->toBe('0.00')
            ->and(TotalJurnalHapusBuku((int) $dokumen->IdJurnal))->toBe(['Debit' => '77000.00', 'Kredit' => '77000.00'])
            ->and(BantuanPembelian::SaldoPeran($idTenant, PeranAkun::BebanPiutangTakTertagih))->toBe('77000.00')
            ->and(BantuanPembelian::SaldoPeran($idTenant, PeranAkun::PiutangUsaha))->toBe('77000.00')
            ->and(LogAudit::query()->where('Peristiwa', 'piutang.hapus-buku')->count())->toBe(1);

        $this->getJson('/kelola/piutang?cari=')->assertOk()->assertJsonPath('Meta.Total', 1)->assertJsonPath('Ringkasan.Total', '77000.00');
        $this->get('/kelola/piutang/hapus-buku')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Piutang/HapusBuku/Daftar')->has('HapusBuku.Data', 1)->where('HapusBuku.Data.0.Nomor', $dokumen->Nomor)
            ->where('HapusBuku.Data.0.Jumlah', '77000.00')->where('Izin.Kelola', true));
        $this->getJson("/kelola/piutang/hapus-buku?cari={$dokumen->Uuid}")->assertOk()->assertJsonPath('Meta.Total', 1);
        expect(BantuanPembelian::PeriksaInvarian($idTenant))->toBe([]);
    });

    it('menolak piutang yang belum jatuh tempo, sudah lunas, sudah dihapus-buku, tanggal masa depan, dan alasan pendek', function (): void {
        $k = SiapkanPiutangHapusBuku($this);

        $this->post("/kelola/piutang/{$k['P2']->Uuid}/hapus-buku", IsianHapusBuku())->assertSessionHasErrors('Umum');
        $this->post("/kelola/piutang/{$k['P1']->Uuid}/hapus-buku", IsianHapusBuku(BantuanPembelian::Hari()->addDays(2)->format('Y-m-d')))->assertSessionHasErrors('Tanggal');
        $this->post("/kelola/piutang/{$k['P1']->Uuid}/hapus-buku", IsianHapusBuku(null, 'ok'))->assertSessionHasErrors('Alasan');
        expect(HapusBukuPiutang::query()->count())->toBe(0);

        $this->post("/kelola/piutang/{$k['P1']->Uuid}/hapus-buku", IsianHapusBuku())->assertSessionHasNoErrors();
        $this->post("/kelola/piutang/{$k['P1']->Uuid}/hapus-buku", IsianHapusBuku())->assertSessionHasErrors('Umum');

        $k['P2']->forceFill(['JatuhTempo' => BantuanPembelian::Hari()->subDays(5)->format('Y-m-d'), 'JumlahDibayar' => '77000.00', 'Status' => StatusPiutang::Lunas])->save();
        $this->post("/kelola/piutang/{$k['P2']->Uuid}/hapus-buku", IsianHapusBuku())->assertSessionHasErrors('Umum');
        expect(HapusBukuPiutang::query()->count())->toBe(1);
    });

    it('pembatalan membalik jurnal dan mengembalikan tagihan; idempoten; piutang bisa dilunasi lalu', function (): void {
        $k = SiapkanPiutangHapusBuku($this);
        $idTenant = $k['Tenant']->Id;
        $this->post("/kelola/piutang/{$k['P1']->Uuid}/hapus-buku", IsianHapusBuku())->assertSessionHasNoErrors();
        $dokumen = HapusBukuPiutang::query()->sole();

        $this->post("/kelola/piutang/hapus-buku/{$dokumen->Uuid}/batalkan", ['Alasan' => 'x'])->assertSessionHasErrors('Alasan');
        $this->post("/kelola/piutang/hapus-buku/{$dokumen->Uuid}/batalkan", ['Alasan' => 'Pelanggan ternyata sanggup membayar'])->assertSessionHasNoErrors();
        $this->post("/kelola/piutang/hapus-buku/{$dokumen->Uuid}/batalkan", ['Alasan' => 'Pelanggan ternyata sanggup membayar'])->assertSessionHasNoErrors();
        $dokumen->refresh();

        expect($dokumen->Status)->toBe(StatusDokumenTerposting::Dibatalkan)
            ->and($dokumen->IdPiutangAktif)->toBeNull()
            ->and($dokumen->IdJurnalPembatalan)->not->toBeNull()
            ->and($k['P1']->refresh()->Status)->toBe(StatusPiutang::BelumLunas)
            ->and($k['P1']->AmbilSisa()->KeString())->toBe('77000.00')
            ->and(BantuanPembelian::SaldoPeran($idTenant, PeranAkun::BebanPiutangTakTertagih))->toBe('0.00')
            ->and(BantuanPembelian::SaldoPeran($idTenant, PeranAkun::PiutangUsaha))->toBe('154000.00')
            ->and(LogAudit::query()->where('Peristiwa', 'piutang.hapus-buku.batalkan')->count())->toBe(1);

        // Tagihan yang dikembalikan bisa dilunasi dan bisa dihapus-buku lagi (dokumen baru).
        $this->post("/kelola/piutang/{$k['P1']->Uuid}/hapus-buku", IsianHapusBuku())->assertSessionHasNoErrors();
        expect(HapusBukuPiutang::query()->count())->toBe(2)
            ->and(HapusBukuPiutang::query()->where('IdPiutangAktif', '>', 0)->count())->toBe(1)
            ->and(BantuanPembelian::PeriksaInvarian($idTenant))->toBe([]);
    });

    it('piutang dihapus-buku tidak bisa dilunasi, di-void, atau diretur sebelum hapus-bukunya dibatalkan', function (): void {
        $k = SiapkanPiutangHapusBuku($this);
        $this->post("/kelola/piutang/{$k['P1']->Uuid}/hapus-buku", IsianHapusBuku())->assertSessionHasNoErrors();
        $pencatat = app(PencatatPiutangPenjualan::class);

        $this->post('/kelola/piutang/pelunasan', [
            'UuidPelanggan' => $k['Toko']->Uuid,
            'UuidAkun' => BantuanPembelian::AkunKas()->Uuid,
            'Tanggal' => BantuanPembelian::Hari()->format('Y-m-d'),
            'Alokasi' => [['UuidPiutang' => $k['P1']->Uuid, 'Jumlah' => '1000']],
        ])->assertSessionHasErrors("Alokasi.{$k['P1']->Uuid}");

        expect($pencatat->PeriksaBisaBatal((int) $k['P1']->IdPenjualan))->toContain('sudah dihapus-buku')
            ->and($pencatat->PeriksaBisaDiretur((int) $k['P1']->IdPenjualan))->toContain('sudah dihapus-buku')
            ->and($pencatat->PeriksaBisaBatal((int) $k['P2']->IdPenjualan))->toBeNull()
            ->and($pencatat->PeriksaBisaDiretur((int) $k['P2']->IdPenjualan))->toBeNull();
    });

    it('izin: kasir tidak bisa melihat; supervisor melihat riwayat tetapi tidak bisa menghapus-buku; tenant lain tidak melihat dokumennya', function (): void {
        $k = SiapkanPiutangHapusBuku($this);
        $this->post("/kelola/piutang/{$k['P1']->Uuid}/hapus-buku", IsianHapusBuku())->assertSessionHasNoErrors();
        $dokumen = HapusBukuPiutang::query()->sole();

        BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id, PeranTenantBawaan::Kasir);
        $this->get('/kelola/piutang/hapus-buku')->assertForbidden();

        BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id, PeranTenantBawaan::Supervisor);
        $this->get('/kelola/piutang/hapus-buku')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h->where('Izin.Kelola', false));
        $this->post("/kelola/piutang/{$k['P2']->Uuid}/hapus-buku", IsianHapusBuku())->assertForbidden();
        $this->post("/kelola/piutang/hapus-buku/{$dokumen->Uuid}/batalkan", ['Alasan' => 'Tidak berwenang'])->assertForbidden();

        $b = BantuanPenjualan::Siapkan($this, 'Toko Tenant Lain');
        BantuanOrganisasi::Masuk($this, $b['Pemilik'], $b['Tenant']->Id);
        $this->get('/kelola/piutang/hapus-buku')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h->has('HapusBuku.Data', 0));
        $this->post("/kelola/piutang/hapus-buku/{$dokumen->Uuid}/batalkan", ['Alasan' => 'Bukan milik tenant ini'])->assertSessionHasErrors('Umum');
    });
});
