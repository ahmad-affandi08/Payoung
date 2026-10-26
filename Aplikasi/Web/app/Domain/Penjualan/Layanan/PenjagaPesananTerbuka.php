<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Layanan;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Organisasi\Data\DataAnggotaOutlet;
use App\Domain\Organisasi\Enum\IzinTenant;
use App\Domain\Organisasi\Kueri\AnggotaOutlet;
use App\Domain\Pemenuhan\Data\DataBarisKirimDapur;
use App\Domain\Penjualan\Enum\StatusPesananTerbuka;
use App\Domain\Penjualan\Model\PesananTerbuka;
use App\Domain\Penjualan\Model\PesananTerbukaDetail;
use Carbon\CarbonImmutable;

/**
 * Pemeriksaan bersama item outbox `PesananTerbuka.*` (F-07 mode meja fase 1): pesanan di outlet perangkat (dikunci
 * baris), masih `Terbuka`, pelaku anggota outlet ber-izin `penjualan.buat` atau `pesanan.meja.catat` (v2.00), pembatalan item yang sudah dikirim ke
 * dapur butuh izin `penjualan.void` pada pelaku atau penyetuju (BR-07.5), dan batas waktu wajar perangkat.
 */
final class PenjagaPesananTerbuka
{
    private const TOLERANSI_JAM_DETIK = 600;

    public function __construct(private readonly AnggotaOutlet $anggota) {}

    public function CariUntukDiubah(string $uuid, int $idOutlet): PesananTerbuka
    {
        $pesanan = PesananTerbuka::query()->where('Uuid', $uuid)->lockForUpdate()->first();

        if ($pesanan === null || $pesanan->IdOutlet !== $idOutlet) {
            throw new PelanggaranAturanBisnis('PesananTidakDitemukan', 'Pesanan terbuka tidak ditemukan di outlet ini. Kirim data pembukaan pesanan lebih dulu.', 'UuidPesanan', 404);
        }

        return $pesanan;
    }

    public function PastikanTerbuka(PesananTerbuka $pesanan): void
    {
        if ($pesanan->Status !== StatusPesananTerbuka::Terbuka) {
            throw new PelanggaranAturanBisnis('PesananSudahDitutup', "Pesanan {$pesanan->Nomor} sudah {$pesanan->Status->AmbilLabel()}. Tidak bisa diubah lagi.", 'UuidPesanan', 409);
        }
    }

    public function CariPelaku(int $idTenant, string $uuidPengguna, int $idOutlet): DataAnggotaOutlet
    {
        $pelaku = $this->anggota->Cari($idTenant, $uuidPengguna, $idOutlet);

        if ($pelaku === null) {
            throw new PelanggaranAturanBisnis('KasirTidakDitemukan', 'Pengguna ini tidak terdaftar di outlet pesanan ini.', 'UuidPengguna');
        }

        // v2.00: pelayan (izin `pesanan.meja.catat`) mencatat pesanan tanpa izin berjualan.
        if (! $pelaku->CekIzin(IzinTenant::PenjualanBuat->value) && ! $pelaku->CekIzin(IzinTenant::PesananMejaCatat->value)) {
            throw new PelanggaranAturanBisnis('TanpaIzin', "{$pelaku->nama} tidak punya izin mencatat pesanan.", 'UuidPengguna', 403);
        }

        return $pelaku;
    }

    /**
     * BR-07.5: pembatalan setelah dikirim ke dapur butuh `penjualan.void` pada pelaku, atau penyetuju yang berizin.
     */
    public function CariPenyetujuVoid(int $idTenant, DataAnggotaOutlet $pelaku, ?string $uuidPenyetuju, int $idOutlet): DataAnggotaOutlet
    {
        if ($pelaku->CekIzin(IzinTenant::PenjualanVoid->value) && ($uuidPenyetuju === null || $uuidPenyetuju === $pelaku->uuid)) {
            return $pelaku;
        }

        $penyetuju = $uuidPenyetuju === null ? null : $this->anggota->Cari($idTenant, $uuidPenyetuju, $idOutlet);

        if ($penyetuju === null || ! $penyetuju->CekIzin(IzinTenant::PenjualanVoid->value)) {
            throw new PelanggaranAturanBisnis('PenyetujuTidakBerwenang', 'Item yang sudah dikirim ke dapur hanya bisa dibatalkan dengan persetujuan pengguna ber-izin void.', 'UuidPenyetuju', 403);
        }

        return $penyetuju;
    }

    public function PastikanWaktuWajar(CarbonImmutable $waktu, string $bidang): void
    {
        if ($waktu->greaterThan(CarbonImmutable::now()->addSeconds(self::TOLERANSI_JAM_DETIK))) {
            throw new PelanggaranAturanBisnis('WaktuTidakValid', 'Waktu ada di masa depan. Periksa jam perangkat.', $bidang);
        }
    }

    /**
     * Baris pesanan → baris kiriman dapur (nama pilihan saja).
     *
     * @param  iterable<PesananTerbukaDetail>  $baris
     * @return list<DataBarisKirimDapur>
     */
    public static function KeBarisDapur(iterable $baris): array
    {
        $hasil = [];

        foreach ($baris as $b) {
            $hasil[] = new DataBarisKirimDapur(
                $b->Uuid,
                $b->IdProduk,
                $b->NamaProduk,
                (string) $b->Jumlah,
                array_values(array_map(fn (array $p): string => $p['Nama'], $b->Pilihan ?? [])),
                $b->Catatan,
            );
        }

        return $hasil;
    }
}
