<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Tagihan\Aksi;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Pengelola\TimInternal\Enum\IzinPengelola;
use App\Domain\Pengelola\TimInternal\Layanan\PencatatAuditPengelola;
use App\Domain\Pengelola\TimInternal\Model\PenggunaPengelola;
use App\Domain\Tenant\Layanan\PengaturanSaldoWhatsapp;
use App\Domain\Tenant\Model\PengaturanPlatform;
use Illuminate\Support\Facades\DB;

/**
 * Super Admin mengatur harga per pesan broadcast WhatsApp dan minimal isi saldo (D-300). Harga 0 berarti broadcast
 * gratis. Perubahan hanya berlaku untuk pesan dan isi saldo berikutnya, tercatat di audit beserta nilai lama dan alasannya.
 */
final class UbahPengaturanSaldoWhatsapp
{
    public function __construct(
        private readonly PengaturanSaldoWhatsapp $pengaturan,
        private readonly PencatatAuditPengelola $audit,
    ) {}

    public function Jalankan(PenggunaPengelola $pelaku, string $hargaPerPesan, string $topUpMinimal, string $alasan): void
    {
        if (! $pelaku->PunyaIzin(IzinPengelola::TagihanPengaturan)) {
            throw new PelanggaranAturanBisnis('BR-P08.2', 'Pengaturan saldo WhatsApp hanya bisa diubah Super Admin.');
        }

        $harga = Uang::Dari($hargaPerPesan);
        $minimal = Uang::Dari($topUpMinimal);

        if ($harga->BernilaiNegatif() || $harga->Bandingkan(Uang::Dari(PengaturanSaldoWhatsapp::MAKS_HARGA)) > 0) {
            throw new PelanggaranAturanBisnis('HargaTidakSah', 'Harga per pesan harus antara Rp 0 dan Rp 100.000.', 'HargaPerPesan');
        }

        if ($minimal->BernilaiNegatif() || $minimal->BernilaiNol() || $minimal->Bandingkan(Uang::Dari(PengaturanSaldoWhatsapp::MAKS_MINIMAL)) > 0) {
            throw new PelanggaranAturanBisnis('MinimalTidakSah', 'Minimal isi saldo harus antara Rp 1 dan Rp 10.000.000.', 'TopUpMinimal');
        }

        DB::transaction(function () use ($pelaku, $harga, $minimal, $alasan): void {
            $lama = ['HargaPerPesan' => $this->pengaturan->HargaPerPesan()->KeString(), 'TopUpMinimal' => $this->pengaturan->TopUpMinimal()->KeString()];

            foreach ([PengaturanSaldoWhatsapp::KUNCI_HARGA => $harga, PengaturanSaldoWhatsapp::KUNCI_MINIMAL => $minimal] as $kunci => $nilai) {
                PengaturanPlatform::query()->updateOrCreate(
                    ['Kunci' => $kunci],
                    ['Nilai' => $nilai->KeString(), 'IdPenggunaPengelolaPengubah' => $pelaku->Id],
                );
            }

            $this->audit->Catat(
                'tagihan.saldo-whatsapp.ubah',
                null,
                nilaiLama: $lama,
                nilaiBaru: ['HargaPerPesan' => $harga->KeString(), 'TopUpMinimal' => $minimal->KeString()],
                alasan: trim($alasan),
                idPelaku: $pelaku->Id,
            );
        });
    }
}
