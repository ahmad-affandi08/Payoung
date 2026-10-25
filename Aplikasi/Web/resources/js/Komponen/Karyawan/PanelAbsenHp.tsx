import { router } from '@inertiajs/react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';

import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import Tombol from '@/Komponen/Formulir/Tombol';
import { AlamatKaryawan } from '@/Komponen/Karyawan/FormulirKaryawan';
import DialogKonfirmasi from '@/Komponen/Tindakan/DialogKonfirmasi';
import { Button } from '@/Komponen/Ui/button';
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/Komponen/Ui/sheet';
import { Skeleton } from '@/Komponen/Ui/skeleton';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { FormatTanggalWaktu } from '@/Pustaka/FormatWaktu';
import { KunciKueri } from '@/Pustaka/KunciKueri';
import type { AbsenHpKaryawan, BarisKaryawan, StatusWajahKaryawan } from '@/Tipe/Karyawan';

async function AmbilAbsenHp(uuid: string, sinyal: AbortSignal): Promise<AbsenHpKaryawan> {
    const respons = await fetch(`${AlamatKaryawan}/${uuid}/absen-hp`, {
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
        signal: sinyal,
    });

    if (!respons.ok) {
        throw new Error(`Data absen HP gagal dimuat (${String(respons.status)})`);
    }

    return (await respons.json()) as AbsenHpKaryawan;
}

export function JenisLabelWajah(status: StatusWajahKaryawan | null): 'sukses' | 'peringatan' | 'bahaya' | 'netral' {
    return status === 'Disetujui'
        ? 'sukses'
        : status === 'Menunggu'
          ? 'peringatan'
          : status === 'Ditolak'
            ? 'bahaya'
            : 'netral';
}

type AksiBerisiko = 'buat-ulang' | 'cabut' | 'hapus';

/** Judul, akibat, dan label tombol aksi yang perlu dikonfirmasi. */
const isiKonfirmasi: Record<AksiBerisiko, { judul: string; akibat: string; label: string }> = {
    'buat-ulang': {
        judul: 'Buat ulang tautan absen?',
        akibat: 'Tautan lama langsung tidak berlaku. Kirim tautan baru ke karyawan supaya bisa absen lagi.',
        label: 'Buat ulang tautan',
    },
    cabut: {
        judul: 'Cabut tautan absen?',
        akibat: 'Karyawan tidak bisa absen lewat tautan sampai Anda membuat tautan baru.',
        label: 'Cabut tautan',
    },
    hapus: {
        judul: 'Atur ulang wajah?',
        akibat: 'Wajah terdaftar dan fotonya dihapus. Karyawan harus mendaftarkan wajah lagi sebelum bisa absen.',
        label: 'Hapus wajah',
    },
};

/**
 * F-18 bagian 4 (D-37, D-180, D-198): panel absen satu karyawan (`karyawan.kelola`). Karyawan absen setelah login atau
 * lewat tautan pribadinya (buat ulang, salin, kirim lewat WhatsApp, cabut) dan mendaftarkan wajahnya sendiri di halaman
 * Absen; di sini pengelola melihat 3 foto pendaftaran, bisa menolaknya dengan alasan, dan mengatur ulang wajah supaya
 * karyawan mendaftar lagi.
 */
export default function PanelAbsenHp({ karyawan, saatTutup }: { karyawan: BarisKaryawan; saatTutup: () => void }) {
    const klien = useQueryClient();
    const kunci = KunciKueri.Karyawan.AbsenHp(karyawan.Uuid);
    const kueri = useQuery({ queryKey: kunci, queryFn: ({ signal }) => AmbilAbsenHp(karyawan.Uuid, signal) });
    const [memproses, AturMemproses] = useState<string | null>(null);
    const [tolak, AturTolak] = useState(false);
    const [alasan, AturAlasan] = useState('');
    const [tersalin, AturTersalin] = useState(false);
    const [konfirmasi, AturKonfirmasi] = useState<AksiBerisiko | null>(null);
    const alamat = `${AlamatKaryawan}/${karyawan.Uuid}`;
    const data = kueri.data;

    const Kirim = (
        aksi: string,
        metode: 'post' | 'delete',
        url: string,
        isi: Record<string, string | boolean> = {},
    ) => {
        AturMemproses(aksi);
        const opsi = {
            preserveScroll: true,
            onSuccess: () => {
                AturTolak(false);
                AturKonfirmasi(null);
                AturAlasan('');
                void klien.invalidateQueries({ queryKey: kunci });
            },
            onFinish: () => AturMemproses(null),
        };

        if (metode === 'post') {
            router.post(url, isi, opsi);
        } else {
            router.delete(url, opsi);
        }
    };

    const Salin = async (teks: string) => {
        try {
            await navigator.clipboard.writeText(teks);
            AturTersalin(true);
        } catch {
            AturTersalin(false);
        }
    };

    return (
        <Sheet open onOpenChange={(terbuka) => (terbuka ? undefined : saatTutup())}>
            <SheetContent className="w-full overflow-y-auto sm:max-w-lg">
                <SheetHeader>
                    <SheetTitle className="text-subjudul text-teks-utama">Absen dari HP | {karyawan.Nama}</SheetTitle>
                    <SheetDescription>
                        Karyawan masuk dengan akunnya atau membuka tautan pribadinya di HP, mendaftarkan wajah sekali,
                        lalu absen dengan pencocokan wajah. Wajah yang tidak cocok ditolak.
                    </SheetDescription>
                </SheetHeader>

                <div className="flex flex-col gap-5 px-4 pb-6">
                    {kueri.isPending ? (
                        <div role="status" aria-label="Memuat data absen HP" className="flex flex-col gap-3">
                            <Skeleton className="h-4 w-32" />
                            <Skeleton className="h-10 w-full" />
                            <Skeleton className="h-4 w-40" />
                            <div className="grid grid-cols-3 gap-2">
                                <Skeleton className="aspect-square w-full" />
                                <Skeleton className="aspect-square w-full" />
                                <Skeleton className="aspect-square w-full" />
                            </div>
                        </div>
                    ) : null}
                    {kueri.isError ? (
                        <Pemberitahuan jenis="bahaya">Data absen HP gagal dimuat. Tutup lalu buka lagi.</Pemberitahuan>
                    ) : null}

                    {data ? (
                        <>
                            <section className="flex flex-col gap-3" aria-labelledby="judul-tautan-absen">
                                <h3 id="judul-tautan-absen" className="text-label font-semibold text-teks-utama">
                                    Tautan absen
                                </h3>
                                {data.Tautan ? (
                                    <>
                                        <p className="break-all rounded-panel border border-garis bg-permukaan-redup px-3 py-2 font-mono text-keterangan text-teks-utama">
                                            {data.Tautan}
                                        </p>
                                        <p className="text-keterangan text-teks-sekunder">
                                            Dibuat {FormatTanggalWaktu(data.TautanDibuatPada)}. Rahasiakan: siapa pun
                                            yang memegang tautan ini bisa mencoba absen atas nama {karyawan.Nama}
                                            (wajah dan lokasinya tetap diperiksa).
                                        </p>
                                        <div className="flex flex-wrap gap-2">
                                            <Tombol varian="sekunder" onClick={() => void Salin(data.Tautan ?? '')}>
                                                {tersalin ? 'Tersalin' : 'Salin tautan'}
                                            </Tombol>
                                            <Button
                                                asChild
                                                variant="outline"
                                                className="h-8 px-4 text-label font-semibold pointer-coarse:h-11"
                                            >
                                                <a
                                                    href={`https://wa.me/?text=${encodeURIComponent(`Tautan absen ${karyawan.Nama}: ${data.Tautan} (simpan ke layar utama HP, jangan dibagikan)`)}`}
                                                    target="_blank"
                                                    rel="noreferrer"
                                                >
                                                    Kirim lewat WhatsApp
                                                </a>
                                            </Button>
                                            <Tombol
                                                varian="sekunder"
                                                disabled={memproses !== null}
                                                onClick={() => AturKonfirmasi('buat-ulang')}
                                            >
                                                Buat ulang tautan
                                            </Tombol>
                                            <Tombol
                                                varian="bahaya"
                                                disabled={memproses !== null}
                                                onClick={() => AturKonfirmasi('cabut')}
                                            >
                                                Cabut tautan
                                            </Tombol>
                                        </div>
                                    </>
                                ) : (
                                    <>
                                        <p className="text-isi text-teks-sekunder">
                                            Belum ada tautan absen. Membuat tautan juga mengikutsertakan {karyawan.Nama}{' '}
                                            dalam absensi.
                                        </p>
                                        <div>
                                            <Tombol
                                                memproses={memproses === 'buat'}
                                                disabled={karyawan.Status !== 'Aktif'}
                                                onClick={() => Kirim('buat', 'post', `${alamat}/tautan-absen`)}
                                            >
                                                Buat tautan absen
                                            </Tombol>
                                        </div>
                                    </>
                                )}
                            </section>

                            <section className="flex flex-col gap-3" aria-labelledby="judul-wajah">
                                <div className="flex flex-wrap items-center gap-2">
                                    <h3 id="judul-wajah" className="text-label font-semibold text-teks-utama">
                                        Wajah terdaftar
                                    </h3>
                                    <LabelStatus
                                        jenis={JenisLabelWajah(data.Wajah?.Status ?? null)}
                                        teks={data.Wajah?.Label ?? 'Belum didaftarkan'}
                                    />
                                </div>
                                {data.Wajah === null ? (
                                    <p className="text-isi text-teks-sekunder">
                                        Karyawan mendaftarkan wajahnya sendiri saat pertama membuka halaman Absen atau
                                        tautannya.
                                    </p>
                                ) : null}
                                {data.Wajah?.AlasanTolak ? (
                                    <p className="text-keterangan text-teks-sekunder">
                                        Alasan ditolak: {data.Wajah.AlasanTolak}
                                    </p>
                                ) : null}
                                {data.Wajah && data.Wajah.JumlahFoto > 0 ? (
                                    <div className="grid grid-cols-3 gap-2">
                                        {Array.from({ length: data.Wajah.JumlahFoto }, (_, i) => (
                                            <img
                                                key={i}
                                                src={`${alamat}/wajah/foto/${String(i)}`}
                                                alt={`Foto pendaftaran wajah ${String(i + 1)} ${karyawan.Nama}`}
                                                className="aspect-square w-full rounded-panel border border-garis object-cover"
                                                loading="lazy"
                                            />
                                        ))}
                                    </div>
                                ) : null}

                                {(data.Wajah?.Status === 'Menunggu' || data.Wajah?.Status === 'Disetujui') && !tolak ? (
                                    <div className="flex flex-wrap gap-2">
                                        {data.Wajah.Status === 'Menunggu' ? (
                                            <Tombol
                                                memproses={memproses === 'setujui'}
                                                disabled={memproses !== null}
                                                onClick={() =>
                                                    Kirim('setujui', 'post', `${alamat}/wajah/tinjau`, {
                                                        Setujui: true,
                                                    })
                                                }
                                            >
                                                Setujui wajah
                                            </Tombol>
                                        ) : null}
                                        <Tombol
                                            varian="sekunder"
                                            disabled={memproses !== null}
                                            onClick={() => AturTolak(true)}
                                        >
                                            Tolak
                                        </Tombol>
                                    </div>
                                ) : null}
                                {tolak ? (
                                    <div className="flex flex-col gap-2">
                                        <BidangTeks
                                            label="Alasan penolakan"
                                            nilai={alasan}
                                            saatBerubah={AturAlasan}
                                            maxLength={200}
                                            keterangan="Ditampilkan ke karyawan, misal: foto gelap, wajah tertutup masker."
                                            required
                                        />
                                        <div className="flex flex-wrap gap-2">
                                            <Tombol
                                                varian="bahaya"
                                                memproses={memproses === 'tolak'}
                                                disabled={alasan.trim() === ''}
                                                onClick={() =>
                                                    Kirim('tolak', 'post', `${alamat}/wajah/tinjau`, {
                                                        Setujui: false,
                                                        Alasan: alasan,
                                                    })
                                                }
                                            >
                                                Tolak wajah
                                            </Tombol>
                                            <Tombol varian="sekunder" onClick={() => AturTolak(false)}>
                                                Batal
                                            </Tombol>
                                        </div>
                                    </div>
                                ) : null}
                                {data.Wajah?.Status === 'Disetujui' ? (
                                    <div>
                                        <Tombol
                                            varian="sekunder"
                                            disabled={memproses !== null}
                                            onClick={() => AturKonfirmasi('hapus')}
                                        >
                                            Atur ulang wajah
                                        </Tombol>
                                    </div>
                                ) : null}
                            </section>
                        </>
                    ) : null}
                </div>
                {konfirmasi ? (
                    <DialogKonfirmasi
                        judul={isiKonfirmasi[konfirmasi].judul}
                        labelAksi={isiKonfirmasi[konfirmasi].label}
                        memproses={memproses === konfirmasi}
                        saatBatal={() => AturKonfirmasi(null)}
                        saatKonfirmasi={() =>
                            konfirmasi === 'buat-ulang'
                                ? Kirim('buat-ulang', 'post', `${alamat}/tautan-absen`)
                                : konfirmasi === 'cabut'
                                  ? Kirim('cabut', 'delete', `${alamat}/tautan-absen`)
                                  : Kirim('hapus', 'delete', `${alamat}/wajah`)
                        }
                    >
                        <p>{isiKonfirmasi[konfirmasi].akibat}</p>
                    </DialogKonfirmasi>
                ) : null}
            </SheetContent>
        </Sheet>
    );
}
