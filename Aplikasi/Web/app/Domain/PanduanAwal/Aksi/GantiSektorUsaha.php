<?php

declare(strict_types=1);

namespace App\Domain\PanduanAwal\Aksi;

use App\Domain\Akuntansi\Aksi\BersihkanAkunTemplate;
use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Data\HasilPembersihanTemplate;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Katalog\Aksi\BersihkanKategoriTemplate;
use App\Domain\Organisasi\Aksi\BersihkanStasiunDapurTemplate;
use App\Domain\Organisasi\Model\Outlet;
use App\Domain\PanduanAwal\Data\DataIsiTemplate;
use App\Domain\PanduanAwal\Kueri\TemplateTerbit;
use App\Domain\PanduanAwal\Layanan\PembacaIsiTemplate;
use App\Domain\PanduanAwal\Model\TemplateSektorVersi;
use App\Domain\Penjualan\Enum\ModeKasir;
use App\Domain\Tenant\Aksi\GantiSektorTenant;
use App\Domain\Tenant\Aksi\LepasFiturOutletTemplate;
use App\Domain\Tenant\Kueri\FiturOutlet;
use Illuminate\Support\Facades\DB;

/**
 * F-01 / P-03 (D-165): Pemilik mengganti jenis usaha satu outlet, misalnya karena salah pilih sektor saat daftar.
 *
 * Penerapan template tetap aditif (BR-01.1): template baru ditambahkan dengan `TerapkanTemplateSektor`. Sesudahnya
 * sektor lama dilepas dari daftar sektor tenant, mode kasir mengikuti template baru, dan sisa template lama
 * dibersihkan **hanya bila belum dipakai** (akun tanpa jurnal/pemetaan, kategori tanpa produk, stasiun dapur
 * diarsipkan, fitur outlet dilepas). Yang sudah dipakai dipertahankan dan disebut alasannya. Pratinjau memakai logika
 * yang sama tanpa menulis apa pun.
 */
final class GantiSektorUsaha
{
    public function __construct(
        private readonly TemplateTerbit $templateTerbit,
        private readonly PembacaIsiTemplate $pembaca,
        private readonly TerapkanTemplateSektor $terapkan,
        private readonly BersihkanAkunTemplate $bersihkanAkun,
        private readonly BersihkanKategoriTemplate $bersihkanKategori,
        private readonly BersihkanStasiunDapurTemplate $bersihkanStasiun,
        private readonly LepasFiturOutletTemplate $lepasFitur,
        private readonly GantiSektorTenant $gantiSektor,
        private readonly FiturOutlet $fiturOutlet,
        private readonly PencatatAudit $audit,
    ) {}

    /**
     * @return array<string, mixed> bentuk ringkasan sama dengan `Jalankan`, tanpa mengubah data
     */
    public function Pratinjau(string $uuidOutlet, string $kodeBaru): array
    {
        [$outlet, $versiBaru, $isiBaru, $versiLama, $isiLama] = $this->Rencanakan($uuidOutlet, $kodeBaru);

        return $this->Susun($outlet, $versiBaru, $isiBaru, $versiLama, $this->Bersihkan($outlet, $isiLama, $isiBaru, false));
    }

    /**
     * @return array<string, mixed>
     */
    public function Jalankan(string $uuidOutlet, string $kodeBaru, bool $bersihkan): array
    {
        [$outlet, $versiBaru, $isiBaru, $versiLama, $isiLama] = $this->Rencanakan($uuidOutlet, $kodeBaru);
        $kodeLama = $outlet->TemplateSektor;

        return DB::transaction(function () use ($outlet, $kodeBaru, $kodeLama, $versiBaru, $isiBaru, $versiLama, $isiLama, $bersihkan): array {
            $this->terapkan->Jalankan($outlet, $kodeBaru);
            $lamaMasihDipakai = $kodeLama !== null && Outlet::query()->where('TemplateSektor', $kodeLama)->whereKeyNot($outlet->Id)->exists();
            $this->gantiSektor->Jalankan($outlet->IdTenant, $kodeLama, $kodeBaru, $lamaMasihDipakai);

            // Mode kasir selalu mengikuti template baru; sisa data lama hanya dibersihkan bila Pemilik memilihnya.
            $fiturDilepas = $this->lepasFitur->Jalankan($outlet->Id, $bersihkan && $isiLama !== null ? $isiLama->kunciFitur : [], $isiBaru->kunciFitur, $this->KonfigurasiPos($isiBaru), true);
            $hasil = $bersihkan ? $this->Bersihkan($outlet, $isiLama, $isiBaru, true) : $this->BersihkanKosong();
            $hasil['Fitur'] = $fiturDilepas;

            $ringkasan = $this->Susun($outlet, $versiBaru, $isiBaru, $versiLama, $hasil);
            $this->audit->Catat('sektor.ganti', $outlet, nilaiLama: ['TemplateSektor' => $kodeLama], nilaiBaru: [
                'TemplateSektor' => $kodeBaru,
                'Bersihkan' => $bersihkan,
                'Dibersihkan' => $ringkasan['Dibersihkan'],
            ]);

            return $ringkasan;
        });
    }

    /**
     * @return array{0: Outlet, 1: TemplateSektorVersi, 2: DataIsiTemplate, 3: TemplateSektorVersi|null, 4: DataIsiTemplate|null}
     */
    private function Rencanakan(string $uuidOutlet, string $kodeBaru): array
    {
        $outlet = Outlet::query()->where('Uuid', $uuidOutlet)->first()
            ?? throw new PelanggaranAturanBisnis('OutletTidakDitemukan', 'Outlet tidak ditemukan.', 'UuidOutlet', 404);

        if ($outlet->TemplateSektor === $kodeBaru) {
            throw new PelanggaranAturanBisnis('SektorSama', 'Outlet ini sudah memakai jenis usaha tersebut. Pilih jenis usaha yang lain.', 'KodeTemplate');
        }

        $versiBaru = $this->templateTerbit->Cari($kodeBaru)
            ?? throw new PelanggaranAturanBisnis('TemplateTidakTersedia', 'Jenis usaha ini belum tersedia. Pilih yang lain.', 'KodeTemplate');
        $versiLama = $this->templateTerbit->CariVersi($outlet->IdTemplateSektorVersi);

        return [
            $outlet,
            $versiBaru,
            $this->pembaca->Baca($versiBaru->Isi),
            $versiLama,
            $versiLama === null ? null : $this->pembaca->Baca($versiLama->Isi),
        ];
    }

    /**
     * @return array{Akun: HasilPembersihanTemplate, Kategori: HasilPembersihanTemplate, StasiunDapur: HasilPembersihanTemplate, Fitur: list<string>}
     */
    private function Bersihkan(Outlet $outlet, ?DataIsiTemplate $lama, DataIsiTemplate $baru, bool $terapkan): array
    {
        if ($lama === null) {
            return $this->BersihkanKosong();
        }

        $kodeBaru = array_map(fn ($akun): string => $akun->kode, $baru->akun);
        $kodeSisa = array_values(array_diff(array_map(fn ($akun): string => $akun->kode, $lama->akun), $kodeBaru));

        return [
            'Akun' => $this->bersihkanAkun->Jalankan($kodeSisa, $terapkan),
            'Kategori' => $this->bersihkanKategori->Jalankan($this->SelisihNama($lama->kategori, $baru->kategori), $terapkan),
            'StasiunDapur' => $this->bersihkanStasiun->Jalankan($this->SelisihNama($lama->stasiunDapur, $baru->stasiunDapur), $terapkan),
            // Saat terapkan, fitur sudah dilepas sebelum pemanggilan ini; pratinjau menghitungnya tanpa menulis.
            'Fitur' => $terapkan ? [] : $this->lepasFitur->Jalankan($outlet->Id, $lama->kunciFitur, $baru->kunciFitur, null, false),
        ];
    }

    /**
     * @return array{Akun: HasilPembersihanTemplate, Kategori: HasilPembersihanTemplate, StasiunDapur: HasilPembersihanTemplate, Fitur: list<string>}
     */
    private function BersihkanKosong(): array
    {
        return ['Akun' => new HasilPembersihanTemplate, 'Kategori' => new HasilPembersihanTemplate, 'StasiunDapur' => new HasilPembersihanTemplate, 'Fitur' => []];
    }

    /**
     * @param  list<string>  $lama
     * @param  list<string>  $baru
     * @return list<string>
     */
    private function SelisihNama(array $lama, array $baru): array
    {
        $dipakaiBaru = array_map(fn (string $nama): string => mb_strtolower(trim($nama)), $baru);

        return array_values(array_filter($lama, fn (string $nama): bool => ! in_array(mb_strtolower(trim($nama)), $dipakaiBaru, true)));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function KonfigurasiPos(DataIsiTemplate $isi): ?array
    {
        return $isi->modeKasir === [] ? null : ['ModeKasir' => $isi->modeKasir, 'ModeKasirDefault' => $isi->modeKasirDefault];
    }

    /**
     * @param  array{Akun: HasilPembersihanTemplate, Kategori: HasilPembersihanTemplate, StasiunDapur: HasilPembersihanTemplate, Fitur: list<string>}  $hasil
     * @return array<string, mixed>
     */
    private function Susun(Outlet $outlet, TemplateSektorVersi $versiBaru, DataIsiTemplate $isiBaru, ?TemplateSektorVersi $versiLama, array $hasil): array
    {
        $namaFitur = $this->fiturOutlet->AmbilNamaFitur($hasil['Fitur']);
        $dipertahankan = [];

        foreach (['Akun' => 'Akun', 'Kategori' => 'Kategori'] as $kunci => $jenis) {
            foreach ($hasil[$kunci]->dipertahankan as $satu) {
                $dipertahankan[] = ['Jenis' => $jenis, 'Nama' => $satu['Nama'], 'Alasan' => $satu['Alasan']];
            }
        }

        return [
            'UuidOutlet' => $outlet->Uuid,
            'NamaOutlet' => $outlet->Nama,
            'Lama' => $versiLama === null ? null : ['Kode' => $versiLama->TemplateSektor->Kode, 'Nama' => $versiLama->TemplateSektor->Nama, 'Versi' => $versiLama->Versi],
            'Baru' => [
                'Kode' => $versiBaru->TemplateSektor->Kode,
                'Nama' => $versiBaru->TemplateSektor->Nama,
                'Versi' => $versiBaru->Versi,
                'JumlahAkun' => count($isiBaru->akun),
                'JumlahKategori' => count($isiBaru->kategori),
                'JumlahFitur' => count($isiBaru->kunciFitur),
                'ModeKasir' => array_values(array_map(fn (string $mode): string => ModeKasir::tryFrom($mode)?->AmbilLabel() ?? $mode, $isiBaru->modeKasir)),
            ],
            'Dibersihkan' => [
                'Akun' => $hasil['Akun']->dihapus,
                'Kategori' => $hasil['Kategori']->dihapus,
                'StasiunDapur' => $hasil['StasiunDapur']->dihapus,
                'Fitur' => array_values(array_map(fn (string $kunci): string => $namaFitur[$kunci] ?? $kunci, $hasil['Fitur'])),
            ],
            'Dipertahankan' => $dipertahankan,
        ];
    }
}
