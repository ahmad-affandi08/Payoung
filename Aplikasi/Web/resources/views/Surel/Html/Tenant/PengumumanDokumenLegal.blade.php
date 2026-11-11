@extends('Surel.TataLetak')

@section('Judul', 'Perubahan '.$LabelDokumen)
@section('Pratinjau', $LabelDokumen.' versi '.$Versi.' berlaku mulai '.$BerlakuMulai.'.')

@section('Isi')
    <p style="margin:0 0 16px 0; font-family:'Lato',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:16px; line-height:24px; color:#4f6567;">Halo {{ $Nama }},</p>

    <p style="margin:0 0 20px 0; font-family:'Lato',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:16px; line-height:24px; color:#4f6567;">Kami memperbarui {{ $LabelDokumen }}. Versi baru ini berlaku mulai {{ $BerlakuMulai }}.</p>

    @include('Surel.Komponen.Rincian', ['Baris' => [
        'Dokumen' => $LabelDokumen,
        'Versi' => $Versi,
        'Berlaku mulai' => $BerlakuMulai,
    ]])

    @if ($RingkasanPerubahan)
        <p style="margin:0 0 8px 0; font-family:'Lato',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:14px; line-height:20px; font-weight:bold; color:#1f3335;">Ringkasan perubahan</p>
        @include('Surel.Komponen.Kutipan', ['Teks' => $RingkasanPerubahan])
    @endif

    @include('Surel.Komponen.Tombol', ['Url' => $Tautan, 'Label' => 'Baca versi lengkapnya'])

    <p style="margin:0; font-family:'Lato',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:16px; line-height:24px; color:#4f6567;">Setelah tanggal berlaku, Anda akan diminta menyetujui versi ini saat membuka back-office berikutnya.</p>
@endsection

@section('CatatanKaki', 'Ada pertanyaan soal perubahan ini? Balas email ini dan tim kami akan menjelaskan.')
