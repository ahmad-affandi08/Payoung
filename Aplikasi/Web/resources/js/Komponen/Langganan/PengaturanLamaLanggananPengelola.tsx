import { useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';

import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import BidangTeksPanjang from '@/Komponen/Formulir/BidangTeksPanjang';
import Tombol from '@/Komponen/Formulir/Tombol';
import { AmbilGalatUmum, KerangkaForm } from '@/Komponen/Pengelola/Tenant/FormTindakan';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';

export type AnakTanggaDiskon = { DariBulan: number; Persen: string };

export type PengaturanLamaLangganan = {
    MinimalBulan: number;
    MaksimalBulan: number;
    BatasTertinggiBulan: number;
    MaksimalBulanManual: number;
    TanggaDiskon: AnakTanggaDiskon[];
};

const MAKS_ANAK_TANGGA = 12;

/**
 * D-249: lama langganan yang boleh dipilih tenant dan tangga diskonnya. Semua peran Tagihan boleh melihat, hanya Super
 * Admin (izin `tagihan.pengaturan`) yang mengubah. Nilai awal server ditandai supaya tidak dikira sudah diatur.
 */
export function PanelLamaLangganan({
    aturan,
    dariKonsol,
    boleh,
}: {
    aturan: PengaturanLamaLangganan;
    dariKonsol: boolean;
    boleh: boolean;
}) {
    const [dialog, AturDialog] = useState(false);

    return (
        <div className="flex flex-col gap-2">
            <div className="flex flex-wrap items-center gap-3">
                <h3 className="text-isi font-semibold text-teks-utama">Lama langganan</h3>
                {dariKonsol ? null : <LabelStatus jenis="peringatan" teks="Nilai awal server" />}
                {boleh ? (
                    <Tombol varian="sekunder" onClick={() => AturDialog(true)}>
                        Ubah lama langganan
                    </Tombol>
                ) : null}
            </div>
            <p className="text-isi text-teks-sekunder">
                Tenant memilih bebas{' '}
                <strong className="text-teks-utama">
                    {aturan.MinimalBulan} sampai {aturan.MaksimalBulan} bulan
                </strong>
                .
            </p>
            {aturan.TanggaDiskon.length === 0 ? (
                <p className="text-isi text-teks-sekunder">Tanpa diskon menurut lama.</p>
            ) : (
                <ul className="flex flex-col gap-1">
                    {aturan.TanggaDiskon.map((anak) => (
                        <li key={anak.DariBulan} className="text-isi text-teks-sekunder">
                            Mulai {anak.DariBulan} bulan: diskon{' '}
                            <strong className="text-teks-utama tabular-nums">{anak.Persen}%</strong>
                        </li>
                    ))}
                </ul>
            )}
            <p className="text-keterangan text-teks-sekunder">
                Lama kelipatan 12 bulan memakai harga tahunan di katalog paket, bukan tangga ini. Perubahan hanya
                berlaku untuk tagihan yang terbit sesudahnya.
            </p>
            {dialog ? <FormUbahLama aturan={aturan} saatSelesai={() => AturDialog(false)} /> : null}
        </div>
    );
}

type IsianAnak = { DariBulan: string; Persen: string };

function FormUbahLama({ aturan, saatSelesai }: { aturan: PengaturanLamaLangganan; saatSelesai: () => void }) {
    const formulir = useForm<{
        MinimalBulan: string;
        MaksimalBulan: string;
        TanggaDiskon: IsianAnak[];
        Alasan: string;
    }>({
        MinimalBulan: String(aturan.MinimalBulan),
        MaksimalBulan: String(aturan.MaksimalBulan),
        TanggaDiskon: aturan.TanggaDiskon.map((anak) => ({ DariBulan: String(anak.DariBulan), Persen: anak.Persen })),
        Alasan: '',
    });
    const galat = formulir.errors as Partial<Record<string, string>>;
    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        formulir.put('/tagihan/pengaturan/lama-langganan', { preserveScroll: true, onSuccess: saatSelesai });
    };
    const UbahAnak = (indeks: number, kunci: keyof IsianAnak, nilai: string) =>
        formulir.setData(
            'TanggaDiskon',
            formulir.data.TanggaDiskon.map((anak, i) => (i === indeks ? { ...anak, [kunci]: nilai } : anak)),
        );

    return (
        <KerangkaForm
            judul="Ubah lama langganan"
            keterangan={
                <p>
                    Atur rentang lama yang boleh dipilih tenant dan diskon menurut lama. Batas maksimal paling tinggi{' '}
                    {aturan.BatasTertinggiBulan} bulan. Tagihan manual boleh sampai {aturan.MaksimalBulanManual} bulan.
                    Perubahan tercatat di audit.
                </p>
            }
            labelKirim="Simpan lama langganan"
            memproses={formulir.processing}
            galatUmum={AmbilGalatUmum(formulir.errors)}
            saatKirim={Kirim}
            saatBatal={saatSelesai}
        >
            <div className="grid gap-4 sm:grid-cols-2">
                <BidangTeks
                    label="Lama minimal (bulan)"
                    nilai={formulir.data.MinimalBulan}
                    saatBerubah={(nilai) => formulir.setData('MinimalBulan', nilai.replace(/\D/g, '').slice(0, 3))}
                    galat={formulir.errors.MinimalBulan}
                    inputMode="numeric"
                    maxLength={3}
                    required
                />
                <BidangTeks
                    label="Lama maksimal (bulan)"
                    nilai={formulir.data.MaksimalBulan}
                    saatBerubah={(nilai) => formulir.setData('MaksimalBulan', nilai.replace(/\D/g, '').slice(0, 3))}
                    galat={formulir.errors.MaksimalBulan}
                    inputMode="numeric"
                    maxLength={3}
                    required
                />
            </div>
            {formulir.data.TanggaDiskon.map((anak, indeks) => (
                <fieldset key={indeks} className="flex flex-col gap-3 rounded-md border border-garis p-3">
                    <legend className="px-1 text-label font-semibold text-teks-utama">{`Diskon ${indeks + 1}`}</legend>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <BidangTeks
                            label={`Mulai lama (bulan) diskon ${indeks + 1}`}
                            nilai={anak.DariBulan}
                            saatBerubah={(nilai) => UbahAnak(indeks, 'DariBulan', nilai.replace(/\D/g, '').slice(0, 3))}
                            galat={galat[`TanggaDiskon.${indeks}.DariBulan`]}
                            inputMode="numeric"
                            maxLength={3}
                            required
                        />
                        <BidangTeks
                            label={`Persen diskon ${indeks + 1}`}
                            nilai={anak.Persen}
                            saatBerubah={(nilai) =>
                                UbahAnak(indeks, 'Persen', nilai.replace(/[^\d.]/g, '').slice(0, 6))
                            }
                            galat={galat[`TanggaDiskon.${indeks}.Persen`]}
                            inputMode="decimal"
                            maxLength={6}
                            required
                        />
                    </div>
                    <div>
                        <Tombol
                            varian="sekunder"
                            onClick={() =>
                                formulir.setData(
                                    'TanggaDiskon',
                                    formulir.data.TanggaDiskon.filter((_, i) => i !== indeks),
                                )
                            }
                        >
                            {`Hapus diskon ${indeks + 1}`}
                        </Tombol>
                    </div>
                </fieldset>
            ))}
            {galat.TanggaDiskon ? <p className="text-keterangan text-bahaya">{galat.TanggaDiskon}</p> : null}
            {formulir.data.TanggaDiskon.length < MAKS_ANAK_TANGGA ? (
                <div>
                    <Tombol
                        varian="sekunder"
                        onClick={() =>
                            formulir.setData('TanggaDiskon', [
                                ...formulir.data.TanggaDiskon,
                                { DariBulan: '', Persen: '' },
                            ])
                        }
                    >
                        Tambah diskon
                    </Tombol>
                </div>
            ) : null}
            <BidangTeksPanjang
                label="Alasan perubahan"
                nilai={formulir.data.Alasan}
                baris={2}
                maksimal={500}
                saatBerubah={(nilai) => formulir.setData('Alasan', nilai)}
                galat={formulir.errors.Alasan}
                required
            />
        </KerangkaForm>
    );
}
