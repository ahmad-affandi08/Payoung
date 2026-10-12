<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Kueri;

use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Bersama\Tabel\Data\DataPermintaanTabel;
use App\Domain\Bersama\Tabel\Layanan\PenerapKueriTabel;
use App\Domain\Organisasi\Kueri\PetaUuidOutlet;
use App\Domain\Penjualan\Enum\StatusTagihanQris;
use App\Domain\Penjualan\Enum\SumberTagihanQris;
use App\Domain\Penjualan\Model\TagihanQris;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Daftar **uang QRIS tanpa penjualan** untuk `TabelData` (E8): tagihan QRIS POS yang `Lunas` tetapi tidak terhubung ke
 * penjualan, baik yang masih menunggu keputusan (`PerluTinjauan`) maupun yang sudah ditutup. Saring `Penutupan`:
 * `Terbuka` / `Ditutup`. Cari: nomor pesanan gerbang atau Uuid tagihan (tautan jurnal). Dibatasi outlet pelaku.
 */
final class DaftarTagihanQrisTanpaPenjualan
{
    public const KOLOM_URUT = ['LunasPada', 'NomorPesanan', 'Jumlah'];

    public const KOLOM_SARING = ['Penutupan'];

    public const URUT_BAWAAN = '-LunasPada';

    public function __construct(private readonly PetaUuidOutlet $petaOutlet) {}

    /**
     * @param  list<int>|null  $idOutletBoleh
     * @return array{Data: list<array<string, mixed>>, Meta: array{Halaman: int, PerHalaman: int, Total: int, JumlahHalaman: int}}
     */
    public function Ambil(DataPermintaanTabel $p, ?array $idOutletBoleh): array
    {
        $kueri = TagihanQris::query()
            ->where('Sumber', SumberTagihanQris::Pos->value)
            ->where('Status', StatusTagihanQris::Lunas->value)
            ->whereNull('UuidPenjualan')
            ->where(fn (Builder $dalam) => $dalam->where('PerluTinjauan', true)->orWhereNotNull('DitutupPada'))
            ->when($idOutletBoleh !== null, fn (Builder $k) => $k->whereIn('IdOutlet', $idOutletBoleh));
        $penutupan = $p->AmbilDaftar('Penutupan', ['Terbuka', 'Ditutup']);

        if (count($penutupan) === 1) {
            $penutupan[0] === 'Ditutup' ? $kueri->whereNotNull('DitutupPada') : $kueri->whereNull('DitutupPada');
        }

        if ($p->cari !== '') {
            $kueri->where(fn (Builder $dalam) => $dalam->where('NomorPesanan', 'like', PenerapKueriTabel::PolaCari($p->cari))->orWhere('Uuid', $p->cari));
        }

        return PenerapKueriTabel::Terapkan($kueri, $p, ['LunasPada' => 'LunasPada', 'NomorPesanan' => 'NomorPesanan', 'Jumlah' => 'Jumlah'], function (Collection $baris): array {
            $outlet = $this->petaOutlet->AmbilKode(array_values(array_unique(array_filter($baris->pluck('IdOutlet')->all(), 'is_int'))));

            return array_values($baris->map(fn (TagihanQris $t): array => [
                'Uuid' => $t->Uuid,
                'NomorPesanan' => $t->NomorPesanan,
                'KodeOutlet' => $outlet[$t->IdOutlet] ?? '',
                'LunasPada' => $t->LunasPada?->setTimezone('Asia/Jakarta')->toIso8601String(),
                'Jumlah' => Uang::Dari($t->JumlahDiterima ?? $t->Jumlah)->KeString(),
                'AlasanTinjauan' => $t->AlasanTinjauan,
                'Ditutup' => $t->DitutupPada !== null,
                'HasilPenutupan' => $t->HasilPenutupan?->value,
                'LabelHasil' => $t->HasilPenutupan?->AmbilLabel(),
                'CatatanPenutupan' => $t->CatatanPenutupan,
                'DitutupPada' => $t->DitutupPada?->setTimezone('Asia/Jakarta')->toIso8601String(),
            ])->all());
        });
    }
}
