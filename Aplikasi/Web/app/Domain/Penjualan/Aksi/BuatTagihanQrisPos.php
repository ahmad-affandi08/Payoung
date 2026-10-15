<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Dokumen\Layanan\PencatatRiwayatStatus;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Integrasi\GerbangPembayaran\GalatGerbang;
use App\Domain\Integrasi\GerbangPembayaran\PembuatGerbangPembayaran;
use App\Domain\Integrasi\GerbangPembayaran\PermintaanQris;
use App\Domain\Penjualan\Data\DataTagihanQrisPos;
use App\Domain\Penjualan\Enum\JenisMetodePembayaran;
use App\Domain\Penjualan\Enum\StatusTagihanQris;
use App\Domain\Penjualan\Layanan\NomorPesananQris;
use App\Domain\Penjualan\Model\MetodePembayaran;
use App\Domain\Penjualan\Model\TagihanQris;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Throwable;

/**
 * F-08 QRIS dinamis (BR-08.5): POS meminta tagihan QRIS untuk satu pembayaran lewat gerbang pembayaran aktif milik
 * tenant (v2.06: akun merchant tenant, penyedia diizinkan platform; URL notifikasi = URL webhook tenant).
 * - Idempoten per `Uuid` perangkat: Uuid yang sama mengembalikan tagihan yang sama tanpa memanggil gerbang lagi.
 * - Baris dicadangkan dulu (indeks unik `IdTenant+Uuid`, `IsiQr` kosong) lalu gerbang dipanggil di luar transaksi DB.
 *   Gerbang pasti menolak (4xx jelas) = cadangan dihapus (Uuid yang sama boleh dicoba lagi) dan 502 `GerbangGagal`.
 * - Audit P0 F-02: hasil tidak pasti (koneksi putus/waktu habis, 5xx, respons rusak, galat tak terduga, atau proses
 *   terhenti sebelum QR tersimpan) = cadangan TIDAK dihapus: status `TidakPasti` + `NomorPesanan` tetap tersimpan agar
 *   webhook/rekonsiliasi (`RekonsiliasiTagihanQris`) bisa mencocokkannya; POS diminta membuat tagihan baru (Uuid baru)
 *   lewat 502 `GerbangTidakPasti`, dan Uuid lama dijawab 409 `TagihanTidakPasti`. QR tagihan itu tidak pernah tampil.
 * - Berlaku `MENIT_BERLAKU` menit (atau batas dari gerbang bila lebih dulu).
 * - Galat: 409 `GerbangBelumAktif` (tenant belum mengaktifkan gerbang atau penyedianya dilarang platform), 422 `MetodeBukanQrisDinamis`, 422 `JumlahTidakBulat`/`JumlahTidakValid`,
 *   409 `UuidSudahDipakai` (Uuid milik perangkat lain), 409 `TagihanSedangDibuat` (permintaan sama masih diproses).
 *
 * Hasil: [tagihan, baru dibuat?].
 */
final class BuatTagihanQrisPos
{
    public const MENIT_BERLAKU = 15;

    public const JUMLAH_MAKSIMAL = '100000000';

    /** Cadangan tanpa isi QR lebih tua dari ini dianggap proses yang terputus dan boleh dibuat ulang. */
    private const DETIK_CADANGAN_BASI = 60;

    public function __construct(
        private readonly KonteksTenant $konteks,
        private readonly PembuatGerbangPembayaran $pembuatGerbang,
        private readonly PencatatRiwayatStatus $riwayat,
        private readonly PencatatAudit $audit,
    ) {}

    /**
     * @return array{0: TagihanQris, 1: bool}
     */
    public function Jalankan(DataTagihanQrisPos $data): array
    {
        $idTenant = $this->konteks->Wajib();
        $uuid = strtoupper($data->uuid);
        $ada = $this->CariAda($uuid, $data->idPerangkat);

        if ($ada !== null) {
            return [$ada, false];
        }

        $gerbangTenant = $this->pembuatGerbang->AmbilAktifTenant()
            ?? throw new PelanggaranAturanBisnis('GerbangBelumAktif', 'QRIS dinamis belum bisa dipakai karena QRIS toko belum diaktifkan. Pakai metode lain.', 'UuidMetode', 409);
        $gerbang = $gerbangTenant->gerbang;

        $metode = MetodePembayaran::query()->where('Uuid', strtoupper($data->uuidMetode))->first();

        if ($metode === null || ! $metode->Aktif || $metode->Jenis !== JenisMetodePembayaran::QrisDinamis) {
            throw new PelanggaranAturanBisnis('MetodeBukanQrisDinamis', 'Metode pembayaran ini bukan QRIS dinamis yang aktif.', 'UuidMetode');
        }

        $jumlah = self::PeriksaJumlah($data->jumlah);
        $nomor = NomorPesananQris::Buat($idTenant, $uuid);
        $keterangan = $data->keterangan === null || trim($data->keterangan) === '' ? null : mb_substr(trim($data->keterangan), 0, 100);
        $kedaluwarsa = CarbonImmutable::now()->addMinutes(self::MENIT_BERLAKU);

        try {
            $tagihan = TagihanQris::query()->create([
                'Uuid' => $uuid,
                'IdOutlet' => $data->idOutlet,
                'IdPerangkat' => $data->idPerangkat,
                'IdMetodePembayaran' => $metode->Id,
                'NomorPesanan' => $nomor,
                'Penyedia' => $gerbang->AmbilKode(),
                'IsiQr' => '',
                'Jumlah' => $jumlah->KeString(),
                'Keterangan' => $keterangan,
                'Status' => StatusTagihanQris::Menunggu,
                'KedaluwarsaPada' => $kedaluwarsa,
            ]);
        } catch (QueryException $galat) {
            if (($galat->errorInfo[1] ?? null) !== 1062) {
                throw $galat;
            }

            return [$this->CariAda($uuid, $data->idPerangkat) ?? throw $galat, false];
        }

        try {
            $hasil = $gerbang->BuatQris(new PermintaanQris(
                nomorPesanan: $nomor,
                jumlah: $jumlah,
                keterangan: $keterangan ?? 'Pembayaran QRIS',
                kedaluwarsaPada: $kedaluwarsa,
                urlNotifikasi: $gerbangTenant->urlNotifikasi,
            ));
        } catch (GalatGerbang $galat) {
            if (! $galat->tidakPasti) {
                $tagihan->delete();

                throw new PelanggaranAturanBisnis('GerbangGagal', $galat->getMessage(), 'Umum', 502);
            }

            $this->TandaiTidakPasti($tagihan, $galat->getMessage());

            throw self::GalatTidakPasti('GerbangTidakPasti', 502);
        } catch (Throwable $galat) {
            $this->TandaiTidakPasti($tagihan, 'Galat tak terduga saat menghubungi gerbang.');

            throw $galat;
        }

        $tagihan->forceFill([
            'IdReferensi' => $hasil->idReferensi === '' ? null : mb_substr($hasil->idReferensi, 0, 100),
            'IsiQr' => $hasil->isiQr,
            'HalamanBayar' => $hasil->halamanBayar,
            'KedaluwarsaPada' => $hasil->kedaluwarsaPada !== null && $hasil->kedaluwarsaPada->lessThan($kedaluwarsa) ? $hasil->kedaluwarsaPada : $kedaluwarsa,
        ])->save();
        $this->riwayat->Catat(TagihanQris::JENIS_DOKUMEN, $tagihan->Id, null, StatusTagihanQris::Menunggu->value, null);
        $this->audit->Catat('tagihan-qris.buat', $tagihan, nilaiBaru: [
            'NomorPesanan' => $nomor,
            'Penyedia' => $tagihan->Penyedia,
            'Jumlah' => $tagihan->Jumlah,
            'Metode' => $metode->Nama,
        ]);

        return [$tagihan, true];
    }

    /**
     * Tagihan ber-Uuid sama milik perangkat ini (kiriman ulang). Cadangan basi tanpa isi QR dihapus agar bisa dibuat ulang.
     */
    private function CariAda(string $uuid, int $idPerangkat): ?TagihanQris
    {
        $ada = TagihanQris::query()->where('Uuid', $uuid)->first();

        if ($ada === null) {
            return null;
        }

        if ($ada->IdPerangkat !== $idPerangkat) {
            throw new PelanggaranAturanBisnis('UuidSudahDipakai', 'Kode unik tagihan ini sudah dipakai perangkat lain. Buat ulang tagihan.', 'Uuid', 409);
        }

        if ($ada->IsiQr !== '') {
            return $ada;
        }

        if ($ada->Status === StatusTagihanQris::TidakPasti) {
            throw self::GalatTidakPasti('TagihanTidakPasti', 409);
        }

        // Cadangan basi = proses sebelumnya terhenti setelah (mungkin) memanggil gerbang: jangan dihapus (audit P0 F-02).
        if ($ada->DibuatPada !== null && $ada->DibuatPada->lessThan(CarbonImmutable::now()->subSeconds(self::DETIK_CADANGAN_BASI))) {
            $this->TandaiTidakPasti($ada, 'Proses pembuatan tagihan terhenti sebelum QR tersimpan.');

            throw self::GalatTidakPasti('TagihanTidakPasti', 409);
        }

        throw new PelanggaranAturanBisnis('TagihanSedangDibuat', 'Tagihan QRIS ini masih dibuat. Coba lagi beberapa detik lagi.', 'Uuid', 409);
    }

    /** Cadangan yang hasilnya di gerbang tidak pasti: disimpan (bukan dihapus) untuk rekonsiliasi. Idempoten. */
    private function TandaiTidakPasti(TagihanQris $tagihan, string $pesan): void
    {
        $diubah = TagihanQris::query()->whereKey($tagihan->Id)->where('Status', StatusTagihanQris::Menunggu->value)->where('IsiQr', '')
            ->update([
                'Status' => StatusTagihanQris::TidakPasti->value,
                'PesanGalatGerbang' => mb_substr($pesan, 0, 300),
                'DiubahPada' => now(),
            ]);

        if ($diubah !== 1) {
            return;
        }

        $tagihan->refresh();
        $this->riwayat->Catat(TagihanQris::JENIS_DOKUMEN, $tagihan->Id, StatusTagihanQris::Menunggu->value, StatusTagihanQris::TidakPasti->value, null, 'Hasil gerbang tidak pasti');
        $this->audit->Catat('tagihan-qris.tidak-pasti', $tagihan, nilaiBaru: [
            'NomorPesanan' => $tagihan->NomorPesanan,
            'Penyedia' => $tagihan->Penyedia,
            'Jumlah' => $tagihan->Jumlah,
        ]);
    }

    private static function GalatTidakPasti(string $kode, int $statusHttp): PelanggaranAturanBisnis
    {
        return new PelanggaranAturanBisnis(
            $kode,
            'Koneksi ke gerbang pembayaran terputus sebelum QRIS diterima, jadi tagihan ini belum pasti dan tidak ditampilkan. Buat tagihan QRIS baru atau pakai metode lain; tagihan lama dicek otomatis.',
            'Umum',
            $statusHttp,
        );
    }

    /** Rupiah penuh 1 s.d. `JUMLAH_MAKSIMAL` (QRIS tidak mengenal sen). */
    private static function PeriksaJumlah(string $jumlah): Uang
    {
        $teks = trim($jumlah);

        if (preg_match('/^\d{1,12}(\.\d+)?$/', $teks) !== 1) {
            throw new PelanggaranAturanBisnis('JumlahTidakValid', 'Jumlah QRIS tidak valid.', 'Jumlah');
        }

        $nilai = BigDecimal::of($teks);

        if (! $nilai->getFractionalPart()->isZero()) {
            throw new PelanggaranAturanBisnis('JumlahTidakBulat', 'Jumlah QRIS harus rupiah penuh tanpa sen.', 'Jumlah');
        }

        if ($nilai->isLessThan(1) || $nilai->isGreaterThan(self::JUMLAH_MAKSIMAL)) {
            throw new PelanggaranAturanBisnis('JumlahTidakValid', 'Jumlah QRIS antara Rp 1 dan Rp 100.000.000.', 'Jumlah');
        }

        return Uang::Dari($nilai->toScale(0));
    }
}
