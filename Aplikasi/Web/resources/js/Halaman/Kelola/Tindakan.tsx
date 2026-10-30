import { Link, router, usePage } from '@inertiajs/react';
import { CircleCheckIcon } from 'lucide-react';
import { useState } from 'react';

import KotakCentang from '@/Komponen/Formulir/KotakCentang';
import Tombol from '@/Komponen/Formulir/Tombol';
import DaftarGalatServer from '@/Komponen/Katalog/DaftarGalatServer';
import { Card } from '@/Komponen/Ui/card';
import { Empty, EmptyDescription, EmptyHeader, EmptyMedia, EmptyTitle } from '@/Komponen/Ui/empty';
import { Switch } from '@/Komponen/Ui/switch';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import { FormatTanggal } from '@/Pustaka/FormatWaktu';
import { JenisLabelTingkat } from '@/Pustaka/TingkatTindakan';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';
import type { ButirTindakan, PropsKotakTindakan, RingkasanWhatsappTindakan } from '@/Tipe/Tindakan';

function KartuButir({ butir }: { butir: ButirTindakan }) {
    const [dipilih, AturDipilih] = useState<string[]>([]);
    const [memproses, AturMemproses] = useState(false);
    const [terbuka, AturTerbuka] = useState(butir.Tingkat === 'Penting');
    const tampil = butir.Rincian;
    const semuaDipilih = tampil.length > 0 && dipilih.length === tampil.length;

    const Tandai = (uuid: string[]) => {
        if (butir.JenisDokumen === null || uuid.length === 0) {
            return;
        }

        router.post(
            '/kelola/tindakan/tinjau',
            { Jenis: butir.JenisDokumen, Uuid: uuid },
            {
                preserveScroll: true,
                onStart: () => AturMemproses(true),
                onFinish: () => AturMemproses(false),
                onSuccess: () => AturDipilih([]),
            },
        );
    };

    const Alihkan = (uuid: string, pilih: boolean) =>
        AturDipilih(pilih ? [...dipilih, uuid] : dipilih.filter((u) => u !== uuid));

    return (
        <Card className="gap-3 rounded-panel p-4 shadow-none">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div className="flex min-w-0 flex-col gap-1">
                    <span className="text-label text-teks-sekunder">{butir.Modul}</span>
                    <h2 className="text-subjudul font-semibold break-words text-teks-utama">
                        {butir.Judul} <span className="tabular-nums">({butir.Jumlah.toLocaleString('id-ID')})</span>
                    </h2>
                    <p className="text-isi text-teks-sekunder">{butir.Keterangan}</p>
                </div>
                <LabelStatus jenis={JenisLabelTingkat[butir.Tingkat]} teks={butir.Tingkat} />
            </div>
            <div className="flex flex-wrap gap-2">
                <Link
                    href={butir.Tautan}
                    className="inline-flex h-8 items-center rounded-kontrol border border-garis-input px-4 text-label font-semibold text-teks-utama pointer-coarse:h-11 hover:bg-permukaan-sorot"
                >
                    {butir.LabelTautan}
                </Link>
                {tampil.length > 0 ? (
                    <Tombol varian="sekunder" onClick={() => AturTerbuka(!terbuka)} aria-expanded={terbuka}>
                        {terbuka ? 'Sembunyikan rincian' : `Lihat rincian (${String(tampil.length)})`}
                    </Tombol>
                ) : null}
            </div>
            {terbuka && tampil.length > 0 ? (
                <div className="flex flex-col gap-2">
                    {butir.BolehTandai ? (
                        <div className="flex flex-wrap items-center justify-between gap-2 border-b border-garis pb-2">
                            <KotakCentang
                                label="Pilih semua yang tampil"
                                nilai={semuaDipilih}
                                saatBerubah={(pilih) => AturDipilih(pilih ? tampil.map((r) => r.Uuid) : [])}
                            />
                            <Tombol
                                memproses={memproses}
                                disabled={dipilih.length === 0}
                                onClick={() => Tandai(dipilih)}
                            >
                                Tandai sudah dicek{dipilih.length > 0 ? ` (${String(dipilih.length)})` : ''}
                            </Tombol>
                        </div>
                    ) : null}
                    <ul className="flex flex-col">
                        {tampil.map((r) => (
                            <li
                                key={r.Uuid}
                                className="flex flex-col gap-1 border-b border-garis py-2 last:border-b-0 sm:flex-row sm:items-start sm:gap-3"
                            >
                                {butir.BolehTandai ? (
                                    <div className="shrink-0">
                                        <KotakCentang
                                            label={`Pilih ${r.Judul}`}
                                            nilai={dipilih.includes(r.Uuid)}
                                            saatBerubah={(pilih) => Alihkan(r.Uuid, pilih)}
                                        />
                                    </div>
                                ) : null}
                                <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                                    {r.Tautan ? (
                                        <Link
                                            href={r.Tautan}
                                            className="font-mono text-isi font-semibold break-all text-brand underline"
                                        >
                                            {r.Judul}
                                        </Link>
                                    ) : (
                                        <span className="text-isi font-semibold break-words text-teks-utama">
                                            {r.Judul}
                                        </span>
                                    )}
                                    {r.Keterangan ? (
                                        <span className="text-label break-words text-teks-sekunder">
                                            {r.Keterangan}
                                        </span>
                                    ) : null}
                                </div>
                                {r.Tanggal ? (
                                    <span className="shrink-0 text-label whitespace-nowrap text-teks-sekunder">
                                        {FormatTanggal(r.Tanggal)}
                                    </span>
                                ) : null}
                            </li>
                        ))}
                    </ul>
                    {butir.Jumlah > tampil.length ? (
                        <p className="text-label text-teks-sekunder">
                            Menampilkan {String(tampil.length)} terbaru dari {butir.Jumlah.toLocaleString('id-ID')}.
                            Sisanya muncul setelah yang ini ditandai.
                        </p>
                    ) : null}
                </div>
            ) : null}
        </Card>
    );
}

/** D-23 D: berlangganan ringkasan pagi Kotak Tindakan lewat WhatsApp (pilihan pribadi; Owner bawaan aktif). */
function PilihanRingkasanWhatsapp({ ringkasan }: { ringkasan: RingkasanWhatsappTindakan }) {
    const [memproses, AturMemproses] = useState(false);

    return (
        <div className="flex items-start gap-3 rounded-panel border border-garis bg-permukaan p-4">
            <Switch
                id="RingkasanWhatsapp"
                checked={ringkasan.Aktif}
                disabled={!ringkasan.BisaWhatsapp || memproses}
                onCheckedChange={(aktif) =>
                    router.put(
                        '/kelola/tindakan/ringkasan-whatsapp',
                        { Aktif: aktif },
                        {
                            preserveScroll: true,
                            onStart: () => AturMemproses(true),
                            onFinish: () => AturMemproses(false),
                        },
                    )
                }
            />
            <div className="flex min-w-0 flex-col gap-0.5">
                <label htmlFor="RingkasanWhatsapp" className="text-isi font-semibold text-teks-utama">
                    Kirim ringkasan ke WhatsApp saya setiap pagi
                </label>
                <span className="text-label text-teks-sekunder">
                    {ringkasan.BisaWhatsapp
                        ? 'Butir penting & perlu perhatian dikirim pukul 07.00 WIB, hanya bila ada.'
                        : 'Akun Anda belum punya nomor HP, jadi ringkasan tidak bisa dikirim. Tambahkan nomor HP di profil akun.'}
                </span>
            </div>
        </div>
    );
}

/**
 * Kotak Tindakan (D-23 C): semua yang perlu perhatian di satu tempat, dari yang paling penting. Transaksi offline yang
 * perlu dicek bisa ditandai "sudah dicek" (izin `tindakan.tinjau`); pengingat lain selesai sendiri saat keadaannya
 * berubah (stok diisi, piutang dilunasi, buku ditutup).
 */
export default function HalamanKotakTindakan({ Butir, RingkasanWhatsapp }: PropsKotakTindakan) {
    const { props } = usePage<PropsBersamaAplikasi>();

    return (
        <TataLetakAplikasi judul="Kotak tindakan">
            <p className="max-w-3xl text-isi text-teks-sekunder">
                Semua yang perlu Anda perhatikan hari ini, dari yang paling penting. Butir hilang sendiri setelah
                diselesaikan.
            </p>
            {RingkasanWhatsapp ? <PilihanRingkasanWhatsapp ringkasan={RingkasanWhatsapp} /> : null}
            <DaftarGalatServer galat={props.errors} />
            {Butir.length === 0 ? (
                <Empty className="rounded-panel border border-garis bg-permukaan px-4 py-6 md:p-8">
                    <EmptyHeader>
                        <EmptyMedia variant="icon" aria-hidden="true">
                            <CircleCheckIcon />
                        </EmptyMedia>
                        <EmptyTitle>Semua beres</EmptyTitle>
                        <EmptyDescription className="text-isi text-teks-sekunder">
                            Tidak ada yang perlu ditindaklanjuti saat ini.
                        </EmptyDescription>
                    </EmptyHeader>
                </Empty>
            ) : (
                <div className="flex flex-col gap-3">
                    {Butir.map((b) => (
                        <KartuButir key={b.Kunci} butir={b} />
                    ))}
                </div>
            )}
        </TataLetakAplikasi>
    );
}
