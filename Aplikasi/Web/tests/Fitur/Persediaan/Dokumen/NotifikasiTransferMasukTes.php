<?php

declare(strict_types=1);

use App\Domain\Organisasi\Enum\JenisGudang;
use App\Domain\Organisasi\Enum\JenisNotifikasiPengguna;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Organisasi\Model\NotifikasiDibisukan;
use App\Domain\Organisasi\Model\NotifikasiPengguna;
use App\Domain\Organisasi\Model\OutletPengguna;
use App\Domain\Persediaan\Aksi\KirimTransferStok;
use App\Domain\Persediaan\Enum\MetodeHpp;
use Carbon\CarbonImmutable;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Persediaan\BantuanDokumenPersediaan as B;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Persediaan\BantuanStokAwal;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * F-05b, OWN-03 / PLT-08: saat transfer stok dikirim, manajer outlet tujuan (izin persediaan.kelola, outlet dalam
 * cakupannya) mendapat kabar "kiriman stok masuk" di pusat notifikasi; pengirim dan manajer outlet lain tidak.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

/** @return list<NotifikasiPengguna> */
function KabarTransferMasuk(): array
{
    return NotifikasiPengguna::query()->where('Jenis', JenisNotifikasiPengguna::TransferStokMasuk->value)->orderBy('Id')->get()->all();
}

function SiapkanKirimanStok(): array
{
    $t = BantuanPersediaan::SiapkanTenant(metodeHpp: MetodeHpp::RataRata);
    $p = BantuanPersediaan::BuatProdukSemuaJenis($t['Pcs'], $t['Kg']);
    $cabang = B::BuatOutlet();
    $lain = B::BuatOutlet('KARTA', 'Cabang Kartasura');
    $gudangCabang = BantuanPersediaan::BuatGudang($cabang, 'Toko Cabang Solo Baru', JenisGudang::Toko);
    BantuanStokAwal::BuatDanPosting($t['Gudang'], [
        BantuanStokAwal::Baris($p['Stok'], '100', '38500'),
    ], $t['Pemilik']->Id, CarbonImmutable::now('Asia/Jakarta')->subDays(3)->format('Y-m-d'));

    $manajerTujuan = BantuanOrganisasi::TambahAnggota($t['Tenant']->Id, PeranTenantBawaan::ManajerOutlet, semuaOutlet: false);
    $manajerLain = BantuanOrganisasi::TambahAnggota($t['Tenant']->Id, PeranTenantBawaan::ManajerOutlet, semuaOutlet: false);
    $peran = BantuanOrganisasi::Peran($t['Tenant']->Id, PeranTenantBawaan::ManajerOutlet);

    foreach ([[$manajerTujuan, $cabang], [$manajerLain, $lain]] as [$pengguna, $outlet]) {
        OutletPengguna::query()->create(['IdOutlet' => $outlet->Id, 'IdPengguna' => $pengguna->Id, 'IdPeran' => $peran->Id]);
    }

    return [...$t, 'Produk' => $p, 'GudangCabang' => $gudangCabang, 'ManajerTujuan' => $manajerTujuan, 'ManajerLain' => $manajerLain];
}

it('transfer dikirim: manajer outlet tujuan dikabari sekali; pengirim dan manajer outlet lain tidak', function (): void {
    $t = SiapkanKirimanStok();
    $draf = B::DrafTransfer($t['Gudang'], $t['GudangCabang'], [B::Baris($t['Produk']['Stok'], '30')]);

    expect(KabarTransferMasuk())->toBe([]);

    $transfer = app(KirimTransferStok::class)->Jalankan($draf, $t['Pemilik']->Id);
    // Kirim ulang dokumen yang sudah dikirim idempoten dan tidak menggandakan kabar.
    app(KirimTransferStok::class)->Jalankan($transfer, $t['Pemilik']->Id);

    BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
    $kabar = KabarTransferMasuk();

    expect($kabar)->toHaveCount(1)
        ->and($kabar[0]->IdPengguna)->toBe($t['ManajerTujuan']->Id)
        ->and($kabar[0]->Kunci)->toBe('TransferStokMasuk:'.$transfer->Uuid)
        ->and($kabar[0]->Judul)->toContain('Toko Cabang Solo Baru')
        ->and($kabar[0]->Isi)->toContain((string) $transfer->Nomor)
        ->and($kabar[0]->Data['Href'])->toBe("/kelola/persediaan/transfer/{$transfer->Uuid}");
});

it('kabar kiriman stok bisa dibisukan penerimanya', function (): void {
    $t = SiapkanKirimanStok();
    NotifikasiDibisukan::query()->create([
        'IdPengguna' => $t['ManajerTujuan']->Id,
        'Jenis' => JenisNotifikasiPengguna::TransferStokMasuk->value,
    ]);

    app(KirimTransferStok::class)->Jalankan(B::DrafTransfer($t['Gudang'], $t['GudangCabang'], [B::Baris($t['Produk']['Stok'], '10')]), $t['Pemilik']->Id);

    BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
    expect(KabarTransferMasuk())->toBe([]);
});
