<?php

declare(strict_types=1);

namespace App\Domain\Kasir\Layanan;

use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Bersama\Tindakan\Data\DataButirTindakan;
use App\Domain\Bersama\Tindakan\Data\DataKonteksTindakan;
use App\Domain\Bersama\Tindakan\Data\DataRincianTindakan;
use App\Domain\Bersama\Tindakan\Enum\TingkatTindakan;
use App\Domain\Bersama\Tindakan\Kontrak\PenyediaTindakan;
use App\Domain\Bersama\Tindakan\Kontrak\TerbatasSektor;
use App\Domain\Bersama\Tindakan\Layanan\PembuatButirTinjauan;
use App\Domain\Kasir\Enum\StatusShift;
use App\Domain\Kasir\Model\MutasiKas;
use App\Domain\Kasir\Model\Shift;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Kotak Tindakan domain Kasir (D-23 C, izin lihat `laporan.penjualan.lihat`, dibatasi outlet akses):
 * - shift & kas masuk/keluar dari kasir yang diterima dengan `PerluTinjauan` dan belum ditandai dicek;
 * - shift yang masih terbuka lebih dari [JAM_SHIFT_LAMA] jam (lupa ditutup: kas & laporan harian belum final).
 */
final class PenyediaTindakanKasir implements PenyediaTindakan, TerbatasSektor
{
    public const JAM_SHIFT_LAMA = 24;

    public function AmbilSektor(): ?array
    {
        return null;
    }

    public function AmbilKecualiSektor(): array
    {
        return ['BIZ'];
    }

    public function Kumpulkan(DataKonteksTindakan $konteks): array
    {
        if (! $konteks->CekIzin('laporan.penjualan.lihat')) {
            return [];
        }

        $idOutlet = $konteks->idOutletBoleh;
        $lama = Shift::query()
            ->whereIn('Status', [StatusShift::Terbuka->value, StatusShift::DibukaUlang->value])
            ->where('DibukaPada', '<', CarbonImmutable::now()->subHours(self::JAM_SHIFT_LAMA))
            ->when($idOutlet !== null, fn ($k) => $k->whereIn('IdOutlet', $idOutlet));
        $jumlahLama = (clone $lama)->count();

        return [
            PembuatButirTinjauan::Buat(
                $this->KueriShift($idOutlet),
                'Shift',
                'Shift',
                'shift.tinjauan',
                'Kasir',
                'Shift perlu dicek',
                'Shift dari kasir yang diterima dengan catatan (misal selisih kas tanpa persetujuan).',
                '/kelola/kasir/shift?saring[PerluTinjauan]=Ya',
                fn (Shift $s): DataRincianTindakan => new DataRincianTindakan($s->Uuid, 'Shift '.$s->TanggalBisnis->toDateString(), $s->AlasanTinjauan, $s->TanggalBisnis->toDateString(), '/kelola/kasir/shift/'.$s->Uuid),
            ),
            PembuatButirTinjauan::Buat(
                $this->KueriMutasiKas($idOutlet),
                'MutasiKas',
                'MutasiKas',
                'mutasi-kas.tinjauan',
                'Kasir',
                'Kas masuk/keluar perlu dicek',
                'Kas masuk/keluar di kasir yang diterima dengan catatan.',
                '/kelola/kasir/shift',
                fn (MutasiKas $m): DataRincianTindakan => new DataRincianTindakan(
                    $m->Uuid,
                    $m->Jenis->AmbilLabel().' '.Uang::Dari($m->Jumlah)->FormatRupiah(),
                    $m->AlasanTinjauan,
                    $m->TanggalBisnis->toDateString(),
                    '/kelola/kasir/shift/'.(string) Shift::query()->whereKey($m->IdShift)->value('Uuid'),
                ),
                tingkat: TingkatTindakan::Perhatian,
            ),
            new DataButirTindakan(
                'shift.lupa-ditutup',
                'Kasir',
                TingkatTindakan::Perhatian,
                'Shift belum ditutup',
                'Masih terbuka lebih dari '.self::JAM_SHIFT_LAMA.' jam. Tutup shift di aplikasi kasir agar kas & laporan harian final.',
                $jumlahLama,
                '/kelola/kasir/shift?saring[Status]=Terbuka',
                'Lihat shift',
                $jumlahLama === 0 ? [] : array_values($lama->orderBy('DibukaPada')->limit(DataButirTindakan::BATAS_RINCIAN)->get()->map(
                    fn (Shift $s): DataRincianTindakan => new DataRincianTindakan($s->Uuid, 'Shift '.$s->TanggalBisnis->toDateString(), 'Dibuka '.$s->DibukaPada->toIso8601ZuluString(), $s->TanggalBisnis->toDateString(), '/kelola/kasir/shift/'.$s->Uuid),
                )->all()),
            ),
        ];
    }

    public function AmbilJenisDokumen(): array
    {
        return ['Shift', 'MutasiKas'];
    }

    public function SaringDokumen(string $jenisDokumen, array $uuid): array
    {
        return match ($jenisDokumen) {
            'Shift' => PembuatButirTinjauan::Saring($this->KueriShift(null), 'Shift', $uuid),
            'MutasiKas' => PembuatButirTinjauan::Saring($this->KueriMutasiKas(null), 'MutasiKas', $uuid),
            default => [],
        };
    }

    /**
     * @param  list<int>|null  $idOutlet
     * @return Builder<Shift>
     */
    private function KueriShift(?array $idOutlet): Builder
    {
        return Shift::query()->where('Shift.PerluTinjauan', true)->when($idOutlet !== null, fn ($k) => $k->whereIn('Shift.IdOutlet', $idOutlet));
    }

    /**
     * @param  list<int>|null  $idOutlet
     * @return Builder<MutasiKas>
     */
    private function KueriMutasiKas(?array $idOutlet): Builder
    {
        return MutasiKas::query()->where('MutasiKas.PerluTinjauan', true)
            ->when($idOutlet !== null, fn ($k) => $k->whereIn('MutasiKas.IdShift', Shift::query()->whereIn('IdOutlet', $idOutlet)->select('Id')));
    }
}
