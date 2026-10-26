<?php

declare(strict_types=1);

namespace App\Domain\Pemenuhan\Aksi;

use App\Domain\Akuntansi\Aksi\PostingJurnal;
use App\Domain\Akuntansi\Data\DataBarisJurnal;
use App\Domain\Akuntansi\Data\DataJurnal;
use App\Domain\Akuntansi\Enum\JenisSumberJurnal;
use App\Domain\Akuntansi\Enum\PeranAkun;
use App\Domain\Akuntansi\Kueri\DaftarAkunPilihan;
use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Organisasi\Kueri\TanggalBisnisOutlet;
use App\Domain\Pemenuhan\Model\ReservasiMeja;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * FnB reservasi meja (D-145): mencatat uang muka (DP) yang diterima dari tamu. **Dibukukan sebagai kewajiban**, bukan
 * pendapatan: Dr akun kas/bank pilihan, Cr Uang Muka Pelanggan (dimensi outlet), bentuk yang sama dengan DP pre-order
 * (J-07.3) dan pesanan online (J-17.1). Pendapatan baru muncul saat DP dipakai membayar tagihan di kasir. Satu DP per
 * reservasi (sudah dicatat = ditolak), nominal Rp 1 – Rp 100.000.000, hanya selagi reservasi Menunggu/Dikonfirmasi/Hadir.
 * Periode terkunci tidak menolak (uang sudah diterima): jurnal digeser ke periode terbuka oleh `PostingJurnal` (F-15).
 * Audit `reservasi-meja.dp-catat`.
 */
final class CatatUangMukaReservasiMeja
{
    public const MAKS_DP = '100000000';

    public function __construct(
        private readonly DaftarAkunPilihan $akun,
        private readonly TanggalBisnisOutlet $tanggalBisnis,
        private readonly PostingJurnal $postingJurnal,
        private readonly PencatatAudit $audit,
    ) {}

    public function Jalankan(ReservasiMeja $reservasi, Uang $jumlah, string $uuidAkun, int $idPengguna): ReservasiMeja
    {
        if ($jumlah->Bandingkan(Uang::Nol()) <= 0) {
            throw new PelanggaranAturanBisnis('UangMukaWajib', 'Uang muka harus lebih dari Rp 0.', 'JumlahDp');
        }

        if ($jumlah->Bandingkan(Uang::Dari(self::MAKS_DP)) > 0) {
            throw new PelanggaranAturanBisnis('UangMukaTerlaluBesar', 'Uang muka maksimal Rp 100.000.000.', 'JumlahDp');
        }

        $akun = $this->akun->CariKasBankDariUuid($uuidAkun)
            ?? throw new PelanggaranAturanBisnis('AkunKasBankWajib', 'Pilih akun kas/bank aktif tempat uang muka diterima.', 'UuidAkun');

        return DB::transaction(function () use ($reservasi, $jumlah, $akun, $idPengguna): ReservasiMeja {
            $r = ReservasiMeja::query()->whereKey($reservasi->Id)->lockForUpdate()->firstOrFail();

            if (! $r->Status->CekMemakaiMeja()) {
                throw new PelanggaranAturanBisnis('ReservasiSudahBerakhir', "Reservasi {$r->Nomor} berstatus {$r->Status->AmbilLabel()}. Uang muka tidak bisa dicatat lagi.", 'Status');
            }

            if (Uang::Dari($r->JumlahDp)->Bandingkan(Uang::Nol()) > 0) {
                throw new PelanggaranAturanBisnis('UangMukaSudahAda', "Uang muka {$r->Nomor} sudah dicatat ".Uang::Dari($r->JumlahDp)->FormatRupiah().'. Kembalikan atau hanguskan dulu bila ingin mengganti.', 'JumlahDp');
            }

            $tanggal = $this->tanggalBisnis->Hitung($r->IdOutlet);
            $r->IdJurnalDp = $this->postingJurnal->Jalankan(new DataJurnal(
                jenisSumber: JenisSumberJurnal::ReservasiMeja,
                idSumber: $r->Id,
                uuidSumber: $r->Uuid,
                nomorSumber: $r->Nomor,
                tanggal: CarbonImmutable::parse($tanggal->toDateString()),
                keterangan: mb_substr("Uang muka reservasi meja {$r->Nomor} a.n. {$r->NamaPemesan}", 0, 255),
                baris: [
                    new DataBarisJurnal(null, $akun['Id'], $r->IdOutlet, $jumlah, Uang::Nol(), "Uang muka {$r->Nomor}"),
                    DataBarisJurnal::Kredit(PeranAkun::UangMukaPelanggan, $jumlah, $r->IdOutlet, $r->Nomor),
                ],
                idPengguna: $idPengguna,
            ))->idJurnal;
            $r->JumlahDp = $jumlah->KeString();
            $r->DpDicatatPada = now();
            $r->save();
            $this->audit->Catat('reservasi-meja.dp-catat', $r, nilaiBaru: ['Nomor' => $r->Nomor, 'JumlahDp' => $r->JumlahDp, 'IdAkun' => $akun['Id']], idPengguna: $idPengguna);

            return $r;
        });
    }
}
