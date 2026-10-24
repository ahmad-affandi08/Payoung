<?php

declare(strict_types=1);

use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Organisasi\Enum\JenisGudang;
use App\Domain\Organisasi\Enum\JenisNotifikasiPengguna;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Organisasi\Model\NotifikasiDibisukan;
use App\Domain\Organisasi\Model\NotifikasiPengguna;
use App\Domain\Organisasi\Model\Outlet;
use App\Domain\Organisasi\Model\OutletPengguna;
use App\Domain\Persediaan\Aksi\AjukanPermintaanStok;
use App\Domain\Persediaan\Aksi\BatalkanPermintaanStok;
use App\Domain\Persediaan\Aksi\BeritahuTransferStokMacet;
use App\Domain\Persediaan\Aksi\KirimTransferStok;
use App\Domain\Persediaan\Aksi\TerimaTransferStok;
use App\Domain\Persediaan\Aksi\TolakPermintaanStok;
use App\Domain\Persediaan\Aksi\TutupPermintaanStok;
use App\Domain\Persediaan\Data\DataBarisPermintaanStok;
use App\Domain\Persediaan\Data\DataPermintaanStok;
use App\Domain\Persediaan\Data\DataTerimaTransfer;
use App\Domain\Persediaan\Enum\MetodeHpp;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Persediaan\BantuanDokumenPersediaan as B;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Persediaan\BantuanStokAwal;
use Tests\Pendukung\Tenant\BantuanFitur;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * F-05b, OWN-03 / PLT-08, D-187: kabar stok antar-outlet. Permintaan stok baru mengabari manajer outlet asal; permintaan
 * ditolak/ditutup mengabari sisi peminta, dibatalkan mengabari sisi pengirim; transfer yang diterima mengabari pengirim
 * dan manajer outlet asal (dengan catatan rusak/ditolak); transfer yang tak kunjung diterima mengabari manajer tujuan
 * (3 hari) lalu pengirim dan manajer asal (7 hari). Pelaku tidak dikabari, outlet kanvas tidak dikabari.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

/** Gudang utama (outlet pertama) = pengirim; cabang = peminta/penerima. Satu manajer per outlet, plus satu di outlet lain. */
function SiapkanKabarStok(): array
{
    $t = BantuanPersediaan::SiapkanTenant(metodeHpp: MetodeHpp::RataRata);
    BantuanFitur::Beri($t['Tenant']->Id, 'stok.transfer');
    $p = BantuanPersediaan::BuatProdukSemuaJenis($t['Pcs'], $t['Kg']);
    $cabang = B::BuatOutlet();
    $lain = B::BuatOutlet('KARTA', 'Cabang Kartasura');
    $gudangCabang = BantuanPersediaan::BuatGudang($cabang, 'Toko Cabang Solo Baru', JenisGudang::Toko);
    BantuanStokAwal::BuatDanPosting($t['Gudang'], [
        BantuanStokAwal::Baris($p['Stok'], '100', '38500'),
    ], $t['Pemilik']->Id, CarbonImmutable::now('Asia/Jakarta')->subDays(3)->format('Y-m-d'));

    $manajerAsal = BantuanOrganisasi::TambahAnggota($t['Tenant']->Id, PeranTenantBawaan::ManajerOutlet, semuaOutlet: false);
    $manajerTujuan = BantuanOrganisasi::TambahAnggota($t['Tenant']->Id, PeranTenantBawaan::ManajerOutlet, semuaOutlet: false);
    $manajerLain = BantuanOrganisasi::TambahAnggota($t['Tenant']->Id, PeranTenantBawaan::ManajerOutlet, semuaOutlet: false);
    $peran = BantuanOrganisasi::Peran($t['Tenant']->Id, PeranTenantBawaan::ManajerOutlet);

    foreach ([[$manajerAsal, $t['Gudang']->IdOutlet], [$manajerTujuan, $cabang->Id], [$manajerLain, $lain->Id]] as [$pengguna, $idOutlet]) {
        OutletPengguna::query()->create(['IdOutlet' => $idOutlet, 'IdPengguna' => $pengguna->Id, 'IdPeran' => $peran->Id]);
    }

    return [...$t, 'Produk' => $p, 'Cabang' => $cabang, 'GudangCabang' => $gudangCabang, 'ManajerAsal' => $manajerAsal, 'ManajerTujuan' => $manajerTujuan, 'ManajerLain' => $manajerLain];
}

/** @return list<int> Id pengguna penerima kabar [jenis], urut Id. */
function PenerimaKabarStok(JenisNotifikasiPengguna $jenis): array
{
    return NotifikasiPengguna::query()->where('Jenis', $jenis->value)->orderBy('Id')->pluck('IdPengguna')->map(fn (mixed $id): int => (int) $id)->all();
}

function AjukanPermintaanKabar(array $t): App\Domain\Persediaan\Model\PermintaanStok
{
    return app(AjukanPermintaanStok::class)->Jalankan(new DataPermintaanStok(
        (string) Str::ulid(),
        $t['Gudang']->Id,
        $t['GudangCabang']->Id,
        CarbonImmutable::parse(B::Kemarin()),
        'Stok rak cabang menipis',
        [new DataBarisPermintaanStok($t['Produk']['Stok']->Id, Kuantitas::Dari('10'))],
    ));
}

function KirimTransferKabar(array $t): App\Domain\Persediaan\Model\TransferStok
{
    return app(KirimTransferStok::class)->Jalankan(B::DrafTransfer($t['Gudang'], $t['GudangCabang'], [B::Baris($t['Produk']['Stok'], '30')]), $t['Pemilik']->Id);
}

function HariTerimaKabar(): CarbonImmutable
{
    return CarbonImmutable::now('Asia/Jakarta')->subHours(4)->startOfDay();
}

describe('permintaan stok', function (): void {
    it('diajukan: manajer outlet asal dikabari; manajer tujuan dan outlet lain tidak', function (): void {
        $t = SiapkanKabarStok();
        $permintaan = AjukanPermintaanKabar($t);

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        $penerima = PenerimaKabarStok(JenisNotifikasiPengguna::PermintaanStok);
        $kabar = NotifikasiPengguna::query()->where('Jenis', JenisNotifikasiPengguna::PermintaanStok->value)->where('IdPengguna', $t['ManajerAsal']->Id)->sole();

        expect($penerima)->toContain($t['ManajerAsal']->Id)
            ->and($penerima)->not->toContain($t['ManajerTujuan']->Id)
            ->and($penerima)->not->toContain($t['ManajerLain']->Id)
            ->and($kabar->Kunci)->toBe("PermintaanStok:{$permintaan->Uuid}:Diajukan")
            ->and($kabar->Judul)->toContain('Toko Cabang Solo Baru')
            ->and($kabar->Isi)->toContain((string) $permintaan->Nomor)
            ->and($kabar->Data['Href'])->toBe("/kelola/persediaan/permintaan/{$permintaan->Uuid}");
    });

    it('ditolak: manajer outlet tujuan dikabari beserta alasannya; pengirim yang menolak dan manajer asal tidak', function (): void {
        $t = SiapkanKabarStok();
        $permintaan = AjukanPermintaanKabar($t);
        app(TolakPermintaanStok::class)->Jalankan($permintaan, 'Stok gudang sedang opname', $t['ManajerAsal']->Id);

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        $kabar = NotifikasiPengguna::query()->where('Kunci', "PermintaanStok:{$permintaan->Uuid}:Ditolak")->get();

        expect($kabar->pluck('IdPengguna')->all())->toContain($t['ManajerTujuan']->Id)
            ->and($kabar->pluck('IdPengguna')->all())->not->toContain($t['ManajerAsal']->Id)
            ->and($kabar->pluck('IdPengguna')->all())->not->toContain($t['ManajerLain']->Id)
            ->and($kabar->firstWhere('IdPengguna', $t['ManajerTujuan']->Id)->Isi)->toContain('Stok gudang sedang opname');
    });

    it('dibatalkan peminta: manajer outlet asal dikabari supaya tidak menyiapkan barang', function (): void {
        $t = SiapkanKabarStok();
        $permintaan = AjukanPermintaanKabar($t);
        app(BatalkanPermintaanStok::class)->Jalankan($permintaan, 'Salah memilih gudang', $t['ManajerTujuan']->Id);

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        $kabar = NotifikasiPengguna::query()->where('Kunci', "PermintaanStok:{$permintaan->Uuid}:Dibatalkan")->pluck('IdPengguna')->map(fn (mixed $id): int => (int) $id)->all();

        expect($kabar)->toContain($t['ManajerAsal']->Id)
            ->and($kabar)->not->toContain($t['ManajerTujuan']->Id);
    });

    it('permintaan dari atau ke outlet kanvas tidak dikabari', function (): void {
        $t = SiapkanKabarStok();
        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        Outlet::query()->whereKey($t['Cabang']->Id)->update(['Kanvas' => true]);
        AjukanPermintaanKabar($t);

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        expect(PenerimaKabarStok(JenisNotifikasiPengguna::PermintaanStok))->toBe([]);
    });

    it('kabar permintaan stok bisa dibisukan penerimanya', function (): void {
        $t = SiapkanKabarStok();
        NotifikasiDibisukan::query()->create(['IdPengguna' => $t['ManajerAsal']->Id, 'Jenis' => JenisNotifikasiPengguna::PermintaanStok->value]);
        AjukanPermintaanKabar($t);

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        expect(PenerimaKabarStok(JenisNotifikasiPengguna::PermintaanStok))->not->toContain($t['ManajerAsal']->Id);
    });
});

describe('transfer diterima', function (): void {
    it('pengirim dan manajer outlet asal dikabari; penerima yang mencatat tidak; barang rusak dan ditolak disebut', function (): void {
        $t = SiapkanKabarStok();
        $transfer = KirimTransferKabar($t);
        app(TerimaTransferStok::class)->Jalankan($transfer, [
            new DataTerimaTransfer(1, Kuantitas::Dari('20'), Kuantitas::Dari('3'), Kuantitas::Dari('7')),
        ], HariTerimaKabar(), $t['ManajerTujuan']->Id);

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        $penerima = PenerimaKabarStok(JenisNotifikasiPengguna::TransferStokDiterima);
        $kabar = NotifikasiPengguna::query()->where('Jenis', JenisNotifikasiPengguna::TransferStokDiterima->value)->where('IdPengguna', $t['ManajerAsal']->Id)->sole();

        expect($penerima)->toContain($t['Pemilik']->Id)
            ->and($penerima)->toContain($t['ManajerAsal']->Id)
            ->and($penerima)->not->toContain($t['ManajerTujuan']->Id)
            ->and($penerima)->not->toContain($t['ManajerLain']->Id)
            ->and($kabar->Kunci)->toBe("TransferStokDiterima:{$transfer->Uuid}:1")
            ->and($kabar->Judul)->toContain('Toko Cabang Solo Baru')
            ->and($kabar->Isi)->toContain('diterima seluruhnya')->toContain('rusak')->toContain('ditolak');
    });

    it('diterima sebagian lalu dilengkapi: satu kabar per penerimaan, yang pertama menyebut sisa dalam perjalanan', function (): void {
        $t = SiapkanKabarStok();
        $transfer = KirimTransferKabar($t);
        $transfer = app(TerimaTransferStok::class)->Jalankan($transfer, [new DataTerimaTransfer(1, Kuantitas::Dari('10'))], HariTerimaKabar(), $t['ManajerTujuan']->Id);
        app(TerimaTransferStok::class)->Jalankan($transfer, [new DataTerimaTransfer(1, Kuantitas::Dari('20'))], HariTerimaKabar(), $t['ManajerTujuan']->Id);

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        $kabar = NotifikasiPengguna::query()->where('IdPengguna', $t['ManajerAsal']->Id)->where('Jenis', JenisNotifikasiPengguna::TransferStokDiterima->value)->orderBy('Id')->get();

        expect($kabar)->toHaveCount(2)
            ->and($kabar[0]->Isi)->toContain('diterima sebagian')
            ->and($kabar[0]->Judul)->toContain('sebagian')
            ->and($kabar[1]->Isi)->toContain('diterima seluruhnya');
    });

    it('transfer ke outlet kanvas tidak dikabari', function (): void {
        $t = SiapkanKabarStok();
        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        Outlet::query()->whereKey($t['Cabang']->Id)->update(['Kanvas' => true]);
        $transfer = KirimTransferKabar($t);
        app(TerimaTransferStok::class)->Jalankan($transfer, [new DataTerimaTransfer(1, Kuantitas::Dari('30'))], HariTerimaKabar(), $t['ManajerTujuan']->Id);

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        expect(PenerimaKabarStok(JenisNotifikasiPengguna::TransferStokDiterima))->toBe([]);
    });
});

describe('transfer macet', function (): void {
    it('3 hari: manajer outlet tujuan dikabari; 7 hari: pengirim dan manajer asal; tidak menggandakan', function (): void {
        $t = SiapkanKabarStok();
        $transfer = KirimTransferKabar($t);
        $aksi = app(BeritahuTransferStokMacet::class);

        // Belum 3 hari: belum ada kabar.
        $this->travel(2)->days();
        $aksi->Jalankan($t['Tenant']->Id, CarbonImmutable::now());
        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        expect(PenerimaKabarStok(JenisNotifikasiPengguna::TransferStokMacet))->toBe([]);

        $this->travel(2)->days();
        $aksi->Jalankan($t['Tenant']->Id, CarbonImmutable::now());
        $aksi->Jalankan($t['Tenant']->Id, CarbonImmutable::now());
        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        $penerima = PenerimaKabarStok(JenisNotifikasiPengguna::TransferStokMacet);
        $kabar = NotifikasiPengguna::query()->where('Jenis', JenisNotifikasiPengguna::TransferStokMacet->value)->where('IdPengguna', $t['ManajerTujuan']->Id)->sole();

        expect($penerima)->toContain($t['ManajerTujuan']->Id)
            ->and($penerima)->not->toContain($t['ManajerAsal']->Id)
            ->and($penerima)->not->toContain($t['ManajerLain']->Id)
            ->and($kabar->Kunci)->toBe("TransferStokMacet:{$transfer->Uuid}:H3")
            ->and($kabar->Judul)->toContain('4 hari')
            ->and($kabar->Isi)->toContain((string) $transfer->Nomor);

        $this->travel(4)->days();
        $aksi->Jalankan($t['Tenant']->Id, CarbonImmutable::now());
        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        $penerima = PenerimaKabarStok(JenisNotifikasiPengguna::TransferStokMacet);

        expect($penerima)->toContain($t['ManajerAsal']->Id)
            ->and($penerima)->toContain($t['Pemilik']->Id)
            ->and($penerima)->not->toContain($t['ManajerLain']->Id)
            ->and(NotifikasiPengguna::query()->where('Kunci', "TransferStokMacet:{$transfer->Uuid}:H7")->where('IdPengguna', $t['ManajerAsal']->Id)->count())->toBe(1);
    });

    it('transfer yang sudah diterima seluruhnya, dibatalkan, atau dari/ke outlet kanvas tidak dianggap macet', function (): void {
        $t = SiapkanKabarStok();
        $transfer = KirimTransferKabar($t);
        app(TerimaTransferStok::class)->Jalankan($transfer, [new DataTerimaTransfer(1, Kuantitas::Dari('30'))], HariTerimaKabar(), $t['ManajerTujuan']->Id);
        $this->travel(10)->days();
        app(BeritahuTransferStokMacet::class)->Jalankan($t['Tenant']->Id, CarbonImmutable::now());

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        expect(PenerimaKabarStok(JenisNotifikasiPengguna::TransferStokMacet))->toBe([]);

        $this->travelBack();
        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        Outlet::query()->whereKey($t['Cabang']->Id)->update(['Kanvas' => true]);
        KirimTransferKabar($t);
        $this->travel(10)->days();
        app(BeritahuTransferStokMacet::class)->Jalankan($t['Tenant']->Id, CarbonImmutable::now());

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        expect(PenerimaKabarStok(JenisNotifikasiPengguna::TransferStokMacet))->toBe([]);
    });
});

it('permintaan ditutup dengan sisa: sisi peminta dikabari bahwa sisa tidak akan dikirim', function (): void {
    $t = SiapkanKabarStok();
    $permintaan = AjukanPermintaanKabar($t);
    app(KirimTransferStok::class)->Jalankan(B::DrafTransfer($t['Gudang'], $t['GudangCabang'], [B::Baris($t['Produk']['Stok'], '4')], null, $permintaan->Uuid), $t['Pemilik']->Id);
    $permintaan->refresh();
    app(TutupPermintaanStok::class)->Jalankan($permintaan, 'Stok sisanya dialokasikan ke cabang lain', $t['ManajerAsal']->Id);

    BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
    $kabar = NotifikasiPengguna::query()->where('Kunci', "PermintaanStok:{$permintaan->Uuid}:Ditutup")->get();

    expect($kabar->pluck('IdPengguna')->all())->toContain($t['ManajerTujuan']->Id)
        ->and($kabar->firstWhere('IdPengguna', $t['ManajerTujuan']->Id)->Isi)->toContain('tidak akan dikirim')->toContain('dialokasikan');
});
