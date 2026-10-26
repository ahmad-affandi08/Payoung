<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Model;

use App\Domain\Bersama\Model\ModelDasar;
use App\Domain\Bersama\Tenant\MilikTenant;
use App\Domain\Penjualan\Enum\KanalPenjualan;
use App\Domain\Penjualan\Enum\StatusPenjualan;
use Closure;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Penjualan (PRD F-07, §15.3). F-07b: dibuat di perangkat POS (bisa offline), diterima server lewat sinkron sebagai
 * dokumen `Lunas`. `Uuid` = `UuidKlien` perangkat. Dokumen yang diterima tidak pernah diubah atau dihapus (CLAUDE.md
 * #8); koreksi lewat void/retur (F-09). Pengecualian: `TotalHpp` dan `IdJurnal` diisi sekali di transaksi penerimaan
 * (setelah mutasi stok & jurnal) dan hanya selama penanda penerimaan aktif (`JalankanPenerimaan`, dipakai
 * `TerimaPenjualanPos`), tanda tinjauan (`PerluTinjauan`, `AlasanTinjauan`), dan `Status` lewat
 * `StatusPenjualan::BisaBerubahKe()`.
 *
 * @property int $Id
 * @property string $Uuid
 * @property int $IdTenant
 * @property int $IdOutlet
 * @property int $IdShift
 * @property int $IdPerangkat
 * @property string $Nomor
 * @property KanalPenjualan $Kanal
 * @property StatusPenjualan $Status
 * @property int|null $IdPelanggan
 * @property int|null $IdPesananPenjualan
 * @property Carbon $TanggalBisnis
 * @property int $IdPengguna
 * @property int|null $IdPenyetujuDiskon
 * @property int|null $IdPenyetujuHarga penyetuju (PIN) perubahan harga baris bila kasirnya tanpa izin `pos.harga.timpa`
 * @property int|null $IdPenyetujuTempo
 * @property int|null $IdApoteker Apotek (§9.5): apoteker yang menyerahkan obat keras/OWA/psikotropika/narkotika
 * @property bool $HargaTermasukPajak
 * @property string $PersenBiayaLayanan
 * @property array{Kelipatan: int, Arah: string}|null $PembulatanTunai
 * @property string $Subtotal
 * @property string $DiskonBaris
 * @property string $DiskonPesanan
 * @property int $PoinDitukar
 * @property string $DiskonPoin
 * @property string $TotalDiskon
 * @property string $BiayaLayanan
 * @property string $BiayaKirim ongkir yang ditagih ke pembeli (F-17 bagian 3)
 * @property string $DiskonKirim potongan ongkir, mis. promo gratis ongkir F-16c; dipisah agar tetap terlihat di laporan
 * @property string $TotalPajak
 * @property string $TotalPajakEksklusif
 * @property string $Pembulatan
 * @property string $TotalAkhir
 * @property string $TotalDibayar
 * @property string $Kembalian
 * @property string $TotalHpp
 * @property string|null $Catatan
 * @property string|null $NomorAntrian nomor panggil penjualan bayar-dulu (v3.52)
 * @property string|null $NamaPemesan nama yang dipanggil saat pesanan siap (v3.52)
 * @property int|null $IdReturTukar retur tukar barang yang nilainya membayar penjualan ini (K-11)
 * @property bool $PerluTinjauan
 * @property string|null $AlasanTinjauan
 * @property int|null $IdJurnal
 * @property Carbon $DibuatOfflinePada
 * @property Carbon $DiterimaPada
 */
final class Penjualan extends ModelDasar
{
    use MilikTenant;

    /** Nilai `RiwayatStatusDokumen.JenisDokumen` untuk dokumen ini. */
    public const JENIS_DOKUMEN = 'Penjualan';

    /** Kolom yang boleh berubah bebas setelah diterima (tanda tinjauan). */
    private const KOLOM_TINJAUAN = ['PerluTinjauan', 'AlasanTinjauan'];

    protected $table = 'Penjualan';

    /** Penanda penerimaan: hanya aktif di dalam `JalankanPenerimaan` (transaksi `TerimaPenjualanPos`). */
    private static bool $sedangMenerima = false;

    /**
     * Menjalankan penerimaan penjualan POS: selama `kerja` berjalan, `IdJurnal`/`TotalHpp` penjualan yang belum
     * berjurnal dan HPP baris (`PenjualanDetail`) boleh diisi. Penanda selalu dipulihkan (juga saat galat).
     *
     * @template T
     *
     * @param  Closure(): T  $kerja
     * @return T
     */
    public static function JalankanPenerimaan(Closure $kerja): mixed
    {
        $lama = self::$sedangMenerima;
        self::$sedangMenerima = true;

        try {
            return $kerja();
        } finally {
            self::$sedangMenerima = $lama;
        }
    }

    public static function CekSedangMenerima(): bool
    {
        return self::$sedangMenerima;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'Kanal' => KanalPenjualan::class,
            'Status' => StatusPenjualan::class,
            'TanggalBisnis' => 'date',
            'HargaTermasukPajak' => 'boolean',
            'PersenBiayaLayanan' => 'decimal:2',
            'PembulatanTunai' => 'array',
            'Subtotal' => 'decimal:2',
            'DiskonBaris' => 'decimal:2',
            'DiskonPesanan' => 'decimal:2',
            'PoinDitukar' => 'integer',
            'DiskonPoin' => 'decimal:2',
            'TotalDiskon' => 'decimal:2',
            'BiayaLayanan' => 'decimal:2',
            'BiayaKirim' => 'decimal:2',
            'DiskonKirim' => 'decimal:2',
            'TotalPajak' => 'decimal:2',
            'TotalPajakEksklusif' => 'decimal:2',
            'Pembulatan' => 'decimal:2',
            'TotalAkhir' => 'decimal:2',
            'TotalDibayar' => 'decimal:2',
            'Kembalian' => 'decimal:2',
            'TotalHpp' => 'decimal:2',
            'PerluTinjauan' => 'boolean',
            'DibuatOfflinePada' => 'datetime',
            'DiterimaPada' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::updating(function (Penjualan $penjualan): void {
            $sedangDiterima = self::$sedangMenerima && $penjualan->getOriginal('IdJurnal') === null;

            foreach (array_keys($penjualan->getDirty()) as $kolom) {
                $boleh = in_array($kolom, self::KOLOM_TINJAUAN, true)
                    || $kolom === self::UPDATED_AT
                    || ($kolom === 'IdJurnal' && $sedangDiterima)
                    || ($kolom === 'TotalHpp' && $sedangDiterima)
                    || ($kolom === 'Status' && self::CekStatusBoleh($penjualan));

                if (! $boleh) {
                    throw new LogicException("Penjualan yang sudah diterima tidak boleh diubah (kolom {$kolom}). Koreksi lewat void/retur.");
                }
            }
        });

        self::deleting(function (): void {
            throw new LogicException('Penjualan tidak boleh dihapus. Koreksi lewat void/retur.');
        });
    }

    /**
     * @return HasMany<PenjualanDetail, $this>
     */
    public function Detail(): HasMany
    {
        return $this->hasMany(PenjualanDetail::class, 'IdPenjualan', 'Id');
    }

    /**
     * @return HasMany<PenjualanPembayaran, $this>
     */
    public function Pembayaran(): HasMany
    {
        return $this->hasMany(PenjualanPembayaran::class, 'IdPenjualan', 'Id');
    }

    /**
     * @return HasMany<PenjualanPajak, $this>
     */
    public function Pajak(): HasMany
    {
        return $this->hasMany(PenjualanPajak::class, 'IdPenjualan', 'Id');
    }

    private static function CekStatusBoleh(Penjualan $penjualan): bool
    {
        $lama = $penjualan->getOriginal('Status');
        $lama = $lama instanceof StatusPenjualan ? $lama : StatusPenjualan::tryFrom((string) $lama);

        return $lama !== null && $lama->BisaBerubahKe($penjualan->Status);
    }
}
