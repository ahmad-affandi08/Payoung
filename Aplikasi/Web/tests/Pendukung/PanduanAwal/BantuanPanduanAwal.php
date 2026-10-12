<?php

declare(strict_types=1);

namespace Tests\Pendukung\PanduanAwal;

use App\Domain\Bersama\Status\StatusDataMaster;
use App\Domain\Organisasi\Model\Outlet;
use App\Domain\Organisasi\Model\Pengguna;
use App\Domain\Pajak\Model\JenisPajak;
use App\Domain\Pajak\Model\TarifPajak;
use App\Domain\PanduanAwal\Aksi\TerapkanTemplateSektor;
use App\Domain\PanduanAwal\Data\HasilPenerapanTemplate;
use App\Domain\PanduanAwal\Enum\StatusTemplateSektor;
use App\Domain\PanduanAwal\Model\TemplateSektor;
use App\Domain\PanduanAwal\Model\TemplateSektorVersi;
use App\Domain\Pengelola\Katalog\Aksi\SiapkanKatalogBawaan;
use App\Domain\Pengelola\Referensi\Aksi\SiapkanPajakBawaan;
use App\Domain\Pengelola\Referensi\Aksi\SiapkanSatuanStandarBawaan;
use App\Domain\Tenant\Model\Tenant;
use RuntimeException;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\TestCase;

/**
 * Prasyarat F-01 untuk test: template sektor Terbit (isi dari `TemplateSektorAwal.json`), tarif pajak terbit, tenant
 * siap wizard, dan masuk sebagai anggota.
 */
final class BantuanPanduanAwal
{
    /**
     * @return array<string, mixed>
     */
    public static function IsiTemplateAwal(string $kode): array
    {
        $isi = file_get_contents(database_path('Data/TemplateSektorAwal.json'));
        $data = is_string($isi) ? json_decode($isi, true) : null;

        foreach (is_array($data) && is_array($data['Template'] ?? null) ? $data['Template'] : [] as $template) {
            if (is_array($template) && ($template['Kode'] ?? null) === $kode && is_array($template['Isi'] ?? null)) {
                return $template['Isi'];
            }
        }

        throw new RuntimeException("Template {$kode} tidak ada di TemplateSektorAwal.json");
    }

    /**
     * Versi Terbit baru untuk template `kode` (versi Terbit sebelumnya menjadi Usang). Data referensi yang dirujuk
     * template (jenis pajak, satuan standar, katalog fitur) ikut disiapkan.
     *
     * @param  array<string, mixed>  $ubahIsi  kunci Isi yang diganti
     */
    public static function TerbitkanTemplate(string $kode = 'FNB-CAF', array $ubahIsi = []): TemplateSektorVersi
    {
        app(SiapkanPajakBawaan::class)->Jalankan();
        app(SiapkanSatuanStandarBawaan::class)->Jalankan();
        app(SiapkanKatalogBawaan::class)->Jalankan();

        $nama = ['FNB-CAF' => 'Kafe / kedai kopi', 'RTL-GEN' => 'Retail umum / kelontong', 'FNB-QSR' => 'Restoran cepat saji'];
        $template = TemplateSektor::query()->firstOrCreate(['Kode' => $kode], ['Nama' => $nama[$kode] ?? $kode]);
        $lama = TemplateSektorVersi::query()->where('IdTemplateSektor', $template->Id)->where('Status', StatusTemplateSektor::Terbit->value)->first();

        if ($lama !== null) {
            $lama->fill(['Status' => StatusTemplateSektor::Usang, 'DiusangkanPada' => now()])->save();
        }

        return TemplateSektorVersi::query()->create([
            'IdTemplateSektor' => $template->Id,
            'Versi' => (int) TemplateSektorVersi::query()->where('IdTemplateSektor', $template->Id)->max('Versi') + 1,
            'Status' => StatusTemplateSektor::Terbit,
            'Isi' => array_replace(self::IsiTemplateAwal($kode), $ubahIsi),
            'HasilValidasi' => ['Lolos' => true, 'Galat' => []],
            'DiterbitkanPada' => now(),
        ])->load('TemplateSektor');
    }

    public static function TerbitkanTarif(string $kodeJenis, ?string $kodeWilayah, string $tarif, bool $biayaLayananMasukDpp = false, ?string $berlakuMulai = null, ?string $berlakuSampai = null): TarifPajak
    {
        app(SiapkanPajakBawaan::class)->Jalankan();
        $jenis = JenisPajak::query()->where('Kode', $kodeJenis)->firstOrFail();

        return TarifPajak::query()->create([
            'IdJenisPajak' => $jenis->Id,
            'Tarif' => $tarif,
            'PengaliDppPembilang' => $kodeJenis === 'Ppn' ? 11 : 1,
            'PengaliDppPenyebut' => $kodeJenis === 'Ppn' ? 12 : 1,
            'KodeWilayah' => $kodeWilayah,
            'BiayaLayananMasukDpp' => $biayaLayananMasukDpp,
            'BerlakuMulai' => $berlakuMulai ?? now('Asia/Jakarta')->subDays(10)->toDateString(),
            'BerlakuSampai' => $berlakuSampai,
            'Status' => StatusDataMaster::Terbit,
            'NomorDasarHukum' => $kodeWilayah === null ? 'UU HPP' : "Perda {$kodeWilayah}",
        ]);
    }

    /**
     * Tenant baru (F-00) beserta Outlet Utamanya; konteks tenant diatur.
     *
     * @return array{Tenant: Tenant, Pemilik: Pengguna, Outlet: Outlet}
     */
    public static function BuatTenant(string $namaUsaha = 'Kopi Nusantara', ?string $kodePaket = null): array
    {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanOrganisasi::BuatTenant($namaUsaha, $kodePaket);
        BantuanOrganisasi::AturKonteks($tenant->Id);

        return ['Tenant' => $tenant, 'Pemilik' => $pemilik, 'Outlet' => Outlet::query()->orderBy('Id')->firstOrFail()];
    }

    /**
     * Menerapkan template lewat Aksi dengan konteks tenant outlet.
     *
     * @param  list<string>  $sektorLain
     */
    public static function Terapkan(Outlet $outlet, string $kode = 'FNB-CAF', array $sektorLain = []): HasilPenerapanTemplate
    {
        BantuanOrganisasi::AturKonteks($outlet->IdTenant);

        return app(TerapkanTemplateSektor::class)->Jalankan($outlet, $kode, $sektorLain);
    }

    public static function Masuk(TestCase $tes, Pengguna $pengguna, Tenant|int $tenant): TestCase
    {
        return BantuanOrganisasi::Masuk($tes, $pengguna, $tenant instanceof Tenant ? $tenant->Id : $tenant);
    }
}
