<?php

declare(strict_types=1);

namespace App\Domain\PanduanAwal\Kueri;

use App\Domain\Organisasi\Model\Outlet;
use App\Domain\PanduanAwal\Data\DataIsiTemplate;
use App\Domain\PanduanAwal\Enum\StatusTemplateSektor;
use App\Domain\PanduanAwal\Layanan\PembacaIsiTemplate;
use App\Domain\PanduanAwal\Model\TemplateSektor;
use App\Domain\PanduanAwal\Model\TemplateSektorVersi;

/**
 * P-03 langkah 6 / BR-P03.1: tawaran pembaruan template sektor. Outlet yang template-nya sudah punya versi terbit lebih
 * baru daripada versi yang pernah diterapkan ditawari pembaruan. Isi tawaran = **perubahan dari versi yang dipakai ke
 * versi terbit** (yang ditambahkan), karena penerapannya bersifat aditif (BR-01.1): akun, kategori, satuan, kelompok
 * pajak, fitur, dan stasiun yang sudah dimiliki tenant tidak ditimpa, dan yang diubah tenant sendiri tidak dikembalikan.
 */
final class PembaruanTemplateSektor
{
    public function __construct(private readonly PembacaIsiTemplate $pembaca) {}

    /**
     * @return list<array{UuidOutlet: string, NamaOutlet: string, KodeTemplate: string, NamaTemplate: string, VersiSaatIni: int, VersiBaru: int, Perubahan: list<array{Bagian: string, Butir: list<string>}>}>
     */
    public function AmbilTawaran(?int $idOutlet = null): array
    {
        $outlet = Outlet::query()
            ->whereNotNull('IdTemplateSektorVersi')
            ->when($idOutlet !== null, fn ($k) => $k->whereKey($idOutlet))
            ->orderBy('Id')
            ->get(['Id', 'Uuid', 'Nama', 'TemplateSektor', 'IdTemplateSektorVersi']);
        $hasil = [];

        foreach ($outlet as $satu) {
            $tawaran = $this->BuatTawaran($satu);

            if ($tawaran !== null) {
                $hasil[] = $tawaran;
            }
        }

        return $hasil;
    }

    /**
     * @return array{UuidOutlet: string, NamaOutlet: string, KodeTemplate: string, NamaTemplate: string, VersiSaatIni: int, VersiBaru: int, Perubahan: list<array{Bagian: string, Butir: list<string>}>}|null
     */
    public function BuatTawaran(Outlet $outlet): ?array
    {
        $lama = TemplateSektorVersi::query()->with('TemplateSektor')->whereKey($outlet->IdTemplateSektorVersi)->first();

        if ($lama === null) {
            return null;
        }

        $baru = $this->CariTerbit($lama->TemplateSektor);

        if ($baru === null || $baru->Versi <= $lama->Versi) {
            return null;
        }

        return [
            'UuidOutlet' => $outlet->Uuid,
            'NamaOutlet' => $outlet->Nama,
            'KodeTemplate' => $lama->TemplateSektor->Kode,
            'NamaTemplate' => $lama->TemplateSektor->Nama,
            'VersiSaatIni' => $lama->Versi,
            'VersiBaru' => $baru->Versi,
            'Perubahan' => self::HitungPerubahan($this->pembaca->Baca($lama->Isi), $this->pembaca->Baca($baru->Isi)),
        ];
    }

    /**
     * Butir yang ada di versi baru tetapi tidak di versi lama, per bagian. Bagian tanpa tambahan tidak disertakan.
     *
     * @return list<array{Bagian: string, Butir: list<string>}>
     */
    public static function HitungPerubahan(DataIsiTemplate $lama, DataIsiTemplate $baru): array
    {
        $bagian = [
            'Akun' => self::Selisih(
                array_map(fn ($a): string => $a->kode, $lama->akun),
                array_map(fn ($a): string => "{$a->kode} {$a->nama}", $baru->akun),
                array_map(fn ($a): string => $a->kode, $baru->akun),
            ),
            'Pemetaan akun' => array_values(array_map(
                fn (string $k): string => $k,
                array_diff(array_keys($baru->pemetaanAkun), array_keys($lama->pemetaanAkun)),
            )),
            'Kategori' => self::Selisih($lama->kategori, $baru->kategori, $baru->kategori),
            'Satuan' => self::Selisih($lama->kodeSatuan, $baru->kodeSatuan, $baru->kodeSatuan),
            'Kelompok pajak' => self::Selisih(
                array_map(fn ($k): string => $k->nama, $lama->kelompokPajak),
                array_map(fn ($k): string => $k->nama, $baru->kelompokPajak),
                array_map(fn ($k): string => $k->nama, $baru->kelompokPajak),
            ),
            'Fitur' => self::Selisih($lama->kunciFitur, $baru->kunciFitur, $baru->kunciFitur),
            'Mode kasir' => self::Selisih($lama->modeKasir, $baru->modeKasir, $baru->modeKasir),
            'Stasiun dapur' => self::Selisih($lama->stasiunDapur, $baru->stasiunDapur, $baru->stasiunDapur),
        ];
        $hasil = [];

        foreach ($bagian as $nama => $butir) {
            if ($butir !== []) {
                $hasil[] = ['Bagian' => $nama, 'Butir' => array_values($butir)];
            }
        }

        return $hasil;
    }

    /**
     * @param  list<string>  $kunciLama  kunci pembanding versi lama
     * @param  list<string>  $label  label tampilan versi baru (urutan sama dengan [$kunciBaru])
     * @param  list<string>  $kunciBaru  kunci pembanding versi baru
     * @return list<string>
     */
    private static function Selisih(array $kunciLama, array $label, array $kunciBaru): array
    {
        $hasil = [];

        foreach ($kunciBaru as $i => $kunci) {
            if (! in_array($kunci, $kunciLama, true)) {
                $hasil[] = $label[$i];
            }
        }

        return $hasil;
    }

    private function CariTerbit(TemplateSektor $template): ?TemplateSektorVersi
    {
        return TemplateSektorVersi::query()
            ->where('IdTemplateSektor', $template->Id)
            ->where('Status', StatusTemplateSektor::Terbit->value)
            ->first();
    }
}
