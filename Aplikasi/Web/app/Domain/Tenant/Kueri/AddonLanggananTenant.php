<?php

declare(strict_types=1);

namespace App\Domain\Tenant\Kueri;

use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Tenant\Enum\StatusPaket;
use App\Domain\Tenant\Layanan\KelayakanBeliAddon;
use App\Domain\Tenant\Layanan\PenghitungProrataAddon;
use App\Domain\Tenant\Model\Addon;
use App\Domain\Tenant\Model\Fitur;
use App\Domain\Tenant\Model\Langganan;
use App\Domain\Tenant\Model\LanggananAddon;
use App\Domain\Tenant\Model\Paket;
use Illuminate\Support\Carbon;

/**
 * Add-on tenant (D-49) untuk halaman Langganan dan dialog fitur terkunci: add-on yang dimiliki (aktif atau baru
 * berakhir) dan katalog yang bisa dibeli, lengkap dengan alasan bila belum bisa dibeli mandiri serta perkiraan harga
 * prorata sebelum PPN. Perkiraan memakai rumus yang sama dengan tagihan (`PenghitungProrataAddon`).
 */
final class AddonLanggananTenant
{
    public function __construct(
        private readonly KelayakanBeliAddon $kelayakan,
        private readonly PenghitungProrataAddon $prorata,
    ) {}

    /**
     * @return array{Alasan: string|null, Dimiliki: list<array<string, mixed>>, Tersedia: list<array<string, mixed>>}
     */
    public function Ambil(int $idTenant): array
    {
        $sekarang = Carbon::now();
        $langganan = Langganan::query()->where('IdTenant', $idTenant)->first();
        $paket = $langganan === null ? null : Paket::query()->whereKey($langganan->IdPaket)->first();
        $alasan = $this->kelayakan->Periksa($langganan, $paket, $sekarang);
        $namaFitur = Fitur::query()->pluck('Nama', 'Kunci')->all();

        $dimiliki = [];
        $idDimiliki = [];

        foreach (LanggananAddon::query()->with('Addon')->where('IdTenant', $idTenant)->orderByDesc('SelesaiPada')->get() as $milik) {
            $aktif = $milik->CekAktifPada($sekarang);
            $idDimiliki[$milik->IdAddon] = $aktif;
            $dimiliki[] = [
                'Uuid' => $milik->Uuid,
                'Kode' => $milik->Addon->Kode,
                'Nama' => $milik->Addon->Nama,
                'Fitur' => $milik->Addon->KunciFitur === null ? null : ($namaFitur[$milik->Addon->KunciFitur] ?? $milik->Addon->KunciFitur),
                'HargaBulanan' => (string) $milik->Addon->HargaBulanan,
                'Jumlah' => $milik->Jumlah,
                'MulaiPada' => $milik->MulaiPada->toIso8601ZuluString(),
                'SelesaiPada' => $milik->SelesaiPada->toIso8601ZuluString(),
                'Aktif' => $aktif,
                'Berhenti' => $milik->BerhentiPada !== null,
            ];
        }

        $tersedia = [];

        foreach (Addon::query()->where('Status', StatusPaket::Aktif->value)->orderBy('HargaBulanan')->orderBy('Nama')->get() as $addon) {
            if ($idDimiliki[$addon->Id] ?? false) {
                continue;
            }

            $tersedia[] = [
                'Kode' => $addon->Kode,
                'Nama' => $addon->Nama,
                'Fitur' => $addon->KunciFitur === null ? null : ($namaFitur[$addon->KunciFitur] ?? $addon->KunciFitur),
                'HargaBulanan' => (string) $addon->HargaBulanan,
                // Add-on penambah batas (outlet/perangkat tambahan) bisa dibeli lebih dari satu.
                'BisaJumlah' => $addon->TambahanBatas !== null,
                'HargaProrata' => $alasan === null && $langganan !== null ? $this->PerkirakanProrata($langganan, $addon, $sekarang)?->KeString() : null,
            ];
        }

        return ['Alasan' => $alasan, 'Dimiliki' => $dimiliki, 'Tersedia' => $tersedia];
    }

    /** Perkiraan harga prorata (sebelum PPN) bila add-on dibeli sekarang; null bila periode langganan belum diketahui. */
    public function PerkirakanProrata(Langganan $langganan, Addon $addon, Carbon $sekarang): ?Uang
    {
        if ($langganan->PeriodeMulai === null || $langganan->PeriodeSelesai === null) {
            return null;
        }

        return $this->prorata->Hitung(
            Uang::Dari($addon->HargaBulanan),
            $langganan->AmbilJumlahBulanSiklus(),
            1,
            $sekarang,
            $langganan->PeriodeMulai,
            $langganan->PeriodeSelesai,
        )['Subtotal'];
    }
}
