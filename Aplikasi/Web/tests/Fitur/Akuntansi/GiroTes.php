<?php

declare(strict_types=1);

use App\Domain\Akuntansi\Aksi\CairkanGiro;
use App\Domain\Akuntansi\Aksi\TolakGiro;
use App\Domain\Akuntansi\Data\DataGiroMasukan;
use App\Domain\Akuntansi\Enum\PeranAkun;
use App\Domain\Akuntansi\Enum\StatusGiro;
use App\Domain\Akuntansi\Model\Giro;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Organisasi\Kueri\TanggalBisnisOutlet;
use App\Domain\Pelanggan\Aksi\BatalkanPembayaranPiutang;
use App\Domain\Pelanggan\Enum\StatusPiutang;
use App\Domain\Pelanggan\Model\Pelanggan;
use App\Domain\Pelanggan\Model\PembayaranPiutang;
use App\Domain\Pelanggan\Model\Piutang;
use App\Domain\Pembelian\Aksi\SimpanPembayaranHutang;
use App\Domain\Pembelian\Data\DataPembayaranHutang;
use App\Domain\Pembelian\Enum\StatusFakturPembelian;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Kasir\BantuanKasir;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Pembelian\BantuanPembelian;
use Tests\Pendukung\Penjualan\BantuanPenjualan;
use Tests\Pendukung\Persediaan\BantuanDokumenPersediaan as B;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Persediaan\PemeriksaInvarian;
use Tests\Pendukung\Tenant\BantuanPendaftaran;
use Tests\TestCase;

/*
 * v3.42 giro/cek mundur (F-12): pelunasan piutang dengan giro → Dr Giro Mundur Diterima / Cr Piutang Usaha; cair →
 * Dr bank / Cr Giro Mundur Diterima (paling cepat tanggal efektif); tolak → pelunasan dibatalkan, piutang terbuka lagi.
 * Pembayaran hutang dengan giro → Dr Hutang Usaha / Cr Hutang Giro; cair → Dr Hutang Giro / Cr bank. Pelunasan bergiro
 * tidak bisa dibatalkan langsung. Jurnal seimbang & akun penampung nol setelah giro diputuskan.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

/**
 * @return array<string, mixed>
 */
function SiapkanPiutangGiro(TestCase $tes): array
{
    $k = BantuanPenjualan::Siapkan($tes, 'Grosir Sembako Giro');
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

    return $k + ['Toko' => $toko, 'P1' => $piutang[0], 'P2' => $piutang[1]];
}

describe('v3.42 giro/cek mundur', function (): void {
    it('giro masuk: pelunasan ke Giro Mundur Diterima, cair ke bank, tolak membuka piutang lagi. Batal langsung ditolak', function (): void {
        $k = SiapkanPiutangGiro($this);
        $hari = app(TanggalBisnisOutlet::class)->Hitung(null);
        $isian = fn (Piutang $p, string $jatuhTempo): array => [
            'UuidPelanggan' => $k['Toko']->Uuid,
            'CaraBayar' => 'Giro',
            'Giro' => ['NomorGiro' => 'bg 123456', 'NamaBank' => 'BCA', 'TanggalJatuhTempo' => $jatuhTempo],
            'Tanggal' => $hari->format('Y-m-d'),
            'Alokasi' => [['UuidPiutang' => $p->Uuid, 'Jumlah' => '77000']],
        ];

        $this->post('/kelola/piutang/pelunasan', ['UuidPelanggan' => $k['Toko']->Uuid, 'CaraBayar' => 'Giro', 'Tanggal' => $hari->format('Y-m-d'), 'Alokasi' => [['UuidPiutang' => $k['P1']->Uuid, 'Jumlah' => '77000']]])
            ->assertSessionHasErrors(['Giro.NomorGiro', 'Giro.NamaBank', 'Giro.TanggalJatuhTempo']);
        $this->post('/kelola/piutang/pelunasan', $isian($k['P1'], $hari->format('Y-m-d')))->assertSessionHasNoErrors()->assertRedirect();
        $this->post('/kelola/piutang/pelunasan', $isian($k['P2'], $hari->addDays(7)->format('Y-m-d')))->assertSessionHasNoErrors()->assertRedirect();
        $g1 = Giro::query()->orderBy('Id')->firstOrFail();
        $g2 = Giro::query()->orderByDesc('Id')->firstOrFail();

        expect($g1->Status)->toBe(StatusGiro::Menunggu)
            ->and($g1->NomorGiro)->toBe('BG 123456')
            ->and($k['P1']->refresh()->Status)->toBe(StatusPiutang::Lunas)
            ->and(BantuanPembelian::SaldoPeran($k['Tenant']->Id, PeranAkun::GiroDiterima))->toBe('154000.00')
            ->and(BantuanPembelian::SaldoPeran($k['Tenant']->Id, PeranAkun::PiutangUsaha))->toBe('0.00')
            ->and(B::KodeGalat(fn () => app(BatalkanPembayaranPiutang::class)->Jalankan(PembayaranPiutang::query()->findOrFail($g1->IdSumber), 'Salah catat pelunasan', $k['Pemilik']->Id)))->toBe('PakaiTolakGiro');

        $bank = BantuanPembelian::AkunKas('1-1200');
        expect(B::KodeGalat(fn () => app(CairkanGiro::class)->Jalankan($g2, $bank->Uuid, $hari, $k['Pemilik']->Id)))->toBe('BelumJatuhTempo');

        $this->get('/kelola/akuntansi/giro')->assertInertia(fn (AssertableInertia $h) => $h->component('Kelola/Akuntansi/Giro/Daftar')
            ->where('Giro.Ringkasan.MasukJumlah', 2)->where('Giro.Ringkasan.MasukNilai', '154000.00')->has('Giro.Data', 2));
        $this->post("/kelola/akuntansi/giro/{$g1->Uuid}/cair", ['UuidAkun' => $bank->Uuid, 'Tanggal' => $hari->format('Y-m-d')])->assertSessionHasNoErrors()->assertRedirect();
        $this->post("/kelola/akuntansi/giro/{$g2->Uuid}/tolak", ['Alasan' => 'Saldo rekening penerbit kosong'])->assertSessionHasNoErrors()->assertRedirect();

        expect($g1->refresh()->Status)->toBe(StatusGiro::Cair)
            ->and($g2->refresh()->Status)->toBe(StatusGiro::Ditolak)
            ->and($k['P2']->refresh()->Status)->toBe(StatusPiutang::BelumLunas)
            ->and(BantuanPembelian::SaldoPeran($k['Tenant']->Id, PeranAkun::GiroDiterima))->toBe('0.00')
            ->and(BantuanPembelian::SaldoPeran($k['Tenant']->Id, PeranAkun::PiutangUsaha))->toBe('77000.00')
            ->and(B::KodeGalat(fn () => app(BatalkanPembayaranPiutang::class)->Jalankan(PembayaranPiutang::query()->findOrFail($g1->IdSumber), 'Salah catat pelunasan', $k['Pemilik']->Id)))->toBe('GiroSudahCair')
            ->and(B::KodeGalat(fn () => app(TolakGiro::class)->Jalankan($g1, 'Ditolak bank penerima', $k['Pemilik']->Id)))->toBe('GiroSudahDiputuskan')
            ->and(PemeriksaInvarian::PeriksaJurnalSeimbang($k['Tenant']->Id))->toBe([]);

        BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id, PeranTenantBawaan::Kasir);
        $this->get('/kelola/akuntansi/giro')->assertForbidden();
        $lain = BantuanOrganisasi::BuatTenant('Warung Sebelah');
        BantuanPersediaan::MasukSebagai($this, $lain['Tenant']->Id);
        $this->post("/kelola/akuntansi/giro/{$g2->Uuid}/cair", ['UuidAkun' => $bank->Uuid, 'Tanggal' => $hari->format('Y-m-d')])->assertNotFound();
    });

    it('giro keluar: pembayaran hutang ke Hutang Giro, cair mengurangi bank. Invarian hutang', function (): void {
        $t = BantuanPembelian::SiapkanTenant();
        $pemasok = BantuanPembelian::BuatPemasok('CV Sumber Rejeki', idPengguna: $t['Pemilik']->Id);
        $beras = BantuanKatalog::BuatProduk(['Nama' => 'Beras Premium Rojolele 5 kg'], '85000.00', $t['Pcs']);
        $grn = BantuanPembelian::TerimaTanpaPo($pemasok, $t['Gudang'], [[$beras, '10', '60000']], $t['Pemilik']->Id);
        $faktur = BantuanPembelian::Fakturkan($pemasok, [$grn], $t['Pemilik']->Id);
        $hari = BantuanPembelian::Hari();

        $bayar = app(SimpanPembayaranHutang::class)->Jalankan(new DataPembayaranHutang(
            $pemasok->Uuid,
            '',
            $hari,
            [$faktur->Uuid => Uang::Dari('600000')],
            null,
            null,
            $t['Pemilik']->Id,
            new DataGiroMasukan('CEK-778899', 'Bank Mandiri', $hari),
        ));
        $giro = Giro::query()->sole();

        expect($faktur->refresh()->Status)->toBe(StatusFakturPembelian::Lunas)
            ->and($giro->NomorSumber)->toBe($bayar->Nomor)
            ->and(BantuanPembelian::SaldoPeran($t['Tenant']->Id, PeranAkun::HutangGiro))->toBe('-600000.00')
            ->and(BantuanPembelian::PeriksaInvarian($t['Tenant']->Id))->toBe([]);

        $bank = BantuanPembelian::AkunKas('1-1200');
        app(CairkanGiro::class)->Jalankan($giro, $bank->Uuid, $hari, $t['Pemilik']->Id);

        expect(BantuanPembelian::SaldoPeran($t['Tenant']->Id, PeranAkun::HutangGiro))->toBe('0.00')
            ->and(BantuanPembelian::SaldoPeran($t['Tenant']->Id, PeranAkun::Bank))->toBe('-600000.00')
            ->and(BantuanPembelian::PeriksaInvarian($t['Tenant']->Id))->toBe([]);
    });
});
