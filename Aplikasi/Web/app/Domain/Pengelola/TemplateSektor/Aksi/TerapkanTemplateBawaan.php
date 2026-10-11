<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\TemplateSektor\Aksi;

use App\Domain\Akuntansi\Enum\PeranAkun;
use App\Domain\PanduanAwal\Enum\StatusTemplateSektor;
use App\Domain\PanduanAwal\Model\TemplateSektor;
use App\Domain\PanduanAwal\Model\TemplateSektorVersi;
use App\Domain\Pengelola\Referensi\Aksi\SiapkanSatuanStandarBawaan;
use App\Domain\Pengelola\TemplateSektor\Layanan\ValidatorTemplate;
use App\Domain\Pengelola\TimInternal\Layanan\PencatatAuditPengelola;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Menerapkan template sektor bawaan rilis (`database/Data/TemplateSektorAwal.json`) ke server berjalan, langsung TERBIT
 * tanpa melewati draf (D-88), dengan satu perintah (`template:terapkan`). Aman diulang:
 *
 * - template baru: dibuat dan versi 1 langsung terbit bila lolos validasi (BR-P03.3);
 * - template lama yang isinya sudah sama dengan rilis: tidak diubah;
 * - template lama yang isi rilisnya berubah: versi baru langsung terbit dan versi sebelumnya menjadi Usang. Tenant yang
 *   sudah memakai versi lama tidak berubah tanpa persetujuannya (BR-P03.1);
 * - template yang sedang punya draf di konsol dilewati (BR-P03.4): pekerjaan penyusun di konsol tidak ditimpa;
 * - template bawaan yang masih draf v1 dan belum pernah terbit (seeder lama) diisi isi rilis lalu diterbitkan;
 * - template yang gagal validasi tidak terbit; hasilnya disimpan sebagai draf supaya penyebabnya terlihat di konsol.
 *
 * Satuan standar bawaan disiapkan lebih dulu karena validasi memeriksa satuan (idempoten).
 */
final class TerapkanTemplateBawaan
{
    private const ALASAN = 'Penerapan template bawaan rilis (template:terapkan)';

    public function __construct(
        private readonly PencatatAuditPengelola $audit,
        private readonly ValidatorTemplate $validator,
        private readonly SiapkanSatuanStandarBawaan $satuan,
    ) {}

    /**
     * @return list<array{Kode: string, Tindakan: string, Versi: int|null, Catatan: list<string>}>
     */
    public function Jalankan(bool $terapkan = true, ?string $path = null): array
    {
        $data = $this->BacaFile($path ?? database_path('Data/TemplateSektorAwal.json'));

        DB::beginTransaction();

        try {
            $this->satuan->Jalankan();
            $hasil = [];

            foreach ($data as $baris) {
                $hasil[] = $this->Proses($baris);
            }

            $terapkan ? DB::commit() : DB::rollBack();
        } catch (Throwable $galat) {
            DB::rollBack();

            throw $galat;
        }

        return $hasil;
    }

    /**
     * @return list<array{Kode: string, Nama: string, Keterangan: string|null, Isi: array<string, mixed>}>
     */
    private function BacaFile(string $path): array
    {
        $isiFile = is_readable($path) ? file_get_contents($path) : false;
        $data = $isiFile === false ? null : json_decode($isiFile, true);

        if (! is_array($data) || ! is_array($data['Template'] ?? null)) {
            throw new RuntimeException("File data template sektor tidak valid: {$path}");
        }

        $hasil = [];

        foreach ($data['Template'] as $baris) {
            if (! is_array($baris) || ! is_string($baris['Kode'] ?? null) || ! is_string($baris['Nama'] ?? null)
                || ! is_array($baris['Isi'] ?? null)) {
                throw new RuntimeException('Setiap template wajib punya Kode, Nama, dan Isi.');
            }

            $hasil[] = [
                'Kode' => $baris['Kode'],
                'Nama' => $baris['Nama'],
                'Keterangan' => is_string($baris['Keterangan'] ?? null) ? $baris['Keterangan'] : null,
                'Isi' => $this->NormalisasiIsi($baris['Isi']),
            ];
        }

        return $hasil;
    }

    /**
     * @param  array{Kode: string, Nama: string, Keterangan: string|null, Isi: array<string, mixed>}  $baris
     * @return array{Kode: string, Tindakan: string, Versi: int|null, Catatan: list<string>}
     */
    private function Proses(array $baris): array
    {
        $kode = $baris['Kode'];
        $isi = $baris['Isi'];
        $template = TemplateSektor::query()->where('Kode', $kode)->lockForUpdate()->first();

        if ($template === null) {
            $template = TemplateSektor::query()->create(['Kode' => $kode, 'Nama' => $baris['Nama'], 'Keterangan' => $baris['Keterangan']]);

            return $this->TerbitkanVersiBaru($template, $isi, null, 'Template baru terbit');
        }

        $terbit = $template->Versi()->where('Status', StatusTemplateSektor::Terbit->value)->first();
        $draf = $template->Versi()->where('Status', StatusTemplateSektor::Draf->value)->first();

        if ($terbit !== null) {
            if ($this->SamaIsi($terbit->Isi, $isi)) {
                return $this->Laporan($kode, 'Tanpa perubahan', $terbit->Versi);
            }

            if ($draf !== null) {
                return $this->Laporan($kode, 'Dilewati', null, ["Punya draf versi {$draf->Versi} di konsol (BR-P03.4). Terbitkan atau hapus draf itu, lalu jalankan lagi."]);
            }

            return $this->TerbitkanVersiBaru($template, $isi, $terbit, 'Isi rilis berubah, versi baru terbit');
        }

        if ($draf !== null) {
            return $this->TerbitkanDraf($template, $draf, $isi);
        }

        return $this->TerbitkanVersiBaru($template, $isi, null, 'Versi baru terbit');
    }

    /**
     * Membuat versi baru langsung Terbit (versi terbit sebelumnya menjadi Usang). Gagal validasi: disimpan sebagai
     * draf berisi hasil validasi dan tidak ada yang berubah pada versi terbit.
     *
     * @param  array<string, mixed>  $isi
     * @return array{Kode: string, Tindakan: string, Versi: int|null, Catatan: list<string>}
     */
    private function TerbitkanVersiBaru(TemplateSektor $template, array $isi, ?TemplateSektorVersi $lama, string $tindakan): array
    {
        $validasi = $this->validator->Validasi($isi);
        $nomor = (int) $template->Versi()->max('Versi') + 1;

        if (! $validasi['Lolos']) {
            $template->Versi()->create([
                'Versi' => $nomor,
                'Status' => StatusTemplateSektor::Draf,
                'Isi' => $isi,
                'IdVersiAsal' => $lama?->Id,
                'HasilValidasi' => $validasi,
                'DivalidasiPada' => now(),
            ]);

            return $this->Gagal($template->Kode, $nomor, $validasi['Galat']);
        }

        $lama?->update(['Status' => StatusTemplateSektor::Usang, 'DiusangkanPada' => now()]);

        $versi = $template->Versi()->create([
            'Versi' => $nomor,
            'Status' => StatusTemplateSektor::Terbit,
            'Isi' => $isi,
            'IdVersiAsal' => $lama?->Id,
            'HasilValidasi' => $validasi,
            'DivalidasiPada' => now(),
            'DiterbitkanPada' => now(),
        ]);

        $this->CatatAudit($versi, $template->Kode, $lama);

        return $this->Laporan($template->Kode, $tindakan, $nomor);
    }

    /**
     * Draf bawaan yang belum pernah terbit: isinya disamakan dengan rilis lalu diterbitkan.
     *
     * @param  array<string, mixed>  $isi
     * @return array{Kode: string, Tindakan: string, Versi: int|null, Catatan: list<string>}
     */
    private function TerbitkanDraf(TemplateSektor $template, TemplateSektorVersi $draf, array $isi): array
    {
        $validasi = $this->validator->Validasi($isi);

        if (! $validasi['Lolos']) {
            $draf->update(['Isi' => $isi, 'HasilValidasi' => $validasi, 'DivalidasiPada' => now()]);

            return $this->Gagal($template->Kode, $draf->Versi, $validasi['Galat']);
        }

        $draf->update([
            'Isi' => $isi,
            'Status' => StatusTemplateSektor::Terbit,
            'HasilValidasi' => $validasi,
            'DivalidasiPada' => now(),
            'DiterbitkanPada' => now(),
        ]);

        $this->CatatAudit($draf, $template->Kode, null);

        return $this->Laporan($template->Kode, 'Draf bawaan terbit', $draf->Versi);
    }

    private function CatatAudit(TemplateSektorVersi $versi, string $kode, ?TemplateSektorVersi $lama): void
    {
        $this->audit->Catat(
            'template.terbitkan-rilis',
            $versi,
            nilaiLama: $lama === null ? null : ['VersiTerbit' => $lama->Versi],
            nilaiBaru: ['Kode' => $kode, 'VersiTerbit' => $versi->Versi],
            alasan: self::ALASAN,
        );
    }

    /**
     * @param  list<array{Bagian: string, Pesan: string}>  $galat
     * @return array{Kode: string, Tindakan: string, Versi: int|null, Catatan: list<string>}
     */
    private function Gagal(string $kode, int $versi, array $galat): array
    {
        return $this->Laporan($kode, 'Gagal validasi', $versi, array_map(
            static fn (array $baris): string => "{$baris['Bagian']}: {$baris['Pesan']}",
            $galat,
        ));
    }

    /**
     * @param  list<string>  $catatan
     * @return array{Kode: string, Tindakan: string, Versi: int|null, Catatan: list<string>}
     */
    private function Laporan(string $kode, string $tindakan, ?int $versi, array $catatan = []): array
    {
        return ['Kode' => $kode, 'Tindakan' => $tindakan, 'Versi' => $versi, 'Catatan' => $catatan];
    }

    /**
     * Isi disamakan dengan cara duplikasi versi menormalkannya: kunci peran akun terbaru.
     *
     * @param  array<mixed>  $isi
     * @return array<string, mixed>
     */
    private function NormalisasiIsi(array $isi): array
    {
        if (is_array($isi['PemetaanAkun'] ?? null)) {
            $isi['PemetaanAkun'] = PeranAkun::NormalisasiPemetaan($isi['PemetaanAkun']);
        }

        /** @var array<string, mixed> $isi */
        return $isi;
    }

    /**
     * @param  array<mixed>  $lama
     * @param  array<mixed>  $baru
     */
    private function SamaIsi(array $lama, array $baru): bool
    {
        return $this->Kanonik($this->NormalisasiIsi($lama)) === $this->Kanonik($baru);
    }

    /**
     * @param  array<mixed>  $nilai
     */
    private function Kanonik(array $nilai): string
    {
        return (string) json_encode($this->UrutkanKunci($nilai), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param  array<mixed>  $nilai
     * @return array<mixed>
     */
    private function UrutkanKunci(array $nilai): array
    {
        foreach ($nilai as $kunci => $isi) {
            if (is_array($isi)) {
                $nilai[$kunci] = $this->UrutkanKunci($isi);
            }
        }

        if (! array_is_list($nilai)) {
            ksort($nilai);
        }

        return $nilai;
    }
}
