<?php

declare(strict_types=1);

namespace App\Domain\Pelanggan\Kueri;

use App\Domain\Bersama\Dokumen\Enum\StatusDokumenTerposting;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Bersama\Tabel\Data\DataPermintaanTabel;
use App\Domain\Bersama\Tabel\Layanan\PenerapKueriTabel;
use App\Domain\Pelanggan\Enum\KelompokUmurPiutang;
use App\Domain\Pelanggan\Enum\StatusPembayaranPiutang;
use App\Domain\Pelanggan\Enum\StatusPiutang;
use App\Domain\Pelanggan\Model\HapusBukuPiutang;
use App\Domain\Pelanggan\Model\Pelanggan;
use App\Domain\Pelanggan\Model\PembayaranPiutang;
use App\Domain\Pelanggan\Model\PengingatPiutang;
use App\Domain\Pelanggan\Model\Piutang;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Piutang back-office (F-12, `TabelData` mode server): piutang terbuka dengan umur per tanggal laporan dan ringkasan
 * Σ sisa per kelompok umur; daftar pelunasan; piutang terbuka satu pelanggan untuk formulir pelunasan.
 */
final class DaftarPiutang
{
    public const KOLOM_URUT = ['Tanggal', 'JatuhTempo', 'Nomor', 'Jumlah'];

    public const KOLOM_SARING = ['Umur', 'Pelanggan', 'Status'];

    public const URUT_BAWAAN = 'JatuhTempo';

    public const URUT_BAWAAN_PELUNASAN = '-Tanggal';

    public const URUT_BAWAAN_HAPUS_BUKU = '-Tanggal';

    /**
     * @param  list<int>|null  $idOutlet  outlet yang boleh diakses (null = semua)
     * @return array{Data: list<array<string, mixed>>, Meta: array{Halaman: int, PerHalaman: int, Total: int, JumlahHalaman: int}, Ringkasan: array{Total: string, Kelompok: list<array{Kunci: string, Label: string, Sisa: string, Jumlah: int}>}}
     */
    public function Terbuka(DataPermintaanTabel $p, CarbonImmutable $hariIni, ?array $idOutlet = null): array
    {
        $terbuka = [StatusPiutang::BelumLunas->value, StatusPiutang::DibayarSebagian->value];
        $dasar = function () use ($p, $terbuka, $idOutlet): Builder {
            $kueri = Piutang::query()->whereIn('Status', $terbuka)->when($idOutlet !== null, fn (Builder $q) => $q->whereIn('IdOutlet', $idOutlet ?? []));
            $pelanggan = $p->saring['Pelanggan'] ?? null;

            if ($pelanggan !== null) {
                $kueri->whereIn('IdPelanggan', Pelanggan::query()->where('Uuid', $pelanggan)->select('Id'));
            }

            if ($p->cari !== '') {
                $pola = PenerapKueriTabel::PolaCari($p->cari);
                $kueri->where(fn (Builder $q) => $q->where('Nomor', 'like', $pola)
                    ->orWhereIn('IdPelanggan', Pelanggan::query()->where('Nama', 'like', $pola)->select('Id')));
            }

            return $kueri;
        };
        $kueri = $dasar();
        $hari = $hariIni->toDateString();
        $umur = $p->AmbilDaftar('Umur', array_map(fn (KelompokUmurPiutang $k): string => $k->value, KelompokUmurPiutang::cases()));

        if ($umur !== []) {
            $kueri->where(function (Builder $dalam) use ($umur, $hari): void {
                foreach ($umur as $k) {
                    [$min, $maks] = KelompokUmurPiutang::from($k)->AmbilRentang();
                    $dalam->orWhere(fn (Builder $satu) => $satu
                        ->when($min !== null, fn ($q) => $q->whereRaw('DATEDIFF(?, `JatuhTempo`) >= ?', [$hari, $min]))
                        ->when($maks !== null, fn ($q) => $q->whereRaw('DATEDIFF(?, `JatuhTempo`) <= ?', [$hari, $maks])));
                }
            });
        }

        $tabel = PenerapKueriTabel::Terapkan($kueri, $p, ['Tanggal' => 'TanggalBisnis', 'JatuhTempo' => 'JatuhTempo', 'Nomor' => 'Nomor', 'Jumlah' => 'Jumlah'], fn (Collection $baris): array => $this->Petakan($baris, $hariIni));
        $kelompok = [];
        $total = Uang::Nol();

        foreach (KelompokUmurPiutang::cases() as $k) {
            $kelompok[$k->value] = ['Kunci' => $k->value, 'Label' => $k->AmbilLabel(), 'Sisa' => Uang::Nol(), 'Jumlah' => 0];
        }

        foreach ($dasar()->get() as $piutang) {
            $k = KelompokUmurPiutang::DariHariLewat(self::HitungHariLewat($piutang, $hariIni))->value;
            $kelompok[$k]['Sisa'] = $kelompok[$k]['Sisa']->Tambah($piutang->AmbilSisa());
            $kelompok[$k]['Jumlah']++;
            $total = $total->Tambah($piutang->AmbilSisa());
        }

        return [...$tabel, 'Ringkasan' => [
            'Total' => $total->KeString(),
            'Kelompok' => array_values(array_map(fn (array $k): array => [...$k, 'Sisa' => $k['Sisa']->KeString()], $kelompok)),
        ]];
    }

    /**
     * @return array{Data: list<array<string, mixed>>, Meta: array{Halaman: int, PerHalaman: int, Total: int, JumlahHalaman: int}}
     */
    public function Pelunasan(DataPermintaanTabel $p): array
    {
        $kueri = PembayaranPiutang::query();
        $status = $p->AmbilDaftar('Status', array_map(fn (StatusPembayaranPiutang $s): string => $s->value, StatusPembayaranPiutang::cases()));

        if ($status !== []) {
            $kueri->whereIn('Status', $status);
        }

        if (($p->saring['Pelanggan'] ?? null) !== null) {
            $kueri->whereIn('IdPelanggan', Pelanggan::query()->where('Uuid', $p->saring['Pelanggan'])->select('Id'));
        }

        if ($p->cari !== '') {
            $kueri->where('Nomor', 'like', PenerapKueriTabel::PolaCari($p->cari));
        }

        return PenerapKueriTabel::Terapkan($kueri, $p, ['Tanggal' => 'Tanggal', 'Nomor' => 'Nomor', 'Jumlah' => 'Jumlah'], function (Collection $baris): array {
            $nama = Pelanggan::query()->whereKey($baris->pluck('IdPelanggan')->all())->pluck('Nama', 'Id');

            return array_values($baris->map(fn (PembayaranPiutang $d): array => [
                'Uuid' => $d->Uuid,
                'Nomor' => $d->Nomor,
                'Tanggal' => $d->Tanggal->format('Y-m-d'),
                'NamaPelanggan' => (string) ($nama[$d->IdPelanggan] ?? ''),
                'Jumlah' => (string) $d->Jumlah,
                'Status' => $d->Status->value,
                'LabelStatus' => $d->Status->AmbilLabel(),
            ])->all());
        });
    }

    /**
     * Daftar hapus-buku piutang (E8). `cari` mencocokkan nomor dokumen, nomor piutang, atau Uuid dokumen (tautan jurnal).
     *
     * @return array{Data: list<array<string, mixed>>, Meta: array{Halaman: int, PerHalaman: int, Total: int, JumlahHalaman: int}}
     */
    public function HapusBuku(DataPermintaanTabel $p): array
    {
        $kueri = HapusBukuPiutang::query();
        $status = $p->AmbilDaftar('Status', array_map(fn (StatusDokumenTerposting $s): string => $s->value, StatusDokumenTerposting::cases()));

        if ($status !== []) {
            $kueri->whereIn('Status', $status);
        }

        if ($p->cari !== '') {
            $pola = PenerapKueriTabel::PolaCari($p->cari);
            $kueri->where(fn (Builder $dalam) => $dalam->where('Nomor', 'like', $pola)->orWhere('NomorPiutang', 'like', $pola)->orWhere('Uuid', $p->cari));
        }

        return PenerapKueriTabel::Terapkan($kueri, $p, ['Tanggal' => 'Tanggal', 'Nomor' => 'Nomor', 'Jumlah' => 'Jumlah'], function (Collection $baris): array {
            $nama = Pelanggan::query()->whereKey($baris->pluck('IdPelanggan')->filter()->all())->pluck('Nama', 'Id');

            return array_values($baris->map(fn (HapusBukuPiutang $d): array => [
                'Uuid' => $d->Uuid,
                'Nomor' => $d->Nomor,
                'NomorPiutang' => $d->NomorPiutang,
                'Tanggal' => $d->Tanggal->format('Y-m-d'),
                'NamaPelanggan' => $d->IdPelanggan === null ? null : (string) ($nama[$d->IdPelanggan] ?? ''),
                'Jumlah' => (string) $d->Jumlah,
                'Alasan' => $d->Alasan,
                'Status' => $d->Status->value,
                'LabelStatus' => $d->Status->AmbilLabel(),
                'AlasanBatal' => $d->AlasanBatal,
            ])->all());
        });
    }

    /**
     * Piutang terbuka satu pelanggan, urut jatuh tempo (formulir pelunasan).
     *
     * @return list<array{Uuid: string, Nomor: string, TanggalBisnis: string, JatuhTempo: string, Jumlah: string, Sisa: string}>
     */
    public function AmbilTerbukaPelanggan(int $idPelanggan): array
    {
        return array_values(Piutang::query()
            ->where('IdPelanggan', $idPelanggan)
            ->whereIn('Status', [StatusPiutang::BelumLunas->value, StatusPiutang::DibayarSebagian->value])
            ->orderBy('JatuhTempo')
            ->orderBy('Id')
            ->get()
            ->map(fn (Piutang $p): array => [
                'Uuid' => $p->Uuid,
                'Nomor' => $p->Nomor,
                'TanggalBisnis' => $p->TanggalBisnis->format('Y-m-d'),
                'JatuhTempo' => $p->JatuhTempo->format('Y-m-d'),
                'Jumlah' => (string) $p->Jumlah,
                'Sisa' => $p->AmbilSisa()->KeString(),
            ])->all());
    }

    /**
     * v3.37 nota tagihan: piutang terbuka satu pelanggan (dibatasi outlet pengguna bila ada), urut jatuh tempo, dengan
     * hari lewat jatuh tempo per `hariIni` (0 = belum/tepat jatuh tempo) dan total sisa & yang sudah lewat.
     *
     * @param  list<int>|null  $idOutlet
     * @return array{Baris: list<array{Nomor: string, TanggalBisnis: string, JatuhTempo: string, Jumlah: string, Dibayar: string, Sisa: string, HariLewat: int}>, TotalSisa: string, TotalLewat: string}
     */
    public function AmbilNotaTagihan(int $idPelanggan, CarbonImmutable $hariIni, ?array $idOutlet = null): array
    {
        $totalSisa = Uang::Nol();
        $totalLewat = Uang::Nol();
        $baris = [];

        $daftar = Piutang::query()
            ->where('IdPelanggan', $idPelanggan)
            ->whereIn('Status', [StatusPiutang::BelumLunas->value, StatusPiutang::DibayarSebagian->value])
            ->when($idOutlet !== null, fn ($kueri) => $kueri->whereIn('IdOutlet', $idOutlet))
            ->orderBy('JatuhTempo')
            ->orderBy('Id')
            ->get();

        foreach ($daftar as $p) {
            $sisa = $p->AmbilSisa();
            $hariLewat = max(0, self::HitungHariLewat($p, $hariIni));
            $totalSisa = $totalSisa->Tambah($sisa);
            $totalLewat = $hariLewat > 0 ? $totalLewat->Tambah($sisa) : $totalLewat;
            $baris[] = [
                'Nomor' => $p->Nomor,
                'TanggalBisnis' => $p->TanggalBisnis->format('Y-m-d'),
                'JatuhTempo' => $p->JatuhTempo->format('Y-m-d'),
                'Jumlah' => Uang::Dari((string) $p->Jumlah)->KeString(),
                'Dibayar' => Uang::Dari((string) $p->Jumlah)->Kurangi($sisa)->KeString(),
                'Sisa' => $sisa->KeString(),
                'HariLewat' => $hariLewat,
            ];
        }

        return ['Baris' => $baris, 'TotalSisa' => $totalSisa->KeString(), 'TotalLewat' => $totalLewat->KeString()];
    }

    /**
     * Pelanggan yang punya piutang terbuka (pilihan formulir & saring).
     *
     * @return list<array{Uuid: string, Nama: string}>
     */
    public function AmbilOpsiPelanggan(): array
    {
        $id = Piutang::query()->whereIn('Status', [StatusPiutang::BelumLunas->value, StatusPiutang::DibayarSebagian->value])->distinct()->pluck('IdPelanggan')->filter()->all();

        return array_values(Pelanggan::query()->whereKey($id)->orderBy('Nama')->get(['Uuid', 'Nama'])
            ->map(fn (Pelanggan $p): array => ['Uuid' => $p->Uuid, 'Nama' => $p->Nama])->all());
    }

    public static function HitungHariLewat(Piutang $piutang, CarbonImmutable $hariIni): int
    {
        return (int) CarbonImmutable::parse($piutang->JatuhTempo->toDateString(), 'UTC')->diffInDays(CarbonImmutable::parse($hariIni->toDateString(), 'UTC'), false);
    }

    /**
     * @param  Collection<int, Piutang>  $baris
     * @return list<array<string, mixed>>
     */
    private function Petakan(Collection $baris, CarbonImmutable $hariIni): array
    {
        $pelanggan = Pelanggan::query()->whereKey($baris->pluck('IdPelanggan')->filter()->all())->get(['Id', 'Uuid', 'Nama'])->keyBy('Id');
        // D-23 D: pengingat terakhir per piutang (tanpa tujuan pelanggan).
        $pengingat = PengingatPiutang::query()
            ->whereIn('IdPiutang', $baris->pluck('Id')->all())
            ->orderBy('Id')
            ->get(['Id', 'IdPiutang', 'Kanal', 'Status', 'DibuatPada'])
            ->keyBy('IdPiutang');

        return array_values($baris->map(function (Piutang $p) use ($pelanggan, $hariIni, $pengingat): array {
            $hari = self::HitungHariLewat($p, $hariIni);
            $umur = KelompokUmurPiutang::DariHariLewat($hari);
            $pel = $p->IdPelanggan === null ? null : $pelanggan->get($p->IdPelanggan);
            $ingat = $pengingat->get($p->Id);

            return [
                'Uuid' => $p->Uuid,
                'Nomor' => $p->Nomor,
                'Tanggal' => $p->TanggalBisnis->format('Y-m-d'),
                'JatuhTempo' => $p->JatuhTempo->format('Y-m-d'),
                'HariLewat' => $hari,
                'Umur' => $umur->value,
                'LabelUmur' => $umur->AmbilLabel(),
                'UuidPelanggan' => $pel?->Uuid,
                'NamaPelanggan' => $pel?->Nama,
                'Jumlah' => (string) $p->Jumlah,
                'Sisa' => $p->AmbilSisa()->KeString(),
                'Status' => $p->Status->value,
                'LabelStatus' => $p->Status->AmbilLabel(),
                'PengingatTerakhir' => $ingat === null ? null : [
                    'Waktu' => $ingat->DibuatPada?->toIso8601String(),
                    'Kanal' => $ingat->Kanal->value,
                    'Status' => $ingat->Status->value,
                    'LabelStatus' => $ingat->Status->AmbilLabel(),
                ],
            ];
        })->all());
    }
}
