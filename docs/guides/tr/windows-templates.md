# Windows şablonları

*English: [../windows-templates.md](../windows-templates.md). İki metin farklıysa İngilizcesi geçerlidir.*

Bu rehber, Proxmotion için bir Windows Server şablonunun nasıl çalıştığını ve iki yolla nasıl
hazırlanacağını anlatır: **Sysprep'li** (Microsoft'un desteklediği yol) ve **Sysprep'siz** (daha
hızlı, bilinen bedelleriyle). Her adımın neden orada olduğunu açıklar; böylece onu başka bir
Windows sürümüne veya kendi imajınıza uyarlayabilir ve sonucu kontrol edebilirsiniz.

Linux şablonlarının kendi rehberi var: [iso-templates.md](iso-templates.md).

**İçindekiler**

1. [Bir Windows sunucusu şablondan nasıl doğar](#bir-windows-sunucusu-şablondan-nasıl-doğar)
2. [Klonları benzersiz yapmak: Sysprep'li veya Sysprep'siz](#klonları-benzersiz-yapmak-sysprepli-veya-sysprepsiz)
3. [Temel sistemi kurmak](#temel-sistemi-kurmak) (iki yol için): [ISO'lar](#1-isolar) · [VM](#2-vmi-oluşturun) · [Kurulum](#3-windowsu-kurun) · [Hazırlık](#4-windowsu-hazırlayın) · [Cloudbase-Init](#5-cloudbase-initi-kurun)
4. [Yol A: Sysprep'li](#yol-a-sysprepli)
5. [Yol B: Sysprep'siz](#yol-b-sysprepsiz)
6. [VM'i şablona çevirin](#vmi-şablona-çevirin) (iki yol için)
7. [Panele ekleyin ve test edin](#panele-ekleyin-ve-test-edin)
8. [Yapılandırma dosyalarının açıklaması](#yapılandırma-dosyalarının-açıklaması)
9. [Yeni bir sunucu ne kadar hızlı hazır olabilir](#yeni-bir-sunucu-ne-kadar-hızlı-hazır-olabilir)
10. [Şablonu güncellemek](#şablonu-güncellemek)
11. [Panel Windows için ne yapar](#panel-windows-için-ne-yapar)
12. [Sorun giderme](#sorun-giderme)
13. [Henüz doğrulanmayanlar](#henüz-doğrulanmayanlar)

## Bir Windows sunucusu şablondan nasıl doğar

Panel bir sunucu oluştururken şablonu klonlar; çekirdekleri, belleği, ağ kartını ve cloud-init
alanlarını (parola, adresler, DNS, VM adı) ayarlar, diski büyütür ve VM'i başlatır. OS türü
**Microsoft Windows** olan bir VM için Proxmox cloud-init alanlarını bir *config drive* olarak yazar
(`config-2` etiketli küçük bir CD):

| CD'deki dosya | İçerik | Okuyan |
|---|---|---|
| `meta_data.json` | `uuid` (*instance ID*), `admin_pass`, SSH anahtarları | Cloudbase-Init: parola |
| `network_data.json` | adresler, gateway, DNS | Cloudbase-Init: ağ |
| `user_data` | `hostname: …` içeren `#cloud-config` | Cloudbase-Init'in `UserDataPlugin`'i: bilgisayar adı |

Proxmox parolayı oraya yalnızca Windows OS türünde düz metin olarak koyar; diğer türlerde Windows'un
kullanamayacağı bir hash saklar.

**Cloudbase-Init**, cloud-init'in Windows karşılığıdır. *Plugin*'lerinin her biri tek bir iş yapar ve
hangi plugin'lerin çalıştığını registry'de instance ID başına hatırlar. Bir klonun instance ID'si
yenidir; bu yüzden her plugin onda bir kez çalışır, sonraki açılışlarda atlanır.

Panel ardından Windows hazır olana kadar bekler (bkz. [Panel ne yapar](#panel-windows-için-ne-yapar)):
guest agent cevap verir, Windows kurulumu `IMAGE_STATE_COMPLETE` bildirir ve `cloudbase-init`
servisi durur. Sonra kendi script'lerini (ek adresler) guest agent üzerinden çalıştırır.

Yani bir Windows şablonunda şunlar gerekir: **VirtIO sürücüleri** (disk ve ağ), **QEMU guest agent**,
config drive'ı okuyacak şekilde ayarlanmış **Cloudbase-Init**, açık **Uzak Masaüstü** ve her klonu
**farklı bir makine** yapacak bir yol; iki yolun ayrıldığı yer de burasıdır.

## Klonları benzersiz yapmak: Sysprep'li veya Sysprep'siz

Bir Windows kurulumu her makinede farklı olması gereken değerler taşır. Sysprep'in *generalize*
aşaması bunları kaldırır; her klonun ilk açılışı da Windows kurulumunun *specialize* aşamasını ve
OOBE'yi çalıştırıp yenilerini oluşturur. Sysprep olmadan klon birebir kopya olarak başlar ve önemli
olanları siz kaldırırsınız.

| | Yol A: Sysprep'li | Yol B: Sysprep'siz |
|---|---|---|
| Microsoft desteği | evet, bir kurulumu çoğaltmanın desteklenen tek yolu | hayır |
| Bir klonun ilk açılışı | *specialize* aşaması, bir yeniden başlatma, OOBE, sonra Cloudbase-Init (klona ek yaklaşık 1,5 dk, ölçüldü) | normal bir açılış, Cloudbase-Init, bilgisayar adı için bir yeniden başlatma (ölçülmedi; belirgin şekilde kısa olması beklenir) |
| Makine SID'i | her klonda yeni | **her sunucuda aynı** |
| Uzak Masaüstü sertifikası | yeni | şablonda silerseniz yeni (B2 adımı) |
| Windows Update / WSUS istemci kimliği | yeni | şablonda silerseniz yeni (B2 adımı) |
| KMS aktivasyonu | çalışır (yeni istemci makine kimliği) | her klon KMS sunucusuna aynı istemci gibi görünür; MAK, AVMA veya perakende anahtar kullanın |
| Sürücüler | ilk açılışta yeniden algılanır (`PersistAllDeviceInstalls` yoksa) | zaten kurulu |
| Şablonu hazırlamak | Sysprep Store uygulamalarında (Edge) durabilir | Sysprep hatası yok |

**Ortak SID'in anlamı.** Birbirine kimlik doğrulaması yapmayan bağımsız sunucularda aynı makine
SID'i uzun süre pek sorun çıkarmadı. Bu artık her yerde geçerli değil: Microsoft'un sürüm notlarına
göre Eylül 2025 güncellemelerinden beri Windows 11 24H2 ve sonrası ile **Windows Server 2025**, aynı
SID'e sahip makineler arasında NTLM ve Kerberos kimlik doğrulamasında başarısız oluyor (örneğin bir
müşterinin iki sunucusu arasındaki dosya paylaşımı) ve bu tasarım gereği. Domain controller'lar ve
bazı yönetim yazılımları aynı SID'i hiçbir zaman kabul etmedi.

**Hangisi seçilmeli.** Geniş çapta sattığınız her şey için ve Windows Server 2025 ve sonrası için
her zaman Yol A. Yol B yalnızca Windows Server 2019 ve 2022 için; ilk açılış süresi destekten daha
önemliyse ve bir şablondan oluşan sunucuların SID'i paylaşmasını kabul ediyorsanız.

Panel iki yolda da aynı çalışır; bunun için bir ayar gerekmez.

## Temel sistemi kurmak

1–5. adımlar iki yol için de aynıdır.

### 1. ISO'lar

**ISO image** içerik türü olan bir storage'a (*Node → `local` → ISO Images → Upload* veya *Download
from URL*):

- **Windows Server** ISO'su (2019, 2022 veya 2025; evaluation ya da volume licensing medyanız) ve
- Fedora projesinin stable sürümü **VirtIO sürücüleri** ISO'su:
  `https://fedorapeople.org/groups/virt/virtio-win/direct-downloads/stable-virtio/virtio-win.iso`.

### 2. VM'i oluşturun

*Create VM*; sunucularınızın aralığı dışında bir VMID (ör. 9000 ve üstü) ve
`tpl-windows-2022-sysprep-20261027` gibi bir adla. Her ayarın bir nedeni var:

| Sekme | Ayar | Neden |
|---|---|---|
| General | *Resource Pool*: API token'ı bir pool'la sınırlıysa panelin pool'u | Token yalnızca görebildiğini klonlar |
| OS | Windows Server ISO'su; *Type* **Microsoft Windows** ve uygun sürüm; `virtio-win.iso` ile **Add additional drive for VirtIO drivers**'ı işaretleyin | OS türü config drive biçimine ve parolanın Cloudbase-Init'e ulaşmasına karar verir |
| System | *Machine* q35, *BIOS* **OVMF (UEFI)** ve bir EFI diski (pre-enrolled keys), *SCSI Controller* VirtIO SCSI single, **QEMU Agent** açık. Windows Server TPM istemez | Modern firmware; agent VirtIO seri portunu ister |
| Disks | Veri yolu SCSI, **40 GiB** (Windows Server en az 32 ister), **Discard** ve **SSD emulation** açık | Panel diski pakete göre büyütür; *Discard* şablonun küçülmesini sağlar (A3/B3) |
| CPU | 1 soket, hazırlarken **4 çekirdek**, *Type* varsayılan (`x86-64-v2-AES`), `host` değil | Güncellemeler daha hızlı kurulur; `host` klonları tek bir CPU modeline bağlar. Her sunucunun sayısını panel ayarlar |
| Memory | 4096 MiB, ballooning kapalı | Her sunucunun değerini panel ayarlar |
| Network | Model VirtIO, internete çıkan bir bridge | Güncellemeler. Cloudbase-Init bu yapılandırmayı her klonda değiştirir |

### 3. Windows'u kurun

1. Konsol *Press any key to boot from CD or DVD* dediğinde bir tuşa basın.
2. Lisansınız olan *Desktop Experience* veya *Core* sürümünü seçin.
3. *Where do you want to install Windows?* ekranında disk görünmez, çünkü Windows'un VirtIO SCSI
   sürücüsü yoktur: *Load driver* → VirtIO CD'si → `vioscsi\<Windows sürümünüz>\amd64`. Görünen diske
   kurun. Windows kendi partition'larını `C:` en sonda olacak şekilde oluşturur; `C:`'nin sonradan
   büyüyebilmesini sağlayan budur.
4. Geçici bir Administrator parolası belirleyip girin.

### 4. Windows'u hazırlayın

1. VirtIO CD'sinde **`virtio-win-guest-tools.exe`**'yi çalıştırın: tüm VirtIO sürücüleri (ağ,
   balloon, seri) ve QEMU guest agent.
2. *Settings → Windows Update* hiçbir şey sunmayana ve yeniden başlatma istemeyene kadar tüm
   güncellemeleri kurup yeniden başlatın. Şablonda bekleyen güncellemeler **her** yeni sunucuda,
   ek yeniden başlatmalarla tamamlanır.
3. Yönetici olarak açılmış PowerShell'de bir VPS müşterisinin beklediğini açın, sunucuyu yalnızca
   yavaşlatanı kapatın:

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

   Uzak Masaüstü'nde Network Level Authentication açık kalır. Windows firewall'unun kendisini açık
   bırakın; panel VM'in dışında Proxmox firewall'uyla filtreler. Ping'e cevap vermek bir tercihtir.

4. VM çalışırken node'un kabuğundan **agent'ı kontrol edin**:

   ```bash
   qm guest cmd <vmid> ping
   qm guest exec <vmid> -- powershell -NoProfile -Command '$PSVersionTable.PSVersion'
   ```

   Panelin script'leri ve Windows uygulamaları Windows PowerShell 5.1 ister (Windows Server 2016 ve
   sonrası).

### 5. Cloudbase-Init'i kurun

`https://cloudbase.it/cloudbase-init/` adresinden indirip kurun: kullanıcı adı `Administrator`,
*Run Cloudbase-Init service as LocalSystem* işaretli.

Kurulumun son sayfası Sysprep'i çalıştırmayı önerir. **Yol A:** *Finish*'e henüz basmayın, sonraki
adımlar ona ihtiyaç duyar. **Yol B:** iki kutuyu da işaretsiz bırakıp *Finish*'e basın.

Yapılandırması `C:\Program Files\Cloudbase Solutions\Cloudbase-Init\conf\` içindedir:

| Dosya | Ne zaman kullanılır |
|---|---|
| `cloudbase-init.conf` | **servis** tarafından, her açılışta (iki yolda da) |
| `cloudbase-init-unattend.conf` | Windows kurulumunun *specialize* aşamasında (yalnızca Yol A) |
| `Unattend.xml` | Sysprep'in Windows kurulumuna verdiği cevap dosyası (yalnızca Yol A) |

## Yol A: Sysprep'li

### A1. Cloudbase-Init'i ayarlayın

Amaç: bir klonun ihtiyaç duyduğu her şey, Windows'un zaten yaptığı yeniden başlatmadan önce
**specialize aşamasında** ayarlansın, başka yeniden başlatma gerekmesin; servis sonra yalnızca ağı
ve parolayı ayarlasın.

`cloudbase-init-unattend.conf`, specialize aşamasında çalışır. Bilgisayar adını orada ayarlar
(Windows'un kendi yeniden başlatmasıyla geçerli olur), `C:`'yi genişletir ve kendiliğinden asla
yeniden başlatmaz:

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

`cloudbase-init.conf`, servis, her açılışta. Önce parola, sonra ağ:

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
plugins=cloudbaseinit.plugins.common.setuserpassword.SetUserPasswordPlugin,cloudbaseinit.plugins.common.networkconfig.NetworkConfigPlugin,cloudbaseinit.plugins.common.sshpublickeys.SetUserSSHPublicKeysPlugin,cloudbaseinit.plugins.common.localscripts.LocalScriptsPlugin
allow_reboot=true
stop_service_on_exit=true

[config_drive]
types=iso
locations=cdrom
```

`Unattend.xml`: iki yeri değiştirin, gerisini Cloudbase-Init'in kurduğu gibi bırakın.

1. `specialize` aşamasında Cloudbase-Init komutu yeniden başlatma istememelidir.
   `&amp;&amp; exit 1 || exit 2`'yi `&amp; exit 0` ile, `OnRequest`'i `Never` ile değiştirin
   (`&amp;`'ı olduğu gibi bırakın: çıplak bir `&` XML'i bozar):

   ```xml
   <RunSynchronousCommand wcm:action="add">
     <Order>1</Order>
     <Path>cmd.exe /c ""C:\Program Files\Cloudbase Solutions\Cloudbase-Init\Python\Scripts\cloudbase-init.exe" --config-file "C:\Program Files\Cloudbase Solutions\Cloudbase-Init\conf\cloudbase-init-unattend.conf" &amp; exit 0"</Path>
     <Description>Run Cloudbase-Init to set the hostname</Description>
     <WillReboot>Never</WillReboot>
   </RunSynchronousCommand>
   ```

2. *İsteğe bağlı:* kurulu sürücüleri koruyan bir `generalize` aşaması ekleyin (doğrudan `<unattend>`
   içine, diğer `<settings>`'lerin yanına); ilk açılış her aygıtı yeniden algılamaz. Microsoft buna
   yalnızca her hedef makine referans makineyle birebir aynı donanıma sahipse izin verir: yalnızca
   her sunucu şablonun sanal donanımını (aynı disk veri yolu, ağ modeli ve makine türü) koruduğu
   sürece kullanın, emin değilseniz eklemeyin:

   ```xml
   <settings pass="generalize">
     <component name="Microsoft-Windows-PnpSysprep" processorArchitecture="amd64" publicKeyToken="31bf3856ad364e35" language="neutral" versionScope="nonSxS" xmlns:wcm="http://schemas.microsoft.com/WMIConfig/2002/State" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
       <PersistAllDeviceInstalls>true</PersistAllDeviceInstalls>
     </component>
   </settings>
   ```

Her ayarın neden orada olduğu: [Yapılandırma dosyalarının açıklaması](#yapılandırma-dosyalarının-açıklaması).

### A2. Sysprep'in işini mümkün kılın

Bir Store uygulaması bir kullanıcı için güncellenip tüm kullanıcılar için provision edilmemişse
Sysprep genelleştirmeyi reddeder. Edge VM çevrimiçiyken kendini günceller; bu yüzden onun kullanıcı
başına paketini kaldırın (Edge'in kendisi kurulu kalır):

```powershell
Get-AppxPackage -AllUsers *MicrosoftEdge.Stable* | Remove-AppxPackage -AllUsers
```

Sysprep yine durursa `C:\Windows\System32\Sysprep\Panther\setuperr.log` paketin adını verir.

### A3. Küçültün

Şablon diskinde hâlâ kullanılan her GB, her yeni sunucu için kopyalanır (full clone):

```powershell
# Superseded update files (afterwards installed updates can no longer be uninstalled)
Dism.exe /Online /Cleanup-Image /StartComponentCleanup /ResetBase
# Temporary files and the update download cache
Remove-Item "$env:TEMP\*", 'C:\Windows\Temp\*' -Recurse -Force -ErrorAction SilentlyContinue
Stop-Service wuauserv; Remove-Item 'C:\Windows\SoftwareDistribution\Download\*' -Recurse -Force -ErrorAction SilentlyContinue
# Tell the storage which blocks are free (needs Discard on the disk)
Optimize-Volume -DriveLetter C -ReTrim -Verbose
```

### A4. Genelleştirin

Cloudbase-Init kurulumuna dönün, **Run Sysprep to create a generalized image** ve **Shutdown when
Sysprep terminates**'i işaretleyip *Finish*'e basın. (Kurulum programı olmadan
[Şablonu güncellemek](#şablonu-güncellemek) bölümündeki komutu çalıştırın.) VM kendini genelleştirir
ve kapanır.

**Onu bir daha başlatmayın**: ilk açılışı specialize aşamasını ve OOBE'yi çalıştırır, şablon artık
genelleştirilmiş olmaz. [VM'i şablona çevirin](#vmi-şablona-çevirin) ile devam edin.

## Yol B: Sysprep'siz

### B1. Cloudbase-Init'i ayarlayın

Specialize aşaması olmadan klonun ilk açılışında her şeyi **servis** yapar: parola, ağ, `C:`'nin
genişletilmesi ve bilgisayar adı. Windows yeni bir bilgisayar adını yalnızca yeniden başlatmayla
uygular; bu yüzden onu ayarlayan plugin (`UserDataPlugin`) **en sona** gelir ve servis yeniden
başlatabilir (`allow_reboot=true`); yeniden başlatmadan sonra tamamlanmış plugin'ler atlanır ve
servis durur.

`cloudbase-init.conf`'u şununla değiştirin:

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
plugins=cloudbaseinit.plugins.common.setuserpassword.SetUserPasswordPlugin,cloudbaseinit.plugins.common.networkconfig.NetworkConfigPlugin,cloudbaseinit.plugins.windows.extendvolumes.ExtendVolumesPlugin,cloudbaseinit.plugins.common.sshpublickeys.SetUserSSHPublicKeysPlugin,cloudbaseinit.plugins.common.localscripts.LocalScriptsPlugin,cloudbaseinit.plugins.common.userdata.UserDataPlugin
allow_reboot=true
stop_service_on_exit=true

[config_drive]
types=iso
locations=cdrom
```

`cloudbase-init-unattend.conf` ve `Unattend.xml` bu yolda kullanılmaz; olduğu gibi bırakın.

`cloudbase-init` servisinin başlangıç türünü **Automatic** bırakın, *Automatic (Delayed Start)*
değil: beklenecek bir kurulum aşaması olmadığından panel, servis durduğunda Windows'u hazır sayar;
henüz başlamamış bir servis de durmuş görünür.

### B2. Sunucu başına farklı olması gerekenleri silin

Bunlar, Sysprep'in sıfırlayacağı ve bir VPS için önemli olan değerlerdir. Yönetici olarak açılmış
PowerShell'de:

```powershell
# Remote Desktop certificate: Windows creates a new one when the service starts on a clone
Remove-Item -Path 'Cert:\LocalMachine\Remote Desktop\*' -Force
# Windows Update client ID: WSUS and update reports would see every server as one
Stop-Service wuauserv
Remove-ItemProperty 'HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\WindowsUpdate' -Name SusClientId, SusClientIdValidation -ErrorAction SilentlyContinue
# Cloudbase-Init's memory of instances it has seen, and its logs
Remove-Item 'HKLM:\SOFTWARE\Cloudbase Solutions\Cloudbase-Init\*' -Recurse -ErrorAction SilentlyContinue
Remove-Item 'C:\Program Files\Cloudbase Solutions\Cloudbase-Init\log\*' -ErrorAction SilentlyContinue
# Event logs of the build
wevtutil el | ForEach-Object { wevtutil cl "$_" 2>$null }
```

Ortak kalan ve Sysprep olmadan düzeltilemeyen: makine SID'i (bkz.
[karşılaştırma](#klonları-benzersiz-yapmak-sysprepli-veya-sysprepsiz)) ve KMS istemci makine kimliği.

Şablonun Administrator parolası, Cloudbase-Init onu değiştirene kadar klonda kalır; ağ
yapılandırılmadan önce `SetUserPasswordPlugin`'in çalışmasının nedeni budur. Yine de şablon için
uzun, rastgele bir parola kullanın.

### B3. Küçültün ve kapatın

[A3](#a3-küçültün)'teki komutları çalıştırın (Edge adımı gerekmez: Sysprep yok), sonra VM'i normal
şekilde kapatın:

```powershell
Stop-Computer
```

Dönüştürmeden önce **onu bir daha başlatmayın**: Cloudbase-Init ve Windows yeniden yeni değerler
yazar ve B2 tekrarlanmalıdır. [VM'i şablona çevirin](#vmi-şablona-çevirin) ile devam edin.

## VM'i şablona çevirin

VM kapalıyken:

1. *Hardware*: **iki** CD/DVD sürücüsünü de kaldırın.
2. *Hardware → Add → CloudInit Drive*, diskle aynı storage'da. *Cloud-Init* sekmesini boş bırakın:
   panel her sunucu için doldurur. (Sürücüyü, VM şablon olarak hâlâ açılabilecekken asla eklemeyin:
   Cloudbase-Init onu uygular.)
3. *Options*: *Boot Order*'da yalnızca disk (`scsi0`), *QEMU Guest Agent* açık.
4. *More → Convert to template*.

**Local** storage'lı bir cluster'da şablon, sunucu barındıran her node'da gerekir (*Clone → Target
node*, mod *Full clone* ile oraya klonlayıp klonu dönüştürün). **Shared** storage'da bir tane yeter.
Bir **linked clone** ayrıca şablonun, sunucunun diskinin gideceği storage'ın ta kendisinde olmasını
ister.

## Panele ekleyin ve test edin

1. Panelin API token'ı bir resource pool'la sınırlıysa şablonu o pool'a ekleyin (*Datacenter →
   Permissions → Pools → `<pool>` → Members*).
2. *Admin → Cluster'lar*: senkronize edin.
3. *Admin → OS şablonları*: *İşletim sistemleri* altında sistemi **Windows** türüyle (ör. "Windows
   Server") ve sürümünü (2022) ekleyin, sonra o sürümle OS şablonunu ekleyip Proxmox şablonunu ona
   eşleyin. Panelin sunucularını Windows olarak ele almasını sağlayan **Windows** türüdür.
   *Klonlama yöntemi*'ni seçin (bkz. [Ne kadar hızlı](#yeni-bir-sunucu-ne-kadar-hızlı-hazır-olabilir)).
4. **İki** test sunucusu oluşturun ve kontrol edin:

| Kontrol | Beklenen |
|---|---|
| Görevin *guest agent'ı bekle* adımı | konsol oturum açma ekranını gösterdiği sıralarda biter; sunucu *Aktif* olur |
| Panelin gösterdiği parolayla `Administrator` olarak **Uzak Masaüstü** | girer; şablonun parolası girmez |
| `hostname`, `ipconfig`, `Get-Volume C` | sunucunun adı, panelin adresi, paketin disk boyutu |
| Yeniden başlatmalar: `Get-WinEvent -FilterHashtable @{LogName='System'; Id=1074} -MaxEvents 5 \| Format-List TimeCreated, Message` | Yol A: `winlogon.exe`'den bir tane, Cloudbase-Init'ten (`python.exe`) hiç. Yol B: bilgisayar adı için Cloudbase-Init'ten bir tane |
| Yalnızca Yol A: iki sunucuda `whoami /user` | SID'in son `-`'den önceki kısmı farklı |
| Yol B: iki sunucuda Uzak Masaüstü sertifikasının parmak izi (`Get-ChildItem 'Cert:\LocalMachine\Remote Desktop'`) | farklı |

Lisanslama ve aktivasyon (SPLA, AVMA, KMS) sizin sorumluluğunuzdadır; siz eklemedikçe şablonda ürün
anahtarı yoktur.

## Yapılandırma dosyalarının açıklaması

- **Servisin plugin'leri.** Varsayılan `CreateUserPlugin` yalnızca var olan Administrator'ın
  parolasını yeniden ayarlıyor ve 10 sn sürüyordu; `SetUserPasswordPlugin` onu metadata'dan
  (`admin_pass`) saniyenin altında ayarlar. Bu, Windows Server'da olduğu gibi yerleşik
  `Administrator` hesabının var ve etkin olmasına dayanır: Administrator'ı kapatılmış veya yeniden
  adlandırılmış bir şablon `CreateUserPlugin`'i geri ister, yoksa parola hiç ayarlanmaz.
- **Ağdan önce parola.** `SetUserPasswordPlugin` çalışana kadar şablonun parolası geçerlidir; onu
  `NetworkConfigPlugin`'den önce çalıştırmak, bu durum sürerken sunucuya adresinden ulaşılamaması
  demektir.
- **`ExtendVolumesPlugin`** `C:`'yi panelin oluşturduğu diske büyütür: Yol A'da specialize
  aşamasında, Yol B'de serviste. Sonraki disk yükseltmelerini panelin `disk.ps1`'i genişletir.
- **`UserDataPlugin`** bilgisayar adını ayarlar. Proxmox hostname'i yalnızca user-data'ya
  (`#cloud-config`, `hostname: …`) yazar; `meta_data.json`'da yoktur, bu yüzden `SetHostNamePlugin`
  tek başına ad bulamaz. Proxmox'un user-data'sından Windows'ta yalnızca `hostname` çalışır; `user`,
  `password`, `chpasswd`, `fqdn`, `disable_root` ve `manage_etc_hosts` *not supported* olarak
  log'lanır, yani hiçbir şey kaybolmaz. Windows bir adı bir sonraki yeniden başlatmada uygular: Yol
  A'da bu specialize aşamasının yeniden başlatmasıdır, Yol B'de servisin kendi yeniden başlatması;
  orada son plugin olmasının nedeni budur.
- **`metadata_services`** yalnızca `ConfigDriveService` ile, Cloudbase-Init'in Proxmox'ta yalnızca
  zaman aşımına uğrayan ağ metadata servislerini (EC2, OpenStack HTTP) denemesini önler (yaklaşık
  iki dakika).
- **`[config_drive]`**: Proxmox'un CloudInit drive'ı bir CD sürücüsündeki ISO'dur; `hdd` üzerinde
  `vfat` yalnızca *"mtools_path" needs to be provided* log'lar. Bu seçenekler eskimiş
  `config_drive_cdrom`, `config_drive_vfat` ve `config_drive_raw_hdd`'nin yerini alır.
- **`allow_reboot`**: unattend dosyasında `false`, çünkü specialize aşamasındaki bir yeniden
  başlatma Sysprep'i bozar; serviste `true`, Yol B'de bilgisayar adı için yeniden başlatır.
- **`stop_service_on_exit=true`**: servis işini bitirince durur; panel tam olarak bunu bekler.
- **`WillReboot` `Never` ve `& exit 0`** (Yol A): Cloudbase-Init'in kendi `Unattend.xml`'i
  `&& exit 1 || exit 2` ve `OnRequest` ile biter; çıkış kodu 1, Cloudbase-Init başarılı olsa bile
  Windows kurulumundan ek bir yeniden başlatma ister. Specialize aşamasında başarısız olan bir
  çalıştırma artık tekrarlanmaz; yalnızca bilgisayar adı atlanır (parola ve ağ servisten gelir).
  Log'u `cloudbase-init-unattend.log`'dur.
- **`PersistAllDeviceInstalls`** (isteğe bağlı, Yol A) şablonun aygıtlarını korur; sanal donanımı
  değiştirilmedikçe bunlar her klona uyar ve "Getting ready"yi kısaltmalıdır (*henüz ölçülmedi*).
  Çekirdek sayısı, bellek boyutu ve MAC adresi bu anlamda donanım farkı değildir; farklı bir disk veri
  yolu veya ağ modeli farktır.
- **`LocalScripts\`** içindeki script'ler ilk açılışta çalışır ve sonrasındaki her şeyi geciktirir;
  log her birinin ne kadar sürdüğünü gösterir (`Script "…" ended with exit code`). Orada sabit
  beklemelerden kaçının.

## Yeni bir sunucu ne kadar hızlı hazır olabilir

Windows Server 2022, 2 vCPU, 4 GiB, LVM-thin üzerinde ölçüldü (2026-10-20), Yol A:

| Aşama | Süre | Neye bağlı |
|---|---|---|
| Şablonun full clone'u | 2:00 | Şablon diskinin dolu kısmı ve storage'ın hızı. **Linked clone** saniyeler sürer |
| İlk açılış: specialize aşaması ("Getting ready"), ardından tek yeniden başlatma | 1:01 | vCPU sayısı; aygıt kurulumu |
| İkinci açılıştan OOBE'nin bitmesine kadar | 0:20 | vCPU sayısı |
| Cloudbase-Init servisi (ağ, parola) | 0:26 | 10 sn'si bu rehberin çıkardığı `CreateUserPlugin`'di (o zamandan beri ölçülmedi) |
| Panelin kurulumun bittiğini görmesi | birkaç saniye | Her 5 sn'de bir kontrol eder |

Yol B specialize aşamasını ve OOBE'yi atlar (yukarıdakinin yaklaşık 1:20'si) ama bilgisayar adı için
bir yeniden başlatma ekler; ölçülmedi.

Kazandıranlar, büyükten küçüğe:

1. OS şablonunda **Klonlama yöntemi *Linked clone*** ([ADR 0027](../../adr/0027-linked-clones.md)):
   yaklaşık 2 dakika daha az. Sunucular bu durumda şablona bağımlı olur; önce ADR'yi okuyun.
2. **Küçük bir şablon** (A3): full clone, thin bir diskin yalnızca dolu bloklarını kopyalar.
3. Pakette **daha çok vCPU**: kurulum aşamaları çoğunlukla işlemci işidir.
4. Bedelleriyle birlikte Yol A yerine **Yol B**.
5. **Gerekenden fazla yeniden başlatma yok** ve **gereksiz Cloudbase-Init plugin'i yok**.

## Şablonu güncellemek

Güvenlik güncellemeleri için şablonu her ay yenileyin.

**Yol A:**

1. Şablonu *Clone* edin (mod **Full clone**), klonu başlatıp konsolda girin. İlk açılış kurulumunu
   bir kez çalıştırır; sorulduğunda bir parola belirleyin.
2. Hiçbiri beklemeyene kadar güncellemeleri kurup yeniden başlatın, sonra A2 ve A3'ü çalıştırın.
3. Cloudbase-Init'in cevap dosyasıyla genelleştirin ve kapanmasına izin verin:

   ```bat
   C:\Windows\System32\Sysprep\sysprep.exe /generalize /oobe /shutdown /unattend:"C:\Program Files\Cloudbase Solutions\Cloudbase-Init\conf\Unattend.xml"
   ```

**Yol B:**

1. Şablonu *Clone* edin (mod **Full clone**). Klonun *Cloud-Init* sekmesinde geçici bir *Password*
   belirleyin, başlatın ve konsolda girin (Cloudbase-Init parolayı uygular ve ad için bir kez yeniden
   başlatır).
2. Hiçbiri beklemeyene kadar güncellemeleri kurup yeniden başlatın, sonra B2 ve B3'ü çalıştırın.

**İkisi de:** [VM'i şablona çevirin](#vmi-şablona-çevirin) adımını yeni, tarihli bir adla yapın,
cluster'ı senkronize edin ve OS şablonunu yeni şablona eşleyin. Eski şablonu, onu kullanan hiçbir şey
kalmayınca silin. Full clone'lar ona bağlı değildir; **linked clone**'lar temel diskini kullandıkça
Proxmox onu silmeyi reddeder, o sunucular reinstall edilene ya da sonlandırılana kadar tutun.

## Panel Windows için ne yapar

İşletim sisteminin **Windows** türü, OS şablonunun platformunu `windows` yapar. Platform şunlara
karar verir:

| İşlem | Windows'ta nasıl çalışır |
|---|---|
| İlk açılış | Config drive parolayı (`admin_pass`), ağ ayarlarını ve hostname'i (user-data'da) taşır. Cloudbase-Init bunları uygular |
| Ek IP adresleri, birincil adres | QEMU guest agent üzerinden çalıştırılan varsayılan `addresses.ps1` guest script'i (ADR 0010, 0011). Adresleri her açılışta yeniden uygulayan bir **"Proxmotion addresses" zamanlanmış görevi** kaydeder |
| Yükseltmeyle büyüyen disk | Varsayılan `disk.ps1`, `C:` partition'ını genişletir |
| Parola sıfırlama | Guest agent **`Administrator`**'ın parolasını ayarlar (ya da şablonun kendi `password_reset` script'i) |
| Hostname değişikliği | Varsayılan `hostname.ps1` bilgisayarı hostname'in ilk etiketine (en çok 15 karakter) yeniden adlandırır. Windows bunu bir sonraki yeniden başlatmada uygular: istenirse panel sunucuyu yeniden başlatır, yoksa *yeniden başlatma gerekli* gösterir (ADR 0012) |
| Uygulamalar | Yalnızca Windows uygulamaları (IIS, SQL Server Express, Chocolatey ve `platform: windows` olan recipe'ler); `run.ps1` bunları **`\Proxmotion\` altında bir zamanlanmış görev** olarak SYSTEM ile çalıştırır (ADR 0029). Windows PowerShell 5.1 gerekir |

Script'ler şablon başına *OS şablonları → Script'ler* altında değiştirilebilir.

**Yeni bir sunucu ne zaman hazırdır.** Guest agent, Windows kullanılabilir olmadan çok önce,
specialize aşamasında zaten cevap verir. Bu yüzden yeni veya reinstall edilmiş bir Windows sunucusu
için görevin *guest agent'ı bekle* adımı, birkaç saniyede bir agent üzerinden kontrol ederek şunlar
olana kadar sürer:

- `HKLM\SOFTWARE\Microsoft\Windows\CurrentVersion\Setup\State` altındaki `ImageState`
  `IMAGE_STATE_COMPLETE` olur (Yol B'de zaten öyledir; bu değeri olmayan bir şablon tamamlanmış
  sayılır) ve
- `cloudbase-init` servisi artık çalışmıyordur (`Stopped` ya da kurulu değil).

Aradaki yeniden başlatmalar beklenir. Sunucu ancak o zaman *Aktif* olur ve adres script'i ile
şablonun kendi script'i çalışır. Agent 10 dakika sonra hiç cevap vermediyse (agent'sız bir şablon)
adım Linux'taki gibi bu notla biter; kurulum 15 dakika sonra bitmediyse adım *Windows setup did not
finish within 15 minutes* notunu düşer ve görev devam eder. Bir yeniden başlatma yine de bir
script'i keserse panel agent'ı bekleyip script'i yeniden çalıştırır (en çok üç kez) ve Windows
script'leri ağa dokunmadan önce Cloudbase-Init'i bekler.

**Bir Yol A sunucusu neden bir kez yeniden başlar.** Windows kurulumu specialize aşamasını OOBE'den
önce her zaman bir yeniden başlatmayla bitirir (`C:\Windows\Panther\UnattendGC\setupact.log`:
*Reboot required after setup.exe and PostSysprep commands, before launching OOBE*). Cloudbase-Init'in
varsayılanları iki tane daha ekler; A1'deki yapılandırma bunları kaldırır:

| Yeniden başlatma | Neden | Çözüm |
|---|---|---|
| Cloudbase-Init servisi (1074 olayında `python.exe`) | Servisteki `UserDataPlugin` adı ayarlar ve yeniden başlatır (`allow_reboot=true`) | `UserDataPlugin`'i specialize aşamasında çalıştırın |
| Specialize aşamasının ortasında `winlogon.exe` (`C:\Windows\Panther\setupact.log`'da `Unattend action requested immediate reboot and recall`) | `Unattend.xml`'deki `<WillReboot>OnRequest</WillReboot>` ve `&& exit 1 \|\| exit 2` | `<WillReboot>Never</WillReboot>` ve `& exit 0` |

## Sorun giderme

| Gördüğünüz | Anlamı / ne yapmalı |
|---|---|
| Uzak Masaüstü bağlanmıyor | Uzak Masaüstü Windows Server'da varsayılan olarak kapalıdır: 4. adım. Panelin adresinin ayarlandığını da kontrol edin (`ipconfig`) |
| Yol A: iki yeniden başlatma, ikisi de `winlogon.exe` | `Unattend.xml` Cloudbase-Init'ten sonra hâlâ yeniden başlatma istiyor: A1 |
| Yol A: `python.exe` (Cloudbase-Init) tarafından bir yeniden başlatma | `UserDataPlugin`, `cloudbase-init-unattend.conf` yerine `cloudbase-init.conf`'ta |
| Yol B: bilgisayar adı şablonunki olarak kalıyor | `UserDataPlugin` eksik ya da `allow_reboot=false`: B1. `cloudbase-init.log` `Changing hostname` göstermeli |
| Yol B: şablonun parolası yeni bir sunucuda çalışıyor | `SetUserPasswordPlugin` çalışmamış: Cloudbase-Init bu instance'ı zaten işlediğini sanıyor (B2'de registry temizlenmemiş) ya da VM'in OS türü Windows değil |
| Yol B: Uzak Masaüstü her sunucuda aynı sertifika için uyarıyor | Sertifika B2'de silinmemiş |
| "Getting ready" uzun sürüyor, *Working on updates* | Şablonda bekleyen güncellemeler vardı |
| Sysprep duruyor: *Package Microsoft.MicrosoftEdge.Stable_… was installed for a user, but not provisioned for all users* | A2; `C:\Windows\System32\Sysprep\Panther\setuperr.log` paketin adını verir |
| Hostname hâlâ `WIN-…` (Yol A) | `cloudbase-init-unattend.log` `Changing hostname` göstermeli |
| Parola ayarlanmamış | `cloudbase-init.log` `Using admin_pass metadata user password` göstermeli. VM'in bir CloudInit drive'ı olmalı ve OS türü Windows olmalı |
| Görev 15 dakika sürüyor ve *Windows setup did not finish* notunu düşüyor | `cloudbase-init` servisi hiç durmamış (`stop_service_on_exit=true` eksik) ya da kurulum takılmış: konsola ve `cloudbase-init.log`'a bakın |
| Yol B: panel script'lerini ağ ayarlanmadan çalıştırdı | Servis geç başladı (gecikmeli başlatma) ve panel onu durmuş gördü: B1 |
| *The additional addresses could not be configured inside the server: QEMU guest agent is not running* | Script çalışırken Windows üç kez yeniden başladı. Zamanlanmış görev adresleri bir sonraki açılışta uygular; ya da Script'ler sayfasında *Sunucuda çalıştır* |
| *Get-NetAdapter : Critical error* | Windows hâlâ kurulumdaydı; güncel script'ler bunu bekler. Varsayılanın yerine geçtiyse şablonun override'ını güncelleyin |
| *Proxmotion addresses* zamanlanmış görevi yok | Adres script'i yeterince ilerleyemedi: `Get-ScheduledTask -TaskName 'Proxmotion addresses'` |

Yeni bir sunucunun zamanı nereye gitti (yeni sunucuda, yönetici olarak PowerShell):

```powershell
Get-WinEvent -FilterHashtable @{LogName='System'; Id=12} -MaxEvents 3 | Format-Table TimeCreated
Get-WinEvent -FilterHashtable @{LogName='System'; Id=1074} -MaxEvents 5 | Format-List TimeCreated, Message
Get-Content 'C:\Program Files\Cloudbase Solutions\Cloudbase-Init\log\cloudbase-init*.log' | Select-String 'Executing plugin|Plugins execution done|ended with|Rebooting'
Select-String -Path C:\Windows\Panther\UnattendGC\setupact.log -Pattern 'ImageState'
```

12. olay her açılış, 1074 her yeniden başlatma ve onu kimin istediği; Cloudbase-Init satırları her
plugin'in ne kadar sürdüğünü, `ImageState` kurulumun ne zaman bittiğini gösterir.

## Henüz doğrulanmayanlar

- **Yol B panelle denenmedi.** Özellikle: bilgisayar adının servis tarafından yeniden başlatmasıyla
  ayarlanması, Uzak Masaüstü sertifikasının ve Windows Update istemci kimliğinin silinmesi,
  Cloudbase-Init'in registry anahtarının temizlenmesi ve panelin hazır olma kontrolünün (servis
  durmuş) servis başlamadan önce geçip geçemeyeceği. Aynı SID'li makineler arasındaki kimlik
  doğrulama hataları Microsoft'un sürüm notlarından alındı, tarafımızdan denenmedi.
- Yol A bir kez, Windows Server 2022'de ölçüldü (2026-10-20): `Unattend.xml` değişikliğiyle tek
  yeniden başlatma ve sunucunun Cloudbase-Init servisi durduktan birkaç saniye sonra *Aktif* olması.
  Kontrol edilmedi: Windows Server 2019 ve 2025, `Unattend.xml`'i farklı olan Cloudbase-Init
  sürümleri, parolanın önce geldiği plugin sırası.
- Ölçülmedi: `PersistAllDeviceInstalls`, `CreateUserPlugin`'siz servis, küçültme komutları, bir
  Windows şablonunun linked clone'ları.
- Windows uygulamaları (ADR 0029) yalnızca panelin sahte Proxmox'una karşı çalıştı.
- Windows varsayılan script'leri tek bir Windows Server imajında çalıştı (2026-10-17): reinstall
  sonrası ek adresler. Birincil adres değişiklikleri, `disk.ps1`, IPv6 ve diğer Windows sürümleri
  kontrol edilmedi.
