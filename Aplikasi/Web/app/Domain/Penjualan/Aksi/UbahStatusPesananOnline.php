<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Dokumen\Layanan\PencatatRiwayatStatus;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Pemenuhan\Enum\StatusPengirimanPesanan;
use App\Domain\Pemenuhan\Model\PengirimanPesanan;
use App\Domain\Penjualan\Enum\JenisPemenuhanOnline;
use App\Domain\Penjualan\Enum\PeristiwaPesananOnline;
use App\Domain\Penjualan\Enum\StatusPesananOnline;
use App\Domain\Penjualan\Layanan\PemberitahuPesananOnline;
use App\Domain\Penjualan\Layanan\PemeriksaPenyelesaianPesananOnline;
use App\Domain\Penjualan\Model\PesananOnline;
use App\Domain\Penjualan\Model\ZonaPengiriman;
use App\Domain\Persediaan\Layanan\PencadangStok;
use App\Domain\Promo\Aksi\LepasVoucherPos;
use Illuminate\Support\Facades\DB;

final class UbahStatusPesananOnline
{
    public function __construct(
        private readonly PencatatRiwayatStatus $riwayat,
        private readonly PencatatAudit $audit,
        private readonly PemeriksaPenyelesaianPesananOnline $selesai,
        private readonly PemberitahuPesananOnline $pemberitahu,
        private readonly LepasVoucherPos $lepasVoucher,
        private readonly PencadangStok $pencadang,
    ) {}

    /**
     * Semua pemeriksaan dilakukan di dalam transaksi setelah baris pesanan dikunci dan dimuat ulang. Tanpa itu, pemeriksaan
     * "sudah dibayar", urutan status, dan pengiriman memakai status basi: kasir yang menagih pesanan pada saat yang sama
     * dengan staf yang membatalkannya, atau penyapu kedaluwarsa dengan tombol Konfirmasi, saling menimpa (pesanan
     * `Dibatalkan` padahal penjualannya `Lunas`, atau hidup lagi setelah `Kedaluwarsa` tanpa cadangan stok dan voucher).
     * Penagihan di kasir (`PenutupUangMukaPesananOnline`) mengunci baris pesanan yang sama, jadi keduanya berurutan.
     */
    public function Jalankan(PesananOnline $pesanan, StatusPesananOnline $status, int $idPengguna, ?string $alasan = null): void
    {
        DB::transaction(function () use ($pesanan, $status, $idPengguna, $alasan): void {
            $terkunci = PesananOnline::query()->whereKey($pesanan->Id)->lockForUpdate()->firstOrFail();
            $pesanan->setRawAttributes($terkunci->getAttributes(), true);

            if ($status === StatusPesananOnline::Selesai && ! $this->selesai->CekSudahDibayar($pesanan)) {
                throw new PelanggaranAturanBisnis('PenjualanBelumLunas', 'Pesanan baru dapat diselesaikan setelah ditautkan ke penjualan yang lunas.', 'Status');
            }
            // Pesanan yang sudah ditagih di kasir (penjualan lunas) membawa stok keluar, pendapatan, dan uang muka terpakai:
            // membatalkannya hanya mengubah status sambil membiarkan semua itu. Pembalikannya lewat void/retur penjualan.
            if ($status === StatusPesananOnline::Dibatalkan && $this->selesai->CekSudahDibayar($pesanan)) {
                throw new PelanggaranAturanBisnis('PenjualanSudahLunas', 'Pesanan sudah ditagih di kasir. Batalkan lewat void atau retur penjualannya.', 'Status', 409);
            }
            $pengiriman = $pesanan->JenisPemenuhan === JenisPemenuhanOnline::Kirim
                ? PengirimanPesanan::query()->where('IdPesananOnline', $pesanan->Id)->lockForUpdate()->first()
                : null;
            if ($status === StatusPesananOnline::Selesai && $pesanan->JenisPemenuhan === JenisPemenuhanOnline::Kirim
                && $pengiriman?->Status !== StatusPengirimanPesanan::Diterima) {
                throw new PelanggaranAturanBisnis('PengirimanBelumDiterima', 'Pesanan kirim selesai otomatis setelah pengiriman diterima.', 'Status');
            }
            if ($status === StatusPesananOnline::Dibatalkan && in_array($pengiriman?->Status, [StatusPengirimanPesanan::Dikirim, StatusPengirimanPesanan::Diterima], true)) {
                throw new PelanggaranAturanBisnis('PengirimanSudahBerjalan', 'Pesanan tidak dapat dibatalkan setelah kurir berangkat.', 'Status', 409);
            }

            $dari = $pesanan->Status;
            $pesanan->UbahStatus($status);
            $pesanan->DiubahOleh = $idPengguna;
            $pesanan->Alasan = $alasan;
            if ($status === StatusPesananOnline::Dikonfirmasi) {
                $pesanan->DikonfirmasiOleh = $idPengguna;
                $pesanan->DikonfirmasiPada = now();
                if ($pesanan->JenisPemenuhan === JenisPemenuhanOnline::Kirim) {
                    $maksHari = $pesanan->IdZonaPengiriman === null ? 0 : (ZonaPengiriman::query()->whereKey($pesanan->IdZonaPengiriman)->value('EstimasiHariMaks') ?? 0);
                    PengirimanPesanan::query()->firstOrCreate(['IdPesananOnline' => $pesanan->Id], [
                        'IdOutlet' => $pesanan->IdOutlet,
                        'Status' => StatusPengirimanPesanan::SiapKemas,
                        'PerkiraanTibaPada' => now()->addDays((int) $maksHari),
                    ]);
                }
            }
            if ($status === StatusPesananOnline::Selesai) {
                $pesanan->SelesaiPada = now();
            }
            if ($status === StatusPesananOnline::Dibatalkan && $pengiriman instanceof PengirimanPesanan
                && $pengiriman->Status->BisaBerubahKe(StatusPengirimanPesanan::Dibatalkan)) {
                $dariPengiriman = $pengiriman->Status;
                $pengiriman->UbahStatus(StatusPengirimanPesanan::Dibatalkan);
                $pengiriman->Alasan = $alasan;
                $pengiriman->DiubahOleh = $idPengguna;
                $pengiriman->save();
                $this->riwayat->Catat('PengirimanPesanan', $pengiriman->Id, $dariPengiriman->value, StatusPengirimanPesanan::Dibatalkan->value, $idPengguna, $alasan);
            }
            $pesanan->save();
            // v3.46: voucher checkout yang dipesan untuk pesanan ini dilepas begitu pesanan tidak jadi ditagih.
            if ($pesanan->KodeVoucher !== null && in_array($status, [StatusPesananOnline::Ditolak, StatusPesananOnline::Dibatalkan], true)) {
                $this->lepasVoucher->Jalankan($pesanan->KodeVoucher, $pesanan->Uuid);
            }
            // v3.48: cadangan stok pesanan yang tidak jadi ditagih dikembalikan ke stok yang bisa dipesan.
            if (in_array($status, [StatusPesananOnline::Ditolak, StatusPesananOnline::Dibatalkan], true)) {
                $this->pencadang->Lepas(PencadangStok::SUMBER_PESANAN_ONLINE, $pesanan->Uuid);
            }
            $this->riwayat->Catat(PesananOnline::JENIS_DOKUMEN, $pesanan->Id, $dari->value, $status->value, $idPengguna, $alasan);
            $this->audit->Catat('pesanan-online.status', $pesanan, ['Status' => $dari->value], ['Status' => $status->value, 'Alasan' => $alasan], idPengguna: $idPengguna);

            // F-17 bagian 3 (v3.32): pembeli diberi tahu lewat WhatsApp. Pesanan kirim yang Siap belum berarti apa-apa
            // bagi pembeli (masih menunggu kurir), jadi yang diberitahukan hanya siap **diambil**.
            $peristiwa = match (true) {
                $status === StatusPesananOnline::Dikonfirmasi => PeristiwaPesananOnline::Dikonfirmasi,
                $status === StatusPesananOnline::Siap && $pesanan->JenisPemenuhan === JenisPemenuhanOnline::AmbilSendiri => PeristiwaPesananOnline::SiapDiambil,
                $status === StatusPesananOnline::Ditolak => PeristiwaPesananOnline::Ditolak,
                $status === StatusPesananOnline::Dibatalkan => PeristiwaPesananOnline::Dibatalkan,
                default => null,
            };
            if ($peristiwa !== null) {
                $this->pemberitahu->Antrekan($pesanan, $peristiwa);
            }
        });
    }
}
