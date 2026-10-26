<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Komunikasi\Aksi;

use App\Domain\Integrasi\Whatsapp\PembuatPengirimWhatsapp;
use App\Domain\Integrasi\Whatsapp\PengirimWhatsapp;
use App\Domain\Integrasi\Whatsapp\PesanWhatsapp;
use App\Domain\Pengelola\Komunikasi\Enum\KanalSiaran;
use App\Domain\Pengelola\Komunikasi\Enum\StatusPenerimaSiaran;
use App\Domain\Pengelola\Komunikasi\Enum\StatusSiaran;
use App\Domain\Pengelola\Komunikasi\Kueri\SegmenSiaran;
use App\Domain\Pengelola\Komunikasi\Model\PenerimaSiaran;
use App\Domain\Pengelola\Komunikasi\Model\PesanSiaran;
use App\Domain\Pengelola\Komunikasi\Surel\SiaranPlatform;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Pengirim siaran (D-210), dipanggil `komunikasi:kirim-siaran` tiap lima menit. Siaran yang jadwalnya tiba ditetapkan
 * penerimanya lalu dikirim bertahap (satu putaran paling banyak `BATCH_*` penerima per siaran) supaya penyedia tidak
 * kebanjiran dan satu siaran besar tidak menahan yang lain. Sebuah penerima diambil lewat status `Diproses` lebih dulu:
 * bila proses mati di tengah jalan pesannya tidak dikirim dua kali, dan baris itu ditutup sebagai gagal.
 *
 * WhatsApp hanya jam 08.00–21.00 WIB (jam tenang). Penyedia WhatsApp resmi (Meta) mewajibkan templat untuk pesan di luar
 * jendela 24 jam, sedangkan siaran berupa teks bebas, jadi penerimanya dilewati dengan alasan yang jelas.
 */
final class KirimSiaranJatuhTempo
{
    public const BATCH_WHATSAPP = 40;

    public const BATCH_EMAIL = 150;

    public const JAM_MULAI_WA = 8;

    public const JAM_SELESAI_WA = 21;

    private const MAKS_SIARAN_PER_PUTARAN = 3;

    private const BATAS_DIPROSES_MENIT = 30;

    public function __construct(
        private readonly SegmenSiaran $segmen,
        private readonly PembuatPengirimWhatsapp $whatsapp,
    ) {}

    /**
     * @return array{Siaran: int, Terkirim: int}
     */
    public function Jalankan(): array
    {
        $siaranDiproses = 0;
        $terkirim = 0;

        $daftar = PesanSiaran::query()
            ->where(fn ($k) => $k
                ->where(fn ($t) => $t->where('Status', StatusSiaran::Terjadwal->value)->where('JadwalKirimPada', '<=', Carbon::now()))
                ->orWhere('Status', StatusSiaran::Mengirim->value))
            // Di luar jam kirim, siaran WhatsApp tidak boleh memakai jatah putaran dan menahan siaran email.
            ->when(! self::DiJamKirimWhatsapp(), fn ($k) => $k->where('Kanal', '<>', KanalSiaran::WhatsApp->value))
            ->orderBy('JadwalKirimPada')
            ->limit(self::MAKS_SIARAN_PER_PUTARAN)
            ->get();

        foreach ($daftar as $siaran) {
            if ($siaran->Kanal === KanalSiaran::WhatsApp && ! self::DiJamKirimWhatsapp()) {
                continue;
            }

            $this->Mulai($siaran);
            $terkirim += $this->KirimBatch($siaran->refresh());
            $this->Selesaikan($siaran);
            $siaranDiproses++;
        }

        return ['Siaran' => $siaranDiproses, 'Terkirim' => $terkirim];
    }

    public static function DiJamKirimWhatsapp(): bool
    {
        $jam = (int) Carbon::now('Asia/Jakarta')->format('G');

        return $jam >= self::JAM_MULAI_WA && $jam < self::JAM_SELESAI_WA;
    }

    /** Tetapkan penerima sekali, saat siaran berpindah dari Terjadwal ke Mengirim. */
    private function Mulai(PesanSiaran $siaran): void
    {
        DB::transaction(function () use ($siaran): void {
            $terkini = PesanSiaran::query()->lockForUpdate()->findOrFail($siaran->Id);

            if ($terkini->Status !== StatusSiaran::Terjadwal) {
                return;
            }

            $jumlah = 0;
            $sekarang = Carbon::now();
            $baris = [];

            foreach ($this->segmen->AmbilPenerima($terkini->Segmen, $terkini->Kanal) as $penerima) {
                $baris[] = [
                    'IdPesanSiaran' => $terkini->Id,
                    'IdTenant' => $penerima['IdTenant'],
                    'Nama' => Str::limit($penerima['Nama'], 120, ''),
                    'Tujuan' => Str::limit($penerima['Tujuan'], 160, ''),
                    'Status' => StatusPenerimaSiaran::Menunggu->value,
                    'DibuatPada' => $sekarang,
                    'DiubahPada' => $sekarang,
                ];
                $jumlah++;

                if (count($baris) >= 500) {
                    DB::table('PenerimaSiaran')->insert($baris);
                    $baris = [];
                }
            }

            if ($baris !== []) {
                DB::table('PenerimaSiaran')->insert($baris);
            }

            $terkini->update(['Status' => StatusSiaran::Mengirim, 'MulaiKirimPada' => $sekarang, 'JumlahPenerima' => $jumlah]);
        });
    }

    private function KirimBatch(PesanSiaran $siaran): int
    {
        if ($siaran->Status !== StatusSiaran::Mengirim) {
            return 0;
        }

        // Baris yang tertinggal `Diproses` terlalu lama berarti proses sebelumnya mati: ditutup gagal, tidak dikirim ulang.
        PenerimaSiaran::query()
            ->where('IdPesanSiaran', $siaran->Id)
            ->where('Status', StatusPenerimaSiaran::Diproses->value)
            ->where('DiubahPada', '<', Carbon::now()->subMinutes(self::BATAS_DIPROSES_MENIT))
            ->update(['Status' => StatusPenerimaSiaran::Gagal->value, 'Alasan' => 'Proses pengiriman terputus.']);

        $batas = $siaran->Kanal === KanalSiaran::WhatsApp ? self::BATCH_WHATSAPP : self::BATCH_EMAIL;
        $ids = DB::transaction(function () use ($siaran, $batas): array {
            $ids = array_values(array_map('intval', PenerimaSiaran::query()
                ->where('IdPesanSiaran', $siaran->Id)
                ->where('Status', StatusPenerimaSiaran::Menunggu->value)
                ->orderBy('Id')
                ->limit($batas)
                ->lockForUpdate()
                ->pluck('Id')
                ->all()));

            if ($ids !== []) {
                PenerimaSiaran::query()->whereIn('Id', $ids)->update(['Status' => StatusPenerimaSiaran::Diproses->value]);
            }

            return $ids;
        });

        $terkirim = 0;
        $pengirim = $siaran->Kanal === KanalSiaran::WhatsApp ? $this->whatsapp->AmbilAktifPlatform() : null;

        foreach (PenerimaSiaran::query()->whereIn('Id', $ids)->orderBy('Id')->get() as $penerima) {
            [$status, $alasan] = $siaran->Kanal === KanalSiaran::Email
                ? $this->KirimEmail($siaran, $penerima)
                : $this->KirimWhatsapp($siaran, $penerima, $pengirim);

            $penerima->update(['Status' => $status, 'Alasan' => $alasan, 'DikirimPada' => $status === StatusPenerimaSiaran::Terkirim ? Carbon::now() : null]);
            $terkirim += $status === StatusPenerimaSiaran::Terkirim ? 1 : 0;
        }

        return $terkirim;
    }

    /**
     * @return array{0: StatusPenerimaSiaran, 1: string|null}
     */
    private function KirimEmail(PesanSiaran $siaran, PenerimaSiaran $penerima): array
    {
        try {
            Mail::to($penerima->Tujuan)->send(new SiaranPlatform($penerima->Nama, $siaran->Judul, $siaran->Isi));

            return [StatusPenerimaSiaran::Terkirim, null];
        } catch (Throwable $galat) {
            return [StatusPenerimaSiaran::Gagal, Str::limit($galat->getMessage(), 290, '')];
        }
    }

    /**
     * @return array{0: StatusPenerimaSiaran, 1: string|null}
     */
    private function KirimWhatsapp(PesanSiaran $siaran, PenerimaSiaran $penerima, ?PengirimWhatsapp $pengirim): array
    {
        if ($pengirim === null) {
            return [StatusPenerimaSiaran::Dilewati, 'WhatsApp platform belum dikonfigurasi di Integrasi.'];
        }

        if ($pengirim->CekResmi()) {
            return [StatusPenerimaSiaran::Dilewati, 'Penyedia WhatsApp resmi hanya mengirim templat yang disetujui Meta. Siaran teks bebas tidak bisa terkirim. Pakai email.'];
        }

        try {
            $hasil = $pengirim->Kirim(new PesanWhatsapp($penerima->Tujuan, "*{$siaran->Judul}*\n\nHalo {$penerima->Nama},\n\n{$siaran->Isi}"));

            return $hasil->berhasil ? [StatusPenerimaSiaran::Terkirim, null] : [StatusPenerimaSiaran::Gagal, Str::limit($hasil->pesan, 290, '')];
        } catch (Throwable $galat) {
            return [StatusPenerimaSiaran::Gagal, Str::limit($galat->getMessage(), 290, '')];
        }
    }

    /** Tutup siaran bila tidak ada lagi penerima yang menunggu atau diproses; hitungan selalu dibaca dari baris penerima. */
    private function Selesaikan(PesanSiaran $siaran): void
    {
        $siaran->refresh();

        if ($siaran->Status !== StatusSiaran::Mengirim) {
            return;
        }

        $hitungan = PenerimaSiaran::query()
            ->where('IdPesanSiaran', $siaran->Id)
            ->selectRaw('Status, COUNT(*) AS Jumlah')
            ->groupBy('Status')
            ->pluck('Jumlah', 'Status');
        $jumlah = static fn (StatusPenerimaSiaran $status): int => (int) ($hitungan[$status->value] ?? 0);

        $attrs = [
            'JumlahTerkirim' => $jumlah(StatusPenerimaSiaran::Terkirim),
            'JumlahGagal' => $jumlah(StatusPenerimaSiaran::Gagal),
            'JumlahDilewati' => $jumlah(StatusPenerimaSiaran::Dilewati),
        ];

        if ($jumlah(StatusPenerimaSiaran::Menunggu) + $jumlah(StatusPenerimaSiaran::Diproses) === 0) {
            $attrs += ['Status' => StatusSiaran::Selesai, 'SelesaiPada' => Carbon::now()];
        }

        $siaran->update($attrs);
    }
}
