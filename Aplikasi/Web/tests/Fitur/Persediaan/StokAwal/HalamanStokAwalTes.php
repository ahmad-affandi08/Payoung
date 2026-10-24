<?php

declare(strict_types=1);

use App\Domain\Akuntansi\Model\PemetaanAkun;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Pendukung\Katalog\BantuanHarga;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Persediaan\BantuanStokAwal;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * F-05a Tim C: props halaman Inertia stok awal (kontrak `Tipe/Persediaan.ts`: PropsDaftarStokAwal, PropsFormStokAwal,
 * PropsDetailStokAwal). Komponen halaman milik Tim G.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

describe('F-05a halaman stok awal', function (): void {
    it('daftar: hanya dokumen di outlet yang boleh diakses, dengan filter status, izin, dan kesiapan akun', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();
        $p = BantuanPersediaan::BuatProdukSemuaJenis($t['Pcs'], $t['Kg']);
        $cabang = BantuanHarga::BuatOutlet('SLO-02', 'Cabang Solo Baru');
        $gudangCabang = BantuanPersediaan::BuatGudang($cabang, 'Gudang Cabang Solo Baru');
        $utama = BantuanStokAwal::BuatDanPosting($t['Gudang'], [BantuanStokAwal::Baris($p['Stok'], '10', '38000')], $t['Pemilik']->Id);
        $drafCabang = BantuanStokAwal::BuatDraf($gudangCabang, [BantuanStokAwal::Baris($p['Stok'], '4', '38000')]);
        BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id);

        $this->get('/kelola/persediaan/stok-awal')->assertOk()->assertInertia(fn (Assert $halaman) => $halaman
            ->component('Kelola/Persediaan/StokAwal/Daftar')
            ->where('StokAwal.Meta.Total', 2)
            ->has('OpsiStatus')
            ->where('Izin.PostingStokAwal', true)
            ->where('KesiapanAkun.Siap', true)
            ->has('OpsiGudang', 2));

        $this->get('/kelola/persediaan/stok-awal?saring[Status]=Diposting')->assertInertia(fn (Assert $halaman) => $halaman
            ->component('Kelola/Persediaan/StokAwal/Daftar')
            ->where('StokAwal.Meta.Total', 1)
            ->where('StokAwal.Data.0.Uuid', $utama->Uuid)
            ->where('StokAwal.Data.0.Nomor', $utama->Nomor)
            ->where('StokAwal.Data.0.LabelStatus', 'Diposting')
            ->where('StokAwal.Data.0.TotalNilai', '380000.00')
            ->where('StokAwal.Data.0.NamaGudang', $t['Gudang']->Nama));

        $this->get("/kelola/persediaan/stok-awal?saring[Gudang]={$gudangCabang->Uuid}&cari=minyak")->assertInertia(fn (Assert $halaman) => $halaman
            ->component('Kelola/Persediaan/StokAwal/Daftar')
            ->where('StokAwal.Meta.Total', 1)
            ->where('StokAwal.Data.0.Uuid', $drafCabang->Uuid));
        $this->get('/kelola/persediaan/stok-awal?cari=tidak-ada-produk-ini')->assertInertia(fn (Assert $halaman) => $halaman
            ->component('Kelola/Persediaan/StokAwal/Daftar')
            ->where('StokAwal.Meta.Total', 0));

        $this->getJson('/kelola/persediaan/stok-awal?saring[Status]=Draf,Diposting&urut=TotalNilai')->assertOk()
            ->assertJsonPath('Meta.Total', 2)
            ->assertJsonPath('Data.0.Uuid', $drafCabang->Uuid);

        $manajer = BantuanHarga::TambahAnggotaOutlet($t['Tenant']->Id, PeranTenantBawaan::ManajerOutlet, $cabang);
        BantuanOrganisasi::Masuk($this, $manajer, $t['Tenant']->Id);
        $this->get('/kelola/persediaan/stok-awal')->assertInertia(fn (Assert $halaman) => $halaman
            ->component('Kelola/Persediaan/StokAwal/Daftar')
            ->where('StokAwal.Meta.Total', 1)
            ->where('StokAwal.Data.0.Uuid', $drafCabang->Uuid));
    });

    it('form buat & ubah: opsi lokasi aktif, batas, dan isi draf dengan versi', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();
        $p = BantuanPersediaan::BuatProdukSemuaJenis($t['Pcs'], $t['Kg']);
        $draf = BantuanStokAwal::BuatDraf($t['Gudang'], [BantuanStokAwal::Baris($p['BahanBaku'], '12.5', '14750')]);
        BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id);

        $this->get('/kelola/persediaan/stok-awal/buat')->assertOk()->assertInertia(fn (Assert $halaman) => $halaman
            ->component('Kelola/Persediaan/StokAwal/Form')
            ->where('Mode', 'Buat')
            ->where('StokAwal', null)
            ->where('BatasBaris', 2000)
            ->where('MaksimalNomorSeriPerBaris', 1000)
            ->where('WajibKedaluwarsaBatch', true)
            ->has('HariIni')
            ->has('OpsiGudang', 1));

        $this->get("/kelola/persediaan/stok-awal/{$draf->Uuid}/ubah")->assertOk()->assertInertia(fn (Assert $halaman) => $halaman
            ->component('Kelola/Persediaan/StokAwal/Form')
            ->where('Mode', 'Ubah')
            ->where('StokAwal.Uuid', $draf->Uuid)
            ->where('StokAwal.UuidGudang', $t['Gudang']->Uuid)
            ->where('StokAwal.VersiDiubahPada', $draf->fresh()?->DiubahPada?->toIso8601String())
            ->where('StokAwal.Baris.0.UuidProduk', $p['BahanBaku']->Uuid)
            ->where('StokAwal.Baris.0.SimbolSatuan', 'kg')
            ->where('StokAwal.Baris.0.BolehDesimal', true)
            ->where('StokAwal.Baris.0.Jumlah', '12.5000')
            ->where('StokAwal.Baris.0.HppSatuan', '14750.000000')
            ->where('StokAwal.Baris.0.SaldoDiGudang', '0.0000'));
    });

    it('detail: baris, jurnal, riwayat, tindakan per izin; kesiapan akun menandai peran yang belum dipetakan', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();
        $p = BantuanPersediaan::BuatProdukSemuaJenis($t['Pcs'], $t['Kg']);
        $dokumen = BantuanStokAwal::BuatDanPosting($t['Gudang'], [
            BantuanStokAwal::Baris($p['Seri'], '2', '650000', nomorSeri: ['RC18-0001', 'RC18-0002']),
        ], $t['Pemilik']->Id);
        $draf = BantuanStokAwal::BuatDraf($t['Gudang'], [BantuanStokAwal::Baris($p['BahanBaku'], '3', '14750')]);
        BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id);

        $this->get("/kelola/persediaan/stok-awal/{$dokumen->Uuid}")->assertOk()->assertInertia(fn (Assert $halaman) => $halaman
            ->component('Kelola/Persediaan/StokAwal/Detail')
            ->where('StokAwal.Nomor', $dokumen->Nomor)
            ->where('StokAwal.Status', 'Diposting')
            ->where('StokAwal.TotalNilai', '1300000.00')
            ->where('StokAwal.DipostingOleh', $t['Pemilik']->Nama)
            ->where('Baris.0.Pelacakan', 'Seri')
            ->where('Baris.0.NomorSeri', ['RC18-0001', 'RC18-0002'])
            ->where('Jurnal.0.TotalDebit', '1300000.00')
            ->where('Jurnal.0.Pembalik', false)
            ->where('Riwayat.1.StatusKe', 'Diposting')
            ->where('Tindakan', ['Ubah' => false, 'Buang' => false, 'Posting' => false, 'Batalkan' => true])
            ->where('BatasPostingLangsung', 300));

        PemetaanAkun::query()->where('Kunci', 'PersediaanBahanBaku')->delete();
        BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id, PeranTenantBawaan::StafGudang);
        $this->get("/kelola/persediaan/stok-awal/{$draf->Uuid}")->assertOk()->assertInertia(fn (Assert $halaman) => $halaman
            ->component('Kelola/Persediaan/StokAwal/Detail')
            ->where('Tindakan', ['Ubah' => true, 'Buang' => true, 'Posting' => false, 'Batalkan' => false])
            ->where('KesiapanAkun.Siap', false)
            ->where('KesiapanAkun.PeranBelumDipetakan.0.Kunci', 'PersediaanBahanBaku'));
    });

    it('dokumen tenant lain = 404 di halaman detail dan ubah', function (): void {
        $tA = BantuanPersediaan::SiapkanTenant();
        $pA = BantuanPersediaan::BuatProdukSemuaJenis($tA['Pcs'], $tA['Kg']);
        $drafA = BantuanStokAwal::BuatDraf($tA['Gudang'], [BantuanStokAwal::Baris($pA['Stok'], '1', '38000')]);
        $tB = BantuanPersediaan::SiapkanTenant('Warung Madura Barokah');
        BantuanPersediaan::MasukSebagai($this, $tB['Tenant']->Id);

        $this->get("/kelola/persediaan/stok-awal/{$drafA->Uuid}")->assertNotFound();
        $this->get("/kelola/persediaan/stok-awal/{$drafA->Uuid}/ubah")->assertNotFound();
    });
});
