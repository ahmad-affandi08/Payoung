import { Link, router } from '@inertiajs/react';
import { useState } from 'react';

import Tombol from '@/Komponen/Formulir/Tombol';
import DaftarTab, { type ItemTab } from '@/Komponen/Katalog/DaftarTab';
import Panel from '@/Komponen/Kelola/Panel';
import BagianAddon, { type DataAddonLangganan } from '@/Komponen/Langganan/BagianAddon';
import FormPilihPaket, { type PilihanPaket } from '@/Komponen/Langganan/FormPilihPaket';
import KepalaPaket from '@/Komponen/Langganan/KepalaPaket';
import PanelKuotaTransaksi, { type DataKuotaTransaksi } from '@/Komponen/Langganan/PanelKuotaTransaksi';
import PanelTurunPaket, { type DataTurunPaket } from '@/Komponen/Langganan/PanelTurunPaket';
import PemakaianPaket from '@/Komponen/Langganan/PemakaianPaket';
import type { AturanLamaLangganan } from '@/Komponen/Langganan/PilihLamaLangganan';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { KolomTabel } from '@/Komponen/TabelData/Tipe';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { FormatRupiah } from '@/Pustaka/Format';
import { FormatTanggalWaktu } from '@/Pustaka/FormatWaktu';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { KapasitasLangganan, RingkasanLangganan } from '@/Tipe/Langganan';
import { JenisLabelTagihan, type TagihanLangganan } from '@/Tipe/TagihanLangganan';

type PropsLangganan = {
    Langganan: RingkasanLangganan | null;
    Addon: DataAddonLangganan;
    PilihanPaket: PilihanPaket[];
    AturanLama: AturanLamaLangganan;
    Tagihan: TagihanLangganan[];
    HariMasaTenggang: number;
    Kapasitas?: KapasitasLangganan;
    TurunPaket?: DataTurunPaket;
    KuotaTransaksi?: DataKuotaTransaksi | null;
    KuotaTransaksiHarian?: DataKuotaTransaksi | null;
};

type KunciTab = 'ikhtisar' | 'paket' | 'addon' | 'tagihan';

const kunciTabSah: readonly KunciTab[] = ['ikhtisar', 'paket', 'addon', 'tagihan'];

/** Tab awal: `?tab=`, atau Paket & harga bila dibuka dari dialog fitur terkunci (`?paket=KODE`, D-23). */
function AmbilTabAwal(): KunciTab {
    if (typeof window === 'undefined') {
        return 'ikhtisar';
    }

    const parameter = new URLSearchParams(window.location.search);
    const diminta = parameter.get('tab');
    const sah = kunciTabSah.find((kunci) => kunci === diminta);

    return sah ?? (parameter.get('paket') ? 'paket' : 'ikhtisar');
}

function TampakTagihanTerbuka(tagihan: TagihanLangganan): boolean {
    return tagihan.Status === 'Terbit' || tagihan.Status === 'JatuhTempo';
}

/**
 * Langganan & tagihan tenant (P-08, D-332): kepala paket dengan sisa masa berlaku, lalu empat tab. Ikhtisar memuat
 * pemakaian paket dan tagihan berikutnya, Paket & harga untuk memperpanjang atau naik paket, Add-on, dan Tagihan.
 */
export default function HalamanLangganan({
    Langganan,
    Addon,
    PilihanPaket,
    AturanLama,
    Tagihan,
    HariMasaTenggang,
    Kapasitas,
    TurunPaket,
    KuotaTransaksi,
    KuotaTransaksiHarian,
}: PropsLangganan) {
    const [tab, AturTab] = useState<KunciTab>(AmbilTabAwal);
    const tagihanTerbuka = Tagihan.find(TampakTagihanTerbuka);
    const jumlahTerbuka = Tagihan.filter(TampakTagihanTerbuka).length;

    const PilihTab = (kunci: KunciTab) => {
        AturTab(kunci);

        // Alamat ikut tab supaya muat ulang dan kembali dari aksi (turun paket, add-on) tetap di tab yang sama.
        const alamat = new URL(window.location.href);
        alamat.searchParams.set('tab', kunci);
        window.history.replaceState(window.history.state, '', alamat);
    };

    const itemTab: ItemTab<KunciTab>[] = [
        { Kunci: 'ikhtisar', Label: 'Ikhtisar' },
        { Kunci: 'paket', Label: 'Paket & harga' },
        { Kunci: 'addon', Label: 'Add-on' },
        {
            Kunci: 'tagihan',
            Label: 'Tagihan',
            ...(jumlahTerbuka > 0 ? { Lencana: `${String(jumlahTerbuka)} menunggu` } : {}),
        },
    ];

    return (
        <TataLetakAplikasi judul="Langganan">
            {Langganan === null ? (
                <Pemberitahuan jenis="bahaya" judul="Data langganan tidak ditemukan">
                    Hubungi tim kami agar langganan usaha Anda diperiksa.
                </Pemberitahuan>
            ) : (
                <>
                    <BannerStatus langganan={Langganan} hariMasaTenggang={HariMasaTenggang} />
                    <KepalaPaket langganan={Langganan} saatPilihPaket={() => PilihTab('paket')} />
                </>
            )}
            <DaftarTab
                label="Bagian halaman langganan"
                tab={itemTab}
                aktif={tab}
                saatPilih={PilihTab}
                panel={{
                    ikhtisar: (
                        <>
                            <div className="grid items-start gap-4 lg:grid-cols-[minmax(0,1fr)_22rem]">
                                <div className="order-2 flex flex-col gap-4 lg:order-1">
                                    <PemakaianPaket kapasitas={Kapasitas} />
                                    <PanelKuotaTransaksi kuota={KuotaTransaksiHarian} />
                                    <PanelKuotaTransaksi kuota={KuotaTransaksi} />
                                </div>
                                <div className="order-1 lg:order-2">
                                    <TagihanBerikutnya
                                        tagihan={tagihanTerbuka}
                                        bolehPilihPaket={Langganan !== null && Langganan.Status !== 'Berhenti'}
                                        saatPilihPaket={() => PilihTab('paket')}
                                    />
                                </div>
                            </div>
                            <TagihanTerakhir tagihan={Tagihan} saatLihatSemua={() => PilihTab('tagihan')} />
                        </>
                    ),
                    paket: (
                        <>
                            {tagihanTerbuka ? (
                                <Pemberitahuan
                                    jenis="peringatan"
                                    judul={`Tagihan ${tagihanTerbuka.Nomor} menunggu pembayaran`}
                                >
                                    <p>
                                        Total {FormatRupiah(tagihanTerbuka.Total)}, jatuh tempo{' '}
                                        {FormatTanggalWaktu(tagihanTerbuka.JatuhTempoPada)}. Selesaikan atau batalkan
                                        tagihan ini sebelum membuat tagihan baru.{' '}
                                        <Link
                                            href={`/kelola/langganan/tagihan/${tagihanTerbuka.Uuid}`}
                                            className="font-semibold text-brand underline"
                                        >
                                            Bayar tagihan
                                        </Link>
                                    </p>
                                </Pemberitahuan>
                            ) : Langganan !== null && Langganan.Status !== 'Berhenti' ? (
                                <FormPilihPaket pilihan={PilihanPaket} langganan={Langganan} aturan={AturanLama} />
                            ) : null}
                            {TurunPaket ? <PanelTurunPaket data={TurunPaket} /> : null}
                        </>
                    ),
                    addon: <BagianAddon addon={Addon} />,
                    tagihan: <RiwayatTagihan tagihan={Tagihan} />,
                }}
            />
        </TataLetakAplikasi>
    );
}

function BannerStatus({ langganan, hariMasaTenggang }: { langganan: RingkasanLangganan; hariMasaTenggang: number }) {
    switch (langganan.Status) {
        case 'Trial':
            return (
                <Pemberitahuan jenis="info" judul="Masa trial">
                    Trial berakhir {FormatTanggalWaktu(langganan.TrialBerakhirPada)}. Setelah itu usaha Anda turun ke
                    paket Gratis. Pilih paket di tab Paket & harga untuk tetap memakai semua fitur.
                </Pemberitahuan>
            );
        case 'Tertunggak':
            return (
                <Pemberitahuan jenis="peringatan" judul="Langganan tertunggak">
                    Periode langganan sudah berakhir. Semua fitur masih berjalan sampai{' '}
                    {FormatTanggalWaktu(langganan.BatasTenggangPada)} (masa tenggang {hariMasaTenggang} hari). Bayar
                    perpanjangan agar kasir tidak terkunci.
                </Pemberitahuan>
            );
        case 'Ditangguhkan':
            return (
                <Pemberitahuan jenis="bahaya" judul="Langganan ditangguhkan">
                    Kasir terkunci. Anda tetap bisa masuk, melihat laporan, mengekspor data, dan membayar tagihan.
                </Pemberitahuan>
            );
        default:
            return null;
    }
}

function TagihanBerikutnya({
    tagihan,
    bolehPilihPaket,
    saatPilihPaket,
}: {
    tagihan: TagihanLangganan | undefined;
    bolehPilihPaket: boolean;
    saatPilihPaket: () => void;
}) {
    if (tagihan === undefined) {
        return (
            <Panel judul="Tagihan berikutnya">
                <p className="text-isi text-teks-sekunder">
                    Tidak ada tagihan yang menunggu pembayaran. Tagihan perpanjangan terbit otomatis menjelang masa
                    berlaku berakhir.
                </p>
                {bolehPilihPaket ? (
                    <div>
                        <Tombol varian="sekunder" onClick={saatPilihPaket}>
                            Lihat paket dan harga
                        </Tombol>
                    </div>
                ) : null}
            </Panel>
        );
    }

    return (
        <Panel
            judul="Tagihan berikutnya"
            keterangan={`${tagihan.Nomor} | ${tagihan.LabelJenis}`}
            aksi={<LabelStatus jenis={JenisLabelTagihan(tagihan.Status)} teks={tagihan.LabelStatus} />}
        >
            <span className="text-tampilan font-semibold tabular-nums text-teks-utama">
                {FormatRupiah(tagihan.Total)}
            </span>
            <dl className="flex flex-col gap-1 text-isi">
                <div className="flex items-baseline justify-between gap-3">
                    <dt className="text-teks-sekunder">Jatuh tempo</dt>
                    <dd className="font-semibold text-teks-utama">{FormatTanggalWaktu(tagihan.JatuhTempoPada)}</dd>
                </div>
                {tagihan.JumlahPpn !== '0.00' ? (
                    <div className="flex items-baseline justify-between gap-3">
                        <dt className="text-teks-sekunder">Sudah termasuk PPN</dt>
                        <dd className="tabular-nums">{FormatRupiah(tagihan.JumlahPpn)}</dd>
                    </div>
                ) : null}
            </dl>
            <Tombol onClick={() => router.visit(`/kelola/langganan/tagihan/${tagihan.Uuid}`)}>Bayar tagihan</Tombol>
        </Panel>
    );
}

function TagihanTerakhir({ tagihan, saatLihatSemua }: { tagihan: TagihanLangganan[]; saatLihatSemua: () => void }) {
    const terakhir = [...tagihan].sort((a, b) => b.TerbitPada.localeCompare(a.TerbitPada)).slice(0, 3);

    return (
        <Panel
            judul="Tagihan terakhir"
            aksi={
                tagihan.length > terakhir.length ? (
                    <Tombol varian="sekunder" onClick={saatLihatSemua}>
                        Lihat semua tagihan
                    </Tombol>
                ) : undefined
            }
        >
            {terakhir.length === 0 ? (
                <p className="text-isi text-teks-sekunder">Belum ada tagihan.</p>
            ) : (
                <ul className="flex flex-col divide-y divide-garis">
                    {terakhir.map((baris) => (
                        <li
                            key={baris.Uuid}
                            className="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 py-3"
                        >
                            <div className="flex min-w-0 flex-col">
                                <Link
                                    href={`/kelola/langganan/tagihan/${baris.Uuid}`}
                                    className="font-mono text-label text-brand underline"
                                >
                                    {baris.Nomor}
                                </Link>
                                <span className="text-keterangan text-teks-sekunder">
                                    {baris.NamaPaket} | {baris.Siklus} | jatuh tempo{' '}
                                    {FormatTanggalWaktu(baris.JatuhTempoPada)}
                                </span>
                            </div>
                            <div className="flex items-center gap-3">
                                <span className="text-isi font-semibold tabular-nums text-teks-utama">
                                    {FormatRupiah(baris.Total)}
                                </span>
                                <LabelStatus jenis={JenisLabelTagihan(baris.Status)} teks={baris.LabelStatus} />
                            </div>
                        </li>
                    ))}
                </ul>
            )}
        </Panel>
    );
}

const kolomTagihan: KolomTabel<TagihanLangganan>[] = [
    {
        id: 'Nomor',
        accessorKey: 'Nomor',
        header: 'Nomor',
        enableSorting: false,
        meta: { label: 'Nomor tagihan', prioritas: 'utama', wajib: true, kelasSel: 'whitespace-nowrap' },
        cell: ({ row }) => (
            <Link
                href={`/kelola/langganan/tagihan/${row.original.Uuid}`}
                className="font-mono text-label text-brand underline"
            >
                {row.original.Nomor}
            </Link>
        ),
    },
    {
        id: 'Paket',
        header: 'Paket',
        enableSorting: false,
        meta: { label: 'Paket', prioritas: 'rendah' },
        cell: ({ row }) => `${row.original.NamaPaket} | ${row.original.Siklus}`,
    },
    {
        id: 'JatuhTempoPada',
        accessorKey: 'JatuhTempoPada',
        header: 'Jatuh tempo',
        meta: { label: 'Jatuh tempo', prioritas: 'penting', kelasSel: 'whitespace-nowrap' },
        cell: ({ row }) => FormatTanggalWaktu(row.original.JatuhTempoPada),
    },
    {
        id: 'Total',
        header: 'Total',
        enableSorting: false,
        meta: { label: 'Total', angka: true, prioritas: 'penting' },
        cell: ({ row }) => FormatRupiah(row.original.Total),
    },
    {
        id: 'Status',
        accessorKey: 'Status',
        header: 'Status',
        meta: { label: 'Status', prioritas: 'penting' },
        cell: ({ row }) => (
            <LabelStatus jenis={JenisLabelTagihan(row.original.Status)} teks={row.original.LabelStatus} />
        ),
    },
];

function RiwayatTagihan({ tagihan }: { tagihan: TagihanLangganan[] }) {
    return (
        <section aria-labelledby="judul-riwayat" className="flex flex-col gap-2">
            <h2 id="judul-riwayat" className="text-subjudul font-semibold text-teks-utama">
                Riwayat tagihan
            </h2>
            <TabelData
                id="langganan-riwayat-tagihan"
                label="Riwayat tagihan langganan"
                kolom={kolomTagihan}
                sumber={{ mode: 'lokal', data: tagihan }}
                ambilIdBaris={(baris) => baris.Uuid}
                urutBawaan="-JatuhTempoPada"
                alamatDetail={(baris) => `/kelola/langganan/tagihan/${baris.Uuid}`}
                kosong={{ ilustrasi: true, judul: 'Belum ada tagihan.' }}
            />
        </section>
    );
}
