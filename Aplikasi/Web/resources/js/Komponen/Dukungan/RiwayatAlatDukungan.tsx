import { useState } from 'react';

import Tombol from '@/Komponen/Formulir/Tombol';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import { FormatRupiah } from '@/Pustaka/Format';
import { FormatTanggalWaktu } from '@/Pustaka/FormatWaktu';
import type { HasilAlatDukunganRingkas } from '@/Tipe/TenantPengelola';

type PropsRiwayatAlat = {
    alat: HasilAlatDukunganRingkas[];
};

const jenisStatus = { Berhasil: 'sukses', TanpaPerubahan: 'netral', Gagal: 'bahaya' } as const;

const labelKunci: Record<string, string> = {
    Kode: 'Kode perangkat',
    Status: 'Status',
    JumlahOutboxTertunda: 'Antrean kirim tertunda',
    TerakhirAktifPada: 'Terakhir terhubung',
    MintaLogPada: 'Log diminta',
    LogDiunggahPada: 'Log diunggah',
    JumlahPerbedaan: 'Perbedaan saldo',
    JumlahPasanganDiperbaiki: 'Pasangan diperbaiki',
    Contoh: 'Contoh perbedaan',
    JumlahBaris: 'Baris ringkasan',
    TotalBersih: 'Total bersih',
    TotalTransaksi: 'Total transaksi',
    Percobaan: 'Percobaan kirim',
    PesanGalat: 'Pesan galat',
    UuidPesanBaru: 'Pesan baru',
    EmailTerverifikasi: 'Email terverifikasi',
    Dikirim: 'Email dikirim',
};

const polaWaktu = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/;

/** Nilai keadaan -> teks pendek yang bisa dibaca Owner (angka, waktu, Ya/Tidak, uang). */
export function FormatNilaiAlat(kunci: string, nilai: unknown): string {
    if (nilai === null || nilai === undefined) {
        return '-';
    }

    if (typeof nilai === 'boolean') {
        return nilai ? 'Ya' : 'Tidak';
    }

    if (typeof nilai === 'number') {
        return String(nilai);
    }

    if (typeof nilai === 'string') {
        if (polaWaktu.test(nilai)) {
            return FormatTanggalWaktu(nilai);
        }

        return kunci === 'TotalBersih' ? FormatRupiah(nilai) : nilai;
    }

    if (Array.isArray(nilai)) {
        return nilai.map((butir) => FormatNilaiAlat(kunci, butir)).join('; ');
    }

    return JSON.stringify(nilai);
}

function KeadaanAlat({ judul, data }: { judul: string; data: Record<string, unknown> | null }) {
    const baris = data === null ? [] : Object.entries(data);

    return (
        <div className="grid min-w-0 gap-1">
            <h4 className="text-label font-semibold text-teks-utama">{judul}</h4>
            {baris.length === 0 ? (
                <p className="text-keterangan text-teks-sekunder">Tidak ada data.</p>
            ) : (
                <dl className="grid gap-1 text-keterangan text-teks-sekunder">
                    {baris.map(([kunci, nilai]) => (
                        <div key={kunci} className="grid gap-0.5">
                            <dt className="font-semibold text-teks-utama">{labelKunci[kunci] ?? kunci}</dt>
                            <dd className="break-words">{FormatNilaiAlat(kunci, nilai)}</dd>
                        </div>
                    ))}
                </dl>
            )}
        </div>
    );
}

function ButirAlat({ hasil }: { hasil: HasilAlatDukunganRingkas }) {
    const [terbuka, AturTerbuka] = useState(false);
    const punyaRincian = hasil.Sebelum !== null || hasil.Sesudah !== null;

    return (
        <li className="grid gap-2 rounded-panel border border-garis p-3">
            <div className="flex flex-wrap items-center gap-2">
                <LabelStatus jenis={jenisStatus[hasil.Status]} teks={hasil.LabelStatus} />
                <span className="text-isi font-semibold text-teks-utama">{hasil.LabelAlat}</span>
                <span className="text-keterangan text-teks-sekunder">
                    {hasil.NamaPetugas} | {FormatTanggalWaktu(hasil.DibuatPada)}
                </span>
            </div>
            <p className="text-isi text-teks-sekunder">{hasil.Ringkasan}</p>
            {punyaRincian ? (
                <div className="grid gap-2">
                    <div>
                        <Tombol
                            varian="sekunder"
                            aria-expanded={terbuka}
                            onClick={() => AturTerbuka((nilai) => !nilai)}
                        >
                            {terbuka ? 'Sembunyikan sebelum & sesudah' : 'Lihat sebelum & sesudah'}
                        </Tombol>
                    </div>
                    {terbuka ? (
                        <div className="grid gap-3 sm:grid-cols-2">
                            <KeadaanAlat judul="Sebelum" data={hasil.Sebelum} />
                            <KeadaanAlat judul="Sesudah" data={hasil.Sesudah} />
                        </div>
                    ) : null}
                </div>
            ) : null}
        </li>
    );
}

/**
 * PGL-16 bagian 2 (BR-P09.3): alat bantu yang dijalankan petugas dukungan beserta hasil sebelum/sesudah. Dipakai halaman
 * Owner (Pengaturan › Akses dukungan) dan panel konsol. Tidak memuat data pribadi: hanya angka, kode, dan waktu.
 */
export default function RiwayatAlatDukungan({ alat }: PropsRiwayatAlat) {
    if (alat.length === 0) {
        return (
            <p className="text-isi text-teks-sekunder">
                Belum ada alat bantu yang dijalankan. Setiap kali petugas memakai alat (misalnya membangun ulang saldo
                stok), hasil dan keadaan sebelum-sesudahnya tercatat di sini.
            </p>
        );
    }

    return (
        <ul className="grid gap-2" aria-label="Alat bantu dukungan yang dijalankan">
            {alat.map((hasil) => (
                <ButirAlat key={hasil.Uuid} hasil={hasil} />
            ))}
        </ul>
    );
}
