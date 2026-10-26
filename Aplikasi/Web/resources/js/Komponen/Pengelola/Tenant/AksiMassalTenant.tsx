import { router, usePage } from '@inertiajs/react';
import { useState } from 'react';

import BidangPilihan from '@/Komponen/Formulir/BidangPilihan';
import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import BidangTeksPanjang from '@/Komponen/Formulir/BidangTeksPanjang';
import Tombol from '@/Komponen/Formulir/Tombol';
import type { KonteksAksiMassal } from '@/Komponen/TabelData/TabelData';
import DialogFormulir from '@/Komponen/Tindakan/DialogFormulir';
import { DialogFooter } from '@/Komponen/Ui/dialog';
import { IzinPengelola, PunyaIzin, type Pilihan, type PropsBersamaPengelola } from '@/Tipe/Pengelola';

/** Batas server `AksiTenantMassal::MAKS`. */
export const MaksTenantMassal = 50;

type JenisAksi = 'trial' | 'tangguhkan' | 'aktifkan' | 'catatan' | 'hapus';

/** Frasa konfirmasi server (`TenantMassalKontroler::FRASA_HAPUS`). */
export const FrasaHapusPermanen = 'HAPUS PERMANEN';

const JUDUL: Record<JenisAksi, string> = {
    trial: 'Perpanjang uji coba',
    tangguhkan: 'Tangguhkan tenant',
    aktifkan: 'Aktifkan kembali',
    catatan: 'Beri catatan internal',
    hapus: 'Hapus permanen',
};

const KETERANGAN: Record<JenisAksi, string> = {
    trial: 'Hari tambahan dan alasan yang sama dicatat di semua tenant terpilih. Tenant yang tidak sedang uji coba dilewati.',
    tangguhkan:
        'Owner hanya bisa masuk, melihat laporan, mengekspor data, dan membayar tagihan; aplikasi kasir terkunci. Tidak ada data yang dihapus.',
    aktifkan: 'Hanya tenant yang sedang ditangguhkan yang diaktifkan; lainnya dilewati.',
    catatan: 'Satu catatan yang sama ditulis di riwayat internal semua tenant terpilih. Owner tidak melihatnya.',
    hapus: 'Menghapus tenant terpilih beserta SEMUA datanya (pengguna, transaksi, jurnal, berkas). Tidak bisa dibatalkan. Hanya tenant berpenanda Uji, Demo, atau Internal tanpa tagihan berbayar yang dihapus; yang lain dilewati.',
};

type Isian = { Hari: string; Alasan: string; Kategori: string; Catatan: string; Isi: string; Frasa: string };

/**
 * Aksi untuk tenant terpilih di daftar tenant konsol (D-208). Tiap aksi hanya tampil bila pelaku berizin; tenant yang
 * tidak memenuhi syarat dilewati server dan alasannya dirangkum di dialog hasil. Tangguhkan/aktifkan minta kode 2FA baru
 * lewat perantara rute, sama dengan aksi satuan.
 */
export default function AksiMassalTenant<T extends { Uuid: string }>({
    konteks,
    pilihanKategori,
}: {
    konteks: KonteksAksiMassal<T>;
    pilihanKategori: Pilihan[];
}) {
    const { Pengguna: pengguna } = usePage<PropsBersamaPengelola>().props;
    const [aksi, AturAksi] = useState<JenisAksi | null>(null);
    const [isian, AturIsian] = useState<Isian>({
        Hari: '7',
        Alasan: '',
        Kategori: pilihanKategori[0]?.Nilai ?? '',
        Catatan: '',
        Isi: '',
        Frasa: '',
    });
    const [galat, AturGalat] = useState<Record<string, string>>({});
    const [memproses, AturMemproses] = useState(false);
    const uuid = konteks.terpilih.map((t) => t.Uuid);
    const terlaluBanyak = uuid.length > MaksTenantMassal;
    const Ubah = (kunci: keyof Isian, nilai: string) => AturIsian((lama) => ({ ...lama, [kunci]: nilai }));

    const Kirim = () => {
        if (aksi === null) {
            return;
        }

        const pilihan = {
            preserveScroll: true,
            onStart: () => AturMemproses(true),
            onFinish: () => AturMemproses(false),
            onError: (errors: Record<string, string>) => AturGalat(errors),
            onSuccess: () => {
                AturAksi(null);
                AturGalat({});
                konteks.bersihkan();
            },
        };

        switch (aksi) {
            case 'trial':
                router.put(
                    '/tenant/massal/trial',
                    { Uuid: uuid, Hari: Number(isian.Hari), Alasan: isian.Alasan },
                    pilihan,
                );
                break;
            case 'tangguhkan':
                router.put(
                    '/tenant/massal/tangguhkan',
                    { Uuid: uuid, Kategori: isian.Kategori, Catatan: isian.Catatan },
                    pilihan,
                );
                break;
            case 'aktifkan':
                router.put('/tenant/massal/aktifkan', { Uuid: uuid, Alasan: isian.Alasan }, pilihan);
                break;
            case 'catatan':
                router.post('/tenant/massal/catatan', { Uuid: uuid, Isi: isian.Isi }, pilihan);
                break;
            case 'hapus':
                router.delete('/tenant/massal/hapus-permanen', {
                    data: { Uuid: uuid, Alasan: isian.Alasan, Frasa: isian.Frasa },
                    ...pilihan,
                });
                break;
        }
    };

    const Buka = (jenis: JenisAksi) => {
        AturGalat({});
        AturAksi(jenis);
    };

    const siapKirim =
        aksi === 'trial'
            ? isian.Alasan.trim().length >= 10 && Number(isian.Hari) >= 1
            : aksi === 'tangguhkan'
              ? isian.Catatan.trim().length >= 10 && isian.Kategori !== ''
              : aksi === 'aktifkan'
                ? isian.Alasan.trim().length >= 10
                : aksi === 'hapus'
                  ? isian.Alasan.trim().length >= 10 && isian.Frasa === FrasaHapusPermanen
                  : isian.Isi.trim().length >= 3;

    const jumlahTeks = uuid.length.toLocaleString('id-ID');

    return (
        <div className="flex flex-wrap items-center gap-2">
            {terlaluBanyak ? (
                <p className="w-full text-keterangan text-bahaya">
                    Maksimal {MaksTenantMassal} tenant sekali proses. Kurangi pilihan.
                </p>
            ) : null}
            {PunyaIzin(pengguna, IzinPengelola.TenantTrialPerpanjang) ? (
                <Tombol varian="sekunder" disabled={terlaluBanyak} onClick={() => Buka('trial')}>
                    Perpanjang uji coba…
                </Tombol>
            ) : null}
            {PunyaIzin(pengguna, IzinPengelola.KomunikasiKelola) ? (
                <Tombol
                    varian="sekunder"
                    disabled={terlaluBanyak}
                    onClick={() => router.get('/komunikasi/buat', { tenant: uuid })}
                >
                    Kirim pesan…
                </Tombol>
            ) : null}
            {PunyaIzin(pengguna, IzinPengelola.TenantCatatanTulis) ? (
                <Tombol varian="sekunder" disabled={terlaluBanyak} onClick={() => Buka('catatan')}>
                    Beri catatan…
                </Tombol>
            ) : null}
            {PunyaIzin(pengguna, IzinPengelola.TenantAktifkan) ? (
                <Tombol varian="sekunder" disabled={terlaluBanyak} onClick={() => Buka('aktifkan')}>
                    Aktifkan kembali…
                </Tombol>
            ) : null}
            {PunyaIzin(pengguna, IzinPengelola.TenantTangguhkan) ? (
                <Tombol varian="bahaya" disabled={terlaluBanyak} onClick={() => Buka('tangguhkan')}>
                    Tangguhkan…
                </Tombol>
            ) : null}
            {PunyaIzin(pengguna, IzinPengelola.TenantHapusPermanen) ? (
                <Tombol varian="bahaya" disabled={terlaluBanyak} onClick={() => Buka('hapus')}>
                    Hapus permanen…
                </Tombol>
            ) : null}
            {aksi !== null ? (
                <DialogFormulir
                    jenis={aksi === 'tangguhkan' || aksi === 'hapus' ? 'konfirmasi' : 'dialog'}
                    judul={`${JUDUL[aksi]} (${jumlahTeks} tenant)`}
                    keterangan={KETERANGAN[aksi]}
                    galatUmum={galat.Umum ?? galat.Uuid}
                    saatTutup={() => AturAksi(null)}
                >
                    <form
                        noValidate
                        className="flex flex-col gap-3"
                        onSubmit={(peristiwa) => {
                            peristiwa.preventDefault();
                            Kirim();
                        }}
                    >
                        {aksi === 'trial' ? (
                            <BidangTeks
                                label="Tambahan hari"
                                nilai={isian.Hari}
                                saatBerubah={(nilai) => Ubah('Hari', nilai.replace(/\D/g, ''))}
                                galat={galat.Hari}
                                inputMode="numeric"
                                maxLength={2}
                                required
                            />
                        ) : null}
                        {aksi === 'trial' || aksi === 'aktifkan' || aksi === 'hapus' ? (
                            <BidangTeksPanjang
                                label="Alasan"
                                nilai={isian.Alasan}
                                saatBerubah={(nilai) => Ubah('Alasan', nilai)}
                                galat={galat.Alasan}
                                baris={3}
                                maksimal={500}
                                required
                            />
                        ) : null}
                        {aksi === 'tangguhkan' ? (
                            <>
                                <BidangPilihan
                                    label="Kategori alasan"
                                    nilai={isian.Kategori}
                                    opsi={pilihanKategori}
                                    saatBerubah={(nilai) => Ubah('Kategori', nilai)}
                                    galat={galat.Kategori}
                                    required
                                />
                                <BidangTeksPanjang
                                    label="Catatan internal"
                                    nilai={isian.Catatan}
                                    saatBerubah={(nilai) => Ubah('Catatan', nilai)}
                                    galat={galat.Catatan}
                                    baris={3}
                                    maksimal={450}
                                    required
                                />
                            </>
                        ) : null}
                        {aksi === 'hapus' ? (
                            <BidangTeks
                                label={`Ketik ${FrasaHapusPermanen} untuk memastikan`}
                                nilai={isian.Frasa}
                                saatBerubah={(nilai) => Ubah('Frasa', nilai)}
                                galat={galat.Frasa}
                                autoComplete="off"
                                required
                            />
                        ) : null}
                        {aksi === 'catatan' ? (
                            <BidangTeksPanjang
                                label="Isi catatan"
                                nilai={isian.Isi}
                                saatBerubah={(nilai) => Ubah('Isi', nilai)}
                                galat={galat.Isi}
                                baris={4}
                                maksimal={2000}
                                required
                            />
                        ) : null}
                        <DialogFooter className="sm:justify-start">
                            <Tombol
                                type="submit"
                                varian={aksi === 'tangguhkan' || aksi === 'hapus' ? 'bahaya' : 'utama'}
                                memproses={memproses}
                                disabled={!siapKirim}
                            >
                                {JUDUL[aksi]}
                            </Tombol>
                            <Tombol varian="sekunder" onClick={() => AturAksi(null)}>
                                Batal
                            </Tombol>
                        </DialogFooter>
                    </form>
                </DialogFormulir>
            ) : null}
        </div>
    );
}
