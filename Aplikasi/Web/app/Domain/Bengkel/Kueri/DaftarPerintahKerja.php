<?php

declare(strict_types=1);

namespace App\Domain\Bengkel\Kueri;

use App\Domain\Bengkel\Enum\StatusPerintahKerja;
use App\Domain\Bengkel\Layanan\TautanPersetujuanServis;
use App\Domain\Bengkel\Model\Kendaraan;
use App\Domain\Bengkel\Model\PerintahKerja;
use App\Domain\Bengkel\Model\PerintahKerjaDetail;
use App\Domain\Bersama\Tabel\Data\DataPermintaanTabel;
use App\Domain\Bersama\Tabel\Layanan\PenerapKueriTabel;
use App\Domain\Karyawan\Kueri\JadwalStafReservasi;
use App\Domain\Organisasi\Kueri\PetaUuidOutlet;
use App\Domain\Pelanggan\Kueri\IdentitasPelanggan;
use App\Domain\Penjualan\Kueri\RingkasanPenjualanDokumen;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Daftar perintah kerja bengkel (§9.10) untuk `TabelData`: cari nomor/nomor polisi, saring tanggal terima (zona
 * tenant), status, mekanik, outlet, kendaraan, pelanggan, dan `Perhatian` (`ServisJatuhTempo` = servis berkala jatuh
 * tempo; `MenungguLama` = estimasi menunggu persetujuan lebih dari 24 jam). Pengguna berbatas outlet hanya melihat
 * outlet aksesnya. Juga dipakai riwayat servis per kendaraan.
 */
final class DaftarPerintahKerja
{
    public const KOLOM_URUT = ['DibuatPada', 'Nomor', 'Total', 'ServisBerikutnyaPada'];

    public const KOLOM_SARING = ['Tanggal', 'Status', 'Mekanik', 'Outlet', 'Kendaraan', 'Pelanggan', 'Perhatian'];

    public const URUT_BAWAAN = '-DibuatPada';

    /** Servis berkala dianggap jatuh tempo dari 7 hari sebelum tanggalnya sampai 30 hari sesudahnya. */
    public const HARI_SEBELUM_JATUH_TEMPO = 7;

    public const HARI_SESUDAH_JATUH_TEMPO = 30;

    public function __construct(
        private readonly IdentitasPelanggan $pelanggan,
        private readonly PetaUuidOutlet $outlet,
        private readonly JadwalStafReservasi $staf,
        private readonly RingkasanPenjualanDokumen $penjualan,
    ) {}

    /**
     * @param  list<int>|null  $idOutletBoleh
     * @return array{Data: list<array<string, mixed>>, Meta: array{Halaman: int, PerHalaman: int, Total: int, JumlahHalaman: int}}
     */
    public function AmbilTabel(DataPermintaanTabel $p, ?array $idOutletBoleh, string $zona, CarbonImmutable $hariIni): array
    {
        $tanggal = $p->AmbilRentangTanggal('Tanggal');
        $status = $p->AmbilDaftar('Status', array_map(fn (StatusPerintahKerja $s): string => $s->value, StatusPerintahKerja::cases()));
        $uuidOutlet = $p->AmbilDaftar('Outlet');
        $uuidMekanik = $p->AmbilDaftar('Mekanik');
        $uuidKendaraan = $p->AmbilDaftar('Kendaraan');
        $uuidPelanggan = $p->AmbilDaftar('Pelanggan');
        $perhatian = $p->AmbilDaftar('Perhatian', ['ServisJatuhTempo', 'MenungguLama']);
        $idMekanik = array_values(array_filter(array_map(fn (string $u): ?int => $this->staf->CariStaf(strtoupper($u))['Id'] ?? null, $uuidMekanik), 'is_int'));
        $idPelanggan = array_values(array_filter(array_map(fn (string $u): ?int => $this->pelanggan->CariId(strtoupper($u)), $uuidPelanggan), 'is_int'));
        $kata = $p->cari;

        $kueri = $this->Dasar($idOutletBoleh)
            ->when($tanggal['Dari'] !== null, fn (Builder $k) => $k->where('DibuatPada', '>=', CarbonImmutable::parse((string) $tanggal['Dari'], $zona)->startOfDay()->utc()))
            ->when($tanggal['Sampai'] !== null, fn (Builder $k) => $k->where('DibuatPada', '<', CarbonImmutable::parse((string) $tanggal['Sampai'], $zona)->startOfDay()->addDay()->utc()))
            ->when($status !== [], fn (Builder $k) => $k->whereIn('Status', $status))
            ->when($uuidOutlet !== [], fn (Builder $k) => $k->whereIn('IdOutlet', array_values($this->outlet->AmbilIdDariUuid($uuidOutlet)) ?: [0]))
            ->when($uuidMekanik !== [], fn (Builder $k) => $k->whereIn('Id', PerintahKerjaDetail::query()->whereIn('IdKaryawan', $idMekanik ?: [0])->select('IdPerintahKerja')))
            ->when($uuidKendaraan !== [], fn (Builder $k) => $k->whereIn('IdKendaraan', Kendaraan::query()->whereIn('Uuid', array_map('strtoupper', $uuidKendaraan))->select('Id')))
            ->when($uuidPelanggan !== [], fn (Builder $k) => $k->whereIn('IdPelanggan', $idPelanggan ?: [0]))
            ->when($perhatian !== [], fn (Builder $k) => $k->where(function (Builder $q) use ($perhatian, $hariIni): void {
                if (in_array('ServisJatuhTempo', $perhatian, true)) {
                    $q->orWhere(fn (Builder $a) => self::TerapkanServisJatuhTempo($a, $hariIni));
                }

                if (in_array('MenungguLama', $perhatian, true)) {
                    $q->orWhere(fn (Builder $a) => self::TerapkanMenungguLama($a));
                }
            }))
            ->when($kata !== '', function (Builder $k) use ($kata): void {
                $pola = PenerapKueriTabel::PolaCari($kata);
                $polaPlat = PenerapKueriTabel::PolaCari(mb_strtoupper(str_replace(' ', '', $kata)));
                $k->where(fn (Builder $q) => $q->where('Nomor', 'like', $pola)
                    ->orWhereIn('IdKendaraan', Kendaraan::query()->whereRaw("REPLACE(`NomorPolisi`, ' ', '') like ?", [$polaPlat])->select('Id')));
            });

        return PenerapKueriTabel::Terapkan(
            $kueri,
            $p,
            ['DibuatPada' => 'DibuatPada', 'Nomor' => 'Nomor', 'Total' => 'Total', 'ServisBerikutnyaPada' => 'ServisBerikutnyaPada'],
            fn (Collection $baris): array => $this->Petakan($baris),
        );
    }

    /**
     * @param  list<int>|null  $idOutletBoleh
     */
    public function Cari(string $uuid, ?array $idOutletBoleh): ?PerintahKerja
    {
        return $this->Dasar($idOutletBoleh)->where('Uuid', strtoupper($uuid))->first();
    }

    /**
     * Riwayat servis satu kendaraan (§9.10): semua perintah kerja terbaru dulu (maks. 100) beserta penjualan penagihnya.
     *
     * @param  list<int>|null  $idOutletBoleh
     * @return list<array<string, mixed>>
     */
    public function RiwayatKendaraan(int $idKendaraan, ?array $idOutletBoleh): array
    {
        return $this->Petakan($this->Dasar($idOutletBoleh)->where('IdKendaraan', $idKendaraan)->orderByDesc('Id')->limit(100)->get());
    }

    /**
     * Servis berkala jatuh tempo: perintah kerja yang sudah selesai/ditagih dengan tanggal servis berikutnya dalam
     * jendela, dan belum ada perintah kerja yang lebih baru untuk kendaraan itu (kendaraannya sudah datang lagi).
     *
     * @param  Builder<PerintahKerja>  $k
     */
    public static function TerapkanServisJatuhTempo(Builder $k, CarbonImmutable $hariIni): void
    {
        $k->whereIn('Status', [StatusPerintahKerja::Selesai->value, StatusPerintahKerja::Ditagih->value])
            ->whereBetween('ServisBerikutnyaPada', [
                $hariIni->subDays(self::HARI_SESUDAH_JATUH_TEMPO)->toDateString(),
                $hariIni->addDays(self::HARI_SEBELUM_JATUH_TEMPO)->toDateString(),
            ])
            ->whereNotExists(fn ($q) => $q->selectRaw('1')
                ->from('PerintahKerja as Baru')
                ->whereColumn('Baru.IdTenant', 'PerintahKerja.IdTenant')
                ->whereColumn('Baru.IdKendaraan', 'PerintahKerja.IdKendaraan')
                ->whereColumn('Baru.Id', '>', 'PerintahKerja.Id')
                ->where('Baru.Status', '!=', StatusPerintahKerja::Dibatalkan->value));
    }

    /**
     * Menunggu persetujuan lebih dari 24 jam (tautan berlaku 7 hari, jadi dibuat lebih dari sehari lalu).
     *
     * @param  Builder<PerintahKerja>  $k
     */
    public static function TerapkanMenungguLama(Builder $k): void
    {
        $k->where('Status', StatusPerintahKerja::MenungguPersetujuan->value)
            ->where('TokenPersetujuanKedaluwarsaPada', '<', now()->addDays(TautanPersetujuanServis::HARI_BERLAKU)->subDay());
    }

    /**
     * @param  Collection<int, PerintahKerja>  $baris
     * @return list<array<string, mixed>>
     */
    public function Petakan(Collection $baris): array
    {
        $kendaraan = $baris->isEmpty() ? collect() : Kendaraan::query()->whereIn('Id', $baris->pluck('IdKendaraan')->unique()->values()->all())->get()->keyBy('Id');
        $pelanggan = $this->pelanggan->AmbilNamaBanyak(array_values($baris->pluck('IdPelanggan')->all()));
        $outlet = [];

        foreach ($this->outlet->AmbilRingkas(array_values(array_unique($baris->pluck('IdOutlet')->all()))) as $o) {
            $outlet[$o['Id']] = $o;
        }

        $detailMekanik = $baris->isEmpty() ? collect() : PerintahKerjaDetail::query()
            ->whereIn('IdPerintahKerja', $baris->pluck('Id')->all())
            ->whereNotNull('IdKaryawan')
            ->get(['IdPerintahKerja', 'IdKaryawan'])
            ->groupBy('IdPerintahKerja');
        $mekanik = $this->staf->AmbilRingkas(array_values(array_unique(array_filter($detailMekanik->flatten()->pluck('IdKaryawan')->all(), 'is_int'))));
        $penjualan = $this->penjualan->AmbilBanyak(array_values(array_filter($baris->pluck('IdPenjualan')->all(), 'is_int')));

        return array_values($baris->map(function (PerintahKerja $pk) use ($kendaraan, $pelanggan, $outlet, $detailMekanik, $mekanik, $penjualan): array {
            $k = $kendaraan->get($pk->IdKendaraan);
            $namaMekanik = array_values(array_unique(array_filter(array_map(
                fn ($d): ?string => $mekanik[$d->IdKaryawan]['Nama'] ?? null,
                ($detailMekanik->get($pk->Id) ?? collect())->all(),
            ))));

            return [
                'Uuid' => $pk->Uuid,
                'Nomor' => $pk->Nomor,
                'DibuatPada' => $pk->DibuatPada?->toIso8601ZuluString(),
                'Status' => $pk->Status->value,
                'LabelStatus' => $pk->Status->AmbilLabel(),
                'Kendaraan' => $k === null ? null : ['Uuid' => $k->Uuid, 'Jenis' => $k->Jenis->value, 'NomorPolisi' => $k->NomorPolisi, 'Label' => $k->AmbilLabel()],
                'Pelanggan' => ['Uuid' => $pelanggan[$pk->IdPelanggan]['Uuid'] ?? null, 'Nama' => $pelanggan[$pk->IdPelanggan]['Nama'] ?? '-'],
                'KmMasuk' => $pk->KmMasuk,
                'Keluhan' => $pk->Keluhan,
                'Total' => (string) $pk->Total,
                'TotalDisetujui' => (string) $pk->TotalDisetujui,
                'Mekanik' => $namaMekanik,
                'Outlet' => ['Uuid' => $outlet[$pk->IdOutlet]['Uuid'] ?? null, 'Nama' => $outlet[$pk->IdOutlet]['Nama'] ?? ''],
                'EstimasiSelesaiPada' => $pk->EstimasiSelesaiPada?->toIso8601ZuluString(),
                'ServisBerikutnyaPada' => $pk->ServisBerikutnyaPada?->toDateString(),
                'ServisBerikutnyaKm' => $pk->ServisBerikutnyaKm,
                'Penjualan' => $pk->IdPenjualan === null ? null : ($penjualan[$pk->IdPenjualan] ?? null),
                'TujuanStatus' => array_map(fn (StatusPerintahKerja $s): string => $s->value, $pk->Status->AmbilTujuanManual()),
            ];
        })->all());
    }

    /**
     * @param  list<int>|null  $idOutletBoleh
     * @return Builder<PerintahKerja>
     */
    private function Dasar(?array $idOutletBoleh): Builder
    {
        return PerintahKerja::query()->when($idOutletBoleh !== null, fn (Builder $k) => $k->whereIn('IdOutlet', $idOutletBoleh === [] ? [0] : $idOutletBoleh));
    }
}
