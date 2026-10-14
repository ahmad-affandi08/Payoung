; Pemasang Windows Payoung Kasir (POS-20, D-154). Dibangun CI (`.github/workflows/BangunAplikasi.yml`) dengan Inno Setup:
;   iscc /DVersiAplikasi=1.0.0 /DSumber=<folder Release> /DKeluar=<folder keluaran> Payoung.iss
; Pemasang ini TIDAK ditandatangani kecuali CI diberi sertifikat (lihat Panduan/RilisAplikasi.md); Windows SmartScreen
; menampilkan peringatan "penerbit tidak dikenal" sampai itu dilakukan. Dipasang per pengguna (tanpa hak admin) supaya
; pembaruan otomatis dari dalam aplikasi (POS-23) bisa berjalan diam-diam tanpa prompt UAC.

#ifndef VersiAplikasi
  #define VersiAplikasi "0.0.0"
#endif
#ifndef Sumber
  #define Sumber "..\..\build\windows\x64\runner\Release"
#endif
#ifndef Keluar
  #define Keluar "Keluaran"
#endif

[Setup]
AppId={{6F0B7A52-3C1E-4D7B-9E61-5A3D2C8F1B40}
AppName=Payoung Kasir
AppVersion={#VersiAplikasi}
AppPublisher=Payoung
AppPublisherURL=https://payoung.id
DefaultDirName={autopf}\Payoung Kasir
DefaultGroupName=Payoung Kasir
DisableProgramGroupPage=yes
PrivilegesRequired=lowest
OutputDir={#Keluar}
OutputBaseFilename=Payoung-Kasir-Pemasang-{#VersiAplikasi}
Compression=lzma2
SolidCompression=yes
WizardStyle=modern
ArchitecturesAllowed=x64compatible
ArchitecturesInstallIn64BitMode=x64compatible
; Pembaruan menutup kasir yang sedang terbuka lalu membukanya lagi (data lokal tidak disentuh).
CloseApplications=yes
RestartApplications=yes
SetupIconFile=..\runner\resources\app_icon.ico
UninstallDisplayIcon={app}\kasir.exe

[Tasks]
Name: "ikonDesktop"; Description: "Buat ikon di desktop"; GroupDescription: "Pintasan:"; Flags: unchecked

[Files]
Source: "{#Sumber}\*"; DestDir: "{app}"; Flags: ignoreversion recursesubdirs createallsubdirs

[Icons]
Name: "{group}\Payoung Kasir"; Filename: "{app}\kasir.exe"
Name: "{autodesktop}\Payoung Kasir"; Filename: "{app}\kasir.exe"; Tasks: ikonDesktop

[Run]
Filename: "{app}\kasir.exe"; Description: "Jalankan Payoung Kasir"; Flags: nowait postinstall skipifsilent
