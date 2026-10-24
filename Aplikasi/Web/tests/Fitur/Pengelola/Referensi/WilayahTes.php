<?php

declare(strict_types=1);

use App\Domain\Pengelola\TimInternal\Enum\PeranPengelolaBawaan;
use App\Domain\Pengelola\TimInternal\Model\LogAuditPengelola;
use App\Domain\Referensi\Enum\TingkatWilayah;
use App\Domain\Referensi\Enum\ZonaWaktu;
use App\Domain\Referensi\Model\Wilayah;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Pengelola\BantuanPengelola;

function BuatProvinsiJateng(): Wilayah
{
    return Wilayah::query()->create(['Kode' => '33', 'Nama' => 'Jawa Tengah', 'Tingkat' => TingkatWilayah::Provinsi, 'ZonaWaktu' => ZonaWaktu::Wib]);
}

describe('Data wilayah (P-02)', function (): void {
    it('Konten & Legal menambah kabupaten/kota di bawah provinsi yang ada, tercatat di audit', function (): void {
        BuatProvinsiJateng();
        $konten = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::KontenLegal);

        $this->actingAs($konten, 'pengelola')
            ->withSession(BantuanPengelola::SesiTerverifikasi())
            ->post(BantuanPengelola::Url('/referensi/wilayah'), [
                'Kode' => '33.74', 'Nama' => 'Kota Semarang', 'Tingkat' => 'KabupatenKota', 'KodeInduk' => '33', 'ZonaWaktu' => 'WIB',
            ])
            ->assertSessionHasNoErrors();

        $wilayah = Wilayah::query()->where('Kode', '33.74')->sole();
        expect($wilayah->KodeInduk)->toBe('33')->and($wilayah->ZonaWaktu)->toBe(ZonaWaktu::Wib);
        $this->assertDatabaseHas('LogAuditPengelola', ['Aksi' => 'referensi.wilayah.buat', 'IdPenggunaPengelola' => $konten->Id, 'IdObjek' => $wilayah->Id]);
    });

    it('menolak kode yang tidak sesuai format atau induk yang tidak cocok', function (array $data, string $bidang): void {
        BuatProvinsiJateng();
        $konten = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::KontenLegal);

        $this->actingAs($konten, 'pengelola')
            ->withSession(BantuanPengelola::SesiTerverifikasi())
            ->post(BantuanPengelola::Url('/referensi/wilayah'), [...['Nama' => 'Uji', 'ZonaWaktu' => 'WIB'], ...$data])
            ->assertSessionHasErrors($bidang);

        expect(Wilayah::query()->count())->toBe(1);
    })->with([
        'format kab/kota salah' => [['Kode' => '3374', 'Tingkat' => 'KabupatenKota', 'KodeInduk' => '33'], 'Kode'],
        'induk belum ada' => [['Kode' => '34.01', 'Tingkat' => 'KabupatenKota', 'KodeInduk' => '34'], 'KodeInduk'],
        'awalan beda dengan induk' => [['Kode' => '34.01', 'Tingkat' => 'KabupatenKota', 'KodeInduk' => '33'], 'KodeInduk'],
        'provinsi punya induk' => [['Kode' => '34', 'Tingkat' => 'Provinsi', 'KodeInduk' => '33'], 'KodeInduk'],
        'kode sudah ada' => [['Kode' => '33', 'Tingkat' => 'Provinsi'], 'Kode'],
    ]);

    it('mengubah nama & zona waktu tetapi kode tidak bisa diubah', function (): void {
        $provinsi = BuatProvinsiJateng();
        $konten = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::KontenLegal);
        $this->actingAs($konten, 'pengelola')->withSession(BantuanPengelola::SesiTerverifikasi());

        $this->put(BantuanPengelola::Url('/referensi/wilayah/33'), ['Kode' => '33', 'Nama' => 'Provinsi Jawa Tengah', 'Tingkat' => 'Provinsi', 'ZonaWaktu' => 'WIB'])
            ->assertSessionHasNoErrors();
        $this->put(BantuanPengelola::Url('/referensi/wilayah/33'), ['Kode' => '35', 'Nama' => 'X', 'Tingkat' => 'Provinsi', 'ZonaWaktu' => 'WIB'])
            ->assertSessionHasErrors('Kode');

        expect($provinsi->refresh()->Nama)->toBe('Provinsi Jawa Tengah')->and($provinsi->Kode)->toBe('33');
        $log = LogAuditPengelola::query()->where('Aksi', 'referensi.wilayah.ubah')->sole();
        expect($log->NilaiLama['Nama'])->toBe('Jawa Tengah')->and($log->NilaiBaru['Nama'])->toBe('Provinsi Jawa Tengah');
    });

    it('semua peran bisa melihat, hanya Konten & Legal dan Super Admin yang bisa mengubah', function (PeranPengelolaBawaan $peran, bool $bolehUbah): void {
        BuatProvinsiJateng();
        $anggota = BantuanPengelola::BuatAnggota($peran);
        $this->actingAs($anggota, 'pengelola')->withSession(BantuanPengelola::SesiTerverifikasi());

        $this->get(BantuanPengelola::Url('/referensi/wilayah?cari=jawa'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $halaman) => $halaman->component('Pengelola/Referensi/Wilayah')->has('Wilayah.Data', 1));

        $respons = $this->put(BantuanPengelola::Url('/referensi/wilayah/33'), ['Kode' => '33', 'Nama' => 'Ubah', 'Tingkat' => 'Provinsi', 'ZonaWaktu' => 'WIB']);
        $bolehUbah ? $respons->assertSessionHasNoErrors() : $respons->assertForbidden();
    })->with([
        [PeranPengelolaBawaan::SuperAdmin, true],
        [PeranPengelolaBawaan::KontenLegal, true],
        [PeranPengelolaBawaan::Keuangan, false],
        [PeranPengelolaBawaan::Dukungan, false],
        [PeranPengelolaBawaan::Teknis, false],
        [PeranPengelolaBawaan::MitraPenjualan, false],
        [PeranPengelolaBawaan::Analis, false],
    ]);

    it('memfilter tingkat dengan saring[Tingkat] dan meng-escape karakter wildcard pada pencarian kode', function (): void {
        BuatProvinsiJateng();
        Wilayah::query()->create(['Kode' => '33.74', 'Nama' => 'Kota Semarang', 'Tingkat' => TingkatWilayah::KabupatenKota, 'KodeInduk' => '33', 'ZonaWaktu' => ZonaWaktu::Wib]);
        $this->actingAs(BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Analis), 'pengelola')->withSession(BantuanPengelola::SesiTerverifikasi());

        $this->get(BantuanPengelola::Url('/referensi/wilayah?saring[Tingkat]=KabupatenKota'))
            ->assertInertia(fn (AssertableInertia $halaman) => $halaman->has('Wilayah.Data', 1)->where('Wilayah.Data.0.Kode', '33.74'));
        $this->get(BantuanPengelola::Url('/referensi/wilayah?cari=%25'))
            ->assertInertia(fn (AssertableInertia $halaman) => $halaman->has('Wilayah.Data', 0)->where('Wilayah.Meta.Total', 0));
    });

    it('TabelData (D-16): JSON di URL yang sama dengan cari nama/awalan kode, filter tingkat, urut, dan Meta', function (): void {
        BuatProvinsiJateng();
        Wilayah::query()->create(['Kode' => '33.74', 'Nama' => 'Kota Semarang', 'Tingkat' => TingkatWilayah::KabupatenKota, 'KodeInduk' => '33', 'ZonaWaktu' => ZonaWaktu::Wib]);
        Wilayah::query()->create(['Kode' => '33.22', 'Nama' => 'Kabupaten Semarang', 'Tingkat' => TingkatWilayah::KabupatenKota, 'KodeInduk' => '33', 'ZonaWaktu' => ZonaWaktu::Wib]);
        $this->actingAs(BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Analis), 'pengelola')->withSession(BantuanPengelola::SesiTerverifikasi());

        $this->getJson(BantuanPengelola::Url('/referensi/wilayah?cari=semarang'))
            ->assertOk()
            ->assertJsonPath('Meta.Total', 2)
            ->assertJsonPath('Meta.JumlahHalaman', 1)
            ->assertJsonPath('Data.0.Kode', '33.22')
            ->assertJsonPath('Data.1.Kode', '33.74');
        $this->getJson(BantuanPengelola::Url('/referensi/wilayah?cari=semarang&urut=-Nama'))
            ->assertOk()
            ->assertJsonPath('Data.0.Nama', 'Kota Semarang');
        $this->getJson(BantuanPengelola::Url('/referensi/wilayah?cari=33.7'))
            ->assertOk()
            ->assertJsonPath('Meta.Total', 1)
            ->assertJsonPath('Data.0.Nama', 'Kota Semarang');
        $this->getJson(BantuanPengelola::Url('/referensi/wilayah?saring[Tingkat]=Provinsi&perHalaman=50'))
            ->assertOk()
            ->assertJsonPath('Meta.Total', 1)
            ->assertJsonPath('Meta.PerHalaman', 50)
            ->assertJsonPath('Data.0.Kode', '33');
    });

    it('tidak bisa dibuka dari domain tenant', function (): void {
        $this->get('http://localhost/referensi/wilayah')->assertNotFound();
    });
});
