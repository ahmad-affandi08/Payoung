import { router, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';

import BidangOutlet from '@/Komponen/Formulir/BidangOutlet';
import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import BidangUang from '@/Komponen/Formulir/BidangUang';
import Tombol from '@/Komponen/Formulir/Tombol';
import Panel from '@/Komponen/Kelola/Panel';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { KolomTabel } from '@/Komponen/TabelData/Tipe';
import DialogFormulir from '@/Komponen/Tindakan/DialogFormulir';
import DialogKonfirmasi from '@/Komponen/Tindakan/DialogKonfirmasi';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import { FormatRupiah } from '@/Pustaka/Format';
import { FormatTanggalWaktu } from '@/Pustaka/FormatWaktu';
import { GalatPermintaan, KirimJson } from '@/Pustaka/PermintaanJson';
import type { SaldoWhatsapp } from '@/Tipe/Whatsapp';

type Outlet = { Uuid: string; Nama: string };
type Mutasi = SaldoWhatsapp['Mutasi'][number];

const DASAR = '/kelola/pelanggan/whatsapp/saldo';

const JenisTopUp = (status: string): 'sukses' | 'peringatan' | 'bahaya' =>
    status === 'Lunas' ? 'sukses' : status === 'Menunggu' ? 'peringatan' : 'bahaya';

/**
 * D-300: saldo WhatsApp. Saldo utama dipakai broadcast semua outlet. Pemilik bisa menyisihkan kredit untuk satu outlet
 * (dengan kode OTP lewat WhatsApp) dan menariknya kembali kapan saja. Saldo tidak dipakai untuk pesan struk, poin, atau
 * laporan karyawan, dan tidak bisa diuangkan kembali.
 */
export default function PanelSaldoWhatsapp({ saldo, outlet }: { saldo: SaldoWhatsapp; outlet: Outlet[] }) {
    const [isi, AturIsi] = useState(false);
    const [alokasi, AturAlokasi] = useState(false);
    const [tarik, AturTarik] = useState<SaldoWhatsapp['KreditOutlet'][number] | null>(null);

    const kolomMutasi: KolomTabel<Mutasi>[] = [
        {
            id: 'DibuatPada',
            accessorKey: 'DibuatPada',
            header: 'Waktu',
            meta: { label: 'Waktu', prioritas: 'utama', wajib: true, kelasSel: 'whitespace-nowrap' },
            cell: ({ row }) => (row.original.DibuatPada ? FormatTanggalWaktu(row.original.DibuatPada) : '-'),
        },
        { id: 'Jenis', accessorKey: 'Jenis', header: 'Kejadian', meta: { label: 'Kejadian', prioritas: 'utama' } },
        { id: 'Kolam', accessorKey: 'Kolam', header: 'Saldo', meta: { label: 'Saldo', prioritas: 'penting' } },
        {
            id: 'Jumlah',
            accessorKey: 'Jumlah',
            header: 'Jumlah',
            meta: { label: 'Jumlah', prioritas: 'utama', kelasSel: 'text-right tabular-nums' },
            cell: ({ row }) => FormatRupiah(row.original.Jumlah),
        },
        {
            id: 'SaldoSetelah',
            accessorKey: 'SaldoSetelah',
            header: 'Sisa',
            meta: { label: 'Sisa', prioritas: 'rendah', kelasSel: 'text-right tabular-nums' },
            cell: ({ row }) => FormatRupiah(row.original.SaldoSetelah),
        },
    ];

    return (
        <div className="flex flex-col gap-4">
            <p className="max-w-3xl text-isi text-teks-sekunder">
                {saldo.Berbayar
                    ? `Broadcast WhatsApp memotong saldo ${FormatRupiah(saldo.HargaPerPesan)} per pesan. Pesan struk, poin, dan laporan karyawan tidak memakai saldo.`
                    : 'Broadcast WhatsApp saat ini gratis, jadi saldo tidak terpakai. Saldo yang sudah ada tetap aman.'}
            </p>

            <div className="grid gap-4 lg:grid-cols-2">
                <Panel judul="Saldo utama">
                    <p className="text-judul font-semibold tabular-nums text-teks-utama">
                        {FormatRupiah(saldo.SaldoUtama)}
                    </p>
                    <div className="mt-3 flex flex-wrap gap-2">
                        <Tombol onClick={() => AturIsi(true)} disabled={!saldo.TopUpTersedia}>
                            Isi saldo
                        </Tombol>
                        <Tombol
                            varian="sekunder"
                            onClick={() => AturAlokasi(true)}
                            disabled={outlet.length === 0 || Number(saldo.SaldoUtama) <= 0}
                        >
                            Alokasikan ke outlet
                        </Tombol>
                    </div>
                    {saldo.TopUpTersedia ? null : (
                        <p className="mt-2 text-label text-teks-sekunder">
                            Pembayaran saldo belum tersedia. Hubungi dukungan bila ini berlangsung lama.
                        </p>
                    )}
                </Panel>

                <Panel judul="Kredit per outlet">
                    {saldo.KreditOutlet.length === 0 ? (
                        <p className="text-isi text-teks-sekunder">
                            Belum ada kredit khusus outlet. Semua outlet memakai saldo utama.
                        </p>
                    ) : (
                        <ul className="flex flex-col gap-2">
                            {saldo.KreditOutlet.map((kredit) => (
                                <li key={kredit.UuidOutlet} className="flex flex-wrap items-center gap-3">
                                    <span className="text-isi text-teks-utama">{kredit.NamaOutlet}</span>
                                    <span className="text-isi font-semibold tabular-nums text-teks-utama">
                                        {FormatRupiah(kredit.Saldo)}
                                    </span>
                                    <Tombol
                                        varian="sekunder"
                                        onClick={() => AturTarik(kredit)}
                                        disabled={Number(kredit.Saldo) <= 0}
                                    >
                                        Tarik ke saldo utama
                                    </Tombol>
                                </li>
                            ))}
                        </ul>
                    )}
                </Panel>
            </div>

            {saldo.TopUp.length > 0 ? (
                <Panel judul="Isi saldo terakhir">
                    <ul className="flex flex-col gap-2">
                        {saldo.TopUp.map((t) => (
                            <li key={t.Uuid} className="flex flex-wrap items-center gap-3 text-isi">
                                <span className="tabular-nums text-teks-utama">{FormatRupiah(t.Jumlah)}</span>
                                <LabelStatus jenis={JenisTopUp(t.Status)} teks={t.LabelStatus} />
                                <span className="text-label text-teks-sekunder">
                                    {t.DibuatPada ? FormatTanggalWaktu(t.DibuatPada) : ''}
                                </span>
                            </li>
                        ))}
                    </ul>
                </Panel>
            ) : null}

            <TabelData
                id="pusat-whatsapp-saldo-mutasi"
                label="Riwayat saldo WhatsApp"
                kolom={kolomMutasi}
                sumber={{ mode: 'lokal', data: saldo.Mutasi }}
                ambilIdBaris={(m) => String(m.Id)}
                cari="Cari kejadian atau outlet"
                labelBaris={(m) => `mutasi ${m.Jenis}`}
                kosong={{ judul: 'Belum ada pergerakan saldo.' }}
            />

            {isi ? <FormIsiSaldo minimal={saldo.TopUpMinimal} saatSelesai={() => AturIsi(false)} /> : null}
            {alokasi ? (
                <DialogAlokasi outlet={outlet} saldoUtama={saldo.SaldoUtama} saatSelesai={() => AturAlokasi(false)} />
            ) : null}
            {tarik ? (
                <DialogKonfirmasi
                    judul={`Tarik kredit ${tarik.NamaOutlet}?`}
                    labelAksi="Tarik ke saldo utama"
                    varian="utama"
                    saatBatal={() => AturTarik(null)}
                    saatKonfirmasi={() =>
                        router.post(
                            `${DASAR}/tarik`,
                            { UuidOutlet: tarik.UuidOutlet },
                            { preserveScroll: true, onFinish: () => AturTarik(null) },
                        )
                    }
                >
                    <p>
                        Sisa kredit {FormatRupiah(tarik.Saldo)} kembali ke saldo utama. Outlet ini lalu memakai saldo
                        utama lagi.
                    </p>
                </DialogKonfirmasi>
            ) : null}
        </div>
    );
}

function FormIsiSaldo({ minimal, saatSelesai }: { minimal: string; saatSelesai: () => void }) {
    const formulir = useForm({ Jumlah: minimal.replace(/\.\d+$/, '') });
    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        formulir.post(`${DASAR}/top-up`);
    };

    return (
        <DialogFormulir judul="Isi saldo WhatsApp" saatTutup={saatSelesai} galatUmum={formulir.errors.Jumlah}>
            <form onSubmit={Kirim} className="flex flex-col gap-4" noValidate>
                <BidangUang
                    label="Jumlah"
                    nilai={formulir.data.Jumlah}
                    saatBerubah={(nilai) => formulir.setData('Jumlah', nilai)}
                    keterangan={`Minimal ${FormatRupiah(minimal)}. Anda diarahkan ke halaman pembayaran, saldo bertambah setelah lunas.`}
                    required
                />
                <div className="flex flex-wrap gap-2">
                    <Tombol type="submit" memproses={formulir.processing}>
                        Lanjut ke pembayaran
                    </Tombol>
                    <Tombol varian="sekunder" onClick={saatSelesai}>
                        Batal
                    </Tombol>
                </div>
            </form>
        </DialogFormulir>
    );
}

function DialogAlokasi({
    outlet,
    saldoUtama,
    saatSelesai,
}: {
    outlet: Outlet[];
    saldoUtama: string;
    saatSelesai: () => void;
}) {
    const formulir = useForm({ UuidOutlet: outlet.length === 1 ? (outlet[0]?.Uuid ?? '') : '', Jumlah: '', Kode: '' });
    const [tujuan, AturTujuan] = useState<string | null>(null);
    const [galat, AturGalat] = useState<string | null>(null);
    const [meminta, AturMeminta] = useState(false);

    const MintaKode = async () => {
        AturMeminta(true);
        AturGalat(null);
        try {
            const hasil = await KirimJson<{ Tujuan: string }>(`${DASAR}/alokasi/kode`, {
                UuidOutlet: formulir.data.UuidOutlet,
                Jumlah: formulir.data.Jumlah,
            });
            AturTujuan(hasil.Tujuan);
        } catch (kesalahan) {
            AturGalat(kesalahan instanceof GalatPermintaan ? kesalahan.message : 'Kode belum bisa dikirim. Coba lagi.');
        } finally {
            AturMeminta(false);
        }
    };

    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        formulir.post(`${DASAR}/alokasi`, { preserveScroll: true, onSuccess: saatSelesai });
    };

    const siapMinta = formulir.data.UuidOutlet !== '' && formulir.data.Jumlah !== '';

    return (
        <DialogFormulir
            judul="Alokasikan kredit ke outlet"
            saatTutup={saatSelesai}
            galatUmum={galat ?? formulir.errors.Kode}
            keterangan={<p>Saldo utama tersedia {FormatRupiah(saldoUtama)}. Kode konfirmasi dikirim lewat WhatsApp.</p>}
        >
            <form onSubmit={Kirim} className="flex flex-col gap-4" noValidate>
                <BidangOutlet
                    nilai={formulir.data.UuidOutlet}
                    opsi={outlet.map((o) => ({ Nilai: o.Uuid, Label: o.Nama }))}
                    saatBerubah={(nilai) => formulir.setData('UuidOutlet', nilai)}
                    galat={formulir.errors.UuidOutlet}
                />
                <BidangUang
                    label="Jumlah kredit"
                    nilai={formulir.data.Jumlah}
                    saatBerubah={(nilai) => formulir.setData('Jumlah', nilai)}
                    galat={formulir.errors.Jumlah}
                    disabled={tujuan !== null}
                    required
                />
                {tujuan === null ? (
                    <div className="flex flex-wrap gap-2">
                        <Tombol onClick={() => void MintaKode()} memproses={meminta} disabled={!siapMinta}>
                            Kirim kode konfirmasi
                        </Tombol>
                        <Tombol varian="sekunder" onClick={saatSelesai}>
                            Batal
                        </Tombol>
                    </div>
                ) : (
                    <>
                        <BidangTeks
                            label="Kode konfirmasi"
                            inputMode="numeric"
                            nilai={formulir.data.Kode}
                            saatBerubah={(nilai) => formulir.setData('Kode', nilai)}
                            keterangan={`Kode 6 angka dikirim ke WhatsApp ${tujuan}. Berlaku 10 menit.`}
                            maxLength={6}
                            required
                        />
                        <div className="flex flex-wrap gap-2">
                            <Tombol type="submit" memproses={formulir.processing}>
                                Alokasikan kredit
                            </Tombol>
                            <Tombol varian="sekunder" onClick={() => void MintaKode()} memproses={meminta}>
                                Kirim ulang kode
                            </Tombol>
                        </div>
                    </>
                )}
            </form>
        </DialogFormulir>
    );
}
