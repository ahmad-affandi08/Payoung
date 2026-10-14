import { router, useForm, usePage } from '@inertiajs/react';
import { useMemo, useState, type FormEvent } from 'react';

import AksiHalaman from '@/Komponen/Kelola/AksiHalaman';
import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import BidangTeksPanjang from '@/Komponen/Formulir/BidangTeksPanjang';
import GrupCentang from '@/Komponen/Formulir/GrupCentang';
import Tombol from '@/Komponen/Formulir/Tombol';
import DialogFormulir from '@/Komponen/Tindakan/DialogFormulir';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { KolomTabel } from '@/Komponen/TabelData/Tipe';
import { Card } from '@/Komponen/Ui/card';
import { DropdownMenuItem, DropdownMenuSeparator } from '@/Komponen/Ui/dropdown-menu';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { FormatTanggalWaktu } from '@/Pustaka/FormatWaktu';
import TataLetakPengelola from '@/TataLetak/TataLetakPengelola';
import { IzinPengelola, PunyaIzin, type PropsBersamaPengelola } from '@/Tipe/Pengelola';

type Anggota = {
    Uuid: string;
    Nama: string;
    Email: string;
    KodePeran: string[];
    Aktif: boolean;
    DuaFaktorAktif: boolean;
    WajibGantiKataSandi: boolean;
    TerakhirMasukPada: string | null;
    DinonaktifkanPada: string | null;
    JumlahPerangkatTepercaya: number;
};

type Undangan = { Uuid: string; Email: string; KodePeran: string[]; BerlakuSampai: string };

type Peran = { Kode: string; Nama: string };

type BatasanIp = { Daftar: string[]; IpAnda: string };

type PropsDaftar = { Anggota: Anggota[]; Undangan: Undangan[]; Peran: Peran[]; BatasanIp?: BatasanIp | null };

type Pilihan = { jenis: 'peran' | 'nonaktifkan'; anggota: Anggota } | null;

function BuatKolom(namaPeran: Map<string, string>): KolomTabel<Anggota>[] {
    return [
        {
            id: 'Nama',
            accessorKey: 'Nama',
            header: 'Nama',
            meta: { label: 'Nama', prioritas: 'utama', wajib: true },
            cell: ({ row: { original: anggota } }) => (
                <>
                    <span className="block font-semibold text-teks-utama">{anggota.Nama}</span>
                    <span className="block text-keterangan font-normal break-all text-teks-sekunder">
                        {anggota.Email}
                    </span>
                </>
            ),
        },
        {
            id: 'Peran',
            header: 'Peran',
            enableSorting: false,
            meta: { label: 'Peran', prioritas: 'penting', kelasSel: 'text-teks-utama' },
            cell: ({ row }) => row.original.KodePeran.map((kode) => namaPeran.get(kode) ?? kode).join(', '),
        },
        {
            id: 'Aktif',
            accessorKey: 'Aktif',
            header: 'Status',
            meta: { label: 'Status', prioritas: 'penting' },
            cell: ({ row: { original: anggota } }) => (
                <div className="flex flex-wrap gap-1">
                    {anggota.Aktif ? (
                        <LabelStatus jenis="sukses" teks="Aktif" />
                    ) : (
                        <LabelStatus jenis="netral" teks="Nonaktif" />
                    )}
                    {anggota.WajibGantiKataSandi ? (
                        <LabelStatus jenis="peringatan" teks="Belum mengganti kata sandi awal" />
                    ) : null}
                    {anggota.DuaFaktorAktif ? null : (
                        <LabelStatus jenis="peringatan" teks="Verifikasi dua langkah belum aktif" />
                    )}
                    {anggota.JumlahPerangkatTepercaya > 0 ? (
                        <LabelStatus jenis="netral" teks={`${anggota.JumlahPerangkatTepercaya} perangkat tepercaya`} />
                    ) : null}
                    {anggota.Aktif ? null : (
                        <span className="block w-full text-keterangan text-teks-sekunder">
                            Dinonaktifkan {FormatTanggalWaktu(anggota.DinonaktifkanPada)}
                        </span>
                    )}
                </div>
            ),
        },
        {
            id: 'TerakhirMasukPada',
            accessorKey: 'TerakhirMasukPada',
            header: 'Terakhir masuk',
            meta: { label: 'Terakhir masuk', prioritas: 'rendah', kelasSel: 'text-teks-sekunder' },
            cell: ({ row }) => FormatTanggalWaktu(row.original.TerakhirMasukPada),
        },
    ];
}

/** Manajemen tim internal (P-01 langkah 3, 5, 6), TabelData D-16. */
export default function Daftar({ Anggota, Undangan, Peran, BatasanIp = null }: PropsDaftar) {
    const { props } = usePage<PropsBersamaPengelola>();
    const pengguna = props.Pengguna;
    const [formTerbuka, AturFormTerbuka] = useState<'tambah' | 'undang' | 'ip' | null>(null);
    const [pilihan, AturPilihan] = useState<Pilihan>(null);
    const namaPeran = new Map(Peran.map((peran) => [peran.Kode, peran.Nama]));
    const opsiPeran = Peran.map((peran) => ({ nilai: peran.Kode, label: peran.Nama }));
    const TampilkanPeran = (kode: string[]) => kode.map((item) => namaPeran.get(item) ?? item).join(', ');

    const bolehUbahPeran = PunyaIzin(pengguna, IzinPengelola.TimPeranTetapkan);
    const bolehNonaktifkan = PunyaIzin(pengguna, IzinPengelola.TimAnggotaNonaktifkan);
    const kolom = useMemo(() => BuatKolom(new Map(Peran.map((peran) => [peran.Kode, peran.Nama]))), [Peran]);

    return (
        <TataLetakPengelola judul="Tim internal">
            {formTerbuka === 'tambah' ? (
                <FormTambah opsiPeran={opsiPeran} saatSelesai={() => AturFormTerbuka(null)} />
            ) : null}
            {formTerbuka === 'undang' ? (
                <FormUndangan opsiPeran={opsiPeran} saatSelesai={() => AturFormTerbuka(null)} />
            ) : null}
            {formTerbuka === 'ip' && BatasanIp !== null ? (
                <FormBatasanIp batasan={BatasanIp} saatSelesai={() => AturFormTerbuka(null)} />
            ) : null}

            {pilihan?.jenis === 'peran' ? (
                <FormPeran
                    key={pilihan.anggota.Uuid}
                    anggota={pilihan.anggota}
                    opsiPeran={opsiPeran}
                    saatSelesai={() => AturPilihan(null)}
                />
            ) : null}
            {pilihan?.jenis === 'nonaktifkan' ? (
                <FormNonaktifkan
                    key={pilihan.anggota.Uuid}
                    anggota={pilihan.anggota}
                    saatSelesai={() => AturPilihan(null)}
                />
            ) : null}

            {BatasanIp !== null ? (
                <p className="max-w-3xl text-isi text-teks-sekunder">
                    {BatasanIp.Daftar.length === 0
                        ? 'Konsol bisa dibuka dari alamat IP mana pun.'
                        : `Konsol hanya bisa dibuka dari ${String(BatasanIp.Daftar.length)} alamat/rentang IP terdaftar.`}{' '}
                    Alamat IP Anda saat ini <span className="font-mono">{BatasanIp.IpAnda}</span>.
                </p>
            ) : null}
            <AksiHalaman>
                {PunyaIzin(pengguna, IzinPengelola.TimAnggotaUndang) || BatasanIp !== null ? (
                    <div className="flex flex-wrap gap-2">
                        {BatasanIp !== null ? (
                            <Tombol varian="sekunder" onClick={() => AturFormTerbuka('ip')}>
                                Pembatasan IP
                            </Tombol>
                        ) : null}
                        {PunyaIzin(pengguna, IzinPengelola.TimAnggotaUndang) ? (
                            <>
                                <Tombol varian="sekunder" onClick={() => AturFormTerbuka('undang')}>
                                    Undang lewat email
                                </Tombol>
                                <Tombol onClick={() => AturFormTerbuka('tambah')}>Tambah anggota</Tombol>
                            </>
                        ) : null}
                    </div>
                ) : null}
            </AksiHalaman>
            <TabelData
                id="pengelola-tim-internal"
                label="Daftar anggota tim internal"
                kolom={kolom}
                sumber={{ mode: 'lokal', data: Anggota }}
                ambilIdBaris={(anggota) => anggota.Uuid}
                urutBawaan="Nama"
                cari="Cari nama atau email"
                saring={[
                    {
                        id: 'Aktif',
                        label: 'Status',
                        jenis: 'pilihanBanyak',
                        opsi: [
                            { nilai: 'true', label: 'Aktif' },
                            { nilai: 'false', label: 'Nonaktif' },
                        ],
                    },
                ]}
                {...(bolehUbahPeran || bolehNonaktifkan
                    ? {
                          aksiBaris: (anggota: Anggota) =>
                              anggota.Aktif ? (
                                  <>
                                      {bolehUbahPeran ? (
                                          <DropdownMenuItem onSelect={() => AturPilihan({ jenis: 'peran', anggota })}>
                                              Ubah peran
                                          </DropdownMenuItem>
                                      ) : null}
                                      {bolehNonaktifkan && anggota.JumlahPerangkatTepercaya > 0 ? (
                                          <DropdownMenuItem
                                              onSelect={() =>
                                                  router.post(
                                                      `/tim-internal/${anggota.Uuid}/cabut-perangkat`,
                                                      {},
                                                      { preserveScroll: true },
                                                  )
                                              }
                                          >
                                              Cabut perangkat tepercaya
                                          </DropdownMenuItem>
                                      ) : null}
                                      {bolehUbahPeran && bolehNonaktifkan ? <DropdownMenuSeparator /> : null}
                                      {bolehNonaktifkan ? (
                                          <DropdownMenuItem
                                              variant="destructive"
                                              onSelect={() => AturPilihan({ jenis: 'nonaktifkan', anggota })}
                                          >
                                              Nonaktifkan
                                          </DropdownMenuItem>
                                      ) : null}
                                  </>
                              ) : (
                                  <DropdownMenuItem disabled>Anggota sudah dinonaktifkan</DropdownMenuItem>
                              ),
                      }
                    : {})}
                kosong={{ ilustrasi: true, judul: 'Belum ada anggota tim internal. Tambahkan anggota pertama.' }}
            />

            <section className="flex flex-col gap-2">
                <h2 className="text-subjudul font-semibold text-teks-utama">Undangan menunggu</h2>
                {Undangan.length === 0 ? (
                    <p className="text-isi text-teks-sekunder">Tidak ada undangan yang menunggu diterima.</p>
                ) : (
                    <Card className="gap-0 py-0 rounded-panel shadow-none">
                        <ul className="divide-y divide-garis">
                            {Undangan.map((undangan) => (
                                <li
                                    key={undangan.Uuid}
                                    className="flex flex-wrap justify-between gap-2 px-4 py-3 text-isi"
                                >
                                    <span className="font-semibold text-teks-utama">{undangan.Email}</span>
                                    <span className="text-teks-sekunder">
                                        {TampilkanPeran(undangan.KodePeran)} | berlaku sampai{' '}
                                        {FormatTanggalWaktu(undangan.BerlakuSampai)}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </Card>
                )}
            </section>
        </TataLetakPengelola>
    );
}

type Opsi = { nilai: string; label: string }[];

/** D-22: tambah anggota langsung dengan kata sandi awal (tanpa email); wajib diganti saat pertama masuk. */
function FormTambah({ opsiPeran, saatSelesai }: { opsiPeran: Opsi; saatSelesai: () => void }) {
    const formulir = useForm<{ Nama: string; Email: string; KataSandi: string; KodePeran: string[] }>({
        Nama: '',
        Email: '',
        KataSandi: '',
        KodePeran: [],
    });

    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        formulir.post('/tim-internal', { preserveScroll: true, onSuccess: saatSelesai });
    };

    return (
        <DialogFormulir
            judul="Tambah anggota tim"
            keterangan={
                <p>
                    Berikan email dan kata sandi awal kepada anggota secara langsung. Saat pertama masuk ia wajib
                    mengganti kata sandi dan mengaktifkan verifikasi dua langkah.
                </p>
            }
            saatTutup={saatSelesai}
        >
            <form onSubmit={Kirim} className="flex flex-col gap-4" noValidate>
                <BidangTeks
                    label="Nama lengkap"
                    nilai={formulir.data.Nama}
                    saatBerubah={(nilai) => formulir.setData('Nama', nilai)}
                    galat={formulir.errors.Nama}
                    maxLength={150}
                    autoFocus
                    required
                />
                <BidangTeks
                    label="Email (untuk masuk)"
                    jenis="email"
                    nilai={formulir.data.Email}
                    saatBerubah={(nilai) => formulir.setData('Email', nilai)}
                    galat={formulir.errors.Email}
                    maxLength={191}
                    required
                />
                <BidangTeks
                    label="Kata sandi awal"
                    jenis="password"
                    keterangan="Minimal 12 karakter, berisi huruf dan angka. Wajib diganti anggota saat pertama masuk."
                    nilai={formulir.data.KataSandi}
                    saatBerubah={(nilai) => formulir.setData('KataSandi', nilai)}
                    galat={formulir.errors.KataSandi}
                    autoComplete="new-password"
                    required
                />
                <GrupCentang
                    legenda="Peran"
                    opsi={opsiPeran}
                    terpilih={formulir.data.KodePeran}
                    saatBerubah={(terpilih) => formulir.setData('KodePeran', terpilih)}
                    galat={formulir.errors.KodePeran}
                    required
                />
                <div className="flex flex-wrap gap-2">
                    <Tombol type="submit" memproses={formulir.processing}>
                        Tambah anggota
                    </Tombol>
                    <Tombol varian="sekunder" onClick={saatSelesai}>
                        Batal
                    </Tombol>
                </div>
            </form>
        </DialogFormulir>
    );
}

function FormUndangan({ opsiPeran, saatSelesai }: { opsiPeran: Opsi; saatSelesai: () => void }) {
    const formulir = useForm<{ Email: string; KodePeran: string[] }>({ Email: '', KodePeran: [] });

    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        formulir.post('/tim-internal/undangan', { preserveScroll: true, onSuccess: saatSelesai });
    };

    return (
        <DialogFormulir
            judul="Undang anggota tim"
            keterangan={
                <p>
                    Undangan dikirim lewat email, berlaku 48 jam, dan hanya bisa dipakai sekali. Anggota wajib
                    mengaktifkan verifikasi dua langkah.
                </p>
            }
            saatTutup={saatSelesai}
        >
            <form onSubmit={Kirim} className="flex flex-col gap-4" noValidate>
                <BidangTeks
                    label="Email"
                    jenis="email"
                    nilai={formulir.data.Email}
                    saatBerubah={(nilai) => formulir.setData('Email', nilai)}
                    galat={formulir.errors.Email}
                    autoFocus
                    required
                />
                <GrupCentang
                    legenda="Peran"
                    opsi={opsiPeran}
                    terpilih={formulir.data.KodePeran}
                    saatBerubah={(terpilih) => formulir.setData('KodePeran', terpilih)}
                    galat={formulir.errors.KodePeran}
                    required
                />
                <div className="flex flex-wrap gap-2">
                    <Tombol type="submit" memproses={formulir.processing}>
                        Kirim undangan
                    </Tombol>
                    <Tombol varian="sekunder" onClick={saatSelesai}>
                        Batal
                    </Tombol>
                </div>
            </form>
        </DialogFormulir>
    );
}

function FormPeran({
    anggota,
    opsiPeran,
    saatSelesai,
}: {
    anggota: Anggota;
    opsiPeran: Opsi;
    saatSelesai: () => void;
}) {
    const { props } = usePage<PropsBersamaPengelola>();
    const formulir = useForm<{ KodePeran: string[]; Alasan: string }>({ KodePeran: anggota.KodePeran, Alasan: '' });

    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        formulir.put(`/tim-internal/${anggota.Uuid}/peran`, { preserveScroll: true, onSuccess: saatSelesai });
    };

    return (
        <DialogFormulir judul={`Ubah peran ${anggota.Nama}`} saatTutup={saatSelesai}>
            <form onSubmit={Kirim} className="flex flex-col gap-4" noValidate>
                {props.errors.Umum ? <Pemberitahuan jenis="bahaya">{props.errors.Umum}</Pemberitahuan> : null}
                <GrupCentang
                    legenda="Peran (boleh lebih dari satu)"
                    opsi={opsiPeran}
                    terpilih={formulir.data.KodePeran}
                    saatBerubah={(terpilih) => formulir.setData('KodePeran', terpilih)}
                    galat={formulir.errors.KodePeran}
                    required
                />
                <BidangTeks
                    label="Alasan (opsional)"
                    nilai={formulir.data.Alasan}
                    saatBerubah={(nilai) => formulir.setData('Alasan', nilai)}
                    galat={formulir.errors.Alasan}
                    maxLength={500}
                />
                <div className="flex flex-wrap gap-2">
                    <Tombol type="submit" memproses={formulir.processing}>
                        Simpan peran
                    </Tombol>
                    <Tombol varian="sekunder" onClick={saatSelesai}>
                        Batal
                    </Tombol>
                </div>
            </form>
        </DialogFormulir>
    );
}

function FormNonaktifkan({ anggota, saatSelesai }: { anggota: Anggota; saatSelesai: () => void }) {
    const { props } = usePage<PropsBersamaPengelola>();
    const formulir = useForm({ Alasan: '' });

    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        formulir.post(`/tim-internal/${anggota.Uuid}/nonaktifkan`, { preserveScroll: true, onSuccess: saatSelesai });
    };

    return (
        <DialogFormulir
            jenis="konfirmasi"
            judul={`Nonaktifkan ${anggota.Nama}?`}
            keterangan={
                <p>
                    Sesinya langsung terputus dan ia tidak bisa masuk lagi. Akun tidak dihapus; riwayat audit tetap
                    tersimpan.
                </p>
            }
            saatTutup={saatSelesai}
        >
            <form onSubmit={Kirim} className="flex flex-col gap-4" noValidate>
                {props.errors.Umum ? <Pemberitahuan jenis="bahaya">{props.errors.Umum}</Pemberitahuan> : null}
                <BidangTeks
                    label="Alasan"
                    nilai={formulir.data.Alasan}
                    saatBerubah={(nilai) => formulir.setData('Alasan', nilai)}
                    galat={formulir.errors.Alasan}
                    maxLength={500}
                    autoFocus
                    required
                />
                <div className="flex flex-wrap gap-2">
                    <Tombol type="submit" varian="bahaya" memproses={formulir.processing}>
                        Nonaktifkan akun
                    </Tombol>
                    <Tombol varian="sekunder" onClick={saatSelesai}>
                        Batal
                    </Tombol>
                </div>
            </form>
        </DialogFormulir>
    );
}

/** PGL-01 (D-146): daftar IP yang boleh membuka konsol. Kosong = tanpa pembatasan; IP sendiri wajib termasuk. */
function FormBatasanIp({ batasan, saatSelesai }: { batasan: BatasanIp; saatSelesai: () => void }) {
    const { props } = usePage<PropsBersamaPengelola>();
    const formulir = useForm({ Daftar: batasan.Daftar.join('\n'), Alasan: '' });

    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        formulir.put('/tim-internal/batasan-ip', { preserveScroll: true, onSuccess: saatSelesai });
    };

    return (
        <DialogFormulir
            judul="Pembatasan alamat IP konsol"
            keterangan={
                <p>
                    Satu alamat IP atau rentang CIDR (misal 203.0.113.0/24) per baris. Kosongkan untuk mematikan
                    pembatasan. Alamat Anda saat ini <span className="font-mono">{batasan.IpAnda}</span> wajib ada di
                    daftar. Bila seluruh tim terkunci, jalankan{' '}
                    <span className="font-mono">php artisan pengelola:kosongkan-allowlist-ip</span> di server.
                </p>
            }
            saatTutup={saatSelesai}
        >
            <form onSubmit={Kirim} className="flex flex-col gap-4" noValidate>
                {props.errors.Umum ? <Pemberitahuan jenis="bahaya">{props.errors.Umum}</Pemberitahuan> : null}
                <BidangTeksPanjang
                    label="Alamat IP yang diizinkan"
                    nilai={formulir.data.Daftar}
                    saatBerubah={(nilai) => formulir.setData('Daftar', nilai)}
                    galat={formulir.errors.Daftar}
                    maksimal={5000}
                />
                <BidangTeks
                    label="Alasan perubahan"
                    nilai={formulir.data.Alasan}
                    saatBerubah={(nilai) => formulir.setData('Alasan', nilai)}
                    galat={formulir.errors.Alasan}
                    maxLength={255}
                    required
                />
                <div className="flex flex-wrap gap-2">
                    <Tombol type="submit" memproses={formulir.processing}>
                        Simpan pembatasan
                    </Tombol>
                    <Tombol varian="sekunder" onClick={saatSelesai}>
                        Batal
                    </Tombol>
                </div>
            </form>
        </DialogFormulir>
    );
}
