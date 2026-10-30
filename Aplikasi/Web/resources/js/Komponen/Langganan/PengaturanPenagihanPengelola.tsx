import { useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';

import BidangPilihan from '@/Komponen/Formulir/BidangPilihan';
import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import BidangTeksPanjang from '@/Komponen/Formulir/BidangTeksPanjang';
import Tombol from '@/Komponen/Formulir/Tombol';
import { AmbilGalatUmum, KerangkaForm } from '@/Komponen/Pengelola/Tenant/FormTindakan';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';

import { PanelLamaLangganan, type PengaturanLamaLangganan } from './PengaturanLamaLanggananPengelola';

export type RekeningTujuan = { Kode: string; NamaBank: string; NomorRekening: string; AtasNama: string };

export type PengaturanPenagihan = {
    Boleh: boolean;
    PlatformPkp: boolean;
    PkpDariKonsol: boolean;
    Rekening: RekeningTujuan[];
    RekeningDariKonsol: boolean;
    LamaLangganan: PengaturanLamaLangganan;
    LamaLanggananDariKonsol: boolean;
};

const MAKS_REKENING = 5;

/**
 * D-114: rekening tujuan transfer dan status PKP platform. Semua peran Tagihan boleh melihat; hanya Super Admin
 * (izin `tagihan.pengaturan`) yang bisa mengubah. Selama belum pernah disimpan dari konsol, yang tampil adalah nilai
 * awal server, dan panel menyebutnya supaya tidak dikira sudah diatur.
 */
export function PanelPengaturanPenagihan({ pengaturan }: { pengaturan: PengaturanPenagihan }) {
    const [dialog, AturDialog] = useState<'rekening' | 'pkp' | null>(null);
    const Tutup = () => AturDialog(null);

    return (
        <section aria-labelledby="judul-pengaturan-penagihan" className="flex flex-col gap-3">
            <h2 id="judul-pengaturan-penagihan" className="text-subjudul font-semibold text-teks-utama">
                Pengaturan penagihan
            </h2>

            <div className="flex flex-col gap-2">
                <div className="flex flex-wrap items-center gap-3">
                    <p className="text-isi text-teks-sekunder">
                        Status PKP platform:{' '}
                        <strong className="text-teks-utama">
                            {pengaturan.PlatformPkp ? 'PKP (tagihan memuat PPN)' : 'Bukan PKP (tagihan tanpa PPN)'}
                        </strong>
                    </p>
                    {pengaturan.PkpDariKonsol ? null : <LabelStatus jenis="peringatan" teks="Nilai awal server" />}
                    {pengaturan.Boleh ? (
                        <Tombol varian="sekunder" onClick={() => AturDialog('pkp')}>
                            Ubah status PKP
                        </Tombol>
                    ) : null}
                </div>
                <p className="text-keterangan text-teks-sekunder">
                    Perubahan hanya berlaku untuk tagihan yang terbit sesudahnya. Tagihan yang sudah terbit tidak
                    berubah.
                </p>
            </div>

            <div className="flex flex-col gap-2">
                <div className="flex flex-wrap items-center gap-3">
                    <h3 className="text-isi font-semibold text-teks-utama">Rekening tujuan transfer</h3>
                    {pengaturan.RekeningDariKonsol ? null : <LabelStatus jenis="peringatan" teks="Nilai awal server" />}
                    {pengaturan.Boleh ? (
                        <Tombol varian="sekunder" onClick={() => AturDialog('rekening')}>
                            Ubah rekening
                        </Tombol>
                    ) : null}
                </div>
                {pengaturan.Rekening.length === 0 ? (
                    <p className="text-isi text-teks-sekunder">
                        Belum ada rekening tujuan. Tagihan manual belum bisa dibayar sampai rekening diisi.
                    </p>
                ) : (
                    <ul className="flex flex-col gap-1">
                        {pengaturan.Rekening.map((rekening) => (
                            <li key={rekening.Kode} className="text-isi text-teks-sekunder">
                                <strong className="text-teks-utama">{rekening.NamaBank}</strong>{' '}
                                <span className="font-mono tabular-nums">{rekening.NomorRekening}</span> a.n.{' '}
                                {rekening.AtasNama}
                            </li>
                        ))}
                    </ul>
                )}
            </div>

            <PanelLamaLangganan
                aturan={pengaturan.LamaLangganan}
                dariKonsol={pengaturan.LamaLanggananDariKonsol}
                boleh={pengaturan.Boleh}
            />

            {dialog === 'pkp' ? <FormUbahPkp pkp={pengaturan.PlatformPkp} saatSelesai={Tutup} /> : null}
            {dialog === 'rekening' ? <FormUbahRekening rekening={pengaturan.Rekening} saatSelesai={Tutup} /> : null}
        </section>
    );
}

const OPSI_PKP = [
    { Nilai: 'ya', Label: 'PKP: tagihan memuat PPN' },
    { Nilai: 'tidak', Label: 'Bukan PKP: tagihan tanpa PPN' },
];

function FormUbahPkp({ pkp, saatSelesai }: { pkp: boolean; saatSelesai: () => void }) {
    const formulir = useForm({ Pkp: pkp, Alasan: '' });
    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        formulir.put('/tagihan/pengaturan/pkp', { preserveScroll: true, onSuccess: saatSelesai });
    };

    return (
        <KerangkaForm
            judul="Ubah status PKP"
            keterangan={
                <p>
                    Nyalakan hanya setelah SPPKP terbit. Tagihan yang terbit sesudah ini mengikuti status baru, yang
                    sudah terbit tidak berubah. Perubahan tercatat di audit.
                </p>
            }
            labelKirim="Simpan status PKP"
            memproses={formulir.processing}
            galatUmum={AmbilGalatUmum(formulir.errors)}
            saatKirim={Kirim}
            saatBatal={saatSelesai}
        >
            <BidangPilihan
                label="Status PKP platform"
                nilai={formulir.data.Pkp ? 'ya' : 'tidak'}
                opsi={OPSI_PKP}
                saatBerubah={(nilai) => formulir.setData('Pkp', nilai === 'ya')}
                galat={formulir.errors.Pkp}
                required
            />
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

type IsianRekening = { NamaBank: string; NomorRekening: string; AtasNama: string };

function FormUbahRekening({ rekening, saatSelesai }: { rekening: RekeningTujuan[]; saatSelesai: () => void }) {
    const awal: IsianRekening[] =
        rekening.length > 0
            ? rekening.map(({ NamaBank, NomorRekening, AtasNama }) => ({ NamaBank, NomorRekening, AtasNama }))
            : [{ NamaBank: '', NomorRekening: '', AtasNama: '' }];
    const formulir = useForm<{ Rekening: IsianRekening[]; Alasan: string }>({ Rekening: awal, Alasan: '' });
    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        formulir.put('/tagihan/pengaturan/rekening-tujuan', { preserveScroll: true, onSuccess: saatSelesai });
    };
    const UbahBaris = (indeks: number, kunci: keyof IsianRekening, nilai: string) =>
        formulir.setData(
            'Rekening',
            formulir.data.Rekening.map((baris, i) => (i === indeks ? { ...baris, [kunci]: nilai } : baris)),
        );
    const galat = formulir.errors as Partial<Record<string, string>>;

    return (
        <KerangkaForm
            judul="Ubah rekening tujuan transfer"
            keterangan={
                <p>
                    Rekening ini yang ditampilkan ke Owner untuk tagihan manual. Rekening pertama menjadi rekening
                    utama. Bukti transfer yang sudah masuk menyimpan salinan rekeningnya sendiri, jadi tidak berubah.
                </p>
            }
            labelKirim="Simpan rekening"
            memproses={formulir.processing}
            galatUmum={AmbilGalatUmum(formulir.errors)}
            saatKirim={Kirim}
            saatBatal={saatSelesai}
        >
            {formulir.data.Rekening.map((baris, indeks) => (
                <fieldset key={indeks} className="flex flex-col gap-3 rounded-md border border-garis p-3">
                    <legend className="px-1 text-label font-semibold text-teks-utama">
                        {indeks === 0 ? 'Rekening utama' : `Rekening ${indeks + 1}`}
                    </legend>
                    <BidangTeks
                        label={`Nama bank (rekening ${indeks + 1})`}
                        nilai={baris.NamaBank}
                        saatBerubah={(nilai) => UbahBaris(indeks, 'NamaBank', nilai)}
                        galat={galat[`Rekening.${indeks}.NamaBank`]}
                        required
                    />
                    <BidangTeks
                        label={`Nomor rekening (rekening ${indeks + 1})`}
                        nilai={baris.NomorRekening}
                        saatBerubah={(nilai) => UbahBaris(indeks, 'NomorRekening', nilai)}
                        galat={galat[`Rekening.${indeks}.NomorRekening`]}
                        inputMode="numeric"
                        kode
                        required
                    />
                    <BidangTeks
                        label={`Atas nama (rekening ${indeks + 1})`}
                        nilai={baris.AtasNama}
                        saatBerubah={(nilai) => UbahBaris(indeks, 'AtasNama', nilai)}
                        galat={galat[`Rekening.${indeks}.AtasNama`]}
                        required
                    />
                    {formulir.data.Rekening.length > 1 ? (
                        <div>
                            <Tombol
                                varian="sekunder"
                                onClick={() =>
                                    formulir.setData(
                                        'Rekening',
                                        formulir.data.Rekening.filter((_, i) => i !== indeks),
                                    )
                                }
                            >
                                {`Hapus rekening ${indeks + 1}`}
                            </Tombol>
                        </div>
                    ) : null}
                </fieldset>
            ))}
            {galat.Rekening ? <p className="text-keterangan text-bahaya">{galat.Rekening}</p> : null}
            {formulir.data.Rekening.length < MAKS_REKENING ? (
                <div>
                    <Tombol
                        varian="sekunder"
                        onClick={() =>
                            formulir.setData('Rekening', [
                                ...formulir.data.Rekening,
                                { NamaBank: '', NomorRekening: '', AtasNama: '' },
                            ])
                        }
                    >
                        Tambah rekening
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
