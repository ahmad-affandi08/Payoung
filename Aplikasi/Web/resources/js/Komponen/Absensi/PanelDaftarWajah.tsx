import { CameraIcon, CheckCircle2Icon, ScanFaceIcon, ShieldCheckIcon, SunIcon } from 'lucide-react';
import { router } from '@inertiajs/react';
import { useId, useState } from 'react';

import PemindaiWajah, { type HasilFoto } from '@/Komponen/Absensi/PemindaiWajah';
import Tombol from '@/Komponen/Formulir/Tombol';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { KirimJson } from '@/Pustaka/PermintaanJson';
import { ALAMAT_ABSEN } from '@/Fitur/Absensi/Alamat';
import type { PropsAbsenSaya } from '@/Tipe/Karyawan';

/** Pendaftaran wajah sekali (persetujuan UU PDP, lalu rekam foto). Langsung aktif tanpa persetujuan pengelola (D-45). */
export default function PanelDaftarWajah({
    Wajah,
    AlamatModelWajah,
    JumlahFotoDaftar,
    daring,
}: Pick<PropsAbsenSaya, 'Wajah' | 'AlamatModelWajah' | 'JumlahFotoDaftar'> & { daring: boolean }) {
    const [setuju, AturSetuju] = useState(false);
    const [pindai, AturPindai] = useState(false);
    const [mengirim, AturMengirim] = useState(false);
    const [galat, AturGalat] = useState<string | null>(null);
    const idSetuju = useId();

    const Kirim = async (foto: HasilFoto[]) => {
        AturPindai(false);
        AturMengirim(true);
        AturGalat(null);

        try {
            await KirimJson(`${ALAMAT_ABSEN}/wajah`, {
                SidikWajah: foto.map((f) => f.Sidik),
                Foto: foto.map((f) => f.Swafoto),
                Persetujuan: setuju,
            });
            router.reload();
        } catch (kesalahan) {
            AturGalat(kesalahan instanceof Error ? kesalahan.message : 'Pendaftaran wajah gagal. Coba lagi.');
        } finally {
            AturMengirim(false);
        }
    };

    const langkah = setuju ? 2 : 1;

    return (
        <section
            className="flex flex-col gap-5 rounded-panel border border-garis bg-permukaan p-5"
            aria-labelledby="judul-daftar-wajah"
        >
            <div className="flex items-center gap-3">
                <span className="grid size-12 shrink-0 place-items-center rounded-full bg-brand-lembut text-brand">
                    <ScanFaceIcon aria-hidden="true" className="size-6" />
                </span>
                <div className="flex min-w-0 flex-col gap-1">
                    <h2 id="judul-daftar-wajah" className="text-judul font-semibold text-teks-utama">
                        Daftarkan wajah
                    </h2>
                    {Wajah?.Status === 'Ditolak' ? <LabelStatus jenis="bahaya" teks="Ditolak, daftar ulang" /> : null}
                </div>
            </div>
            {Wajah?.AlasanTolak ? (
                <Pemberitahuan jenis="peringatan">Alasan pengelola: {Wajah.AlasanTolak}</Pemberitahuan>
            ) : null}
            <p className="text-isi text-teks-sekunder">
                Sekali saja sebelum absen pertama. Kamera depan akan mengambil {JumlahFotoDaftar} foto wajah Anda
                berturut-turut. Setelah itu Anda langsung bisa absen.
            </p>

            <ol className="grid grid-cols-2 gap-2 text-keterangan" aria-label="Langkah pendaftaran">
                {['Setujui penggunaan data', 'Rekam wajah'].map((nama, indeks) => {
                    const nomor = indeks + 1;
                    const aktif = nomor === langkah;
                    const lewat = nomor < langkah;

                    return (
                        <li
                            key={nama}
                            aria-current={aktif ? 'step' : undefined}
                            className={`flex items-center gap-2 rounded-panel border p-2 ${
                                aktif
                                    ? 'border-brand bg-brand-lembut text-teks-utama'
                                    : 'border-garis text-teks-sekunder'
                            }`}
                        >
                            <span
                                className={`grid size-6 shrink-0 place-items-center rounded-full font-semibold ${
                                    lewat
                                        ? 'bg-sukses text-permukaan'
                                        : aktif
                                          ? 'bg-brand text-brand-teks'
                                          : 'bg-permukaan-sorot'
                                }`}
                            >
                                {lewat ? <CheckCircle2Icon aria-hidden="true" className="size-4" /> : nomor}
                            </span>
                            {nama}
                        </li>
                    );
                })}
            </ol>

            <ul className="flex flex-col gap-2 rounded-panel bg-permukaan-redup p-3 text-keterangan text-teks-sekunder">
                <li className="flex items-start gap-2">
                    <SunIcon aria-hidden="true" className="mt-0.5 size-4 shrink-0 text-brand" />
                    Cari tempat yang terang, wajah menghadap lurus ke kamera.
                </li>
                <li className="flex items-start gap-2">
                    <ScanFaceIcon aria-hidden="true" className="mt-0.5 size-4 shrink-0 text-brand" />
                    Lepas masker dan kacamata gelap, lalu kedip saat diminta.
                </li>
                <li className="flex items-start gap-2">
                    <ShieldCheckIcon aria-hidden="true" className="mt-0.5 size-4 shrink-0 text-brand" />
                    Data wajah disimpan terenkripsi dan hanya dipakai untuk absensi.
                </li>
            </ul>

            <label
                htmlFor={idSetuju}
                className="flex items-start gap-3 rounded-panel border border-garis p-3 text-keterangan text-teks-utama"
            >
                <input
                    id={idSetuju}
                    type="checkbox"
                    className="mt-0.5 size-5 shrink-0 accent-brand"
                    checked={setuju}
                    onChange={(peristiwa) => AturSetuju(peristiwa.target.checked)}
                />
                <span>
                    Saya setuju data wajah (foto dan sidik wajah) saya diproses toko ini{' '}
                    <strong>hanya untuk absensi</strong>, disimpan terenkripsi, dan dihapus saat saya tidak lagi bekerja
                    di sini (UU No. 27 Tahun 2022 tentang Pelindungan Data Pribadi).
                </span>
            </label>

            {galat ? <Pemberitahuan jenis="bahaya">{galat}</Pemberitahuan> : null}

            {pindai ? (
                <PemindaiWajah
                    alamatModel={AlamatModelWajah}
                    jumlah={JumlahFotoDaftar}
                    saatSelesai={(hasil) => void Kirim(hasil)}
                    saatBatal={() => AturPindai(false)}
                    saatGalat={(pesan) => {
                        AturPindai(false);
                        AturGalat(pesan);
                    }}
                />
            ) : (
                <Tombol
                    ukuran="besar"
                    disabled={!setuju || !daring}
                    memproses={mengirim}
                    onClick={() => AturPindai(true)}
                >
                    <CameraIcon aria-hidden="true" className="size-4" />
                    Mulai rekam wajah
                </Tombol>
            )}
        </section>
    );
}
