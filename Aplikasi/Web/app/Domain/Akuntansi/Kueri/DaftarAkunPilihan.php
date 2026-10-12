<?php

declare(strict_types=1);

namespace App\Domain\Akuntansi\Kueri;

use App\Domain\Akuntansi\Enum\TipeAkun;
use App\Domain\Akuntansi\Model\Akun;

/**
 * API baca publik akun tenant aktif untuk domain lain yang memetakan sesuatu ke akun (F-06 kategori kas): pilihan
 * dropdown per tipe dan pencarian per Uuid, tanpa domain lain membaca tabel `Akun`. Akun nonaktif (F-13a) tidak
 * ditawarkan dan tidak bisa dipilih; `AmbilBanyak` tetap mengembalikannya untuk tampilan data lama.
 *
 * @phpstan-type BarisAkun array{Id: int, Uuid: string, Kode: string, Nama: string, Jenis: string}
 */
final class DaftarAkunPilihan
{
    /**
     * @param  list<TipeAkun>  $tipe
     * @return list<BarisAkun>
     */
    public function Ambil(array $tipe): array
    {
        return array_values(Akun::query()
            ->where('Aktif', true)
            ->whereIn('Jenis', array_map(fn (TipeAkun $t): string => $t->value, $tipe))
            ->orderBy('Kode')
            ->get()
            ->map(fn (Akun $a): array => self::Petakan($a))
            ->all());
    }

    /**
     * Akun aktif dari Uuid bila tipenya termasuk `$tipe`; null bila tidak ada, nonaktif, milik tenant lain, atau
     * tipenya lain.
     *
     * @param  list<TipeAkun>  $tipe
     * @return BarisAkun|null
     */
    public function CariDariUuid(string $uuid, array $tipe): ?array
    {
        $akun = Akun::query()->where('Uuid', $uuid)->where('Aktif', true)->first();

        return $akun !== null && in_array($akun->Jenis, $tipe, true) ? self::Petakan($akun) : null;
    }

    /**
     * Akun aktif untuk formulir transaksi kas & bank (F-13a): akun kas/bank dan akun lawan (tanpa HPP), disaring FE
     * per jenis transaksi; aturan akhirnya tetap di `SimpanTransaksiKasBank::PeriksaAkun`.
     *
     * @return list<array{Uuid: string, Kode: string, Nama: string, Jenis: string, KasBank: bool}>
     */
    public function AmbilUntukKasBank(): array
    {
        return array_values(Akun::query()
            ->where('Aktif', true)
            ->where('Jenis', '!=', TipeAkun::Hpp->value)
            ->orderBy('Kode')
            ->get()
            ->map(fn (Akun $a): array => ['Uuid' => $a->Uuid, 'Kode' => $a->Kode, 'Nama' => $a->Nama, 'Jenis' => $a->Jenis->value, 'KasBank' => $a->KasBank])
            ->all());
    }

    /**
     * FIN-05 jurnal umum manual: semua akun aktif kecuali akun kendali (`PenjagaAkunKendali`), urut kode. Aturan akhirnya
     * tetap di `CatatJurnalUmum`; daftar ini hanya agar formulir tidak menawarkan akun yang pasti ditolak.
     *
     * @param  list<int>  $idDikecualikan
     * @return list<array{Uuid: string, Kode: string, Nama: string, Jenis: string}>
     */
    public function AmbilUntukJurnalUmum(array $idDikecualikan): array
    {
        return array_values(Akun::query()
            ->where('Aktif', true)
            ->when($idDikecualikan !== [], fn ($k) => $k->whereNotIn('Id', $idDikecualikan))
            ->orderBy('Kode')
            ->get()
            ->map(fn (Akun $a): array => ['Uuid' => $a->Uuid, 'Kode' => $a->Kode, 'Nama' => $a->Nama, 'Jenis' => $a->Jenis->value])
            ->all());
    }

    /**
     * F-04 fase 1: akun kas/bank aktif (`Akun.KasBank`) untuk pembayaran hutang & belanja stok, urut kode.
     *
     * @return list<BarisAkun>
     */
    public function AmbilKasBank(): array
    {
        return array_values(Akun::query()
            ->where('Aktif', true)
            ->where('KasBank', true)
            ->orderBy('Kode')
            ->get()
            ->map(fn (Akun $a): array => self::Petakan($a))
            ->all());
    }

    /**
     * Akun kas/bank aktif dari Uuid; null bila tidak ada, nonaktif, milik tenant lain, atau bukan akun kas/bank.
     *
     * @return BarisAkun|null
     */
    public function CariKasBankDariUuid(string $uuid): ?array
    {
        $akun = Akun::query()->where('Uuid', $uuid)->where('Aktif', true)->where('KasBank', true)->first();

        return $akun === null ? null : self::Petakan($akun);
    }

    /**
     * @param  list<int>  $id
     * @return array<int, BarisAkun> kunci = Id
     */
    public function AmbilBanyak(array $id): array
    {
        $hasil = [];

        foreach (Akun::query()->whereIn('Id', array_values(array_unique($id)))->get() as $akun) {
            $hasil[$akun->Id] = self::Petakan($akun);
        }

        return $hasil;
    }

    /**
     * @return BarisAkun
     */
    private static function Petakan(Akun $akun): array
    {
        return ['Id' => $akun->Id, 'Uuid' => $akun->Uuid, 'Kode' => $akun->Kode, 'Nama' => $akun->Nama, 'Jenis' => $akun->Jenis->value];
    }
}
