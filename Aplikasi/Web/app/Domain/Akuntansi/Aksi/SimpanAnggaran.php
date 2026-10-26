<?php

declare(strict_types=1);

namespace App\Domain\Akuntansi\Aksi;

use App\Domain\Akuntansi\Data\DataAnggaran;
use App\Domain\Akuntansi\Enum\TipeAkun;
use App\Domain\Akuntansi\Model\Akun;
use App\Domain\Akuntansi\Model\Anggaran;
use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Uang;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * F8 (FIN-11): menyimpan anggaran satu tahun untuk satu cakupan (seluruh usaha atau satu outlet). Tiap baris adalah satu
 * akun pendapatan, HPP, atau beban dengan dua belas angka bulanan; baris yang tidak dikirim tidak disentuh, angka nol
 * menghapus anggaran bulan itu. Anggaran adalah rencana, jadi boleh diubah; setiap penyimpanan yang mengubah sesuatu
 * diaudit (`anggaran.simpan`). Anggaran seluruh usaha hanya untuk pelaku yang boleh mengakses semua outlet.
 */
final class SimpanAnggaran
{
    public const TAHUN_MINIMAL = 2020;

    public const TAHUN_MAKSIMAL = 2100;

    /** Tipe akun yang bisa dianggarkan: yang tampil di laba rugi. */
    public const TIPE_AKUN = [TipeAkun::Pendapatan, TipeAkun::Hpp, TipeAkun::Beban];

    public function __construct(private readonly PencatatAudit $audit) {}

    /**
     * @return array{Dibuat: int, Diubah: int, Dihapus: int}
     *
     * @throws PelanggaranAturanBisnis
     */
    public function Jalankan(DataAnggaran $data): array
    {
        self::PeriksaCakupan($data->tahun, $data->idOutlet, $data->idOutletBoleh);

        return DB::transaction(function () use ($data): array {
            $akun = $this->AmbilAkun($data);
            $hasil = ['Dibuat' => 0, 'Diubah' => 0, 'Dihapus' => 0];
            $ringkasan = [];

            foreach ($data->baris as $baris) {
                $satu = $akun[$baris->uuidAkun];
                $ada = self::Cakupan(Anggaran::query()->where('Tahun', $data->tahun)->where('IdAkun', $satu->Id), $data->idOutlet)
                    ->lockForUpdate()->get()->keyBy('Bulan');
                $berubah = 0;

                foreach ($baris->perBulan as $indeks => $jumlah) {
                    $bulan = $indeks + 1;
                    $lama = $ada->get($bulan);
                    $nilaiLama = $lama === null ? Uang::Nol() : Uang::Dari($lama->Jumlah);

                    if ($nilaiLama->SamaDengan($jumlah)) {
                        continue;
                    }

                    $berubah++;

                    if ($jumlah->BernilaiNol()) {
                        $lama?->delete();
                        $hasil['Dihapus']++;
                    } elseif ($lama === null) {
                        Anggaran::query()->create([
                            'Tahun' => $data->tahun,
                            'Bulan' => $bulan,
                            'IdAkun' => $satu->Id,
                            'IdOutlet' => $data->idOutlet,
                            'Jumlah' => $jumlah->KeString(),
                        ]);
                        $hasil['Dibuat']++;
                    } else {
                        $lama->Jumlah = $jumlah->KeString();
                        $lama->save();
                        $hasil['Diubah']++;
                    }
                }

                if ($berubah > 0) {
                    $ringkasan[] = ['Kode' => $satu->Kode, 'Bulan' => $berubah];
                }
            }

            if ($ringkasan !== []) {
                $this->audit->Catat('anggaran.simpan', null, nilaiBaru: ['Tahun' => $data->tahun, 'IdOutlet' => $data->idOutlet, ...$hasil, 'Akun' => $ringkasan]);
            }

            return $hasil;
        });
    }

    /**
     * Cakupan sah: tahun dalam rentang dan anggaran seluruh usaha hanya untuk pelaku tanpa batas outlet.
     *
     * @param  list<int>|null  $idOutletBoleh
     *
     * @throws PelanggaranAturanBisnis
     */
    public static function PeriksaCakupan(int $tahun, ?int $idOutlet, ?array $idOutletBoleh): void
    {
        if ($tahun < self::TAHUN_MINIMAL || $tahun > self::TAHUN_MAKSIMAL) {
            throw new PelanggaranAturanBisnis('TahunTidakValid', 'Tahun anggaran '.self::TAHUN_MINIMAL.' sampai '.self::TAHUN_MAKSIMAL.'.');
        }

        if ($idOutletBoleh === null) {
            return;
        }

        if ($idOutlet === null) {
            throw new PelanggaranAturanBisnis('AnggaranSeluruhUsahaDitolak', 'Anggaran seluruh usaha hanya bisa diatur oleh pengguna yang boleh mengakses semua outlet. Pilih outlet Anda.');
        }

        if (! in_array($idOutlet, $idOutletBoleh, true)) {
            throw new PelanggaranAturanBisnis('OutletTidakBoleh', 'Anda tidak punya akses ke outlet ini.');
        }
    }

    /**
     * Batasi kueri `Anggaran` ke satu cakupan: satu outlet, atau seluruh usaha (tanpa outlet).
     *
     * @template TKueri of Builder<Anggaran>
     *
     * @param  TKueri  $kueri
     * @return TKueri
     */
    public static function Cakupan(Builder $kueri, ?int $idOutlet): Builder
    {
        return $idOutlet === null ? $kueri->whereNull('IdOutlet') : $kueri->where('IdOutlet', $idOutlet);
    }

    /**
     * @return array<string, Akun>
     *
     * @throws PelanggaranAturanBisnis
     */
    private function AmbilAkun(DataAnggaran $data): array
    {
        $uuid = [];

        foreach ($data->baris as $baris) {
            if (isset($uuid[$baris->uuidAkun])) {
                throw new PelanggaranAturanBisnis('AkunGanda', 'Satu akun hanya boleh muncul sekali dalam anggaran.');
            }

            if (count($baris->perBulan) !== 12) {
                throw new PelanggaranAturanBisnis('BulanTidakLengkap', 'Anggaran harus berisi dua belas bulan.');
            }

            $uuid[$baris->uuidAkun] = true;
        }

        $akun = Akun::query()
            ->whereIn('Uuid', array_keys($uuid))
            ->whereIn('Jenis', array_map(fn (TipeAkun $t): string => $t->value, self::TIPE_AKUN))
            ->get()
            ->keyBy('Uuid');

        foreach ($data->baris as $baris) {
            $satu = $akun->get($baris->uuidAkun);

            if (! $satu instanceof Akun) {
                throw new PelanggaranAturanBisnis('AkunTidakValid', 'Akun harus berupa akun pendapatan, HPP, atau beban milik usaha Anda.');
            }

            foreach ($baris->perBulan as $jumlah) {
                if ($jumlah->BernilaiNegatif()) {
                    throw new PelanggaranAturanBisnis('JumlahTidakValid', "Anggaran akun {$satu->Kode} tidak boleh negatif.");
                }

                if (! $jumlah->BernilaiNol() && ! $satu->Aktif) {
                    throw new PelanggaranAturanBisnis('AkunNonaktif', "Akun {$satu->Kode} sudah nonaktif. Anggarannya hanya bisa dikosongkan.");
                }
            }
        }

        /** @var array<string, Akun> */
        return $akun->all();
    }
}
