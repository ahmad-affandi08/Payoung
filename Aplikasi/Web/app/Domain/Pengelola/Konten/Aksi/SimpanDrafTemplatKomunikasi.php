<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Konten\Aksi;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Pengelola\TimInternal\Layanan\PencatatAuditPengelola;
use App\Domain\Pengelola\TimInternal\Model\PenggunaPengelola;
use App\Domain\Tenant\Enum\BahasaKomunikasi;
use App\Domain\Tenant\Enum\KanalKomunikasi;
use App\Domain\Tenant\Enum\PeristiwaKomunikasi;
use App\Domain\Tenant\Enum\StatusTemplateKomunikasi;
use App\Domain\Tenant\Layanan\PenyusunPesanKomunikasi;
use App\Domain\Tenant\Model\TemplateKomunikasi;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * PGL-07 / P-06: membuat atau mengubah draf templat komunikasi untuk satu peristiwa + kanal + bahasa. Satu draf per
 * kombinasi (BR-P06.4); versi terbit tidak pernah diubah (BR-P06.1) sehingga perubahan selalu menghasilkan versi baru.
 *
 * Validasi isi: email wajib `Subjek`, `Judul`, dan `Isi`, WhatsApp hanya `Isi`, notifikasi aplikasi `Judul` (satu baris,
 * maks. 65) dan `Isi` (maks. 200); subjek satu baris (tanpa pemisah baris yang
 * bisa menyisipkan header email); hanya variabel yang dikenal peristiwa itu; variabel wajib kanal itu (WhatsApp: `Tautan`)
 * harus ada; panjang dibatasi.
 */
final class SimpanDrafTemplatKomunikasi
{
    public const BATAS_SUBJEK = 150;

    public const BATAS_JUDUL = 150;

    public const BATAS_ISI_EMAIL = 3000;

    public const BATAS_ISI_WHATSAPP = 1500;

    /** Notifikasi aplikasi: judul push ringkas dan isi pendek (terpotong di layar kunci bila lebih panjang). */
    public const BATAS_JUDUL_NOTIFIKASI = 65;

    public const BATAS_ISI_NOTIFIKASI = 200;

    public function __construct(private readonly PencatatAuditPengelola $audit) {}

    public function Jalankan(PenggunaPengelola $pelaku, PeristiwaKomunikasi $peristiwa, KanalKomunikasi $kanal, BahasaKomunikasi $bahasa, ?string $subjek, ?string $judul, string $isi): TemplateKomunikasi
    {
        if (! in_array($kanal, $peristiwa->AmbilKanal(), true)) {
            throw new PelanggaranAturanBisnis('KanalTidakDidukung', "{$peristiwa->AmbilLabel()} tidak dikirim lewat {$kanal->AmbilLabel()}.", 'Kanal');
        }

        $email = $kanal === KanalKomunikasi::Email;
        $subjek = $email ? self::Rapikan($subjek) : null;
        $judul = $kanal === KanalKomunikasi::WhatsApp ? null : self::Rapikan($judul);
        $isi = trim(str_replace("\r\n", "\n", $isi));

        $this->Periksa($peristiwa, $kanal, $subjek, $judul, $isi);

        try {
            return DB::transaction(fn (): TemplateKomunikasi => $this->Simpan($pelaku, $peristiwa, $kanal, $bahasa, $subjek, $judul, $isi));
        } catch (UniqueConstraintViolationException) {
            throw new PelanggaranAturanBisnis('BR-P06.4', 'Draf templat ini baru saja dibuat orang lain. Muat ulang halaman lalu ubah draf itu.');
        }
    }

    private function Simpan(PenggunaPengelola $pelaku, PeristiwaKomunikasi $peristiwa, KanalKomunikasi $kanal, BahasaKomunikasi $bahasa, ?string $subjek, ?string $judul, string $isi): TemplateKomunikasi
    {
        $sekombinasi = TemplateKomunikasi::query()
            ->where('KodePeristiwa', $peristiwa->value)
            ->where('Kanal', $kanal->value)
            ->where('Bahasa', $bahasa->value)
            ->lockForUpdate()
            ->get();
        $draf = $sekombinasi->first(fn (TemplateKomunikasi $t): bool => $t->Status === StatusTemplateKomunikasi::Draf);
        $lama = $draf?->only(['Subjek', 'Judul', 'Isi']);

        if ($draf === null) {
            $draf = new TemplateKomunikasi([
                'KodePeristiwa' => $peristiwa->value,
                'Kanal' => $kanal,
                'Bahasa' => $bahasa,
                'Versi' => (int) $sekombinasi->max('Versi') + 1,
                'Status' => StatusTemplateKomunikasi::Draf,
            ]);
        }

        $draf->fill(['Subjek' => $subjek, 'Judul' => $judul, 'Isi' => $isi, 'IdPenggunaPengelolaPenyusun' => $pelaku->Id]);

        if (! $draf->exists || $draf->isDirty(['Subjek', 'Judul', 'Isi'])) {
            $baru = ! $draf->exists;
            $draf->save();
            $this->audit->Catat($baru ? 'komunikasi.templat.buat' : 'komunikasi.templat.ubah', $draf, nilaiLama: $lama, nilaiBaru: [
                'KodePeristiwa' => $peristiwa->value,
                'Kanal' => $kanal->value,
                'Bahasa' => $bahasa->value,
                'Versi' => $draf->Versi,
            ]);
        }

        return $draf;
    }

    private function Periksa(PeristiwaKomunikasi $peristiwa, KanalKomunikasi $kanal, ?string $subjek, ?string $judul, string $isi): void
    {
        if ($kanal === KanalKomunikasi::Email) {
            if ($subjek === null) {
                throw new PelanggaranAturanBisnis('SubjekWajib', 'Isi subjek email.', 'Subjek');
            }

            if ($judul === null) {
                throw new PelanggaranAturanBisnis('JudulWajib', 'Isi judul email.', 'Judul');
            }

            if (str_contains($subjek, "\n") || str_contains($subjek, "\r")) {
                throw new PelanggaranAturanBisnis('SubjekSatuBaris', 'Subjek email harus satu baris.', 'Subjek');
            }

            if (mb_strlen($subjek) > self::BATAS_SUBJEK || mb_strlen($judul) > self::BATAS_JUDUL) {
                throw new PelanggaranAturanBisnis('TeksTerlaluPanjang', 'Subjek dan judul paling panjang '.self::BATAS_SUBJEK.' karakter.', mb_strlen($subjek) > self::BATAS_SUBJEK ? 'Subjek' : 'Judul');
            }
        }

        if ($kanal === KanalKomunikasi::Notifikasi) {
            if ($judul === null) {
                throw new PelanggaranAturanBisnis('JudulWajib', 'Isi judul notifikasi.', 'Judul');
            }

            if (str_contains($judul, "\n") || str_contains($judul, "\r") || mb_strlen($judul) > self::BATAS_JUDUL_NOTIFIKASI) {
                throw new PelanggaranAturanBisnis('TeksTerlaluPanjang', 'Judul notifikasi satu baris dan paling panjang '.self::BATAS_JUDUL_NOTIFIKASI.' karakter.', 'Judul');
            }
        }

        if ($isi === '') {
            throw new PelanggaranAturanBisnis('IsiWajib', 'Isi pesan tidak boleh kosong.', 'Isi');
        }

        $batas = match ($kanal) {
            KanalKomunikasi::Email => self::BATAS_ISI_EMAIL,
            KanalKomunikasi::WhatsApp => self::BATAS_ISI_WHATSAPP,
            KanalKomunikasi::Notifikasi => self::BATAS_ISI_NOTIFIKASI,
        };

        if (mb_strlen($isi) > $batas) {
            throw new PelanggaranAturanBisnis('TeksTerlaluPanjang', "Isi paling panjang {$batas} karakter untuk {$kanal->AmbilLabel()}.", 'Isi');
        }

        $dikenal = array_column($peristiwa->AmbilVariabel(), 'Nama');

        foreach (['Subjek' => $subjek, 'Judul' => $judul, 'Isi' => $isi] as $bidang => $teks) {
            $asing = array_values(array_diff(PenyusunPesanKomunikasi::AmbilVariabelDipakai((string) $teks), $dikenal));

            if ($asing !== []) {
                throw new PelanggaranAturanBisnis('VariabelTidakDikenal', 'Variabel tidak dikenal: {'.implode('}, {', $asing).'}. Pakai variabel dari daftar di samping.', $bidang);
            }
        }

        foreach ($peristiwa->AmbilVariabelWajib($kanal) as $wajib) {
            if (! in_array($wajib, PenyusunPesanKomunikasi::AmbilVariabelDipakai($isi), true)) {
                throw new PelanggaranAturanBisnis('VariabelWajib', "Isi pesan {$kanal->AmbilLabel()} wajib memuat {{$wajib}}.", 'Isi');
            }
        }
    }

    private static function Rapikan(?string $teks): ?string
    {
        $teks = $teks === null ? null : trim($teks);

        return $teks === '' ? null : $teks;
    }
}
