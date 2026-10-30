<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Tagihan\Aksi;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Pengelola\TimInternal\Enum\IzinPengelola;
use App\Domain\Pengelola\TimInternal\Layanan\PencatatAuditPengelola;
use App\Domain\Pengelola\TimInternal\Model\PenggunaPengelola;
use App\Domain\Tenant\Layanan\PengaturanLamaLangganan;
use Illuminate\Support\Facades\DB;

/**
 * Super Admin mengatur lama langganan yang boleh dipilih tenant dan tangga diskonnya (D-249). Hanya berlaku untuk
 * tagihan yang terbit sesudahnya: tagihan yang sudah terbit dan periode yang sudah dibayar tidak berubah (CLAUDE.md #8),
 * dan perpanjangan otomatis lama yang sudah dipakai tenant tidak gagal walau maksimalnya diturunkan. Wajib beralasan
 * dan teraudit.
 */
final class UbahLamaLangganan
{
    public function __construct(
        private readonly PengaturanLamaLangganan $pengaturan,
        private readonly PencatatAuditPengelola $audit,
    ) {}

    /**
     * @param  list<array{DariBulan: mixed, Persen: mixed}>  $tangga
     */
    public function Jalankan(PenggunaPengelola $pelaku, int $minimal, int $maksimal, array $tangga, string $alasan): void
    {
        if (! $pelaku->PunyaIzin(IzinPengelola::TagihanPengaturan)) {
            throw new PelanggaranAturanBisnis('BR-P08.17', 'Lama langganan hanya bisa diubah Super Admin.');
        }

        $this->pengaturan->PastikanIsianKonsolSah($minimal, $maksimal, $tangga);
        $rapi = PengaturanLamaLangganan::RapikanTangga($tangga);

        DB::transaction(function () use ($pelaku, $minimal, $maksimal, $rapi, $alasan): void {
            $lama = $this->pengaturan->Ambil();
            $this->pengaturan->Simpan($minimal, $maksimal, $rapi, $pelaku->Id);
            $this->audit->Catat(
                'tagihan.lama-langganan.ubah',
                null,
                nilaiLama: ['MinimalBulan' => $lama['MinimalBulan'], 'MaksimalBulan' => $lama['MaksimalBulan'], 'TanggaDiskon' => $lama['TanggaDiskon']],
                nilaiBaru: ['MinimalBulan' => $minimal, 'MaksimalBulan' => $maksimal, 'TanggaDiskon' => $rapi],
                alasan: trim($alasan),
                idPelaku: $pelaku->Id,
            );
        });
    }
}
