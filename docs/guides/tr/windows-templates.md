# Windows şablonları

*English: [../windows-templates.md](../windows-templates.md). İki metin farklıysa İngilizcesi geçerlidir.*

Windows şablonlarıyla ilgili her şey tek yerde: şablonu Proxmox'ta hazırlamak, yeni bir sunucu tek
yeniden başlatmayla çabucak hazır olsun diye Cloudbase-Init ve Sysprep'i ayarlamak, hızlı
klonlansın diye şablonu küçük tutmak, şablonu güncellemek, panelin Windows sunucularını nasıl
yönettiği ve bir şey çalışmadığında neye bakılacağı.

**İçindekiler**

1. [Yeni bir sunucu ne kadar hızlı hazır olabilir](#yeni-bir-sunucu-ne-kadar-hızlı-hazır-olabilir)
2. [Şablonu hazırlamak](#şablonu-hazırlamak): [ISO'lar](#1-isolar) · [VM](#2-vmi-oluşturun) · [Kurulum](#3-windowsu-kurun) · [Hazırlık](#4-windowsu-hazırlayın) · [Cloudbase-Init](#5-cloudbase-initi-kurun-ve-ayarlayın) · [Küçültme ve Sysprep](#6-küçültün-ve-genelleştirin) · [Şablon](#7-vmi-şablona-çevirin) · [Panel](#8-panele-ekleyin)
3. [Yapılandırma dosyaları](#yapılandırma-dosyaları)
4. [Şablonu güncellemek](#şablonu-güncellemek)
5. [Panel Windows için ne yapar](#panel-windows-için-ne-yapar)
6. [Yeni bir Windows sunucusu neden yeniden başlar](#yeni-bir-windows-sunucusu-neden-yeniden-başlar)
7. [Sorun giderme](#sorun-giderme)
8. [Henüz doğrulanmayanlar](#henüz-doğrulanmayanlar)

## Yeni bir sunucu ne kadar hızlı hazır olabilir

Windows Server 2022, 2 vCPU, 4 GiB, LVM-thin üzerinde ölçüldü (2026-10-20). Bu rehberin
yapılandırması kullanıldı, *ölçülmedi* diye işaretlenenler hariç:

| Aşama | Süre | Neye bağlı |
|---|---|---|
| Şablonun full clone'u | 2:00 | Şablon diskinin dolu kısmı ve storage'ın hızı. **Linked clone** saniyeler sürer |
| İlk açılış: Sysprep'in *specialize* aşaması ("Getting ready"), ardından tek yeniden başlatma | 1:01 | vCPU sayısı; aygıt kurulumu (`PersistAllDeviceInstalls`, ölçülmedi) |
| İkinci açılıştan OOBE'nin bitmesine kadar | 0:20 | vCPU sayısı |
| Cloudbase-Init servisi (ağ, şifre) | 0:26 | 10 sn'si bu rehberin çıkardığı `CreateUserPlugin`'di (ölçülmedi) |
| Panelin kurulumun bittiğini görmesi | birkaç saniye | Her 5 sn'de bir kontrol eder |

Kazandıranlar, büyükten küçüğe:

1. OS şablonunda **Klonlama yöntemi *Linked clone*** ([ADR 0027](../../adr/0027-linked-clones.md)):
   yaklaşık 2 dakika daha az. Sunucular bu durumda şablona bağımlı olur; önce ADR'yi okuyun.
2. **Küçük bir şablon** ([6. adım](#6-küçültün-ve-genelleştirin)): full clone, thin bir diskin
   yalnızca dolu bloklarını kopyalar; şablondan silinen her GB klonu kısaltır.
3. Pakette **daha çok vCPU**: kurulum aşamaları çoğunlukla işlemci işidir.
4. **Üç yerine tek yeniden başlatma** ve **gereksiz Cloudbase-Init eklentilerinin olmaması**
   ([yapılandırma dosyaları](#yapılandırma-dosyaları)).

Sysprep ile genelleştirilmiş bir imaj her zaman *specialize* aşamasına ve bir yeniden başlatmaya
ihtiyaç duyar; ilk açılıştan itibaren en az yaklaşık 1,5 dakika bekleyin. Daha azı yalnızca
Sysprep olmadan mümkündür ve bunu Microsoft desteklemez (her sunucu aynı makine SID'ini ve aynı
Uzak Masaüstü sertifikasını taşır).

## Şablonu hazırlamak

### 1. ISO'lar

**ISO image** içerik türü olan bir storage'a (*Node → `local` → ISO Images → Upload* ya da
*Download from URL*):

- **Windows Server** ISO'su (2019, 2022 ya da 2025; deneme sürümü veya toplu lisans medyanız) ve
- **VirtIO sürücüleri** ISO'su, Fedora projesinin kararlı sürümü:
  `https://fedorapeople.org/groups/virt/virtio-win/direct-downloads/stable-virtio/virtio-win.iso`.

### 2. VM'i oluşturun

*Create VM*; sunucularınızın aralığı dışında bir VMID (ör. 9000 ve üstü) ve
`tpl-windows-2022-20261020` gibi bir isimle.

| Sekme | Ayar |
|---|---|
| General | *Resource Pool*: API token'ı bir havuzla sınırlıysa panelin havuzu |
| OS | Windows Server ISO'su. *Type* **Microsoft Windows**, uygun sürüm: Proxmox, Cloudbase-Init'in okuyacağı şifreyi yalnızca Windows türündeki VM'ler için yazar. **Add additional drive for VirtIO drivers** kutusunu işaretleyip `virtio-win.iso`'yu seçin |
| System | *Machine* q35, *BIOS* **OVMF (UEFI)**, *Add EFI Disk* (pre-enrolled keys), *SCSI Controller* VirtIO SCSI single, **QEMU Agent** açık. Windows Server TPM istemez |
| Disks | Bus SCSI, **40 GiB** (Windows Server en az 32 ister; panel diski pakete göre büyütür), **Discard** ve **SSD emulation** açık, sunucularınızın kullandığı storage'da |
| CPU | 1 soket, hazırlarken **4 çekirdek** (güncellemeler daha hızlı kurulur; panel her sunucunun kendi sayısını ayarlar), *Type* varsayılan (`x86-64-v2-AES`), `host` değil; böylece klonlar her node'da çalışır |
| Memory | 4096 MiB, ballooning kapalı |
| Network | Model VirtIO, güncellemeler için internete çıkan bir bridge |

### 3. Windows'u kurun

1. Konsolda *Press any key to boot from CD or DVD* görününce bir tuşa basın.
2. Lisansınız olan *Desktop Experience* ya da *Core* sürümünü seçin.
3. *Where do you want to install Windows?* ekranında disk görünmez: *Load driver* → VirtIO CD'si →
   `vioscsi\<Windows sürümünüz>\amd64`. Görünen diske kurun.
4. Geçici bir Administrator şifresi belirleyip oturum açın.

### 4. Windows'u hazırlayın

1. VirtIO CD'sinde **`virtio-win-guest-tools.exe`** çalıştırın: bütün VirtIO sürücüleri ve QEMU guest
   agent.
2. Bütün Windows güncellemelerini kurun ve *Settings → Windows Update* hiçbir şey önermeyene ve
   yeniden başlatma istemeyene kadar yeniden başlatın. Genelleştirirken bekleyen güncellemeler
   **her** yeni sunucuda, fazladan yeniden başlatmalarla tamamlanır.
3. Yönetici olarak açılan PowerShell'de, bir VPS müşterisinin beklediklerini açın, sunucuyu
   yalnızca yavaşlatanları kapatın:

   ```powershell
   # Remote Desktop (off by default on Windows Server) and its firewall rules; the group is
   # named by its resource string so this works in every display language
   Set-ItemProperty 'HKLM:\System\CurrentControlSet\Control\Terminal Server' -Name fDenyTSConnections -Value 0
   Enable-NetFirewallRule -Group '@FirewallAPI.dll,-28752'
   # Answer ping (IPv4 and IPv6), useful for customers' monitoring
   Enable-NetFirewallRule -Name FPS-ICMP4-ERQ-In, FPS-ICMP6-ERQ-In
   # No Server Manager window at every logon, no hibernation file, high-performance power plan
   Get-ScheduledTask -TaskName ServerManager | Disable-ScheduledTask
   powercfg /hibernate off
   powercfg /setactive SCHEME_MIN
   ```

   Uzak Masaüstü, Ağ Düzeyinde Kimlik Doğrulama (NLA) açık kalarak çalışır. Windows güvenlik
   duvarının kendisini açık bırakın. Ping'e cevap vermek bir tercihtir: çoğu VPS müşterisi bunu
   bekler, ama ICMP'ye cevap vermeyen sunucular istiyorsanız o satırı çıkarın.

### 5. Cloudbase-Init'i kurun ve ayarlayın

Cloudbase-Init, cloud-init'in Windows karşılığıdır: Proxmox'un CloudInit sürücüsünü okur ve
Administrator şifresini, ağı ve bilgisayar adını ayarlar.

1. `https://cloudbase.it/cloudbase-init/` adresinden indirip kurun. Kurulumda kullanıcı adı olarak
   `Administrator` yazın ve *Run Cloudbase-Init service as LocalSystem* kutusunu işaretleyin.
   **Henüz Finish'e basmayın.**
2. `C:\Program Files\Cloudbase Solutions\Cloudbase-Init\conf\` içindeki üç dosyayı
   [Yapılandırma dosyaları](#yapılandırma-dosyaları) bölümündeki gibi değiştirin:
   `cloudbase-init-unattend.conf`, `cloudbase-init.conf` ve `Unattend.xml`'in işaretli iki kısmı.

### 6. Küçültün ve genelleştirin

Şablon diskinde dolu kalan her GB, her yeni sunucu için kopyalanır (full clone). Yönetici olarak
açılan PowerShell'de:

```powershell
# Edge updates itself while the VM is online; Sysprep then stops with 0x80073cf2
# ("installed for a user, but not provisioned for all users"). Edge itself stays installed.
Get-AppxPackage -AllUsers *MicrosoftEdge.Stable* | Remove-AppxPackage -AllUsers
# Superseded update files (afterwards installed updates can no longer be uninstalled)
Dism.exe /Online /Cleanup-Image /StartComponentCleanup /ResetBase
# Temporary files and the update download cache
Remove-Item "$env:TEMP\*", 'C:\Windows\Temp\*' -Recurse -Force -ErrorAction SilentlyContinue
Stop-Service wuauserv; Remove-Item 'C:\Windows\SoftwareDistribution\Download\*' -Recurse -Force -ErrorAction SilentlyContinue
# Tell the storage which blocks are free (needs Discard on the disk)
Optimize-Volume -DriveLetter C -ReTrim -Verbose
```

Sonra Cloudbase-Init kurulumuna dönün, **Run Sysprep to create a generalized image** ve
**Shutdown when Sysprep terminates** kutularını işaretleyip *Finish*'e basın. VM kendini
genelleştirir ve kapanır. **Bir daha başlatmayın**: ilk açılış kurulumunu çalıştırır ve şablon
bozulur. (Kurulum sihirbazı olmadan, [Şablonu güncellemek](#şablonu-güncellemek) bölümündeki
Sysprep komutunu çalıştırın.)

### 7. VM'i şablona çevirin

1. *Hardware*: **iki** CD/DVD sürücüsünü de kaldırın.
2. *Hardware → Add → CloudInit Drive*, diskle aynı storage'da. *Cloud-Init* sekmesini boş bırakın:
   panel her sunucu için doldurur.
3. *Options*: *Boot Order* yalnızca disk (`scsi0`), *QEMU Guest Agent* açık.
4. *More → Convert to template*.

**Yerel** storage'lı bir cluster'da şablon, sunucu barındıracak her node'da gerekir (*Clone →
Target node*, mod *Full clone* ile oraya klonlayıp klonu şablona çevirin). **Paylaşımlı**
storage'da bir tane yeter. **Linked clone** için ayrıca şablonun, sunucunun diskinin gideceği
storage'ın tam kendisinde olması gerekir.

### 8. Panele ekleyin

1. Panelin API token'ı bir kaynak havuzuyla sınırlıysa şablonu o havuza ekleyin
   (*Datacenter → Permissions → Pools → `<havuz>` → Members*).
2. *Admin → Clusters*: senkronlayın.
3. *Admin → OS templates*: *Operating systems* altında türü **Windows** olan sistemi (ör.
   "Windows Server") ve sürümünü (2022) ekleyin, sonra o sürümle OS şablonunu ekleyip Proxmox
   şablonunu ona eşleyin. *Klonlama yöntemi*ni seçin (bkz.
   [Ne kadar hızlı](#yeni-bir-sunucu-ne-kadar-hızlı-hazır-olabilir)).
4. Bir test sunucusu oluşturup kontrol edin:
   - görevin *wait for the guest agent* adımı, konsolda giriş ekranı göründüğü sıralarda bitiyor ve
     sunucu *Active* oluyor;
   - **Uzak Masaüstü** ile `Administrator` olarak, panelin gösterdiği şifreyle giriş
     yapılabiliyor;
   - `hostname` sunucunun adını, `C:` paket boyutunu, `ipconfig` panelin adresini gösteriyor;
   - System günlüğünde, şablonun kendi Sysprep kapanışı dışında `winlogon.exe`'den tek bir 1074
     olayı var, Cloudbase-Init'ten hiç yok:
     `Get-WinEvent -FilterHashtable @{LogName='System'; Id=1074} -MaxEvents 5 | Format-List TimeCreated, Message`.

Lisanslama ve aktivasyon (SPLA, AVMA, KMS) sizin sorumluluğunuzdadır; siz eklemedikçe şablonda
ürün anahtarı yoktur.

## Yapılandırma dosyaları

Üçü de `C:\Program Files\Cloudbase Solutions\Cloudbase-Init\conf\` içindedir.

`cloudbase-init-unattend.conf`, Sysprep'in *specialize* aşamasında çalışır. Bilgisayar adını orada
ayarlar; böylece isim, Windows kurulumunun zaten yaptığı yeniden başlatmayla uygulanır.
Kendiliğinden asla yeniden başlatmaz:

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

`cloudbase-init.conf` her açılışta çalışan servistir. Ağı ve şifreyi ayarlar:

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
plugins=cloudbaseinit.plugins.common.networkconfig.NetworkConfigPlugin,cloudbaseinit.plugins.common.sshpublickeys.SetUserSSHPublicKeysPlugin,cloudbaseinit.plugins.common.setuserpassword.SetUserPasswordPlugin,cloudbaseinit.plugins.common.localscripts.LocalScriptsPlugin
allow_reboot=true
stop_service_on_exit=true

[config_drive]
types=iso
locations=cdrom
```

`Unattend.xml`: iki kısmı değiştirin, gerisini Cloudbase-Init'in kurduğu gibi bırakın.

1. `specialize` aşamasında Cloudbase-Init komutu yeniden başlatma istememeli.
   `&amp;&amp; exit 1 || exit 2` yerine `&amp; exit 0`, `OnRequest` yerine `Never` yazın (`&amp;`
   olduğu gibi kalmalı: düz `&` XML'i bozar):

   ```xml
   <RunSynchronousCommand wcm:action="add">
     <Order>1</Order>
     <Path>cmd.exe /c ""C:\Program Files\Cloudbase Solutions\Cloudbase-Init\Python\Scripts\cloudbase-init.exe" --config-file "C:\Program Files\Cloudbase Solutions\Cloudbase-Init\conf\cloudbase-init-unattend.conf" &amp; exit 0"</Path>
     <Description>Run Cloudbase-Init to set the hostname</Description>
     <WillReboot>Never</WillReboot>
   </RunSynchronousCommand>
   ```

2. *İsteğe bağlı:* kurulu sürücüleri koruyan bir `generalize` aşaması ekleyin (doğrudan
   `<unattend>` içine, diğer `<settings>` bölümlerinin yanına); böylece ilk açılış her aygıtı
   yeniden tanıyıp kurmaz. Bu, Cloudbase-Init'in değil Windows kurulumunun bir ayarıdır. Microsoft
   buna yalnızca her hedef makine referans makineyle birebir aynı donanıma sahipse izin verir:
   yalnızca her sunucu şablonun sanal donanımını koruduğu sürece kullanın (aynı disk veri yolu,
   ağ kartı modeli ve makine türü), emin değilseniz eklemeyin:

   ```xml
   <settings pass="generalize">
     <component name="Microsoft-Windows-PnpSysprep" processorArchitecture="amd64" publicKeyToken="31bf3856ad364e35" language="neutral" versionScope="nonSxS" xmlns:wcm="http://schemas.microsoft.com/WMIConfig/2002/State" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
       <PersistAllDeviceInstalls>true</PersistAllDeviceInstalls>
     </component>
   </settings>
   ```

Her ayarın nedeni:

- **Servisin eklentileri.** `CreateUserPlugin` var olan Administrator'ın şifresini yalnızca bir kez
  daha ayarlıyordu ve 10 sn sürüyordu; `SetUserPasswordPlugin` şifreyi metadata'dan
  (`admin_pass`) saniyenin çok küçük bir kısmında ayarlar. `ExtendVolumesPlugin` *specialize*
  aşamasında zaten çalıştı; sonraki disk yükseltmelerini panelin `disk.ps1` scripti genişletir. Bu
  yüzden ikisi de çıkarıldı (*henüz ölçülmedi*). Bu, Windows Server'daki gibi yerleşik
  `Administrator` hesabının var ve etkin olmasına dayanır: Administrator'ı devre dışı bırakılmış ya
  da adı değiştirilmiş bir şablonda `CreateUserPlugin` geri eklenmelidir, yoksa şifre hiç
  ayarlanmaz.
- **`UserDataPlugin` specialize aşamasında.** Proxmox hostname'i yalnızca user-data'ya yazar
  (`#cloud-config`, `hostname: …`); `meta_data.json` içinde yalnızca `uuid`, `admin_pass` ve SSH
  anahtarları vardır, bu yüzden `SetHostNamePlugin` isim bulamaz. Proxmox'un user-data'sından
  Windows'ta yalnızca `hostname` çalışır; `user`, `password`, `chpasswd`, `fqdn`, `disable_root` ve
  `manage_etc_hosts` *not supported* olarak loglanır, yani bir şey kaybedilmez.
- Yalnızca `ConfigDriveService` içeren **`metadata_services`**, Cloudbase-Init'in Proxmox'ta zaten
  zaman aşımına düşecek ağ metadata servislerini (EC2, OpenStack HTTP) denemesini önler
  (yaklaşık iki dakika).
- **`[config_drive]`**: Proxmox'un CloudInit sürücüsü bir CD sürücüsündeki ISO'dur; `hdd`
  üzerinde `vfat` yalnızca *"mtools_path" needs to be provided* uyarısı yazar. Bu seçenekler
  eskimiş `config_drive_cdrom`, `config_drive_vfat` ve `config_drive_raw_hdd` seçeneklerinin
  yerine geçer.
- Unattend dosyasında **`allow_reboot=false`**: orada bir yeniden başlatma Sysprep'i bozar.
- **`WillReboot` `Never`**: specialize aşamasında başarısız olan bir Cloudbase-Init çalışması artık
  yeniden başlatılıp tekrarlanmaz; o zaman yalnızca hostname atlanır (şifre ve ağ servisten
  gelir). Logu `cloudbase-init-unattend.log` dosyasıdır.
- **`PersistAllDeviceInstalls`** (isteğe bağlı, Microsoft-Windows-PnpSysprep), şablonun aygıtlarını
  korur; şablonun sanal donanımı değiştirilmediği sürece bunlar her klonla aynıdır ve "Getting
  ready" süresini kısaltması beklenir (*henüz ölçülmedi*). Microsoft, küçük donanım farklarının
  bile ciddi sorunlara yol açabileceği konusunda uyarır; çekirdek sayısı, RAM miktarı ve MAC
  adresi böyle bir fark değildir, farklı bir disk veri yolu ya da ağ kartı modeli öyledir.
- **`LocalScripts\`** içindeki scriptler ilk açılışta çalışır ve kendilerinden sonraki her şeyi
  geciktirir; logda her birinin ne kadar sürdüğü görünür (`Script "…" ended with exit code`).
  Orada sabit beklemelerden kaçının.

## Şablonu güncellemek

Güvenlik güncellemeleri için şablonu her ay yenileyin:

1. Şablonu *Clone* ile kopyalayın (mod **Full clone**), klonu başlatıp konsoldan oturum açın. Klon
   ilk açılış kurulumunu bir kez çalıştırır; istendiğinde bir şifre belirleyin.
2. Güncellemeleri kurun ve bekleyen kalmayana kadar yeniden başlatın ([4. adım](#4-windowsu-hazırlayın)),
   sonra [6. adımdaki](#6-küçültün-ve-genelleştirin) PowerShell bloğunu çalıştırın.
3. Cloudbase-Init'in yanıt dosyasıyla genelleştirin ve kapanmasını bekleyin:

   ```bat
   C:\Windows\System32\Sysprep\sysprep.exe /generalize /oobe /shutdown /unattend:"C:\Program Files\Cloudbase Solutions\Cloudbase-Init\conf\Unattend.xml"
   ```

4. [7. adımı](#7-vmi-şablona-çevirin) yeni, tarihli bir isimle yapın, panelde cluster'ı senkronlayıp
   OS şablonunu yeni şablona eşleyin.
5. Eski şablonu, onu kullanan kalmayınca silin. Full clone'lar ona bağlı değildir. **Linked clone'lar**
   temel diskini kullandığı sürece Proxmox silmeyi reddeder: o sunucular yeniden kurulana ya da
   silinene kadar saklayın ve eski şablonlar için storage planlayın.

## Panel Windows için ne yapar

İşletim sisteminin **Windows** türü, OS şablonunun platformunu `windows` yapar. Platform şunları
belirler:

| İşlem | Windows'ta nasıl çalışır |
|---|---|
| İlk açılış | Proxmox'un CloudInit sürücüsü (config drive) şifreyi (`admin_pass`), ağ ayarlarını ve hostname'i (user-data içinde) taşır. Cloudbase-Init bunları uygular |
| Ek IP adresleri, birincil adres | Varsayılan `addresses.ps1` guest scripti (ADR 0010, 0011), QEMU guest agent üzerinden çalışır. Adresleri her açılışta yeniden uygulayan bir **"Proxmotion addresses" zamanlanmış görevi** kaydeder |
| Yükseltmeyle büyüyen disk | Varsayılan `disk.ps1`, `C:` bölümünü genişletir |
| Şifre sıfırlama | Guest agent **`Administrator`** kullanıcısının şifresini ayarlar (ya da şablonun kendi `password_reset` scripti) |
| Hostname değişikliği | Varsayılan `hostname.ps1`, bilgisayarı hostname'in ilk kısmıyla (en fazla 15 karakter) yeniden adlandırır. Windows bunu bir sonraki yeniden başlatmada uygular: istenirse panel sunucuyu yeniden başlatır, yoksa *restart required* gösterir (ADR 0012) |
| Uygulamalar | Yalnızca Windows uygulamaları (IIS, SQL Server Express, Chocolatey ve `platform: windows` olan tarifler): tarif `C:\ProgramData\Proxmotion\apps\<kurulum>` klasörüne yazılır (yalnızca SYSTEM ve Administrators okuyabilir) ve SYSTEM olarak çalışan, **`\Proxmotion\` altındaki bir zamanlanmış görev** olan `run.ps1` ile çalıştırılır; betik isterse Windows'u yeniden başlatır ve betiği sonra yeniden çalıştırır (ADR 0029). Windows PowerShell 5.1 gerekir (Windows Server 2016 ve sonrası, Windows 10/11) |

Scriptler şablon başına *OS templates → Scripts* altında değiştirilebilir.

**Yeni bir sunucu ne zaman hazırdır.** Guest agent, Windows kullanılabilir olmadan çok önce,
Sysprep'in *specialize* aşamasında cevap vermeye başlar. Bu yüzden yeni ya da yeniden kurulmuş bir
Windows sunucusunda görevin *wait for the guest agent* adımı, agent üzerinden birkaç saniyede bir
kontrol ederek şu koşullar sağlanana kadar sürer:

- `HKLM\SOFTWARE\Microsoft\Windows\CurrentVersion\Setup\State` altındaki `ImageState` değeri
  `IMAGE_STATE_COMPLETE` (bu değeri hiç olmayan bir şablon tamamlanmış sayılır) ve
- `cloudbase-init` servisi artık çalışmıyor (`Stopped` ya da kurulu değil).

Aradaki yeniden başlatmalar beklenir. Sunucu ancak o zaman *Active* olur ve adres scripti ile
şablonun kendi scripti çalışır. Agent 10 dakika içinde hiç cevap vermezse (agent'ı olmayan bir
şablon) adım, Linux'taki gibi bu notla biter; kurulum 15 dakikada bitmezse adım *Windows setup did
not finish within 15 minutes* notunu düşer ve görev devam eder. Bir yeniden başlatma yine de bir
scripti yarıda keserse panel agent'ı bekleyip scripti yeniden çalıştırır (en fazla üç kez); Windows
scriptleri de ağa dokunmadan önce Cloudbase-Init'in bitmesini bekler. Askıdan çıkarmada yalnızca
agent beklenir.

## Yeni bir Windows sunucusu neden yeniden başlar

Sysprep ile genelleştirilmiş bir şablondan klonlanan sunucu **en az bir kez** yeniden başlar:
Windows kurulumu *specialize* aşamasını OOBE'den önce bir yeniden başlatmayla bitirir
(`C:\Windows\Panther\UnattendGC\setupact.log`: *Reboot required after setup.exe and PostSysprep
commands, before launching OOBE*). Cloudbase-Init'in varsayılanları iki tane daha ekler;
[yapılandırma dosyaları](#yapılandırma-dosyaları) bunları kaldırır:

| Yeniden başlatma | Neden | Çözüm |
|---|---|---|
| İlk açılışta Cloudbase-Init servisi (1074 olayında `python.exe`) | `UserDataPlugin` hostname'i en sonda ayarlar (`Changing hostname to '…'`, ardından `Rebooting`) ve servisin yeniden başlatmasına izin vardır (`allow_reboot=true`) | `UserDataPlugin`'i specialize aşamasında çalıştırın (`cloudbase-init-unattend.conf`) |
| specialize aşamasının ortasında `winlogon.exe` (`C:\Windows\Panther\setupact.log` içinde `Unattend action requested immediate reboot and recall`) | Cloudbase-Init'in `Unattend.xml` dosyası onu `<WillReboot>OnRequest</WillReboot>` ve `&& exit 1 \|\| exit 2` ile çalıştırır: çıkış kodu 1, Cloudbase-Init başarılı olsa bile Windows kurulumundan yeniden başlatma ister | `<WillReboot>Never</WillReboot>` ve `& exit 0` |

## Sorun giderme

| Gördüğünüz | Anlamı / ne yapmalı |
|---|---|
| Uzak Masaüstü bağlanmıyor | Windows Server'da Uzak Masaüstü varsayılan olarak kapalıdır: [4. adımın](#4-windowsu-hazırlayın) ilk iki komutunu şablonda (ve ondan klonlanmış sunucularda) çalıştırın. Panelin adresinin ayarlandığını da kontrol edin (`ipconfig`) |
| İki yeniden başlatma, ikisi de `winlogon.exe` | `Unattend.xml` Cloudbase-Init'ten sonra hâlâ yeniden başlatma istiyor (`<WillReboot>OnRequest</WillReboot>`, `exit 1`): bkz. [Yapılandırma dosyaları](#yapılandırma-dosyaları) |
| `python.exe` (Cloudbase-Init) kaynaklı bir yeniden başlatma | Hostname'i hâlâ servis ayarlıyor: `UserDataPlugin` `cloudbase-init.conf`'ta değil, `cloudbase-init-unattend.conf`'ta olmalı |
| "Getting ready" uzun sürüyor, *Working on updates* | Şablon genelleştirilirken bekleyen güncellemeler vardı: şablonu bekleyen kalmayana kadar güncelleyin |
| Sysprep duruyor: *Package Microsoft.MicrosoftEdge.Stable_… was installed for a user, but not provisioned for all users* | Edge kendini güncelledi. Kullanıcıya özel paketini kaldırıp Sysprep'i yeniden çalıştırın ([6. adım](#6-küçültün-ve-genelleştirin)). Store uygulamaları da aynı şekilde durdurabilir; paketin adı `C:\Windows\System32\Sysprep\Panther\setuperr.log` içinde yazar |
| Hostname hâlâ `WIN-…` | User-data eklentisi çalışmadı. `cloudbase-init-unattend.log` içinde `Changing hostname` arayın |
| Şifre ayarlanmadı | `cloudbase-init.log` içinde `Using admin_pass metadata user password` görünmeli. VM'in bir CloudInit sürücüsü olmalı ve OS türü Windows olmalı (yoksa Proxmox şifreyi şifrelenmiş saklar ve Cloudbase-Init şifrelenmiş metni alır) |
| Görev 15 dakika sürüyor ve *Windows setup did not finish* notunu düşüyor | `cloudbase-init` servisi hiç durmadı (`stop_service_on_exit=true` eksik) ya da kurulum takıldı: konsola ve `cloudbase-init.log` dosyasına bakın |
| Görev adımında *The additional addresses could not be configured inside the server: QEMU guest agent is not running* | Script çalışırken Windows yeniden başladı ve üç kez geri gelmedi. Olay günlüğüne bakın (1074). Zamanlanmış görev adresleri bir sonraki açılışta uygular; ya da Scripts sayfasındaki *Run on server*'ı kullanın |
| *Get-NetAdapter : Critical error* | Windows hâlâ kuruluyordu, ağ komutları hazır değildi. Güncel scriptler bunu bekler; şablonun kendi scripti varsayılanın yerine geçtiyse onu güncelleyin |
| *Proxmotion addresses* zamanlanmış görevi yok | Adres scripti o noktaya hiç gelemedi: `Get-ScheduledTask -TaskName 'Proxmotion addresses'` |

Yeni bir sunucunun süresinin nereye gittiği (yeni sunucuda, Yönetici olarak PowerShell):

```powershell
Get-WinEvent -FilterHashtable @{LogName='System'; Id=12} -MaxEvents 3 | Format-Table TimeCreated
Get-WinEvent -FilterHashtable @{LogName='System'; Id=1074} -MaxEvents 5 | Format-List TimeCreated, Message
Get-Content 'C:\Program Files\Cloudbase Solutions\Cloudbase-Init\log\cloudbase-init*.log' | Select-String 'Executing plugin|Plugins execution done|ended with|Rebooting'
Select-String -Path C:\Windows\Panther\UnattendGC\setupact.log -Pattern 'ImageState'
```

12 numaralı olay her açılışı, 1074 her yeniden başlatmayı ve kimin istediğini gösterir;
Cloudbase-Init satırları her eklentinin ne kadar sürdüğünü, `ImageState` kurulumun ne zaman
bittiğini gösterir.

## Henüz doğrulanmayanlar

- Windows uygulamaları (ADR 0029) yalnızca panelin sahte Proxmox'unda çalıştı: zamanlanmış görev,
  yeniden başlatıp devam etme, log okuyucu ve üç dahili Windows recipe'si gerçek bir Windows
  konuğunda denenmedi.
- Bir kez, Windows Server 2022'de ölçüldü (2026-10-20): `Unattend.xml` değişikliğiyle tek yeniden
  başlatma, ve panelin kurulum kontrolünün sunucuyu Cloudbase-Init servisi durduktan birkaç saniye
  sonra *Active* yapması. Denenmedi: hostname'in tek yeniden başlatmayla uygulanması, Windows
  Server 2019 ve 2025, ve `Unattend.xml` dosyası farklı olan Cloudbase-Init sürümleri.
- Ölçülmedi: `PersistAllDeviceInstalls`, `CreateUserPlugin` ve `ExtendVolumesPlugin` olmadan
  servis, 6. adımdaki küçültme komutları, bir Windows şablonunun linked clone'ları.
- 4. adımdaki Uzak Masaüstü ve ping komutlarını biz çalıştırmadık; `@FirewallAPI.dll,-28752`
  güvenlik duvarı grubu ve `FPS-ICMP4-ERQ-In` / `FPS-ICMP6-ERQ-In` kural adları Windows'un kendi
  yerleşik adlarıdır.
- Windows varsayılan scriptleri tek bir Windows Server imajında çalıştı (2026-10-17): yeniden
  kurulumdan sonra ek adresler. Birincil adres değişikliği, `disk.ps1`, IPv6 ve diğer Windows
  sürümleri denenmedi.
