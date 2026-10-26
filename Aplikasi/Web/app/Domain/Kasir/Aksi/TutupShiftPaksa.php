<?php

declare(strict_types=1);

namespace App\Domain\Kasir\Aksi;

use App\Domain\Akuntansi\Aksi\PostingJurnal;
use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Dokumen\Layanan\PencatatRiwayatStatus;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Bersama\Peristiwa\PeristiwaIntegrasi;
use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Kasir\Enum\StatusShift;
use App\Domain\Kasir\Kueri\LaporanShift;
use App\Domain\Kasir\Layanan\PenyusunJurnalSelisihKas;
use App\Domain\Kasir\Model\BukaUlangShift;
use App\Domain\Kasir\Model\Shift;
use App\Domain\Organisasi\Kueri\PetaUuidOutlet;
use App\Domain\Organisasi\Kueri\TanggalBisnisOutlet;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Tutup paksa shift dari back-office: shift yang tidak pernah ditutup kasir (aplikasi hilang, perangkat rusak, lupa)
 * menahan kasir membuka shift baru dan membuat penjualannya tidak tampil rapi. Supervisor ber-izin
 * `shift.selisih.setujui` menutupnya dengan alasan tertulis (minimal 5 karakter).
 *
 * - Hanya shift aktif (`Terbuka`/`DibukaUlang`) yang bisa ditutup paksa (`ShiftTidakAktif`).
 * - Kas aktual = hitungan supervisor bila diisi; bila tidak, sama dengan kas seharusnya (selisih nol, tanpa jurnal).
 *   Kas seharusnya dihitung server dari datanya (`LaporanShift`). Selisih yang diisi dicatat J-11.1/J-11.2 seperti
 *   tutup shift biasa; periode terkunci → `PeriodeTerkunci`.
 * - Shift ditandai `PerluTinjauan` (`DitutupPaksa`) dan dicatat di riwayat status & audit. Penjualan/kas dari
 *   perangkat yang tiba terlambat tetap diterima dengan tinjauan `ShiftSudahDitutup`, sedangkan `Shift.Tutup` dari
 *   perangkat yang sama (data berbeda) ditolak `ShiftSudahDitutup` supaya tidak menimpa penutupan ini.
 */
final class TutupShiftPaksa
{
    private const PANJANG_ALASAN_MINIMAL = 5;

    public function __construct(
        private readonly KonteksTenant $konteks,
        private readonly TanggalBisnisOutlet $tanggalBisnis,
        private readonly LaporanShift $laporan,
        private readonly PenyusunJurnalSelisihKas $penyusun,
        private readonly PostingJurnal $postingJurnal,
        private readonly PencatatRiwayatStatus $riwayat,
        private readonly PencatatAudit $audit,
        private readonly PetaUuidOutlet $petaOutlet,
    ) {}

    /**
     * @param  list<int>|null  $idOutletBoleh  batas outlet pelaku (null = semua)
     */
    public function Jalankan(string $uuidShift, int $idPelaku, string $alasan, ?Uang $kasAktual, ?array $idOutletBoleh): Shift
    {
        $alasan = trim($alasan);

        if (mb_strlen($alasan) < self::PANJANG_ALASAN_MINIMAL) {
            throw new PelanggaranAturanBisnis('AlasanDiperlukan', 'Tulis alasan tutup paksa minimal 5 karakter.', 'Alasan');
        }

        return DB::transaction(function () use ($uuidShift, $idPelaku, $alasan, $kasAktual, $idOutletBoleh): Shift {
            $idTenant = $this->konteks->Wajib();
            $shift = Shift::query()->where('Uuid', $uuidShift)->lockForUpdate()->first();

            if ($shift === null || ($idOutletBoleh !== null && ! in_array($shift->IdOutlet, $idOutletBoleh, true))) {
                throw new PelanggaranAturanBisnis('ShiftTidakDikenal', 'Shift tidak ditemukan.', 'UuidShift', 404);
            }

            if (! $shift->Status->CekAktif()) {
                throw new PelanggaranAturanBisnis('ShiftTidakAktif', "Shift ini {$shift->Status->AmbilLabel()}; tidak perlu ditutup paksa.", 'UuidShift');
            }

            $sekarang = CarbonImmutable::now();
            $laporan = $this->laporan->Hitung($shift);
            $aktual = $kasAktual ?? $laporan->kasSeharusnya;
            $selisih = $aktual->Kurangi($laporan->kasSeharusnya);
            $statusLama = $shift->Status;

            $shift->Status = StatusShift::Tertutup;
            $shift->DitutupOleh = $idPelaku;
            $shift->DitutupPada = Carbon::instance($sekarang);
            $shift->KasSeharusnya = $laporan->kasSeharusnya->KeString();
            $shift->KasAktual = $aktual->KeString();
            $shift->Selisih = $selisih->KeString();
            $shift->PecahanKasAkhir = null;
            $shift->AlasanSelisih = $alasan;
            $shift->IdPenyetujuSelisih = $idPelaku;
            $shift->PerluTinjauan = true;
            $shift->AlasanTinjauan = mb_substr(implode('; ', array_filter([$shift->AlasanTinjauan, "DitutupPaksa: {$alasan}"])), 0, 255);
            $shift->save();

            $bukaUlang = BukaUlangShift::query()->where('IdShift', $shift->Id)->count();
            $jurnal = $this->penyusun->Susun($shift, $selisih, $this->tanggalBisnis->Hitung($shift->IdOutlet, $sekarang), $idPelaku, $bukaUlang === 0 ? 'Utama' : 'Tutup-'.($bukaUlang + 1));

            if ($jurnal !== null) {
                $this->postingJurnal->Jalankan($jurnal);
            }

            $this->riwayat->Catat(Shift::JENIS_DOKUMEN, $shift->Id, $statusLama->value, StatusShift::Tertutup->value, $idPelaku, "Ditutup paksa: {$alasan}");
            $this->audit->Catat('shift.tutup-paksa', $shift, nilaiBaru: [
                'KasSeharusnya' => $shift->KasSeharusnya,
                'KasAktual' => $shift->KasAktual,
                'Selisih' => $shift->Selisih,
                'Alasan' => $alasan,
            ], idPengguna: $idPelaku);

            PeristiwaIntegrasi::dispatch($idTenant, 'shift.ditutup', $shift->Id, [
                'Uuid' => $shift->Uuid,
                'UuidOutlet' => $this->petaOutlet->Ambil([$shift->IdOutlet])[$shift->IdOutlet] ?? null,
                'TanggalBisnis' => $shift->TanggalBisnis->toDateString(),
                'DibukaPada' => $shift->DibukaPada->toIso8601ZuluString(),
                'DitutupPada' => $shift->DitutupPada->toIso8601ZuluString(),
                'KasAwal' => Uang::Dari($shift->KasAwal)->KeString(),
                'KasSeharusnya' => $laporan->kasSeharusnya->KeString(),
                'KasAktual' => $aktual->KeString(),
                'Selisih' => $selisih->KeString(),
            ], kunci: (string) ($bukaUlang + 1));

            return $shift;
        });
    }
}
