<?php

declare(strict_types=1);

namespace App\Domain\Bersama\Database;

use Illuminate\Support\Facades\DB;

/**
 * Menghapus semua baris yang bergantung pada satu atau beberapa baris induk lewat foreign key, berantai ke bawah
 * (anak, cucu, dan seterusnya), tanpa mengenal nama tabel tertentu. Dasarnya `information_schema` (pengenal bukan
 * masukan pengguna), sama seperti penghapus tenant uji (D-213), tetapi dibatasi pada keturunan baris induk yang
 * diberikan, bukan seluruh tenant.
 *
 * Aturan penelusuran (induk sendiri tidak pernah dihapus; pemanggil yang memutuskan nasibnya):
 * - Anak langsung induk dihapus, apa pun nullable-nya (baris itu memang tentang induk).
 * - Cucu dan di bawahnya dihapus bila foreign key-nya wajib terisi (baris tak bisa ada tanpa induknya), tetapi hanya
 *   DIKOSONGKAN bila foreign key-nya boleh null (rujukan opsional dari dokumen lain tidak boleh ikut hilang).
 * - Tabel pembukuan dan jejak audit (`DILINDUNGI`) tidak pernah dihapus; rujukannya ke baris yang dihapus hanya
 *   dikosongkan bila boleh null, selain itu dibiarkan (angkanya tetap seimbang).
 * - Tabel di `$kecuali` dilewati (ditangani pemanggil).
 *
 * `Rencanakan` murni membaca (dipakai pratinjau "akan menghapus N baris"); `Terapkan` mengeksekusi rencana itu. Foreign
 * key dimatikan sementara pada koneksi ini selama `Terapkan` supaya urutan hapus tidak penting; pemanggil wajib
 * membungkusnya dalam transaksi sehingga kegagalan di tengah = tidak ada yang terhapus.
 *
 * @phpstan-type Rencana array{Hapus: array<string, list<int>>, Langsung: list<array{0: string, 1: string, 2: list<int>}>, Kosongkan: list<array{0: string, 1: string, 2: list<int>}>}
 */
final class PenghapusBerantai
{
    /** Pembukuan dan jejak audit: tidak pernah dihapus oleh penelusuran ini. */
    public const DILINDUNGI = ['Jurnal', 'JurnalDetail', 'JurnalUmum', 'LogAudit', 'LogAuditPengelola', 'RiwayatStatusDokumen'];

    private const UKURAN_KELOMPOK = 500;

    /** @var list<array{Tabel: string, Kolom: string, Induk: string, KolomInduk: string, BolehNull: bool}>|null */
    private ?array $kunciAsing = null;

    /** @var array<string, true>|null */
    private ?array $tabelBerId = null;

    /**
     * @param  list<int>  $idInduk
     * @param  list<string>  $kecuali
     * @return Rencana
     */
    public function Rencanakan(string $induk, array $idInduk, array $kecuali = []): array
    {
        $hapus = [];
        $langsung = [];
        $kosongkan = [];
        $sudah = [$induk => array_fill_keys($idInduk, true)];
        /** @var list<array{0: string, 1: list<int>, 2: int}> $antrean */
        $antrean = [[$induk, $idInduk, 0]];

        while ($antrean !== []) {
            [$tabel, $ids, $tingkat] = array_shift($antrean);

            foreach ($this->AnakDari($tabel) as $fk) {
                $anak = $fk['Tabel'];
                $kolom = $fk['Kolom'];

                if (in_array($anak, $kecuali, true)) {
                    continue;
                }

                if (in_array($anak, self::DILINDUNGI, true) || ($tingkat > 0 && $fk['BolehNull'])) {
                    if ($fk['BolehNull']) {
                        $kosongkan[] = [$anak, $kolom, $ids];
                    }

                    continue;
                }

                if (! $this->PunyaKolomId($anak)) {
                    $langsung[] = [$anak, $kolom, $ids];

                    continue;
                }

                $baru = [];

                foreach ($this->CariId($anak, $kolom, $ids) as $id) {
                    if (! isset($sudah[$anak][$id])) {
                        $sudah[$anak][$id] = true;
                        $baru[] = $id;
                    }
                }

                if ($baru === []) {
                    continue;
                }

                $hapus[$anak] = [...($hapus[$anak] ?? []), ...$baru];
                $antrean[] = [$anak, $baru, $tingkat + 1];
            }
        }

        return ['Hapus' => $hapus, 'Langsung' => $langsung, 'Kosongkan' => $kosongkan];
    }

    /**
     * Jumlah baris per tabel pada rencana (tabel tanpa kolom `Id` dihitung dari kolom penghubungnya).
     *
     * @param  Rencana  $rencana
     * @return array<string, int>
     */
    public function Hitung(array $rencana): array
    {
        $jumlah = [];

        foreach ($rencana['Hapus'] as $tabel => $ids) {
            $jumlah[$tabel] = count($ids);
        }

        foreach ($rencana['Langsung'] as [$tabel, $kolom, $ids]) {
            $banyak = 0;

            foreach (array_chunk($ids, self::UKURAN_KELOMPOK) as $kelompok) {
                $banyak += DB::table($tabel)->whereIn($kolom, $kelompok)->count();
            }

            if ($banyak > 0) {
                $jumlah[$tabel] = ($jumlah[$tabel] ?? 0) + $banyak;
            }
        }

        arsort($jumlah);

        return $jumlah;
    }

    /**
     * @param  Rencana  $rencana
     * @return array<string, int> baris yang benar-benar dihapus per tabel
     */
    public function Terapkan(array $rencana): array
    {
        $hasil = [];

        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        try {
            foreach ($rencana['Kosongkan'] as [$tabel, $kolom, $ids]) {
                foreach (array_chunk($ids, self::UKURAN_KELOMPOK) as $kelompok) {
                    DB::table($tabel)->whereIn($kolom, $kelompok)->update([$kolom => null]);
                }
            }

            foreach ($rencana['Langsung'] as [$tabel, $kolom, $ids]) {
                foreach (array_chunk($ids, self::UKURAN_KELOMPOK) as $kelompok) {
                    $hasil[$tabel] = ($hasil[$tabel] ?? 0) + DB::table($tabel)->whereIn($kolom, $kelompok)->delete();
                }
            }

            foreach ($rencana['Hapus'] as $tabel => $ids) {
                foreach (array_chunk($ids, self::UKURAN_KELOMPOK) as $kelompok) {
                    $hasil[$tabel] = ($hasil[$tabel] ?? 0) + DB::table($tabel)->whereIn('Id', $kelompok)->delete();
                }
            }
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }

        return array_filter($hasil, static fn (int $jumlah): bool => $jumlah > 0);
    }

    /**
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function CariId(string $tabel, string $kolom, array $ids): array
    {
        $ditemukan = [];

        foreach (array_chunk($ids, self::UKURAN_KELOMPOK) as $kelompok) {
            foreach (DB::table($tabel)->whereIn($kolom, $kelompok)->pluck('Id') as $id) {
                $ditemukan[] = (int) $id;
            }
        }

        return $ditemukan;
    }

    /**
     * @return list<array{Tabel: string, Kolom: string, Induk: string, KolomInduk: string, BolehNull: bool}>
     */
    private function AnakDari(string $induk): array
    {
        $this->kunciAsing ??= array_values(array_map(
            static fn (\stdClass $baris): array => [
                'Tabel' => (string) $baris->Tabel,
                'Kolom' => (string) $baris->Kolom,
                'Induk' => (string) $baris->Induk,
                'KolomInduk' => (string) $baris->KolomInduk,
                'BolehNull' => $baris->Boleh === 'YES',
            ],
            DB::select(
                'SELECT k.TABLE_NAME AS Tabel, k.COLUMN_NAME AS Kolom, k.REFERENCED_TABLE_NAME AS Induk,
                        k.REFERENCED_COLUMN_NAME AS KolomInduk, c.IS_NULLABLE AS Boleh
                 FROM information_schema.KEY_COLUMN_USAGE k
                 JOIN information_schema.COLUMNS c
                   ON c.TABLE_SCHEMA = k.TABLE_SCHEMA AND c.TABLE_NAME = k.TABLE_NAME AND c.COLUMN_NAME = k.COLUMN_NAME
                 WHERE k.TABLE_SCHEMA = DATABASE() AND k.REFERENCED_TABLE_NAME IS NOT NULL
                 ORDER BY k.TABLE_NAME, k.COLUMN_NAME',
            ),
        ));

        return array_values(array_filter(
            $this->kunciAsing,
            static fn (array $fk): bool => $fk['Induk'] === $induk && $fk['KolomInduk'] === 'Id',
        ));
    }

    private function PunyaKolomId(string $tabel): bool
    {
        $this->tabelBerId ??= array_fill_keys(array_map('strval', array_column(DB::select(
            'SELECT TABLE_NAME AS Nama FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = ?',
            ['Id'],
        ), 'Nama')), true);

        return isset($this->tabelBerId[$tabel]);
    }
}
