# Windows şablonları ve Cloudbase-Init

*English: [../windows-templates.md](../windows-templates.md). Bu bir çeviridir; iki sürüm
arasında fark varsa İngilizce olan geçerlidir.*

Proxmotion'ın Windows sunucuları nasıl yönettiği, yeni bir sunucunun mümkün olduğunca az yeniden
başlatmayla hızlıca hazır olması için Windows şablonunda Cloudbase-Init'in nasıl ayarlanacağı ve
bir şey çalışmadığında nelere bakılacağı. Şablonun kendisini hazırlamak (VirtIO sürücüleri, guest
agent, Cloudbase-Init kurulumu, Sysprep) [iso-templates.md](../iso-templates.md#windows-server)
içinde anlatılıyor (İngilizce).

## Panel Windows için ne yapar

*Admin → OS templates* sayfasında *Operating systems* bölümüne işletim sistemini (örneğin "Windows
Server") **Windows** türüyle ekleyin, sürümünü (2022) ekleyin ve OS şablonu için o sürümü seçin:
platformu `windows` olur. Platform şunları belirler:

| İşlem | Windows'ta nasıl çalışır |
|---|---|
| İlk açılış | Proxmox'un cloud-init sürücüsü (config drive) şifreyi (`admin_pass`), ağ ayarlarını ve hostname'i (user-data içinde) taşır. Cloudbase-Init bunları uygular |
| Ek IP adresleri, birincil adres | Varsayılan `addresses.ps1` guest scripti (ADR 0010, 0011), QEMU guest agent üzerinden çalışır. Adresleri her açılışta yeniden uygulayan **"Proxmotion addresses" zamanlanmış görevini** kaydeder |
| Yükseltmeyle büyüyen disk | Varsayılan `disk.ps1`, `C:` bölümünü genişletir |
| Şifre sıfırlama | Guest agent **`Administrator`** kullanıcısının şifresini ayarlar (ya da şablonun kendi `password_reset` scripti çalışır) |
| Hostname değişikliği | Varsayılan `hostname.ps1`, bilgisayarı hostname'in ilk kısmıyla (en fazla 15 karakter) yeniden adlandırır. Windows bunu bir sonraki yeniden başlatmada uygular: istenirse panel sunucuyu yeniden başlatır, aksi hâlde *yeniden başlatma gerekli* uyarısı gösterir (ADR 0012) |

Scriptler şablon bazında *OS templates → Scripts* sayfasından değiştirilebilir.

Kurulumdan hemen sonra guest agent, **Windows kurulumunu bitirmeden** cevap vermeye başlar:
Sysprep'in *specialize* aşamasında zaten çalışır, Sysprep ve Cloudbase-Init ise sunucuyu sonrasında
hâlâ yeniden başlatır. Bu yüzden yeni kurulan ya da yeniden kurulan bir Windows sunucusunda görevin
*guest agent'ı bekleme* adımı, agent ilk cevap verdiğinde bitmez. Panel agent üzerinden birkaç
saniyede bir şunları kontrol eder:

- `HKLM\SOFTWARE\Microsoft\Windows\CurrentVersion\Setup\State` altındaki `ImageState` değeri
  `IMAGE_STATE_COMPLETE` mi (bu değeri hiç olmayan bir şablon tamamlanmış sayılır),
- `cloudbase-init` servisi artık çalışmıyor mu (`Stopped` ya da kurulu değil).

Aradaki yeniden başlatmalar, agent yokken, sadece beklenir. Sunucu ancak bundan sonra *Active*
olur; adres scripti ve şablonun kendi scripti de ancak o zaman çalışır. Agent 10 dakika içinde hiç
cevap vermezse (agent'ı olmayan bir şablon) adım, Linux'taki gibi bu notla biter; kurulum 15 dakika
içinde bitmezse adım *Windows setup did not finish within 15 minutes* notunu düşer ve görev yine de
devam eder (sunucu kurulumunu hâlâ sürdürüyor olabilir).

Scriptler geç gelen bir yeniden başlatmayla da başa çıkar: bir script çalışırken agent kaybolursa
panel geri gelmesini bekler ve scripti yeniden çalıştırır (en fazla üç kez); Windows scriptleri de
ağa dokunmadan önce Cloudbase-Init'in bitmesini bekler. Şablondan çıkardığınız her yeniden
başlatma, yeni sunucunun daha erken hazır olması demektir.

**Henüz gerçek bir Windows sunucusunda doğrulanmadı:** yukarıdaki kontrol ve agent'ın bir yeniden
başlatma ile Cloudbase-Init servisinin başlaması arasındaki kısa sürede cevap verip veremeyeceği
(bu, kontrolün erken geçmesine yol açardı; o durumu scriptlerin yeniden denemeleri karşılar).
Askıdan çıkarılan bir sunucuda yalnızca agent beklenir.

## Yeni bir Windows sunucusu neden yeniden başlar

Sysprep ile genelleştirilmiş bir şablondan klonlanan sunucu **en az bir kez** yeniden başlar:
Windows kurulumu *specialize* aşamasını OOBE'ye geçmeden önce bir yeniden başlatmayla bitirir
(`C:\Windows\Panther\UnattendGC` altındaki `setupact.log`: *Reboot required after setup.exe and
PostSysprep commands, before launching OOBE*). Bunu kaldıran bir ayar yoktur.

İki yeniden başlatma daha Cloudbase-Init'in varsayılanlarından gelir ve kaldırılabilir:

| Yeniden başlatma | Neden | Çözüm |
|---|---|---|
| İlk açılışta Cloudbase-Init servisi (1074 olayında `python.exe`) | `UserDataPlugin` hostname'i en sonda user-data'dan ayarlar (`Changing hostname to '…'`, ardından `Rebooting`) ve servisin yeniden başlatmasına izin vardır (`allow_reboot=true`) | `UserDataPlugin`'i specialize aşamasında çalıştırın (aşağıdaki `cloudbase-init-unattend.conf`): isim değişikliği Windows kurulumunun kendi yeniden başlatmasıyla uygulanır |
| specialize aşamasının ortasında `winlogon.exe` (`C:\Windows\Panther\setupact.log` içinde `Unattend action requested immediate reboot and recall`) | Cloudbase-Init'in `Unattend.xml` dosyası onu specialize aşamasında `<WillReboot>OnRequest</WillReboot>` ve `&& exit 1 \|\| exit 2` ile çalıştırır: çıkış kodu 1, Windows kurulumundan yeniden başlatma ister; yani Cloudbase-Init başarılı olsa da yeniden başlar | `<WillReboot>Never</WillReboot>` ve `& exit 0` (aşağıdaki `Unattend.xml`) |

Hostname için neden user-data eklentisi gerekiyor: Proxmox hostname'i **sadece user-data'ya**
yazar (`#cloud-config`, `hostname: …`). `meta_data.json` içinde yalnızca `uuid`, `admin_pass` ve
SSH anahtarları vardır. Bu yüzden metadata'yı okuyan `SetHostNamePlugin` bir isim bulamaz ve
hiçbir şey yapmaz.

Windows Server 2022'de ölçüldü (2026-10-20): üç düzeltmeyle yeni bir sunucu bir kez yeniden
başladı. `Unattend.xml` düzeltmesinden önce iki kez yeniden başlıyordu (ikisi de `winlogon.exe`) ve
kurulum, ilk açılıştan Cloudbase-Init servisinin ağı ve şifreyi ayarlamasına kadar yaklaşık 2,5
dakika sürdü. Yeni bir Windows sunucusunun hazır olması bundan çok daha uzun sürüyorsa görevin
*clone* adımına bakın: büyük bir Windows diskinin tam klonu yavaş depolamada dakikalar sürebilir.

## Önerilen yapılandırma

Üç dosya da `C:\Program Files\Cloudbase Solutions\Cloudbase-Init\conf\` içindedir.

`cloudbase-init-unattend.conf` (Sysprep'in specialize aşamasında çalışır; kendiliğinden asla
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
types=iso
locations=cdrom
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
types=iso
locations=cdrom
```

`Unattend.xml`: `specialize` aşamasında yalnızca Cloudbase-Init komutunu değiştirin, böylece hiç
yeniden başlatma istemez (`&amp;` olduğu gibi kalmalı: düz `&` XML'i bozar):

```xml
<RunSynchronousCommand wcm:action="add">
  <Order>1</Order>
  <Path>cmd.exe /c ""C:\Program Files\Cloudbase Solutions\Cloudbase-Init\Python\Scripts\cloudbase-init.exe" --config-file "C:\Program Files\Cloudbase Solutions\Cloudbase-Init\conf\cloudbase-init-unattend.conf" &amp; exit 0"</Path>
  <Description>Run Cloudbase-Init to set the hostname</Description>
  <WillReboot>Never</WillReboot>
</RunSynchronousCommand>
```

Notlar:

- `UserDataPlugin`'i taşımak bir şey kaybettirmez: Proxmox'un user-data'sından Windows'ta yalnızca
  `hostname` çalışır. `user`, `password`, `chpasswd`, `fqdn`, `disable_root` ve `manage_etc_hosts`
  logda *not supported* olarak geçer. Şifre metadata'dan (`CreateUserPlugin`,
  `SetUserPasswordPlugin`), ağ `NetworkConfigPlugin`'den gelir.
- `metadata_services` içinde yalnızca `ConfigDriveService` olması, Cloudbase-Init'in Proxmox'ta
  zaten zaman aşımına düşecek ağ metadata servislerini (EC2, OpenStack HTTP) denemesini önler
  (yaklaşık iki dakika).
- Proxmox'un CloudInit sürücüsü bir CD sürücüsündeki ISO'dur, bu yüzden `[config_drive]` yalnızca
  orada arar; `hdd` üzerinde `vfat` sadece *"mtools_path" needs to be provided* uyarısı yazar. Bu
  seçenekler, logun eskimiş (*deprecated*) dediği `config_drive_cdrom`, `config_drive_vfat` ve
  `config_drive_raw_hdd` seçeneklerinin yerine geçer.
- Unattend dosyasında `allow_reboot=false` kalmalı: orada bir yeniden başlatma Sysprep'i bozar.
- `WillReboot` `Never` olunca, specialize aşamasında başarısız olan bir Cloudbase-Init çalışması
  artık yeniden başlatılıp tekrarlanmaz; o zaman yalnızca hostname atlanır (şifre ve ağ ilk
  açılışta servisten gelir). Logu `cloudbase-init-unattend.log` dosyasıdır.
- `LocalScripts\` içindeki kendi scriptleriniz ilk açılışta çalışır ve kendinden sonraki her şeyi
  geciktirir. Logda her birinin ne kadar sürdüğü görünür (`Script "…" ended with exit code`).
  Orada sabit beklemelerden kaçının.

## Yapılandırmayı şablona uygulamak

Dosyalar şablon imajının içinde durur:

1. Şablonun tam bir klonunu oluşturun (ya da şablonun yapıldığı VM'i başlatın) ve oturum açın.
2. Üç dosyayı yukarıdaki gibi düzenleyin.
3. Sysprep'ten hemen önce Edge'in kullanıcıya özel paketini kaldırın. VM internete çıkar çıkmaz
   Edge kendini günceller ve Sysprep *Package Microsoft.MicrosoftEdge.Stable_… was installed for a
   user, but not provisioned for all users* hatasıyla durur
   (`C:\Windows\System32\Sysprep\Panther\setuperr.log` içinde `0x80073cf2`). Edge'in kendisi
   kurulu kalır:

   ```powershell
   Get-AppxPackage -AllUsers *MicrosoftEdge.Stable* | Remove-AppxPackage -AllUsers
   ```

4. Cloudbase-Init'in yanıt dosyasıyla yeniden genelleştirin ve kapanmasını bekleyin:

   ```bat
   C:\Windows\System32\Sysprep\sysprep.exe /generalize /oobe /shutdown /unattend:"C:\Program Files\Cloudbase Solutions\Cloudbase-Init\conf\Unattend.xml"
   ```

5. Proxmox'ta VM'i şablona çevirin (bkz. [iso-templates.md](../iso-templates.md#updating-a-template)).
   Yeni bir VMID ise panelde cluster'ı senkronlayıp OS şablonunu ona eşleyin.
6. Bir test sunucusu oluşturun ve System günlüğünde `winlogon.exe`'den tek bir 1074 olayı olduğunu
   (şablonun kendi Sysprep kapanışı hariç), Cloudbase-Init'ten hiç olmadığını ve `hostname`
   komutunun sunucunun adını yazdığını kontrol edin.

## Sorun giderme

| Gördüğünüz | Anlamı / ne yapmalı |
|---|---|
| Görev adımında *The additional addresses could not be configured inside the server: QEMU guest agent is not running* | Script çalışırken Windows yeniden başladı ve üç denemede de geri gelmedi. Olay günlüğüne (1074) bakın. Zamanlanmış görev adresleri bir sonraki açılışta uygular; ya da Scripts sayfasında *Sunucuda çalıştır*'ı kullanın |
| *Get-NetAdapter : Critical error* | Windows hâlâ kuruluyordu, ağ komutları hazır değildi. Güncel scriptler bunu bekler; varsayılanın yerine kendi scriptini kullanan şablonları güncelleyin |
| *Proxmotion addresses* zamanlanmış görevi yok | Adres scripti o noktaya hiç gelemedi. PowerShell'de: `Get-ScheduledTask -TaskName 'Proxmotion addresses'` |
| Hostname hâlâ `WIN-…` | User-data eklentisi çalışmadı. `cloudbase-init-unattend.log` (eski yapılandırmada `cloudbase-init.log`) içinde `Changing hostname` arayın |
| Şifre ayarlanmadı | `cloudbase-init.log` içinde `Using admin_pass metadata user password` görünmeli; Proxmox VM'inde CloudInit sürücüsü olmalı |
| İki yeniden başlatma, ikisi de `winlogon.exe` | `Unattend.xml` Cloudbase-Init'ten sonra hâlâ yeniden başlatma istiyor (`<WillReboot>OnRequest</WillReboot>`, `exit 1`): *Önerilen yapılandırma*'ya bakın |
| Sysprep duruyor: *Package Microsoft.MicrosoftEdge.Stable_… was installed for a user, but not provisioned for all users* | Edge klonda kendini güncelledi. Kullanıcıya özel paketini kaldırıp Sysprep'i yeniden çalıştırın (*Yapılandırmayı şablona uygulamak*, 3. adım). Store uygulamaları da aynı şekilde durdurabilir; paketin adı `C:\Windows\System32\Sysprep\Panther\setuperr.log` içinde yazar |

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
- Yukarıdaki yapılandırmayla tek yeniden başlatma bir Windows Server 2022 şablonunda görüldü
  (2026-10-20); hostname'in bununla uygulanması, Windows Server 2019 ve 2025, ve farklı bir
  `Unattend.xml` yazan bir Cloudbase-Init sürümüyle hazırlanmış şablon denenmedi.
