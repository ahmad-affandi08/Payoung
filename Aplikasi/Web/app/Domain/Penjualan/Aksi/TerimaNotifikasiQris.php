<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Aksi;

use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Integrasi\GerbangPembayaran\GalatGerbang;
use App\Domain\Integrasi\GerbangPembayaran\GerbangPembayaran;
use App\Domain\Integrasi\GerbangPembayaran\HasilWebhook;
use App\Domain\Integrasi\GerbangPembayaran\StatusPembayaranGerbang;
use App\Domain\Penjualan\Enum\StatusTagihanQris;
use App\Domain\Penjualan\Layanan\NomorPesananQris;
use App\Domain\Penjualan\Layanan\PenerapStatusTagihanQris;
use App\Domain\Penjualan\Model\TagihanQris;
use App\Domain\Tenant\Kueri\ProfilTenant;
use Illuminate\Support\Facades\Log;

/**
 * F-08 BR-08.5: notifikasi gerbang (tanda tangan sudah diverifikasi adaptor dengan kredensial tenant pemilik URL
 * webhook, v2.06) diterapkan ke tagihan QRIS. Tenant di `NomorPesanan` (`PY{tenant basis-36}-{Uuid}`) wajib sama dengan
 * tenant pemilik webhook: tenant yang menandatangani notifikasi dengan kuncinya sendiri tidak bisa melunasi tagihan
 * tenant lain. Pencarian lewat scope `MilikTenant` (tanpa query lintas tenant). Tagihan tidak dikenal / tenant atau
 * penyedia berbeda = false (dijawab 200 agar gerbang berhenti mengulang) dan dicatat di log. Idempoten: tagihan
 * `Lunas` tidak berubah lagi.
 */
final class TerimaNotifikasiQris
{
    public function __construct(
        private readonly KonteksTenant $konteks,
        private readonly ProfilTenant $profil,
        private readonly PenerapStatusTagihanQris $penerap,
    ) {}

    /**
     * @param  GerbangPembayaran|null  $gerbang  dipakai menanyakan ulang status bila penyedia belum bisa memastikannya saat notifikasi tiba
     *
     * @throws GalatGerbang bila status tetap tidak bisa dipastikan: pemanggil menjawab 503 supaya gerbang mengirim ulang
     */
    public function Jalankan(HasilWebhook $notifikasi, string $kodePenyedia, int $idTenantWebhook, ?GerbangPembayaran $gerbang = null): bool
    {
        $nomor = NomorPesananQris::Urai($notifikasi->nomorPesanan);

        if ($nomor === null || ! $this->profil->CekAda($nomor['IdTenant'])) {
            return $this->Abaikan($notifikasi, $kodePenyedia, 'nomor pesanan tidak dikenal');
        }

        if ($nomor['IdTenant'] !== $idTenantWebhook) {
            return $this->Abaikan($notifikasi, $kodePenyedia, 'nomor pesanan milik tenant lain');
        }

        $konteksLama = $this->konteks->Ambil();
        $this->konteks->Atur($nomor['IdTenant']);

        try {
            $tagihan = TagihanQris::query()->where('NomorPesanan', $notifikasi->nomorPesanan)->first();

            if ($tagihan === null || $tagihan->Penyedia !== $kodePenyedia) {
                return $this->Abaikan($notifikasi, $kodePenyedia, $tagihan === null ? 'tagihan tidak ditemukan' : 'penyedia berbeda');
            }

            if ($notifikasi->belumTerkonfirmasi) {
                return $this->TanyakanUlang($tagihan, $gerbang, $kodePenyedia);
            }

            $this->penerap->Terapkan($tagihan->Id, $notifikasi->status, $notifikasi->jumlah, "Webhook {$kodePenyedia}");

            return true;
        } finally {
            $konteksLama === null ? $this->konteks->Kosongkan() : $this->konteks->Atur($konteksLama);
        }
    }

    /**
     * Penyedia gagal memastikan status saat notifikasi. Tagihan yang sudah selesai tidak perlu ditanya lagi. Selain itu
     * ditanyakan ulang dengan id transaksi yang tersimpan di tagihan (notifikasi sering tidak membawanya).
     *
     * @throws GalatGerbang
     */
    private function TanyakanUlang(TagihanQris $tagihan, ?GerbangPembayaran $gerbang, string $kodePenyedia): bool
    {
        if ($tagihan->Status !== StatusTagihanQris::Menunggu) {
            return true;
        }

        if ($gerbang === null) {
            throw new GalatGerbang('Status QRIS belum bisa dipastikan.', tidakPasti: true);
        }

        $hasil = $gerbang->CekStatusDenganJumlah($tagihan->NomorPesanan, (string) $tagihan->IdReferensi);

        if ($hasil->status !== StatusPembayaranGerbang::Menunggu) {
            $this->penerap->Terapkan($tagihan->Id, $hasil->status, $hasil->jumlah, "Webhook {$kodePenyedia}");
        }

        return true;
    }

    private function Abaikan(HasilWebhook $notifikasi, string $kodePenyedia, string $alasan): bool
    {
        Log::warning('Notifikasi QRIS dinamis diabaikan: '.$alasan.'.', [
            'Penyedia' => $kodePenyedia,
            'NomorPesanan' => mb_substr($notifikasi->nomorPesanan, 0, 60),
            'Status' => $notifikasi->status->value,
        ]);

        return false;
    }
}
