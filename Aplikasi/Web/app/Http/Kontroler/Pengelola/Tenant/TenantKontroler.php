<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Pengelola\Tenant;

use App\Domain\Bersama\Tabel\Data\DataPermintaanTabel;
use App\Domain\Pengelola\Katalog\Kueri\DaftarKatalog;
use App\Domain\Pengelola\Tenant\Aksi\BuatOverrideTenant;
use App\Domain\Pengelola\Tenant\Aksi\PerpanjangTrial;
use App\Domain\Pengelola\Tenant\Enum\KategoriKesehatan;
use App\Domain\Pengelola\Tenant\Enum\KategoriPenangguhan;
use App\Domain\Pengelola\Tenant\Kueri\DaftarTenant;
use App\Domain\Pengelola\Tenant\Kueri\TampilanTenant;
use App\Domain\Pengelola\TimInternal\Enum\IzinPengelola;
use App\Domain\Pengelola\TimInternal\Layanan\PencatatAuditPengelola;
use App\Domain\Tenant\Enum\PenandaTenant;
use App\Domain\Tenant\Enum\StatusLangganan;
use App\Domain\Tenant\Model\Paket;
use App\Domain\Tenant\Model\Tenant;
use App\Http\Kontroler\Kontroler;
use App\Http\Kontroler\Pengelola\PelakuPengelola;
use App\Http\Respons\ResponsTabel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Daftar tenant & tampilan 360° dasar (P-07). Tindakan pada tenant ada di TindakanTenantKontroler.
 */
final class TenantKontroler extends Kontroler
{
    use PelakuPengelola;

    private const BATAS_EKSPOR = 5000;

    public function Daftar(Request $permintaan, DaftarTenant $kueri): Response|JsonResponse
    {
        $tabel = DataPermintaanTabel::Dari($permintaan->query(), DaftarTenant::KOLOM_URUT, '-DibuatPada', DaftarTenant::KOLOM_SARING);

        return ResponsTabel::Kirim($permintaan, 'Pengelola/Tenant/Daftar', 'Tenant', fn (): array => $kueri->AmbilTabel($tabel), fn (): array => [
            'PilihanStatus' => array_map(fn (StatusLangganan $item) => ['Nilai' => $item->value, 'Label' => $item->AmbilLabel()], StatusLangganan::cases()),
            'PilihanKesehatan' => array_map(fn (KategoriKesehatan $item) => ['Nilai' => $item->value, 'Label' => $item->AmbilLabel()], KategoriKesehatan::cases()),
            'PilihanKategoriPenangguhan' => array_map(fn (KategoriPenangguhan $item) => ['Nilai' => $item->value, 'Label' => $item->AmbilLabel()], KategoriPenangguhan::cases()),
            'PilihanPaket' => Paket::query()->orderBy('Urutan')->orderBy('Id')->get(['Kode', 'Nama'])->map(fn (Paket $paket): array => ['Nilai' => $paket->Kode, 'Label' => $paket->Nama])->values()->all(),
            'PilihanPenanda' => [
                ['Nilai' => DaftarTenant::SARING_TANPA_PENANDA, 'Label' => 'Tanpa penanda'],
                ...array_map(fn (PenandaTenant $item) => ['Nilai' => $item->value, 'Label' => $item->AmbilLabel()], PenandaTenant::cases()),
            ],
        ]);
    }

    /**
     * Ekspor CSV daftar tenant sesuai cari & saringan di tabel (D-210). Memuat identitas dan langganan, tanpa data
     * usaha. Dibatasi `BATAS_EKSPOR` baris; setiap ekspor tercatat di log audit.
     */
    public function Ekspor(Request $permintaan, DaftarTenant $kueri, PencatatAuditPengelola $audit): StreamedResponse
    {
        $query = $permintaan->query();
        $audit->Catat('tenant.daftar.ekspor', nilaiBaru: ['Saringan' => $query], idPelaku: $this->AmbilPelaku()->Id);

        return response()->streamDownload(function () use ($query, $kueri): void {
            $keluaran = fopen('php://output', 'w');

            if ($keluaran === false) {
                return;
            }

            fwrite($keluaran, "\xEF\xBB\xBF");
            fputcsv($keluaran, ['Tenant', 'Slug', 'Email Owner', 'Paket', 'Status langganan', 'Trial berakhir', 'Kesehatan', 'Skor kesehatan', 'Penanda', 'Terdaftar'], ',', '"', '');
            $tertulis = 0;

            for ($halaman = 1; $tertulis < self::BATAS_EKSPOR; $halaman++) {
                $tabel = DataPermintaanTabel::Dari([...$query, 'halaman' => $halaman, 'perHalaman' => 100], DaftarTenant::KOLOM_URUT, '-DibuatPada', DaftarTenant::KOLOM_SARING);
                $hasil = $kueri->AmbilTabel($tabel);

                foreach ($hasil['Data'] as $baris) {
                    fputcsv($keluaran, array_map(self::AmankanSel(...), [
                        $baris['Nama'], $baris['Slug'], $baris['EmailPemilik'] ?? '', $baris['KodePaket'] ?? '', $baris['StatusLangganan'] ?? '',
                        $baris['TrialBerakhirPada'] ?? '', $baris['Kesehatan']['LabelKategori'] ?? '', $baris['Kesehatan']['Skor'] ?? '', $baris['Penanda'] ?? '', $baris['DibuatPada'],
                    ]), ',', '"', '');
                    $tertulis++;
                }

                if ($halaman >= $hasil['Meta']['JumlahHalaman']) {
                    break;
                }
            }

            fclose($keluaran);
        }, 'tenant-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Sel yang diawali rumus ( = + - @ ) diberi apostrof agar tidak dijalankan Excel (CSV injection). */
    private static function AmankanSel(mixed $nilai): string
    {
        $teks = (string) $nilai;

        return $teks !== '' && str_contains('=+-@', $teks[0]) ? "'".$teks : $teks;
    }

    public function Tampilkan(Tenant $tenant, TampilanTenant $kueri, DaftarKatalog $katalog): Response
    {
        $pelaku = $this->AmbilPelaku();

        return Inertia::render('Pengelola/Tenant/Tampil', [
            // Tab tagihan & tiket hanya untuk peran yang boleh melihatnya (§19.3); null = tab disembunyikan.
            'Tenant' => $kueri->Ambil($tenant, $pelaku->PunyaIzin(IzinPengelola::DukunganTiketLihat), $pelaku->PunyaIzin(IzinPengelola::TagihanLihat)),
            'Pilihan' => [
                'KategoriPenangguhan' => array_map(
                    fn (KategoriPenangguhan $item) => ['Nilai' => $item->value, 'Label' => $item->AmbilLabel()],
                    KategoriPenangguhan::cases(),
                ),
                'Penanda' => array_map(fn (PenandaTenant $item) => ['Nilai' => $item->value, 'Label' => $item->AmbilLabel()], PenandaTenant::cases()),
                'KolomBatas' => Paket::KOLOM_BATAS,
                'Fitur' => array_map(fn (array $fitur) => ['Nilai' => $fitur['Kunci'], 'Label' => "{$fitur['Nama']} ({$fitur['Kunci']})"], $katalog->AmbilFitur()),
                // D-112: paket harga negosiasi yang boleh ditagih manual oleh Keuangan.
                'PaketNegosiasi' => array_map(
                    fn (Paket $paket) => ['Nilai' => $paket->Kode, 'Label' => $paket->Nama],
                    array_values(Paket::query()->where('HargaNegosiasi', true)->orderBy('Urutan')->get()->all()),
                ),
            ],
            'Aturan' => [
                'MaksHariTrial' => PerpanjangTrial::MAKS_HARI,
                'MaksKaliTrial' => PerpanjangTrial::MAKS_KALI,
                'MaksHariOverride' => BuatOverrideTenant::MAKS_HARI,
            ],
        ]);
    }
}
