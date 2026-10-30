import { Link, router, usePage } from '@inertiajs/react';
import { ChevronDownIcon, PlusIcon, XIcon } from 'lucide-react';
import { useState } from 'react';

import DialogIsiMassal from '@/Komponen/Karyawan/Jadwal/DialogIsiMassal';
import DialogKebutuhanStaf from '@/Komponen/Karyawan/Jadwal/DialogKebutuhanStaf';
import DialogPolaJadwal from '@/Komponen/Karyawan/Jadwal/DialogPolaJadwal';
import DialogShiftKerja from '@/Komponen/Karyawan/Jadwal/DialogShiftKerja';
import PanelPerhatianJadwal from '@/Komponen/Karyawan/Jadwal/PanelPerhatianJadwal';
import BidangOutlet from '@/Komponen/Formulir/BidangOutlet';
import Tombol from '@/Komponen/Formulir/Tombol';
import DaftarGalatServer from '@/Komponen/Katalog/DaftarGalatServer';
import PesanHanyaLihat from '@/Komponen/Katalog/PesanHanyaLihat';
import { Button } from '@/Komponen/Ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/Komponen/Ui/dropdown-menu';
import PemilihJam from '@/Komponen/Tanggal/PemilihJam';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import {
    AdaGalatJam,
    BarisKosong,
    BarisTerisi,
    BuatIsian,
    CekJam,
    GeserTanggal,
    LabelShift,
    NamaHari,
    SusunPilihanJam,
    SusunSelBerubah,
    type BarisIsian,
    type Isian,
} from '@/Fitur/Karyawan/LogikaJadwal';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';
import type { PropsJadwalKerja } from '@/Tipe/Karyawan';

const alamat = '/kelola/karyawan/jadwal';

type Dialog = 'shift' | 'massal' | 'pola' | 'kebutuhan' | null;

/**
 * F-18 EMP-02, D-251: jadwal kerja mingguan per outlet (Senin–Minggu). Satu hari boleh punya beberapa shift, shift bisa
 * dipasang dari daftar shift bernama, diisi massal, atau ditarik dari pola berulang. Di atas jadwal tampil jam yang
 * kurang staf dan permintaan tukar shift. Jadwal bisa dikirim ke karyawan lewat WhatsApp.
 */
export default function HalamanJadwalKerja({ OpsiOutlet, UuidOutlet, Senin, Jadwal, Izin }: PropsJadwalKerja) {
    const { props } = usePage<PropsBersamaAplikasi>();
    const [isian, AturIsian] = useState<Isian>(() => BuatIsian(Jadwal.Baris));
    // Jadwal dari server berubah (isi massal, salin, pola, tukar shift, atau simpan berhasil): isian dimuat ulang dari server.
    // Dibandingkan menurut isinya, jadi simpan yang gagal (jadwal sama) tidak membuang ketikan pengguna.
    const jejakServer = JSON.stringify(Jadwal.Baris);
    const [jejakTerakhir, AturJejakTerakhir] = useState(jejakServer);

    if (jejakTerakhir !== jejakServer) {
        AturJejakTerakhir(jejakServer);
        AturIsian(BuatIsian(Jadwal.Baris));
    }

    const [dialog, AturDialog] = useState<Dialog>(null);
    const [memproses, AturMemproses] = useState(false);
    const pilihanMulai = SusunPilihanJam(isian, 'JamMulai');
    const pilihanSelesai = SusunPilihanJam(isian, 'JamSelesai');
    const berubah = SusunSelBerubah(Jadwal.Baris, isian);
    const galatLokal = AdaGalatJam(berubah);
    const shiftAktif = Jadwal.ShiftKerja.filter((s) => s.Aktif);
    const namaShift = new Map(Jadwal.ShiftKerja.map((s) => [s.Uuid, s.Nama]));
    const Buka = (uuidOutlet: string | null, senin: string) =>
        router.get(alamat, { ...(uuidOutlet ? { outlet: uuidOutlet } : {}), minggu: senin }, { preserveState: false });
    const KirimAksi = (jalur: string) =>
        router.post(`${alamat}/${jalur}`, { UuidOutlet, Senin }, { preserveScroll: true });
    const UbahSel = (uuid: string, tanggal: string, olah: (baris: BarisIsian[]) => BarisIsian[]) =>
        AturIsian((lama) => {
            const baris = olah(lama[uuid]?.[tanggal] ?? [BarisKosong()]);

            return { ...lama, [uuid]: { ...lama[uuid], [tanggal]: baris.length === 0 ? [BarisKosong()] : baris } };
        });
    const UbahJam = (uuid: string, tanggal: string, indeks: number, kolom: 'JamMulai' | 'JamSelesai', nilai: string) =>
        UbahSel(uuid, tanggal, (baris) =>
            baris.map((b, i) => (i === indeks ? { ...b, [kolom]: nilai, UuidShift: null } : b)),
        );
    const TambahBaris = (uuid: string, tanggal: string, baru: BarisIsian) =>
        UbahSel(uuid, tanggal, (baris) => [...BarisTerisi(baris), baru]);
    const HapusBaris = (uuid: string, tanggal: string, indeks: number) =>
        UbahSel(uuid, tanggal, (baris) => baris.filter((_, i) => i !== indeks));
    const Simpan = () =>
        router.put(
            alamat,
            { UuidOutlet, Senin, Sel: berubah },
            { preserveScroll: true, onStart: () => AturMemproses(true), onFinish: () => AturMemproses(false) },
        );
    const Tutup = () => AturDialog(null);

    return (
        <TataLetakAplikasi judul="Jadwal kerja">
            <p className="max-w-3xl text-isi text-teks-sekunder">
                Isi jam kerja per hari: ketik (misal 830 → 08:30), klik isian untuk memilih, atau pasang shift dengan
                tombol Tambah. Satu hari boleh punya beberapa shift. Kosongkan untuk libur. Jam selesai lebih kecil dari
                jam mulai berarti shift lewat tengah malam. Keterlambatan di rekap absensi dihitung dari jadwal ini.
            </p>
            {!Izin.Kelola ? <PesanHanyaLihat izin="karyawan.kelola" objek="jadwal kerja" /> : null}
            <DaftarGalatServer galat={props.errors} />

            <div className="flex flex-wrap items-end gap-3">
                <div className="w-full sm:w-64">
                    <BidangOutlet
                        nilai={UuidOutlet ?? ''}
                        opsi={OpsiOutlet.map((o) => ({ Nilai: o.Uuid, Label: o.Nama }))}
                        saatBerubah={(uuid) => Buka(uuid, Senin)}
                    />
                </div>
                <div className="flex flex-wrap items-center gap-2">
                    <Button variant="outline" onClick={() => Buka(UuidOutlet, GeserTanggal(Senin, -7))}>
                        Minggu sebelumnya
                    </Button>
                    <span className="text-isi font-semibold tabular-nums">
                        {Senin} s.d. {GeserTanggal(Senin, 6)}
                    </span>
                    <Button variant="outline" onClick={() => Buka(UuidOutlet, GeserTanggal(Senin, 7))}>
                        Minggu berikutnya
                    </Button>
                </div>
            </div>

            {Izin.Kelola && UuidOutlet ? (
                <div className="flex flex-wrap items-center gap-2">
                    <Button variant="outline" onClick={() => AturDialog('massal')}>
                        Isi massal
                    </Button>
                    <Button variant="outline" onClick={() => KirimAksi('salin')}>
                        Salin minggu lalu
                    </Button>
                    <Button variant="outline" onClick={() => KirimAksi('terapkan-pola')}>
                        Terapkan pola
                    </Button>
                    <Button variant="outline" onClick={() => KirimAksi('umumkan')}>
                        Kirim ke karyawan
                    </Button>
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <Button variant="outline">
                                Atur
                                <ChevronDownIcon aria-hidden="true" className="size-4" />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="start">
                            <DropdownMenuItem onSelect={() => AturDialog('shift')}>Shift kerja</DropdownMenuItem>
                            <DropdownMenuItem onSelect={() => AturDialog('pola')}>Pola berulang</DropdownMenuItem>
                            <DropdownMenuItem onSelect={() => AturDialog('kebutuhan')}>Kebutuhan staf</DropdownMenuItem>
                            <DropdownMenuItem asChild>
                                <Link href="/kelola/karyawan/cuti">Cuti dan izin</Link>
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>
            ) : (
                <div>
                    <Button asChild variant="outline">
                        <Link href="/kelola/karyawan/cuti">Cuti & izin</Link>
                    </Button>
                </div>
            )}

            <PanelPerhatianJadwal
                alamat={alamat}
                kekurangan={Jadwal.Kekurangan}
                tukarShift={Jadwal.TukarShift}
                bolehKelola={Izin.Kelola}
            />

            {Jadwal.Baris.length === 0 ? (
                <Pemberitahuan jenis="info" judul="Belum ada karyawan di outlet ini">
                    Tambahkan karyawan dengan outlet utama ini di menu Tim.
                </Pemberitahuan>
            ) : (
                <ul className="flex flex-col gap-3" aria-label="Jadwal per karyawan">
                    {Jadwal.Baris.map((b) => (
                        <li key={b.Uuid} className="rounded-panel border border-garis bg-permukaan p-3">
                            <p className="font-semibold break-words">
                                {b.Nama}
                                {b.Jabatan ? (
                                    <span className="font-normal text-teks-sekunder"> | {b.Jabatan}</span>
                                ) : null}
                                {!b.Aktif ? <span className="font-normal text-teks-sekunder"> | nonaktif</span> : null}
                            </p>
                            <div className="mt-2 grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-7">
                                {Jadwal.Hari.map((tanggal, i) => {
                                    const awal = b.Jadwal[tanggal] ?? [];
                                    const outletLain = awal.filter((s) => s.OutletLain);
                                    const baris = isian[b.Uuid]?.[tanggal] ?? [BarisKosong()];
                                    const kunci = !Izin.Kelola || outletLain.length > 0 || !b.Aktif;
                                    const hari = NamaHari[i] ?? '';

                                    return (
                                        <fieldset
                                            key={tanggal}
                                            className="min-w-0 rounded-panel border border-garis p-2"
                                        >
                                            <legend className="px-1 text-label text-teks-sekunder">
                                                {hari} {tanggal.slice(8, 10)}/{tanggal.slice(5, 7)}
                                            </legend>
                                            {b.Cuti[tanggal] ? (
                                                <p className="mb-1 text-keterangan font-semibold text-teks-utama">
                                                    {b.Cuti[tanggal].Jenis}
                                                    {b.Cuti[tanggal].Berbayar ? '' : ' | tidak digaji'}
                                                </p>
                                            ) : null}
                                            {outletLain.length > 0 ? (
                                                <p className="text-keterangan text-teks-sekunder">
                                                    Di outlet lain{' '}
                                                    {outletLain.map((s) => `${s.JamMulai}–${s.JamSelesai}`).join(', ')}
                                                </p>
                                            ) : (
                                                <div className="flex flex-col gap-1.5">
                                                    {baris.map((sel, n) => {
                                                        const nomor = n === 0 ? '' : ` ${n + 1}`;
                                                        const nama = sel.UuidShift
                                                            ? namaShift.get(sel.UuidShift)
                                                            : null;

                                                        return (
                                                            <div key={n} className="flex flex-col gap-0.5">
                                                                {nama ? (
                                                                    <span className="text-keterangan font-semibold text-teks-sekunder">
                                                                        {nama}
                                                                    </span>
                                                                ) : null}
                                                                <div className="flex items-center gap-1">
                                                                    {(['JamMulai', 'JamSelesai'] as const).map(
                                                                        (kolom) => (
                                                                            <PemilihJam
                                                                                key={kolom}
                                                                                label={`${b.Nama} ${hari} ${kolom === 'JamMulai' ? 'mulai' : 'selesai'}${nomor}`}
                                                                                labelTersembunyi
                                                                                ringkas
                                                                                contoh={
                                                                                    kolom === 'JamMulai'
                                                                                        ? '08:00'
                                                                                        : '16:00'
                                                                                }
                                                                                langkahMenit={15}
                                                                                pilihanCepat={
                                                                                    kolom === 'JamMulai'
                                                                                        ? pilihanMulai
                                                                                        : pilihanSelesai
                                                                                }
                                                                                disabled={kunci}
                                                                                nilai={sel[kolom]}
                                                                                galat={
                                                                                    sel[kolom] !== '' &&
                                                                                    !CekJam(sel[kolom])
                                                                                        ? 'Format JJ:MM'
                                                                                        : undefined
                                                                                }
                                                                                saatBerubah={(baru) =>
                                                                                    UbahJam(
                                                                                        b.Uuid,
                                                                                        tanggal,
                                                                                        n,
                                                                                        kolom,
                                                                                        baru,
                                                                                    )
                                                                                }
                                                                                className="flex-1 [&_[data-slot=input-group]]:h-11"
                                                                            />
                                                                        ),
                                                                    )}
                                                                    {!kunci &&
                                                                    (baris.length > 1 ||
                                                                        sel.JamMulai !== '' ||
                                                                        sel.JamSelesai !== '') ? (
                                                                        <Button
                                                                            variant="ghost"
                                                                            size="icon"
                                                                            aria-label={`Hapus shift${nomor} ${b.Nama} ${hari}`}
                                                                            onClick={() =>
                                                                                HapusBaris(b.Uuid, tanggal, n)
                                                                            }
                                                                        >
                                                                            <XIcon
                                                                                aria-hidden="true"
                                                                                className="size-4"
                                                                            />
                                                                        </Button>
                                                                    ) : null}
                                                                </div>
                                                            </div>
                                                        );
                                                    })}
                                                    {!kunci ? (
                                                        <DropdownMenu>
                                                            <DropdownMenuTrigger asChild>
                                                                <Button
                                                                    variant="ghost"
                                                                    size="sm"
                                                                    className="self-start"
                                                                    aria-label={`Tambah shift ${b.Nama} ${hari}`}
                                                                >
                                                                    <PlusIcon aria-hidden="true" className="size-4" />
                                                                    Tambah
                                                                </Button>
                                                            </DropdownMenuTrigger>
                                                            <DropdownMenuContent align="start">
                                                                {shiftAktif.map((s) => (
                                                                    <DropdownMenuItem
                                                                        key={s.Uuid}
                                                                        onSelect={() =>
                                                                            TambahBaris(b.Uuid, tanggal, {
                                                                                JamMulai: s.JamMulai,
                                                                                JamSelesai: s.JamSelesai,
                                                                                UuidShift: s.Uuid,
                                                                            })
                                                                        }
                                                                    >
                                                                        {LabelShift(s)}
                                                                    </DropdownMenuItem>
                                                                ))}
                                                                <DropdownMenuItem
                                                                    onSelect={() =>
                                                                        UbahSel(b.Uuid, tanggal, (lama) => [
                                                                            ...lama,
                                                                            BarisKosong(),
                                                                        ])
                                                                    }
                                                                >
                                                                    Jam sendiri
                                                                </DropdownMenuItem>
                                                            </DropdownMenuContent>
                                                        </DropdownMenu>
                                                    ) : null}
                                                </div>
                                            )}
                                        </fieldset>
                                    );
                                })}
                            </div>
                        </li>
                    ))}
                </ul>
            )}

            {Izin.Kelola && Jadwal.Baris.length > 0 ? (
                <div className="flex flex-wrap items-center gap-3">
                    <Tombol
                        type="button"
                        memproses={memproses}
                        disabled={berubah.length === 0 || galatLokal}
                        onClick={Simpan}
                    >
                        Simpan jadwal
                    </Tombol>
                    <span className="text-keterangan text-teks-sekunder">
                        {galatLokal
                            ? 'Periksa jam yang ditandai: format JJ:MM, isi keduanya atau kosongkan keduanya.'
                            : berubah.length === 0
                              ? 'Belum ada perubahan.'
                              : `${berubah.length} hari berubah.`}
                    </span>
                </div>
            ) : null}

            {UuidOutlet && dialog === 'shift' ? (
                <DialogShiftKerja alamat={alamat} shift={Jadwal.ShiftKerja} saatTutup={Tutup} />
            ) : null}
            {UuidOutlet && dialog === 'massal' ? (
                <DialogIsiMassal
                    alamat={alamat}
                    uuidOutlet={UuidOutlet}
                    senin={Senin}
                    hari={Jadwal.Hari}
                    karyawan={Jadwal.Baris}
                    shift={Jadwal.ShiftKerja}
                    saatTutup={Tutup}
                />
            ) : null}
            {UuidOutlet && dialog === 'pola' ? (
                <DialogPolaJadwal
                    alamat={alamat}
                    uuidOutlet={UuidOutlet}
                    karyawan={Jadwal.Baris}
                    shift={Jadwal.ShiftKerja}
                    saatTutup={Tutup}
                />
            ) : null}
            {UuidOutlet && dialog === 'kebutuhan' ? (
                <DialogKebutuhanStaf
                    alamat={alamat}
                    uuidOutlet={UuidOutlet}
                    kebutuhan={Jadwal.Kebutuhan}
                    saatTutup={Tutup}
                />
            ) : null}
        </TataLetakAplikasi>
    );
}
