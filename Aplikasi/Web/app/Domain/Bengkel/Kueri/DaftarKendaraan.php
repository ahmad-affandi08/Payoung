<?php

declare(strict_types=1);

namespace App\Domain\Bengkel\Kueri;

use App\Domain\Bengkel\Enum\JenisUnitServis;
use App\Domain\Bengkel\Enum\StatusPerintahKerja;
use App\Domain\Bengkel\Model\Kendaraan;
use App\Domain\Bengkel\Model\PerintahKerja;
use App\Domain\Bersama\Tabel\Data\DataPermintaanTabel;
use App\Domain\Bersama\Tabel\Layanan\PenerapKueriTabel;
use App\Domain\Pelanggan\Kueri\IdentitasPelanggan;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Kendaraan pelanggan bengkel (§9.10): daftar `TabelData` (cari nomor polisi/merek/tipe, saring pelanggan & status)
 * dan pencarian untuk pemilih kendaraan di formulir perintah kerja. Nama pelanggan dibaca lewat kueri publik domain
 * Pelanggan.
 */
final class DaftarKendaraan
{
    public const KOLOM_URUT = ['NomorPolisi', 'Merek', 'KmTerakhir', 'DiubahPada'];

    public const KOLOM_SARING = ['Pelanggan', 'Aktif', 'Jenis'];

    public const URUT_BAWAAN = 'NomorPolisi';

    public function __construct(private readonly IdentitasPelanggan $pelanggan) {}

    /**
     * @return array{Data: list<array<string, mixed>>, Meta: array{Halaman: int, PerHalaman: int, Total: int, JumlahHalaman: int}}
     */
    public function AmbilTabel(DataPermintaanTabel $p): array
    {
        $uuidPelanggan = $p->AmbilDaftar('Pelanggan');
        $aktif = $p->AmbilDaftar('Aktif', ['Ya', 'Tidak']);
        $idPelanggan = array_values(array_filter(array_map(fn (string $u): ?int => $this->pelanggan->CariId(strtoupper($u)), $uuidPelanggan), 'is_int'));
        $kueri = Kendaraan::query()
            ->when($uuidPelanggan !== [], fn (Builder $k) => $k->whereIn('IdPelanggan', $idPelanggan ?: [0]))
            ->when(count($aktif) === 1, fn (Builder $k) => $k->where('Aktif', $aktif[0] === 'Ya'))
            ->when(($jenis = $p->AmbilDaftar('Jenis', array_column(JenisUnitServis::cases(), 'value'))) !== [], fn (Builder $k) => $k->whereIn('Jenis', $jenis))
            ->when($p->cari !== '', function (Builder $k) use ($p): void {
                $pola = PenerapKueriTabel::PolaCari($p->cari);
                $polaPlat = PenerapKueriTabel::PolaCari(mb_strtoupper(str_replace(' ', '', $p->cari)));
                $k->where(fn (Builder $q) => $q->where('NomorPolisi', 'like', $pola)
                    ->orWhereRaw("REPLACE(`NomorPolisi`, ' ', '') like ?", [$polaPlat])
                    ->orWhere('Merek', 'like', $pola)
                    ->orWhere('Tipe', 'like', $pola));
            });

        return PenerapKueriTabel::Terapkan(
            $kueri,
            $p,
            ['NomorPolisi' => 'NomorPolisi', 'Merek' => 'Merek', 'KmTerakhir' => 'KmTerakhir', 'DiubahPada' => 'DiubahPada'],
            fn (Collection $baris): array => $this->Petakan($baris),
        );
    }

    /**
     * Pemilih kendaraan formulir perintah kerja: kendaraan aktif yang cocok nomor polisi/merek/tipe, disaring pemilik
     * bila pelanggan sudah dipilih. Kata kosong = 20 kendaraan pertama.
     *
     * @return list<array<string, mixed>>
     */
    public function Cari(string $kata, ?string $uuidPelanggan, int $batas = 20): array
    {
        $kata = trim($kata);
        $idPelanggan = $uuidPelanggan === null || $uuidPelanggan === '' ? null : ($this->pelanggan->CariId(strtoupper($uuidPelanggan)) ?? 0);
        $daftar = Kendaraan::query()
            ->where('Aktif', true)
            ->when($idPelanggan !== null, fn (Builder $k) => $k->where('IdPelanggan', $idPelanggan))
            ->when($kata !== '', function (Builder $k) use ($kata): void {
                $polaPlat = PenerapKueriTabel::PolaCari(mb_strtoupper(str_replace(' ', '', $kata)));
                $pola = PenerapKueriTabel::PolaCari($kata);
                $k->where(fn (Builder $q) => $q->whereRaw("REPLACE(`NomorPolisi`, ' ', '') like ?", [$polaPlat])->orWhere('Merek', 'like', $pola)->orWhere('Tipe', 'like', $pola));
            })
            ->orderBy('NomorPolisi')
            ->orderBy('Id')
            ->limit(max(1, min(50, $batas)))
            ->get();

        return $this->Petakan($daftar);
    }

    /**
     * Kendaraan seorang pelanggan untuk halaman detail pelanggan (aktif lebih dulu).
     *
     * @return list<array<string, mixed>>
     */
    public function AmbilMilikPelanggan(int $idPelanggan): array
    {
        return $this->Petakan(Kendaraan::query()->where('IdPelanggan', $idPelanggan)->orderByDesc('Aktif')->orderBy('NomorPolisi')->limit(50)->get());
    }

    /**
     * @param  Collection<int, Kendaraan>  $baris
     * @return list<array<string, mixed>>
     */
    public function Petakan(Collection $baris): array
    {
        $pelanggan = $this->pelanggan->AmbilNamaBanyak(array_values($baris->pluck('IdPelanggan')->all()));
        $servis = $baris->isEmpty() ? collect() : PerintahKerja::query()
            ->whereIn('IdKendaraan', $baris->pluck('Id')->all())
            ->where('Status', '!=', StatusPerintahKerja::Dibatalkan->value)
            ->selectRaw('`IdKendaraan`, MAX(`DibuatPada`) as `Terakhir`, COUNT(*) as `Jumlah`')
            ->groupBy('IdKendaraan')
            ->get()
            ->keyBy('IdKendaraan');

        return array_values($baris->map(function (Kendaraan $k) use ($pelanggan, $servis): array {
            $s = $servis->get($k->Id);

            return [
                'Uuid' => $k->Uuid,
                'Jenis' => $k->Jenis->value,
                'NomorPolisi' => $k->NomorPolisi,
                'Merek' => $k->Merek,
                'Tipe' => $k->Tipe,
                'Tahun' => $k->Tahun,
                'Warna' => $k->Warna,
                'NomorRangka' => $k->NomorRangka,
                'NomorMesin' => $k->NomorMesin,
                'KmTerakhir' => $k->KmTerakhir,
                'Catatan' => $k->Catatan,
                'Aktif' => $k->Aktif,
                'Label' => $k->AmbilLabel(),
                'Pelanggan' => ['Uuid' => $pelanggan[$k->IdPelanggan]['Uuid'] ?? null, 'Nama' => $pelanggan[$k->IdPelanggan]['Nama'] ?? '-'],
                'JumlahServis' => (int) ($s?->getAttribute('Jumlah') ?? 0),
                'ServisTerakhirPada' => $s?->getAttribute('Terakhir') === null ? null : CarbonImmutable::parse((string) $s->getAttribute('Terakhir'), 'UTC')->toIso8601ZuluString(),
            ];
        })->all());
    }
}
