<?php

declare(strict_types=1);

namespace App\Domain\Integrasi\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Integrasi\Billing\NomorPesananBilling;
use App\Domain\Integrasi\Billing\PenyediaTagihanPlatform;
use App\Domain\Integrasi\Enum\StatusTopUpSaldoWhatsapp;
use App\Domain\Integrasi\GerbangPembayaran\GalatGerbang;
use App\Domain\Integrasi\Model\TopUpSaldoWhatsapp;
use App\Domain\Integrasi\Whatsapp\KetersediaanWhatsapp;
use App\Domain\Tenant\Layanan\PengaturanSaldoWhatsapp;
use Illuminate\Support\Str;

/**
 * D-300: memulai isi saldo WhatsApp. Membuat `TopUpSaldoWhatsapp` bernomor pesanan gerbang billing platform lalu
 * meminta halaman bayar (Checkout) dan mengembalikan alamatnya. Saldo baru bertambah setelah notifikasi atau status
 * gerbang menyatakan lunas (`TerimaNotifikasiTopUpWhatsapp`), tidak pernah dari peramban.
 */
final class MulaiTopUpSaldoWhatsapp
{
    public const MAKS_JUMLAH = '10000000';

    public function __construct(
        private readonly KonteksTenant $konteks,
        private readonly KetersediaanWhatsapp $ketersediaan,
        private readonly PengaturanSaldoWhatsapp $pengaturan,
        private readonly PenyediaTagihanPlatform $penyedia,
        private readonly PencatatAudit $audit,
    ) {}

    /** @return string alamat halaman bayar penyedia */
    public function Jalankan(Uang $jumlah, int $idPengguna, string $namaPembayar, string $emailPembayar, string $urlKembali): string
    {
        $idTenant = $this->konteks->Wajib();

        if (! $this->ketersediaan->TersediaUntuk($idTenant)) {
            throw new PelanggaranAturanBisnis('WhatsappTidakTersedia', 'Layanan WhatsApp belum aktif untuk usaha ini.', statusHttp: 409);
        }

        $minimal = $this->pengaturan->TopUpMinimal();

        if ($jumlah->Bandingkan($minimal) < 0 || $jumlah->Bandingkan(Uang::Dari(self::MAKS_JUMLAH)) > 0) {
            throw new PelanggaranAturanBisnis('JumlahTidakSah', 'Jumlah isi saldo antara '.$minimal->FormatRupiah().' dan '.Uang::Dari(self::MAKS_JUMLAH)->FormatRupiah().'.', 'Jumlah');
        }

        if (! $this->penyedia->CekCheckoutAktif()) {
            throw new PelanggaranAturanBisnis('PembayaranBelumSiap', 'Pembayaran online belum tersedia. Hubungi dukungan Payoung.', statusHttp: 409);
        }

        $uuid = (string) Str::ulid();
        $topUp = TopUpSaldoWhatsapp::query()->create([
            'Uuid' => $uuid, 'IdTenant' => $idTenant, 'Jumlah' => $jumlah->KeString(), 'Status' => StatusTopUpSaldoWhatsapp::Menunggu,
            'RefGateway' => NomorPesananBilling::Buat($idTenant, $uuid), 'IdPengguna' => $idPengguna,
        ]);

        try {
            $hasil = $this->penyedia->BuatCheckout((string) $topUp->RefGateway, $jumlah, $namaPembayar, $emailPembayar, $urlKembali);
        } catch (GalatGerbang $galat) {
            // Hanya galat yang pasti tidak membuat apa pun di gerbang boleh ditutup; yang tidak pasti dibiarkan Menunggu
            // supaya notifikasi yang mungkin menyusul tetap menemukan pasangannya.
            if (! $galat->tidakPasti) {
                $topUp->update(['Status' => StatusTopUpSaldoWhatsapp::Gagal]);
            }

            throw new PelanggaranAturanBisnis('GerbangMenolak', $galat->getMessage(), statusHttp: 409);
        }

        $topUp->update(['UrlBayar' => $hasil->urlBayar]);
        $this->audit->Catat('saldo-whatsapp.top-up-mulai', $topUp, nilaiBaru: ['Jumlah' => $topUp->Jumlah], idPengguna: $idPengguna);

        return $hasil->urlBayar;
    }
}
