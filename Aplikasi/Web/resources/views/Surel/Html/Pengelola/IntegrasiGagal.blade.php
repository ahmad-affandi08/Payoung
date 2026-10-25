@extends('Surel.TataLetak')

@section('Judul', 'Integrasi gagal di '.$Lingkungan)
@section('Pratinjau', count($Gagal).' integrasi gagal pada tes koneksi berkala.')

@section('Isi')
    <p style="margin:0 0 20px 0; font-family:'Atkinson Hyperlegible Next','Atkinson Hyperlegible',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:16px; line-height:24px; color:#4f6567;">Tes koneksi berkala di lingkungan <strong style="color:#1f3335;">{{ $Lingkungan }}</strong> gagal untuk integrasi berikut.</p>

    <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="width:100%; margin:0 0 20px 0; background-color:#ebf0ec; border:1px solid #d2dcd6; border-radius:8px;">
        @foreach ($Gagal as $baris)
            <tr>
                <td style="padding:{{ $loop->first ? '14' : '0' }}px 16px {{ $loop->last ? '14' : '10' }}px 16px; font-family:'Atkinson Hyperlegible Next','Atkinson Hyperlegible',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:14px; line-height:20px; color:#4f6567;">
                    <strong style="color:#1f3335;">{{ $baris['Label'] }}</strong><br />{{ $baris['Pesan'] }}
                </td>
            </tr>
        @endforeach
    </table>

    <p style="margin:0; font-family:'Atkinson Hyperlegible Next','Atkinson Hyperlegible',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:16px; line-height:24px; color:#4f6567;">Periksa di Platform Pengelola &rsaquo; Integrasi, perbaiki kredensial atau layanan penyedia, lalu uji ulang.</p>
@endsection

@section('CatatanKaki', 'Email ini dikirim sekali saat status berubah menjadi gagal.')
