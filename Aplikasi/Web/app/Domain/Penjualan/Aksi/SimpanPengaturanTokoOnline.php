<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Integrasi\GerbangPembayaran\PembuatGerbangPembayaran;
use App\Domain\Organisasi\Model\Outlet;
use App\Domain\Penjualan\Model\PengaturanTokoOnline;
use App\Domain\Tenant\Layanan\PemeriksaFiturTenant;
use Illuminate\Support\Facades\DB;

final class SimpanPengaturanTokoOnline
{
    public function __construct(
        private readonly PemeriksaFiturTenant $fitur,
        private readonly PembuatGerbangPembayaran $gerbang,
        private readonly PencatatAudit $audit,
    ) {}

    /** @param array<string, mixed> $data */
    public function Jalankan(Outlet $outlet, array $data, int $idPengguna): void
    {
        if ((bool) $data['Aktif'] && ! $this->fitur->CekAktif($outlet->IdTenant, PemeriksaFiturTenant::KUNCI_TOKO_ONLINE)) {
            throw new PelanggaranAturanBisnis('FiturTidakAktif', 'Toko online belum aktif di paket usaha ini. Tambahkan add-on Toko online.', 'Aktif');
        }
        // F-17 bagian 2: menyalakan QRIS tanpa gerbang aktif hanya akan menggantung pelanggan di halaman bayar,
        // jadi ditolak di sini alih-alih dibiarkan gagal satu per satu saat checkout.
        if ((bool) $data['QrisAktif'] && $this->gerbang->AmbilAktifTenant() === null) {
            throw new PelanggaranAturanBisnis(
                'GerbangBelumAktif',
                'Gerbang pembayaran toko belum aktif, jadi QRIS online belum bisa dinyalakan. Atur dulu di Integrasi.',
                'QrisAktif',
            );
        }
        if ((int) $data['MenitKedaluwarsa'] < 15 || (int) $data['MenitKedaluwarsa'] > 1440) {
            throw new PelanggaranAturanBisnis('PengaturanTidakValid', 'Batas kedaluwarsa harus 15–1.440 menit.', 'MenitKedaluwarsa');
        }

        DB::transaction(function () use ($outlet, $data, $idPengguna): void {
            $p = PengaturanTokoOnline::query()->lockForUpdate()->first() ?? new PengaturanTokoOnline;
            $lama = [...$p->only(['Aktif', 'BayarSaatAmbilAktif', 'CodAktif', 'QrisAktif', 'AkunPelangganAktif', 'NotifikasiWhatsappAktif', 'MinimalPesanan', 'MenitKedaluwarsa', 'PesanTutup']), ...$outlet->only(['TokoOnlineAktif', 'AmbilSendiriAktif', 'KirimAktif'])];
            $p->fill(array_intersect_key($data, array_flip(['Aktif', 'BayarSaatAmbilAktif', 'CodAktif', 'QrisAktif', 'AkunPelangganAktif', 'NotifikasiWhatsappAktif', 'MinimalPesanan', 'MenitKedaluwarsa', 'PesanTutup'])))->save();
            $outlet->fill(array_intersect_key($data, array_flip(['TokoOnlineAktif', 'AmbilSendiriAktif', 'KirimAktif'])))->save();
            $this->audit->Catat('toko-online.pengaturan', $p, $lama, $data, idPengguna: $idPengguna);
        });
    }
}
