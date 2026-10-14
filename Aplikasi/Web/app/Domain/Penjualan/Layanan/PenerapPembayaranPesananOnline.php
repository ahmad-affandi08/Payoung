<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Layanan;

use App\Domain\Akuntansi\Aksi\PostingJurnal;
use App\Domain\Akuntansi\Data\DataBarisJurnal;
use App\Domain\Akuntansi\Data\DataJurnal;
use App\Domain\Akuntansi\Enum\JenisSumberJurnal;
use App\Domain\Akuntansi\Enum\PeranAkun;
use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Dokumen\Layanan\PencatatRiwayatStatus;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Bersama\Peristiwa\PeristiwaIntegrasi;
use App\Domain\Organisasi\Kueri\TanggalBisnisOutlet;
use App\Domain\Pelanggan\Kueri\IdentitasPelanggan;
use App\Domain\Penjualan\Aksi\SiapkanMetodeUangMuka;
use App\Domain\Penjualan\Enum\PeristiwaPesananOnline;
use App\Domain\Penjualan\Enum\StatusPesananOnline;
use App\Domain\Penjualan\Enum\SumberTagihanQris;
use App\Domain\Penjualan\Model\MetodePembayaran;
use App\Domain\Penjualan\Model\PesananOnline;
use App\Domain\Penjualan\Model\TagihanQris;
use App\Domain\Penjualan\Tugas\BeritahuPesananOnlineMasukTugas;
use Carbon\CarbonImmutable;

/**
 * F-17 toko online bagian 2, **J-17.1**: uang pelanggan yang masuk lewat QRIS web dibukukan sebagai **kewajiban**,
 * bukan pendapatan — Dr akun kliring metode gerbang (atau peran `PiutangPencairan` bila metodenya tidak punya akun
 * sendiri), Cr `Uang Muka Pelanggan`. Bentuk jurnalnya sama dengan DP pre-order J-07.3, karena peristiwanya sama:
 * toko sudah menerima uang tetapi belum menyerahkan apa pun. Pendapatan, HPP, stok, dan pajak baru muncul saat
 * pesanan ditagihkan sebagai `Penjualan` di kasir dan uang mukanya dipakai lewat metode Uang Muka.
 *
 * Dipanggil `PenerapStatusTagihanQris` **di transaksi DB yang sama** dengan pelunasan tagihannya (aturan #10), jadi
 * tidak ada keadaan di mana tagihan sudah `Lunas` tetapi uangnya belum masuk buku. Idempoten: notifikasi gerbang yang
 * terkirim berulang tidak pernah menjurnal dua kali, dijaga `PesananOnline.DibayarPada` di bawah kunci baris.
 *
 * Periode terkunci tidak pernah menolak pembayaran: `PostingJurnal` menggeser tanggal posting ke periode terbuka
 * berikutnya (F-15/§18). Uang pelanggan sudah diterima; menolaknya hanya akan menghilangkannya dari buku.
 */
final class PenerapPembayaranPesananOnline
{
    public function __construct(
        private readonly TanggalBisnisOutlet $tanggalBisnis,
        private readonly SiapkanMetodeUangMuka $metodeUangMuka,
        private readonly PostingJurnal $postingJurnal,
        private readonly PencatatRiwayatStatus $riwayat,
        private readonly PencatatAudit $audit,
        private readonly PemberitahuPesananOnline $pemberitahu,
        private readonly IdentitasPelanggan $identitasPelanggan,
    ) {}

    /** Hasil: pesanan yang dibayar, atau null bila tagihan ini bukan milik pesanan online / sudah pernah diterapkan. */
    public function Terapkan(TagihanQris $tagihan): ?PesananOnline
    {
        if ($tagihan->Sumber !== SumberTagihanQris::TokoOnline || $tagihan->IdPesananOnline === null) {
            return null;
        }

        $pesanan = PesananOnline::query()->whereKey($tagihan->IdPesananOnline)->lockForUpdate()->first();

        if (! $pesanan instanceof PesananOnline || $pesanan->DibayarPada !== null) {
            return null;
        }

        $diterima = Uang::Dari($tagihan->JumlahDiterima ?? $tagihan->Jumlah);
        $metode = MetodePembayaran::query()->whereKey($tagihan->IdMetodePembayaran)->first();

        if (! $metode instanceof MetodePembayaran) {
            return null;
        }

        [$idAkun, $peran] = PenyusunJurnalPenjualan::TentukanAkunMetode($metode);
        $debit = $idAkun !== null
            ? new DataBarisJurnal(null, $idAkun, $pesanan->IdOutlet, $diterima, Uang::Nol(), $metode->Nama)
            : DataBarisJurnal::Debit($peran, $diterima, $pesanan->IdOutlet, $metode->Nama);
        $tanggal = CarbonImmutable::parse($this->tanggalBisnis->Hitung($pesanan->IdOutlet)->toDateString());

        $pesanan->IdJurnal = $this->postingJurnal->Jalankan(new DataJurnal(
            jenisSumber: JenisSumberJurnal::PesananOnline,
            idSumber: $pesanan->Id,
            uuidSumber: $pesanan->Uuid,
            nomorSumber: $pesanan->Nomor,
            tanggal: $tanggal,
            keterangan: mb_substr("Uang muka pesanan online {$pesanan->Nomor} lewat {$metode->Nama}", 0, 255),
            baris: [$debit, DataBarisJurnal::Kredit(PeranAkun::UangMukaPelanggan, $diterima, $pesanan->IdOutlet, $pesanan->Nomor)],
            idPengguna: null,
        ))->idJurnal;
        $pesanan->DibayarPada = now();
        $pesanan->JumlahDibayar = $diterima->KeString();
        $asal = $pesanan->Status;

        if ($asal === StatusPesananOnline::MenungguPembayaran) {
            $pesanan->UbahStatus(StatusPesananOnline::MenungguKonfirmasi);
            // OWN-03: pesanan berbayar di muka kini menunggu konfirmasi toko.
            BeritahuPesananOnlineMasukTugas::dispatch($pesanan->IdTenant, $pesanan->Id)->afterCommit();
        }

        $pesanan->save();
        // Kasir menagih pesanan berbayar lewat metode sistem "Uang muka (DP)"; tanpa metodenya dia kehabisan jalan,
        // jadi dibuat di sini (idempoten per tenant) alih-alih menunggu pre-order pertama tenant.
        $this->metodeUangMuka->Jalankan();

        if ($asal !== $pesanan->Status) {
            $this->riwayat->Catat(PesananOnline::JENIS_DOKUMEN, $pesanan->Id, $asal->value, $pesanan->Status->value, null, 'Pembayaran QRIS diterima');
            $this->pemberitahu->Antrekan($pesanan, PeristiwaPesananOnline::PembayaranDiterima);
        }

        $this->audit->Catat('pesanan-online.dibayar', $pesanan, ['Status' => $asal->value], [
            'Status' => $pesanan->Status->value,
            'JumlahDibayar' => $pesanan->JumlahDibayar,
            'NomorPesananQris' => $tagihan->NomorPesanan,
        ], idTenant: $pesanan->IdTenant);

        // X7 §16.4 (v4.07): webhook `pembayaran.diterima` setelah commit (sekali, dijaga `DibayarPada` di atas).
        PeristiwaIntegrasi::dispatch($pesanan->IdTenant, 'pembayaran.diterima', $pesanan->Id, [
            'Sumber' => 'PesananOnline',
            'Uuid' => $pesanan->Uuid,
            'Nomor' => $pesanan->Nomor,
            'UuidPelanggan' => $this->identitasPelanggan->AmbilUuid($pesanan->IdPelanggan),
            'Tanggal' => $tanggal->toDateString(),
            'Jumlah' => $diterima->KeString(),
            'Metode' => $metode->Nama,
            'Giro' => false,
            'Alokasi' => [],
        ]);

        return $pesanan;
    }
}
