<?php

declare(strict_types=1);

namespace App\Domain\Organisasi\Layanan;

use App\Domain\Bersama\Tindakan\Data\DataKonteksTindakan;
use App\Domain\Bersama\Tindakan\Data\DataRincianTindakan;
use App\Domain\Bersama\Tindakan\Kontrak\PenyediaTindakan;
use App\Domain\Bersama\Tindakan\Kontrak\TerbatasSektor;
use App\Domain\Bersama\Tindakan\Layanan\PembuatButirTinjauan;
use App\Domain\Organisasi\Model\ItemSinkronPemulihan;
use App\Domain\Organisasi\Model\Perangkat;
use Illuminate\Database\Eloquent\Builder;

/**
 * Kotak Tindakan perangkat (audit P0 F-01, izin `perangkat.kelola`, dibatasi outlet akses): data kasir yang diterima
 * lewat jalur pemulihan (dikirim setelah perangkat dicabut, atau oleh perangkat lain atas nama perangkat asal). Data
 * tetap tercatat; pemilik mengecek bahwa transaksinya wajar lalu menandainya sudah dicek.
 */
final class PenyediaTindakanPerangkat implements PenyediaTindakan, TerbatasSektor
{
    public const JENIS_DOKUMEN = 'ItemSinkronPemulihan';

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
        if (! $konteks->CekIzin('perangkat.kelola')) {
            return [];
        }

        $nama = Perangkat::query()->pluck('Nama', 'Id')->all();

        return [
            PembuatButirTinjauan::Buat(
                $this->Kueri($konteks->idOutletBoleh),
                self::JENIS_DOKUMEN,
                'ItemSinkronPemulihan',
                'perangkat.sinkron-pemulihan',
                'Perangkat',
                'Data kasir dari perangkat dicabut perlu dicek',
                'Transaksi yang dibuat offline sebelum perangkat dicabut, atau dikirim perangkat lain setelah aktivasi ulang. Tetap tercatat atas nama perangkat pembuatnya.',
                '/kelola/perangkat',
                fn (ItemSinkronPemulihan $i): DataRincianTindakan => new DataRincianTindakan(
                    $i->Uuid,
                    $i->Jenis.' dari '.($nama[$i->IdPerangkat] ?? 'perangkat'),
                    $i->Alasan->AmbilLabel(),
                    $i->DibuatPadaKlien->setTimezone('Asia/Jakarta')->toDateString(),
                    '/kelola/perangkat',
                ),
            ),
        ];
    }

    public function AmbilJenisDokumen(): array
    {
        return [self::JENIS_DOKUMEN];
    }

    public function SaringDokumen(string $jenisDokumen, array $uuid): array
    {
        return $jenisDokumen === self::JENIS_DOKUMEN ? PembuatButirTinjauan::Saring($this->Kueri(null), 'ItemSinkronPemulihan', $uuid) : [];
    }

    /**
     * @param  list<int>|null  $idOutlet
     * @return Builder<ItemSinkronPemulihan>
     */
    private function Kueri(?array $idOutlet): Builder
    {
        return ItemSinkronPemulihan::query()->when($idOutlet !== null, fn (Builder $k) => $k->whereIn('ItemSinkronPemulihan.IdOutlet', $idOutlet ?: [0]));
    }
}
