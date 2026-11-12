import { router, useForm, usePage } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';

import BidangPilihan from '@/Komponen/Formulir/BidangPilihan';
import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import Tombol from '@/Komponen/Formulir/Tombol';
import Panel from '@/Komponen/Kelola/Panel';
import { Sheet, SheetContent, SheetDescription, SheetFooter, SheetHeader, SheetTitle } from '@/Komponen/Ui/sheet';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { FormatTanggalWaktu } from '@/Pustaka/FormatWaktu';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';

type BidangPengaturan = {
    Kunci: string;
    Label: string;
    Jenis: 'Teks' | 'Angka' | 'Email' | 'Url' | 'Pilihan';
    Wajib: boolean;
    Opsi?: string[];
    Bawaan?: string | number;
    Keterangan?: string;
};

type BidangKredensial = { Kunci: string; Label: string; Wajib: boolean };

type OpsiPenyedia = {
    Nilai: string;
    Label: string;
    Keterangan: string;
    Resmi: boolean;
    BidangPengaturan: BidangPengaturan[];
    BidangKredensial: BidangKredensial[];
};

export type SlotIntegrasiServer = {
    Jenis: 'Email' | 'Whatsapp' | 'Penyimpanan';
    LabelJenis: string;
    Penyedia: { Nilai: string; Label: string };
    BidangPengaturan: BidangPengaturan[];
    BidangKredensial: BidangKredensial[];
    DaftarPenyedia: OpsiPenyedia[];
    Konfigurasi: {
        Pengaturan: Record<string, string | number>;
        PetunjukKredensial: Record<string, string>;
        Aktif: boolean;
        Status: 'BelumDiuji' | 'Terhubung' | 'Gagal';
        LabelStatus: string;
        TerakhirDiujiPada: string | null;
        HasilUji: { Berhasil: boolean; Pesan: string } | null;
    } | null;
};

const jenisLabelStatus = { BelumDiuji: 'peringatan', Terhubung: 'sukses', Gagal: 'bahaya' } as const;

/** Kegunaan tiap integrasi bagi toko, supaya Owner tahu apa yang mati bila belum diatur. */
const kegunaan: Record<SlotIntegrasiServer['Jenis'], string> = {
    Email: 'Reset kata sandi, verifikasi akun pengguna, dan email penting dari sistem.',
    Whatsapp: 'Struk digital, pengingat piutang, kampanye, ringkasan pagi, dan kode masuk pembeli toko online.',
    Penyimpanan: 'Foto produk, bukti kas, swafoto absensi, dan berkas lain. Kosong = disimpan di disk server ini.',
};

/**
 * D-35 edisi Lisensi: Pengaturan › Email & WhatsApp server (khusus Owner). Toko yang memasang Payoung di servernya
 * sendiri menghubungkan akun email, WhatsApp, dan penyimpanan berkas miliknya di sini. Kredensial disimpan terenkripsi
 * dan tidak pernah ditampilkan ulang; integrasi baru aktif setelah uji koneksi berhasil.
 */
export default function HalamanIntegrasiServer({ Integrasi }: { Integrasi: SlotIntegrasiServer[] }) {
    const { props } = usePage<PropsBersamaAplikasi>();
    const [sunting, AturSunting] = useState<SlotIntegrasiServer | null>(null);
    const [memproses, AturMemproses] = useState<string | null>(null);
    const Kirim = (slot: SlotIntegrasiServer, aksi: 'uji' | 'nonaktifkan') =>
        router.post(
            `/kelola/pengaturan/integrasi-server/${slot.Jenis.toLowerCase()}/${aksi}`,
            {},
            { preserveScroll: true, onStart: () => AturMemproses(slot.Jenis), onFinish: () => AturMemproses(null) },
        );

    return (
        <TataLetakAplikasi judul="Email & WhatsApp server">
            <p className="text-isi text-teks-sekunder">
                Hubungkan email, WhatsApp, dan penyimpanan berkas milik toko. Perubahan berlaku setelah uji koneksi
                berhasil.
            </p>
            {props.errors.Umum ? <Pemberitahuan jenis="bahaya">{props.errors.Umum}</Pemberitahuan> : null}
            <div className="grid gap-4 lg:grid-cols-3">
                {Integrasi.map((slot) => {
                    const konfigurasi = slot.Konfigurasi;

                    return (
                        <Panel
                            key={slot.Jenis}
                            judul={slot.LabelJenis}
                            keterangan={kegunaan[slot.Jenis]}
                            aksi={
                                konfigurasi ? (
                                    <LabelStatus
                                        jenis={konfigurasi.Aktif ? 'sukses' : jenisLabelStatus[konfigurasi.Status]}
                                        teks={konfigurasi.Aktif ? 'Aktif' : konfigurasi.LabelStatus}
                                    />
                                ) : (
                                    <LabelStatus jenis="netral" teks="Belum diatur" />
                                )
                            }
                        >
                            {konfigurasi ? (
                                <dl className="grid grid-cols-1 gap-y-1 text-keterangan">
                                    <dt className="text-teks-sekunder">Penyedia</dt>
                                    <dd className="text-teks-utama">{slot.Penyedia.Label}</dd>
                                    {slot.BidangKredensial.map((bidang) => (
                                        <div key={bidang.Kunci} className="contents">
                                            <dt className="text-teks-sekunder">{bidang.Label}</dt>
                                            <dd className="font-mono text-teks-utama">
                                                {konfigurasi.PetunjukKredensial[bidang.Kunci] ?? '••••'}
                                            </dd>
                                        </div>
                                    ))}
                                    <dt className="text-teks-sekunder">Uji koneksi terakhir</dt>
                                    <dd className="text-teks-utama">
                                        {konfigurasi.HasilUji && konfigurasi.TerakhirDiujiPada
                                            ? `${FormatTanggalWaktu(konfigurasi.TerakhirDiujiPada)} | ${konfigurasi.HasilUji.Pesan}`
                                            : 'Belum pernah diuji'}
                                    </dd>
                                </dl>
                            ) : (
                                <p className="text-keterangan text-teks-sekunder">
                                    Belum ada akun {slot.LabelJenis.toLowerCase()} yang terhubung.
                                </p>
                            )}
                            <div className="flex flex-wrap gap-2">
                                <Tombol varian={konfigurasi ? 'sekunder' : 'utama'} onClick={() => AturSunting(slot)}>
                                    {konfigurasi ? 'Ubah' : 'Atur'}
                                </Tombol>
                                {konfigurasi ? (
                                    <Tombol
                                        varian="sekunder"
                                        memproses={memproses === slot.Jenis}
                                        disabled={memproses !== null}
                                        onClick={() => Kirim(slot, 'uji')}
                                    >
                                        {konfigurasi.Aktif ? 'Uji koneksi' : 'Uji & aktifkan'}
                                    </Tombol>
                                ) : null}
                                {konfigurasi?.Aktif ? (
                                    <Tombol
                                        varian="sekunder"
                                        disabled={memproses !== null}
                                        onClick={() => Kirim(slot, 'nonaktifkan')}
                                    >
                                        Nonaktifkan
                                    </Tombol>
                                ) : null}
                            </div>
                        </Panel>
                    );
                })}
            </div>
            {sunting ? <FormIntegrasiServer slot={sunting} saatSelesai={() => AturSunting(null)} /> : null}
        </TataLetakAplikasi>
    );
}

type IsianIntegrasiServer = {
    Jenis: string;
    Penyedia: string;
    Pengaturan: Record<string, string>;
    Kredensial: Record<string, string>;
};

function IsiAwalPengaturan(bidang: BidangPengaturan[], tersimpan: Record<string, string | number> | null) {
    return Object.fromEntries(
        bidang.map((b) => [b.Kunci, String(tersimpan?.[b.Kunci] ?? b.Bawaan ?? b.Opsi?.[0] ?? '')]),
    );
}

function LabelOpsi(opsi: string) {
    return opsi.length <= 3 ? opsi.toUpperCase() : opsi;
}

function FormIntegrasiServer({ slot, saatSelesai }: { slot: SlotIntegrasiServer; saatSelesai: () => void }) {
    const konfigurasi = slot.Konfigurasi;
    const formulir = useForm<IsianIntegrasiServer>({
        Jenis: slot.Jenis,
        Penyedia: slot.Penyedia.Nilai,
        Pengaturan: IsiAwalPengaturan(slot.BidangPengaturan, konfigurasi?.Pengaturan ?? null),
        Kredensial: Object.fromEntries(slot.BidangKredensial.map((bidang) => [bidang.Kunci, ''])),
    });
    const penyedia = slot.DaftarPenyedia.find((p) => p.Nilai === formulir.data.Penyedia) ?? slot.DaftarPenyedia[0];
    // Kredensial tersimpan hanya berlaku untuk penyedia yang sama (ganti penyedia = isi ulang).
    const penyediaTersimpan = konfigurasi !== null && penyedia?.Nilai === slot.Penyedia.Nilai;
    const galat = formulir.errors as Record<string, string | undefined>;
    const GantiPenyedia = (nilai: string) => {
        const baru = slot.DaftarPenyedia.find((p) => p.Nilai === nilai);
        if (!baru) {
            return;
        }
        const sama = konfigurasi !== null && nilai === slot.Penyedia.Nilai;
        formulir.setData({
            ...formulir.data,
            Penyedia: nilai,
            Pengaturan: IsiAwalPengaturan(baru.BidangPengaturan, sama ? konfigurasi.Pengaturan : null),
            Kredensial: Object.fromEntries(baru.BidangKredensial.map((bidang) => [bidang.Kunci, ''])),
        });
    };
    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        formulir.post('/kelola/pengaturan/integrasi-server', { preserveScroll: true, onSuccess: saatSelesai });
    };

    return (
        <Sheet
            open
            onOpenChange={(terbuka) => {
                if (!terbuka) {
                    saatSelesai();
                }
            }}
        >
            <SheetContent className="w-full overflow-y-auto sm:max-w-xl">
                <SheetHeader>
                    <SheetTitle className="text-subjudul text-teks-utama">
                        {konfigurasi ? 'Ubah' : 'Atur'} {slot.LabelJenis}
                    </SheetTitle>
                    <SheetDescription>
                        Pilih penyedia lalu isi data akun Anda. Kredensial disimpan terenkripsi dan tidak pernah
                        ditampilkan lagi.
                    </SheetDescription>
                </SheetHeader>
                {galat.Umum ? (
                    <div className="px-4">
                        <Pemberitahuan jenis="bahaya">{galat.Umum}</Pemberitahuan>
                    </div>
                ) : null}
                <form onSubmit={Kirim} className="grid gap-3 px-4 sm:grid-cols-2" noValidate>
                    <div className="sm:col-span-2">
                        <BidangPilihan
                            label="Penyedia"
                            nilai={formulir.data.Penyedia}
                            opsi={slot.DaftarPenyedia.map((p) => ({ Nilai: p.Nilai, Label: p.Label }))}
                            saatBerubah={GantiPenyedia}
                            galat={galat.Penyedia}
                            required
                        />
                    </div>
                    {penyedia?.Keterangan ? (
                        <div className="sm:col-span-2">
                            <Pemberitahuan jenis={penyedia.Resmi ? 'info' : 'peringatan'}>
                                {penyedia.Keterangan}
                            </Pemberitahuan>
                        </div>
                    ) : null}
                    {penyedia?.BidangPengaturan.map((bidang) =>
                        bidang.Jenis === 'Pilihan' ? (
                            <BidangPilihan
                                key={bidang.Kunci}
                                label={bidang.Label}
                                nilai={formulir.data.Pengaturan[bidang.Kunci] ?? ''}
                                opsi={(bidang.Opsi ?? []).map((opsi) => ({ Nilai: opsi, Label: LabelOpsi(opsi) }))}
                                saatBerubah={(nilai) =>
                                    formulir.setData('Pengaturan', {
                                        ...formulir.data.Pengaturan,
                                        [bidang.Kunci]: nilai,
                                    })
                                }
                                galat={galat[`Pengaturan.${bidang.Kunci}`]}
                                required={bidang.Wajib}
                            />
                        ) : (
                            <BidangTeks
                                key={bidang.Kunci}
                                label={bidang.Label}
                                {...(bidang.Keterangan ? { keterangan: bidang.Keterangan } : {})}
                                jenis={bidang.Jenis === 'Email' ? 'email' : 'text'}
                                inputMode={bidang.Jenis === 'Angka' ? 'numeric' : undefined}
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
                        ),
                    )}
                    {penyedia?.BidangKredensial.map((bidang) => (
                        <BidangTeks
                            key={bidang.Kunci}
                            label={bidang.Label}
                            jenis="password"
                            autoComplete="new-password"
                            keterangan={
                                penyediaTersimpan && konfigurasi.PetunjukKredensial[bidang.Kunci]
                                    ? `Tersimpan ${konfigurasi.PetunjukKredensial[bidang.Kunci]}. Kosongkan bila tidak diganti.`
                                    : bidang.Wajib
                                      ? 'Wajib diisi.'
                                      : 'Opsional.'
                            }
                            nilai={formulir.data.Kredensial[bidang.Kunci] ?? ''}
                            saatBerubah={(nilai) =>
                                formulir.setData('Kredensial', { ...formulir.data.Kredensial, [bidang.Kunci]: nilai })
                            }
                            galat={galat[`Kredensial.${bidang.Kunci}`]}
                            required={bidang.Wajib && !penyediaTersimpan}
                        />
                    ))}
                    <p className="text-keterangan text-teks-sekunder sm:col-span-2">
                        Setelah disimpan, integrasi nonaktif sampai uji koneksi berhasil.
                    </p>
                    <SheetFooter className="flex-row px-0 sm:col-span-2">
                        <Tombol type="submit" memproses={formulir.processing}>
                            Simpan pengaturan
                        </Tombol>
                        <Tombol varian="sekunder" onClick={saatSelesai}>
                            Batal
                        </Tombol>
                    </SheetFooter>
                </form>
            </SheetContent>
        </Sheet>
    );
}
