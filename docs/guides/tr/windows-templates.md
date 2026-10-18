# Windows şablonları ve Cloudbase-Init

*English: [../windows-templates.md](../windows-templates.md). Bu bir çeviridir; iki sürüm
arasında fark varsa İngilizce olan geçerlidir.*

Proxmotion'ın Windows sunucuları nasıl yönettiği, yeni bir sunucunun mümkün olduğunca az yeniden
başlatmayla hızlıca hazır olması için Windows şablonunda Cloudbase-Init'in nasıl ayarlanacağı ve
bir şey çalışmadığında nelere bakılacağı. Şablonun kendisini hazırlamak (VirtIO sürücüleri, guest
agent, Cloudbase-Init kurulumu, Sysprep) [iso-templates.md](../iso-templates.md#windows-server)
içinde anlatılıyor (İngilizce).

## Panel Windows için ne yapar

*Admin → OS templates* sayfasında OS şablonu için **`windows`** ailesini seçin: platform aileye
göre belirlenir (diğer tüm aileler Linux'tur). Platform şunları belirler:

| İşlem | Windows'ta nasıl çalışır |
|---|---|
| İlk açılış | Proxmox'un cloud-init sürücüsü (config drive) şifreyi (`admin_pass`), ağ ayarlarını ve hostname'i (user-data içinde) taşır. Cloudbase-Init bunları uygular |
| Ek IP adresleri, birincil adres | Varsayılan `addresses.ps1` guest scripti (ADR 0010, 0011), QEMU guest agent üzerinden çalışır. Adresleri her açılışta yeniden uygulayan **"Proxmotion addresses" zamanlanmış görevini** kaydeder |
| Yükseltmeyle büyüyen disk | Varsayılan `disk.ps1`, `C:` bölümünü genişletir |
| Şifre sıfırlama | Guest agent **`Administrator`** kullanıcısının şifresini ayarlar (ya da şablonun kendi `password_reset` scripti çalışır) |
| Hostname değişikliği | Varsayılan `hostname.ps1`, bilgisayarı hostname'in ilk kısmıyla (en fazla 15 karakter) yeniden adlandırır. Windows bunu bir sonraki yeniden başlatmada uygular: istenirse panel sunucuyu yeniden başlatır, aksi hâlde *yeniden başlatma gerekli* uyarısı gösterir (ADR 0012) |

Scriptler şablon bazında *OS templates → Scripts* sayfasından değiştirilebilir.

Kurulumdan hemen sonra guest agent, **Windows kurulumunu bitirmeden** cevap vermeye başlar:
Sysprep ve Cloudbase-Init sunucuyu hâlâ yeniden başlatır. Panel bununla başa çıkar. Bir script
çalışırken agent kaybolursa geri gelmesini bekler ve scripti yeniden çalıştırır (en fazla üç kez).
Windows scriptleri de ağa dokunmadan önce Cloudbase-Init'in bitmesini bekler. Yani şablondan
çıkardığınız her yeniden başlatma, yeni sunucunun daha erken hazır olması demektir.

## Yeni bir Windows sunucusu neden yeniden başlar

ISO rehberindeki gibi hazırlanmış bir şablonda Cloudbase-Init logu
(`C:\Program Files\Cloudbase Solutions\Cloudbase-Init\log\cloudbase-init.log`) ve System olay
günlüğü (olay kimliği **1074**) iki yeniden başlatma gösterir:

| # | Kim | Neden | Önlenebilir mi |
|---|---|---|---|
| 1 | `winlogon.exe` (Sysprep) | *specialize* aşamasının sonu, OOBE'ye geçiş | Hayır: Sysprep ile genelleştirilmiş her imajda bir kez olur |
| 2 | Cloudbase-Init (`python.exe`) | `UserDataPlugin`, hostname'i user-data'dan ayarlar (`Changing hostname to '…'`, ardından `Rebooting`) | **Evet** |

İkincisinin nedeni: Proxmox hostname'i **sadece user-data'ya** yazar (`#cloud-config`,
`hostname: …`). `meta_data.json` içinde yalnızca `uuid` ve `admin_pass` vardır. Bu yüzden
metadata'yı okuyan `SetHostNamePlugin` bir isim bulamaz ve hiçbir şey yapmaz. İsmi en sonda
user-data eklentisi ayarlar. Windows'ta isim değişikliği yeniden başlatma ister ve servis
yapılandırması buna izin verir (`allow_reboot=true`).

## Önerilen yapılandırma

İki dosya da `C:\Program Files\Cloudbase Solutions\Cloudbase-Init\conf\` içindedir.

**Fikir:** `UserDataPlugin`'i *specialize* aşamasında (`cloudbase-init-unattend.conf`) çalıştırmak.
Orada yapılan isim değişikliği, Sysprep'in zaten yaptığı yeniden başlatmayla uygulanır. Böylece
ikinci yeniden başlatma ortadan kalkar. Servis (`cloudbase-init.conf`) artık onu çalıştırmaz.

`cloudbase-init-unattend.conf` (Sysprep'in specialize aşamasında çalışır, kendi başına asla
yeniden başlatmaz):

```ini
[DEFAULT]
username=Administrator
groups=Administrators
inject_user_password=true
first_logon_behaviour=no
metadata_services=cloudbaseinit.metadata.services.configdrive.ConfigDriveService
check_latest_version=false
log_dir=C:\Program Files\Cloudbase Solutions\Cloudbase-Init\log\
log_file=cloudbase-init-unattend.log
plugins=cloudbaseinit.plugins.common.sethostname.SetHostNamePlugin,cloudbaseinit.plugins.windows.extendvolumes.ExtendVolumesPlugin,cloudbaseinit.plugins.common.userdata.UserDataPlugin
allow_reboot=false
stop_service_on_exit=false

[config_drive]
types=iso,vfat
locations=cdrom,hdd
```

`cloudbase-init.conf` (servis, her açılışta):

```ini
[DEFAULT]
username=Administrator
groups=Administrators
inject_user_password=true
first_logon_behaviour=no
metadata_services=cloudbaseinit.metadata.services.configdrive.ConfigDriveService
check_latest_version=false
mtu_use_dhcp_config=false
ntp_use_dhcp_config=false
log_dir=C:\Program Files\Cloudbase Solutions\Cloudbase-Init\log\
log_file=cloudbase-init.log
local_scripts_path=C:\Program Files\Cloudbase Solutions\Cloudbase-Init\LocalScripts\
plugins=cloudbaseinit.plugins.windows.createuser.CreateUserPlugin,cloudbaseinit.plugins.common.networkconfig.NetworkConfigPlugin,cloudbaseinit.plugins.common.sshpublickeys.SetUserSSHPublicKeysPlugin,cloudbaseinit.plugins.windows.extendvolumes.ExtendVolumesPlugin,cloudbaseinit.plugins.common.setuserpassword.SetUserPasswordPlugin,cloudbaseinit.plugins.common.localscripts.LocalScriptsPlugin
allow_reboot=true
stop_service_on_exit=true

[config_drive]
types=iso,vfat
locations=cdrom,hdd
```

Notlar:

- `UserDataPlugin`'i taşımak bir şey kaybettirmez: Proxmox'un user-data'sından Windows'ta yalnızca
  `hostname` çalışır. `user`, `password`, `chpasswd`, `fqdn` ve `manage_etc_hosts` logda *not
  supported* olarak geçer. Şifre metadata'dan (`CreateUserPlugin`, `SetUserPasswordPlugin`), ağ
  `NetworkConfigPlugin`'den gelir.
- `metadata_services` içinde yalnızca `ConfigDriveService` olması, Cloudbase-Init'in Proxmox'ta
  zaten zaman aşımına düşecek ağ metadata servislerini (EC2, OpenStack HTTP) denemesini önler.
- `[config_drive] types` / `locations`, logun eskimiş (*deprecated*) dediği `config_drive_cdrom`,
  `config_drive_vfat` ve `config_drive_raw_hdd` seçeneklerinin yerine geçer.
- Unattend dosyasında `allow_reboot=false` kalmalı: orada bir yeniden başlatma Sysprep'i bozar.
- `LocalScripts\` içindeki kendi scriptleriniz ilk açılışta çalışır ve kendinden sonraki her şeyi
  geciktirir. Logda her birinin ne kadar sürdüğü görünür (`Script "…" ended with exit code`).
  Orada sabit beklemelerden kaçının.

**Durum:** ikinci yeniden başlatmanın nedeni gerçek bir Windows sunucusunun loglarından okundu
(2026-10-17). Yukarıdaki yapılandırma önerilen çözümdür; **etkisi henüz ölçülmedi**. Şablonu
yeniden hazırladıktan sonra bir sunucu oluşturup System günlüğünde `winlogon.exe`'den tek bir 1074
olayı olduğunu, Cloudbase-Init'ten hiç olmadığını kontrol edin.

## Yapılandırmayı şablona uygulamak

Dosyalar şablon imajının içinde durur:

1. Şablonun tam bir klonunu oluşturun (ya da şablonun yapıldığı VM'i başlatın) ve oturum açın.
2. İki dosyayı yukarıdaki gibi düzenleyin.
3. Cloudbase-Init'in yanıt dosyasıyla yeniden genelleştirin ve kapanmasını bekleyin:

   ```bat
   C:\Windows\System32\Sysprep\sysprep.exe /generalize /oobe /shutdown /unattend:"C:\Program Files\Cloudbase Solutions\Cloudbase-Init\conf\Unattend.xml"
   ```

4. Proxmox'ta VM'i şablona çevirin (bkz. [iso-templates.md](../iso-templates.md#updating-a-template)).
   Yeni bir VMID ise panelde cluster'ı senkronlayıp OS şablonunu ona eşleyin.

## Sorun giderme

| Gördüğünüz | Anlamı / ne yapmalı |
|---|---|
| Görev adımında *The additional addresses could not be configured inside the server: QEMU guest agent is not running* | Script çalışırken Windows yeniden başladı ve üç denemede de geri gelmedi. Olay günlüğüne (1074) bakın. Zamanlanmış görev adresleri bir sonraki açılışta uygular; ya da Scripts sayfasında *Sunucuda çalıştır*'ı kullanın |
| *Get-NetAdapter : Critical error* | Windows hâlâ kuruluyordu, ağ komutları hazır değildi. Güncel scriptler bunu bekler; varsayılanın yerine kendi scriptini kullanan şablonları güncelleyin |
| *Proxmotion addresses* zamanlanmış görevi yok | Adres scripti o noktaya hiç gelemedi. PowerShell'de: `Get-ScheduledTask -TaskName 'Proxmotion addresses'` |
| Hostname hâlâ `WIN-…` | User-data eklentisi çalışmadı ya da yeniden başlatmasına izin verilmedi. `cloudbase-init*.log` içinde `Changing hostname` arayın |
| Şifre ayarlanmadı | `cloudbase-init.log` içinde `Using admin_pass metadata user password` görünmeli; Proxmox VM'inde CloudInit sürücüsü olmalı |

Sunucunun içinde işe yarayan komutlar (Yönetici olarak PowerShell):

```powershell
Get-NetIPAddress -AddressFamily IPv4 | Format-Table IPAddress, PrefixLength, SkipAsSource
Get-WinEvent -FilterHashtable @{LogName='System'; Id=1074} -MaxEvents 5 | Format-List TimeCreated, Message
Get-Content 'C:\Program Files\Cloudbase Solutions\Cloudbase-Init\log\cloudbase-init.log' -Tail 40
Get-ChildItem C:\ProgramData\Proxmotion
```

## Henüz doğrulanmayanlar

- Windows varsayılan scriptleri tek bir Windows Server imajında çalıştı (2026-10-17): iki kurulum
  yeniden başlatmasıyla birlikte, yeniden kurulumdan sonra ek adresler. Birincil adres
  değişikliği, `disk.ps1`, IPv6 ve diğer Windows sürümleri denenmedi.
- Yukarıdaki yapılandırmayla hazırlanan bir şablonun yalnızca bir kez yeniden başlayıp
  başlamadığı (yukarıdaki *Durum* notuna bakın).
