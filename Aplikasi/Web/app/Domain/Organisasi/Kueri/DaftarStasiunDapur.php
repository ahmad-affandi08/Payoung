<?php

declare(strict_types=1);

namespace App\Domain\Organisasi\Kueri;

use App\Domain\Organisasi\Enum\StatusOrganisasi;
use App\Domain\Organisasi\Model\StasiunDapur;

/**
 * Kueri publik stasiun dapur tenant (F-10a) untuk domain lain (Katalog: pilihan stasiun kategori; PanduanAwal:
 * penerapan template) dan halaman back-office. Stasiun bawaan = stasiun aktif pertama menurut Urutan lalu Nama.
 *
 * D-310: stasiun `Salinan` (checker) hanya menerima salinan semua tiket di printer. Ia tidak memasak, jadi tidak ikut
 * perutean kategori/produk, stasiun bawaan, pilihan stasiun, dan layar dapur (KDS).
 */
final class DaftarStasiunDapur
{
    /**
     * @return list<array{Uuid: string, Nama: string, Urutan: int, Status: string, Salinan: bool}>
     */
    public function AmbilSemua(): array
    {
        return array_values(StasiunDapur::query()->orderBy('Status')->orderBy('Urutan')->orderBy('Nama')->get()
            ->map(fn (StasiunDapur $s): array => ['Uuid' => $s->Uuid, 'Nama' => $s->Nama, 'Urutan' => $s->Urutan, 'Status' => $s->Status->value, 'Salinan' => $s->Salinan])
            ->all());
    }

    /**
     * Pilihan stasiun aktif untuk form kategori dan produk (tanpa stasiun salinan).
     *
     * @return list<array{Nilai: string, Label: string}>
     */
    public function AmbilOpsiAktif(): array
    {
        return array_values(StasiunDapur::query()->where('Status', StatusOrganisasi::Aktif->value)->where('Salinan', false)->orderBy('Urutan')->orderBy('Nama')->get()
            ->map(fn (StasiunDapur $s): array => ['Nilai' => $s->Uuid, 'Label' => $s->Nama])
            ->all());
    }

    /**
     * Stasiun aktif yang memasak (Id → Nama, tanpa salinan) urut Urutan lalu Nama, dan stasiun bawaan (aktif pertama)
     * untuk perutean tiket dapur.
     *
     * @return array{Aktif: array<int, string>, Bawaan: int|null}
     */
    public function AmbilPerutean(): array
    {
        $aktif = [];

        foreach (StasiunDapur::query()->where('Status', StatusOrganisasi::Aktif->value)->where('Salinan', false)->orderBy('Urutan')->orderBy('Nama')->get() as $s) {
            $aktif[$s->Id] = $s->Nama;
        }

        return ['Aktif' => $aktif, 'Bawaan' => array_key_first($aktif)];
    }

    /**
     * Id stasiun aktif yang memasak per Uuid (KDS memilih stasiun lewat Uuid; stasiun salinan tidak punya layar dapur).
     *
     * @return array<string, int>
     */
    public function AmbilIdAktifPerUuid(): array
    {
        return StasiunDapur::query()->where('Status', StatusOrganisasi::Aktif->value)->where('Salinan', false)->pluck('Id', 'Uuid')->map(fn ($id): int => (int) $id)->all();
    }

    /** Id stasiun aktif (bukan salinan) dari Uuid; null bila tidak ada, milik tenant lain, diarsipkan, atau stasiun salinan. */
    public function CariIdAktif(string $uuid): ?int
    {
        $id = StasiunDapur::query()->where('Uuid', $uuid)->where('Status', StatusOrganisasi::Aktif->value)->where('Salinan', false)->value('Id');

        return is_int($id) ? $id : null;
    }

    /**
     * Semua stasiun (termasuk yang diarsipkan) per Id, untuk menampilkan rujukan kategori.
     *
     * @return array<int, array{Uuid: string, Nama: string, Aktif: bool}>
     */
    public function AmbilPetaId(): array
    {
        $hasil = [];

        foreach (StasiunDapur::query()->get() as $s) {
            $hasil[$s->Id] = ['Uuid' => $s->Uuid, 'Nama' => $s->Nama, 'Aktif' => $s->Status === StatusOrganisasi::Aktif];
        }

        return $hasil;
    }

    /**
     * Stasiun salinan (checker) aktif: Uuid dan nama, untuk dipasangkan ke printer di kasir.
     *
     * @return list<array{Uuid: string, Nama: string}>
     */
    public function AmbilSalinanAktif(): array
    {
        return array_values(StasiunDapur::query()->where('Status', StatusOrganisasi::Aktif->value)->where('Salinan', true)->orderBy('Urutan')->orderBy('Nama')->get()
            ->map(fn (StasiunDapur $s): array => ['Uuid' => $s->Uuid, 'Nama' => $s->Nama])
            ->all());
    }

    /** Ada stasiun aktif yang memasak (bukan hanya checker)? */
    public function CekAdaStasiunMemasak(): bool
    {
        return StasiunDapur::query()->where('Status', StatusOrganisasi::Aktif->value)->where('Salinan', false)->exists();
    }
}
