import { router, useForm, usePage } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';

import BidangPilihan from '@/Komponen/Formulir/BidangPilihan';
import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import Tombol from '@/Komponen/Formulir/Tombol';
import Panel from '@/Komponen/Kelola/Panel';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { FormatTanggalWaktu } from '@/Pustaka/FormatWaktu';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';
import type { BidangWhatsapp, KuotaWhatsapp, OpsiPenyediaWhatsapp, PropsWhatsappTenant } from '@/Tipe/WhatsappTenant';

const alamat = '/kelola/pengaturan/whatsapp';

const jenisStatusUji = { BelumDiuji: 'peringatan', Berhasil: 'sukses', Gagal: 'bahaya' } as const;

const NAMA_BULAN = [
    'Januari',
    'Februari',
    'Maret',
    'April',
    'Mei',
    'Juni',
    'Juli',
    'Agustus',
    'September',
    'Oktober',
    'November',
    'Desember',
];

type IsianWhatsapp = {
    Penyedia: string;
    Pengaturan: Record<string, string>;
    Kredensial: Record<string, string>;
};

/** `2026-10` menjadi `Oktober 2026`. */
export function FormatPeriodeKuota(periode: string): string {
    const [tahun = '', bulan = ''] = periode.split('-');
    const nama = NAMA_BULAN[Number(bulan) - 1];

    return nama ? `${nama} ${tahun}` : periode;
}

/** Tingkat kuota untuk warna dan pesan: aman, hampir habis (sisa ≤ 20%), atau habis. */
export function TingkatKuota(kuota: KuotaWhatsapp): 'tanpa-batas' | 'aman' | 'hampir' | 'habis' {
    if (kuota.Batas === null) {
        return 'tanpa-batas';
    }

    if (kuota.Sisa === 0) {
        return 'habis';
    }

    return kuota.Batas > 0 && (kuota.Sisa ?? 0) / kuota.Batas <= 0.2 ? 'hampir' : 'aman';
}

/** Isian awal bidang pengaturan: nilai tersimpan (penyedia sama), nilai bawaan penyedia, atau opsi pertama. */
export function IsiAwalPengaturanWhatsapp(
    bidang: BidangWhatsapp[],
    tersimpan: Record<string, string | number> | null,
): Record<string, string> {
    return Object.fromEntries(
        bidang.map((b) => [b.Kunci, String(tersimpan?.[b.Kunci] ?? b.Bawaan ?? b.Opsi?.[0] ?? '')]),
    );
}

function KredensialKosong(penyedia: OpsiPenyediaWhatsapp | undefined): Record<string, string> {
    return Object.fromEntries((penyedia?.BidangKredensial ?? []).map((b) => [b.Kunci, '']));
}

function PanelKuota({ kuota, nomorPayoungTersedia }: { kuota: KuotaWhatsapp; nomorPayoungTersedia: boolean }) {
    const tingkat = TingkatKuota(kuota);
    const persen = kuota.Batas === null || kuota.Batas === 0 ? 0 : Math.min(100, (kuota.Terpakai / kuota.Batas) * 100);

    return (
        <Panel judul="Kuota pesan nomor Payoung" tingkat="h2">
            <div className="grid gap-3">
                <p className="text-isi text-teks-sekunder">
                    Pesan ke pelanggan (struk digital, pengingat piutang, kampanye, pengingat reservasi, dan lainnya)
                    memakai nomor WhatsApp Payoung dan dihitung ke kuota paket Anda, per bulan kalender. Pemasangan
                    nomor sendiri di bawah tidak memakai kuota.
                </p>
                <div className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                    <span className="text-judul font-semibold text-teks-utama tabular-nums">
                        {kuota.Terpakai.toLocaleString('id-ID')}
                        {kuota.Batas === null ? '' : ` / ${kuota.Batas.toLocaleString('id-ID')}`}
                    </span>
                    <span className="text-isi text-teks-sekunder">
                        {kuota.Batas === null
                            ? `pesan terkirim di ${FormatPeriodeKuota(kuota.Periode)} (tanpa batas)`
                            : `pesan terpakai di ${FormatPeriodeKuota(kuota.Periode)}`}
                    </span>
                </div>
                {kuota.Batas !== null ? (
                    <div
                        role="progressbar"
                        aria-label="Pemakaian kuota pesan WhatsApp"
                        aria-valuemin={0}
                        aria-valuemax={kuota.Batas}
                        aria-valuenow={Math.min(kuota.Terpakai, kuota.Batas)}
                        className="h-2 w-full overflow-hidden rounded-full bg-latar"
                    >
                        <div
                            className={tingkat === 'aman' ? 'h-full bg-brand' : 'h-full bg-bahaya'}
                            style={{ width: `${persen}%` }}
                        />
                    </div>
                ) : null}
                {tingkat === 'habis' ? (
                    <Pemberitahuan jenis="bahaya" judul="Kuota bulan ini habis">
                        Pesan lewat nomor Payoung ditolak sampai bulan depan.{' '}
                        {kuota.Batas === 0
                            ? 'Paket Anda belum menyertakan kuota pesan.'
                            : 'Tambah add-on kuota di menu Langganan, naikkan paket, atau pasang WhatsApp sendiri di bawah.'}
                    </Pemberitahuan>
                ) : null}
                {tingkat === 'hampir' ? (
                    <Pemberitahuan jenis="peringatan" judul="Kuota hampir habis">
                        Sisa {kuota.Sisa?.toLocaleString('id-ID')} pesan bulan ini. Tambah add-on kuota atau pasang
                        WhatsApp sendiri agar pesan ke pelanggan tidak terhenti.
                    </Pemberitahuan>
                ) : null}
                {!nomorPayoungTersedia ? (
                    <Pemberitahuan jenis="info">
                        Nomor WhatsApp Payoung belum tersambung, jadi pesan baru bisa dikirim setelah Anda memasang
                        WhatsApp sendiri.
                    </Pemberitahuan>
                ) : null}
            </div>
        </Panel>
    );
}

/**
 * Pengaturan › WhatsApp (D-108): pantau kuota pesan nomor Payoung dan, bila perlu, pasang nomor WhatsApp sendiri
 * (Fonnte, Wablas, StarSender, Watzap, atau WhatsApp Cloud API). Alur sama dengan gerbang pembayaran: simpan, uji
 * koneksi, aktifkan. Pesan lewat nomor sendiri tidak memakai kuota.
 */
export default function HalamanWhatsappTenant({
    Kuota,
    NomorPayoungTersedia,
    Milik,
    DaftarPenyedia,
}: PropsWhatsappTenant) {
    const { props } = usePage<PropsBersamaAplikasi>();
    const galatUmum = (props.errors as Record<string, string | undefined>).Umum;
    const penyediaAwal = Milik?.Penyedia ?? DaftarPenyedia[0]?.Nilai ?? '';
    const penyediaAwalOpsi = DaftarPenyedia.find((p) => p.Nilai === penyediaAwal);
    const formulir = useForm<IsianWhatsapp>({
        Penyedia: penyediaAwal,
        Pengaturan: IsiAwalPengaturanWhatsapp(penyediaAwalOpsi?.BidangPengaturan ?? [], Milik?.Pengaturan ?? null),
        Kredensial: KredensialKosong(penyediaAwalOpsi),
    });
    const [memproses, AturMemproses] = useState<'uji' | 'status' | null>(null);
    const penyedia = DaftarPenyedia.find((p) => p.Nilai === formulir.data.Penyedia);
    // Kredensial tersimpan hanya berlaku untuk penyedia yang sama (ganti penyedia = isi ulang).
    const penyediaTersimpan = Milik !== null && formulir.data.Penyedia === Milik.Penyedia;
    const galat = formulir.errors as Record<string, string | undefined>;

    const GantiPenyedia = (nilai: string) => {
        const baru = DaftarPenyedia.find((p) => p.Nilai === nilai);

        if (!baru) {
            return;
        }

        const sama = Milik !== null && nilai === Milik.Penyedia;
        formulir.setData('Penyedia', nilai);
        formulir.setData(
            'Pengaturan',
            IsiAwalPengaturanWhatsapp(baru.BidangPengaturan, sama ? Milik.Pengaturan : null),
        );
        formulir.setData('Kredensial', KredensialKosong(baru));
    };

    const Simpan = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        formulir.post(alamat, {
            preserveScroll: true,
            onSuccess: () => formulir.setData('Kredensial', KredensialKosong(penyedia)),
        });
    };

    const Jalankan = (aksi: 'uji' | 'aktifkan' | 'nonaktifkan') => {
        router.post(
            `${alamat}/${aksi}`,
            {},
            {
                preserveScroll: true,
                onStart: () => AturMemproses(aksi === 'uji' ? 'uji' : 'status'),
                onFinish: () => AturMemproses(null),
            },
        );
    };

    return (
        <TataLetakAplikasi judul="WhatsApp">
            <div className="grid gap-4">
                {galatUmum ? <Pemberitahuan jenis="bahaya">{galatUmum}</Pemberitahuan> : null}
                <PanelKuota kuota={Kuota} nomorPayoungTersedia={NomorPayoungTersedia} />

                {Milik ? (
                    <Panel judul="Status WhatsApp sendiri" tingkat="h2">
                        <div className="grid gap-3">
                            <div className="flex flex-wrap items-center gap-2">
                                <span className="text-isi font-semibold text-teks-utama">{Milik.LabelPenyedia}</span>
                                <LabelStatus jenis={jenisStatusUji[Milik.StatusUji]} teks={Milik.LabelStatusUji} />
                                <LabelStatus
                                    jenis={Milik.Aktif ? 'sukses' : 'netral'}
                                    teks={Milik.Aktif ? 'Aktif, tanpa kuota' : 'Nonaktif'}
                                />
                            </div>
                            {Milik.PesanUji ? (
                                <p className="text-keterangan text-teks-sekunder">
                                    Hasil uji terakhir
                                    {Milik.DiujiPada ? ` (${FormatTanggalWaktu(Milik.DiujiPada)})` : ''}:{' '}
                                    {Milik.PesanUji}
                                </p>
                            ) : null}
                            <div className="flex flex-wrap gap-2">
                                <Tombol
                                    varian="sekunder"
                                    memproses={memproses === 'uji'}
                                    disabled={memproses !== null || formulir.isDirty}
                                    onClick={() => Jalankan('uji')}
                                >
                                    Uji koneksi
                                </Tombol>
                                {Milik.Aktif ? (
                                    <Tombol
                                        varian="bahaya"
                                        memproses={memproses === 'status'}
                                        disabled={memproses !== null}
                                        onClick={() => Jalankan('nonaktifkan')}
                                    >
                                        Kembali ke nomor Payoung
                                    </Tombol>
                                ) : (
                                    <Tombol
                                        memproses={memproses === 'status'}
                                        disabled={
                                            memproses !== null || formulir.isDirty || Milik.StatusUji !== 'Berhasil'
                                        }
                                        onClick={() => Jalankan('aktifkan')}
                                    >
                                        Aktifkan WhatsApp sendiri
                                    </Tombol>
                                )}
                            </div>
                            {!Milik.Aktif && Milik.StatusUji !== 'Berhasil' ? (
                                <p className="text-keterangan text-teks-sekunder">
                                    WhatsApp sendiri bisa diaktifkan setelah uji koneksi berhasil.
                                </p>
                            ) : null}
                        </div>
                    </Panel>
                ) : null}

                <Panel judul={Milik ? 'Ubah WhatsApp sendiri' : 'Pasang WhatsApp sendiri'} tingkat="h2">
                    <form onSubmit={Simpan} className="grid gap-3 sm:grid-cols-2" noValidate>
                        <div className="sm:col-span-2">
                            <Pemberitahuan jenis="info">
                                Pakai nomor khusus usaha. Penyedia tidak resmi (Fonnte, Wablas, StarSender, Watzap)
                                murah dan mudah, tetapi nomor bisa diblokir WhatsApp bila mengirim massal. WhatsApp
                                Cloud API (Meta) resmi dan lebih aman, tetapi perlu akun Meta Business dan templat pesan
                                yang disetujui.
                            </Pemberitahuan>
                        </div>
                        <BidangPilihan
                            label="Penyedia"
                            nilai={formulir.data.Penyedia}
                            opsi={DaftarPenyedia.map((p) => ({ Nilai: p.Nilai, Label: p.Label }))}
                            saatBerubah={GantiPenyedia}
                            galat={galat.Penyedia}
                            required
                        />
                        {penyedia?.Keterangan ? (
                            <div className="sm:col-span-2">
                                <Pemberitahuan jenis="info">{penyedia.Keterangan}</Pemberitahuan>
                            </div>
                        ) : null}
                        {(penyedia?.BidangPengaturan ?? []).map((bidang) => (
                            <BidangTeks
                                key={bidang.Kunci}
                                label={bidang.Label}
                                {...(bidang.Keterangan ? { keterangan: bidang.Keterangan } : {})}
                                jenis="text"
                                nilai={formulir.data.Pengaturan[bidang.Kunci] ?? ''}
                                saatBerubah={(nilai) =>
                                    formulir.setData('Pengaturan', {
                                        ...formulir.data.Pengaturan,
                                        [bidang.Kunci]: nilai,
                                    })
                                }
                                galat={galat[`Pengaturan.${bidang.Kunci}`]}
                                required={bidang.Wajib}
                            />
                        ))}
                        {(penyedia?.BidangKredensial ?? []).map((bidang) => (
                            <BidangTeks
                                key={bidang.Kunci}
                                label={bidang.Label}
                                jenis="password"
                                autoComplete="new-password"
                                keterangan={
                                    penyediaTersimpan && Milik.PetunjukKredensial[bidang.Kunci]
                                        ? `Tersimpan ${Milik.PetunjukKredensial[bidang.Kunci]}. Kosongkan bila tidak diganti.`
                                        : 'Salin dari dasbor penyedia.'
                                }
                                nilai={formulir.data.Kredensial[bidang.Kunci] ?? ''}
                                saatBerubah={(nilai) =>
                                    formulir.setData('Kredensial', {
                                        ...formulir.data.Kredensial,
                                        [bidang.Kunci]: nilai,
                                    })
                                }
                                galat={galat[`Kredensial.${bidang.Kunci}`]}
                                required={bidang.Wajib && !penyediaTersimpan}
                            />
                        ))}
                        <p className="text-keterangan text-teks-sekunder sm:col-span-2">
                            Kredensial disimpan terenkripsi dan tidak pernah ditampilkan ulang. Setelah disimpan,
                            WhatsApp sendiri nonaktif sampai uji koneksi berhasil.
                        </p>
                        <div className="sm:col-span-2">
                            <Tombol type="submit" memproses={formulir.processing}>
                                Simpan WhatsApp sendiri
                            </Tombol>
                        </div>
                    </form>
                </Panel>
            </div>
        </TataLetakAplikasi>
    );
}
