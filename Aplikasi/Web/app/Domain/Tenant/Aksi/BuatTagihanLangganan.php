<?php

declare(strict_types=1);

namespace App\Domain\Tenant\Aksi;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Tenant\Enum\JenisTagihanLangganan;
use App\Domain\Tenant\Enum\StatusLangganan;
use App\Domain\Tenant\Enum\StatusPaket;
use App\Domain\Tenant\Enum\StatusTagihanLangganan;
use App\Domain\Tenant\Kueri\PermintaanTurunPaketMenunggu;
use App\Domain\Tenant\Layanan\PenerbitTagihanLangganan;
use App\Domain\Tenant\Layanan\PengaturanLamaLangganan;
use App\Domain\Tenant\Model\KuponLangganan;
use App\Domain\Tenant\Model\KuponLanggananPemakaian;
use App\Domain\Tenant\Model\Langganan;
use App\Domain\Tenant\Model\Paket;
use App\Domain\Tenant\Model\TagihanLangganan;
use Illuminate\Support\Facades\DB;

/**
 * Owner membuat tagihan langganan untuk upgrade dari Trial/Gratis, aktivasi ulang, perpanjangan, atau naik paket di
 * tengah periode (D-98; turun paket berlaku periode berikutnya dan belum tersedia mandiri).
 * Pembayaran langganan platform diproses melalui gerbang pembayaran online.
 */
final class BuatTagihanLangganan
{
    public function __construct(
        private readonly KonteksTenant $konteks,
        private readonly PenerbitTagihanLangganan $penerbit,
        private readonly PermintaanTurunPaketMenunggu $turunPaket,
        private readonly PengaturanLamaLangganan $lamaLangganan,
    ) {}

    public function Jalankan(int $idPengguna, string $kodePaket, int $jumlahBulan, ?string $kodeKupon = null): TagihanLangganan
    {
        $idTenant = $this->konteks->Wajib();
        $this->lamaLangganan->PastikanSah($jumlahBulan);

        return DB::transaction(function () use ($idTenant, $idPengguna, $kodePaket, $jumlahBulan, $kodeKupon): TagihanLangganan {
            $langganan = Langganan::query()->where('IdTenant', $idTenant)->lockForUpdate()->first()
                ?? throw new PelanggaranAturanBisnis('LanggananTidakAda', 'Data langganan usaha ini tidak ditemukan. Hubungi tim kami.');

            if ($langganan->Status === StatusLangganan::Berhenti) {
                throw new PelanggaranAturanBisnis('LanggananBerhenti', 'Langganan usaha ini sudah berhenti. Hubungi tim kami untuk mengaktifkan kembali.');
            }

            if ($langganan->CekDitangguhkanManual()) {
                throw new PelanggaranAturanBisnis('BR-P07.4', 'Usaha ini sedang ditangguhkan oleh tim kami. Hubungi tim kami lewat menu Bantuan.');
            }

            // D-49: tagihan add-on yang belum dibayar tidak boleh menghalangi tagihan paket.
            $this->penerbit->BatalkanTagihanTengahPeriodeTerbuka($idTenant, 'Dibatalkan otomatis karena tagihan paket dibuat.');
            $terbuka = TagihanLangganan::query()->whereIn('Status', StatusTagihanLangganan::NilaiTerbuka())->first();

            if ($terbuka !== null) {
                throw new PelanggaranAturanBisnis(
                    'TagihanMasihTerbuka',
                    "Masih ada tagihan {$terbuka->Nomor} yang belum dibayar. Bayar atau batalkan tagihan itu dulu.",
                );
            }

            // BR-P04.4: permintaan turun paket yang menunggu menggantikan paket berjalan di tagihan perpanjangan berikutnya.
            $turun = $this->CekBerjalan($langganan) ? $this->turunPaket->Cari($langganan->Id) : null;

            if ($turun !== null && in_array(strtoupper(trim($kodePaket)), [$langganan->Paket->Kode, $turun->PaketTujuan->Kode], true)) {
                if ($turun->PaketTujuan->Kode === (string) config('tenant.KodePaketGratis')) {
                    throw new PelanggaranAturanBisnis('TurunKeGratis', 'Langganan Anda dijadwalkan turun ke paket Gratis saat periode ini berakhir. Batalkan permintaan turun paket dulu bila ingin memperpanjang.', 'KodePaket');
                }

                $paket = $this->TentukanPaket($langganan, $turun->PaketTujuan->Kode);
                $kupon = $kodeKupon === null || trim($kodeKupon) === '' ? null : $this->CariKupon($idTenant, strtoupper(trim($kodeKupon)), $paket);

                return $this->penerbit->Terbitkan($langganan, $paket, JenisTagihanLangganan::Perpanjangan, $jumlahBulan, $kupon, $idPengguna, $langganan->Paket);
            }

            $paket = $this->TentukanPaket($langganan, $kodePaket);

            // D-98: ganti paket saat langganan Aktif = naik paket, selisih harga prorata sampai akhir periode.
            if ($langganan->Status === StatusLangganan::Aktif && $paket->Id !== $langganan->IdPaket) {
                if ($kodeKupon !== null && trim($kodeKupon) !== '') {
                    throw new PelanggaranAturanBisnis('KuponTidakBerlaku', 'Kupon belum bisa dipakai untuk naik paket. Kosongkan kode kupon, atau pakai kupon saat perpanjangan.', 'KodeKupon');
                }

                // Naik paket menggantikan rencana turun paket yang masih menunggu.
                if ($turun !== null) {
                    app(BatalkanTurunPaket::class)->Jalankan($turun->Uuid, $idPengguna, 'Diganti dengan naik paket.');
                }

                return $this->penerbit->TerbitkanNaikPaket($langganan, $paket, $idPengguna);
            }

            $jenis = $this->TentukanJenis($langganan, $paket);
            $kupon = $kodeKupon === null || trim($kodeKupon) === '' ? null : $this->CariKupon($idTenant, strtoupper(trim($kodeKupon)), $paket);

            return $this->penerbit->Terbitkan($langganan, $paket, $jenis, $jumlahBulan, $kupon, $idPengguna);
        });
    }

    /**
     * Paket Aktif, bukan harga negosiasi, bukan paket Gratis. Paket berjalan yang sudah diarsipkan tetap bisa
     * diperpanjang pemakainya (BR-P04.2).
     */
    private function TentukanPaket(Langganan $langganan, string $kodePaket): Paket
    {
        $paket = Paket::query()->where('Kode', strtoupper(trim($kodePaket)))->first();
        $paketBerjalan = $paket !== null && $paket->Id === $langganan->IdPaket && $this->CekBerjalan($langganan);
        $bolehDipilih = $paket !== null
            && ($paket->Status === StatusPaket::Aktif || ($paketBerjalan && $paket->Status === StatusPaket::Diarsipkan))
            && ! $paket->HargaNegosiasi
            && $paket->Kode !== (string) config('tenant.KodePaketGratis');

        if (! $bolehDipilih) {
            throw new PelanggaranAturanBisnis('PaketTidakTersedia', 'Paket ini tidak bisa dipilih. Pilih paket lain dari daftar.', 'KodePaket');
        }

        return $paket;
    }

    private function TentukanJenis(Langganan $langganan, Paket $paket): JenisTagihanLangganan
    {
        if ($this->CekBerjalan($langganan) && $paket->Id === $langganan->IdPaket) {
            return JenisTagihanLangganan::Perpanjangan;
        }

        return JenisTagihanLangganan::Aktivasi;
    }

    private function CekBerjalan(Langganan $langganan): bool
    {
        return in_array($langganan->Status, [StatusLangganan::Aktif, StatusLangganan::Tertunggak], true);
    }

    /** BR-P04.7: kupon aktif, belum lewat, berlaku untuk paket, kuota tenant berbeda belum habis. */
    private function CariKupon(int $idTenant, string $kode, Paket $paket): KuponLangganan
    {
        $kupon = KuponLangganan::query()->where('Kode', $kode)->lockForUpdate()->first();
        $hariIni = now('Asia/Jakarta')->toDateString();

        if ($kupon === null || ! $kupon->Aktif || ($kupon->BerlakuSampai !== null && $kupon->BerlakuSampai->toDateString() < $hariIni)) {
            throw new PelanggaranAturanBisnis('KuponTidakBerlaku', 'Kode kupon tidak berlaku.', 'KodeKupon');
        }

        if ($kupon->DaftarKodePaket !== null && ! in_array($paket->Kode, $kupon->DaftarKodePaket, true)) {
            throw new PelanggaranAturanBisnis('KuponBukanUntukPaket', "Kupon ini tidak berlaku untuk paket {$paket->Nama}.", 'KodeKupon');
        }

        $terpakai = $this->penerbit->HitungBulanTerpakai($idTenant, $kupon);

        if ($terpakai >= $kupon->DurasiBulan) {
            throw new PelanggaranAturanBisnis('KuponSudahDipakai', 'Kupon ini sudah Anda pakai sampai habis.', 'KodeKupon');
        }

        if ($terpakai === 0 && $kupon->Kuota !== null) {
            $jumlahTenant = KuponLanggananPemakaian::query()
                ->where('IdKuponLangganan', $kupon->Id)
                ->whereNull('DibatalkanPada')
                ->distinct()
                ->count('IdTenant');

            if ($jumlahTenant >= $kupon->Kuota) {
                throw new PelanggaranAturanBisnis('KuotaKuponHabis', 'Kuota kupon ini sudah habis.', 'KodeKupon');
            }
        }

        return $kupon;
    }
}
