<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Dukungan\Kueri;

use App\Domain\Bersama\Tabel\Data\DataPermintaanTabel;
use App\Domain\Bersama\Tabel\Layanan\PenerapKueriTabel;
use App\Domain\Dukungan\Enum\PrioritasTiketDukungan;
use App\Domain\Dukungan\Enum\StatusTiketDukungan;
use App\Domain\Dukungan\Model\TiketDukungan;
use App\Domain\Pengelola\Tenant\Layanan\KonteksPengelola;
use App\Domain\Pengelola\TimInternal\Model\PenggunaPengelola;
use App\Domain\Tenant\Kueri\RingkasanTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Antrean tiket dukungan semua tenant di Platform Pengelola (P-09). Dibaca lewat KonteksPengelola (tercatat di log
 * audit). Tiket terbuka diurutkan dari batas SLA terdekat; saringan status, prioritas, lewat SLA, dan penanggung jawab.
 */
final class AntreanTiketDukungan
{
    public const SARING_STATUS_TERBUKA = 'Terbuka';

    public const SARING_STATUS_SEMUA = 'Semua';

    public const SARING_MILIK_SAYA = 'Saya';

    public const SARING_MILIK_BELUM = 'Belum';

    public const KOLOM_URUT = ['BatasSlaPada', 'DibuatPada'];

    public const KOLOM_SARING = ['Status', 'Prioritas', 'LewatSla', 'Milik'];

    public function __construct(
        private readonly KonteksPengelola $konteks,
        private readonly RingkasanTenant $ringkasanTenant,
    ) {}

    /**
     * Antrean untuk `TabelData` (D-16). Saring: `Status` (`Terbuka` bawaan, `Semua`, atau satu status), `Prioritas`
     * (pilihan banyak), `LewatSla` (`1`), `Milik` (`Saya`/`Belum`); cari nomor/judul. Tanpa urut pilihan: tiket terbuka
     * dari batas SLA terdekat, selain itu terbaru dulu.
     *
     * @return array{Data: list<array<string, mixed>>, Meta: array{Halaman: int, PerHalaman: int, Total: int, JumlahHalaman: int}}
     */
    public function AmbilTabel(PenggunaPengelola $pelaku, DataPermintaanTabel $permintaan): array
    {
        $saring = [
            'Status' => $permintaan->saring['Status'] ?? self::SARING_STATUS_TERBUKA,
            'Prioritas' => $permintaan->AmbilDaftar('Prioritas', array_map(fn (PrioritasTiketDukungan $p): string => $p->value, PrioritasTiketDukungan::cases())),
            'LewatSla' => $permintaan->AmbilBoolean('LewatSla') === true,
            'Milik' => $permintaan->saring['Milik'] ?? '',
            'Kata' => $permintaan->cari,
        ];

        return $this->konteks->JalankanLintasTenant('Membuka antrean tiket dukungan', function () use ($pelaku, $saring, $permintaan): array {
            $kueri = $this->BangunKueri($pelaku, $saring);

            if ($permintaan->urut === []) {
                $saring['Status'] === self::SARING_STATUS_TERBUKA || StatusTiketDukungan::tryFrom($saring['Status'])?->CekTerbuka() === true
                    ? $kueri->orderBy('BatasSlaPada')->orderBy('Id')
                    : $kueri->orderByDesc('Id');
            }

            return PenerapKueriTabel::Terapkan($kueri, $permintaan, ['BatasSlaPada' => 'BatasSlaPada', 'DibuatPada' => 'Id'], fn (Collection $tiket): array => $this->Petakan(array_values($tiket->all())));
        });
    }

    /**
     * @param  array{Status: string, Prioritas: list<string>, LewatSla: bool, Milik: string, Kata: string}  $saring
     * @return Builder<TiketDukungan>
     */
    private function BangunKueri(PenggunaPengelola $pelaku, array $saring): Builder
    {
        $terbuka = array_map(fn (StatusTiketDukungan $status) => $status->value, StatusTiketDukungan::AmbilTerbuka());
        $kueri = $this->konteks->KueriLintas(TiketDukungan::class);

        match ($saring['Status']) {
            self::SARING_STATUS_SEMUA => null,
            self::SARING_STATUS_TERBUKA => $kueri->whereIn('Status', $terbuka),
            // Status tak dikenal menghasilkan daftar kosong, bukan semua tiket.
            default => $kueri->where('Status', StatusTiketDukungan::tryFrom($saring['Status']) === null ? '-' : $saring['Status']),
        };

        if ($saring['Prioritas'] !== []) {
            $kueri->whereIn('Prioritas', $saring['Prioritas']);
        }

        if ($saring['LewatSla']) {
            $kueri->whereNull('ResponsPertamaPada')->whereIn('Status', $terbuka)->where('BatasSlaPada', '<', now());
        }

        match ($saring['Milik']) {
            self::SARING_MILIK_SAYA => $kueri->where('IdPenanggungJawab', $pelaku->Id),
            self::SARING_MILIK_BELUM => $kueri->whereNull('IdPenanggungJawab'),
            default => null,
        };

        if ($saring['Kata'] !== '') {
            $kata = PenerapKueriTabel::PolaCari($saring['Kata']);
            $kueri->where(fn (Builder $bagian) => $bagian->where('Nomor', 'like', $kata)->orWhere('Judul', 'like', $kata));
        }

        return $kueri;
    }

    /**
     * @param  list<TiketDukungan>  $tiket
     * @return list<array<string, mixed>>
     */
    private function Petakan(array $tiket): array
    {
        $tenant = collect($this->ringkasanTenant->Ambil(array_values(array_unique(array_map(fn (TiketDukungan $baris) => $baris->IdTenant, $tiket)))))
            ->keyBy('Id');
        $idPenanggungJawab = array_values(array_filter(array_unique(array_map(fn (TiketDukungan $baris) => $baris->IdPenanggungJawab, $tiket))));
        $namaPenanggungJawab = $idPenanggungJawab === []
            ? collect()
            : PenggunaPengelola::query()->whereKey($idPenanggungJawab)->pluck('Nama', 'Id');
        $sekarang = now();

        return array_map(fn (TiketDukungan $baris): array => [
            'Uuid' => $baris->Uuid,
            'Nomor' => $baris->Nomor,
            'Judul' => $baris->Judul,
            'NamaTenant' => $tenant->get($baris->IdTenant)['Nama'] ?? "Tenant #{$baris->IdTenant}",
            'Kategori' => $baris->Kategori->AmbilLabel(),
            'Prioritas' => $baris->Prioritas->value,
            'LabelPrioritas' => $baris->Prioritas->AmbilLabel(),
            'Status' => $baris->Status->value,
            'LabelStatus' => $baris->Status->AmbilLabel(),
            'PenanggungJawab' => $baris->IdPenanggungJawab === null ? null : (string) ($namaPenanggungJawab[$baris->IdPenanggungJawab] ?? '-'),
            'BatasSlaPada' => $baris->BatasSlaPada->toIso8601String(),
            'ResponsPertamaPada' => $baris->ResponsPertamaPada?->toIso8601String(),
            'LewatSla' => $baris->CekLewatSla($sekarang),
            'TingkatEskalasi' => $baris->TingkatEskalasi->value,
            'LabelEskalasi' => $baris->TingkatEskalasi->AmbilLabel(),
            'PesanTerakhirPada' => $baris->PesanTerakhirPada?->toIso8601String(),
            'DibuatPada' => $baris->DibuatPada->toIso8601String(),
        ], $tiket);
    }
}
