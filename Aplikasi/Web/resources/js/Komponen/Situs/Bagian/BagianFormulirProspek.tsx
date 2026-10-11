import { useForm, usePage } from '@inertiajs/react';
import { CircleCheck } from 'lucide-react';
import type { FormEvent } from 'react';

import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import BidangTeksPanjang from '@/Komponen/Formulir/BidangTeksPanjang';
import KotakCentang from '@/Komponen/Formulir/KotakCentang';
import Tombol from '@/Komponen/Formulir/Tombol';
import { CatatProspek } from '@/Pustaka/AnalitikSitus';
import type { BagianSitus } from '@/Tipe/Situs';

import { KepalaBagian, type LatarBagian, WadahBagian } from './KepalaBagian';

/**
 * Formulir kontak/minta demo (situs bagian B). Isian dikirim ke `/prospek`; bidang `Situs` adalah perangkap bot yang
 * disembunyikan dari pengunjung & pembaca layar. Persetujuan pemakaian data wajib (UU PDP).
 */
export default function BagianFormulirProspek({
    bagian,
    latar,
    garisAtas,
}: {
    bagian: Extract<BagianSitus, { Jenis: 'FormulirProspek' }>;
    latar: LatarBagian;
    garisAtas?: boolean | undefined;
}) {
    const { props, url } = usePage<{ ProspekTerkirim?: boolean }>();
    const demo = bagian.JenisProspek === 'Demo';
    const formulir = useForm({
        Jenis: demo ? 'Demo' : 'Kontak',
        Nama: '',
        NamaUsaha: '',
        NoHp: '',
        Email: '',
        JenisUsaha: '',
        Kota: '',
        Pesan: '',
        HalamanAsal: url.split(/[?#]/)[0] ?? '/',
        Setuju: false,
        Situs: '',
    });
    const d = formulir.data;
    const galat = formulir.errors as Record<string, string | undefined>;

    const Kirim = (p: FormEvent) => {
        p.preventDefault();
        formulir.post('/prospek', {
            preserveScroll: true,
            onSuccess: () => {
                CatatProspek(d.Jenis);
                formulir.reset();
            },
        });
    };

    return (
        <WadahBagian latar={latar} id="formulir-prospek" garisAtas={garisAtas}>
            <KepalaBagian label={bagian.Label} judul={bagian.Judul} subjudul={bagian.Subjudul} />
            <div className="mx-auto w-full max-w-2xl rounded-3xl border border-garis bg-permukaan p-5 sm:p-8">
                {props.ProspekTerkirim ? (
                    <div role="status" className="flex flex-col items-center gap-2 py-6 text-center">
                        <CircleCheck className="size-10 text-sukses" aria-hidden />
                        <p className="text-subjudul font-semibold text-teks-utama">
                            Terima kasih, pesan Anda terkirim.
                        </p>
                        <p className="text-isi text-teks-sekunder">
                            Tim kami akan menghubungi Anda lewat WhatsApp dalam 1 hari kerja.
                        </p>
                    </div>
                ) : (
                    <form onSubmit={Kirim} className="grid gap-4 sm:grid-cols-2" noValidate>
                        <BidangTeks
                            label="Nama"
                            nilai={d.Nama}
                            saatBerubah={(v) => formulir.setData('Nama', v)}
                            galat={galat.Nama}
                            autoComplete="name"
                            maxLength={100}
                            required
                        />
                        <BidangTeks
                            label="Nomor WhatsApp"
                            keterangan="Contoh 0812 3456 7890."
                            nilai={d.NoHp}
                            saatBerubah={(v) => formulir.setData('NoHp', v)}
                            galat={galat.NoHp}
                            autoComplete="tel"
                            inputMode="tel"
                            maxLength={25}
                            required
                        />
                        <BidangTeks
                            label="Nama usaha"
                            nilai={d.NamaUsaha}
                            saatBerubah={(v) => formulir.setData('NamaUsaha', v)}
                            galat={galat.NamaUsaha}
                            autoComplete="organization"
                            maxLength={150}
                        />
                        <BidangTeks
                            label="Jenis usaha"
                            keterangan="Misalnya kafe, toko kelontong, salon, laundry."
                            nilai={d.JenisUsaha}
                            saatBerubah={(v) => formulir.setData('JenisUsaha', v)}
                            galat={galat.JenisUsaha}
                            maxLength={60}
                        />
                        <BidangTeks
                            label="Email"
                            jenis="email"
                            nilai={d.Email}
                            saatBerubah={(v) => formulir.setData('Email', v)}
                            galat={galat.Email}
                            autoComplete="email"
                            maxLength={150}
                        />
                        <BidangTeks
                            label="Kota"
                            nilai={d.Kota}
                            saatBerubah={(v) => formulir.setData('Kota', v)}
                            galat={galat.Kota}
                            autoComplete="address-level2"
                            maxLength={100}
                        />
                        <div className="sm:col-span-2">
                            <BidangTeksPanjang
                                label={demo ? 'Yang ingin Anda lihat saat demo' : 'Pesan'}
                                nilai={d.Pesan}
                                saatBerubah={(v) => formulir.setData('Pesan', v)}
                                galat={galat.Pesan}
                                maksimal={1000}
                                baris={4}
                            />
                        </div>
                        {/* Perangkap bot: tidak terlihat & tidak bisa difokus pengunjung. */}
                        <div aria-hidden="true" className="absolute -left-[9999px] h-px w-px overflow-hidden">
                            <label>
                                Situs web
                                <input
                                    type="text"
                                    name="Situs"
                                    tabIndex={-1}
                                    autoComplete="off"
                                    value={d.Situs}
                                    onChange={(e) => formulir.setData('Situs', e.target.value)}
                                />
                            </label>
                        </div>
                        <div className="flex flex-col gap-1 sm:col-span-2">
                            <KotakCentang
                                label="Saya setuju data ini dipakai tim Payoung untuk menghubungi saya."
                                nilai={d.Setuju}
                                saatBerubah={(v) => formulir.setData('Setuju', v)}
                            />
                            <p className="text-keterangan text-teks-sekunder">
                                Data disimpan terenkripsi dan tidak dibagikan ke pihak lain. Baca{' '}
                                <a href="/legal/kebijakan-privasi" className="text-brand underline">
                                    kebijakan privasi
                                </a>
                                .
                            </p>
                            {galat.Setuju ? (
                                <p className="text-keterangan font-semibold text-bahaya">{galat.Setuju}</p>
                            ) : null}
                        </div>
                        <div className="sm:col-span-2">
                            <Tombol type="submit" memproses={formulir.processing}>
                                {bagian.TeksTombol || (demo ? 'Kirim permintaan demo' : 'Kirim pesan')}
                            </Tombol>
                        </div>
                    </form>
                )}
            </div>
        </WadahBagian>
    );
}
