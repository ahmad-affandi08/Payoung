<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Tagihan\Aksi;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Pengelola\Tagihan\Kueri\DaftarTagihanPlatform;
use App\Domain\Pengelola\Tagihan\Layanan\PelaksanaRefundLangganan;
use App\Domain\Pengelola\Tagihan\Layanan\PemberitahuRefundLangganan;
use App\Domain\Pengelola\TimInternal\Enum\IzinPengelola;
use App\Domain\Pengelola\TimInternal\Layanan\PencatatAuditPengelola;
use App\Domain\Pengelola\TimInternal\Model\PenggunaPengelola;
use App\Domain\Tenant\Enum\StatusRefundLangganan;
use App\Domain\Tenant\Enum\StatusTagihanLangganan;
use App\Domain\Tenant\Model\Langganan;
use App\Domain\Tenant\Model\RefundLangganan;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Keuangan/Super Admin mengajukan refund penuh tagihan yang sudah lunas (P-08, BR-P08.2, D-112, audit E6).
 *
 * - Refund sampai `config('tagihan.BatasRefundTanpaPersetujuan')` langsung disetujui dan dilaksanakan; di atasnya
 *   ditahan `Diajukan` sampai Super Admin memutuskan (`PutuskanRefundLangganan`). Super Admin yang mengajukan tidak
 *   terikat persetujuan kedua (D-34).
 * - Hanya refund penuh (sebesar total tagihan); refund sebagian belum didukung. Tagihan Rp 0 (kupon 100%) tidak
 *   bisa di-refund karena tidak ada uang yang masuk.
 * - Satu pengajuan `Diajukan` per tagihan. Pengembalian uangnya dilakukan Keuangan di luar sistem; referensinya
 *   dicatat di `CatatanPengembalian`.
 */
final class AjukanRefundLangganan
{
    public function __construct(
        private readonly PelaksanaRefundLangganan $pelaksana,
        private readonly PemberitahuRefundLangganan $pemberitahu,
        private readonly PencatatAuditPengelola $audit,
    ) {}

    public function Jalankan(PenggunaPengelola $pelaku, string $uuidTagihan, string $alasan, ?string $catatanPengembalian = null): RefundLangganan
    {
        $awal = DaftarTagihanPlatform::KueriTagihan()->where('Uuid', $uuidTagihan)->first()
            ?? throw new PelanggaranAturanBisnis('TagihanTidakDitemukan', 'Tagihan tidak ditemukan.');

        [$refund, $tagihan] = DB::transaction(function () use ($pelaku, $awal, $alasan, $catatanPengembalian): array {
            $langganan = Langganan::query()->where('IdTenant', $awal->IdTenant)->lockForUpdate()->first()
                ?? throw new PelanggaranAturanBisnis('LanggananTidakAda', 'Langganan tenant ini tidak ditemukan.');
            $tagihan = DaftarTagihanPlatform::KueriTagihan()->with('Paket')->whereKey($awal->Id)->lockForUpdate()->firstOrFail();

            if ($tagihan->Status !== StatusTagihanLangganan::Lunas) {
                throw new PelanggaranAturanBisnis('HanyaTagihanLunas', "Hanya tagihan lunas yang bisa di-refund. Tagihan {$tagihan->Nomor} berstatus {$tagihan->Status->AmbilLabel()}.");
            }

            $jumlah = $tagihan->AmbilTotal();

            if ($jumlah->BernilaiNol()) {
                throw new PelanggaranAturanBisnis('TagihanTanpaUang', 'Tagihan ini bernilai Rp 0 (kupon 100%), tidak ada uang yang bisa dikembalikan.');
            }

            $menunggu = DaftarTagihanPlatform::KueriRefund()
                ->where('IdTagihanLangganan', $tagihan->Id)
                ->where('Status', StatusRefundLangganan::Diajukan->value)
                ->exists();

            if ($menunggu) {
                throw new PelanggaranAturanBisnis('RefundMasihDiajukan', 'Refund tagihan ini sedang menunggu persetujuan.');
            }

            $refund = RefundLangganan::query()->create([
                'IdTenant' => $tagihan->IdTenant,
                'IdTagihanLangganan' => $tagihan->Id,
                'Status' => StatusRefundLangganan::Diajukan,
                'Jumlah' => $jumlah->KeString(),
                'Alasan' => trim($alasan),
                'CatatanPengembalian' => $catatanPengembalian === null || trim($catatanPengembalian) === '' ? null : trim($catatanPengembalian),
                'IdPenggunaPengelolaPengaju' => $pelaku->Id,
            ]);
            $langsung = $pelaku->PunyaIzin(IzinPengelola::TagihanRefundSetujui)
                || $jumlah->Bandingkan(Uang::Dari((string) config('tagihan.BatasRefundTanpaPersetujuan'))) <= 0;

            if ($langsung) {
                $diakhiri = $this->pelaksana->Laksanakan($refund, $tagihan, $langganan, $pelaku, CarbonImmutable::now());
                $this->audit->Catat(
                    'tagihan.refund.setuju',
                    $refund,
                    nilaiLama: ['Tagihan' => StatusTagihanLangganan::Lunas->value],
                    nilaiBaru: ['Tagihan' => StatusTagihanLangganan::Dikembalikan->value, 'Nomor' => $tagihan->Nomor, 'Jumlah' => $jumlah->KeString(), 'LayananDiakhiri' => $diakhiri],
                    alasan: trim($alasan),
                    idPelaku: $pelaku->Id,
                    idTenant: $tagihan->IdTenant,
                );

                return [$refund, $tagihan];
            }

            $this->audit->Catat(
                'tagihan.refund.ajukan',
                $refund,
                nilaiBaru: ['Nomor' => $tagihan->Nomor, 'Jumlah' => $jumlah->KeString()],
                alasan: trim($alasan),
                idPelaku: $pelaku->Id,
                idTenant: $tagihan->IdTenant,
            );

            return [$refund, $tagihan];
        });

        if ($refund->Status === StatusRefundLangganan::Disetujui) {
            $this->pemberitahu->Kirim($tagihan, $refund);
        }

        return $refund;
    }
}
