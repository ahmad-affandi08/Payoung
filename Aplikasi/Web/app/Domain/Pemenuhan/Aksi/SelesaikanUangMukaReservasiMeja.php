<?php

declare(strict_types=1);

namespace App\Domain\Pemenuhan\Aksi;

use App\Domain\Akuntansi\Aksi\PostingJurnal;
use App\Domain\Akuntansi\Data\DataBarisJurnal;
use App\Domain\Akuntansi\Data\DataJurnal;
use App\Domain\Akuntansi\Enum\JenisSumberJurnal;
use App\Domain\Akuntansi\Enum\PeranAkun;
use App\Domain\Akuntansi\Kueri\DaftarAkunPilihan;
use App\Domain\Akuntansi\Layanan\PenjagaKunciPeriode;
use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Organisasi\Kueri\TanggalBisnisOutlet;
use App\Domain\Pemenuhan\Enum\CaraSelesaiUangMukaMeja;
use App\Domain\Pemenuhan\Model\ReservasiMeja;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * FnB reservasi meja (D-145): menyelesaikan **sisa** uang muka yang tidak dipakai membayar tagihan, sekali. `Dikembalikan`
 * → Dr Uang Muka Pelanggan, Cr akun kas/bank pilihan; `Hangus` → Dr Uang Muka Pelanggan, Cr Pendapatan Lain. Bertanggal
 * bisnis hari ini (periode harus terbuka). Alasan 5–255 karakter. Dipakai langsung dari rincian reservasi, dan oleh
 * `UbahStatusReservasiMeja` saat reservasi dibatalkan/tidak datang/selesai dengan sisa DP. Audit `reservasi-meja.dp-selesai`.
 */
final class SelesaikanUangMukaReservasiMeja
{
    /** Jurnal penyelesaian terpisah dari jurnal DP pada sumber yang sama. */
    public const KUNCI_JURNAL = 'Penyelesaian';

    public function __construct(
        private readonly DaftarAkunPilihan $akun,
        private readonly TanggalBisnisOutlet $tanggalBisnis,
        private readonly PenjagaKunciPeriode $penjagaPeriode,
        private readonly PostingJurnal $postingJurnal,
        private readonly PencatatAudit $audit,
    ) {}

    public function Jalankan(ReservasiMeja $reservasi, CaraSelesaiUangMukaMeja $cara, ?string $uuidAkun, string $alasan, int $idPengguna): ReservasiMeja
    {
        $alasan = trim($alasan);

        if (mb_strlen($alasan) < 5 || mb_strlen($alasan) > 255) {
            throw new PelanggaranAturanBisnis('AlasanWajib', 'Tulis alasan 5–255 karakter.', 'Alasan');
        }

        $idAkun = null;

        if ($cara === CaraSelesaiUangMukaMeja::Dikembalikan) {
            $idAkun = ($uuidAkun === null || $uuidAkun === '' ? null : $this->akun->CariKasBankDariUuid($uuidAkun))['Id']
                ?? throw new PelanggaranAturanBisnis('AkunKasBankWajib', 'Pilih akun kas/bank aktif untuk mengembalikan uang muka.', 'UuidAkun');
        }

        return DB::transaction(function () use ($reservasi, $cara, $idAkun, $alasan, $idPengguna): ReservasiMeja {
            $r = ReservasiMeja::query()->whereKey($reservasi->Id)->lockForUpdate()->firstOrFail();
            $sisa = $r->AmbilSisaUangMuka();

            if ($sisa->Bandingkan(Uang::Nol()) <= 0) {
                throw new PelanggaranAturanBisnis('TidakAdaSisaUangMuka', "Reservasi {$r->Nomor} tidak punya sisa uang muka.", 'JumlahDp');
            }

            $tanggal = $this->tanggalBisnis->Hitung($r->IdOutlet);
            $this->penjagaPeriode->PastikanTerbuka($tanggal);
            $kredit = $idAkun !== null
                ? new DataBarisJurnal(null, $idAkun, $r->IdOutlet, Uang::Nol(), $sisa, "Pengembalian uang muka {$r->Nomor}")
                : DataBarisJurnal::Kredit(PeranAkun::PendapatanLain, $sisa, $r->IdOutlet, "Uang muka hangus {$r->Nomor}");
            $r->IdJurnalPenyelesaianDp = $this->postingJurnal->Jalankan(new DataJurnal(
                jenisSumber: JenisSumberJurnal::ReservasiMeja,
                idSumber: $r->Id,
                uuidSumber: $r->Uuid,
                nomorSumber: $r->Nomor,
                tanggal: CarbonImmutable::parse($tanggal->toDateString()),
                keterangan: mb_substr(($cara === CaraSelesaiUangMukaMeja::Dikembalikan ? 'Pengembalian' : 'Uang muka hangus')." reservasi meja {$r->Nomor}: {$alasan}", 0, 255),
                baris: [DataBarisJurnal::Debit(PeranAkun::UangMukaPelanggan, $sisa, $r->IdOutlet, $r->Nomor), $kredit],
                idPengguna: $idPengguna,
                kunciSumber: self::KUNCI_JURNAL,
            ))->idJurnal;

            if ($cara === CaraSelesaiUangMukaMeja::Dikembalikan) {
                $r->DpDikembalikan = Uang::Dari($r->DpDikembalikan)->Tambah($sisa)->KeString();
            } else {
                $r->DpHangus = Uang::Dari($r->DpHangus)->Tambah($sisa)->KeString();
            }

            $r->save();
            $this->audit->Catat('reservasi-meja.dp-selesai', $r, nilaiBaru: ['Nomor' => $r->Nomor, 'Cara' => $cara->value, 'Jumlah' => $sisa->KeString(), 'Alasan' => $alasan], idPengguna: $idPengguna);

            return $r;
        });
    }
}
