<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Tagihan\Aksi;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Pengelola\TimInternal\Enum\IzinPengelola;
use App\Domain\Pengelola\TimInternal\Layanan\PencatatAuditPengelola;
use App\Domain\Pengelola\TimInternal\Model\PenggunaPengelola;
use App\Domain\Tenant\Layanan\PengaturanPenagihanPlatform;
use App\Domain\Tenant\Model\PengaturanPlatform;
use Illuminate\Support\Facades\DB;

/**
 * Super Admin menyimpan daftar rekening tujuan transfer penagihan langganan (P-08, D-114). Rekening ini yang ditampilkan
 * ke Owner untuk tagihan manual, jadi hanya peran dengan izin `tagihan.pengaturan` (Super Admin) yang boleh mengubahnya,
 * dengan alasan dan audit nilai lama/baru. Maksimal {@see self::MAKS} rekening; kode dibuat otomatis (`UTAMA`, `REK2`, …).
 * Bukti transfer yang sudah diunggah menyimpan salinan rekeningnya sendiri, jadi perubahan di sini tidak mengubah riwayat.
 */
final class SimpanRekeningTujuanPlatform
{
    public const MAKS = 5;

    public function __construct(
        private readonly PengaturanPenagihanPlatform $pengaturan,
        private readonly PencatatAuditPengelola $audit,
    ) {}

    /**
     * @param  list<array{NamaBank: string, NomorRekening: string, AtasNama: string}>  $rekening
     * @return list<array{Kode: string, NamaBank: string, NomorRekening: string, AtasNama: string}>
     */
    public function Jalankan(PenggunaPengelola $pelaku, array $rekening, string $alasan): array
    {
        if (! $pelaku->PunyaIzin(IzinPengelola::TagihanPengaturan)) {
            throw new PelanggaranAturanBisnis('BR-P08.17', 'Rekening tujuan hanya bisa diubah Super Admin.');
        }

        if (count($rekening) > self::MAKS) {
            throw new PelanggaranAturanBisnis('RekeningTerlaluBanyak', 'Maksimal '.self::MAKS.' rekening tujuan.', 'Rekening');
        }

        $baru = [];

        foreach (array_values($rekening) as $indeks => $baris) {
            $baru[] = [
                'Kode' => $indeks === 0 ? 'UTAMA' : 'REK'.($indeks + 1),
                'NamaBank' => trim($baris['NamaBank']),
                'NomorRekening' => trim($baris['NomorRekening']),
                'AtasNama' => trim($baris['AtasNama']),
            ];
        }

        return DB::transaction(function () use ($pelaku, $baru, $alasan): array {
            $lama = $this->pengaturan->Rekening();
            PengaturanPlatform::query()->updateOrCreate(
                ['Kunci' => PengaturanPenagihanPlatform::KUNCI_REKENING],
                ['Nilai' => json_encode($baru, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'IdPenggunaPengelolaPengubah' => $pelaku->Id],
            );
            $this->audit->Catat(
                'tagihan.rekening-tujuan.ubah',
                null,
                nilaiLama: ['Rekening' => $lama],
                nilaiBaru: ['Rekening' => $baru],
                alasan: trim($alasan),
                idPelaku: $pelaku->Id,
            );

            return $baru;
        });
    }
}
