import { Link } from '@inertiajs/react';

import AksiHalaman from '@/Komponen/Kelola/AksiHalaman';
import NavigasiKampanye from '@/Komponen/Pengelola/KampanyeProspek/NavigasiKampanye';
import TabTenant from '@/Komponen/Pengelola/Tenant/TabTenant';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { KolomTabel } from '@/Komponen/TabelData/Tipe';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { Button } from '@/Komponen/Ui/button';
import TataLetakPengelola from '@/TataLetak/TataLetakPengelola';
import type { BarisKampanyeProspek, RingkasKontakProspek, StatusKampanyeProspek } from '@/Tipe/KampanyeProspek';

export function JenisStatusKampanye(status: StatusKampanyeProspek): 'sukses' | 'peringatan' | 'netral' {
    return status === 'Selesai' ? 'sukses' : status === 'Berjalan' || status === 'Dijeda' ? 'peringatan' : 'netral';
}

const kolom: KolomTabel<BarisKampanyeProspek>[] = [
    {
        id: 'Nama',
        accessorKey: 'Nama',
        header: 'Kampanye',
        meta: { label: 'Kampanye', prioritas: 'utama', wajib: true },
        cell: ({ row: { original: kampanye } }) => (
            <>
                <Link href={`/kampanye-prospek/${kampanye.Uuid}`} className="font-semibold text-brand underline">
                    {kampanye.Nama}
                </Link>
                <span className="block text-keterangan font-normal text-teks-sekunder">{kampanye.Subjek}</span>
            </>
        ),
    },
    {
        id: 'Status',
        accessorKey: 'Status',
        header: 'Status',
        meta: { label: 'Status', prioritas: 'utama' },
        cell: ({ row: { original: kampanye } }) => (
            <LabelStatus jenis={JenisStatusKampanye(kampanye.Status)} teks={kampanye.LabelStatus} />
        ),
    },
    {
        id: 'Hasil',
        header: 'Hasil kirim',
        enableSorting: false,
        meta: { label: 'Hasil kirim', prioritas: 'penting', kelasSel: 'tabular-nums text-teks-sekunder' },
        cell: ({ row: { original: kampanye } }) =>
            kampanye.JumlahPenerima === 0
                ? '—'
                : `${kampanye.JumlahTerkirim.toLocaleString('id-ID')} dari ${kampanye.JumlahPenerima.toLocaleString('id-ID')} terkirim` +
                  (kampanye.JumlahGagal > 0 ? ` | ${String(kampanye.JumlahGagal)} gagal` : '') +
                  (kampanye.JumlahDilewati > 0 ? ` | ${String(kampanye.JumlahDilewati)} dilewati` : ''),
    },
    {
        id: 'Sektor',
        header: 'Sektor',
        enableSorting: false,
        meta: { label: 'Sektor', prioritas: 'rendah', kelasSel: 'text-teks-sekunder' },
        cell: ({ row: { original: kampanye } }) => kampanye.Sektor.join(', '),
    },
];

/**
 * Kampanye email prospek (D-216): email ke calon pelanggan dari daftar kontak, dikirim pelan pada jam kerja dari pengirim
 * kampanye yang terpisah dari email transaksional. Hanya Super Admin.
 */
export default function Daftar({
    Kampanye,
    EmailKampanyeAktif,
    Kontak,
}: {
    Kampanye: BarisKampanyeProspek[];
    EmailKampanyeAktif: boolean;
    Kontak: RingkasKontakProspek;
}) {
    return (
        <TataLetakPengelola judul="Tenant">
            <TabTenant />
            <NavigasiKampanye />
            {!EmailKampanyeAktif ? (
                <Pemberitahuan jenis="peringatan" judul="Email kampanye belum aktif">
                    Kampanye baru bisa dijalankan setelah pengirim kampanye diatur di{' '}
                    <Link href="/integrasi">Integrasi › Email kampanye</Link>. Pakai domain atau subdomain pengirim yang
                    terpisah dari email transaksional.
                </Pemberitahuan>
            ) : null}
            <AksiHalaman
                keterangan={
                    <span className="text-isi text-teks-sekunder tabular-nums">
                        {Kontak.Aktif.toLocaleString('id-ID')} kontak aktif | {Kontak.Berhenti.toLocaleString('id-ID')}{' '}
                        berhenti atau tidak aktif
                    </span>
                }
            >
                <Button asChild>
                    <Link href="/kampanye-prospek/buat">Buat kampanye</Link>
                </Button>
            </AksiHalaman>
            <TabelData
                id="pengelola-kampanye-prospek"
                label="Kampanye prospek"
                kolom={kolom}
                sumber={{ mode: 'lokal', data: Kampanye }}
                ambilIdBaris={(kampanye) => kampanye.Uuid}
                cari="Cari nama kampanye"
                saring={[
                    {
                        id: 'Status',
                        label: 'Status',
                        jenis: 'pilihanBanyak',
                        opsi: ['Draf', 'Berjalan', 'Dijeda', 'Selesai', 'Dibatalkan'].map((nilai) => ({
                            nilai,
                            label: nilai,
                        })),
                    },
                ]}
                alamatDetail={(kampanye) => `/kampanye-prospek/${kampanye.Uuid}`}
                kosong={{
                    ilustrasi: true,
                    judul: 'Belum ada kampanye. Impor kontak, lalu buat kampanye pertama.',
                }}
            />
        </TataLetakPengelola>
    );
}
