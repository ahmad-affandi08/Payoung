<?php

declare(strict_types=1);

namespace App\Domain\Pelanggan\Kueri;

use App\Domain\Pelanggan\Enum\StatusPelanggan;
use App\Domain\Pelanggan\Layanan\NomorHp;
use App\Domain\Pelanggan\Model\Pelanggan;
use App\Domain\Pelanggan\Model\PelangganAlias;
use App\Domain\Pelanggan\Model\TierPelanggan;

/**
 * Kueri publik domain Pelanggan (F-16a): menemukan pelanggan dari Uuid yang dikirim POS (termasuk alias Uuid
 * perangkat untuk nomor HP yang sudah terdaftar). Dipakai penerimaan `Penjualan.Buat`.
 */
final class IdentitasPelanggan
{
    public function __construct(private readonly CariPelangganPos $cariPos) {}

    public function CariId(string $uuid): ?int
    {
        $id = Pelanggan::query()->where('Uuid', $uuid)->value('Id');

        if ($id !== null) {
            return (int) $id;
        }

        $alias = PelangganAlias::query()->where('Uuid', $uuid)->value('IdPelanggan');

        return $alias === null ? null : (int) $alias;
    }

    /** F-07 mode service: pelanggan aktif dengan nomor HP ini (dinormalisasi), untuk menautkan reservasi. */
    public function CariIdDariNoHp(string $noHp): ?int
    {
        $nomor = NomorHp::Normalisasi($noHp);
        $id = $nomor === null ? null : Pelanggan::query()->where('NoHp', $nomor)->where('Status', StatusPelanggan::Aktif->value)->value('Id');

        return $id === null ? null : (int) $id;
    }

    /** F-16d: pelanggan berstatus Aktif (bukan diarsipkan). */
    /**
     * Nama & Uuid pelanggan untuk daftar/detail dokumen domain lain (grosir F-12), supaya kueri domain itu tidak perlu
     * membaca tabel `Pelanggan` sendiri.
     *
     * @param  array<mixed>  $id  nilai bukan int diabaikan
     * @return array<int, array{Uuid: string, Nama: string}>
     */
    public function AmbilNamaBanyak(array $id): array
    {
        $id = array_values(array_unique(array_filter($id, 'is_int')));

        if ($id === []) {
            return [];
        }

        $hasil = [];

        foreach (Pelanggan::query()->whereIn('Id', $id)->get(['Id', 'Uuid', 'Nama']) as $p) {
            $hasil[$p->Id] = ['Uuid' => $p->Uuid, 'Nama' => $p->Nama];
        }

        return $hasil;
    }

    /** Uuid publik pelanggan (webhook X7, v4.07); null bila tidak ada. */
    public function AmbilUuid(?int $id): ?string
    {
        $uuid = $id === null ? null : Pelanggan::query()->whereKey($id)->value('Uuid');

        return is_string($uuid) ? $uuid : null;
    }

    public function CekAktif(int $id): bool
    {
        return Pelanggan::query()->whereKey($id)->where('Status', StatusPelanggan::Aktif->value)->exists();
    }

    /** F-16c: kode tier pelanggan (syarat promo); null = tanpa tier. */
    public function AmbilKodeTier(?int $id): ?string
    {
        $idTier = $id === null ? null : Pelanggan::query()->whereKey($id)->value('IdTier');

        return $idTier === null ? null : TierPelanggan::query()->whereKey($idTier)->value('Kode');
    }

    /** Tanggal lahir `YYYY-MM-DD` untuk promo ulang tahun (F-16c bagian 3); null bila tidak diisi/tidak dikenal. */
    public function AmbilTanggalLahir(?int $id): ?string
    {
        $pelanggan = $id === null ? null : Pelanggan::query()->whereKey($id)->first(['Id', 'TanggalLahir']);

        return $pelanggan?->TanggalLahir?->toDateString();
    }

    /**
     * Uuid & nama pelanggan untuk tampilan dokumen (detail penjualan).
     *
     * @return array{Uuid: string, Nama: string}|null
     */
    public function AmbilRingkas(?int $id): ?array
    {
        $p = $id === null ? null : Pelanggan::query()->whereKey($id)->first(['Uuid', 'Nama']);

        return $p === null ? null : ['Uuid' => $p->Uuid, 'Nama' => $p->Nama];
    }

    /**
     * Laundry (§9.9): nama & nomor HP (format `62…`, null bila tidak valid) untuk tiket dan notifikasi WhatsApp.
     *
     * @return array{Nama: string, NoHp: string|null}|null
     */
    public function AmbilKontak(int $id): ?array
    {
        $p = Pelanggan::query()->whereKey($id)->first(['Id', 'Nama', 'NoHp']);

        return $p === null ? null : ['Nama' => $p->Nama, 'NoHp' => NomorHp::Normalisasi($p->NoHp)];
    }

    /**
     * F-12 §9.7: identitas penagihan untuk dokumen grosir yang dicetak (surat jalan, faktur, nota kredit). Nomor HP
     * **tidak** disamarkan di sini, berbeda dengan `AmbilUntukPos`: dokumen ini dicetak untuk pembeli itu sendiri dan
     * sopir yang mengantar, jadi nomor yang bisa dihubungi memang bagian isinya.
     *
     * @return array{Nama: string, Alamat: string|null, NoHp: string}|null
     */
    public function AmbilUntukCetak(int $id): ?array
    {
        $p = Pelanggan::query()->whereKey($id)->first(['Id', 'Nama', 'Alamat', 'NoHp']);

        return $p === null ? null : ['Nama' => $p->Nama, 'Alamat' => $p->Alamat, 'NoHp' => $p->NoHp];
    }

    /**
     * F-21: identitas penagihan untuk invoice yang dicetak atau dibuka lewat tautan publik. Berbeda dari `AmbilUntukCetak`,
     * memuat email dan NPWP (terenkripsi di tabel, didekripsi model) karena invoice B2B dipakai bagian pajak pembeli.
     * Nama & alamat memakai versi sesuai DJP bila diisi, sehingga cocok dengan Faktur Pajak.
     *
     * @return array{Nama: string, Alamat: string|null, NoHp: string, Email: string|null, Npwp: string|null}|null
     */
    public function AmbilUntukInvoice(int $id): ?array
    {
        $p = Pelanggan::query()->whereKey($id)->first();

        if ($p === null) {
            return null;
        }

        return [
            'Nama' => $p->NamaNpwp !== null && $p->NamaNpwp !== '' ? $p->NamaNpwp : $p->Nama,
            'Alamat' => $p->AlamatNpwp !== null && $p->AlamatNpwp !== '' ? $p->AlamatNpwp : $p->Alamat,
            'NoHp' => $p->NoHp,
            'Email' => $p->Email,
            'Npwp' => $p->Npwp,
        ];
    }

    /**
     * F-12 bagian 2: identitas pelanggan pre-order untuk POS (nomor HP tersamar, tier untuk harga) per Id.
     *
     * @param  list<int>  $id
     * @return array<int, array{Uuid: string, Nama: string, NoHp: string, KodeTier: string|null, NamaTier: string|null}>
     */
    public function AmbilUntukPos(array $id): array
    {
        $hasil = [];
        $daftar = $id === [] ? collect() : Pelanggan::query()->whereKey(array_values(array_unique($id)))->get(['Id', 'Uuid', 'Nama', 'NoHp', 'IdTier']);
        $tier = TierPelanggan::query()->whereKey(array_values(array_filter($daftar->pluck('IdTier')->all(), 'is_int')))->get(['Id', 'Kode', 'Nama'])->keyBy('Id');

        foreach ($daftar as $p) {
            $t = $p->IdTier === null ? null : $tier->get($p->IdTier);
            $hasil[$p->Id] = ['Uuid' => $p->Uuid, 'Nama' => $p->Nama, 'NoHp' => NomorHp::Samarkan($p->NoHp), 'KodeTier' => $t?->Kode, 'NamaTier' => $t?->Nama];
        }

        return $hasil;
    }

    /**
     * F-12 bagian 2: Id pelanggan aktif yang nama/nomor HP-nya cocok dengan [kata] (cari pre-order di POS), maks. 20.
     *
     * @return list<int>
     */
    public function CariIdPos(string $kata): array
    {
        return array_values(array_map('intval', array_filter(array_map(
            fn (array $p): ?int => $this->CariId($p['Uuid']),
            $this->cariPos->Cari($kata),
        ))));
    }
}
