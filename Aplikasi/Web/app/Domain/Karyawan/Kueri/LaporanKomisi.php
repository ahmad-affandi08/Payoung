<?php

declare(strict_types=1);

namespace App\Domain\Karyawan\Kueri;

use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Bersama\Tabel\Data\DataPermintaanTabel;
use App\Domain\Bersama\Tabel\Layanan\PenerapKueriTabel;
use App\Domain\Karyawan\Model\Karyawan;
use App\Domain\Karyawan\Model\Komisi;
use App\Domain\Organisasi\Kueri\PetaUuidOutlet;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Laporan komisi per karyawan (F-18, `TabelData` mode server): saring tanggal bisnis & outlet (pengguna terbatas
 * outlet hanya outletnya), cari nama. Per karyawan: jumlah baris dilayani, dasar komisi, komisi kotor, dibatalkan
 * (void/retur), dan bersih; ringkasan total bersih periode.
 */
final class LaporanKomisi
{
    public const KOLOM_URUT = ['Nama', 'Bersih'];

    public const KOLOM_SARING = ['TanggalBisnis', 'Outlet'];

    public const URUT_BAWAAN = '-Bersih';

    public function __construct(private readonly PetaUuidOutlet $outlet) {}

    /**
     * @param  list<int>|null  $idOutletBoleh
     * @return array{Data: list<array<string, mixed>>, Meta: array{Halaman: int, PerHalaman: int, Total: int, JumlahHalaman: int}, Ringkasan: array{Bersih: string}}
     */
    public function Ambil(DataPermintaanTabel $p, ?array $idOutletBoleh): array
    {
        $tanggal = $p->AmbilRentangTanggal('TanggalBisnis');
        $uuidOutlet = $p->AmbilDaftar('Outlet');
        $idOutlet = $uuidOutlet === [] ? null : array_values($this->outlet->AmbilIdDariUuid($uuidOutlet));
        $saring = fn (Builder $k): Builder => $k
            ->when($idOutletBoleh !== null, fn (Builder $q) => $q->whereIn('Komisi.IdOutlet', $idOutletBoleh ?? []))
            ->when($idOutlet !== null, fn (Builder $q) => $q->whereIn('Komisi.IdOutlet', $idOutlet ?? []))
            ->when($tanggal['Dari'] !== null, fn (Builder $q) => $q->where('Komisi.TanggalBisnis', '>=', $tanggal['Dari']))
            ->when($tanggal['Sampai'] !== null, fn (Builder $q) => $q->where('Komisi.TanggalBisnis', '<=', $tanggal['Sampai']));
        $kueri = Karyawan::query()
            ->select(['Karyawan.Id', 'Karyawan.Uuid', 'Karyawan.Nama', 'Karyawan.Jabatan'])
            ->whereIn('Karyawan.Id', $saring(Komisi::query()->select('Komisi.IdKaryawan')))
            ->selectSub($saring(Komisi::query()->selectRaw('COUNT(*)')->whereColumn('Komisi.IdKaryawan', 'Karyawan.Id')), 'JumlahBaris')
            ->selectSub($saring(Komisi::query()->selectRaw('COALESCE(SUM(`Komisi`.`Dasar` * `Komisi`.`Porsi`), 0)')->whereColumn('Komisi.IdKaryawan', 'Karyawan.Id')), 'TotalDasar')
            ->selectSub($saring(Komisi::query()->selectRaw('COALESCE(SUM(`Komisi`.`Jumlah`), 0)')->whereColumn('Komisi.IdKaryawan', 'Karyawan.Id')), 'Kotor')
            ->selectSub($saring(Komisi::query()->selectRaw('COALESCE(SUM(`Komisi`.`JumlahDibatalkan`), 0)')->whereColumn('Komisi.IdKaryawan', 'Karyawan.Id')), 'Dibatalkan')
            ->selectSub($saring(Komisi::query()->selectRaw('COALESCE(SUM(`Komisi`.`Jumlah` - `Komisi`.`JumlahDibatalkan`), 0)')->whereColumn('Komisi.IdKaryawan', 'Karyawan.Id')), 'Bersih')
            ->when($p->cari !== '', fn (Builder $k) => $k->where('Karyawan.Nama', 'like', PenerapKueriTabel::PolaCari($p->cari)));
        $bersih = (string) $saring(Komisi::query())->selectRaw('CAST(COALESCE(SUM(`Jumlah` - `JumlahDibatalkan`), 0) AS DECIMAL(18,2)) AS Total')->value('Total');

        return [
            ...PenerapKueriTabel::Terapkan($kueri, $p, ['Nama' => 'Karyawan.Nama', 'Bersih' => 'Bersih'], fn (Collection $baris): array => array_values($baris->map(fn (Karyawan $k): array => [
                'Uuid' => $k->Uuid,
                'Nama' => $k->Nama,
                'Jabatan' => $k->Jabatan,
                'JumlahBaris' => (int) $k->getAttribute('JumlahBaris'),
                'TotalDasar' => self::Uang($k->getAttribute('TotalDasar')),
                'Kotor' => self::Uang($k->getAttribute('Kotor')),
                'Dibatalkan' => self::Uang($k->getAttribute('Dibatalkan')),
                'Bersih' => self::Uang($k->getAttribute('Bersih')),
            ])->all())),
            'Ringkasan' => ['Bersih' => self::Uang($bersih)],
        ];
    }

    /**
     * Ringkasan untuk widget Beranda: komisi bersih per karyawan pada rentang tanggal bisnis (outlet dibatasi akses),
     * urut terbesar, dan total seluruh karyawan (bukan hanya `$batas` teratas).
     *
     * @param  list<int>|null  $idOutletBoleh
     * @return array{Total: string, Baris: list<array{Uuid: string, Nama: string, Bersih: string}>}
     */
    public function AmbilRingkasanPeriode(string $dari, string $sampai, ?array $idOutletBoleh, int $batas = 5): array
    {
        if ($idOutletBoleh === []) {
            return ['Total' => Uang::Nol()->KeString(), 'Baris' => []];
        }

        $dasar = fn (): Builder => Komisi::query()
            ->whereBetween('TanggalBisnis', [$dari, $sampai])
            ->when($idOutletBoleh !== null, fn (Builder $k) => $k->whereIn('IdOutlet', $idOutletBoleh ?? []));
        $total = (string) $dasar()->selectRaw('CAST(COALESCE(SUM(`Jumlah` - `JumlahDibatalkan`), 0) AS DECIMAL(18,2)) AS Total')->value('Total');
        $teratas = $dasar()
            ->groupBy('IdKaryawan')
            ->selectRaw('`IdKaryawan`, CAST(COALESCE(SUM(`Jumlah` - `JumlahDibatalkan`), 0) AS DECIMAL(18,2)) AS `Bersih`')
            ->orderByDesc('Bersih')
            ->orderBy('IdKaryawan')
            ->limit($batas)
            ->toBase()
            ->get();
        $karyawan = Karyawan::query()->whereIn('Id', $teratas->pluck('IdKaryawan')->all())->get()->keyBy('Id');
        $baris = [];

        foreach ($teratas as $b) {
            $k = $karyawan->get((int) $b->IdKaryawan);

            if ($k !== null) {
                $baris[] = ['Uuid' => $k->Uuid, 'Nama' => $k->Nama, 'Bersih' => self::Uang($b->Bersih)];
            }
        }

        return ['Total' => self::Uang($total), 'Baris' => $baris];
    }

    /**
     * F-18 bagian 3 rekap gaji: komisi bersih (komisi − dibatalkan) per karyawan untuk tanggal bisnis dalam rentang.
     *
     * @return array<int, string> Id karyawan → komisi bersih
     */
    public function AmbilBersihPerKaryawan(string $dari, string $sampai): array
    {
        $hasil = [];

        foreach (Komisi::query()
            ->whereBetween('TanggalBisnis', [$dari, $sampai])
            ->groupBy('IdKaryawan')
            ->selectRaw('`IdKaryawan`, CAST(COALESCE(SUM(`Jumlah` - `JumlahDibatalkan`), 0) AS DECIMAL(18,2)) AS `Bersih`')
            ->toBase()
            ->get() as $b) {
            $hasil[(int) $b->IdKaryawan] = self::Uang($b->Bersih);
        }

        return $hasil;
    }

    /**
     * Realisasi penjualan per karyawan (target penjualan, v1.85): Σ (dasar baris − dasar dibatalkan) × porsi staf untuk
     * tanggal bisnis dalam rentang. Hanya baris yang mencatat staf (`Baris.*.Staf`) yang terhitung.
     *
     * @return array<int, string> IdKaryawan → nilai
     */
    public function AmbilPenjualanPerKaryawan(string $dari, string $sampai): array
    {
        $hasil = [];

        foreach (Komisi::query()
            ->whereBetween('TanggalBisnis', [$dari, $sampai])
            ->groupBy('IdKaryawan')
            ->selectRaw('`IdKaryawan`, CAST(COALESCE(SUM(ROUND((`Dasar` - `DasarDibatalkan`) * `Porsi`, 2)), 0) AS DECIMAL(18,2)) AS `Nilai`')
            ->toBase()
            ->get() as $b) {
            $hasil[(int) $b->IdKaryawan] = self::Uang($b->Nilai);
        }

        return $hasil;
    }

    private static function Uang(mixed $nilai): string
    {
        return (string) Uang::Dari(is_numeric($nilai) ? (string) $nilai : '0')->KeString();
    }
}
