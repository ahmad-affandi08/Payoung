<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Komunikasi\Aksi;

use App\Domain\Pengelola\Komunikasi\Enum\StatusKampanyeProspek;
use App\Domain\Pengelola\Komunikasi\Enum\StatusKontakProspek;
use App\Domain\Pengelola\Komunikasi\Enum\StatusPenerimaSiaran;
use App\Domain\Pengelola\Komunikasi\Layanan\PenghitungKampanyeProspek;
use App\Domain\Pengelola\Komunikasi\Layanan\PengirimKampanyeProspek;
use App\Domain\Pengelola\Komunikasi\Layanan\PenyusunIsiKampanyeProspek;
use App\Domain\Pengelola\Komunikasi\Layanan\TautanBerhentiProspek;
use App\Domain\Pengelola\Komunikasi\Model\KampanyeProspek;
use App\Domain\Pengelola\Komunikasi\Model\PenerimaKampanyeProspek;
use App\Domain\Pengelola\Komunikasi\Surel\PesanKampanyeProspek;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Pengirim kampanye prospek (D-216), dipanggil `prospek:kirim-kampanye` tiap lima menit. Sengaja pelan: paling banyak
 * `BATCH_PER_PUTARAN` email per kampanye per putaran, tidak lebih dari `BatasPerHari` kampanye itu per hari, dan hanya
 * pada jam kerja 08.00–17.00 WIB, supaya penyedia dan kotak masuk penerima tidak kebanjiran dan reputasi pengirim
 * terjaga. Kontak yang berhenti setelah kampanye dijalankan dilewati; alamat yang ditolak server tujuan ditandai
 * `Pentalan` dan tidak dikirimi lagi. Lima kegagalan beruntun menjeda kampanye sampai petugas memeriksanya.
 *
 * Penerima diambil lewat status `Diproses` lebih dulu: bila proses mati di tengah jalan, pesan tidak terkirim dua kali.
 */
final class KirimKampanyeProspekJatuhTempo
{
    public const BATCH_PER_PUTARAN = 5;

    public const JAM_MULAI = 8;

    public const JAM_SELESAI = 17;

    public const GAGAL_BERUNTUN_JEDA = 5;

    private const MAKS_KAMPANYE_PER_PUTARAN = 3;

    private const BATAS_DIPROSES_MENIT = 30;

    public function __construct(private readonly HentikanKontakProspek $hentikan) {}

    public static function DiJamKirim(): bool
    {
        $jam = (int) Carbon::now('Asia/Jakarta')->format('G');

        return $jam >= self::JAM_MULAI && $jam < self::JAM_SELESAI;
    }

    /**
     * @return array{Kampanye: int, Terkirim: int}
     */
    public function Jalankan(): array
    {
        if (! self::DiJamKirim()) {
            return ['Kampanye' => 0, 'Terkirim' => 0];
        }

        $diproses = 0;
        $terkirim = 0;

        $daftar = KampanyeProspek::query()
            ->where('Status', StatusKampanyeProspek::Berjalan->value)
            ->orderBy('Id')
            ->limit(self::MAKS_KAMPANYE_PER_PUTARAN)
            ->get();

        foreach ($daftar as $kampanye) {
            if (! PengirimKampanyeProspek::Tersedia()) {
                $this->Jeda($kampanye, 'Email kampanye tidak aktif: tidak ada pengirim email di Integrasi.');

                continue;
            }

            $terkirim += $this->KirimBatch($kampanye);
            $this->Selesaikan($kampanye->refresh());
            $diproses++;
        }

        return ['Kampanye' => $diproses, 'Terkirim' => $terkirim];
    }

    private function KirimBatch(KampanyeProspek $kampanye): int
    {
        // Baris yang tertinggal `Diproses` terlalu lama berarti proses sebelumnya mati: ditutup gagal, tidak dikirim ulang.
        PenerimaKampanyeProspek::query()
            ->where('IdKampanyeProspek', $kampanye->Id)
            ->where('Status', StatusPenerimaSiaran::Diproses->value)
            ->where('DiubahPada', '<', Carbon::now()->subMinutes(self::BATAS_DIPROSES_MENIT))
            ->update(['Status' => StatusPenerimaSiaran::Gagal->value, 'Alasan' => 'Proses pengiriman terputus.']);

        $awalHari = Carbon::now('Asia/Jakarta')->startOfDay()->setTimezone((string) config('app.timezone'));
        $sudahHariIni = PenerimaKampanyeProspek::query()
            ->where('IdKampanyeProspek', $kampanye->Id)
            ->where('Status', StatusPenerimaSiaran::Terkirim->value)
            ->where('DikirimPada', '>=', $awalHari)
            ->count();
        $jatah = min($kampanye->BatasPerHari - $sudahHariIni, self::BATCH_PER_PUTARAN);

        if ($jatah <= 0) {
            return 0;
        }

        $ids = DB::transaction(function () use ($kampanye, $jatah): array {
            $ids = array_values(array_map('intval', PenerimaKampanyeProspek::query()
                ->where('IdKampanyeProspek', $kampanye->Id)
                ->where('Status', StatusPenerimaSiaran::Menunggu->value)
                ->orderBy('Id')
                ->limit($jatah)
                ->lockForUpdate()
                ->pluck('Id')
                ->all()));

            if ($ids !== []) {
                PenerimaKampanyeProspek::query()->whereIn('Id', $ids)->update(['Status' => StatusPenerimaSiaran::Diproses->value]);
            }

            return $ids;
        });

        $terkirim = 0;

        foreach (PenerimaKampanyeProspek::query()->with('Kontak')->whereIn('Id', $ids)->orderBy('Id')->get() as $penerima) {
            $kontak = $penerima->Kontak;

            if ($kontak->Status !== StatusKontakProspek::Aktif) {
                $penerima->update(['Status' => StatusPenerimaSiaran::Dilewati, 'Alasan' => $kontak->Status === StatusKontakProspek::Berhenti ? 'Sudah berhenti berlangganan.' : 'Alamat tidak aktif.']);

                continue;
            }

            try {
                PengirimKampanyeProspek::Mailer()->to($kontak->Email)->send(new PesanKampanyeProspek(
                    PenyusunIsiKampanyeProspek::Isi($kampanye->Subjek, $kontak->Sektor),
                    PenyusunIsiKampanyeProspek::Isi($kampanye->Isi, $kontak->Sektor),
                    TautanBerhentiProspek::Buat($kontak),
                    $kontak->Sumber,
                    PengirimKampanyeProspek::AlamatPengirim(),
                    PengirimKampanyeProspek::NamaPengirim(),
                ));
                $penerima->update(['Status' => StatusPenerimaSiaran::Terkirim, 'Alasan' => null, 'DikirimPada' => Carbon::now()]);
                $terkirim++;
            } catch (Throwable $galat) {
                $pesan = $galat->getMessage();
                $penerima->update(['Status' => StatusPenerimaSiaran::Gagal, 'Alasan' => Str::limit($pesan, 290, '')]);

                // Penolakan permanen oleh server tujuan (alamat tidak ada): jangan dicoba lagi di kampanye berikutnya.
                if (preg_match('/\b550\b|5\.1\.1|user unknown|does not exist|no such user|mailbox unavailable/i', $pesan) === 1) {
                    $this->hentikan->Jalankan($kontak, StatusKontakProspek::Pentalan);
                }
            }
        }

        $this->JedaBilaGagalBeruntun($kampanye);

        return $terkirim;
    }

    /** Lima pengiriman terakhir semuanya gagal: kemungkinan masalah pengirim, bukan alamat penerima. */
    private function JedaBilaGagalBeruntun(KampanyeProspek $kampanye): void
    {
        $terakhir = PenerimaKampanyeProspek::query()
            ->where('IdKampanyeProspek', $kampanye->Id)
            ->whereIn('Status', [StatusPenerimaSiaran::Terkirim->value, StatusPenerimaSiaran::Gagal->value])
            ->orderByDesc('DiubahPada')
            ->orderByDesc('Id')
            ->limit(self::GAGAL_BERUNTUN_JEDA)
            ->get();

        if ($terakhir->count() === self::GAGAL_BERUNTUN_JEDA && $terakhir->every(fn (PenerimaKampanyeProspek $p): bool => $p->Status === StatusPenerimaSiaran::Gagal)) {
            $this->Jeda($kampanye, 'Lima email terakhir gagal berturut-turut. Periksa pengaturan Email kampanye, lalu lanjutkan.');
        }
    }

    private function Jeda(KampanyeProspek $kampanye, string $alasan): void
    {
        $kampanye->update(['Status' => StatusKampanyeProspek::Dijeda, 'AlasanJeda' => $alasan]);
    }

    /** Tutup kampanye bila tidak ada lagi penerima yang menunggu atau diproses. */
    private function Selesaikan(KampanyeProspek $kampanye): void
    {
        $hitung = PenghitungKampanyeProspek::Segarkan($kampanye);

        if ($kampanye->Status === StatusKampanyeProspek::Berjalan && $hitung['Menunggu'] + $hitung['Diproses'] === 0) {
            $kampanye->update(['Status' => StatusKampanyeProspek::Selesai, 'SelesaiPada' => Carbon::now()]);
        }
    }
}
