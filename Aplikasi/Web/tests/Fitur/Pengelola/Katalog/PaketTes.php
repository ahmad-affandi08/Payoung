<?php

declare(strict_types=1);

use App\Domain\Bersama\Status\StatusDataMaster;
use App\Domain\Pengelola\Katalog\Aksi\SiapkanKatalogBawaan;
use App\Domain\Pengelola\TimInternal\Enum\PeranPengelolaBawaan;
use App\Domain\Pengelola\TimInternal\Model\LogAuditPengelola;
use App\Domain\Pengelola\TimInternal\Model\PenggunaPengelola;
use App\Domain\Tenant\Enum\StatusPaket;
use App\Domain\Tenant\Kueri\PaketTersedia;
use App\Domain\Tenant\Model\Addon;
use App\Domain\Tenant\Model\Fitur;
use App\Domain\Tenant\Model\HargaPaket;
use App\Domain\Tenant\Model\Paket;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Pengelola\BantuanPengelola;
use Tests\TestCase;

function SebagaiAnggotaKatalog(TestCase $tes, PenggunaPengelola $pengguna): TestCase
{
    return $tes->actingAs($pengguna, 'pengelola')->withSession(BantuanPengelola::SesiTerverifikasi());
}

/**
 * @param  array<string, mixed>  $ubah
 * @return array<string, mixed>
 */
function DataPaketUji(array $ubah = []): array
{
    return [
        'Kode' => 'UJI',
        'Nama' => 'Paket Uji',
        'Keterangan' => null,
        'HargaNegosiasi' => false,
        'MasaTrialHari' => 14,
        'Urutan' => 9,
        'Batas' => ['BatasOutlet' => 2, 'BatasPerangkatPerOutlet' => 3, 'BatasPengguna' => null, 'BatasSku' => '', 'KuotaPesanWaBulanan' => 0, 'BatasPenyimpananMb' => null],
        'KunciFitur' => ['pos.retail', 'laporan.dasar'],
        ...$ubah,
    ];
}

beforeEach(function (): void {
    // Waktu dibekukan agar tanggal di test tidak kedaluwarsa seiring waktu (BR tanggal berlaku tidak boleh lewat).
    $this->travelTo(Carbon::parse('2026-09-23 10:00:00', 'Asia/Jakarta'));
    app(SiapkanKatalogBawaan::class)->Jalankan();
});

describe('Katalog paket (P-04)', function (): void {
    it('seeder memuat fitur & paket §21 sebagai draf dengan draf harga, idempoten, tanpa harga di kode', function (): void {
        app(SiapkanKatalogBawaan::class)->Jalankan();

        expect(Paket::query()->pluck('Kode')->all())->toEqualCanonicalizing(['GRATIS', 'STARTER', 'PRO', 'BISNIS', 'ENTERPRISE'])
            ->and(Paket::query()->where('Status', '!=', StatusPaket::Draf->value)->count())->toBe(0)
            ->and(HargaPaket::query()->count())->toBe(4)
            ->and(HargaPaket::query()->where('Status', '!=', StatusDataMaster::Draf->value)->count())->toBe(0)
            ->and(Paket::query()->where('Kode', 'ENTERPRISE')->sole()->HargaNegosiasi)->toBeTrue()
            ->and(Paket::query()->where('Kode', 'GRATIS')->sole()->AmbilBatas()['BatasSku'])->toBe(100)
            ->and(Paket::query()->where('Kode', 'BISNIS')->sole()->AmbilKunciFitur())->toContain('api.publik')
            ->and(Paket::query()->where('Kode', 'PRO')->sole()->AmbilBatas())->toBe([
                'BatasOutlet' => 3, 'BatasPerangkatPerOutlet' => 5, 'BatasPengguna' => 20,
                'BatasSku' => null, 'KuotaPesanWaBulanan' => 100, 'BatasPenyimpananMb' => 5120, 'KuotaTransaksiBulanan' => null, 'KuotaTransaksiHarian' => null,
            ])
            ->and(Addon::query()->count())->toBe(6)
            ->and(Addon::query()->where('Status', '!=', StatusPaket::Diarsipkan->value)->count())->toBe(0)
            ->and(Addon::query()->where('Kode', 'OUTLET_TAMBAHAN')->sole()->TambahanBatas)->toBe(['BatasOutlet' => 1]);
    });

    it('Keuangan menyusun draf paket dengan batas & fitur; batas kosong = tak terbatas; tercatat di audit', function (): void {
        $keuangan = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Keuangan);

        SebagaiAnggotaKatalog($this, $keuangan)->post(BantuanPengelola::Url('/katalog/paket'), DataPaketUji())->assertSessionHasNoErrors();

        $paket = Paket::query()->where('Kode', 'UJI')->sole();
        expect($paket->Status)->toBe(StatusPaket::Draf)
            ->and($paket->AmbilBatas())->toBe([
                'BatasOutlet' => 2, 'BatasPerangkatPerOutlet' => 3, 'BatasPengguna' => null,
                'BatasSku' => null, 'KuotaPesanWaBulanan' => 0, 'BatasPenyimpananMb' => null, 'KuotaTransaksiBulanan' => null, 'KuotaTransaksiHarian' => null,
            ])
            ->and($paket->AmbilKunciFitur())->toBe(['laporan.dasar', 'pos.retail']);
        $this->assertDatabaseHas('LogAuditPengelola', ['Aksi' => 'katalog.paket.buat', 'IdPenggunaPengelola' => $keuangan->Id]);
    });

    it('kuota transaksi per bulan diatur dari konsol seperti batas lain: angka disimpan, kosong = tak terbatas, negatif ditolak', function (): void {
        $keuangan = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Keuangan);
        $batas = ['BatasOutlet' => 1, 'BatasPerangkatPerOutlet' => 1, 'BatasPengguna' => 2, 'BatasSku' => 100, 'KuotaPesanWaBulanan' => 0, 'BatasPenyimpananMb' => 100];

        SebagaiAnggotaKatalog($this, $keuangan)->post(BantuanPengelola::Url('/katalog/paket'), DataPaketUji(['Batas' => [...$batas, 'KuotaTransaksiBulanan' => 500, 'KuotaTransaksiHarian' => 50]]))->assertSessionHasNoErrors();
        expect(Paket::query()->where('Kode', 'UJI')->sole()->AmbilBatas())->toMatchArray(['KuotaTransaksiBulanan' => 500, 'KuotaTransaksiHarian' => 50]);

        $uuid = Paket::query()->where('Kode', 'UJI')->sole()->Uuid;
        SebagaiAnggotaKatalog($this, $keuangan)->put(BantuanPengelola::Url("/katalog/paket/{$uuid}"), DataPaketUji(['Batas' => [...$batas, 'KuotaTransaksiBulanan' => '', 'KuotaTransaksiHarian' => '']]))->assertSessionHasNoErrors();
        expect(Paket::query()->where('Kode', 'UJI')->sole()->AmbilBatas())->toMatchArray(['KuotaTransaksiBulanan' => null, 'KuotaTransaksiHarian' => null]);

        SebagaiAnggotaKatalog($this, $keuangan)->put(BantuanPengelola::Url("/katalog/paket/{$uuid}"), DataPaketUji(['Batas' => [...$batas, 'KuotaTransaksiBulanan' => -5]]))->assertSessionHasErrors('Batas.KuotaTransaksiBulanan');
        SebagaiAnggotaKatalog($this, $keuangan)->put(BantuanPengelola::Url("/katalog/paket/{$uuid}"), DataPaketUji(['Batas' => [...$batas, 'KuotaTransaksiHarian' => -1]]))->assertSessionHasErrors('Batas.KuotaTransaksiHarian');
    });

    it('menolak fitur tidak dikenal, batas negatif, dan kode ganda', function (array $ubah, string $bidang): void {
        $keuangan = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Keuangan);

        SebagaiAnggotaKatalog($this, $keuangan)->post(BantuanPengelola::Url('/katalog/paket'), DataPaketUji($ubah))->assertSessionHasErrors($bidang);
    })->with([
        'fitur tidak dikenal' => [['KunciFitur' => ['pos.terbang']], 'KunciFitur.0'],
        'batas negatif' => [['Batas' => ['BatasOutlet' => -1]], 'Batas.BatasOutlet'],
        'kode ganda' => [['Kode' => 'PRO'], 'Kode'],
    ]);

    it('BR-P04.6: paket hanya bisa diaktifkan bila punya harga terbit atau harga negosiasi', function (): void {
        $superAdmin = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::SuperAdmin);
        $pro = Paket::query()->where('Kode', 'PRO')->sole();
        $enterprise = Paket::query()->where('Kode', 'ENTERPRISE')->sole();
        SebagaiAnggotaKatalog($this, $superAdmin);

        $this->post(BantuanPengelola::Url("/katalog/paket/{$pro->Uuid}/aktifkan"))->assertSessionHasErrors('Umum');
        $this->post(BantuanPengelola::Url("/katalog/paket/{$enterprise->Uuid}/aktifkan"))->assertSessionHasNoErrors();

        HargaPaket::query()->where('IdPaket', $pro->Id)->update(['Status' => StatusDataMaster::Terbit->value]);
        $this->post(BantuanPengelola::Url("/katalog/paket/{$pro->Uuid}/aktifkan"))->assertSessionHasNoErrors();

        expect($pro->refresh()->Status)->toBe(StatusPaket::Aktif)->and($enterprise->refresh()->Status)->toBe(StatusPaket::Aktif);
    });

    it('BR-P04.2: paket diarsipkan (dengan alasan) tidak tersedia untuk tenant baru dan bisa diaktifkan lagi', function (): void {
        $superAdmin = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::SuperAdmin);
        $enterprise = Paket::query()->where('Kode', 'ENTERPRISE')->sole();
        SebagaiAnggotaKatalog($this, $superAdmin);
        $this->post(BantuanPengelola::Url("/katalog/paket/{$enterprise->Uuid}/aktifkan"));
        expect(array_map(fn (Paket $paket) => $paket->Kode, app(PaketTersedia::class)->AmbilUntukPendaftaran()))->toBe(['ENTERPRISE']);

        $this->post(BantuanPengelola::Url("/katalog/paket/{$enterprise->Uuid}/arsipkan"))->assertSessionHasErrors('Alasan');
        $this->post(BantuanPengelola::Url("/katalog/paket/{$enterprise->Uuid}/arsipkan"), ['Alasan' => 'Diganti paket baru'])
            ->assertSessionHasNoErrors();

        expect(app(PaketTersedia::class)->AmbilUntukPendaftaran())->toBe([])
            ->and($enterprise->refresh()->DiarsipkanPada)->not->toBeNull();

        $this->post(BantuanPengelola::Url("/katalog/paket/{$enterprise->Uuid}/aktifkan"))->assertSessionHasNoErrors();
        expect($enterprise->refresh()->Status)->toBe(StatusPaket::Aktif)->and($enterprise->DiarsipkanPada)->toBeNull();
    });

    it('BR-P04.6: paket aktif hanya diubah Super Admin dengan alasan; Keuangan hanya boleh mengubah draf', function (): void {
        $superAdmin = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::SuperAdmin);
        $keuangan = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Keuangan);
        $enterprise = Paket::query()->where('Kode', 'ENTERPRISE')->sole();
        SebagaiAnggotaKatalog($this, $superAdmin)->post(BantuanPengelola::Url("/katalog/paket/{$enterprise->Uuid}/aktifkan"));
        $data = DataPaketUji(['Kode' => 'ENTERPRISE', 'Nama' => 'Enterprise', 'HargaNegosiasi' => true]);

        SebagaiAnggotaKatalog($this, $keuangan)->put(BantuanPengelola::Url("/katalog/paket/{$enterprise->Uuid}"), $data)->assertSessionHasErrors('Umum');
        SebagaiAnggotaKatalog($this, $superAdmin)->put(BantuanPengelola::Url("/katalog/paket/{$enterprise->Uuid}"), $data)->assertSessionHasErrors('Alasan');
        $this->put(BantuanPengelola::Url("/katalog/paket/{$enterprise->Uuid}"), [...$data, 'Alasan' => 'Sesuaikan batas'])->assertSessionHasNoErrors();

        $log = LogAuditPengelola::query()->where('Aksi', 'katalog.paket.ubah')->sole();
        expect($log->Alasan)->toBe('Sesuaikan batas')
            ->and($log->NilaiLama['Fitur'] ?? [])->toContain('piutang.grosir')
            ->and($log->NilaiBaru['Fitur'] ?? [])->toBe(['laporan.dasar', 'pos.retail']);
    });

    it('izin: peran lain hanya melihat katalog; Keuangan tidak bisa mengaktifkan paket', function (): void {
        $analis = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Analis);
        $keuangan = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Keuangan);
        $enterprise = Paket::query()->where('Kode', 'ENTERPRISE')->sole();

        SebagaiAnggotaKatalog($this, $analis)->get(BantuanPengelola::Url('/katalog/paket'))
            ->assertInertia(fn (AssertableInertia $halaman) => $halaman->component('Pengelola/Katalog/Paket')->has('Paket', 5));
        $this->post(BantuanPengelola::Url('/katalog/paket'), DataPaketUji())->assertForbidden();

        SebagaiAnggotaKatalog($this, $keuangan)->post(BantuanPengelola::Url("/katalog/paket/{$enterprise->Uuid}/aktifkan"))
            ->assertForbidden();
    });
    it('BR-P04.6: paket aktif tidak bisa keluar dari harga negosiasi tanpa harga berlaku', function (): void {
        $superAdmin = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::SuperAdmin);
        $enterprise = Paket::query()->where('Kode', 'ENTERPRISE')->sole();
        SebagaiAnggotaKatalog($this, $superAdmin)->post(BantuanPengelola::Url("/katalog/paket/{$enterprise->Uuid}/aktifkan"));

        $this->put(BantuanPengelola::Url("/katalog/paket/{$enterprise->Uuid}"), [
            ...DataPaketUji(['Kode' => 'ENTERPRISE', 'Nama' => 'Enterprise', 'HargaNegosiasi' => false]),
            'Alasan' => 'Harga tetap',
        ])->assertSessionHasErrors('HargaNegosiasi');

        expect($enterprise->refresh()->HargaNegosiasi)->toBeTrue();
    });

    it('perubahan fitur tercatat di audit', function (): void {
        SebagaiAnggotaKatalog($this, BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::SuperAdmin))
            ->post(BantuanPengelola::Url('/katalog/fitur'), ['Kunci' => 'pos.uji', 'Nama' => 'Uji', 'Modul' => 'Penjualan']);
        $this->put(BantuanPengelola::Url('/katalog/fitur/pos.uji'), ['Kunci' => 'pos.uji', 'Nama' => 'Uji baru', 'Modul' => 'Penjualan']);

        $log = LogAuditPengelola::query()->where('Aksi', 'katalog.fitur.ubah')->sole();
        expect($log->NilaiLama['Nama'] ?? null)->toBe('Uji')->and($log->NilaiBaru['Nama'] ?? null)->toBe('Uji baru');
    });
});

describe('Katalog fitur (P-04)', function (): void {
    it('Super Admin menambah fitur dengan kunci D-06; kunci tidak bisa diubah; Keuangan tidak bisa', function (): void {
        $superAdmin = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::SuperAdmin);
        $keuangan = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Keuangan);
        SebagaiAnggotaKatalog($this, $superAdmin);

        $this->post(BantuanPengelola::Url('/katalog/fitur'), ['Kunci' => 'pos.layar-pelanggan', 'Nama' => 'Layar pelanggan', 'Modul' => 'Penjualan'])
            ->assertSessionHasNoErrors();
        $this->post(BantuanPengelola::Url('/katalog/fitur'), ['Kunci' => 'Pos Layar', 'Nama' => 'X', 'Modul' => 'Y'])->assertSessionHasErrors('Kunci');
        $this->put(BantuanPengelola::Url('/katalog/fitur/pos.layar-pelanggan'), ['Kunci' => 'pos.layar', 'Nama' => 'X', 'Modul' => 'Y'])
            ->assertSessionHasErrors('Kunci');

        expect(Fitur::query()->where('Kunci', 'pos.layar-pelanggan')->sole()->Nama)->toBe('Layar pelanggan');
        SebagaiAnggotaKatalog($this, $keuangan)
            ->post(BantuanPengelola::Url('/katalog/fitur'), ['Kunci' => 'pos.lain', 'Nama' => 'X', 'Modul' => 'Y'])
            ->assertForbidden();
    });
});
